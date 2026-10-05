<?php

namespace App\Services;

use App\Models\Load;
use App\Models\Review;
use App\Models\User;
use RuntimeException;

class ReviewService
{
    public function submit(Load $load, User $reviewer, int $rating, ?string $comment = null): Review
    {
        // Puan yalnız onaylanıp tamamlanan sevkiyata verilir: teslimat onayı/uyuşmazlık sürerken ya da iade ile kapanmışsa verilmez.
        if (! self::canReview($load)) {
            throw new RuntimeException('Değerlendirme yalnız tamamlanmış (onaylanmış) sevkiyatlar için yapılabilir.');
        }

        $ownerUserId = $load->cargoOwnerProfile?->user_id;
        $driverUserId = $load->driverProfile?->user_id;

        $revieweeId = match (true) {
            $reviewer->id === $ownerUserId => $driverUserId,
            $reviewer->id === $driverUserId => $ownerUserId,
            default => null,
        };

        if (! $revieweeId) {
            throw new RuntimeException('Bu sevkiyatın tarafı değilsiniz.');
        }

        if (Review::query()->where('load_id', $load->id)->where('reviewer_id', $reviewer->id)->exists()) {
            throw new RuntimeException('Bu sevkiyatı zaten değerlendirdiniz.');
        }

        $review = Review::create([
            'load_id' => $load->id,
            'reviewer_id' => $reviewer->id,
            'reviewee_id' => $revieweeId,
            'rating' => max(1, min(5, $rating)),
            'comment' => $comment ? mb_substr(trim($comment), 0, 1000) : null,
        ]);

        if ($reviewee = User::find($revieweeId)) {
            $isDriver = $reviewee->id === $driverUserId;
            app(NotificationService::class)->notify($reviewee, 'Yeni değerlendirme aldınız',
                ["{$load->pickup_location} → {$load->delivery_location} sevkiyatı için ".str_repeat('★', $review->rating).str_repeat('☆', 5 - $review->rating).' ('.$review->rating.'/5) puan aldınız.'.($review->comment ? ' Yorum: '.$review->comment : ''),
                    'Değerlendirmeler profilinizde görünür ve gelecekteki tekliflerinizde güven puanınızı etkiler.'],
                route($isDriver ? 'driver.jobs.show' : 'cargo-owner.shipments.show', $load->id), 'Sevkiyatı görüntüle', 'review');
        }

        return $review;
    }

    /** Sevkiyat puanlanabilir mi: tamamlandı ve iade ile kapanmadı. Ekranlar ve servis aynı kuralı kullanır. */
    public static function canReview(Load $load): bool
    {
        return $load->status === Load::STATUS_COMPLETED && ! $load->isClosedWithRefund();
    }

    public function hasReviewed(Load $load, User $reviewer): bool
    {
        return Review::query()->where('load_id', $load->id)->where('reviewer_id', $reviewer->id)->exists();
    }
}
