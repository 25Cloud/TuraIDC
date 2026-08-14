<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class Setting extends Model
{
    public $timestamps = false;

    private const TABLE_NAME = 'settings';

    private const ENCRYPTED_PREFIX = 'enc:';

    private const CACHE_TTL_SECONDS = 600;

    private const BRAND_REPLACEMENTS = [
        'IDC Finance 平台' => '图拉云平台',
        'IDC Finance' => '图拉云',
    ];

    private const SENSITIVE_KEYS = [
        'verification_key',
        'email_password',
        'sms_access_key',
        'sms_secret_key',
        'geetest_captcha_key',
        'alipay_private_key',
        'alipay_public_key',
    ];

    /**
     * @var array<string, array<string, mixed>>
     */
    private static array $groupValueCache = [];

    /**
     * @var array<string, bool>
     */
    private static array $tableExistsCache = [];

    public static function getValue(string $group, string $key, $default = null)
    {
        $values = static::getGroupRawValues($group);

        if (! array_key_exists($key, $values)) {
            return static::normalizeDisplayValue($key, $default);
        }

        return static::normalizeDisplayValue(
            $key,
            static::decodeValue($key, $values[$key])
        );
    }

    public static function setValue(string $group, string $key, $value): void
    {
        static::writeValue($group, $key, $value);
        static::forgetGroupCache($group);
    }

    /**
     * 批量写入同一分组的多个键值，只清一次缓存。
     *
     * 典型场景：后台保存整组设置、首次把默认配置回填到数据库。
     *
     * @param  array<string, mixed>  $values
     */
    public static function setValues(string $group, array $values): void
    {
        if ($values === []) {
            return;
        }

        DB::transaction(function () use ($group, $values) {
            foreach ($values as $key => $value) {
                static::writeValue($group, (string) $key, $value);
            }
        });

        static::forgetGroupCache($group);
    }

    public static function forgetCachedGroup(string $group): void
    {
        static::forgetGroupCache($group);
    }

    private static function writeValue(string $group, string $key, mixed $value): void
    {
        $encoded = static::encodeValue($key, static::normalizeDisplayValue($key, $value));

        DB::table(self::TABLE_NAME)->updateOrInsert(
            ['group_key' => $group, 'item_key' => $key],
            ['item_value' => $encoded]
        );
    }

    public static function isSensitiveKey(string $key): bool
    {
        return in_array(trim($key), self::SENSITIVE_KEYS, true);
    }

    private static function encodeValue(string $key, mixed $value): mixed
    {
        if (! static::isSensitiveKey($key)) {
            return $value;
        }

        $raw = (string) $value;
        if ($raw === '') {
            return '';
        }

        if (str_starts_with($raw, self::ENCRYPTED_PREFIX)) {
            return $raw;
        }

        return self::ENCRYPTED_PREFIX.Crypt::encryptString($raw);
    }

    private static function decodeValue(string $key, mixed $value): mixed
    {
        if (! static::isSensitiveKey($key)) {
            return $value;
        }

        $raw = (string) $value;
        if ($raw === '') {
            return '';
        }

        if (! str_starts_with($raw, self::ENCRYPTED_PREFIX)) {
            return $raw;
        }

        try {
            return Crypt::decryptString(substr($raw, strlen(self::ENCRYPTED_PREFIX)));
        } catch (\Throwable) {
            return '';
        }
    }

    private static function normalizeDisplayValue(string $key, mixed $value): mixed
    {
        if (static::isSensitiveKey($key) || ! is_string($value) || $value === '') {
            return $value;
        }

        return str_replace(
            array_keys(self::BRAND_REPLACEMENTS),
            array_values(self::BRAND_REPLACEMENTS),
            $value
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function getGroupRawValues(string $group): array
    {
        if (! static::settingsTableExists()) {
            return [];
        }

        if (isset(self::$groupValueCache[$group])) {
            return self::$groupValueCache[$group];
        }

        $cacheKey = static::groupCacheKey($group);

        try {
            $values = Cache::remember($cacheKey, now()->addSeconds(self::CACHE_TTL_SECONDS), function () use ($group) {
                return DB::table(self::TABLE_NAME)
                    ->where('group_key', $group)
                    ->orderBy('id')
                    ->get(['item_key', 'item_value'])
                    ->mapWithKeys(fn ($item) => [
                        (string) ($item->item_key ?? '') => $item->item_value,
                    ])
                    ->all();
            });
        } catch (QueryException $exception) {
            static::markSettingsTableMissing($exception, $group);

            return [];
        }

        self::$groupValueCache[$group] = is_array($values) ? $values : [];

        return self::$groupValueCache[$group];
    }

    private static function settingsTableExists(): bool
    {
        $connectionName = (new static)->getConnectionName() ?: config('database.default', 'default');

        if (array_key_exists($connectionName, self::$tableExistsCache)) {
            return self::$tableExistsCache[$connectionName];
        }

        try {
            return self::$tableExistsCache[$connectionName] = Schema::connection($connectionName)->hasTable(self::TABLE_NAME);
        } catch (\Throwable $exception) {
            Log::warning('[settings] 检查 settings 表失败，回退到默认配置', [
                'connection' => $connectionName,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return self::$tableExistsCache[$connectionName] = false;
        }
    }

    private static function markSettingsTableMissing(QueryException $exception, string $group): void
    {
        $connectionName = (new static)->getConnectionName() ?: config('database.default', 'default');
        self::$tableExistsCache[$connectionName] = false;

        Log::warning('[settings] 读取 settings 表失败，回退到默认配置', [
            'group' => $group,
            'connection' => $connectionName,
            'message' => $exception->getMessage(),
            'exception' => $exception::class,
        ]);
    }

    private static function forgetGroupCache(string $group): void
    {
        unset(self::$groupValueCache[$group]);

        Cache::forget(static::groupCacheKey($group));
    }

    private static function groupCacheKey(string $group): string
    {
        // 设置分组名都是代码硬编码的短英文常量（basic、notification 等），
        // 直接拼接即可：可读性更好，省一次 md5 运算
        return 'settings:group:'.$group;
    }
}
