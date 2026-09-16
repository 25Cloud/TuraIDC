<?php

declare(strict_types=1);

namespace App\Services\Integrations\Plugins\Concerns;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * 绑定行变更感知写入。
 *
 * 四张绑定/快照表（supplier_plugin_bindings、product_upstream_bindings、
 * service_upstream_bindings、service_runtime_snapshots、service_connection_snapshots）
 * 被「定时同步 + 每次控制台访问」高频重写：开通、状态同步、商品库存同步、服务详情
 * 展示都会调一次「确保绑定存在」，而实现是无条件 updateOrInsert。数据一个字节都没变
 * 也要写整行前后镜像进 binlog（生产实测两张绑定表每天约 9GB）。
 *
 * 更隐蔽的是 secret_json：Crypt::encryptString 每次用随机 IV，同一份明文每次密文都不同，
 * 于是即便加了逐列比对，密钥列也永远被判成「有变化」。
 *
 * 这里把判定收敛成三条规则：
 * 1) 业务列逐列比对，全部一致就不发 UPDATE；
 * 2) 时间戳列（updated_at / last_synced_at / checked_at）与其嵌套在 JSON 快照里的同名
 *    字段属于易变数据，不参与比对——否则每次同步都会「有变化」；
 * 3) 密钥明文未变时复用原密文，避免随机 IV 让行内容每次都不同。
 */
trait PersistsBindingRowsOnChange
{
    /** 易变时间戳列：只写不改语义，不参与「是否变化」判定 */
    private const BINDING_VOLATILE_COLUMNS = [
        'created_at',
        'updated_at',
        'last_synced_at',
        'last_checked_at',
        'synced_at',
        'checked_at',
    ];

    /** 易变时间戳字段：嵌套在 JSON 快照内部，比对时需要一并剔除 */
    private const BINDING_VOLATILE_JSON_KEYS = [
        'synced_at',
        'checked_at',
        'attempted_at',
        'last_synced_at',
        'last_status_sync_at',
        'nat_remote_checked_at',
        'connection_cached_at',
    ];

    /**
     * @param  array<string, mixed>  $identity
     */
    protected function findBindingRow(string $table, array $identity): ?object
    {
        return DB::table($table)->where($identity)->first();
    }

    /**
     * 仅当业务列真正变化时 UPDATE。
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $volatileColumns  调用方额外声明的易变列
     * @return bool 是否发生了写入
     */
    protected function updateBindingRowOnChange(
        string $table,
        object $existing,
        array $payload,
        array $volatileColumns = []
    ): bool {
        if ($this->bindingRowMatches($payload, $existing, $volatileColumns)) {
            return false;
        }

        DB::table($table)->where('id', (int) $existing->id)->update($payload);

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $volatileColumns
     */
    protected function bindingRowMatches(array $payload, object $existing, array $volatileColumns = []): bool
    {
        $volatile = array_merge(self::BINDING_VOLATILE_COLUMNS, $volatileColumns);

        foreach ($payload as $column => $value) {
            if (in_array((string) $column, $volatile, true)) {
                continue;
            }

            if (! $this->bindingColumnMatches($existing->{$column} ?? null, $value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 密钥明文未变时复用已有密文。
     *
     * @param  array<string, mixed>  $secrets  本次期望写入的密钥明文
     * @param  array<string, mixed>  $existingPlainText  从既有密文解出的明文
     */
    protected function stableEncryptedSecrets(
        ?string $existingCipherText,
        array $secrets,
        array $existingPlainText = []
    ): ?string {
        $existingCipherText = $existingCipherText === null ? null : trim($existingCipherText);
        $existingCipherText = $existingCipherText === '' ? null : $existingCipherText;
        $filtered = $this->bindingPresentValues($secrets);

        // 语义与 encryptSecrets 一致：没有密钥就清空该列。
        if ($filtered === []) {
            return null;
        }

        if ($existingCipherText !== null && $filtered == $this->bindingPresentValues($existingPlainText)) {
            return $existingCipherText;
        }

        return Crypt::encryptString((string) json_encode($filtered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 解出既有密文中的密钥明文，用于判断本次是否需要重新加密。
     *
     * @return array<string, mixed>
     */
    protected function decryptBindingSecrets(?string $cipherText): array
    {
        $cipherText = trim((string) ($cipherText ?? ''));
        if ($cipherText === '') {
            return [];
        }

        try {
            $decoded = json_decode(Crypt::decryptString($cipherText), true);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    protected function bindingPresentValues(array $value): array
    {
        return array_filter(
            $value,
            static fn (mixed $item): bool => $item !== null && $item !== '' && $item !== []
        );
    }

    private function bindingColumnMatches(mixed $existing, mixed $incoming): bool
    {
        if ($this->bindingJsonLike($existing) && $this->bindingJsonLike($incoming)) {
            $left = $this->bindingStrippedJson((string) $existing);
            $right = $this->bindingStrippedJson((string) $incoming);

            // == 对数组按「键值对」比较：顺序无关，标量按值宽松比较，
            // 上游回传的 1 与本地存的 '1' 是同一份数据，不该触发写入。
            return $left == $right;
        }

        return $this->bindingScalar($existing) === $this->bindingScalar($incoming);
    }

    private function bindingJsonLike(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $trimmed = ltrim($value);

        return str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[');
    }

    /**
     * @return array<mixed>
     */
    private function bindingStrippedJson(string $json): array
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return [];
        }

        return $this->bindingStripVolatileKeys($decoded);
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function bindingStripVolatileKeys(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, self::BINDING_VOLATILE_JSON_KEYS, true)) {
                unset($value[$key]);

                continue;
            }

            if (is_array($item)) {
                $value[$key] = $this->bindingStripVolatileKeys($item);
            }
        }

        return $value;
    }

    private function bindingScalar(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
    }
}
