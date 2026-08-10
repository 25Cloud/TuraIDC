<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketDepartment extends Model
{
    protected $fillable = [
        'code',
        'name',
        'status',
        'sort_order',
    ];
}
