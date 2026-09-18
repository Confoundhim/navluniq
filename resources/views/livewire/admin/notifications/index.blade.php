<?php

use App\Livewire\NotificationsPage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

new
#[Layout('components.layouts.admin')]
#[Title('Bildirimler')]
class extends NotificationsPage {}; ?>

@include('livewire.notifications.partials.list')
