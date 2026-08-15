<?php

declare(strict_types=1);

return [
    'retention_days' => 30,
    'file_retention_days' => 180,

    // 归档文件始终落在后端 storage 目录内，不支持 NAS 或外部挂载目录。
    'archive_root' => storage_path('app/private/log-archives'),
    'report_root' => storage_path('logs/log-archive'),

    // 可执行参数的运行时默认值；管理员后台可覆盖。
    'pt_archiver_binary' => '/usr/bin/pt-archiver',
    'pt_archiver_defaults_file' => '/etc/turaidc/pt-archiver.cnf',

    'concurrency' => 2,
    'batch_size' => 1000,
    'sleep_seconds' => 1,

    'tables' => [
        'operation_logs' => 'API/后台操作及管理员登录日志',
        'activity_logs' => '系统与业务活动日志',
        'message_logs' => '短信/邮件统一消息日志',
        'automation_logs' => '自动化任务业务日志',
        'schedule_run_logs' => 'Laravel 调度运行日志',
        'integration_plugin_runtime_logs' => '插件运行日志',
        'gateway_logs' => '支付网关交互日志',
    ],

    'excluded_tables' => [
        'archive_audit_logs',
        'account_transactions',
        'payments',
        'payment_callbacks',
        'invoices',
        'invoice_items',
        'failed_jobs',
        // 运行台账是人工重跑和审计追溯的长期记录，不得随普通日志在线删除。
        'schedule_task_runs',
    ],
];
