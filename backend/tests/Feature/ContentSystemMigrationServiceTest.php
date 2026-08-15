<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\System\ContentSystemMigrationService;
use Tests\TestCase;

class ContentSystemMigrationServiceTest extends TestCase
{
    public function test_it_builds_setting_payload_and_detects_value_type(): void
    {
        $service = new ContentSystemMigrationService;

        $payload = $service->buildSettingPayload([
            'id' => 22,
            'group_key' => 'basic',
            'item_key' => 'site_name',
            'item_value' => '图拉云',
        ]);

        $this->assertSame(22, $payload['id']);
        $this->assertSame('basic', $payload['group_key']);
        $this->assertSame('site_name', $payload['item_key']);
        $this->assertSame('图拉云', $payload['item_value']);
        $this->assertSame('string', $payload['value_type']);
        $this->assertSame(1, $payload['is_public']);
    }

    public function test_it_builds_notification_log_payload_for_message_logs(): void
    {
        $service = new ContentSystemMigrationService;

        $payload = $service->buildNotificationLogPayload([
            'id' => 2,
            'channel' => 'sms',
            'recipient' => '13896336364',
            'template_code' => '100001',
            'content' => '验证码',
            'params_json' => '{"code":"123456"}',
            'provider' => 'aliyun',
            'request_id' => 'req-otp-e0db4ca2',
            'status' => 'success',
            'error_msg' => null,
            'origin_type' => 'sms_verify',
            'origin_id' => 0,
            'created_at' => '2026-05-18 01:40:18',
            'updated_at' => '2026-05-18 01:40:18',
        ]);

        $this->assertSame(2, $payload['id']);
        $this->assertSame('sms', $payload['channel']);
        $this->assertSame('success', $payload['status']);
        $this->assertNull($payload['error_msg']);
        $this->assertSame('req-otp-e0db4ca2', $payload['trace_id']);
    }

    public function test_it_builds_automation_log_payload_and_derives_result_status(): void
    {
        $service = new ContentSystemMigrationService;

        $payload = $service->buildAutomationLogPayload([
            'id' => 1,
            'task_key' => 'coupon-campaign-dispatch',
            'action' => 'dispatch',
            'object_type' => 'coupon_campaign',
            'object_id' => 2,
            'rule_key' => '202605180800',
            'meta' => '{"trace_id":"auto-trace-1","status":"success"}',
            'executed_at' => '2026-05-18 01:39:45',
            'created_at' => '2026-05-18 01:39:45',
            'updated_at' => '2026-05-18 01:39:45',
        ]);

        $this->assertSame(1, $payload['id']);
        $this->assertSame('success', $payload['result_status']);
        $this->assertSame('auto-trace-1', $payload['trace_id']);
        $this->assertJson($payload['meta_json']);
    }

    public function test_it_builds_operation_log_payload_and_derives_subject_type(): void
    {
        $service = new ContentSystemMigrationService;

        $payload = $service->buildOperationLogPayload([
            'id' => 22,
            'user_id' => 14,
            'user_type' => 'client',
            'action' => 'POST api/v2/client/invoices',
            'module' => 'invoices',
            'subject_id' => null,
            'context' => '{"request_id":"invoice-trace-22","status":403}',
            'ip_address' => '127.0.0.1',
            'created_at' => '2026-05-18 01:39:45',
        ]);

        $this->assertSame(22, $payload['id']);
        $this->assertSame(14, $payload['user_id']);
        $this->assertSame('invoice', $payload['subject_type']);
        $this->assertSame('invoice-trace-22', $payload['trace_id']);
        $this->assertJson($payload['context_json']);
    }

    public function test_it_builds_ticket_payload_with_service_mapping_and_reply_timestamps(): void
    {
        $service = new ContentSystemMigrationService;

        $payload = $service->buildTicketPayload(
            legacyTicket: [
                'id' => 7,
                'user_id' => 75,
                'department' => 'support',
                'subject' => 'Ticket Detail Regression',
                'priority' => 2,
                'status' => 3,
                'service_id' => 12,
                'assignee_id' => null,
                'created_at' => '2026-05-18 01:40:20',
                'updated_at' => '2026-05-18 01:45:20',
                'close_reason' => 'client',
            ],
            targetServiceInstanceId: 1,
            lastReplyAt: '2026-05-18 01:41:00'
        );

        $this->assertSame(7, $payload['id']);
        $this->assertSame('TK00000007', $payload['ticket_no']);
        $this->assertSame(75, $payload['user_id']);
        $this->assertSame(1, $payload['service_instance_id']);
        $this->assertSame('2026-05-18 01:41:00', $payload['last_reply_at']);
        $this->assertSame('2026-05-18 01:45:20', $payload['closed_at']);
        $this->assertSame('client', $payload['close_reason']);
    }

    public function test_it_builds_ticket_reply_payload_and_maps_staff_sender_to_admin(): void
    {
        $service = new ContentSystemMigrationService;

        $payload = $service->buildTicketReplyPayload([
            'id' => 4,
            'ticket_id' => 2,
            'user_id' => 12,
            'content' => 'Staff response',
            'is_staff' => 1,
            'attachments' => '[]',
            'created_at' => '2026-05-18 01:40:19',
        ]);

        $this->assertSame(4, $payload['id']);
        $this->assertSame(2, $payload['ticket_id']);
        $this->assertNull($payload['user_id']);
        $this->assertSame(12, $payload['admin_user_id']);
        $this->assertSame('admin', $payload['sender_type']);
        $this->assertSame('[]', $payload['attachments_json']);
        $this->assertSame(0, $payload['is_internal']);
    }
}
