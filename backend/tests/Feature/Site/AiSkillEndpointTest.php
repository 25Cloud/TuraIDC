<?php

declare(strict_types=1);

namespace Tests\Feature\Site;

use App\Models\ApiKey;
use App\Services\OpenApi\ApiKeyService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * AI Skill 发布面：公开、无需鉴权、只发说明书。
 *
 * 关键约束：`{{base_url}}` 必须替换成当前站点，否则用户装下去的 skill 指向的是
 * 占位符而不是可用地址；未知 slug 与路径穿越必须 404，不能读到 resources 之外。
 */
class AiSkillEndpointTest extends TestCase
{
    use DatabaseTransactions;

    public function test_skill_markdown_is_public_and_base_url_is_rendered(): void
    {
        $response = $this->get('/api/v2/site/ai-skill/turaidc/SKILL.md');

        $response->assertOk();
        $this->assertStringContainsString('text/markdown', (string) $response->headers->get('Content-Type'));

        $body = (string) $response->getContent();
        $this->assertStringContainsString('name: turaidc', $body);
        $this->assertStringNotContainsString('{{base_url}}', $body);
        $this->assertStringContainsString(rtrim((string) config('app.url'), '/'), $body);
        $this->assertSame('1.0.0', $response->headers->get('X-Skill-Version'));
    }

    public function test_manifest_is_valid_json_with_tools_and_placeholders_rendered(): void
    {
        $response = $this->get('/api/v2/site/ai-skill/turaidc/manifest.json');

        $response->assertOk();

        $raw = (string) $response->getContent();
        $this->assertStringNotContainsString('{{base_url}}', $raw);

        $manifest = json_decode($raw, true);
        $this->assertIsArray($manifest);
        $this->assertSame('turaidc', $manifest['name']);
        $this->assertSame('bearer', $manifest['auth']['scheme']);
        $this->assertNotEmpty($manifest['tools']);

        $names = array_column($manifest['tools'], 'name');
        foreach (['list_services', 'renew_service', 'create_ticket', 'reset_service_password', 'get_balance'] as $expected) {
            $this->assertContains($expected, $names);
        }

        // 每个工具都要能说清「怎么调」：方法、路径、作用域、风险级别一个都不能缺
        foreach ($manifest['tools'] as $tool) {
            $this->assertArrayHasKey('method', $tool);
            $this->assertArrayHasKey('path', $tool);
            $this->assertArrayHasKey('scope', $tool);
            $this->assertContains($tool['risk'], ['read', 'write']);
        }
    }

    public function test_bare_slug_serves_the_same_document(): void
    {
        $this->get('/api/v2/site/ai-skill/turaidc')
            ->assertOk()
            ->assertHeader('X-Skill-Slug', 'turaidc');
    }

    public function test_unknown_skill_returns_404(): void
    {
        $this->get('/api/v2/site/ai-skill/does-not-exist/SKILL.md')->assertNotFound();
    }

    public function test_path_traversal_slug_is_rejected(): void
    {
        $this->get('/api/v2/site/ai-skill/..%2F..%2F.env/SKILL.md')->assertNotFound();
    }

    public function test_install_json_describes_how_to_connect_an_agent(): void
    {
        $response = $this->get('/api/v2/site/ai-skill/turaidc/install.json')->assertOk();

        $data = (array) $response->json('data');
        $base = rtrim((string) config('app.url'), '/');

        $this->assertSame('turaidc', $data['slug']);
        $this->assertSame('1.0.0', $data['version']);
        $this->assertSame($base.'/api/v2/site/ai-skill/turaidc/SKILL.md', $data['skill_url']);
        $this->assertSame($base.'/api/v2/site/ai-skill/turaidc/manifest.json', $data['manifest_url']);
        $this->assertSame($base.'/api/v2/open', $data['api_base_url']);

        // 卡片上要能一键复制的两条命令，必须真的包含可用的 skill 地址
        $this->assertStringContainsString($data['skill_url'], $data['commands']['install_by_url']);
        $this->assertStringContainsString($data['skill_url'], $data['commands']['curl']);

        // 需要的密钥作用域必须与 ApiKeyService 的域白名单一致，否则用户按卡片建 key 会少权限
        foreach ($data['required_scopes'] as $scope) {
            $this->assertContains($scope, ApiKeyService::SCOPE_DOMAINS);
        }
    }

    #[Test]
    public function test_install_json_404s_for_unknown_skill(): void
    {
        $this->get('/api/v2/site/ai-skill/no-such-skill/install.json')->assertNotFound();
    }

    /**
     * 部署方可换：品牌名、控制台地址、接口基址、密钥前缀全部由部署配置决定，
     * 出站文档里不许残留占位符，也不许出现写死的项目品牌。
     */
    #[Test]
    public function test_documents_are_brand_neutral_and_fully_rendered(): void
    {
        $base = rtrim((string) config('app.url'), '/');

        $markdown = $this->get('/api/v2/site/ai-skill/turaidc/SKILL.md')->assertOk()->getContent();
        $manifest = $this->get('/api/v2/site/ai-skill/turaidc/manifest.json')->assertOk()->getContent();

        foreach (['SKILL.md' => $markdown, 'manifest.json' => $manifest] as $label => $body) {
            $this->assertStringNotContainsString('{{', $body, $label.' 里不能残留未替换的占位符');
            $this->assertStringNotContainsString('TuraIDC', $body, $label.' 里不能写死项目品牌');
        }

        // 接口基址、密钥前缀都必须来自当前部署
        $this->assertStringContainsString($base.'/api/v2/open', $markdown);
        $this->assertStringContainsString(ApiKey::KEY_PREFIX, $markdown);

        $decoded = json_decode($manifest, true);
        $this->assertIsArray($decoded, 'manifest.json 必须是合法 JSON');
        $this->assertSame('turaidc', $decoded['name'], 'manifest 的 name 应等于 skill slug');
        $this->assertSame($base.'/api/v2/open', $decoded['base_url']);
        $this->assertSame(ApiKey::KEY_PREFIX.'*', $decoded['auth']['key_format']);
    }

    /**
     * 接入卡片要能显示当前站点自己的名字与控制台地址，而不是固定文案。
     */
    #[Test]
    public function test_install_json_exposes_deployment_brand_and_console(): void
    {
        config(['app.console_url' => 'https://console.example.test']);

        $data = (array) $this->get('/api/v2/site/ai-skill/turaidc/install.json')->assertOk()->json('data');

        $this->assertNotSame('', (string) $data['site_name'], '站点名不能为空');
        $this->assertSame(ApiKey::KEY_PREFIX, $data['key_prefix']);
        $this->assertSame('https://console.example.test', $data['console_url']);
        $this->assertSame('https://console.example.test/client/api-keys', $data['console_keys_url']);
    }

    /**
     * 没配控制台地址时不许拿官网地址兜底：官网 SPA 没有 /client/api-keys 这个页面，
     * 兜出来的链接点进去是白页。此时 console_url 留空，文档只写相对路径。
     */
    #[Test]
    public function test_console_url_is_empty_when_not_configured(): void
    {
        config(['app.console_url' => null]);

        $data = (array) $this->get('/api/v2/site/ai-skill/turaidc/install.json')->assertOk()->json('data');
        $this->assertSame('', $data['console_url']);
        $this->assertSame('/client/api-keys', $data['console_keys_url']);

        $markdown = $this->get('/api/v2/site/ai-skill/turaidc/SKILL.md')->assertOk()->getContent();
        $this->assertStringContainsString('`/client/api-keys`', $markdown);
        $this->assertStringNotContainsString((string) config('app.frontend_url').'/client/api-keys', $markdown);
    }
}
