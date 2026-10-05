<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Tarayıcının CSP ihlal raporu (I14): application/csp-report gövdesi JSON'dur, CSRF yoktur, adlı sınırlayıcı (csp-report)
 * ile 30/dk. Aynı ihlal (sayfa + kural + engellenen adres) saatte bir kez günlüğe yazılır; gövde saklanmaz.
 */
class CspReportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $raw = (string) $request->getContent();
        $data = json_decode(mb_substr($raw, 0, 8000), true);
        // Eski biçim {"csp-report": {...}} ya da Reporting API [{"body": {...}}]
        $report = is_array($data) ? ($data['csp-report'] ?? ($data[0]['body'] ?? $data)) : [];
        if (! is_array($report) || $report === []) {
            return response('', 204);
        }

        $document = mb_substr((string) ($report['document-uri'] ?? $report['documentURL'] ?? ''), 0, 200);
        $directive = mb_substr((string) ($report['violated-directive'] ?? $report['effectiveDirective'] ?? ''), 0, 100);
        $blocked = mb_substr((string) ($report['blocked-uri'] ?? $report['blockedURL'] ?? ''), 0, 200);
        if ($directive === '') {
            return response('', 204);
        }

        $key = 'csp:report:'.sha1($document.'|'.$directive.'|'.$blocked);
        if (Cache::add($key, 1, now()->addHour())) {
            Log::notice('CSP ihlali bildirildi.', [
                'document' => $document,
                'directive' => $directive,
                'blocked' => $blocked,
                'source' => mb_substr((string) ($report['source-file'] ?? $report['sourceFile'] ?? ''), 0, 200),
                'line' => $report['line-number'] ?? $report['lineNumber'] ?? null,
                'enforced' => (($report['disposition'] ?? '') === 'enforce'),
            ]);
        }

        return response('', 204);
    }
}
