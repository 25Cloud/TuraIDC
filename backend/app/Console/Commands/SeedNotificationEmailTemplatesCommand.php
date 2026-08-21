<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\NotificationTemplate;
use App\Support\EmailNotificationTemplateDefaults;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SeedNotificationEmailTemplatesCommand extends Command
{
    protected $signature = 'app:seed-email-templates
        {--force : 覆盖包含自定义内容在内的所有已存在邮件模板，需谨慎使用}';

    protected $description = '幂等恢复默认邮件通知模板（notification_templates，channel=email）';

    public function handle(): int
    {
        if (! Schema::hasTable('notification_templates')) {
            $this->error('notification_templates 表不存在，请先执行数据库迁移');

            return self::FAILURE;
        }

        $templates = EmailNotificationTemplateDefaults::templates();

        if ($templates === []) {
            $this->warn('EmailNotificationTemplateDefaults::templates() 未返回任何模板');

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');
        $created = 0;
        $updated = 0;
        $overwrittenCustom = 0;
        $skipped = 0;

        DB::transaction(function () use ($templates, $force, &$created, &$updated, &$overwrittenCustom, &$skipped): void {
            foreach ($templates as $index => $default) {
                $code = trim((string) ($default['code'] ?? ''));
                if ($code === '') {
                    $skipped++;

                    continue;
                }

                $payload = [
                    'name' => (string) ($default['name'] ?? ''),
                    'description' => (string) ($default['description'] ?? ''),
                    'audience' => (string) ($default['audience'] ?? 'user'),
                    'subject' => isset($default['subject']) ? (string) $default['subject'] : null,
                    'content' => (string) ($default['content'] ?? ''),
                    'variables_json' => $default['variables'] ?? [],
                    'is_enabled' => true,
                    'is_custom' => false,
                    'sort_order' => $index + 1,
                ];

                $existing = NotificationTemplate::query()
                    ->where('channel', 'email')
                    ->where('code', $code)
                    ->first(['id', 'is_custom']);

                if ($existing instanceof NotificationTemplate) {
                    if ((bool) $existing->is_custom && ! $force) {
                        $skipped++;

                        continue;
                    }

                    $wasCustom = (bool) $existing->is_custom;
                    $existing->forceFill($payload)->save();

                    if ($wasCustom) {
                        $overwrittenCustom++;
                    } else {
                        $updated++;
                    }

                    continue;
                }

                NotificationTemplate::query()->create(array_merge([
                    'channel' => 'email',
                    'code' => $code,
                ], $payload));

                $created++;
            }
        });

        $this->info(sprintf(
            '邮件模板恢复完成：新建 %d 个，刷新未自定义 %d 个，覆盖自定义 %d 个，跳过 %d 个',
            $created,
            $updated,
            $overwrittenCustom,
            $skipped
        ));

        return self::SUCCESS;
    }
}
