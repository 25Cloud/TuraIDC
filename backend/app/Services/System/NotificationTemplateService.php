<?php

namespace App\Services\System;

use App\Models\NotificationTemplate;
use App\Support\EmailTemplateCatalog;
use App\Support\NotificationTemplateContent;
use App\Support\SmsTemplateCatalog;
use Illuminate\Support\Facades\Schema;

class NotificationTemplateService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(?string $channel = null): array
    {
        if (! Schema::hasTable('notification_templates')) {
            return [];
        }

        $query = NotificationTemplate::query()
            ->orderBy('sort_order')
            ->orderBy('id');

        if ($channel !== null) {
            $query->where('channel', $channel);
        }

        return $query
            ->get()
            ->map(fn (NotificationTemplate $template): array => $this->formatModel($template))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $channel, string $code): ?array
    {
        $channel = trim($channel);
        $code = trim($code);
        if ($channel === '' || $code === '') {
            return null;
        }

        if (Schema::hasTable('notification_templates')) {
            $template = NotificationTemplate::query()
                ->where('channel', $channel)
                ->where('code', $code)
                ->first();

            if ($template instanceof NotificationTemplate) {
                return $this->formatModel($template);
            }
        }

        return null;
    }

    public function isEnabled(string $channel, string $code): bool
    {
        $channel = trim($channel);
        $code = trim($code);
        if ($channel === '' || $code === '') {
            return true;
        }

        if (! Schema::hasTable('notification_templates')) {
            return true;
        }

        $template = NotificationTemplate::query()
            ->where('channel', $channel)
            ->where('code', $code)
            ->first(['id', 'is_enabled']);

        if (! $template instanceof NotificationTemplate) {
            return true;
        }

        return (bool) $template->is_enabled;
    }

    /**
     * 将旧模板 setting key 写入数据库，并返回仍需保存为普通设置的配置项。
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function extractTemplateSettings(array $settings): array
    {
        $remaining = [];

        foreach ($settings as $key => $value) {
            $parsed = $this->parseTemplateSettingKey((string) $key);
            if ($parsed === null || ! $this->updateTemplateField($parsed, $value)) {
                $remaining[$key] = $value;
            }
        }

        return $remaining;
    }

    public function isTemplateSettingKey(string $key): bool
    {
        return $this->parseTemplateSettingKey($key) !== null;
    }

    /**
     * @return array{channel: string, code: string, field: string}|null
     */
    private function parseTemplateSettingKey(string $key): ?array
    {
        $key = trim($key);

        if (preg_match('/^email_template_subject_(.+)$/', $key, $matches) === 1) {
            return ['channel' => 'email', 'code' => trim((string) $matches[1]), 'field' => 'subject'];
        }

        if (preg_match('/^email_template_content_(.+)$/', $key, $matches) === 1) {
            return ['channel' => 'email', 'code' => trim((string) $matches[1]), 'field' => 'content'];
        }

        if (preg_match('/^email_template_enabled_(.+)$/', $key, $matches) === 1) {
            return ['channel' => 'email', 'code' => trim((string) $matches[1]), 'field' => 'is_enabled'];
        }

        if (preg_match('/^email_template_css_(.+)$/', $key, $matches) === 1) {
            return ['channel' => 'email', 'code' => trim((string) $matches[1]), 'field' => 'ignored_css'];
        }

        if (preg_match('/^sms_template_content_(.+)$/', $key, $matches) === 1) {
            return ['channel' => 'sms', 'code' => trim((string) $matches[1]), 'field' => 'content'];
        }

        if (preg_match('/^sms_template_enabled_(.+)$/', $key, $matches) === 1) {
            return ['channel' => 'sms', 'code' => trim((string) $matches[1]), 'field' => 'is_enabled'];
        }

        if (preg_match('/^sms_template_provider_template_id_(.+)$/', $key, $matches) === 1) {
            return ['channel' => 'sms', 'code' => trim((string) $matches[1]), 'field' => 'provider_template_id'];
        }

        return null;
    }

    /**
     * @param  array{channel: string, code: string, field: string}  $parsed
     */
    private function updateTemplateField(array $parsed, mixed $value): bool
    {
        if ($parsed['field'] === 'ignored_css') {
            return true;
        }

        if (! Schema::hasTable('notification_templates')) {
            return false;
        }

        $template = NotificationTemplate::query()
            ->where('channel', $parsed['channel'])
            ->where('code', $parsed['code'])
            ->first();

        if (! $template instanceof NotificationTemplate) {
            return false;
        }

        $field = $parsed['field'];
        if ($field === 'is_enabled') {
            $template->is_enabled = $this->normalizeEnabledValue($value);
            $template->save();

            return true;
        }

        $template->{$field} = is_string($value)
            ? $this->sanitizeTemplateField((string) $template->channel, $field, $value)
            : (string) $value;
        $template->is_custom = true;
        $template->save();

        return true;
    }

    private function formatModel(NotificationTemplate $template): array
    {
        return [
            'channel' => (string) $template->channel,
            'code' => (string) $template->code,
            'name' => (string) $template->name,
            'description' => (string) $template->description,
            'audience' => (string) $template->audience,
            'subject' => $template->subject,
            'content' => (string) $template->content,
            'provider_template_id' => (string) ($template->provider_template_id ?? ''),
            'is_enabled' => (bool) $template->is_enabled,
            'variables' => array_values((array) ($template->variables_json ?? [])),
            'provider_variables' => array_values((array) ($template->provider_variables_json ?? [])),
            'setting_keys' => $this->settingKeys((string) $template->channel, (string) $template->code),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function settingKeys(string $channel, string $code): array
    {
        if ($channel === 'email') {
            return [
                'subject' => EmailTemplateCatalog::subjectSettingKey($code),
                'content' => EmailTemplateCatalog::contentSettingKey($code),
                'enabled' => EmailTemplateCatalog::enabledSettingKey($code),
            ];
        }

        return [
            'content' => SmsTemplateCatalog::contentSettingKey($code),
            'provider_template_id' => SmsTemplateCatalog::providerTemplateIdSettingKey($code),
            'enabled' => SmsTemplateCatalog::enabledSettingKey($code),
        ];
    }

    private function normalizeEnabledValue(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true);
    }

    /**
     * 模板字段入库前的净化，口径见 NotificationTemplateContent。
     *
     * 原实现是 `preg_replace('/<\/?script\b[^>]*>/iu', '', $value)`，而其注释声称会移除
     * script/iframe/object/embed 与事件处理器 —— 实际只删 script 开闭标签。本地以 9 个
     * 常见载荷实测，放行 8 个（`<img onerror>`、`<svg onload>`、`<iframe>`、`<object>`、
     * `<embed>`、`<body onload>`、`javascript:` 全部原样通过），且
     * `<scr<script>ipt>alert(1)</scr</script>ipt>` 在剥掉内层后会**重组出**一个可执行的
     * `<script>` 标签 —— 净化反而使情况变坏。这是单遍黑名单剥离的固有缺陷，
     * 不是那条正则写得不好，故整体换成白名单方案。
     */
    private function sanitizeTemplateField(string $channel, string $field, string $value): string
    {
        return NotificationTemplateContent::sanitizeForStorage($channel, $field, $value);
    }
}
