<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site\V2;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * AI Skill 发布面（公开、无需鉴权）。
 *
 * 用户把它自己的 harness / agent 接到本系统上：一个安装链接（SKILL.md）说明怎么用，
 * 一份 manifest.json 给它机器可读的工具清单。真正的业务调用走开放接口
 * `/api/v2/open/*`（Bearer 密钥 + 作用域），这里只发「说明书」，不碰任何业务数据。
 *
 * 文件存放在 resources/ai-skill/{slug}/ 下，`{{base_url}}` 占位在出站时替换成
 * 当前站点地址，因此同一个 skill 包在任何部署域名下装下去都是直接可用的。
 */
class AiSkillController extends Controller
{
    /** skill slug 白名单形态：只允许小写字母、数字与连字符，杜绝路径穿越 */
    private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,63}$/';

    public function show(string $skill): Response
    {
        return $this->serve($skill, 'SKILL.md', 'text/markdown; charset=UTF-8');
    }

    public function manifest(string $skill): Response
    {
        return $this->serve($skill, 'manifest.json', 'application/json; charset=UTF-8');
    }

    /**
     * 接入信息：给控制台「AI Skill 接入」卡片用的一份自描述数据。
     *
     * 站点地址由后端按 config('app.url') 生成，前端不用猜域名；更换部署域名后
     * 卡片里的链接会自动跟着变，不会出现「文档指向老域名」的漂移。
     */
    public function install(string $skill): JsonResponse
    {
        $body = $this->load($skill, 'SKILL.md');

        if ($body === null) {
            abort(404, 'skill 不存在');
        }

        $base = $this->baseUrl();
        $skillUrl = $base.'/api/v2/site/ai-skill/'.$skill.'/SKILL.md';

        return $this->success([
            'slug' => $skill,
            'version' => $this->resolveVersion($body),
            'skill_url' => $skillUrl,
            'manifest_url' => $base.'/api/v2/site/ai-skill/'.$skill.'/manifest.json',
            'api_base_url' => $base.'/api/v2/open',
            'required_scopes' => ['products', 'orders', 'services', 'finance', 'tickets'],
            'commands' => [
                'install_by_url' => '/skills install '.$skillUrl,
                'curl' => 'mkdir -p ~/.claude/skills/'.$skill
                    .' && curl -fsSL '.$skillUrl.' -o ~/.claude/skills/'.$skill.'/SKILL.md',
            ],
        ]);
    }

    private function serve(string $skill, string $filename, string $contentType): Response
    {
        $body = $this->load($skill, $filename);

        if ($body === null) {
            abort(SymfonyResponse::HTTP_NOT_FOUND, 'skill 不存在');
        }

        $etag = '"'.substr(hash('sha256', $body), 0, 16).'"';

        $response = new Response($body, SymfonyResponse::HTTP_OK, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=300',
            'ETag' => $etag,
            'X-Skill-Slug' => $skill,
            'X-Skill-Version' => $this->resolveVersion($body),
        ]);

        $response->setEtag(trim($etag, '"'));
        $response->isNotModified(request());

        return $response;
    }

    private function load(string $skill, string $filename): ?string
    {
        if (preg_match(self::SLUG_PATTERN, $skill) !== 1) {
            return null;
        }

        $path = resource_path('ai-skill/'.$skill.'/'.$filename);

        if (! is_file($path)) {
            return null;
        }

        $body = @file_get_contents($path);

        if ($body === false || $body === '') {
            return null;
        }

        return str_replace('{{base_url}}', $this->baseUrl(), $body);
    }

    /**
     * 站点地址以配置为准：反代后 `url('/')` 会跟着请求 scheme 走，
     * 在 https 站点上经内网 http 探测时可能写出 http 链接，装进 agent 后就一直错下去。
     */
    private function baseUrl(): string
    {
        $configured = trim((string) config('app.url'));

        return rtrim($configured !== '' ? $configured : url('/'), '/');
    }

    /**
     * 从 SKILL.md 的 YAML frontmatter 取 version，取不到时不编造。
     */
    private function resolveVersion(string $body): string
    {
        if (preg_match('/^---\s*$(.*?)^---\s*$/ms', $body, $frontmatter) !== 1) {
            return '';
        }

        return preg_match('/^version:\s*(\S+)\s*$/m', $frontmatter[1], $match) === 1
            ? trim($match[1], "\"'")
            : '';
    }
}