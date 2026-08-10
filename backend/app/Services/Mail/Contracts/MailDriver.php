<?php

declare(strict_types=1);

namespace App\Services\Mail\Contracts;

interface MailDriver
{
    public function key(): string;

    public function label(): string;

    /**
     * @param  array<string, mixed>  $context
     */
    public function sendHtml(string $to, string $subject, string $html, array $context = []): void;
}
