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
        if (! in_array($load->status, [Load::STATUS_DELIVERED, Load::STATUS_COMPLETED], true)) {
            throw new RuntimeException('Değerlendirme yalnız teslim edilmiş sevkiyatlar için yapılabilir.');
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

        return Review::create([
            'load_id' => $load->id,
            'reviewer_id' => $reviewer->id,
            'reviewee_id' => $revieweeId,
            'rating' => max(1, min(5, $rating)),
            'comment' => $comment ? mb_substr(trim($comment), 0, 1000) : null,
        ]);
    }

    public function hasReviewed(Load $load, User $reviewer): bool
    {
        return Review::query()->where('load_id', $load->id)->where('reviewer_id', $reviewer->id)->exists();
    }
}
