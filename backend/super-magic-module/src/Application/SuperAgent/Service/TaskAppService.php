<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\SuperMagic\Application\SuperAgent\Service;

use App\Application\Chat\Service\MagicChatMessageAppService;
use App\Application\File\Service\FileAppService;
use App\Domain\Chat\Entity\Items\SeqExtra;
use App\Domain\Chat\Entity\MagicSeqEntity;
use App\Domain\Chat\Entity\ValueObject\ConversationType;
use App\Domain\Chat\Entity\ValueObject\MessageType\ChatMessageType;
use App\Domain\Chat\Service\MagicChatFileDomainService;
use App\Domain\Contact\Entity\ValueObject\DataIsolation;
use App\Domain\Contact\Entity\ValueObject\UserType;
use App\Domain\Contact\Service\MagicUserDomainService;
use App\ErrorCode\GenericErrorCode;
use App\ErrorCode\SuperAgentErrorCode;
use App\Infrastructure\Core\Exception\BusinessException;
use App\Infrastructure\Core\Exception\EventException;
use App\Infrastructure\Core\Exception\ExceptionBuilder;
use App\Infrastructure\Util\Context\CoContext;
use App\Infrastructure\Util\IdGenerator\IdGenerator;
use App\Infrastructure\Util\Locker\LockerInterface;
use App\Interfaces\Authorization\Web\MagicUserAuthorization;
use Dtyq\AsyncEvent\AsyncEventUtil;
use Dtyq\SuperMagic\Domain\SuperAgent\Constant\TaskFileType;
use Dtyq\SuperMagic\Domain\SuperAgent\Entity\TaskEntity;
use Dtyq\SuperMagic\Domain\SuperAgent\Entity\TopicEntity;
use Dtyq\SuperMagic\Domain\SuperAgent\Entity\ValueObject\ChatInstruction;
use Dtyq\SuperMagic\Domain\SuperAgent\Entity\ValueObject\MessageMetadata;
use Dtyq\SuperMagic\Domain\SuperAgent\Entity\ValueObject\MessagePayload;
use Dtyq\SuperMagic\Domain\SuperAgent\Entity\ValueObject\MessageType;
use Dtyq\SuperMagic\Domain\SuperAgent\Entity\ValueObject\TaskContext;
use Dtyq\SuperMagic\Domain\SuperAgent\Entity\ValueObject\TaskStatus;
use Dtyq\SuperMagic\Domain\SuperAgent\Event\RunTaskAfterEvent;
use Dtyq\SuperMagic\Domain\SuperAgent\Event\RunTaskBeforeEvent;
use Dtyq\SuperMagic\Domain\SuperAgent\Event\RunTaskCallbackEvent;
use Dtyq\SuperMagic\Domain\SuperAgent\Repository\Facade\TaskRepositoryInterface;
use Dtyq\SuperMagic\Domain\SuperAgent\Service\MessageBuilderDomainService;
use Dtyq\SuperMagic\Domain\SuperAgent\Service\TaskDomainService;
use Dtyq\SuperMagic\Domain\SuperAgent\Service\TopicDomainService;
use Dtyq\SuperMagic\Domain\SuperAgent\Service\WorkspaceDomainService;
use Dtyq\SuperMagic\Infrastructure\ExternalAPI\Sandbox\Config\WebSocketConfig;
use Dtyq\SuperMagic\Infrastructure\ExternalAPI\Sandbox\SandboxResult;
use Dtyq\SuperMagic\Infrastructure\ExternalAPI\Sandbox\SandboxStruct;
use Dtyq\SuperMagic\Infrastructure\ExternalAPI\Sandbox\Volcengine\SandboxService;
use Dtyq\SuperMagic\Infrastructure\ExternalAPI\Sandbox\WebSocket\WebSocketSession;
use Dtyq\SuperMagic\Infrastructure\Utils\ToolProcessor;
use Dtyq\SuperMagic\Interfaces\SuperAgent\DTO\TopicTaskMessageDTO;
use Error;
use Exception;
use Hyperf\Codec\Json;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

class TaskAppService extends AbstractAppService
{
    protected LoggerInterface $logger;

    /**
     * 消息构建服务
     */
    private MessageBuilderDomainService $messageBuilder;

    public function __construct(
        private readonly WorkspaceDomainService $workspaceDomainService,
        private readonly TopicDomainService $topicDomainService,
        private readonly TaskDomainService $taskDomainService,
        private readonly MagicChatMessageAppService $chatMessageAppService,
        private readonly MagicChatFileDomainService $chatFileDomainService,
        private readonly FileAppService $fileAppService,
        private readonly SandboxService $sandboxService,
        private readonly FileProcessAppService $fileProcessAppService,
        protected MagicUserDomainService $userDomainService,
        protected TaskRepositoryInterface $taskRepository,
        protected LockerInterface $locker,
        LoggerFactory $loggerFactory
    ) {
        $this->messageBuilder = new MessageBuilderDomainService();
        $this->logger = $loggerFactory->get(get_class($this));
    }

    /**
     * 初始化智能体任务，建立WebSocket连接，并启动处理协程.
     */
    public function initAgentTask(
        DataIsolation $dataIsolation,
        string $agentUserId,
        string $conversationId,
        string $chatTopicId,
        string $prompt,
        ?string $attachments = null,
        ChatInstruction $instruction = ChatInstruction::Normal,
        string $taskMode = ''
    ): string {
        $topicId = 0;
        $taskId = '';
        try {
            $topicEntity = $this->topicDomainService->getTopicByChatTopicId($dataIsolation, $chatTopicId);
            if (is_null($topicEntity)) {
                ExceptionBuilder::throw(SuperAgentErrorCode::TOPIC_NOT_FOUND, 'topic.topic_not_found');
            }
            // 检查用户任务数量限制和白名单
            $this->beforeInitTask($dataIsolation, $instruction, $topicEntity);
            // 初始化任务
            $taskEntity = $this->taskDomainService->initTopicTask($dataIsolation, $topicEntity, $instruction, $taskMode, $prompt, $attachments);
            $topicId = $taskEntity->getTopicId();
            $taskId = $taskEntity->getTaskId();

            // 初始化上下文
            $taskContext = new TaskContext(
                $taskEntity,
                $dataIsolation,
                $conversationId,
                $chatTopicId,
                $agentUserId,
                $topicEntity->getSandboxId(),
                $taskId,
                $instruction,
            );

            // 如果是中断指令，直接发送中断指令
            if ($instruction == ChatInstruction::Interrupted) {
                $this->sendInternalMessageToSandbox($taskContext, $topicEntity);
                return $taskId;
            }

            // 其余指令都是对话信息
            // 处理用户发送的信息
            // 记录用户发送的消息
            $attachmentsArr = is_null($attachments) ? [] : json_decode($attachments, true);
            $this->taskDomainService->recordUserMessage(
                (string) $taskEntity->getId(),
                $dataIsolation->getCurrentUserId(),
                $agentUserId,
                $prompt,
                null,
                $taskEntity->getTopicId(),
                '',
                $attachmentsArr
            );
            // 处理用户上传的附件
            $this->fileProcessAppService->processInitialAttachments($attachments, $taskEntity, $dataIsolation);

            // 初始化沙箱环境
            // 没有沙箱id，那么一定是首次任务
            $isFirstTaskMessage = empty($taskEntity->getSandboxId());
            /** @var bool $isInitConfig */
            [$isInitConfig, $sandboxId] = $this->initSandbox($taskEntity->getSandboxId());
            if (empty($sandboxId)) {
                $this->taskDomainService->updateTaskStatus(
                    dataIsolation: $dataIsolation,
                    topicId: $taskEntity->getTopicId(),
                    status: TaskStatus::ERROR,
                    id: $taskEntity->getId(),
                    taskId: $taskEntity->getTaskId(),
                    sandboxId: $sandboxId
                );
                throw new BusinessException('创建沙箱失败', 500);
            }
            $this->logger->info(sprintf('创建沙箱成功: %s', $sandboxId));
            $taskEntity->setSandboxId($sandboxId);
            // 设置任务状态为等待中
            $this->updateTaskStatus($taskEntity, $dataIsolation, $taskId, TaskStatus::WAITING);
            $taskContext->setSandboxId($sandboxId);

            // 5. 启动协程处理WebSocket通信
            $requestId = CoContext::getOrSetRequestId();
            Coroutine::create(function () use ($taskContext, $isInitConfig, $isFirstTaskMessage, $requestId) {
                try {
                    CoContext::setRequestId($requestId);
                    $this->sendChatMessageToSandbox($taskContext, $isInitConfig, $isFirstTaskMessage);
                } catch (Throwable $e) {
                    $this->logger->error(sprintf(
                        'WebSocket通信处理异常: %s, 任务ID: %s',
                        $e->getMessage(),
                        $taskContext->getTaskId()
                    ));
                    // 更新任务状态为错误
                    $this->updateTaskStatus(
                        $taskContext->getTask(),
                        $taskContext->getDataIsolation(),
                        $taskContext->getTaskId(),
                        TaskStatus::ERROR,
                        $e->getMessage()
                    );
                }
            });

            return $taskContext->getTaskId();
        } catch (EventException $e) {
            $this->logger->error(sprintf(
                '初始化任务, 事件处理失败: %s',
                $e->getMessage()
            ));
            // 发送消息给客户端
            $this->sendErrorMessageToClient($topicId, (string) $taskId, $chatTopicId, $conversationId, $e->getMessage());
            throw new BusinessException('初始化任务, 事件处理失败', 500);
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                '初始化任务失败: %s',
                $e->getMessage()
            ));

            $text = '系统繁忙，请稍后重试';
            if ($e->getCode() === GenericErrorCode::IllegalOperation->value) {
                $text = $e->getMessage();
            }
            // 发送消息给客户端
            $this->sendErrorMessageToClient($topicId, (string) $taskId, $chatTopicId, $conversationId, $text);
            throw new BusinessException('初始化任务失败', 500);
        }
    }

    public function beforeInitTask(DataIsolation $dataIsolation, ChatInstruction $instruction, TopicEntity $topicEntity): void
    {
        if ($instruction == ChatInstruction::Interrupted) {
            return;
        }

        $taskRound = $this->taskDomainService->getTaskNumByTopicId($topicEntity->getId());
        AsyncEventUtil::dispatch(new RunTaskBeforeEvent($dataIsolation->getCurrentOrganizationCode(), $dataIsolation->getCurrentUserId(), $topicEntity->getId(), $taskRound));
        $this->logger->info(sprintf('投递任务开始事件，话题id：%s, round: %d', $topicEntity->getId(), $taskRound));
    }

    /**
     * 发送终止任务信息.
     * @throws Throwable
     */
    public function sendInternalMessageToSandbox(TaskContext $taskContext, TopicEntity $topicEntity, string $msg = ''): void
    {
        $text = empty($msg) ? '任务已终止' : $msg;
        // 检查沙箱是否存在
        if (empty($topicEntity->getSandboxId())) {
            $this->logger->info('沙箱id不存在，直接更新任务状态');
            $this->updateTaskStatus($taskContext->getTask(), $taskContext->getDataIsolation(), $taskContext->getTaskId(), TaskStatus::Suspended, '沙箱id不存在，直接更新任务状态');
            $this->sendErrorMessageToClient($topicEntity->getId(), (string) $taskContext->getTask()->getId(), $taskContext->getChatTopicId(), $taskContext->getChatConversationId(), $text);
            return;
        }
        // 调用远程查询沙箱是否存在
        $result = $this->sandboxService->checkSandboxExists($topicEntity->getSandboxId());
        if ($result->getCode() == SandboxResult::NotFound || $result?->getSandboxData()?->getStatus() == SandboxResult::SandboxExited) {
            $this->logger->info('沙箱不存在或者退出，直接更新任务状态');
            $this->updateTaskStatus($taskContext->getTask(), $taskContext->getDataIsolation(), $taskContext->getTaskId(), TaskStatus::Suspended, '沙箱不存在或者退出，直接更新任务状态');
            $this->sendErrorMessageToClient($topicEntity->getId(), (string) $taskContext->getTask()->getId(), $taskContext->getChatTopicId(), $taskContext->getChatConversationId(), $text);
        }
        // 如果沙箱存在，构建 websocket 通道进行打通
        $websocketSession = $this->getSandboxWebsocketClient($taskContext);
        if (is_null($websocketSession)) {
            throw new BusinessException('获取沙箱websocket客户端失败', 500);
        }
        try {
            // 如果 message 为空，就不发送，因为你触发沙箱的关闭，会有回调消息，避免重复发的情况
            if (! empty($msg)) {
                $this->sendErrorMessageToClient($topicEntity->getId(), (string) $taskContext->getTask()->getId(), $taskContext->getChatTopicId(), $taskContext->getChatConversationId(), $msg);
            }
            // 设置打断指令
            $taskContext->getTask()->setPrompt('终止任务');
            $taskContext->setInstruction(ChatInstruction::Interrupted);
            $this->sendMessageToSandbox($websocketSession, $taskContext);
        } catch (Exception $e) {
            $this->logger->error(sprintf('终止沙箱任务信息失败，错误内容为: %s', $e->getMessage()));
            throw new BusinessException('发生终止任务失败', 500);
        } finally {
            $websocketSession->disconnect();
        }
    }

    /**
     * 处理话题任务消息.
     *
     * @param TopicTaskMessageDTO $messageDTO 消息DTO
     */
    public function handleTopicTaskMessage(TopicTaskMessageDTO $messageDTO): void
    {
        $this->logger->info(sprintf(
            '开始处理话题任务消息，task_id: %s , 消息内容为: %s',
            $messageDTO->getPayload()->getTaskId() ?? '',
            json_encode($messageDTO->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ));

        // 获取任务信息
        $taskEntity = $this->taskDomainService->getTaskById((int) $messageDTO->getMetadata()->getSuperMagicTaskId() ?? '');
        if (is_null($taskEntity)) {
            throw new RuntimeException(sprintf('根据任务 id: %s 未找到任务信息', $messageDTO->getPayload()->getTaskId() ?? ''));
        }

        // 处理消息前分发事件
        $topicEntity = $this->topicDomainService->getTopicById($taskEntity->getTopicId());
        if (is_null($topicEntity)) {
            throw new RuntimeException(sprintf('根据话题 id: %s 未找到话题信息', $taskEntity->getTopicId()));
        }

        // 构建任务上下文
        // 创建数据隔离对象
        $dataIsolation = DataIsolation::create(
            $messageDTO->getMetadata()->getOrganizationCode(),
            $messageDTO->getMetadata()->getUserId()
        );

        // 创建任务上下文
        $taskContext = new TaskContext(
            task: $taskEntity,
            dataIsolation: $dataIsolation,
            chatConversationId: $messageDTO->getMetadata()?->getChatConversationId(),
            chatTopicId: $messageDTO->getMetadata()?->getChatTopicId(),
            agentUserId: $messageDTO->getMetadata()?->getAgentUserId(),
            sandboxId: $messageDTO->getMetadata()?->getSandboxId(),
            taskId: $messageDTO->getPayload()?->getTaskId(),
            instruction: ChatInstruction::tryFrom($messageDTO->getMetadata()?->getInstruction()) ?? ChatInstruction::Normal
        );

        try {
            AsyncEventUtil::dispatch(new RunTaskCallbackEvent(
                $taskContext->getCurrentOrganizationCode(),
                $taskContext->getCurrentUserId(),
                $taskContext->getTopicId(),
                $topicEntity->getTopicName(),
                $taskContext->getTask()->getId(),
                $messageDTO
            ));

            // 处理接收到的消息
            $this->handleReceivedMessage($messageDTO, $taskContext);

            $this->logger->info(sprintf(
                '处理话题任务消息完成，message_id: %s',
                $messageDTO->getPayload()->getMessageId()
            ));
        } catch (EventException $e) {
            $this->logger->error(sprintf('处理消息事件回调的过程出现异常: %s', $e->getMessage()));
            $this->sendInternalMessageToSandbox($taskContext, $topicEntity, $e->getMessage());
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                '处理话题任务消息异常: %s, message_id: %s',
                $e->getMessage(),
                $messageDTO->getPayload()->getMessageId()
            ), [
                'exception' => $e,
                'message' => $messageDTO->toArray(),
            ]);
        }
    }

    /**
     * 获取分布式互斥锁.
     *
     * @param string $lockKey 锁的键名
     * @param string $lockOwner 锁的持有者
     * @param int $lockExpireSeconds 锁的过期时间（秒）
     * @return bool 是否成功获取锁
     */
    public function acquireLock(string $lockKey, string $lockOwner, int $lockExpireSeconds): bool
    {
        return $this->locker->mutexLock($lockKey, $lockOwner, $lockExpireSeconds);
    }

    /**
     * 释放分布式互斥锁.
     *
     * @param string $lockKey 锁的键名
     * @param string $lockOwner 锁的持有者
     * @return bool 是否成功释放锁
     */
    public function releaseLock(string $lockKey, string $lockOwner): bool
    {
        return $this->locker->release($lockKey, $lockOwner);
    }

    /**
     * 获取 websocket 客户端廉价.
     */
    private function getSandboxWebsocketClient(TaskContext $taskContext): ?WebSocketSession
    {
        $config = new WebSocketConfig();
        $task = $taskContext->getTask();
        $sandboxId = $taskContext->getSandboxId();
        $wsUrl = $this->sandboxService->getWebsocketUrl($sandboxId);

        // 打印连接参数
        $this->logger->info(sprintf(
            'WebSocket连接参数，URL: %s，最大连接时间: %d秒',
            $wsUrl,
            $config->getConnectTimeout()
        ));

        // 创建 WebSocket 会话
        $session = new WebSocketSession(
            $config,
            $this->logger,
            $wsUrl,
            $task->getTaskId()
        );

        try {
            $session->connect();
            return $session;
        } catch (Exception $e) {
            $this->logger->error(sprintf(
                'WebSocket连接失败，URL: %s，错误信息: %s',
                $wsUrl,
                $e->getMessage()
            ));
            return null;
        }
    }

    /**
     * 处理WebSocket通信
     */
    private function sendChatMessageToSandbox(
        TaskContext $taskContext,
        bool $isInitConfig,
        bool $isFirstTaskMessage,
    ): void {
        // 建立连接
        $session = $this->getSandboxWebsocketClient($taskContext);
        if (is_null($session)) {
            throw new BusinessException('获取沙箱websocket客户端失败');
        }
        try {
            // 发送初始化消息
            if ($isInitConfig) {
                $this->initTaskMessageToSandbox($session, $taskContext, $isFirstTaskMessage);
            }
            // 发送聊天消息
            $taskId = $this->sendMessageToSandbox($session, $taskContext);
            // 初始化成功后，更新状态为 running
            $taskContext->getTask()->setTaskId($taskId);
            // 更新任务为执行状态
            $this->updateTaskStatus($taskContext->getTask(), $taskContext->getDataIsolation(), $taskId, TaskStatus::RUNNING);
            // 这里取一个配置，是否需要进入 websocket 循环
            $mode = config('super-magic.sandbox.pull_message_mode');
            // websocket 模式，将持续等待
            if ($mode === 'websocket') {
                $this->processMessageLoop($session, $taskContext);
            }
        } catch (Throwable $e) {
            $this->logger->error(sprintf('WebSocket会话异常: %s', $e->getMessage()), [
                'exception' => $e,
                'task_id' => $taskContext->getTask()->getTaskId(),
                'sandbox_id' => $taskContext->getTask()->getSandboxId(),
            ]);
            $this->updateTaskStatus($taskContext->getTask(), $taskContext->getDataIsolation(), $taskContext->getTaskId(), TaskStatus::ERROR, $e->getMessage());
            $this->sendErrorMessageToClient($taskContext->getTask()->getTopicId(), (string) $taskContext->getTask()->getId(), $taskContext->getChatTopicId(), $taskContext->getChatConversationId(), '远程服务器连接失败，请稍后重试');
            throw $e;
        } finally {
            // 确保连接被关闭
            try {
                $session->disconnect();
                $this->logger->info(sprintf(
                    'WebSocket会话关闭成功，任务ID: %s',
                    $taskContext->getTaskId()
                ));
            } catch (Throwable $e) {
                $this->logger->warning(sprintf(
                    '关闭WebSocket连接失败，错误: %s，任务ID: %s',
                    $e->getMessage(),
                    $taskContext->getTaskId()
                ));
            }
        }
    }

    private function initTaskMessageToSandbox(WebSocketSession $session, TaskContext $taskContext, bool $isFirstTaskMessage): string
    {
        $dataIsolation = $taskContext->getDataIsolation();
        $task = $taskContext->getTask();
        $uploadCredential = $this->getUploadCredential(
            $dataIsolation->getCurrentUserId(),
            $dataIsolation->getCurrentOrganizationCode(),
            $task->getWorkDir()
        );

        // 使用值对象替代原始数组
        $messageMetadata = new MessageMetadata(
            agentUserId: $taskContext->getAgentUserId(),
            userId: $dataIsolation->getCurrentUserId(),
            organizationCode: $dataIsolation->getCurrentOrganizationCode(),
            chatConversationId: $taskContext->getChatConversationId(),
            chatTopicId: $taskContext->getChatTopicId(),
            instruction: $taskContext->getInstruction()->value,
            sandboxId: $taskContext->getSandboxId(),
            superMagicTaskId: (string) $task->getId(),
        );

        $topicEntity = $this->workspaceDomainService->getTopicById($task->getTopicId());
        if (is_null($topicEntity)) {
            throw new RuntimeException('初始化 agent 发现话题不存在，话题 id: ' . $task->getTopicId());
        }
        $sandboxConfig = ! empty($topicEntity->getSandboxConfig()) ? json_decode($topicEntity->getSandboxConfig(), true) : null;
        $initMessage = $this->messageBuilder->buildInitMessage(
            $dataIsolation->getCurrentUserId(),
            $uploadCredential,
            $messageMetadata,
            $isFirstTaskMessage,
            $sandboxConfig,
            $task->getTaskMode(),
        );
        $this->logger->info(sprintf('[Send to Sandbox Init Message] task_id: %s, data: %s', $task->getTaskId(), json_encode($initMessage, JSON_UNESCAPED_UNICODE)));
        $session->send($initMessage);

        // 等待初始化响应
        $message = $session->receive(900);
        if ($message === null) {
            throw new RuntimeException('等待 agent 初始化响应超时');
        }

        $this->logger->info(sprintf(
            '[Receive from Sandbox Init Message] task_id: %s, data: %s',
            $task->getTaskId(),
            json_encode($message, JSON_UNESCAPED_UNICODE)
        ));

        // 将原始消息转换为统一格式
        $messageDTO = $this->convertWebSocketMessageToDTO($message);
        $payload = $messageDTO->getPayload();

        // 使用新的统一格式进行验证
        if (! $payload->getType() || $payload->getType() !== MessageType::Init->value) {
            throw new RuntimeException('收到非预期的初始化响应类型');
        }

        if ($payload->getStatus() === TaskStatus::ERROR->value) {
            throw new RuntimeException('agent 初始化失败: ' . json_encode($messageDTO->toArray(), JSON_UNESCAPED_UNICODE));
        }

        return $payload->getTaskId();
    }

    private function sendMessageToSandbox(WebSocketSession $session, TaskContext $taskContext): string
    {
        $dataIsolation = $taskContext->getDataIsolation();
        $task = $taskContext->getTask();

        $attachmentUrls = $this->getAttachmentUrls($task->getAttachments(), $dataIsolation->getCurrentOrganizationCode());
        $chatMessage = $this->messageBuilder->buildChatMessage(
            $dataIsolation->getCurrentUserId(),
            $task->getId(),
            $taskContext->getInstruction()->value,
            $task->getPrompt(),
            $attachmentUrls,
            $task->getTaskMode()
        );
        $session->send($chatMessage);
        $this->logger->info(sprintf('[Send to Sandbox Chat Message] task_id: %s, data: %s', $task->getTaskId(), json_encode($chatMessage, JSON_UNESCAPED_UNICODE)));

        // 等待响应
        $message = $session->receive(60);
        if ($message === null) {
            throw new RuntimeException('等待 agent 响应超时');
        }

        $this->logger->info(sprintf(
            '[Receive from Sandbox Chat Message] task_id: %s, data: %s',
            $task->getTaskId(),
            json_encode($message, JSON_UNESCAPED_UNICODE)
        ));

        // 将原始消息转换为统一格式
        $messageDTO = $this->convertWebSocketMessageToDTO($message);
        $payload = $messageDTO->getPayload();

        // 使用新的统一格式进行验证
        if (! $payload->getType() || $payload->getType() !== MessageType::Chat->value) {
            throw new RuntimeException('收到非预期的响应类型');
        }

        if ($payload->getStatus() === TaskStatus::ERROR->value) {
            throw new RuntimeException('agent 响应失败: ' . json_encode($messageDTO->toArray(), JSON_UNESCAPED_UNICODE));
        }

        return $payload->getTaskId();
    }

    /**
     * 处理WebSocket消息流程.
     */
    private function processMessageLoop(
        WebSocketSession $session,
        TaskContext $taskContext
    ): void {
        // 添加最大处理时间限制，避免无限循环
        $startTime = time();
        $config = new WebSocketConfig();
        $taskTimeout = $config->getTaskTimeout();
        $task = $taskContext->getTask();

        while (true) {
            try {
                // 检查连接状态
                if (! $session->isConnected()) {
                    $this->logger->warning('WebSocket连接已断开，尝试重新连接');
                    try {
                        $session->connect();
                    } catch (Throwable $e) {
                        $this->logger->error(sprintf(
                            '重新连接失败: %s, 任务ID: %s',
                            $e->getMessage(),
                            $taskContext->getTaskId()
                        ));

                        $this->updateTaskStatus($task, $taskContext->getDataIsolation(), $taskContext->getTaskId(), TaskStatus::ERROR, $e->getMessage());
                        return; // 退出处理
                    }
                }

                // 接收消息
                $message = $session->receive($config->getReadTimeout());
                if ($message === null) {
                    // 定期检查任务是否已经超时
                    if (time() - $startTime > $taskTimeout) {
                        $errMsg = sprintf(
                            '任务处理超时，任务ID: %s，运行时间: %d秒，任务超时时间: %d秒',
                            $taskContext->getTaskId(),
                            time() - $startTime,
                            $taskTimeout
                        );
                        $this->logger->warning($errMsg);
                        $this->updateTaskStatus($task, $taskContext->getDataIsolation(), $taskContext->getTaskId(), TaskStatus::ERROR, $errMsg);
                        return; // 退出处理
                    }
                    continue;
                }

                $this->logger->info('[Websocket Server] 收到服务端的消息: ' . json_encode($message, JSON_UNESCAPED_UNICODE));

                // 将消息转换为统一格式
                $messageDTO = $this->convertWebSocketMessageToDTO($message);

                // 设置 task id
                $taskContext->setTaskId($messageDTO->getPayload()->getTaskId() ?: $task->getTaskId());

                // 处理消息并判断是否需要继续处理
                $shouldContinue = $this->handleReceivedMessage($messageDTO, $taskContext);
                if (! $shouldContinue) {
                    $this->logger->info('[任务已经完成] task_id: ' . $taskContext->getTaskId());
                    break; // 如果是终止消息，退出循环
                }
            } catch (Throwable $e) {
                $this->logger->error(sprintf(
                    'Task 处理消息异常: %s, 任务ID: %s',
                    $e->getMessage(),
                    $taskContext->getTaskId()
                ));

                // 判断是否是致命错误，如果是则终止处理
                if ($this->isFatalError($e)) {
                    $this->updateTaskStatus($task, $taskContext->getDataIsolation(), $taskContext->getTaskId(), TaskStatus::ERROR, $e->getMessage());
                    return; // 退出处理
                }

                // 非致命错误，继续处理
                continue;
            }
        }
    }

    /**
     * 处理接收到的消息.
     *
     * @param TopicTaskMessageDTO $messageDTO 消息
     * @param TaskContext $taskContext 任务上下文
     * @return bool 是否继续处理消息
     */
    private function handleReceivedMessage(TopicTaskMessageDTO $messageDTO, TaskContext $taskContext): bool
    {
        $payload = $messageDTO->getPayload();
        // 1. 解析消息基本信息
        $messageType = $payload->getType() ?: 'unknown';
        $content = $payload->getContent();
        $status = $payload->getStatus() ?: TaskStatus::RUNNING->value;
        $tool = $payload->getTool() ?? [];
        $steps = $payload->getSteps() ?? [];
        $event = $payload->getEvent();
        $attachments = $payload->getAttachments() ?? [];
        $projectArchive = $payload->getProjectArchive() ?? [];
        $showInUi = $payload->getShowInUi() ?? true;
        $messageId = $payload->getMessageId();

        // 2. 处理未知消息类型
        if (! MessageType::isValid($messageType)) {
            $this->logger->warning(sprintf(
                '收到未知类型的消息，类型: %s，任务ID: %s',
                $messageType,
                $taskContext->getTaskId()
            ));
            return true;
        }

        // 如果是持久化沙箱消息
        if ($messageType == MessageType::ProjectArchive->value) {
            $this->workspaceDomainService->updateTopicSandboxConfig($taskContext->getDataIsolation(), $taskContext->getTopicId(), $projectArchive);
            return true;
        }

        // 3. 处理工具附件（如果有）
        try {
            if ($tool !== null && ! empty($tool['attachments'])) {
                $this->processToolAttachments($tool, $taskContext);
                // 使用工具处理器处理文件ID匹配
                ToolProcessor::processToolAttachments($tool);
            }

            // 处理消息附件
            $this->processMessageAttachments($attachments, $taskContext);

            // 每个状态需要做一些特殊处理
            if ($status === TaskStatus::Suspended->value) {
                $this->pauseTaskSteps($steps);
            } elseif ($status === TaskStatus::FINISHED->value) {
                // 使用工具处理器生成输出内容工具
                $outputTool = ToolProcessor::generateOutputContentTool($attachments);
                if ($outputTool !== null) {
                    $tool = $outputTool;
                }
            }

            // 4. 记录AI消息
            $task = $taskContext->getTask();
            $this->taskDomainService->recordAiMessage(
                (string) $task->getId(),
                $taskContext->getAgentUserId(),
                $task->getUserId(),
                $messageType,
                $content,
                $status,
                $steps,
                $tool,
                $task->getTopicId(),
                $event,
                $attachments,
                $showInUi,
                $messageId
            );

            // 5. 发送消息到客户端
            if ($showInUi) {
                $this->sendMessageToClient(
                    topicId: $task->getTopicId(),
                    taskId: (string) $task->getId(),
                    chatTopicId: $taskContext->getChatTopicId(),
                    chatConversationId: $taskContext->getChatConversationId(),
                    content: $content,
                    messageType: $messageType,
                    status: $status,
                    event: $event,
                    steps: $steps,
                    tool: $tool,
                    attachments: $attachments
                );
            }

            // 6. 判断是否需要继续处理
            $taskStatus = TaskStatus::tryFrom($status) ?? TaskStatus::ERROR;
            if (TaskStatus::tryFrom($status)) {
                $this->updateTaskStatus($taskContext->getTask(), $taskContext->getDataIsolation(), $taskContext->getTaskId(), $taskStatus);
            }
            if (in_array($taskStatus, [TaskStatus::FINISHED, TaskStatus::ERROR, TaskStatus::Suspended])) {
                $this->logger->info(sprintf('任务完成，任务信息: %s', json_encode($messageDTO->toArray(), JSON_UNESCAPED_UNICODE)));
                AsyncEventUtil::dispatch(new RunTaskAfterEvent($taskContext->getCurrentOrganizationCode(), $taskContext->getCurrentUserId(), $task->getTopicId(), $task->getId(), $status, $messageDTO->getTokenUsageDetails()));
                return false;
            }
            return true;
        } catch (Exception $e) {
            $this->logger->error(sprintf('处理消息的过程出现异常: %s', $e->getMessage()));
            return true;
        }
    }

    private function pauseTaskSteps(array &$steps): void
    {
        if (empty($steps)) {
            return;
        }
        // 将当前步骤设置为暂停
        foreach ($steps as $key => $step) {
            if ($step['status'] === TaskStatus::RUNNING->value) {
                // 前端暂停的样式
                $steps[$key]['status'] = TaskStatus::Suspended->value;
            }
        }
    }

    private function sendErrorMessageToClient(int $topicId, string $taskId, string $chatTopicId, string $chatConversationId, string $message): void
    {
        $this->sendMessageToClient(
            topicId: $topicId,
            taskId: $taskId,
            chatTopicId: $chatTopicId,
            chatConversationId: $chatConversationId,
            content: $message,
            messageType: MessageType::Error->value,
            status: TaskStatus::ERROR->value,
            event: '',
            steps: [],
            tool: [],
            attachments: []
        );
    }

    /**
     * 发送消息到客户端.
     *
     * @param int $topicId 话题ID
     * @param string $taskId 任务ID
     * @param string $chatTopicId 聊天话题ID
     * @param string $chatConversationId 聊天会话ID
     * @param string $content 消息内容
     * @param string $messageType 消息类型
     * @param string $status 状态
     * @param string $event 事件
     * @param null|array $steps 步骤
     * @param null|array $tool 工具
     * @param null|array $attachments 附件
     */
    private function sendMessageToClient(
        int $topicId,
        string $taskId,
        string $chatTopicId,
        string $chatConversationId,
        string $content,
        string $messageType,
        string $status,
        string $event,
        ?array $steps = null,
        ?array $tool = null,
        ?array $attachments = null
    ): void {
        // 创建消息对象
        $message = $this->messageBuilder->createSuperAgentMessage(
            $topicId,
            $taskId,
            $content,
            $messageType,
            $status,
            $event,
            $steps,
            $tool,
            $attachments
        );

        // 创建序列实体
        $seqDTO = new MagicSeqEntity();
        $seqDTO->setObjectType(ConversationType::Ai);
        $seqDTO->setContent($message);
        $seqDTO->setSeqType(ChatMessageType::SuperAgentCard);

        $extra = new SeqExtra();
        $extra->setTopicId($chatTopicId);
        $seqDTO->setExtra($extra);
        $seqDTO->setConversationId($chatConversationId);

        $this->logger->info('[Send to Client] 发送给客户端消息: ' . json_encode($message->toArray(), JSON_UNESCAPED_UNICODE));

        // 发送消息
        $this->chatMessageAppService->aiSendMessage($seqDTO, (string) IdGenerator::getSnowId());
    }

    /**
     * 获取上传凭证
     */
    private function getUploadCredential(string $agentUserId, string $organizationCode, string $workDir): array
    {
        $userAuthorization = new MagicUserAuthorization();
        $userAuthorization->setId($agentUserId);
        $userAuthorization->setOrganizationCode($organizationCode);
        $userAuthorization->setUserType(UserType::Ai);
        // sts token 暂时设置 2 天
        return $this->fileAppService->getStsTemporaryCredential($userAuthorization, 'private', $workDir, 3600 * 2);
    }

    /**
     * 获取附件URL.
     */
    private function getAttachmentUrls(string $attachmentsJson, string $organizationCode): array
    {
        if (empty($attachmentsJson)) {
            return [];
        }

        $attachments = Json::decode($attachmentsJson);
        if (empty($attachments)) {
            return [];
        }

        $fileIds = [];
        foreach ($attachments as $attachment) {
            $fileId = $attachment['file_id'] ?? '';
            if (empty($fileId)) {
                continue;
            }
            $fileIds[] = $fileId;
        }

        if (empty($fileIds)) {
            return [];
        }

        $files = [];
        $fileEntities = $this->chatFileDomainService->getFileEntitiesByFileIds($fileIds, null, null, true);
        foreach ($fileEntities as $fileEntity) {
            $files[] = [
                'file_extension' => $fileEntity->getFileExtension(),
                'file_key' => $fileEntity->getFileKey(),
                'file_size' => $fileEntity->getFileSize(),
                'filename' => $fileEntity->getFileName(),
                'display_filename' => $fileEntity->getFileName(),
                'file_tag' => 'user_upload',
                'file_url' => $fileEntity->getExternalUrl(),
            ];
        }
        return $files;
    }

    /**
     * 初始化沙箱环境，获取沙箱ID.
     *
     * @param string $sandboxId 已有的沙箱ID（如果有）
     * @return array [bool $needInit, string $sandboxId] 第一个元素表示是否需要初始化配置，第二个元素为沙箱ID
     */
    private function initSandbox(string $sandboxId): array
    {
        try {
            // 如果已有沙箱ID，先检查沙箱状态
            if (! empty($sandboxId)) {
                // 检查沙箱是否存在
                $result = $this->sandboxService->checkSandboxExists($sandboxId);

                // 记录沙箱状态
                $this->logger->info(sprintf(
                    '检查沙箱状态: sandboxId=%s, code=%d, success=%s, data=%s',
                    $sandboxId,
                    $result->getCode(),
                    $result->isSuccess() ? 'true' : 'false',
                    json_encode($result->getSandboxData()->toArray(), JSON_UNESCAPED_UNICODE)
                ));

                // 如果沙箱存在且状态为 running，直接返回该沙箱
                if ($result->getCode() === SandboxResult::Normal
                    && $result->getSandboxData()->getStatus() === SandboxResult::SandboxRunnig) {
                    $this->logger->info(sprintf('沙箱状态正常(running)，直接使用: sandboxId=%s', $sandboxId));
                    return [false, $sandboxId]; // 不需要初始化配置
                }

                // 记录需要创建新沙箱的原因（调试使用，没有业务逻辑，可忽略）
                if ($result->getCode() === SandboxResult::NotFound) {
                    $this->logger->info(sprintf('沙箱不存在，需创建新沙箱: sandboxId=%s', $sandboxId));
                } elseif ($result->getCode() === SandboxResult::Normal
                           && $result->getSandboxData()->getStatus() === SandboxResult::SandboxExited) {
                    $this->logger->info(sprintf('沙箱状态为 exited，需创建新沙箱: sandboxId=%s', $sandboxId));
                } else {
                    $this->logger->info(sprintf(
                        '沙箱状态异常，需创建新沙箱: sandboxId=%s, status=%s',
                        $sandboxId,
                        $result->getSandboxData()->getStatus()
                    ));
                }
            } else {
                $this->logger->info('沙箱ID为空，需创建新沙箱');
            }

            // 创建新沙箱
            $struct = new SandboxStruct();
            $struct->setSandboxId($sandboxId);
            $result = $this->sandboxService->create($struct);

            // 记录创建结果
            $this->logger->info(sprintf(
                '创建沙箱结果: code=%d, success=%s, message=%s, data=%s, sandboxId=%s',
                $result->getCode(),
                $result->isSuccess() ? 'true' : 'false',
                $result->getMessage(),
                json_encode($result->getSandboxData()->toArray(), JSON_UNESCAPED_UNICODE),
                $result->getSandboxData()->getSandboxId() ?? 'null'
            ));

            // 检查创建结果
            if (! $result->isSuccess()) {
                $this->logger->error(sprintf(
                    '创建沙箱失败: code=%d, message=%s',
                    $result->getCode(),
                    $result->getMessage()
                ));
                return [false, '']; // 创建失败
            }

            // 创建成功，返回需要初始化配置
            return [true, $result->getSandboxData()->getSandboxId()];
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                '沙箱初始化异常: %s, trace=%s',
                $e->getMessage(),
                $e->getTraceAsString()
            ));
            return [false, ''];
        }
    }

    /**
     * 更新任务状态.
     */
    private function updateTaskStatus(
        TaskEntity $task,
        DataIsolation $dataIsolation,
        string $taskId,
        TaskStatus $status,
        string $errMsg = ''
    ): void {
        try {
            $this->taskDomainService->updateTaskStatus(
                dataIsolation: $dataIsolation,
                topicId: $task->getTopicId(),
                status: $status,
                id: $task->getId(),
                taskId: $taskId,
                sandboxId: $task->getSandboxId(),
                errMsg: $errMsg
            );

            // 记录日志
            $this->logger->info(sprintf(
                '任务状态更新完成，任务ID: %s，状态: %s',
                $taskId,
                $status->value
            ));
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                '更新任务状态失败：%s, 任务ID: %s',
                $e->getMessage(),
                $taskId
            ));
            throw $e;
        }
    }

    /**
     * 判断是否为致命错误.
     *
     * @param Throwable $e 异常对象
     * @return bool 是否为致命错误
     */
    private function isFatalError(Throwable $e): bool
    {
        // 连接错误、内存不足、超时等都视为致命错误
        $errorMessage = strtolower($e->getMessage());

        return $e instanceof Error  // PHP致命错误
            || str_contains($errorMessage, 'memory')
            || str_contains($errorMessage, 'timeout')
            || str_contains($errorMessage, 'socket')
            || str_contains($errorMessage, 'closed');
    }

    /**
     * 处理工具中的附件，将其保存到任务文件表和聊天文件表中.
     */
    private function processToolAttachments(?array &$tool, TaskContext $taskContext): void
    {
        if (empty($tool) || empty($tool['attachments'])) {
            return;
        }

        $task = $taskContext->getTask();
        $dataIsolation = $taskContext->getDataIsolation();

        for ($i = 0; $i < count($tool['attachments']); ++$i) {
            $tool['attachments'][$i] = $this->processSingleAttachment(
                $tool['attachments'][$i],
                $task,
                $dataIsolation
            );
        }
    }

    private function processMessageAttachments(?array &$attachments, TaskContext $taskContext): void
    {
        if (empty($attachments)) {
            return;
        }

        $task = $taskContext->getTask();
        $dataIsolation = $taskContext->getDataIsolation();

        for ($i = 0; $i < count($attachments); ++$i) {
            $attachments[$i] = $this->processSingleAttachment(
                $attachments[$i],
                $task,
                $dataIsolation
            );
        }
    }

    /**
     * 处理单个附件，保存到任务文件表和聊天文件表中.
     *
     * @param array $attachment 附件信息
     * @param TaskEntity $task 任务实体
     * @param DataIsolation $dataIsolation 数据隔离对象
     * @return array 处理后的附件信息
     */
    private function processSingleAttachment(array $attachment, TaskEntity $task, DataIsolation $dataIsolation): array
    {
        // 检查必须的字段
        if (empty($attachment['file_key']) || empty($attachment['file_extension']) || empty($attachment['filename'])) {
            $this->logger->warning(sprintf(
                '附件信息不完整，跳过处理，任务ID: %s，附件内容: %s',
                $task->getTaskId(),
                json_encode($attachment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ));
            return [];
        }

        try {
            // 直接调用FileProcessAppService处理附件
            [$fileId, $taskFileEntity] = $this->fileProcessAppService->processFileByFileKey(
                $attachment['file_key'],
                $dataIsolation,
                $attachment,
                $task->getTopicId(),
                (int) $task->getId(),
                $attachment['file_tag'] ?? TaskFileType::PROCESS->value
            );

            // 保存文件ID到附件信息中
            $attachment['file_id'] = (string) $fileId;

            $this->logger->info(sprintf(
                '附件保存成功，文件ID: %s，任务ID: %s，文件名: %s',
                $fileId,
                $task->getTaskId(),
                $attachment['filename']
            ));
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                '处理附件异常: %s, 附件名称: %s, 任务ID: %s',
                $e->getMessage(),
                $attachment['filename'] ?? '未知',
                $task->getTaskId()
            ));
        }

        return $attachment;
    }

    /**
     * 从WebSocket接收到的消息转换为统一消息格式.
     *
     * @param array $message WebSocket接收到的消息
     * @return TopicTaskMessageDTO 统一消息DTO
     */
    private function convertWebSocketMessageToDTO(array $message): TopicTaskMessageDTO
    {
        // 构建元数据值对象
        $metadata = MessageMetadata::fromArray($message['metadata'] ?? []);

        // 创建负载值对象
        $payload = MessagePayload::fromArray($message['payload'] ?? []);

        // 创建DTO
        return new TopicTaskMessageDTO($metadata, $payload);
    }
}
