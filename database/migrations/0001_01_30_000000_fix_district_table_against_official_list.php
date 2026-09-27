<?php

use App\Support\TurkishLocations;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * İlçe tablosu resmî listeyle (81 il, 973 ilçe) karşılaştırılıp düzeltildi: yanlış ile bağlanmış (Ağaçören Ankara'da,
 * Tuzla Kocaeli'de…), İngilizce adlı (Imbros, Prince Islands) ve yanlış yazılmış (Ulukisla, Karakeçeli) ilçeler.
 * Kayıtlı ilanlardaki ilçe adı, il kodu ve "İl İlçe" etiketi aynı şekilde düzeltilir. Tekrar çalıştırmak güvenlidir.
 */
return new class extends Migration
{
    /** [eski ilçe adı, eski il kodu, yeni ilçe adı, yeni il kodu] */
    private const CHANGES = [
        ['Yumurtalık', 31, 'Yumurtalık', 1], // Hatay → Adana
        ['Çamlıdere', 14, 'Çamlıdere', 6], // Bolu → Ankara
        ['İbradi', 7, 'İbradı', 7], // Antalya → Antalya
        ['Imbros', 17, 'Gökçeada', 17], // Çanakkale → Çanakkale
        ['Merkez', 20, 'Merkezefendi', 20], // Denizli → Denizli
        ['Alacakaya', 21, 'Alacakaya', 23], // Diyarbakır → Elazığ
        ['Ilıç', 24, 'İliç', 24], // Erzincan → Erzincan
        ['Giresun District', 28, 'Merkez', 28], // Giresun → Giresun
        ['Şarkîkaraağaç', 32, 'Şarkikaraağaç', 32], // Isparta → Isparta
        ['Prince Islands', 34, 'Adalar', 34], // İstanbul → İstanbul
        ['Tuzla', 41, 'Tuzla', 34], // Kocaeli → İstanbul
        ['Emirgazi', 68, 'Emirgazi', 42], // Aksaray → Konya
        ['Güneysınır', 70, 'Güneysınır', 42], // Karaman → Konya
        ['Derinkuyu', 51, 'Derinkuyu', 50], // Niğde → Nevşehir
        ['Ulukisla', 51, 'Ulukışla', 51], // Niğde → Niğde
        ['Sapanca', 41, 'Sapanca', 54], // Kocaeli → Sakarya
        ['Yakakent', 57, 'Yakakent', 55], // Sinop → Samsun
        ['Pervari', 73, 'Pervari', 56], // Şırnak → Siirt
        ['Bahçesaray', 13, 'Bahçesaray', 65], // Bitlis → Van
        ['Ağaçören', 6, 'Ağaçören', 68], // Ankara → Aksaray
        ['Sarıyahşi', 6, 'Sarıyahşi', 68], // Ankara → Aksaray
        ['Bahşılı', 71, 'Bahşili', 71], // Kırıkkale → Kırıkkale
        ['Karakeçeli', 71, 'Karakeçili', 71], // Kırıkkale → Kırıkkale
        ['Yahşihan', 6, 'Yahşihan', 71], // Ankara → Kırıkkale
        ['İdil', 47, 'İdil', 73], // Mardin → Şırnak
        ['Altınova', 41, 'Altınova', 77], // Kocaeli → Yalova
    ];

    public function up(): void
    {
        foreach (['scraped_loads', 'loads'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (['pickup', 'delivery'] as $side) {
                if (! Schema::hasColumn($table, "{$side}_district") || ! Schema::hasColumn($table, "{$side}_province_code")) {
                    continue;
                }
                foreach (self::CHANGES as [$oldName, $oldCode, $newName, $newCode]) {
                    $oldProvince = TurkishLocations::province($oldCode)['name'] ?? null;
                    $newProvince = TurkishLocations::province($newCode)['name'] ?? null;
                    $q = DB::table($table)->where("{$side}_district", $oldName)->where("{$side}_province_code", $oldCode);
                    $update = ["{$side}_district" => $newName, "{$side}_province_code" => $newCode];
                    if ($oldProvince && $newProvince && Schema::hasColumn($table, "{$side}_location")) {
                        $update["{$side}_location"] = DB::raw("REPLACE({$side}_location, '{$oldProvince} {$oldName}', '{$newProvince} {$newName}')");
                    }
                    $q->update($update);
                }
            }
        }
    }

    public function down(): void {}
};
