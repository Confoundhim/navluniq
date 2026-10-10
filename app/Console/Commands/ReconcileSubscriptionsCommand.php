<?php

namespace App\Console\Commands;

use App\Services\SubscriptionService;
use Illuminate\Console\Command;

/**
 * Ödenmiş ama dönemi yazılmamış premium abonelik emirlerini etkinleştirir (saatlik). Ödeme bildirimi "ödendi" yazdıktan sonra
 * etkinleştirme adımı çökmüşse (bildirim e-postası, veritabanı kesintisi) şoför parasını ödemiş ama premium olmamış kalır; bu
 * görev o emirleri yakalar (H2). Tekrar çalıştırmak güvenli: dönemi yazılmış emir ikinci kez etkinleştirilmez.
 */
class ReconcileSubscriptionsCommand extends Command
{
    protected $signature = 'subscriptions:reconcile';

    protected $description = 'Ödenmiş ama etkinleşmemiş premium abonelik emirlerini etkinleştirir';

    public function handle(SubscriptionService $subscriptions): int
    {
        $this->info('Mutabakatla etkinleştirilen abonelik emri: '.$subscriptions->reconcilePaidOrders());

        return self::SUCCESS;
    }
}
