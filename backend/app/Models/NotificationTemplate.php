<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'channel',
        'code',
        'name',
        'description',
        'audience',
        'subject',
        'content',
        'variables_json',
        'provider_variables_json',
        'provider_template_id',
        'is_enabled',
        'is_custom',
        'sort_order',
    ];

    protected $casts = [
        'variables_json' => 'array',
        'provider_variables_json' => 'array',
        'is_enabled' => 'boolean',
        'is_custom' => 'boolean',
        'sort_order' => 'integer',
    ];
}
