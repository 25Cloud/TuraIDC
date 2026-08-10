<?php

declare(strict_types=1);

namespace App\Contracts\Integrations;

interface MailProviderInterface
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function sendHtml(string $to, string $subject, string $html, array $context = []): void;
}
