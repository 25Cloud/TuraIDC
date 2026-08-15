<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountTransaction;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Models\UserAccount;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IdcModelLayerSmokeTest extends TestCase
{
    public function test_core_models_use_default_connection(): void
    {
        $this->assertNull((new Service)->getConnectionName());
        $this->assertSame('services', (new Service)->getTable());
        $this->assertNull((new AccountTransaction)->getConnectionName());
        $this->assertSame('account_transactions', (new AccountTransaction)->getTable());
        $this->assertNull((new Invoice)->getConnectionName());
        $this->assertNull((new Ticket)->getConnectionName());
    }

    public function test_core_relations_are_available(): void
    {
        $this->assertTrue(method_exists(Invoice::class, 'service'));
        $this->assertTrue(method_exists(Invoice::class, 'payments'));
        $this->assertTrue(method_exists(User::class, 'services'));
        $this->assertTrue(method_exists(User::class, 'accountTransactions'));
        $this->assertTrue(method_exists(Product::class, 'services'));
        $this->assertTrue(method_exists(Coupon::class, 'orders'));
        $this->assertTrue(method_exists(Ticket::class, 'service'));
    }

    public function test_user_account_model_exposes_current_balance_fields(): void
    {
        $account = new UserAccount([
            'cash_balance' => '12.34',
            'credit_limit' => '56.78',
            'referral_frozen_balance' => '1.23',
            'version' => 2,
        ]);

        $this->assertTrue(in_array('cash_balance', $account->getFillable(), true));
        $this->assertTrue(in_array('credit_limit', $account->getFillable(), true));
        $this->assertTrue(in_array('referral_frozen_balance', $account->getFillable(), true));
        $this->assertSame('12.34', $account->cash_balance);
        $this->assertSame('56.78', $account->credit_limit);
        $this->assertSame('1.23', $account->referral_frozen_balance);
        $this->assertSame(2, $account->version);
    }

    public function test_ticket_model_uses_current_columns(): void
    {
        $ticket = new Ticket;
        $ticket->service_id = 12;
        $ticket->assignee_id = 8;

        $this->assertSame(12, $ticket->service_id);
        $this->assertSame(8, $ticket->assignee_id);
        $this->assertTrue(in_array('service_id', $ticket->getFillable(), true));
        $this->assertTrue(in_array('assignee_id', $ticket->getFillable(), true));
    }

    public function test_ticket_reply_model_uses_current_columns(): void
    {
        $reply = new TicketReply;
        $reply->attachments = [['name' => 'a.png', 'path' => '/a.png']];
        $reply->is_staff = 1;
        $reply->user_id = 5;

        $this->assertSame(1, $reply->is_staff);
        $this->assertIsArray($reply->attachments);
        $this->assertSame('a.png', $reply->attachments[0]['name'] ?? null);
        $this->assertTrue(in_array('is_staff', $reply->getFillable(), true));
    }

    public function test_services_table_exposes_core_indexes(): void
    {
        $indexes = collect(DB::select("
            SELECT DISTINCT index_name AS index_name
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'services'
        "))
            ->map(fn (object $row) => (string) $row->index_name)
            ->values()
            ->all();

        $this->assertNotEmpty($indexes);
        $this->assertContains('PRIMARY', $indexes);
        $this->assertTrue(collect($indexes)->contains(fn (string $name): bool => str_contains($name, 'user')));
        $this->assertTrue(collect($indexes)->contains(fn (string $name): bool => str_contains($name, 'product')));
    }

    public function test_services_table_exposes_core_foreign_keys(): void
    {
        $foreignKeys = collect(DB::select("
            SELECT constraint_name AS constraint_name
            FROM information_schema.table_constraints
            WHERE table_schema = DATABASE()
              AND table_name = 'services'
              AND constraint_type = 'FOREIGN KEY'
        "))
            ->map(fn (object $row) => (string) $row->constraint_name)
            ->values()
            ->all();

        $this->assertNotEmpty($foreignKeys);
        $this->assertTrue(collect($foreignKeys)->contains(fn (string $name): bool => str_contains($name, 'user')));
        $this->assertTrue(collect($foreignKeys)->contains(fn (string $name): bool => str_contains($name, 'product')));
        $this->assertTrue(collect($foreignKeys)->contains(fn (string $name): bool => str_contains($name, 'invoice')));
    }
}
