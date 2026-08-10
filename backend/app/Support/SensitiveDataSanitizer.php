<?php

declare(strict_types=1);

namespace App\Support;

final class SensitiveDataSanitizer
{
    private const REDACTED = '[REDACTED]';

    private const EXACT_SENSITIVE_FIELDS = [
        'password',
        'password_confirmation',
        'oldpassword',
        'newpassword',
        'confirmpassword',
        'token',
        'access_token',
        'verify_token',
        'authorization',
        'cookie',
        'email',
        'to_email',
        'mail',
        'recipient',
        'phone',
        'mobile',
        'telephone',
        'tel',
        'account',
        'username',
        'nickname',
        'qq',
        'wechat',
        'ip',
        'ip_address',
        'client_ip',
        'remote_addr',
        'device_id',
        'device',
        'user_agent',
        'client_id',
        'client_secret',
        'api_key',
        'secret_key',
        'access_key',
        'accesskeyid',
        'accesskeysecret',
        'captcha_key',
        'captcha_output',
        'pass_token',
        'vkey',
        'vaptcha_vkey',
        'knock',
        'dfu',
        'verification_key',
        'sms_access_key',
        'sms_secret_key',
        'geetest_captcha_key',
        'alipay_private_key',
        'alipay_public_key',
        'sign',
        'signature',
        'cert_no',
        'id_card',
        'idcard',
        'id_no',
        'id_card_number',
        'idcard_number',
        'real_name',
        'cert_name',
        'id_name',
        'image',
        'image_base64',
        'face_image',
        'front_base64',
        'back_base64',
    ];

    private const PARTIAL_SENSITIVE_FIELDS = [
        'password',
        '_token',
        'token_',
        '_secret',
        'secret_',
        '_key',
        'private_key',
        'public_key',
        'authorization',
        'signature',
        'captcha',
    ];

    private const TEXT_SENSITIVE_FIELDS = [
        'password',
        'token',
        'access_token',
        'verify_token',
        'authorization',
        'cookie',
        'email',
        'recipient',
        'phone',
        'mobile',
        'telephone',
        'account',
        'username',
        'nickname',
        'qq',
        'wechat',
        'ip_address',
        'device_id',
        'client_id',
        'client_secret',
        'api_key',
        'secret_key',
        'access_key',
        'accesskeyid',
        'accesskeysecret',
        'captcha_key',
        'captcha_output',
        'pass_token',
        'vkey',
        'vaptcha_vkey',
        'knock',
        'dfu',
        'verification_key',
        'sms_access_key',
        'sms_secret_key',
        'geetest_captcha_key',
        'alipay_private_key',
        'alipay_public_key',
        'sign',
        'signature',
        'cert_no',
        'id_card',
        'idcard',
        'id_no',
        'id_card_number',
        'idcard_number',
        'real_name',
        'cert_name',
        'id_name',
        'image',
        'image_base64',
        'face_image',
        'front_base64',
        'back_base64',
    ];

    public static function sanitize(mixed $value, ?string $field = null): mixed
    {
        if ($field !== null && self::isSensitiveField($field)) {
            return self::maskSensitiveValue($value);
        }

        if (is_array($value)) {
            $sanitized = [];

            foreach ($value as $key => $item) {
                $childField = is_string($key) ? $key : null;
                $sanitized[$key] = self::sanitize($item, $childField);
            }

            return $sanitized;
        }

        if (is_object($value)) {
            if ($value instanceof \JsonSerializable) {
                return self::sanitize($value->jsonSerialize(), $field);
            }

            if (method_exists($value, '__toString')) {
                return self::sanitize((string) $value, $field);
            }

            return '[OBJECT]';
        }

        if (is_string($value)) {
            return self::sanitizeText($value);
        }

        return $value;
    }

    public static function sanitizeText(string $text): string
    {
        $sanitized = $text;

        $sanitized = preg_replace('/(authorization\s*[=:]\s*)(Bearer\s+)?(["\']?)[A-Za-z0-9._~+\/=-]+(["\']?)/iu', '$1"'.self::REDACTED.'"', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/=-]+/iu', 'Bearer "'.self::REDACTED.'"', $sanitized) ?? $sanitized;

        foreach (self::TEXT_SENSITIVE_FIELDS as $field) {
            $quotedField = preg_quote($field, '/');

            $patterns = [
                '/("'.$quotedField.'"\s*:\s*)"([^"]*)"/iu',
                "/('".$quotedField."'\\s*:\\s*)'([^']*)'/iu",
                '/('.$quotedField.'\s*[=:]\s*)([^,\s&}\]]+)/iu',
            ];

            foreach ($patterns as $pattern) {
                $sanitized = preg_replace($pattern, '$1"'.self::REDACTED.'"', $sanitized) ?? $sanitized;
            }
        }

        $sanitized = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '[REDACTED_EMAIL]', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/(?<!\d)1[3-9]\d{9}(?!\d)/u', '[REDACTED_PHONE]', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/u', '[REDACTED_IP]', $sanitized) ?? $sanitized;

        return $sanitized;
    }

    private static function isSensitiveField(string $field): bool
    {
        $normalized = strtolower(trim($field));
        if ($normalized === '') {
            return false;
        }

        if (in_array($normalized, self::EXACT_SENSITIVE_FIELDS, true)) {
            return true;
        }

        foreach (self::PARTIAL_SENSITIVE_FIELDS as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private static function maskSensitiveValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value === '') {
            return '';
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_array($value)) {
            return self::REDACTED;
        }

        if (is_string($value)) {
            return self::REDACTED;
        }

        return self::REDACTED;
    }
}
