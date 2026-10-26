<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client\V2;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\V2\Ai\ConfirmActionRequest;
use App\Http\Requests\Client\V2\Ai\CreateConversationRequest;
use App\Http\Requests\Client\V2\Ai\ListConversationsRequest;
use App\Http\Requests\Client\V2\Ai\SendMessageRequest;
use App\Http\Requests\Client\V2\Ai\UpdateConversationRequest;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiToolInvocation;
use App\Models\User;
use App\Services\Ai\AiChatService;
use App\Services\Ai\AiConfig;
use App\Services\Ai\AiConversationService;
use App\Services\Ai\AiQuotaService;
use App\Services\Ai\AiToolExecutor;
use App\Services\Ai\Tools\AiToolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 用户端 AI 助手接口：薄层，业务全在 Service。
 *
 * 流式端点用 text/event-stream，事件类型见 stream()；非流式端点保持统一 JSON 外层，
 * 前端在流式失败时自动回退到它。
 */
class AiController extends Controller
{
    public function __construct(
        private readonly AiConfig $config,
        private readonly AiConversationService $conversations,
        private readonly AiChatService $chat,
        private readonly AiToolRegistry $registry,
        private readonly AiQuotaService $quota,
        private readonly AiToolExecutor $executor,
    ) {}

    public function bootstrap(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $channel = (string) $request->query('channel', 'console');
        $channel = in_array($channel, ['console', 'site', 'admin'], true) ? $channel : 'console';

        $enabled = $this->config->enabled() && $this->config->channelEnabled($channel) && $this->config->isConfigured();
        $mode = $this->conversations->resolvePermissionMode(null);

        return $this->success([
            'enabled' => $enabled,
            'channels' => [
                'console' => $this->config->channelEnabled('console'),
                'site' => $this->config->channelEnabled('site'),
                'admin' => $this->config->channelEnabled('admin'),
            ],
            'permission_mode' => $mode,
            'allowed_permission_modes' => $this->conversations->allowedPermissionModes(),
            'auto_approve_beta' => true,
            'model_label' => $this->config->model(),
            'protocol' => $this->config->protocol(),
            'quota' => $this->quota->snapshot($user),
            'tools' => $enabled ? $this->registry->catalog($mode) : [],
        ]);
    }

    public function conversations(ListConversationsRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->assertEnabled((string) ($request->validated()['channel'] ?? 'console'));
        $paginator = $this->conversations->listForUser($user, (int) ($request->validated()['page'] ?? 1), $request->perPage(20));

        return $this->paginate($paginator, null, [
            'list' => array_map(
                fn (AiConversation $conversation): array => $this->conversations->presentConversation($conversation),
                $paginator->items(),
            ),
        ]);
    }

    public function storeConversation(CreateConversationRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();
        $this->assertEnabled((string) ($data['channel'] ?? 'console'));

        $conversation = $this->conversations->createForUser(
            $user,
            (string) ($data['channel'] ?? 'console'),
            isset($data['subject_type']) ? (string) $data['subject_type'] : null,
            isset($data['subject_id']) ? (int) $data['subject_id'] : null,
            isset($data['permission_mode']) ? (string) $data['permission_mode'] : null,
            (string) ($data['title'] ?? ''),
        );

        return $this->success($this->conversations->presentConversation($conversation), '已创建对话');
    }

    public function showConversation(Request $request, int $conversation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $model = $this->conversations->findForUser($user, $conversation);
        $page = max((int) $request->query('page', '1'), 1);
        $pageSize = min(max((int) $request->query('page_size', '50'), 1), 100);
        $messages = $this->conversations->messages($model, $page, $pageSize);

        return $this->success([
            'conversation' => $this->conversations->presentConversation($model),
            'messages' => array_map(
                fn (AiMessage $message): array => $this->conversations->presentMessage($message),
                array_values($messages->items()),
            ),
            'pending_actions' => $this->conversations->pendingActions($model),
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $messages->total(),
        ]);
    }

    public function updateConversation(UpdateConversationRequest $request, int $conversation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $model = $this->conversations->findForUser($user, $conversation);
        $data = $request->validated();

        if (isset($data['title'])) {
            $model->title = mb_substr(trim((string) $data['title']), 0, 120);
        }
        if (isset($data['permission_mode'])) {
            $model->permission_mode = $this->conversations->resolvePermissionMode((string) $data['permission_mode']);
        }
        $model->save();

        return $this->success($this->conversations->presentConversation($model), '已更新对话');
    }

    public function destroyConversation(Request $request, int $conversation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $model = $this->conversations->findForUser($user, $conversation);

        // 软删：保留审计记录，只把状态置为已删除
        $model->status = AiConversation::STATUS_DELETED;
        $model->save();

        return $this->success(null, '已删除对话');
    }

    public function messages(SendMessageRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();
        $channel = $request->channel();
        $this->assertEnabled($channel);

        $conversation = $this->resolveConversation($user, $data, $channel);
        $result = $this->chat->run($user, $conversation, (string) $data['message']);

        return $this->success([
            'conversation_id' => (int) $conversation->id,
            'message_id' => $result['message_id'],
            'content' => $result['content'],
            'reasoning' => $result['reasoning'] ?? '',
            'tool_runs' => $result['tool_runs'],
            'pending_actions' => $result['pending_actions'],
            'permission_mode' => $result['permission_mode'],
            'usage' => $result['usage'],
            'quota' => $this->quota->snapshot($user),
        ]);
    }

    public function stream(SendMessageRequest $request): StreamedResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();
        $channel = $request->channel();

        try {
            $this->assertEnabled($channel);
            $conversation = $this->resolveConversation($user, $data, $channel);
        } catch (BusinessException $exception) {
            return $this->error($exception->getErrorCode(), $exception->getMessage());
        }

        $text = (string) $data['message'];
        $timeout = $this->config->requestTimeoutSeconds();
        $conversationId = (int) $conversation->id;

        return response()->stream(function () use ($user, $conversation, $text, $timeout, $conversationId): void {
            // 关闭所有输出缓冲，否则 nginx/php-fpm 会把 SSE 攒到结束才发
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            if (function_exists('set_time_limit')) {
                @set_time_limit($timeout + 30);
            }
            ignore_user_abort(false);

            $emit = static function (string $event, array $payload): void {
                echo 'event: ' . $event . "\n";
                echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n\n";
                flush();
            };

            $emit('meta', ['conversation_id' => $conversationId, 'message_id' => null]);

            try {
                $result = $this->chat->run(
                    $user,
                    $conversation,
                    $text,
                    static function (string $chunk) use ($emit): void {
                        if (connection_aborted() === 1) {
                            throw new BusinessException('客户端已断开', 49900, 499);
                        }
                        $emit('delta', ['text' => $chunk]);
                    },
                    static function (string $chunk) use ($emit): void {
                        if (connection_aborted() === 1) {
                            throw new BusinessException('客户端已断开', 49900, 499);
                        }
                        $emit('reasoning', ['text' => $chunk]);
                    },
                    static function (array $toolRun) use ($emit): void {
                        $emit('tool', $toolRun);
                    },
                    static function (array $pending) use ($emit): void {
                        $emit('pending_action', $pending);
                    },
                );

                $emit('done', [
                    'conversation_id' => $conversationId,
                    'message_id' => $result['message_id'],
                    'content' => $result['content'],
                    'reasoning' => $result['reasoning'] ?? '',
                    'tool_runs' => $result['tool_runs'],
                    'pending_actions' => $result['pending_actions'],
                    'permission_mode' => $result['permission_mode'],
                    'usage' => $result['usage'],
                    'quota' => $this->quota->snapshot($user),
                ]);
            } catch (BusinessException $exception) {
                $emit('error', ['code' => $exception->getErrorCode(), 'message' => $exception->getMessage()]);
            } catch (\Throwable $exception) {
                Log::warning('ai.stream_failed', [
                    'conversation_id' => $conversationId,
                    'user_id' => (int) $user->id,
                    'error' => $exception->getMessage(),
                ]);
                $emit('error', ['code' => 50000, 'message' => 'AI 响应中断，请稍后重试']);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    public function confirmAction(ConfirmActionRequest $request, int $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $invocation = $this->findInvocation($user, $action);
        $result = $this->executor->confirm($invocation, (string) $request->validated()['confirm_token']);

        if ($invocation->conversation instanceof AiConversation) {
            $this->conversations->appendMessage(
                $invocation->conversation,
                AiMessage::ROLE_SYSTEM,
                '【操作结果】' . (string) $result['summary'],
            );
        }

        return $this->success([
            'action_id' => (int) $invocation->id,
            'status' => $result['status'],
            'summary' => (string) $result['summary'],
            'conversation_id' => (int) $invocation->conversation_id,
        ], $result['status'] === 'done' ? '已执行' : '执行失败');
    }

    public function rejectAction(Request $request, int $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $invocation = $this->findInvocation($user, $action);
        $this->executor->reject($invocation);

        if ($invocation->conversation instanceof AiConversation) {
            $this->conversations->appendMessage(
                $invocation->conversation,
                AiMessage::ROLE_SYSTEM,
                '【操作结果】用户已取消该操作',
            );
        }

        return $this->success([
            'action_id' => (int) $invocation->id,
            'status' => 'rejected',
            'summary' => '用户已取消该操作',
            'conversation_id' => (int) $invocation->conversation_id,
        ], '已取消');
    }

    /**
     * 会话解析：带了 conversation_id 就复用（且必须是本人），否则新建。
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveConversation(User $user, array $data, string $channel): AiConversation
    {
        $conversationId = (int) ($data['conversation_id'] ?? 0);
        if ($conversationId > 0) {
            $conversation = $this->conversations->findForUser($user, $conversationId);
            if (isset($data['permission_mode'])) {
                $conversation->permission_mode = $this->conversations->resolvePermissionMode((string) $data['permission_mode']);
                $conversation->save();
            }

            return $conversation;
        }

        $subject = $data['subject'] ?? null;

        return $this->conversations->createForUser(
            $user,
            $channel,
            is_array($subject) && ! empty($subject['type']) ? (string) $subject['type'] : null,
            is_array($subject) && ! empty($subject['id']) ? (int) $subject['id'] : null,
            isset($data['permission_mode']) ? (string) $data['permission_mode'] : null,
        );
    }

    private function findInvocation(User $user, int $id): AiToolInvocation
    {
        $invocation = AiToolInvocation::query()
            ->with(['conversation'])
            ->where('user_id', (int) $user->id)
            ->find($id);

        if (! $invocation instanceof AiToolInvocation) {
            throw new BusinessException('待确认操作不存在', 40400, 404);
        }

        return $invocation;
    }

    private function assertEnabled(string $channel): void
    {
        if (! $this->config->enabled()) {
            throw new BusinessException('AI 助手未启用', 42200, 422);
        }
        if (! $this->config->channelEnabled($channel)) {
            throw new BusinessException('当前入口未开放 AI 助手', 42200, 422);
        }
        if (! $this->config->isConfigured()) {
            throw new BusinessException('AI 网关尚未配置，请联系管理员', 42200, 422);
        }
    }
}
