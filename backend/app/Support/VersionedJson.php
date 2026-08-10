<?php

declare(strict_types=1);

namespace App\Support;

final class VersionedJson
{
    public const SCHEMA_VERSION_KEY = '_schema_version';

    public const SCHEMA_TYPE_KEY = '_schema_type';

    public const TRADE_SNAPSHOT_VERSION = 1;

    public const PAYMENT_CALLBACK_VERSION = 1;

    /**
     * @return array<string, mixed>|null
     */
    public static function tradeSnapshot(mixed $payload, string $schemaType): ?array
    {
        $decoded = self::decodeToArray($payload);
        if ($decoded === null) {
            return null;
        }

        return self::stamp($decoded, $schemaType, self::TRADE_SNAPSHOT_VERSION);
    }

    /**
     * @return array<string, mixed>
     */
    public static function paymentCallback(mixed $payload, string $callbackType): array
    {
        return self::stamp(
            self::decodeToArray($payload) ?? [],
            'payment_callback.'.$callbackType,
            self::PAYMENT_CALLBACK_VERSION,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function decodeToArray(mixed $payload): ?array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (is_string($payload) && trim($payload) !== '') {
            $decoded = json_decode($payload, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<string, mixed>
     */
    public static function stamp(array $payload, string $schemaType, int $schemaVersion): array
    {
        $payload[self::SCHEMA_VERSION_KEY] = (int) ($payload[self::SCHEMA_VERSION_KEY] ?? $schemaVersion);
        $payload[self::SCHEMA_TYPE_KEY] = (string) ($payload[self::SCHEMA_TYPE_KEY] ?? $schemaType);

        return $payload;
    }

    /**
     * @param  array<mixed>|null  $payload
     */
    public static function isVersioned(?array $payload): bool
    {
        return is_array($payload)
            && array_key_exists(self::SCHEMA_VERSION_KEY, $payload)
            && is_numeric($payload[self::SCHEMA_VERSION_KEY]);
    }

    /**
     * @param  array<mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    public static function withoutMeta(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        unset($payload[self::SCHEMA_VERSION_KEY], $payload[self::SCHEMA_TYPE_KEY]);

        return $payload;
    }
}
