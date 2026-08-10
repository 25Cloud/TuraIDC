<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Server extends Model
{
    protected $fillable = [
        'name', 'hostname', 'ip_address', 'type', 'module',
        'module_config', 'max_accounts', 'current_accounts', 'status',
    ];

    protected function casts(): array
    {
        return ['module_config' => 'array'];
    }
}
