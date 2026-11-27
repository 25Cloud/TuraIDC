<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site\V2;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Setting;
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
 * 文件存放在 resources/ai-skill/{slug}/ 下。**品牌相关的一切都是占位符**——
 * 站点名、控制台地址、接口基址、密钥前缀、slug 全部在出站时按当前部署的实际配置替换。
 * 任何站长把系统装到自己的域名下，既不用改这份文档，也不用改控制器：
 * 改后台站点名、改 app.url / app.frontend_url、改目录名，文档就自动跟着变。
 */
class AiSkillController extends Controller
{
    /** skill slug 白名单形态：只允许小写字母、数字与连字符，杜绝路径穿越 */
    private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,63}$/';

    /** 控制台里「API 凭据」页的路径：文档与接入卡片指向同一处 */
    private const CONSOLE_KEYS_PATH = '/client/api-keys';

    /** 站点名一处都取不到时的兜底，避免文档里出现空品牌 */
    private const FALLBACK_SITE_NAME = 'IDC';

    public function show(string $skill): Response
    {
        return $this->serve($skill, 'SKILL.md', 'text/markdown; charset=UTF-8');
    }

    public function manifest(string $skill): Response
    {
        return $this->serve($skill, 'manifest.json', 'application/json; charset=UTF-8');
    }

    /**
     * 接入信息：给控制台「AI Skill 接入」卡片与官网特色位用的一份自描述数据。
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
            'site_name' => $this->siteName(),
            'version' => $this->resolveVersion($body),
            'skill_url' => $skillUrl,
            'manifest_url' => $base.'/api/v2/site/ai-skill/'.$skill.'/manifest.json',
            'api_base_url' => $base.'/api/v2/open',
            'console_url' => $this->consoleUrl(),
            'console_keys_url' => $this->consoleUrl().self::CONSOLE_KEYS_PATH,
            'key_prefix' => ApiKey::KEY_PREFIX,
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

        // strtr 一次性替换：替换结果里即使出现别的占位符字样也不会被二次解析，
        // 站点名里带 {{...}} 这类字符时不会把文档搞坏。
        return strtr($body, $this->placeholders($skill));
    }

    /**
     * 出站占位符表。新占位符只在这里加一行，文档里就能用。
     *
     * @return array<string, string>
     */
    private function placeholders(string $skill): array
    {
        $base = $this->baseUrl();

        return [
            '{{base_url}}' => $base,
            '{{api_base}}' => $base.'/api/v2/open',
            '{{skill_slug}}' => $skill,
            // 站点名要落进 manifest.json 的字符串与 Markdown 标题里，先做内联转义
            '{{site_name}}' => $this->escapeInline($this->siteName()),
            '{{console_url}}' => $this->consoleUrl(),
            '{{console_keys_path}}' => self::CONSOLE_KEYS_PATH,
            '{{key_prefix}}' => ApiKey::KEY_PREFIX,
        ];
    }

    /**
     * 站点名取后台设置，其次取部署配置；都为空时用中性兜底。
     */
    private function siteName(): string
    {
        try {
            $configured = trim((string) Setting::getValue('basic', 'site_name', ''));
        } catch (\Throwable) {
            $configured = '';
        }

        if ($configured === '') {
            $configured = trim((string) config('idc.site_name', ''));
        }

        return $configured !== '' ? $configured : self::FALLBACK_SITE_NAME;
    }

    /**
     * 控制台根地址：只认 app.console_url。
     *
     * 不能拿 app.frontend_url 兜底——那是官网地址，官网 SPA 上并没有 /client/api-keys
     * 这个页面，兜出来的链接点进去是白页。没配置时返回空串，文档里就只剩相对路径
     * `/client/api-keys`，用户在自己已登录的控制台里照样能找到，且不会指到错的域名。
     */
    private function consoleUrl(): string
    {
        $configured = trim((string) config('app.console_url'));

        return $configured !== '' ? rtrim($configured, '/') : '';
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
     * 内联转义：占位符的值会进 JSON 字符串与 Markdown 标题，
     * 引号和反斜杠必须转义，否则 manifest.json 会变成非法 JSON。
     */
    private function escapeInline(string $value): string
    {
        $value = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);

        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
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
