<x-layouts.frontend title="Yeni Şifre Belirle | NavlunIQ" :noindex="true">
    <livewire:frontend.reset-password :token="$token" :email="request('email', '')" />
</x-layouts.frontend>
