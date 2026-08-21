<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ticket_reply_deliveries') && ! Schema::hasColumn('ticket_reply_deliveries', 'last_attempt_at')) {
            Schema::table('ticket_reply_deliveries', function (Blueprint $table): void {
                $table->timestamp('last_attempt_at')->nullable()->after('attempts');
            });
        }

        if (! Schema::hasTable('ticket_replies')) {
            return;
        }

        Schema::table('ticket_replies', function (Blueprint $table): void {
            if (! Schema::hasColumn('ticket_replies', 'sender_type')) {
                $table->string('sender_type', 32)->nullable()->after('is_staff');
            }
            if (! Schema::hasColumn('ticket_replies', 'sender_name')) {
                $table->string('sender_name', 120)->nullable()->after('sender_type');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('ticket_reply_deliveries') && Schema::hasColumn('ticket_reply_deliveries', 'last_attempt_at')) {
            Schema::table('ticket_reply_deliveries', function (Blueprint $table): void {
                $table->dropColumn('last_attempt_at');
            });
        }

        if (! Schema::hasTable('ticket_replies')) {
            return;
        }

        Schema::table('ticket_replies', function (Blueprint $table): void {
            $columns = [];
            if (Schema::hasColumn('ticket_replies', 'sender_name')) {
                $columns[] = 'sender_name';
            }
            if (Schema::hasColumn('ticket_replies', 'sender_type')) {
                $columns[] = 'sender_type';
            }
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
