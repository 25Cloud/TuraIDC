<?php

declare(strict_types=1);

namespace App\Services\Upstream\Support;

use App\Services\Upstream\Contracts\WebSessionCredentialParser;

final class WebSessionCookieParser
{
    /**
     * @param  iterable<int, WebSessionCredentialParser>  $credentialParsers
     */
    public function __construct(
        private readonly iterable $credentialParsers = [],
    ) {}

    public function parse(string $notes): string
    {
        $notes = trim($notes);
        if ($notes === '') {
            return '';
        }

        $genericCookie = $this->parseGenericCookie($notes);
        if ($genericCookie !== '') {
            return $genericCookie;
        }

        foreach ($this->credentialParsers as $credentialParser) {
            $cookie = $this->normalizeCookieHeaderValue($credentialParser->parseWebSessionCookie($notes));
            if ($cookie !== '') {
                return $cookie;
            }
        }

        return '';
    }

    private function parseGenericCookie(string $notes): string
    {
        $decoded = json_decode($notes, true);
        if (is_array($decoded)) {
            foreach (['web_session_cookie', 'upstream_cookie', 'cookie'] as $key) {
                $value = trim((string) ($decoded[$key] ?? ''));
                if ($value !== '') {
                    return $this->normalizeCookieHeaderValue($value);
                }
            }
        }

        if (preg_match('/(?:web_session_cookie|upstream_cookie|cookie)\s*[=:]\s*(.+)$/imu', $notes, $match) === 1) {
            return $this->normalizeCookieHeaderValue((string) $match[1]);
        }

        return '';
    }

    private function normalizeCookieHeaderValue(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/^Cookie\s*:\s*/i', '', $value) ?? $value;
        $value = strtok($value, "\r\n") ?: '';

        return trim($value);
    }
}
