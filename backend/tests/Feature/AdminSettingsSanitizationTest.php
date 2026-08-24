<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Models\Setting;
use App\Support\AdminPermissions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminSettingsSanitizationTest extends TestCase
{
    /** @var list<array{group_key: string, item_key: string, item_value: mixed}> */
    private array $originalRows = [];

    /** @var list<array{admin: int, role: int}> 本用例建出来的账号与角色，tearDown 逐个删掉 */
    private array $createdAdmins = [];

    /** @var list<array{0: string, 1: string}> */
    private const TOUCHED_KEYS = [
        ['basic', 'site_name'],
        ['basic', 'service_email'],
        ['basic', 'alipay_private_key'],
        ['home_hero', 'slides'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // 测试库不走 RefreshDatabase，这里先备份被改写的设置项，tearDown 原样还原。
        foreach (self::TOUCHED_KEYS as [$group, $key]) {
            $row = DB::table('settings')->where('group_key', $group)->where('item_key', $key)->first(['item_value']);
            $this->originalRows[] = ['group_key' => $group, 'item_key' => $key, 'item_value' => $row?->item_value];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalRows as $row) {
            $query = DB::table('settings')->where('group_key', $row['group_key'])->where('item_key', $row['item_key']);
            $row['item_value'] === null ? $query->delete() : $query->update(['item_value' => $row['item_value']]);
        }

        // 角色被账号引用，必须先删账号再删角色。
        foreach ($this->createdAdmins as $created) {
            DB::table('admin_users')->where('id', $created['admin'])->delete();
            DB::table('roles')->where('id', $created['role'])->delete();
        }

        parent::tearDown();
    }

    public function test_settings_strip_html_tags_before_persisting(): void
    {
        Sanctum::actingAs($this->createAdmin([AdminPermissions::SETTINGS_MANAGE]));

        $this->postJson('/api/v2/admin/settings', [
            'group' => 'basic',
            'settings' => [
                'site_name' => '图拉云</script><img src=x onerror=alert(1)>',
                'service_email' => '<b>support</b>@example.com',
            ],
        ])->assertOk();

        // 站点名会被 SEO 渲染拼进公开页 HTML，落库前就不该带标签，
        // 否则任何漏掉 e() 的渲染路径都会重新变成存储型 XSS。
        $siteName = (string) Setting::getValue('basic', 'site_name');
        $this->assertStringNotContainsString('<img', $siteName);
        $this->assertStringNotContainsString('</script>', $siteName);
        $this->assertStringContainsString('图拉云', $siteName);

        $this->assertSame('support@example.com', Setting::getValue('basic', 'service_email'));
    }

    public function test_sensitive_keys_keep_raw_value(): void
    {
        Sanctum::actingAs($this->createAdmin([AdminPermissions::SETTINGS_MANAGE]));

        // 私钥是任意字节串，剥标签会直接把内容改坏，之后所有签名都会失败。
        $privateKey = "-----BEGIN PRIVATE KEY-----\nMIIB<Ag>EAAoIBAQ==\n-----END PRIVATE KEY-----";

        $this->postJson('/api/v2/admin/settings', [
            'group' => 'basic',
            'settings' => ['alipay_private_key' => $privateKey],
        ])->assertOk();

        $this->assertSame($privateKey, Setting::getValue('basic', 'alipay_private_key'));
    }

    public function test_json_settings_are_not_mangled(): void
    {
        Sanctum::actingAs($this->createAdmin([AdminPermissions::SETTINGS_MANAGE]));

        // home_hero.slides 这类结构化配置整串剥标签会破坏 JSON 结构，必须原样落库。
        $slides = json_encode(
            [['title' => '首页轮播', 'image' => '/uploads/hero.png']],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $this->postJson('/api/v2/admin/settings', [
            'group' => 'home_hero',
            'settings' => ['slides' => $slides],
        ])->assertOk();

        $stored = (string) Setting::getValue('home_hero', 'slides');
        $this->assertSame($slides, $stored);
        $this->assertIsArray(json_decode($stored, true));
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'settings-sanitize-'.$suffix,
            'label' => 'Settings Sanitize',
            'permissions' => $permissions,
        ]);

        $admin = AdminUser::query()->create([
            'username' => 'settings-sanitize-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Settings Sanitize',
            'email' => 'settings-sanitize-'.$suffix.'@example.com',
            'status' => 1,
        ]);

        $this->createdAdmins[] = ['admin' => (int) $admin->id, 'role' => (int) $role->id];

        return $admin;
    }
}
