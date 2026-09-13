<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupportTicketMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'support_ticket_id',
        'sender_id',
        'sender_type',
        'body',
        'attachments',
    ];

    protected $casts = [
        'attachments'=>'array',
    ];
}
