<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $table = 'settings';

    public $timestamps = false;

    protected $fillable = [
        'group_key',
        'item_key',
        'item_value',
    ];
}
