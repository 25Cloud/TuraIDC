<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 商品目录与供应商域迁移连接配置
    |--------------------------------------------------------------------------
    |
    | source_connection: 旧库连接名（读取源数据）
    | target_connection: 新库连接名（写入迁移数据）
    |
    */

    'source_connection' => env('CATALOG_MIGRATION_SOURCE_DB', 'mysql'),
    'target_connection' => env('CATALOG_MIGRATION_TARGET_DB', 'mysql'),

];
