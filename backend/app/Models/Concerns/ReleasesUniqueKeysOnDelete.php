<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * 软删时释放全局唯一键。
 *
 * 背景：应用层唯一性检查（如用户注册、文章 slug 校验）走 Eloquent 的
 * SoftDeletes 全局作用域，把软删行视为"可复用"；而 MySQL 唯一索引不感知
 * 软删，同一列一旦写过就永久占用。二者语义矛盾会在"软删后重建同 email /
 * phone / slug"时抛出裸的重复键异常。
 *
 * 本 trait 在 `deleting` 事件中把需要释放的唯一列改写为带主键后缀的
 * 唯一占位值（del-<列名>-<id>），让数据库层与应用层"软删后可复用"的
 * 语义一致。改写不产生实际数据损失：业务实体仍保留在软删行内，原始值
 * 已在调用方的审计日志（如 UserService::deleteUser 的操作日志）留痕。
 *
 * 注意：调用方删除操作应包在 DB::transaction 中，保证唯一列改写与
 * deleted_at 写入原子；不适用物理删除（forceDelete 直接跳过）。
 */
trait ReleasesUniqueKeysOnDelete
{
    public static function bootReleasesUniqueKeysOnDelete(): void
    {
        static::deleting(function (Model $model): void {
            if (method_exists($model, 'isForceDeleting') && $model->isForceDeleting()) {
                return;
            }

            $values = [];
            foreach ($model->uniqueKeysToReleaseOnDelete() as $column) {
                $current = $model->getRawOriginal($column);
                if ($current === null || $current === '') {
                    continue;
                }
                $values[$column] = $model->buildReleasedUniqueValue($column, (string) $current);
            }

            if ($values === []) {
                return;
            }

            DB::table($model->getTable())
                ->where($model->getKeyName(), $model->getKey())
                ->update($values);
        });
    }

    /**
     * 软删时需要释放全局唯一键的列名。
     *
     * @return array<int, string>
     */
    abstract public function uniqueKeysToReleaseOnDelete(): array;

    protected function buildReleasedUniqueValue(string $column, string $current): string
    {
        $key = (string) ($this->getKey() ?? '');

        // 长度受列宽约束（email 100 / phone 20 / slug 220），取短后缀保证唯一。
        return 'del-'.trim($column, '_').'-'.$key;
    }
}
