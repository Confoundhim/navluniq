<?php

namespace Database\Seeders;

use App\Models\Faq;
use App\Support\Settings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Sıkça sorulan sorular. Metinler platformun gerçek işleyişini anlatır;
 * oran ve süreler yönetim panelindeki ayarlardan okunur.
 */
class FaqSeeder extends Seeder
{
    public function run(): void
    {
        $driverRate = self::percent(Settings::float('commission_standard_driver'));
        $ownerRate = Settings::float('commission_cargo_owner');
        $autoApprovalHours = max(1, Settings::int('delivery_auto_approval_hours'));
        $offerDays = max(1, Settings::int('offer_validity_days'));
        $premiumPrice = number_format(Settings::float('premium_monthly_price'), 0, ',', '.');

        $ownerFeeText = $ownerRate > 0
            ? 'Yük sahiplerine navlun bedeli üzerinden %'.self::percent($ownerRate).' hizmet bedeli yansıtılır.'
            : 'Yük sahiplerinden ilan yayınlama veya ödeme için ayrıca hizmet bedeli alınmaz.';

        $faqs = [
            [
                'order_num' => 1,
                'question' => 'Teslimat onaylı ödeme nedir, şoför ödemesini ne zaman alır?',
                'answer' => 'Yük sahibi teklifi kabul ettikten sonra navlun bedelini lisanslı ödeme kuruluşu iyzico üzerinden kredi kartı ya da banka kartıyla öder; NavlunIQ taraflar adına para tutmaz. Şoför yükü teslim edip teslim kanıtını (POD) yüklediğinde yük sahibi teslimatı onaylar. Yük sahibi '.$autoApprovalHours.' saat içinde onay vermez ya da itiraz etmezse sistem teslimatı otomatik onaylar. Onayın ardından şoförün ödemesi, platform hizmet bedeli düşülerek kayıtlı IBAN\'ına yapılır; durumu şoför panelindeki Ödemelerim ekranından izlenir.',
            ],
            [
                'order_num' => 2,
                'question' => 'Evrak onayı nasıl yapılır ve hangi belgeler zorunludur?',
                'answer' => 'Şoförler için sürücü belgesi, SRC belgesi, psikoteknik raporu, kimlikle çekilmiş fotoğraf ve araç ruhsatı zorunludur; K yetki belgesi ve taşıyıcı sorumluluk sigortası varsa eklenebilir. Yük sahipleri için kimlik kartı, kurumsal hesaplarda ayrıca vergi levhası istenir. Belgeler NavlunIQ evrak ekibi tarafından tek tek incelenir; otomatik onay yoktur. İnceleme genellikle bir iş günü içinde tamamlanır, sonuç panelinizde ve e-postanızda görünür. Evrakları onaylanmamış şoförler teklif veremez; yük sahipleri ilan yayınlayabilir ancak onaylı hesaplar şoförlere güven verir.',
            ],
            [
                'order_num' => 3,
                'question' => 'Canlı konum paylaşımı nasıl çalışır, telefon şarjımı hızlı bitirir mi?',
                'answer' => 'Konum paylaşımı yalnız aktif bir sevkiyat sırasında, şoför sevkiyat ekranından "Konum paylaş" seçeneğini açtığında çalışır. Tarayıcının konum izni istenir; paylaşım sekme açıkken belirli aralıklarla konum gönderir ve sevkiyat tamamlandığında ya da şoför kapattığında durur. Sürekli arka plan takibi yapılmaz, bu nedenle pil tüketimi normal navigasyon kullanımının altındadır. Yük sahibi konumu yalnız kendi sevkiyatı için, panelindeki harita üzerinden görür.',
            ],
            [
                'order_num' => 4,
                'question' => 'Premium şoför üyeliği bana ne kazandırır?',
                'answer' => 'Kısaca: yeni ilanları herkesten 20 dakika önce görürsünüz ve anında bildirim alırsınız. Yük sahiplerinin açtığı sistem ilanları ve onaylı dış kaynak ilanları önce premium üyelere açılır; standart üyelere ve Telegram kanalına 20 dakika sonra düşer. Dış kaynak ilanlarında ilan sahibinin telefon numarasının tamamı da görünür. Aylık ücret '.$premiumPrice.' ₺\'dir (KDV dahil), sevkiyat başına ek ücret yoktur ve platform hizmet bedeli premium ile değişmez.',
            ],
            [
                'order_num' => 5,
                'question' => 'Ücretsiz şoför hesabı ile premium arasındaki fark nedir?',
                'answer' => 'Tek fark zamandır. Ücretsiz hesap tüm ilanları görür ve sınırsız teklif verir; bu hak her zaman ücretsizdir. Ancak yeni ilanlar (sistem ilanları ve dış kaynak ilanlar) ücretsiz hesaba premium üyelerden 20 dakika sonra açılır ve dış kaynak ilanlarında numara kısmen gizlenir. Platform hizmet bedeli (%'.$driverRate.') iki hesapta da aynıdır. Uygulamayı sürekli açmak istemeyenler sistem ilanlarını herkese açıldığı anda Telegram kanalımızdan da takip edebilir.',
            ],
            [
                'order_num' => 6,
                'question' => 'Dış kaynaklı ilanlar sisteme nasıl derlenir?',
                'answer' => 'Yalnız kullanım ve paylaşım izni alınmış kaynaklardan (web siteleri ve izinli gruplar) gelen ilan mesajları toplanır. Mesajlar önce otomatik olarak rota, yük cinsi, tonaj ve fiyat alanlarına ayrıştırılır, ardından operasyon ekibimiz her ilanı kontrol edip onaylar veya reddeder. Sadece onaylanan ilanlar şoför ilan listesine düşer ve "dış kaynak" etiketiyle ayrı gösterilir. Bu ilanlarda pazarlık ve ödeme ilan sahibiyle doğrudan yapılır; NavlunIQ\'nun teslimat onaylı ödeme sistemi yalnız platform içi ilanlarda geçerlidir.',
            ],
            [
                'order_num' => 7,
                'question' => 'NavlunIQ bir nakliye firması mıdır?',
                'answer' => 'Hayır. NavlunIQ bir nakliye firması veya kargo operatörü değildir; yük sahipleri ile onaylı şoförleri buluşturan, ödemeyi lisanslı ödeme kuruluşu üzerinden teslimat onayına bağlayan ve süreci kayıt altına alan bir aracı teknoloji platformudur. Taşıma sözleşmesi yük sahibi ile şoför arasında kurulur; tarafların sorumlulukları kullanıcı sözleşmesinde açıklanmıştır.',
            ],
            [
                'order_num' => 8,
                'question' => 'Uyuşmazlık merkezi nasıl çalışır?',
                'answer' => 'Teslimatta hasar, eksik ya da anlaşmazlık yaşanırsa yük sahibi teslimatı onaylamak yerine panelinden itiraz açar; o anda navlun ödemesi askıya alınır ve otomatik onay durur. Şoför kendi açıklamasını ve fotoğrafını ekler. NavlunIQ destek ekibi iki tarafın beyanlarını, yükleme ve teslim fotoğraflarını ve varsa konum kayıtlarını inceleyerek ödemenin şoföre yapılmasına ya da yük sahibine iadesine karar verir. Karar ve gerekçesi her iki tarafa panelden bildirilir. Bu süreç taraflara hukuki yollara başvurma hakkını kaybettirmez.',
            ],
            [
                'order_num' => 9,
                'question' => 'Aynı hesapla hem şoför hem yük sahibi olabilir miyim?',
                'answer' => 'Evet. Aynı e-posta ve telefon numarasıyla tek hesabınıza ikinci rolü ekleyebilirsiniz; kayıt ekranında mevcut şifrenizi girmeniz istenir. Panelinizin sol alt köşesindeki rol değiştirme alanından e-postanıza gönderilen tek kullanımlık doğrulama koduyla iki panel arasında geçiş yaparsınız. Her rolün evrak onayı ayrı ayrı yapılır.',
            ],
            [
                'order_num' => 10,
                'question' => 'Taşınan yükler sigorta kapsamında mıdır?',
                'answer' => 'NavlunIQ yükleri kendiliğinden sigortalamaz. Taşıma sırasındaki sorumluluk taşıma sözleşmesinin taraflarına aittir; şoförün taşıyıcı sorumluluk sigortası varsa evrak bölümünde görünür. Yüksek değerli yükler için yük sahibinin nakliyat sigortası yaptırmasını öneririz. Platform üzerinden bir sigorta seçeneği sunulduğunda kapsam, istisnalar ve ücret ödeme adımında ayrıca gösterilir.',
            ],
            [
                'order_num' => 11,
                'question' => 'Yüklediğim ehliyet, ruhsat ve vergi levhası gibi belgeler güvende mi?',
                'answer' => 'Evet. Belgeler internetten doğrudan erişilemeyen özel bir depolama alanında saklanır ve yalnız belge sahibi ile yetkili evrak ekibi oturum açarak görüntüleyebilir. IBAN bilgileri veritabanında şifreli tutulur, şifreler geri döndürülemez biçimde hashlenir. Belgeler evrak onayı ve yasal saklama yükümlülükleri dışında kullanılmaz; ayrıntılar KVKK aydınlatma metninde yer alır.',
            ],
            [
                'order_num' => 12,
                'question' => 'Komisyonlar ve faturalar nasıl işler?',
                'answer' => 'Gizli maliyet yoktur. '.$ownerFeeText.' Tamamlanan sevkiyatlarda şoförün navlun ödemesinden %'.$driverRate.' platform hizmet bedeli kesilir; bu oran premium üyelikle değişmez; kesinti tutarı teklif ekranında ve Ödemelerim sayfasında açıkça gösterilir. Premium abonelik ve hizmet bedelleri için KDV dahil fatura düzenlenir ve panelinizden görüntülenir. Oranlar değiştiğinde yeni oran yalnız değişiklikten sonra kabul edilen tekliflere uygulanır.',
            ],
            [
                'order_num' => 13,
                'question' => 'Yük sahibi olarak bir ilanı nasıl iptal edebilirim?',
                'answer' => 'İlan henüz teklif almadıysa ya da teklif kabul edilmiş ancak ödeme yapılmamışsa ilanı panelinizden tek adımda iptal edebilirsiniz; bekleyen teklifler otomatik olarak reddedilir. Navlun ödemesi alındıktan veya şoför yola çıktıktan sonra iptal yalnız destek ekibi üzerinden, tarafların mutabakatı ya da uyuşmazlık kararıyla yapılır; iade bu karara göre gerçekleşir.',
            ],
            [
                'order_num' => 14,
                'question' => 'Premium aboneliğimi iptal edebilir miyim, ücret iadesi var mı?',
                'answer' => 'Premium üyelik aylık dönemler halinde satın alınır ve otomatik yenilenmez; dönem sonunda uzatmazsanız hesabınız kendiliğinden standart üyeliğe döner, komisyon oranınız standart orana ayarlanır. Dijital hizmet satın alındığı anda kullanıma açıldığından dönem içinde ücret iadesi yapılmaz; dönem sonuna kadar tüm premium haklarınızı kullanmaya devam edersiniz.',
            ],
            [
                'order_num' => 15,
                'question' => 'Destek ekibine nasıl ulaşabilirim?',
                'answer' => 'Panelinizdeki "Uyuşmazlık ve Destek" bölümünden destek talebi açabilirsiniz; talepler kayıt altına alınır ve yanıtlar aynı ekranda görünür. Üye olmadan iletişim sayfasındaki formu kullanabilir, sitede yayınlanan e-posta adresine yazabilir veya tanımlıysa mesaj hattından yazabilirsiniz. Çalışma saatleri ve güncel iletişim bilgileri iletişim sayfasında yer alır.',
            ],
        ];

        DB::transaction(function () use ($faqs): void {
            foreach ($faqs as $faq) {
                Faq::updateOrCreate(
                    ['order_num' => $faq['order_num']],
                    [
                        'question' => $faq['question'],
                        'answer' => $faq['answer'],
                        'is_active' => true,
                    ]
                );
            }
        });
    }

    private static function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, ',', '.'), '0'), ',');
    }
}
