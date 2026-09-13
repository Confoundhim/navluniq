<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class ActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'action',
        'description',
        'ip_address',
    ];

    /**
     * İşlemi Yapan Personel İlişkisi
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Sistem Genelinde Otomatik Sicil Logu Kaydedici Statik Yardımcı Metod [17]
     * 🚀 Intelephense uyarısını önlemek için resmi Auth Facade sınıfı kullanılmıştır.
     */
    public static function record(string $action, string $description)
    {
        if (Auth::check()) {
            self::create([
                'user_id' => Auth::id(),
                'action' => $action,
                'description' => $description,
                'ip_address' => request()->ip()
            ]);
        }
    }
}
