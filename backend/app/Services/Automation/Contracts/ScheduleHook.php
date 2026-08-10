<?php

namespace App\Services\Automation\Contracts;

interface ScheduleHook
{
    public function handle(string $hook, array $context = []): mixed;
}
