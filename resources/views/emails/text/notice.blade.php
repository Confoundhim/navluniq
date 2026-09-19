{{ $subjectLine }}

Merhaba{{ $recipientName ? ' '.$recipientName : '' }},

@foreach($lines as $line)
{{ $line }}

@endforeach
@if($actionUrl)
{{ $actionText ?? 'Panele git' }}: {{ $actionUrl }}

@endif
Saygılarımızla,
NavlunIQ Ekibi
{{ \App\Support\Company::get('phone') }} · {{ \App\Support\Company::get('email') }} · {{ rtrim((string) config('app.url'), '/') }}

{{ \App\Support\Company::get('name') }} · {{ \App\Support\Company::get('address') }}
Bu e-posta otomatik gönderilmiştir.
