<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Models\User;
use App\Models\VerificationHistory;
use App\Support\AdminPermissions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2AdminVerificationApiTest extends TestCase
{
    public function test_admin_verification_read_apis_use_v2_projection(): void
    {
        $fixture = $this->createVerifiedUserFixture();

        $this->getJson('/api/v2/admin/verifications')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([]));

        $this->getJson('/api/v2/admin/verifications')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::VERIFICATION_LIST]));

        $this->getJson('/api/v2/admin/verifications?per_page=20&pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page', 'pageSize']]]);

        $listResponse = $this->getJson('/api/v2/admin/verifications?'.http_build_query([
            'keyword' => $fixture['user']->nickname,
            'page' => 1,
            'page_size' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.id', $fixture['user']->id)
            ->assertJsonMissingPath('data.list.0.verification_certify_id')
            ->assertJsonMissingPath('data.list.0.id_card');

        $this->assertSame(['list', 'total', 'page', 'page_size'], array_keys($listResponse->json('data')));
        $this->assertSame($this->listWhitelist(), array_keys($listResponse->json('data.list.0')));
        $this->assertNoSensitiveKeys($listResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $listResponse->getContent()));

        $summaryResponse = $this->getJson('/api/v2/admin/verifications/summary')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->assertSame(['stats', 'config'], array_keys($summaryResponse->json('data')));
        $this->assertSame($this->summaryStatsWhitelist(), array_keys($summaryResponse->json('data.stats')));
        $this->assertSame($this->summaryConfigWhitelist(), array_keys($summaryResponse->json('data.config')));
        $this->assertNoSensitiveKeys($summaryResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $summaryResponse->getContent()));

        $detailResponse = $this->getJson('/api/v2/admin/verifications/'.$fixture['user']->id)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', $fixture['user']->id)
            ->assertJsonMissingPath('data.verification_certify_id')
            ->assertJsonMissingPath('data.id_card');

        $this->assertSame($this->detailWhitelist(), array_keys($detailResponse->json('data')));
        $this->assertNoSensitiveKeys($detailResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $detailResponse->getContent()));

        $historyResponse = $this->getJson('/api/v2/admin/verifications/'.$fixture['user']->id.'/history?'.http_build_query([
            'page' => 1,
            'page_size' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.id', $fixture['history']->id)
            ->assertJsonMissingPath('data.list.0.verification_certify_id')
            ->assertJsonMissingPath('data.list.0.id_card');

        $this->assertSame(['user_name', 'list', 'total', 'page', 'page_size'], array_keys($historyResponse->json('data')));
        $this->assertSame($this->historyWhitelist(), array_keys($historyResponse->json('data.list.0')));
        $this->assertNoSensitiveKeys($historyResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $historyResponse->getContent()));
    }

    public function test_admin_verification_unbind_uses_v2_action_and_compact_result(): void
    {
        $fixture = $this->createVerifiedUserFixture();

        Sanctum::actingAs($this->createAdmin([AdminPermissions::VERIFICATION_LIST]));

        $this->postJson('/api/v2/admin/verifications/'.$fixture['user']->id.'/unbindings', [
            'reject_reason' => 'manual reject',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::VERIFICATION_UNBIND]));

        $this->postJson('/api/v2/admin/verifications/'.$fixture['user']->id.'/unbindings?pageSize=20', [
            'reject_reason' => '',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['reject_reason', 'pageSize']]]);

        $response = $this->postJson('/api/v2/admin/verifications/'.$fixture['user']->id.'/unbindings', [
            'reject_reason' => 'manual reject',
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.user_id', $fixture['user']->id)
            ->assertJsonPath('data.reject_reason', 'manual reject')
            ->assertJsonMissingPath('data.verification_certify_id');

        $this->assertSame($this->unbindWhitelist(), array_keys($response->json('data')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));

        $fixture['user']->refresh();
        $this->assertSame(5, (int) $fixture['user']->verification_status);
        $this->assertSame(0, (int) $fixture['user']->is_verified);
        $this->assertNull($fixture['user']->verification_certify_id);
    }

    /**
     * @return array{user: User, history: VerificationHistory}
     */
    private function createVerifiedUserFixture(): array
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $user = User::query()->create([
            'email' => 'v2-verification-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'V2 Verification '.$suffix,
            'real_name' => 'Verified User '.$suffix,
            'id_card' => '110101199001011234',
            'is_verified' => 1,
            'verification_status' => 2,
            'verification_message' => 'passed',
            'verification_certify_id' => 'CERT-SECRET-'.$suffix,
            'member_level_id' => null,
            'total_sales_amount' => '0.00',
            'referrer_user_id' => null,
            'verified_at' => now(),
        ]);

        $history = VerificationHistory::query()->create([
            'user_id' => (int) $user->id,
            'real_name' => 'Verified User '.$suffix,
            'id_card' => '110101199001011234',
            'verification_status' => 2,
            'verification_message' => 'passed',
            'verification_certify_id' => 'CERT-HISTORY-SECRET-'.$suffix,
            'verification_biz_code' => 'FACE',
            'verification_type' => 'personal',
            'submitted_at' => now(),
            'completed_at' => now(),
        ]);

        return [
            'user' => $user,
            'history' => $history,
        ];
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-verification-'.$suffix,
            'label' => 'V2 Verification',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-verification-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 Verification',
            'email' => 'v2-verification-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    /**
     * @return list<string>
     */
    private function listWhitelist(): array
    {
        return [
            'id',
            'email',
            'phone',
            'nickname',
            'display_name',
            'real_name',
            'id_card_masked',
            'verification_status',
            'verification_status_label',
            'verification_message',
            'created_at',
        ];
    }

    /**
     * @return list<string>
     */
    private function detailWhitelist(): array
    {
        return [
            'id',
            'display_name',
            'email',
            'phone',
            'real_name',
            'id_card_masked',
            'verification_status',
            'verification_status_label',
            'verification_message',
            'verification_biz_code',
            'verification_method_label',
            'verification_type_label',
            'document_type_label',
            'identity_region_label',
            'created_at',
            'updated_at',
            'verified_at',
        ];
    }

    /**
     * @return list<string>
     */
    private function historyWhitelist(): array
    {
        return [
            'id',
            'real_name',
            'id_card_masked',
            'verification_status',
            'verification_status_label',
            'verification_message',
            'verification_method_label',
            'verification_type_label',
            'submitted_at',
            'completed_at',
        ];
    }

    /**
     * @return list<string>
     */
    private function summaryStatsWhitelist(): array
    {
        return [
            'total',
            'verified',
            'pending',
            'failed',
            'unbound',
        ];
    }

    /**
     * @return list<string>
     */
    private function summaryConfigWhitelist(): array
    {
        return [
            'verification_api_masked',
            'verification_biz_code',
            'configured',
            'driver_key',
            'plugin_id',
            'free_attempts',
            'retry_fee',
            'charge_enabled',
            'amount',
        ];
    }

    /**
     * @return list<string>
     */
    private function unbindWhitelist(): array
    {
        return [
            'user_id',
            'real_name',
            'unbound_at',
            'operator',
            'reject_reason',
        ];
    }

    private function assertNoSensitiveKeys(mixed $payload): void
    {
        if (! is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                foreach (['password', 'secret', 'api_key', 'raw_response', 'third_party_response', 'certify_id'] as $needle) {
                    $this->assertStringNotContainsString($needle, strtolower($key));
                }
            }

            $this->assertNoSensitiveKeys($value);
        }
    }
}
