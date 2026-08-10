<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 身份域迁移连接配置
    |--------------------------------------------------------------------------
    |
    | source_connection: 旧库连接名（读取源数据）
    | target_connection: 新库连接名（写入迁移数据）
    |
    | 连接参数在 config/database.php 的 idc / idc 中定义。
    | 这里只控制迁移命令默认使用哪个连接。
    |
    */

    'source_connection' => env('IDENTITY_MIGRATION_SOURCE_DB', 'mysql'),
    'target_connection' => env('IDENTITY_MIGRATION_TARGET_DB', 'mysql'),

];
