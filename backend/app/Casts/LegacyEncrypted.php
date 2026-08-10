<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

class LegacyEncrypted implements CastsAttributes
{
    /**
     * @var array<string, int|null>
     */
    private static array $columnLengthCache = [];

    public function get($model, string $key, $value, array $attributes): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return (string) $value;
        }
    }

    public function set($model, string $key, $value, array $attributes): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $plain = (string) $value;
        $encrypted = Crypt::encryptString($plain);
        $columnLength = $this->resolveColumnLength($model, $key);

        // 原本此处在密文超长时静默回退明文，会造成敏感数据以明文写库。
        // 明确拒绝：宁可让写入报错暴露问题，也不允许悄悄降级明文存储。
        // 运维侧需确保目标列足够宽（建议 >= 512 以容纳未来加密输出变长）。
        if ($columnLength !== null && strlen($encrypted) > $columnLength) {
            throw new \RuntimeException(sprintf(
                'LegacyEncrypted: 密文长度 %d 超过 %s.%s 列长度 %d，拒绝回退明文。'
                .'请扩大列宽（建议 varchar(512)）或调整加密策略。',
                strlen($encrypted),
                is_object($model) && method_exists($model, 'getTable') ? $model->getTable() : 'unknown',
                $key,
                $columnLength,
            ));
        }

        return $encrypted;
    }

    private function resolveColumnLength(mixed $model, string $key): ?int
    {
        if (! is_object($model) || ! method_exists($model, 'getTable')) {
            return null;
        }

        $connectionName = method_exists($model, 'getConnectionName')
            ? ($model->getConnectionName() ?: config('database.default'))
            : config('database.default');
        $table = (string) $model->getTable();
        $cacheKey = "{$connectionName}:{$table}:{$key}";

        if (array_key_exists($cacheKey, self::$columnLengthCache)) {
            return self::$columnLengthCache[$cacheKey];
        }

        try {
            $columns = Schema::connection($connectionName)->getColumns($table);
            $type = '';

            foreach ($columns as $column) {
                if (strtolower((string) ($column['name'] ?? '')) !== strtolower($key)) {
                    continue;
                }

                $type = (string) ($column['type'] ?? '');

                break;
            }

            if (preg_match('/^(?:var)?char\((\d+)\)/i', $type, $matches) === 1) {
                return self::$columnLengthCache[$cacheKey] = (int) $matches[1];
            }

            if (str_starts_with(strtolower($type), 'text')) {
                return self::$columnLengthCache[$cacheKey] = 65535;
            }
        } catch (\Throwable) {
            return self::$columnLengthCache[$cacheKey] = null;
        }

        return self::$columnLengthCache[$cacheKey] = null;
    }
}
