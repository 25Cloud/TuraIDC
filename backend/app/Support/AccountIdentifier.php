<?php

declare(strict_types=1);

namespace App\Support;

final class AccountIdentifier
{
    public static function detectType(?string $value): ?string
    {
        $email = self::normalizeEmail($value);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'email';
        }

        $phone = self::normalizePhone($value);
        if ($phone !== '' && preg_match('/^1[3-9]\d{9}$/', $phone) === 1) {
            return 'phone';
        }

        return null;
    }

    public static function normalizeAccount(?string $value): string
    {
        return match (self::detectType($value)) {
            'email' => self::normalizeEmail($value),
            'phone' => self::normalizePhone($value),
            default => trim((string) $value),
        };
    }

    public static function normalizeEmail(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    public static function normalizeOptionalEmail(?string $value): ?string
    {
        $email = self::normalizeEmail($value);

        return $email !== '' ? $email : null;
    }

    public static function normalizePhone(?string $value): string
    {
        $phone = preg_replace('/\D+/', '', trim((string) $value)) ?? '';

        if (str_starts_with($phone, '86') && strlen($phone) === 13) {
            $phone = substr($phone, 2);
        }

        return $phone;
    }

    public static function normalizeOptionalPhone(?string $value): ?string
    {
        $phone = self::normalizePhone($value);

        return $phone !== '' ? $phone : null;
    }
}
