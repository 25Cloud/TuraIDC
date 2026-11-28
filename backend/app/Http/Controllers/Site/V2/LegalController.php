<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site\V2;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;

/**
 * 服务条款 / 隐私政策正文。
 *
 * 正文体积不小（每份约 10KB），不适合塞进 /v2/site/config——那是首页每次都要拉的，
 * 塞进去等于给首屏加几十 KB 且绝大多数访客根本不会打开条款页。所以单独一条接口。
 *
 * 内容由站长在后台维护，格式约定：
 *   - 以 `## ` 开头的行是节标题
 *   - 其余非空行是段落，连续段落按行拆开
 *   - 正文里可以用 {{site_name}} / {{contact_email}} / {{icp_record}} 占位，
 *     渲染时替换成站点当前配置，站点改名或换邮箱后条款不用重写
 *
 * 后台没填时返回空 sections，前端回落到内置文案，页面不会开天窗。
 */
class LegalController extends Controller
{
    private const SETTING_GROUP = 'basic';

    private const KINDS = [
        'terms' => 'terms_content',
        'privacy' => 'privacy_content',
    ];

    /** 正文支持的占位符 → 站点配置键 */
    private const PLACEHOLDERS = [
        '{{site_name}}' => 'site_name',
        '{{contact_email}}' => 'service_email',
        '{{icp_record}}' => 'icp_record',
    ];

    /**
     * @return JsonResponse
     */
    public function show(string $kind)
    {
        $key = self::KINDS[$kind] ?? null;

        // 未知类型不报错：返回空 sections，前端自然回落到内置文案
        $raw = $key === null
            ? ''
            : (string) Setting::getValue(self::SETTING_GROUP, $key, '');

        return $this->success([
            'kind' => $key === null ? '' : $kind,
            'sections' => $this->parse($this->replacePlaceholders($raw)),
        ]);
    }

    private function replacePlaceholders(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        $replacements = [];

        foreach (self::PLACEHOLDERS as $placeholder => $settingKey) {
            $replacements[$placeholder] = (string) Setting::getValue(self::SETTING_GROUP, $settingKey, '');
        }

        return strtr($raw, $replacements);
    }

    /**
     * 把纯文本排版成 [{title, paragraphs}]。
     *
     * 用状态数组而不是闭包按引用捕获：PHPStan 会把闭包捕获的变量当成常量，
     * 于是 `=== ''` 恒真、后续语句被判定不可达（CI 会因此失败）。
     *
     * @return list<array{title: string, paragraphs: list<string>}>
     */
    private function parse(string $raw): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($raw));

        if ($text === '') {
            return [];
        }

        /** @var list<array{title: string, paragraphs: list<string>}> $sections */
        $sections = [];

        /** @var array{title: string, paragraphs: list<string>} $state */
        $state = ['title' => '', 'paragraphs' => []];

        foreach (explode("\n", $text) as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            if (str_starts_with($trimmed, '##')) {
                if ($state['title'] !== '' || $state['paragraphs'] !== []) {
                    $sections[] = $state;
                }

                $state = ['title' => trim(ltrim($trimmed, '#')), 'paragraphs' => []];

                continue;
            }

            $state['paragraphs'][] = $trimmed;
        }

        if ($state['title'] !== '' || $state['paragraphs'] !== []) {
            $sections[] = $state;
        }

        return $sections;
    }
}
