<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientInvoiceRoutesRemovedTest extends TestCase
{
    public function test_client_invoice_routes_are_available(): void
    {
        $user = User::query()->create([
            'email' => 'invoice-route-removed-'.bin2hex(random_bytes(4)).'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Invoice Route Removed',
            'real_name' => '',
            'id_card' => '',
            'verification_status' => 0,
            'verification_message' => '',
            'verification_certify_id' => null,
            'member_level_id' => null,
            'total_sales_amount' => '0.00',
            'referrer_user_id' => null,
            'verified_at' => null,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v2/client/invoices')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->getJson('/api/v2/client/invoices/summary')
            ->assertOk()
            ->assertJsonPath('code', 0);
    }
}
