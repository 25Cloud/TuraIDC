<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

class ScheduleConsoleHookCoverageTest extends TestCase
{
    public function test_console_schedule_only_registers_heartbeat_and_core_provider_schedules_log_archival(): void
    {
        $source = file_get_contents(base_path('routes/console.php'));
        $providerSource = file_get_contents(base_path('app/Services/Automation/Heartbeat/Providers/CoreScheduledTaskProvider.php'));

        $this->assertIsString($source);
        $this->assertIsString($providerSource);
        $this->assertStringContainsString("Schedule::command('scheduler:heartbeat')", $source);
        $this->assertStringNotContainsString('queue:work', $source);
        $this->assertStringNotContainsString('db:archive-logs', $source);
        $this->assertStringContainsString("key: 'log-archive'", $providerSource);
        $this->assertStringContainsString("ScheduleRule::cron('0 2 * * *')", $providerSource);
        $this->assertStringContainsString("'db:archive-logs'", $providerSource);
        $this->assertStringContainsString("'--execute' => true", $providerSource);
        $this->assertStringContainsString("'--json' => true", $providerSource);
    }
}
