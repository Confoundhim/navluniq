$ErrorActionPreference = 'Stop'
Set-Location (Split-Path $PSScriptRoot -Parent)

Write-Host '1/8 Composer bağımlılıkları'
composer install --no-interaction --prefer-dist

Write-Host '2/8 PHP ve Blade kaynak lint'
$phpFiles = Get-ChildItem app,bootstrap,config,database,routes,tests,resources\views -Recurse -File | Where-Object { $_.Extension -eq '.php' -or $_.Name -like '*.blade.php' }
foreach ($file in $phpFiles) {
    $result = & php -l $file.FullName 2>&1
    if ($LASTEXITCODE -ne 0) { throw "PHP lint başarısız: $($file.FullName)`n$result" }
}

Write-Host '3/8 Laravel önbellek temizliği'
php artisan optimize:clear

Write-Host '4/8 Migration durumu'
php artisan migrate:status

Write-Host '5/8 Route derleme'
php artisan route:list | Out-Null

Write-Host '6/8 PHPUnit'
php artisan test

Write-Host '7/8 Node bağımlılıkları'
npm ci

Write-Host '8/8 Üretim derlemesi'
npm run build
npm audit --omit=dev

Write-Host 'Tüm yerel doğrulamalar tamamlandı.' -ForegroundColor Green
