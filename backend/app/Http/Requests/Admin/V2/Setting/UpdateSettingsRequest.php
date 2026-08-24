<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Setting;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Support\TextSanitizer;

class UpdateSettingsRequest extends AdminFormRequest
{
    /**
     * 不做消毒的键：值为密钥/证书等任意字节串，剥标签会直接损坏内容。
     * 与 Setting::SENSITIVE_KEYS 保持一致。
     */
    private const RAW_KEYS = [
        'verification_key',
        'email_password',
        'sms_access_key',
        'sms_secret_key',
        'geetest_captcha_key',
        'alipay_private_key',
        'alipay_public_key',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'group' => ['nullable', 'string', 'max:50'],
            'settings' => ['required', 'array'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }

    public function group(): string
    {
        $group = trim((string) $this->validated('group', 'system'));

        return $group !== '' ? $group : 'system';
    }

    /**
     * 设置项落库前统一剥掉 HTML 标签。
     *
     * 站点名等设置会被 SEO 渲染拼进公开页 HTML，此前写入端不做任何消毒，全靠渲染
     * 端逐处转义兜底——任何新增的渲染路径只要漏一个 e()，就会重新变成存储型 XSS。
     * 现网 74 条设置中含 HTML 标签的为 0 条，说明没有任何设置项依赖标记，可安全剥离。
     *
     * 两类值跳过：
     * - RAW_KEYS 里的密钥/证书，值是任意字节串，剥标签会损坏内容；
     * - 合法 JSON（如 home_hero.slides、traffic_package_catalog.items），
     *   属结构化配置，整串剥标签会破坏结构，其内部文本由各自的渲染端负责转义。
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $settings = (array) $this->validated('settings', []);

        foreach ($settings as $key => $value) {
            if (! is_string($value) || in_array((string) $key, self::RAW_KEYS, true)) {
                continue;
            }

            if ($this->looksLikeJson($value)) {
                continue;
            }

            $settings[$key] = TextSanitizer::clean($value, preserveNewLines: true);
        }

        return $settings;
    }

    private function looksLikeJson(string $value): bool
    {
        $trimmed = trim($value);

        if (! str_starts_with($trimmed, '{') && ! str_starts_with($trimmed, '[')) {
            return false;
        }

        json_decode($trimmed, true);

        return json_last_error() === JSON_ERROR_NONE;
    }
}
