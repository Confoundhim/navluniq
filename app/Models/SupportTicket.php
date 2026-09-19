<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupportTicket extends Model
{
    use HasFactory, HasPublicId, SoftDeletes;

    public const CATEGORIES = [
        'technical' => 'Teknik sorun ve hata bildirimi',
        'account' => 'Hesap, giriş ve güvenlik',
        'kyc' => 'Belge ve KYC doğrulama',
        'load' => 'İlan, teklif ve rota',
        'escrow' => 'Ödeme ve tahsilat',
        'billing' => 'Fatura ve muhasebe',
        'subscription' => 'Abonelik ve fiyatlandırma',
        'dispute' => 'Uyuşmazlık yönetimi',
        'partnership' => 'İş birliği ve kurumsal',
        'other' => 'Diğer',
    ];

    public const ROLE_LABELS = ['cargo_owner' => 'Yük sahibi', 'driver' => 'Şoför', 'guest' => 'Ziyaretçi'];

    protected $fillable = [
        'user_id',
        'name',
        'email',
        'phone',
        'role',
        'category',
        'subject',
        'message',
        'status',
        'priority',
        'admin_reply',
        'assigned_to',
        'replied_at',
        'closed_at',
    ];

    protected $casts = [
        'replied_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
