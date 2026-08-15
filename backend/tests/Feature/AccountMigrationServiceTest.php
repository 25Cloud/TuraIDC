<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\System\AccountMigrationService;
use Tests\TestCase;

class AccountMigrationServiceTest extends TestCase
{
    public function test_it_builds_referral_reward_payload_from_legacy_reward_and_invoice_mapping(): void
    {
        $service = new AccountMigrationService;

        $payload = $service->buildReferralRewardPayload(
            legacyReward: [
                'id' => 4,
                'referrer_user_id' => 62,
                'referred_user_id' => 63,
                'order_id' => 21,
                'invoice_id' => null,
                'product_id' => 30,
                'order_amount' => '100.00',
                'reward_rate' => '5.00',
                'reward_amount' => '5.00',
                'available_at' => '2026-05-18 01:39:01',
                'released_at' => '2026-05-18 01:40:01',
                'status' => 1,
                'trace_id' => 'reward-refund-3d538136',
                'remark' => 'legacy reward',
                'created_at' => '2026-05-18 01:39:01',
                'updated_at' => '2026-05-18 01:40:01',
            ],
            targetInvoiceId: 16
        );

        $this->assertSame(4, $payload['id']);
        $this->assertSame(62, $payload['referrer_user_id']);
        $this->assertSame(63, $payload['referred_user_id']);
        $this->assertSame(21, $payload['order_id']);
        $this->assertSame(16, $payload['invoice_id']);
        $this->assertSame(16, $payload['source_invoice_id']);
        $this->assertSame(30, $payload['product_id']);
        $this->assertSame('100.00', $payload['order_amount']);
        $this->assertSame('5.00', $payload['reward_rate']);
        $this->assertSame('5.00', $payload['reward_amount']);
        $this->assertSame('2026-05-18 01:39:01', $payload['available_at']);
        $this->assertSame('2026-05-18 01:40:01', $payload['released_at']);
        $this->assertSame(1, $payload['status']);
    }

    public function test_it_builds_referral_relation_payload_from_legacy_user(): void
    {
        $service = new AccountMigrationService;

        $payload = $service->buildReferralRelationPayload([
            'id' => 63,
            'referrer_user_id' => 62,
            'referral_code' => 'INVITE-63',
            'referred_at' => '2026-05-18 01:39:01',
            'created_at' => '2026-05-18 01:00:00',
            'updated_at' => '2026-05-18 01:40:01',
        ]);

        $this->assertSame(63, $payload['referred_user_id']);
        $this->assertSame(62, $payload['referrer_user_id']);
        $this->assertSame('INVITE-63', $payload['referral_code_snapshot']);
        $this->assertSame('2026-05-18 01:39:01', $payload['bound_at']);
        $this->assertSame('2026-05-18 01:00:00', $payload['created_at']);
        $this->assertSame('2026-05-18 01:40:01', $payload['updated_at']);
    }

    public function test_it_builds_withdrawal_payload_from_legacy_referral_withdrawal(): void
    {
        $service = new AccountMigrationService;

        $payload = $service->buildWithdrawalPayload([
            'id' => 2,
            'user_id' => 51,
            'amount' => '30.00',
            'method' => 'alipay',
            'account_name' => 'tester',
            'account_no' => 'zhifubao-ok-6ead7f50',
            'status' => 1,
            'remark' => 'approved withdrawal',
            'trace_id' => 'withdraw-approved-6ead7f50',
            'processed_at' => '2026-05-18 01:39:57',
            'created_at' => '2026-05-18 01:39:50',
            'updated_at' => '2026-05-18 01:39:57',
        ]);

        $this->assertSame(2, $payload['id']);
        $this->assertSame(51, $payload['user_id']);
        $this->assertSame('referral_withdrawing', $payload['account_type']);
        $this->assertSame('30.00', $payload['amount']);
        $this->assertSame('WD00000002', $payload['withdrawal_no']);
        $this->assertSame(1, $payload['status']);
        $this->assertSame('alipay', $payload['method']);
        $this->assertSame('approved withdrawal', $payload['rejected_reason']);
        $this->assertSame('2026-05-18 01:39:57', $payload['processed_at']);
        $this->assertJson($payload['account_snapshot_json']);
    }

    public function test_it_builds_cash_ledger_payload_from_balance_log(): void
    {
        $service = new AccountMigrationService;

        $payload = $service->buildCashLedgerPayload([
            'id' => 4,
            'user_id' => 61,
            'event_type' => 'invoice_refund',
            'change_amount' => '100.00',
            'balance_after' => '100.00',
            'reference_id' => 14,
            'remark' => '账单退款 INVREF3E29E69C',
            'created_at' => '2026-05-18 01:40:00',
        ]);

        $this->assertSame(4, $payload['id']);
        $this->assertSame(61, $payload['user_id']);
        $this->assertSame('cash', $payload['account_type']);
        $this->assertSame('invoice_refund', $payload['business_type']);
        $this->assertSame('credit', $payload['direction']);
        $this->assertSame('100.00', $payload['amount']);
        $this->assertSame('0.00', $payload['balance_before']);
        $this->assertSame('100.00', $payload['balance_after']);
        $this->assertSame('balance_log', $payload['source_type']);
        $this->assertSame(14, $payload['source_id']);
    }

    public function test_it_builds_referral_ledger_payload_from_referral_account_log(): void
    {
        $service = new AccountMigrationService;

        $payload = $service->buildReferralLedgerPayload([
            'id' => 1,
            'user_id' => 11,
            'event_type' => 'reward_frozen',
            'change_amount' => '10.00',
            'frozen_balance' => '10.00',
            'available_balance' => '0.00',
            'pending_withdrawal_balance' => '0.00',
            'withdrawn_balance' => '0.00',
            'reference_type' => null,
            'reference_id' => null,
            'remark' => 'referral regression',
            'trace_id' => 'referral-regression',
            'created_at' => '2026-05-18 01:39:44',
        ]);

        $this->assertSame(1000001, $payload['id']);
        $this->assertSame(11, $payload['user_id']);
        $this->assertSame('referral_frozen', $payload['account_type']);
        $this->assertSame('reward_frozen', $payload['business_type']);
        $this->assertSame('credit', $payload['direction']);
        $this->assertSame('10.00', $payload['amount']);
        $this->assertSame('0.00', $payload['balance_before']);
        $this->assertSame('10.00', $payload['balance_after']);
        $this->assertSame('referral_account_log', $payload['source_type']);
    }

    public function test_it_builds_opening_balance_ledger_payload_for_account_baseline(): void
    {
        $service = new AccountMigrationService;

        $payload = $service->buildOpeningBalanceLedgerPayload(
            userId: 51,
            accountType: 'cash',
            openingBalance: '88.50',
            happenedAt: '2026-05-18 00:00:00'
        );

        $this->assertSame(2000000511, $payload['id']);
        $this->assertSame(51, $payload['user_id']);
        $this->assertSame('cash', $payload['account_type']);
        $this->assertSame('opening_balance', $payload['business_type']);
        $this->assertSame('credit', $payload['direction']);
        $this->assertSame('88.50', $payload['amount']);
        $this->assertSame('0.00', $payload['balance_before']);
        $this->assertSame('88.50', $payload['balance_after']);
        $this->assertSame('account_migration', $payload['source_type']);
        $this->assertSame('2026-05-18 00:00:00', $payload['happened_at']);
    }

    public function test_it_partitions_payloads_by_existing_user_set(): void
    {
        $service = new AccountMigrationService;

        $partition = $service->partitionPayloadsByUserSet(
            [
                ['id' => 1, 'user_id' => 51, 'amount' => '20.00'],
                ['id' => 2, 'user_id' => 167, 'amount' => '8.00'],
                ['id' => 3, 'user_id' => 66, 'amount' => '30.00'],
            ],
            [
                51 => true,
                66 => true,
            ]
        );

        $this->assertCount(2, $partition['kept']);
        $this->assertSame([2], $partition['skipped_row_ids']);
        $this->assertSame([167], $partition['skipped_user_ids']);
    }

    public function test_it_partitions_referral_relations_by_existing_user_sets(): void
    {
        $service = new AccountMigrationService;

        $partition = $service->partitionReferralRelationsByUserSet(
            [
                [
                    'referred_user_id' => 63,
                    'referrer_user_id' => 62,
                    'referral_code_snapshot' => 'INVITE-63',
                ],
                [
                    'referred_user_id' => 64,
                    'referrer_user_id' => 999,
                    'referral_code_snapshot' => 'INVITE-64',
                ],
                [
                    'referred_user_id' => 1001,
                    'referrer_user_id' => 62,
                    'referral_code_snapshot' => 'INVITE-1001',
                ],
            ],
            [
                62 => true,
                63 => true,
                64 => true,
            ]
        );

        $this->assertCount(1, $partition['kept']);
        $this->assertSame([1001], $partition['skipped_referred_user_ids']);
        $this->assertSame([999], $partition['skipped_referrer_user_ids']);
    }

    public function test_it_builds_balance_snapshot_payloads_from_user_account(): void
    {
        $service = new AccountMigrationService;

        $payloads = $service->buildBalanceSnapshotPayloads([
            'user_id' => 51,
            'cash_balance' => '88.50',
            'referral_available_balance' => '22.00',
            'referral_frozen_balance' => '11.00',
            'referral_pending_withdrawal_balance' => '20.00',
            'referral_withdrawn_balance' => '30.00',
        ], '2026-05-18');

        $this->assertCount(5, $payloads);
        $this->assertSame('cash', $payloads[0]['account_type']);
        $this->assertSame('88.50', $payloads[0]['available_balance']);
        $this->assertSame('referral_frozen', $payloads[1]['account_type']);
        $this->assertSame('11.00', $payloads[1]['frozen_balance']);
        $this->assertSame('referral_available', $payloads[2]['account_type']);
        $this->assertSame('22.00', $payloads[2]['available_balance']);
        $this->assertSame('referral_withdrawing', $payloads[3]['account_type']);
        $this->assertSame('20.00', $payloads[3]['frozen_balance']);
        $this->assertSame('referral_withdrawn', $payloads[4]['account_type']);
        $this->assertSame('30.00', $payloads[4]['available_balance']);
    }
}
