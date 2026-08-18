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
        {--force : 覆盖已存在的模板为默认内容，保留自定义需慎用}';

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
        $skipped = 0;

        DB::transaction(function () use ($templates, $force, &$created, &$skipped): void {
            foreach ($templates as $index => $default) {
                $code = (string) ($default['code'] ?? '');
                if ($code === '') {
                    $skipped++;
                    continue;
                }

                $exists = NotificationTemplate::query()
                    ->where('channel', 'email')
                    ->where('code', $code)
                    ->exists();

                if ($exists && ! $force) {
                    $skipped++;
                    continue;
                }

                NotificationTemplate::updateOrCreate(
                    ['channel' => 'email', 'code' => $code],
                    [
                        'name' => (string) ($default['name'] ?? ''),
                        'description' => (string) ($default['description'] ?? ''),
                        'audience' => (string) ($default['audience'] ?? 'user'),
                        'subject' => isset($default['subject']) ? (string) $default['subject'] : null,
                        'content' => (string) ($default['content'] ?? ''),
                        'variables_json' => $default['variables'] ?? [],
                        'is_enabled' => true,
                        'is_custom' => false,
                        'sort_order' => $index + 1,
                    ]
                );
                $created++;
            }
        });

        $this->info(sprintf('邮件模板恢复完成：新建/覆盖 %d 个，跳过 %d 个（已存在则保留自定义内容）', $created, $skipped));

        return self::SUCCESS;
    }
}
