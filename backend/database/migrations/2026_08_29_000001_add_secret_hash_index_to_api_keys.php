<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 为 api_keys.secret_hash 建普通索引，支撑按 hash 等值命中的密钥校验。
 *
 * 背景：ApiKeyService::resolve() 原先把全表 enabled 密钥载入内存逐行 hash_equals，
 * 任意 Bearer 头即可触发这次 O(n) 全表扫描。改为按 secret_hash 等值查询后需要此索引。
 * hashSecret() 是确定性的 sha256(secret + app.key)，明文一对一映射到 hash，等值查找安全。
 *
 * 用普通 index 而非 unique：sha256 碰撞概率虽可忽略，但唯一约束会让极端情况下的插入直接失败；
 * 校验侧配合 status 过滤取首条即可，不依赖唯一性。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('api_keys')) {
            return;
        }

        // 幂等：索引已存在则跳过，避免重复执行迁移时报 Duplicate key name。
        if ($this->indexExists('api_keys', 'api_keys_secret_hash_index')) {
            return;
        }

        Schema::table('api_keys', function (Blueprint $table): void {
            $table->index('secret_hash', 'api_keys_secret_hash_index');
        });
    }

    /**
     * 遵循本项目「数据库只新增、绝不删除对象」的铁律：不在 down() 里 dropIndex。
     * 该索引是纯性能结构、不承载任何数据，回滚保留它不会造成数据层面的副作用；
     * 是否清理交由上游维护者决定。
     */
    public function down(): void
    {
        // 有意为空：见上方说明。
    }

    private function indexExists(string $table, string $index): bool
    {
        $database = Schema::getConnection()->getDatabaseName();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
