<?php

declare(strict_types=1);

namespace App\Services\System;

use App\Exceptions\BusinessException;
use Throwable;

class NotificationTemplateTestSendService
{
    public function __construct(
        private readonly NotificationTemplateService $templates,
        private readonly NotificationService $emails,
        private readonly SmsService $sms,
    ) {}

    /**
     * @param  list<string>  $recipients
     * @return array<string, mixed>
     */
    public function send(string $channel, string $code, array $recipients): array
    {
        $template = $this->templates->find($channel, $code);
        if (! is_array($template)) {
            throw new BusinessException('通知模板不存在', 40400, 404);
        }

        $params = $this->buildSampleParams($template);
        $results = [];

        foreach ($recipients as $recipient) {
            $results[] = $this->sendOne($channel, $code, $template, $recipient, $params);
        }

        $successCount = count(array_filter(
            $results,
            static fn (array $result): bool => ($result['status'] ?? '') === 'success'
        ));
        $failedCount = count($results) - $successCount;

        return [
            'channel' => $channel,
            'code' => $code,
            'template_name' => (string) ($template['name'] ?? ''),
            'status' => $this->summaryStatus($successCount, $failedCount),
            'total' => count($results),
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'results' => $results,
        ];
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, string>  $params
     * @return array{recipient: string, status: string, message: string, error: string|null}
     */
    private function sendOne(string $channel, string $code, array $template, string $recipient, array $params): array
    {
        try {
            if (! ((bool) ($template['is_enabled'] ?? true))) {
                throw new BusinessException('模板已停用，无法测试发送');
            }

            if ($channel === 'email') {
                $this->emails->sendTemplateEmail($recipient, $code, $params);
            } else {
                $this->sms->sendTemplateSms($recipient, $code, $params, [
                    'origin_type' => 'notification_template_test',
                ]);
            }

            return [
                'recipient' => $recipient,
                'status' => 'success',
                'message' => '发送成功',
                'error' => null,
            ];
        } catch (Throwable $exception) {
            return [
                'recipient' => $recipient,
                'status' => 'failed',
                'message' => '发送失败',
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $template
     * @return array<string, string>
     */
    private function buildSampleParams(array $template): array
    {
        $variables = $this->templateVariables($template);
        $params = [];

        foreach ($variables as $variable) {
            $params[$variable] = $this->sampleValue($variable);
        }

        return $params;
    }

    /**
     * @param  array<string, mixed>  $template
     * @return list<string>
     */
    private function templateVariables(array $template): array
    {
        $variables = array_map('strval', (array) ($template['variables'] ?? []));
        $content = (string) ($template['content'] ?? '');
        $subject = (string) ($template['subject'] ?? '');

        preg_match_all('/\{\{\s*#?\s*([a-zA-Z0-9_]+)\s*\}\}/u', $subject."\n".$content, $doubleBraceMatches);
        preg_match_all('/(?<!\{)\{([a-zA-Z0-9_]+)\}(?!\})/u', $subject."\n".$content, $singleBraceMatches);

        return array_values(array_unique(array_filter(array_merge(
            $variables,
            array_map('strval', $doubleBraceMatches[1] ?? []),
            array_map('strval', $singleBraceMatches[1] ?? [])
        ), static fn (string $variable): bool => trim($variable) !== '')));
    }

    private function sampleValue(string $key): string
    {
        return match ($key) {
            'site_name' => '图拉云',
            'site_logo' => '/branding/logo.svg',
            'display_name' => '张三',
            'username' => 'zhangsan',
            'recipient_name' => '运维管理员',
            'email' => 'demo@example.com',
            'client_email' => 'client@example.com',
            'client_name' => '测试用户',
            'login_at' => '2026-04-01 14:30:00',
            'ip' => '203.0.113.25',
            'device' => 'Windows / Chrome',
            'code' => '482915',
            'expire_minutes', 'min' => '5',
            'service_name' => '香港云服务器 CSP-2G',
            'days_left' => '3',
            'expires_at' => '2026-04-10 23:59:59',
            'billing_cycle_label' => '月付',
            'urgency_message' => '您的服务将在 3 天后到期，请提前完成续费。',
            'invoice_no', 'invoiceid' => 'zd202604011430004821',
            'order_no', 'order_id' => 'dd202604011430004821',
            'product_name' => 'ecs.g9i.2c2g 2 vCPU 2G',
            'product_main_ip' => '203.0.113.18',
            'product_username' => 'administrator',
            'operating_system' => 'Ubuntu 22.04',
            'addon_ip' => '203.0.113.19',
            'activated_at' => '2026-04-01 14:36:00',
            'hostname' => 'hk-node-01',
            'amount', 'total', 'paid_amount', 'order_total_fee', 'product_price' => '199.00',
            'due_date' => '2026-04-05',
            'due_at' => '2026-04-05 23:59:59',
            'paid_at' => '2026-04-01 10:12:33',
            'refunded_at' => '2026-04-02 09:10:00',
            'payment_method' => '支付宝',
            'trade_no' => '2026040100001001',
            'notice_title' => '账单提醒',
            'notice_message' => '请在到期前完成支付，以免影响关联服务。',
            'status_label', 'status' => '处理中',
            'remark' => '人工核账通过',
            'ticket_id' => '1024',
            'ticket_subject', 'subject' => '实例网络不通',
            'ticket_created_at' => '2026-04-01 14:40:00',
            'ticket_closed_at' => '2026-04-04 14:40:00',
            'department' => '技术支持',
            'priority' => '高',
            'message_preview' => '您好，实例无法 SSH 登录，请协助排查。',
            'staff_name' => '技术支持 A',
            'tickets_url' => '/client/tickets',
            'login_tip' => '如您尚未登录，请先登录后查看工单详情。',
            'bind_type' => '手机',
            'bind_account' => '138****8000',
            'registered_at' => '2026-04-01 09:00:00',
            'terminated_at' => '2026-05-10 03:00:00',
            'resumed_at' => '2026-04-02 12:00:00',
            'approved_at' => '2026-04-03 16:00:00',
            'bound_at' => '2026-04-01 09:30:00',
            'product_end_time' => '2026-04-10 23:59:59',
            'product_first_time' => '2026-04-01 14:30:00',
            'product_binlly_cycle' => '月付',
            'description' => '测试发送',
            default => '测试'.$key,
        };
    }

    private function summaryStatus(int $successCount, int $failedCount): string
    {
        if ($failedCount === 0) {
            return 'success';
        }

        if ($successCount > 0) {
            return 'partial_failed';
        }

        return 'failed';
    }
}
