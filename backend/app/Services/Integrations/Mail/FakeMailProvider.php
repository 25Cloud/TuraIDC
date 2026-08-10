<?php

declare(strict_types=1);

namespace App\Services\Integrations\Mail;

use App\Contracts\Integrations\MailProviderInterface;

class FakeMailProvider implements MailProviderInterface
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $sent = [];

    public function sendHtml(string $to, string $subject, string $html, array $context = []): void
    {
        $this->sent[] = compact('to', 'subject', 'html', 'context');
    }
}
