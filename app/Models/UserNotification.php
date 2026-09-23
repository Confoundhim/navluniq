<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kullanıcıya gösterilen uygulama içi bildirim ve ona bağlı e-posta gönderim durumu.
 */
class UserNotification extends Model
{
    public const MAIL_PENDING = 'pending';

    public const MAIL_SENT = 'sent';

    public const MAIL_FAILED = 'failed';

    public const MAIL_SKIPPED = 'skipped';

    public const MAX_MAIL_ATTEMPTS = 4;

    /** Bildirim türü → panelde gösterilen etiket. */
    public const TYPE_LABELS = [
        'general' => 'Genel',
        'return_load' => 'Dönüş yükü',
        'welcome' => 'Hoş geldiniz',
        'security' => 'Güvenlik',
        'kyc' => 'Belge doğrulama',
        'offer' => 'Teklif',
        'load' => 'İlan',
        'shipment' => 'Sevkiyat',
        'payment' => 'Ödeme',
        'payout' => 'Hakediş',
        'subscription' => 'Premium üyelik',
        'dispute' => 'Uyuşmazlık',
        'support' => 'Destek',
        'review' => 'Değerlendirme',
        'admin' => 'Yönetim',
    ];

    protected $fillable = [
        'user_id', 'type', 'title', 'lines', 'action_url', 'action_text',
        'mail_status', 'mail_attempts', 'mail_error', 'mail_sent_at', 'read_at',
    ];

    protected $casts = [
        'lines' => 'array',
        'mail_sent_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function scopeMailRetryable(Builder $query): Builder
    {
        return $query->where('mail_status', self::MAIL_FAILED)->where('mail_attempts', '<', self::MAX_MAIL_ATTEMPTS);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function markRead(): void
    {
        if ($this->read_at === null) {
            $this->forceFill(['read_at' => now()])->save();
        }
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? ucfirst($this->type);
    }

    public function mailStatusLabel(): string
    {
        return match ($this->mail_status) {
            self::MAIL_SENT => 'Gönderildi',
            self::MAIL_FAILED => 'Başarısız',
            self::MAIL_SKIPPED => 'E-posta yok',
            default => 'Bekliyor',
        };
    }
}
