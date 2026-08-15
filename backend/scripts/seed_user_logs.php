<?php

use App\Models\OperationLog;
use Carbon\Carbon;

$userId = 988047;
$ips = ['192.168.1.100', '10.0.0.55', '203.0.113.42', '198.51.100.7'];
$dates = ['2026-07-15', '2026-07-16', '2026-07-17', '2026-07-18', '2026-07-19'];
$actions = [
    '登录后台', '修改密码', '查看账单', '购买产品', '提交工单',
    '查看服务详情', 'GET api/v2/client/services', 'POST api/v2/client/tickets',
    'GET api/v2/client/invoices', 'DELETE api/v2/client/services/123',
];
$isWeb = [true, true, true, true, true, true, false, false, false, false];

$count = 0;
foreach (range(0, 9) as $i) {
    if ($isWeb[$i]) {
        $ctx = ['actor_type' => 'client', 'actor_name' => '测试用户'];
        $module = 'user';
    } else {
        $parts = explode(' ', $actions[$i], 2);
        $ctx = [
            'method' => $parts[0],
            'path' => $parts[1] ?? '/',
            'status' => 200,
            'request_id' => uniqid(),
            'duration_ms' => rand(10, 500),
        ];
        $module = 'api';
    }
    OperationLog::query()->create([
        'user_id' => $userId,
        'user_type' => 'client',
        'action' => $actions[$i],
        'module' => $module,
        'context' => json_encode($ctx),
        'ip_address' => $ips[array_rand($ips)],
        'created_at' => Carbon::parse(
            $dates[array_rand($dates)].' '.rand(8, 22).':'.str_pad(rand(0, 59), 2, '0', STR_PAD_LEFT).':'.str_pad(rand(0, 59), 2, '0', STR_PAD_LEFT)
        ),
    ]);
    $count++;
}

echo "Inserted {$count} logs for user {$userId}.\n";

$total = OperationLog::where('user_id', $userId)->where('user_type', 'client')->count();
$api = OperationLog::where('user_id', $userId)->where('user_type', 'client')->whereNotNull('context->method')->count();
$web = OperationLog::where('user_id', $userId)->where('user_type', 'client')->whereNull('context->method')->count();
echo "Total: {$total} | API: {$api} | Web: {$web}\n";
