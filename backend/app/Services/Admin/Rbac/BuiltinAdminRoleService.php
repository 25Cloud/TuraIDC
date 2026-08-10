<?php

declare(strict_types=1);

namespace App\Services\Admin\Rbac;

use App\Support\AdminPermissions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BuiltinAdminRoleService
{
    public function sync(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        $now = Carbon::now();
        $labels = AdminPermissions::builtInRoleLabels();
        $rows = [];

        foreach (AdminPermissions::builtInRolePermissions() as $name => $permissions) {
            $rows[] = [
                'name' => $name,
                'label' => $labels[$name] ?? $name,
                'permissions' => json_encode(array_values($permissions), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('roles')->upsert($rows, ['name'], ['label', 'permissions', 'updated_at']);
    }
}
