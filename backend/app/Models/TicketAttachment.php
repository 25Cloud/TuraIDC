<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketAttachment extends Model
{
    protected $fillable = [
        'ticket_message_id',
        'file_name',
        'file_path',
        'mime_type',
        'file_size',
        'created_at',
        'updated_at',
    ];
}
