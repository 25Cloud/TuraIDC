<?php

declare(strict_types=1);

namespace App\Services\Integrations\Plugins\Adapters;

use App\Services\Integrations\Plugins\PluginManifest;
use App\Services\Integrations\Plugins\PluginRuntimeRegistry;
use App\Services\Mail\Contracts\MailDriver;

final readonly class PluginMailDriver implements MailDriver
{
    public function __construct(
        private PluginRuntimeRegistry $runtime,
        private PluginManifest $manifest,
    ) {}

    public function key(): string
    {
        return $this->manifest->key;
    }

    public function label(): string
    {
        return $this->manifest->name;
    }

    public function sendHtml(string $to, string $subject, string $html, array $context = []): void
    {
        $this->runtime->execute($this->manifest->domain, $this->manifest->slug, 'mail.send_html', [
            'to' => $to,
            'subject' => $subject,
            'html' => $html,
            'context' => $context,
        ]);
    }
}
