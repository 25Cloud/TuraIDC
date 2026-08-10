<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AdminUser;
use Illuminate\Http\Request;

class AdminPrivacy
{
    public function __construct(
        private readonly bool $canViewRaw = false,
        private readonly bool $adminContext = true,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $admin = $request->user();

        return $admin instanceof AdminUser
            ? new self(self::canViewRaw($admin), true)
            : new self(false, false);
    }

    public static function current(): self
    {
        $admin = request()->user();

        return $admin instanceof AdminUser
            ? new self(self::canViewRaw($admin), true)
            : new self(false, false);
    }

    public static function canViewRaw(mixed $admin): bool
    {
        return $admin instanceof AdminUser
            && $admin->hasPermission(AdminPermissions::PRIVACY_VIEW_RAW);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $sensitiveFields
     * @param  array<string, string>  $keywordFields
     * @return array<string, string>
     */
    public static function forbiddenFilterMessages(array $input, array $sensitiveFields, array $keywordFields = [], mixed $admin = null): array
    {
        if (! $admin instanceof AdminUser || self::canViewRaw($admin)) {
            return [];
        }

        $messages = [];
        foreach ($sensitiveFields as $field => $label) {
            if (trim((string) ($input[$field] ?? '')) !== '') {
                $messages[$field] = "缺少原始隐私查看权限，不能使用{$label}筛选";
            }
        }

        foreach ($keywordFields as $field => $label) {
            $value = trim((string) ($input[$field] ?? ''));
            if ($value !== '' && self::containsExplicitPrivacyNeedle($value)) {
                $messages[$field] = "缺少原始隐私查看权限，不能使用{$label}筛选";
            }
        }

        return $messages;
    }

    public static function containsExplicitPrivacyNeedle(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $value) === 1
            || preg_match('/(?<!\d)1[3-9]\d{9}(?!\d)/', $value) === 1
            || preg_match('/(?<![0-9Xx])\d{6}(18|19|20)\d{2}(0[1-9]|1[0-2])([0-2]\d|3[01])\d{3}[0-9Xx](?![0-9Xx])/', $value) === 1
            || preg_match('/(?<!\d)\d{15}(?!\d)/', $value) === 1;
    }

    public function allowsRaw(): bool
    {
        return $this->canViewRaw;
    }

    public function email(mixed $value): string
    {
        $email = trim((string) ($value ?? ''));
        if (! $this->adminContext || $this->canViewRaw || $email === '') {
            return $email;
        }

        if (! str_contains($email, '@')) {
            return $this->generic($email);
        }

        [$local, $domain] = explode('@', $email, 2);
        $first = mb_substr($local, 0, 1);

        return $first.'***@'.$domain;
    }

    public function phone(mixed $value): string
    {
        $phone = trim((string) ($value ?? ''));
        if (! $this->adminContext || $this->canViewRaw || $phone === '') {
            return $phone;
        }

        if (mb_strlen($phone) <= 7) {
            return mb_substr($phone, 0, 1).'***';
        }

        return mb_substr($phone, 0, 3).'****'.mb_substr($phone, -4);
    }

    public function name(mixed $value): string
    {
        $name = trim((string) ($value ?? ''));
        if (! $this->adminContext || $this->canViewRaw || $name === '') {
            return $name;
        }

        if (mb_strlen($name) <= 1) {
            return '*';
        }

        return mb_substr($name, 0, 1).str_repeat('*', max(mb_strlen($name) - 1, 1));
    }

    public function idCard(mixed $value): string
    {
        $idCard = trim((string) ($value ?? ''));
        if ($idCard === '') {
            return '-';
        }

        if ($this->canViewRaw) {
            return $idCard;
        }

        $length = mb_strlen($idCard);
        if ($length <= 8) {
            return mb_substr($idCard, 0, 1).str_repeat('*', max($length - 2, 1)).mb_substr($idCard, -1);
        }

        return mb_substr($idCard, 0, 6).str_repeat('*', max($length - 10, 1)).mb_substr($idCard, -4);
    }

    public function ip(mixed $value): string
    {
        $ip = trim((string) ($value ?? ''));
        if (! $this->adminContext || $this->canViewRaw || $ip === '') {
            return $ip;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $segments = explode('.', $ip);

            return ($segments[0] ?? '*').'.'.($segments[1] ?? '*').'.*.*';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return strtok($ip, ':').':****';
        }

        return $this->generic($ip);
    }

    public function account(mixed $value): string
    {
        $account = trim((string) ($value ?? ''));
        if (! $this->adminContext || $this->canViewRaw || $account === '') {
            return $account;
        }

        if (str_contains($account, '@')) {
            return $this->email($account);
        }

        if (preg_match('/^1[3-9]\d{9}$/', $account) === 1) {
            return $this->phone($account);
        }

        return $this->generic($account);
    }

    public function displayName(mixed $displayName, mixed $email = '', mixed $phone = '', mixed $realName = ''): string
    {
        $value = trim((string) ($displayName ?? ''));
        if (! $this->adminContext || $this->canViewRaw || $value === '') {
            return $value;
        }

        if ($value === trim((string) $realName)) {
            return $this->name($value);
        }

        if ($value === trim((string) $email)) {
            return $this->email($value);
        }

        if ($value === trim((string) $phone)) {
            return $this->phone($value);
        }

        return $value;
    }

    public function payload(mixed $value): mixed
    {
        if (! $this->adminContext || $this->canViewRaw) {
            return $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        $masked = [];
        foreach ($value as $key => $item) {
            $keyString = is_string($key) ? mb_strtolower($key) : '';
            $masked[$key] = match (true) {
                in_array($keyString, ['email', 'to_email', 'recipient_email', 'old_email', 'new_email'], true) => $this->email($item),
                in_array($keyString, ['phone', 'mobile', 'recipient_phone', 'old_phone', 'new_phone'], true) => $this->phone($item),
                in_array($keyString, ['ip', 'ip_address', 'last_login_ip', 'previous_ip'], true) => $this->ip($item),
                in_array($keyString, ['real_name', 'account_name', 'alipay_real_name'], true) => $this->name($item),
                in_array($keyString, ['id_card', 'idcard', 'cert_no', 'certificate_no'], true) => $this->idCard($item),
                in_array($keyString, ['account_no', 'alipay_account'], true) => $this->account($item),
                default => is_array($item) ? $this->payload($item) : $item,
            };
        }

        return $masked;
    }

    private function generic(string $value): string
    {
        $length = mb_strlen($value);
        if ($length <= 2) {
            return str_repeat('*', max($length, 1));
        }

        return mb_substr($value, 0, 1).str_repeat('*', max($length - 2, 1)).mb_substr($value, -1);
    }
}
