<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 服务实例域迁移连接配置
    |--------------------------------------------------------------------------
    |
    | source_connection: 旧库连接名（读取源数据）
    | target_connection: 新库连接名（写入迁移数据）
    |
    */

    'source_connection' => env('SERVICE_MIGRATION_SOURCE_DB', 'mysql'),
    'target_connection' => env('SERVICE_MIGRATION_TARGET_DB', 'mysql'),
    'legacy_db_database' => env('SERVICE_MIGRATION_LEGACY_DB_DATABASE', ''),
    'legacy_table_prefix' => env('SERVICE_MIGRATION_LEGACY_TABLE_PREFIX', ''),

];
