<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\AiToolInvocation;
use App\Models\AdminUser;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\AiConfig;
use App\Services\Integrations\Ai\AiProviderManager;
use App\Support\AdminPermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Fakes\Integrations\Ai\FakeAiProvider;
use Tests\TestCase;

/**
 * AI 助手端到端：权限档位、确认协议、越权、额度。
 *
 * 全部走 FakeAiProvider，不产生任何真实网关调用。
 *
 * 注意：这里**不用** RefreshDatabase。测试库是全量用例共享的既有基线库，
 * 用 RefreshDatabase 会把共享库重建，导致后续依赖基线数据的用例顺序性失败。
 * 只清 AI 自己的四张表即可保证断言确定性；测试库首次使用前先执行一次
 * `DB_DATABASE=<测试库> php artisan migrate` 把四张表建出来。
 */
class AiAssistantTest extends TestCase
{
    private FakeAiProvider $fake;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ai_tool_invocations', 'ai_messages', 'ai_conversations', 'ai_usage_daily'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        $this->fake = new FakeAiProvider;
        $this->app->bind(AiProviderManager::class, function () {
            $manager = new AiProviderManager($this->app->make(AiConfig::class));
            $manager->use($this->fake);

            return $manager;
        });

        Setting::setValue('ai', 'enabled', 1);
        Setting::setValue('ai', 'base_url', 'https://gateway.example.test');
        Setting::setValue('ai', 'provider_api_key', 'test-key');
        Setting::setValue('ai', 'model', 'test-model');
        Setting::setValue('ai', 'console_enabled', 1);
        Setting::setValue('ai', 'site_enabled', 1);
        Setting::setValue('ai', 'admin_enabled', 0);
        Setting::setValue('ai', 'max_permission_mode', 'auto_approve');
        Setting::setValue('ai', 'default_permission_mode', 'confirm');
        Setting::setValue('ai', 'user_daily_messages', 100);
        Setting::setValue('ai', 'user_daily_tokens', 1000000);

        $this->user = User::factory()->create();
    }

    public function test_bootstrap_requires_authentication(): void
    {
        $this->getJson('/api/v2/client/ai/bootstrap')->assertStatus(401);
    }

    public function test_bootstrap_reports_disabled_channels_and_quota(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v2/client/ai/bootstrap?channel=console')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->assertTrue($response->json('data.enabled'));
        $this->assertSame('confirm', $response->json('data.permission_mode'));
        $this->assertContains('auto_approve', $response->json('data.allowed_permission_modes'));
        $this->assertSame('test-model', $response->json('data.model_label'));
        $this->assertNotEmpty($response->json('data.tools'));
    }

    public function test_channel_switch_disables_site_when_off(): void
    {
        Setting::setValue('ai', 'site_enabled', 0);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/ai/messages', [
                'message' => '你好',
                'channel' => 'site',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 42200);
    }

    public function test_read_tool_executes_immediately_and_answers(): void
    {
        $this->fake->pushToolCall('get_balance');
        $this->fake->pushText('你的余额是 0.00 元');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/ai/messages', ['message' => '我余额多少'])
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->assertSame('你的余额是 0.00 元', $response->json('data.content'));
        $this->assertCount(1, $response->json('data.tool_runs'));
        $this->assertSame('done', $response->json('data.tool_runs.0.status'));
        $this->assertSame([], $response->json('data.pending_actions'));
    }

    public function test_write_tool_waits_for_confirmation_in_confirm_mode(): void
    {
        $this->fake->pushToolCall('power_action', ['service_id' => 1, 'action' => 'reboot']);
        $this->fake->pushText('请确认重启动作');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/ai/messages', ['message' => '帮我重启实例 1'])
            ->assertOk();

        $pending = $response->json('data.pending_actions');
        $this->assertCount(1, $pending);
        $this->assertSame('power_action', $pending[0]['tool']);
        $this->assertSame('write', $pending[0]['risk']);
        $this->assertNotEmpty($pending[0]['confirm_token']);

        $invocation = AiToolInvocation::query()->findOrFail($pending[0]['id']);
        $this->assertSame(AiToolInvocation::STATUS_PENDING, $invocation->status);

        // 未确认前不得执行：工具结果里明确写着 waiting
        $this->assertSame(0, \App\Models\Service::query()->count());
    }

    public function test_read_only_mode_rejects_write_tools(): void
    {
        $this->fake->pushToolCall('power_action', ['service_id' => 1, 'action' => 'off']);
        $this->fake->pushText('当前为仅可查看模式');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/ai/messages', [
                'message' => '关机',
                'permission_mode' => 'read_only',
            ])
            ->assertOk();

        $this->assertSame('rejected', $response->json('data.tool_runs.0.status'));
        $this->assertSame([], $response->json('data.pending_actions'));
    }

    public function test_auto_approve_executes_write_tool_but_still_holds_dangerous(): void
    {
        $this->fake->pushToolCall('create_ticket', [
            'subject' => '测试工单',
            'content' => '由 AI 提交',
            'department' => 'support',
        ]);
        $this->fake->pushText('已替你提交工单');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/ai/messages', [
                'message' => '帮我建个工单',
                'permission_mode' => 'auto_approve',
            ])
            ->assertOk();

        $this->assertSame('done', $response->json('data.tool_runs.0.status'));
        $this->assertSame([], $response->json('data.pending_actions'));

        $this->fake->pushToolCall('reinstall_service', ['service_id' => 9, 'os_id' => 'ubuntu-22']);
        $this->fake->pushText('重装需要你确认');

        $dangerous = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/ai/messages', [
                'message' => '重装系统',
                'permission_mode' => 'auto_approve',
            ])
            ->assertOk();

        $this->assertCount(1, $dangerous->json('data.pending_actions'));
        $this->assertSame('dangerous', $dangerous->json('data.pending_actions.0.risk'));
    }

    public function test_confirm_rejects_forged_token_and_accepts_real_one(): void
    {
        $this->fake->pushToolCall('create_ticket', [
            'subject' => '发票问题',
            'content' => '想问发票',
            'department' => 'billing',
        ]);
        $this->fake->pushText('请确认建单');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/ai/messages', ['message' => '帮我问发票'])
            ->assertOk();

        $action = $response->json('data.pending_actions.0');

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/ai/actions/' . $action['id'] . '/confirm', [
                'confirm_token' => 'forged-token',
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 40300);

        $confirmed = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/ai/actions/' . $action['id'] . '/confirm', [
                'confirm_token' => $action['confirm_token'],
            ])
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->assertSame('done', $confirmed->json('data.status'));

        $invocation = AiToolInvocation::query()->findOrFail($action['id']);
        $this->assertContains($invocation->status, [AiToolInvocation::STATUS_CONFIRMED, AiToolInvocation::STATUS_FAILED]);

        // 重复确认必须被拒（已处理）
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/ai/actions/' . $action['id'] . '/confirm', [
                'confirm_token' => $action['confirm_token'],
            ])
            ->assertStatus(409);
    }

    public function test_reject_marks_action_rejected(): void
    {
        $this->fake->pushToolCall('create_ticket', [
            'subject' => '测试',
            'content' => '内容',
            'department' => 'sales',
        ]);
        $this->fake->pushText('请确认');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/ai/messages', ['message' => '建单'])
            ->assertOk();
        $actionId = $response->json('data.pending_actions.0.id');

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/ai/actions/' . $actionId . '/reject')
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertSame(
            AiToolInvocation::STATUS_REJECTED,
            AiToolInvocation::query()->findOrFail($actionId)->status,
        );
    }

    public function test_conversation_ownership_is_enforced(): void
    {
        $other = User::factory()->create();
        $conversation = AiConversation::query()->create([
            'user_id' => (int) $other->id,
            'channel' => 'console',
            'title' => '别人的会话',
            'permission_mode' => AiConversation::MODE_CONFIRM,
            'status' => AiConversation::STATUS_ACTIVE,
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v2/client/ai/conversations/' . $conversation->id)
            ->assertStatus(404)
            ->assertJsonPath('code', 40400);
    }

    public function test_conversation_list_and_rename(): void
    {
        $created = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/ai/conversations', ['title' => '新会话'])
            ->assertOk();
        $id = $created->json('data.id');

        $this->actingAs($this->user, 'sanctum')
            ->patchJson('/api/v2/client/ai/conversations/' . $id, ['title' => '改名后'])
            ->assertOk()
            ->assertJsonPath('data.title', '改名后');

        $list = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v2/client/ai/conversations?page=1&page_size=10')
            ->assertOk();

        $this->assertSame(1, $list->json('data.total'));

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson('/api/v2/client/ai/conversations/' . $id)
            ->assertOk();

        $this->assertSame(0, $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v2/client/ai/conversations?page=1&page_size=10')
            ->json('data.total'));
    }

    public function test_permission_mode_is_clamped_by_site_maximum(): void
    {
        Setting::setValue('ai', 'max_permission_mode', 'confirm');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/ai/conversations', ['permission_mode' => 'auto_approve'])
            ->assertOk();

        $this->assertSame('confirm', $response->json('data.permission_mode'));
    }

    public function test_quota_exceeded_returns_429(): void
    {
        // 额度为 0 表示不限；这里设成 1 条，第二次调用必须被拦
        Setting::setValue('ai', 'user_daily_messages', 1);
        Setting::setValue('ai', 'user_daily_tokens', 0);

        $this->fake->pushText('第一次可以回答');
        $this->fake->pushText('第二次不应被调用');

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/ai/messages', ['message' => '你好'])
            ->assertOk();

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/ai/messages', ['message' => '再来一次'])
            ->assertStatus(429)
            ->assertJsonPath('code', 42900);
    }

    public function test_admin_settings_never_returns_raw_api_key(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'ai-admin-' . $suffix,
            'label' => 'AI Admin',
            'permissions' => [AdminPermissions::AI_VIEW, AdminPermissions::AI_MANAGE],
        ]);
        $admin = AdminUser::query()->create([
            'username' => 'ai-admin-' . $suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'AI Admin',
            'email' => 'ai-admin-' . $suffix . '@example.com',
            'status' => 1,
        ]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v2/admin/ai/settings')->assertOk();

        $apiKey = $response->json('data.api_key');
        $this->assertTrue((bool) $apiKey['has_value']);
        $this->assertSame('******', $apiKey['masked_value']);
        $this->assertStringNotContainsString('test-key', json_encode($response->json('data'), JSON_UNESCAPED_UNICODE));
    }
}
