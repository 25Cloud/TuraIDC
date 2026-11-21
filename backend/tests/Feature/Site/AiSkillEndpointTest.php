<?php

declare(strict_types=1);

namespace Tests\Feature\Site;

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
            $this->assertContains($scope, \App\Services\OpenApi\ApiKeyService::SCOPE_DOMAINS);
        }
    }

    #[Test]
    public function test_install_json_404s_for_unknown_skill(): void
    {
        $this->get('/api/v2/site/ai-skill/no-such-skill/install.json')->assertNotFound();
    }
}