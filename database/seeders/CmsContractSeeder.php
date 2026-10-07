<?php

namespace Database\Seeders;

use App\Models\CmsContent;
use Illuminate\Database\Seeder;

class CmsContractSeeder extends Seeder
{
    public const KEYS = ['contract_kvkk', 'contract_terms', 'contract_privacy', 'contract_distance_sale', 'contract_cancellation'];

    /** Seed izi: "sha1(ham şablon)|sha1(yazılan metin)"; legal:refresh --if-stale yönetici düzenlemesini bununla ayırır. */
    public const SEED_MARK_PREFIX = 'legal_seed_';

    /**
     * Beş yasal metni yükler. Şirket künyesi yer tutucu olarak kalır ve gösterimde panelden doldurulur.
     * Metinler hukuk danışmanı onayından geçirilmelidir; bu seed yalnızca başlangıç içeriğidir.
     */
    public function run(): void
    {
        foreach (self::KEYS as $key) {
            self::seedKey($key);
        }
    }

    /** Tek metni koddaki şablonla yazar ve seed izini kaydeder. */
    public static function seedKey(string $key): void
    {
        $raw = self::templates()[$key] ?? null;
        if ($raw === null) {
            return;
        }
        $value = strtr($raw, self::dateTokens());
        CmsContent::updateOrCreate(['key' => $key], ['value' => $value]);
        CmsContent::updateOrCreate(['key' => self::SEED_MARK_PREFIX.$key], ['value' => self::templateHash($key).'|'.sha1($value)]);
    }

    /** Ham şablonun özeti (tarih yer tutucusu doldurulmadan); şablon değişince değişir. */
    public static function templateHash(string $key): string
    {
        return sha1((string) (self::templates()[$key] ?? ''));
    }

    /** @return array<string,string> */
    private static function dateTokens(): array
    {
        return [
            '{{LEGAL_DATE}}' => (string) (config('company.legal_effective_date') ?: now()->translatedFormat('d F Y')),
        ];
    }

    /**
     * Beş metnin ham şablonu. Şirket künyesi yer tutucuları ({{COMPANY_*}}) ve ayardan okunan süreler ({{AUTO_APPROVAL_HOURS}},
     * {{OFFER_PAYMENT_HOURS}}, {{PREMIUM_LEAD_MINUTES}}, {{EXTERNAL_LIST_DAYS}}) metinde saklanır; sayfada gösterilirken
     * App\Support\Company::fillTokens() güncel değerlerle doldurur.
     *
     * @return array<string,string>
     */
    public static function templates(): array
    {
        // 1. KVKK AYDINLATMA METNİ
        $kvkk = <<<'HTML'
<div class="space-y-6 text-neutral-800 dark:text-neutral-200 leading-relaxed">
    <div class="border-b border-neutral-200 dark:border-neutral-800 pb-4">
        <span class="text-[11px] font-bold uppercase tracking-wider text-neutral-500 dark:text-neutral-400 block mb-1">Yasal Mevzuat ve KVKK Uyumu</span>
        <h2 class="text-xl sm:text-2xl font-bold text-neutral-900 dark:text-white">6698 Sayılı Kişisel Verilerin Korunması Kanunu Aydınlatma Metni</h2>
        <span class="text-xs text-neutral-400">Son Güncelleme: {{LEGAL_DATE}}</span>
    </div>

    <p>
        <strong>{{COMPANY_NAME}}</strong> (“NavlunIQ” veya “Şirket”) olarak, 6698 sayılı Kişisel Verilerin Korunması Kanunu (“KVKK”) ve ilgili mevzuat uyarınca, <strong>"Veri Sorumlusu"</strong> sıfatıyla, kişisel verilerinizin toplanması, işlenmesi, saklanması, aktarılması ve imha edilmesi süreçleri hakkında sizi bilgilendiriyoruz. Bu metin <strong>navluniq.com</strong> web sitesi üzerinden sunulan tüm hizmetler için geçerlidir; açık rıza gerektiren işlemler için rıza, bu aydınlatmadan ayrı ve açıkça alınır.
    </p>

    <!-- Madde 1: Veri Sorumlusunun Kimliği -->
    <div class="p-4 bg-neutral-50 dark:bg-neutral-900/60 rounded-xl border border-neutral-200 dark:border-neutral-800 space-y-2">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 1: Veri Sorumlusunun Kimliği</h3>
        <p class="text-xs text-neutral-600 dark:text-neutral-400">KVKK kapsamında muhatabınız olan Veri Sorumlusu resmi bilgileri aşağıdadır:</p>
        <ul class="list-disc pl-5 space-y-1 text-xs">
            <li><strong>Şirket Unvanı:</strong> {{COMPANY_NAME}}</li>
            <li><strong>Vergi Dairesi ve No:</strong> {{COMPANY_TAX_OFFICE}} / {{COMPANY_TAX_NO}}</li>
            <li><strong>Adres:</strong> {{COMPANY_ADDRESS}}</li>
            <li><strong>E-Posta:</strong> {{COMPANY_EMAIL}}</li>
            <li><strong>Telefon:</strong> {{COMPANY_PHONE}}</li>
        </ul>
    </div>

    <!-- Madde 2: İşlenen Kişisel Veri Kategorileri ve Veri Türleri -->
    <div class="space-y-3">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 2: İşlenen Kişisel Veri Kategorileri ve Veri Türleri</h3>
        <p>Yük ilanı ve teklif eşleştirmesi, belge ve kimlik doğrulaması, güvenli ödeme ve sevkiyat takibi kapsamında aşağıdaki kişisel verileriniz, ilgili hizmetin gerektirdiği ölçüde işlenmektedir:</p>
        <ul class="list-disc pl-5 space-y-2">
            <li><strong>Kimlik Bilgileri:</strong> Ad, soyad; bireysel yük sahiplerinde kimlik doğrulaması için T.C. kimlik numarası ve doğum yılı; kurumsal yük sahiplerinde vergi kimlik numarası ve şirket unvanı; şoförlerde ödeme kuruluşu nezdinde alt üye iş yeri kaydı için T.C. kimlik numarası ya da vergi kimlik numarası ile yüklenen belgelerde yer alan kimlik bilgileri.</li>
            <li><strong>İletişim Bilgileri:</strong> Cep telefonu numarası, e-posta adresi, kurumsal adres, ilanda belirtilen yükleme ve teslimat adresleri.</li>
            <li><strong>Mesleki Belgeler (KYC) Verileri:</strong> Şoförler için sürücü belgesi, SRC belgesi, psikoteknik raporu, kimlikle çekilmiş fotoğraf ve araç ruhsatı (zorunlu); K yetki belgesi ve taşıyıcı sorumluluk sigortası poliçesi (isteğe bağlı); araç plakası ve araç fotoğrafı. Kurumsal yük sahipleri isterse vergi levhası ve imza sirküleri yükleyebilir; yük sahiplerinden belge fotoğrafı zorunlu tutulmaz. Belgeler yetkili personel tarafından incelenir; belgelerden otomatik veri çıkarma yapılmaz.</li>
            <li><strong>Fotoğraf Verileri:</strong> Kimlikle çekilmiş fotoğraf ve profil fotoğrafı yalnız belgenin hesap sahibine ait olduğunun yetkili personelce görsel kontrolü için saklanır. Yüz tanıma, biyometrik şablon çıkarma ya da başka bir biyometrik işleme yapılmaz; bu fotoğraflar KVKK m. 6 anlamında özel nitelikli (biyometrik) veri olarak işlenmez.</li>
            <li><strong>Finansal Veriler:</strong> Şoförlerin hakediş için bildirdiği IBAN ve hesap sahibi bilgisi, ödeme emirleri, hakediş ve iade kayıtları, fatura kayıtları, ödeme kuruluşu işlem referansları. Kart bilgileri NavlunIQ tarafından görülmez ve saklanmaz; ödeme kuruluşunun güvenli ödeme sayfasında işlenir.</li>
            <li><strong>Coğrafi Konum Bilgileri:</strong> Şoförün yalnız “yolda” durumundaki bir sevkiyat sırasında, tarayıcısında konum iznini vermesi halinde toplanan enlem, boylam, hız, yön ve doğruluk değerleri.</li>
            <li><strong>İşlem Güvenliği Verileri:</strong> IP adresi, giriş ve işlem kayıtları, e-posta ile gönderilen tek kullanımlık doğrulama kodu kayıtları, tarayıcı bilgisi; sözleşme onaylarının sürümü, zamanı, IP adresi ve tarayıcı bilgisi.</li>
            <li><strong>Sevkiyat, Değerlendirme ve Uyuşmazlık Verileri:</strong> Teklifler, sevkiyat durumları, teslim kanıtı fotoğrafı, taraflarca verilen puan ve yorumlar, uyuşmazlık beyanları ve ekleri, destek talepleri.</li>
            <li><strong>Dış Kaynak İlan Verileri:</strong> Herkese açık taşımacılık gruplarında paylaşılan yük ilanlarının metni ve ilan sahibinin iletişim numarası (ayrıntı Madde 4).</li>
        </ul>
    </div>

    <!-- Madde 3: Kişisel Verilerin İşlenme Amaçları -->
    <div class="space-y-3">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 3: Kişisel Verilerin İşlenme Amaçları</h3>
        <p>Kişisel verileriniz, KVKK'nın 5. maddesinde belirtilen kişisel veri işleme şartları dahilinde aşağıdaki amaçlar doğrultusunda işlenmektedir:</p>
        <ul class="list-disc pl-5 space-y-1.5">
            <li>Yük ilanlarının yayınlanması, tekliflerin alınması, yük sahipleri ile şoförlerin eşleştirilmesi ve sevkiyatın takibi,</li>
            <li>Şoförlerin yasal taşıma belgelerinin yetkili personel tarafından incelenmesi ve onaylanması; bireysel yük sahiplerinin T.C. kimlik numarası, ad soyad ve doğum yılı ile Nüfus ve Vatandaşlık İşleri Genel Müdürlüğü (NVİ) kimlik doğrulama servisinden teyidi; kurumsal yük sahiplerinin vergi kimlik numarasının sağlanması ve yetkili personelce teyidi (bu doğrulama teklif kabulü ve ödeme öncesinde tamamlanır),</li>
            <li>Taşıma esnasında yükün güvenliğinin sağlanması amacıyla şoförün izin verdiği konumun yalnız o sevkiyat süresince ilgili yük sahibine gösterilmesi,</li>
            <li data-clause="bildirim-tercihi">Premium sürücülere aracına uygun yeni ilan yayınlandığında uygulama içi bildirim ve e-posta gönderilmesi (yeni ilan e-postaları şoför panelinden her zaman kapatılıp açılabilir; işlemsel bildirimler hizmetin gereğidir),</li>
            <li><strong>Lisanslı ödeme kuruluşu ve banka altyapıları</strong> üzerinden navlun tahsilatı, teslimat onayına kadar bekletme, hakediş aktarımı, iade ve mutabakat süreçlerinin yürütülmesi; şoförün ödeme kuruluşunda alt üye iş yeri olarak kaydı,</li>
            <li>Aracılık komisyonu ve premium abonelik bedellerinin faturalandırılması ve muhasebeleştirilmesi,</li>
            <li>Hesap güvenliğinin sağlanması, dolandırıcılık ve kötüye kullanımın önlenmesi, hesapların askıya alınması ya da sınırlandırılması,</li>
            <li>Müşteri ilişkileri süreçlerinin yürütülmesi, destek taleplerinin alınması ve uyuşmazlıkların çözümlenmesi,</li>
            <li>Ayrıca onay verilmişse kampanya ve duyuru içerikli ticari elektronik ileti gönderilmesi,</li>
            <li>Herkese açık taşımacılık gruplarında paylaşılan yük ilanlarının standart ilan kartına dönüştürülerek premium şoförlere sunulması.</li>
        </ul>
    </div>

    <!-- Madde 4: Kişisel Veri Toplamanın Yöntemi ve Hukuki Sebebi -->
    <div class="space-y-3">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 4: Kişisel Veri Toplamanın Yöntemi ve Hukuki Sebebi</h3>
        <p>Kişisel verileriniz, <strong>navluniq.com</strong> web sitesindeki kayıt, profil, ilan, teklif ve belge yükleme formları, tarayıcı konum izni, ödeme kuruluşu ve NVİ doğrulama servisi gibi yetkilendirilmiş entegrasyonlar ve herkese açık taşımacılık grupları vasıtasıyla elektronik ortamda toplanmaktadır. Kişisel verilerinizin işlenmesindeki hukuki sebeplerimiz şunlardır:</p>
        <ul class="list-disc pl-5 space-y-1.5">
            <li><strong>Sözleşmenin Kurulması ve İfası (KVKK m. 5/2-c):</strong> Üyelik işlemlerinin tamamlanması, ilan ve teklif süreçleri, belge ve kimlik doğrulaması, sevkiyatın koordinasyonu, konumun yük sahibine gösterilmesi, ödeme ve hakediş süreçlerinin yönetilmesi.</li>
            <li><strong>Veri Sorumlusunun Hukuki Yükümlülüğü (KVKK m. 5/2-ç):</strong> Faturalandırma ve vergi mevzuatından doğan kayıt tutma, ödeme kuruluşu mevzuatının aradığı alt üye iş yeri kimlik bilgileri, 5651 sayılı Kanun kapsamındaki kayıtlar, resmi makamların taleplerine uyum.</li>
            <li><strong>Bir Hakkın Tesisi, Kullanılması veya Korunması (KVKK m. 5/2-e):</strong> Uyuşmazlık kayıtları, teslim kanıtları, sözleşme onay kayıtları ve işlem günlüklerinin saklanması.</li>
            <li><strong>Meşru Menfaat (KVKK m. 5/2-f):</strong> Hesap güvenliği, dolandırıcılık ve kötüye kullanımın önlenmesi, hizmet kalitesinin ölçülmesi.</li>
            <li><strong>Açık Rıza (KVKK m. 5/1):</strong> Kampanya ve duyuru içerikli ticari elektronik iletiler yalnız ayrıca verilen onayla gönderilir; onay üyelik için zorunlu değildir ve her zaman geri alınabilir. Konum paylaşımı şoförün cihazında konum iznini vermesiyle başlar, izin kaldırılınca durur.</li>
            <li data-clause="dis-kaynak"><strong>Meşru Menfaat (KVKK m. 5/2-f) – Dış Kaynak İlanları:</strong> Herkese açık ya da yöneticisinin paylaşıma izin verdiği taşımacılık gruplarında alenen paylaşılan yük ilanları, ilan sahibiyle iletişim kurulabilmesi amacıyla derlenir; yalnız ilan metni (güzergâh, yük, araç, fiyat) ve ilan sahibinin iletişim numarası standart ilan kartına dönüştürülerek işlenir, gönderen adı saklanmaz. İletişim numarası şifreli saklanır, yönetici kayıtlarında maskelenir ve yalnız belge doğrulaması tamamlanmış premium sürücülere gösterilir; bu sürücüler numarayı yalnız o ilan için ilan sahibiyle iletişim amacıyla kullanır, üçüncü kişilerle paylaşamaz (Kullanıcı Sözleşmesi md. 3.4). İlanlar yayından <strong>{{EXTERNAL_LIST_DAYS}} gün</strong> sonra listeden kaldırılır ve arşivlenir. İlan sahibi, <strong>{{COMPANY_EMAIL}}</strong> adresine veya iletişim formuna yazarak ilanının derhal kaldırılmasını isteyebilir; talep en geç 48 saat içinde yerine getirilir.</li>
        </ul>
    </div>

    <!-- Madde 5: Verilerin Saklanması ve Alınan Siber Tedbirler -->
    <div class="space-y-3">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 5: Verilerin Saklanma Süreleri ve Alınan Güvenlik Tedbirleri</h3>
        <p>
            Kişisel verileriniz, işleme amacının gerektirdiği süre ve yasal saklama süreleri boyunca muhafaza edilir: hesap ve profil verileri üyelik süresince ve hesabın silinmesinden sonra yasal zamanaşımı süreleri boyunca; fatura, ödeme ve muhasebe kayıtları 6102 sayılı Türk Ticaret Kanunu ve 213 sayılı Vergi Usul Kanunu uyarınca <strong>10 yıl</strong>; konum kayıtları <strong>90 gün</strong> (sonra kendiliğinden silinir); erişim ve işlem kayıtları 5651 sayılı Kanun ve ilgili mevzuatın öngördüğü süre; dış kaynak ilanları listeden kaldırıldıktan sonra arşivde, ilan sahibinin talebi halinde derhal silinir. Hesabınızı sildiğinizde belgeleriniz, konum kayıtlarınız, kimlik ve vergi numaranız ile araç plakanız silinir; yalnız yasal saklama yükümlülüğü bulunan kayıtlar süresi boyunca korunur.
        </p>
        <p>
            Belge görselleriniz (sürücü belgesi, SRC vb.) herkese açık erişime kapalı <strong>kyc_private</strong> depolama alanında yetki kontrolleriyle tutulur ve yalnız yetkili personel görür. Parolalar tek yönlü özetleme (<strong>Bcrypt</strong>) ile saklanır; teslim kanıtı dosyalarının bütünlüğü <strong>SHA-256</strong> özetiyle kayıt altına alınır; dış kaynak ilanlarındaki iletişim numaraları şifreli saklanır; tüm trafik HTTPS ile şifrelenir. Parolanız değiştiğinde diğer cihazlardaki oturumlar kapatılır; e-posta, telefon ve IBAN değişiklikleri parola doğrulaması ister.
        </p>
    </div>

    <!-- Madde 6: İşlenen Kişisel Verilerin Yurt İçinde Aktarılması -->
    <div class="space-y-3">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 6: İşlenen Kişisel Verilerin Yurt İçinde Aktarılması</h3>
        <p>
            Kişisel verileriniz, hukuki dayanak ve gerekli bilgilendirme olmaksızın üçüncü kişilerin bağımsız reklam veya pazarlama amaçları için aktarılmaz. Veriler, Kanun’un 8. maddesindeki şartlara uygun olarak ve hizmetin gerektirdiği ölçüde şu alıcı gruplarıyla paylaşılır: navlun tahsilatı, teslimat onayına kadar bekletme, hakediş ve iade işlemleri için <strong>sözleşmeli lisanslı ödeme kuruluşu ve bankalar</strong> (şoförün alt üye iş yeri kaydı için ad soyad, kimlik ya da vergi numarası ve IBAN dahil); bireysel yük sahibi kimlik doğrulaması için <strong>NVİ kimlik doğrulama servisi</strong> (T.C. kimlik numarası, ad soyad, doğum yılı); sevkiyat sürecinde karşı taraf (ödeme alındıktan sonra yük sahibi ile şoförün ad ve telefon numarası birbirine gösterilir; şoförün konumu yalnız yoldaki sevkiyatta yük sahibine gösterilir); usulüne uygun bilgi talep eden <strong>adli ve idari merciler</strong>. Herkese açılan sistem ilanları, duyuru kanalında yalnız güzergâh, yük, araç, fiyat ve yükleme tarihi bilgileriyle paylaşılır; ilan sahibinin adı ve telefonu kanalda yer almaz.
        </p>
    </div>

    <!-- Madde 7: Kişisel Verilerin Yurt Dışına Aktarılması -->
    <div class="space-y-3" data-clause="yurt-disi-aktarim">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 7: Kişisel Verilerin Yurt Dışına Aktarılması (KVKK m. 9)</h3>
        <p>
            Üyelerimizin kimlik, belge, finansal ve konum verileri yurt dışına aktarılmaz. Yurt dışına aktarım yalnız aşağıdaki sınırlı hallerde ve Kanun’un 9. maddesindeki şartlara uygun olarak yapılır:
        </p>
        <ul class="list-disc pl-5 space-y-1.5">
            <li><strong>Dış kaynak ilan metinlerinin yapay zeka ile ayrıştırılması:</strong> Herkese açık taşımacılık gruplarında paylaşılan ilan metinleri (güzergâh, yük, araç, fiyat ve ilan sahibinin iletişim numarası dahil), standart ilan kartına dönüştürülmek üzere sunucuları yurt dışında bulunan <strong>büyük dil modeli (yapay zeka) hizmet sağlayıcılarına</strong> gönderilebilir. Gönderen adı ve üyelerimize ait hiçbir veri bu metinlerle paylaşılmaz; gönderim yalnız metnin yapılandırılması ve doğruluğunun denetlenmesi amacıyla yapılır. Aktarım, ilan sahibiyle iletişim kurulmasına yönelik meşru menfaate (m. 5/2-f) dayanır ve Kanun’un 9. maddesi kapsamındaki uygun güvence şartlarına tabidir.</li>
            <li><strong>E-posta ve duyuru altyapısı:</strong> İşlemsel e-postalar ve herkese açılan sistem ilanlarının duyuru kanalı mesajları, sunucuları yurt dışında bulunan iletişim altyapısı sağlayıcıları üzerinden iletilebilir; e-postada yalnız alıcının adı ve e-posta adresi, duyuru kanalında yalnız ilanın güzergâh, yük, araç, fiyat ve tarih bilgileri yer alır.</li>
        </ul>
    </div>

    <!-- Madde 8: Veri Sahibi Olarak Haklarınız -->
    <div class="p-4 bg-neutral-50 dark:bg-neutral-900/60 rounded-xl border border-neutral-200 dark:border-neutral-800 space-y-2">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 8: Veri Sahibi Olarak Haklarınız (KVKK Madde 11)</h3>
        <p>
            Kanun'un 11. maddesi uyarınca; kişisel verilerinizin işlenip işlenmediğini öğrenme, işlenmişse buna ilişkin bilgi talep etme, işlenme amacını ve amacına uygun kullanılıp kullanılmadığını öğrenme, yurt içinde veya yurt dışında aktarıldığı üçüncü kişileri bilme, eksik veya yanlış işlenmişse düzeltilmesini isteme, Kanun’un 7. maddesindeki şartlar çerçevesinde silinmesini veya yok edilmesini isteme, düzeltme ve silme işlemlerinin aktarıldığı üçüncü kişilere bildirilmesini isteme, işlenen verilerin münhasıran otomatik sistemler vasıtasıyla analiz edilmesi suretiyle aleyhinize bir sonucun ortaya çıkmasına itiraz etme ve kanuna aykırı işleme nedeniyle zarara uğramanız halinde zararın giderilmesini talep etme haklarına sahipsiniz.
        </p>
        <p>
            Başvurularınızı Veri Sorumlusuna Başvuru Usul ve Esasları Hakkında Tebliğ’e uygun olarak <strong>{{COMPANY_EMAIL}}</strong> adresine ya da <strong>{{COMPANY_ADDRESS}}</strong> adresine yazılı olarak iletebilirsiniz. Başvurular kimlik doğrulamasının ardından en geç <strong>30 gün</strong> içinde ücretsiz olarak sonuçlandırılır; işlem ayrıca bir maliyet gerektirirse Kurul’un belirlediği tarifedeki ücret istenebilir. Panelinizdeki <strong>Hesabım</strong> bölümünden verilerinizin bir kopyasını indirebilir ve hesabınızı silebilirsiniz; yasal saklama zorunluluğu bulunan kayıtlar bu süre boyunca korunur. Başvurunuzun reddedilmesi, cevabın yetersiz bulunması ya da süresinde cevap verilmemesi halinde Kişisel Verileri Koruma Kurulu’na şikâyet hakkınız saklıdır.
        </p>
    </div>
</div>
HTML;

        // 2. KULLANICI SÖZLEŞMESİ
        $terms = <<<'HTML'
<div class="space-y-6 text-neutral-800 dark:text-neutral-200 leading-relaxed">
    <div class="border-b border-neutral-200 dark:border-neutral-800 pb-4">
        <span class="text-[11px] font-bold uppercase tracking-wider text-neutral-500 dark:text-neutral-400 block mb-1">Yasal Mevzuat ve Taahhüt</span>
        <h2 class="text-xl sm:text-2xl font-bold text-neutral-900 dark:text-white">NavlunIQ Kullanıcı Sözleşmesi</h2>
        <span class="text-xs text-neutral-400">Son Güncelleme: {{LEGAL_DATE}}</span>
    </div>

    <p>
        Lütfen bu sözleşmeyi onaylamadan önce tüm içeriği dikkatlice okuyun. Sözleşme, güncel metin ve sürüm kullanıcıya gösterildikten sonra onay işleminin (sürüm, zaman, IP adresi ve tarayıcı bilgisiyle) sistem kayıtlarına alınmasıyla yürürlüğe girer; yalnızca platformu ziyaret etmek sözleşmenin kabul edildiği anlamına gelmez.
    </p>

    <!-- Madde 1: Taraflar ve Tanımlar -->
    <div class="p-4 bg-neutral-50 dark:bg-neutral-900/60 rounded-xl border border-neutral-200 dark:border-neutral-800 space-y-2">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 1: Taraflar ve Tanımlar</h3>
        <ul class="space-y-1.5 text-xs">
            <li><strong>1.1 Hizmet Sağlayıcı:</strong> {{COMPANY_ADDRESS}} adresinde mukim <strong>{{COMPANY_NAME}}</strong> (“NavlunIQ”, Ticaret Sicil No {{COMPANY_TRADE_REGISTRY_NO}}, MERSİS {{COMPANY_MERSIS_NO}}).</li>
            <li><strong>1.2 Sürücü (Şoför):</strong> Ticari taşımacılık yapmaya yetkili olan ve platform aracılığıyla yük taşıma teklifi sunan gerçek ya da tüzel kişi kullanıcıyı ifade eder.</li>
            <li><strong>1.3 Gönderici (Yük Sahibi):</strong> Platform üzerinden navlun ilanı yayınlayarak yükünün taşınmasını talep eden gerçek veya tüzel kişi kullanıcıyı ifade eder.</li>
            <li><strong>1.4 Sistem İlanı / Dış Kaynak İlanı:</strong> Sistem ilanı, göndericinin platformda açtığı ve güvenli ödeme sürecine tabi ilandır. Dış kaynak ilanı, herkese açık taşımacılık gruplarından derlenen ve yalnız bilgilendirme amacıyla gösterilen ilandır (Madde 3.3).</li>
        </ul>
    </div>

    <!-- Madde 2: Sözleşmenin Konusu ve Kapsamı -->
    <div class="space-y-2">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 2: Sözleşmenin Konusu ve Kapsamı</h3>
        <p>
            İşbu sözleşmenin konusu; NavlunIQ'nun göndericiler ile sürücüleri ilan ve teklif sistemi, belge ve kimlik doğrulaması, sevkiyat sırasında izinli konum paylaşımı ve lisanslı ödeme kuruluşu üzerinden yürütülen teslimat onaylı ödeme altyapısıyla buluşturduğu dijital lojistik platformunun kullanım şartlarının, tarafların karşılıklı hak, borç ve sorumluluklarının belirlenmesidir.
        </p>
    </div>

    <!-- Madde 3: Platformun Hukuki Niteliği ve Sorumluluk Sınırı -->
    <div class="space-y-3">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 3: Platformun Hukuki Niteliği ve Sorumluluk Sınırı (Aracılık Beyanı)</h3>
        <ul class="list-disc pl-5 space-y-2">
            <li><strong>3.1 Teknoloji Sağlayıcı Beyanı:</strong> NavlunIQ, göndericiler ile sürücüleri dijital ortamda buluşturan ve sunduğu hizmetin niteliği ölçüsünde 6563 sayılı Elektronik Ticaretin Düzenlenmesi Hakkında Kanun ve ilgili mevzuat kapsamında <strong>"Aracı Hizmet Sağlayıcı"</strong> olarak faaliyet gösterir. NavlunIQ, açıkça ayrıca üstlenmediği işlemlerde yükün fiili taşıyıcısı, kargo firması veya taşıma işleri organizatörü sıfatıyla hareket etmez; kullanıcıların sağladığı içerikleri kontrol etme yükümlülüğü bulunmaz.</li>
            <li><strong>3.2 Sorumluluk Muafiyeti:</strong> Taşıma sözleşmesi gönderici ile sürücü arasında kurulur ve 6102 sayılı Türk Ticaret Kanunu’nun taşıma işlerine ilişkin hükümlerine tabidir. Taraflar kendi beyan, belge, yükleme, taşıma ve teslim yükümlülüklerinden sorumludur. Bu hüküm NavlunIQ’nun kendi kusuru, veri güvenliği, ödeme sürecine ilişkin yükümlülükleri veya emredici mevzuattan doğan sorumluluklarını kaldırmaz. NavlunIQ yük sigortası sunmaz; yükün sigortalanması tarafların kendi sorumluluğundadır.</li>
            <li data-clause="dis-kaynak"><strong>3.3 Dış Kaynak İlanları:</strong> Paylaşımına izin verilen taşımacılık gruplarından derlenen ilanlar bilgilendirme amacıyla, yalnız belge doğrulaması tamamlanmış premium sürücülere gösterilir. Bu ilanlarda NavlunIQ taraf, aracı veya taşıma organizatörü değildir; teklif, anlaşma ve ödeme doğrudan ilan sahibi ile sürücü arasında yapılır ve NavlunIQ güvenli ödeme sistemi kapsamına girmez. NavlunIQ ilan içeriğinin doğruluğunu, ilan sahibinin kimliğini veya taşımanın ifasını garanti etmez. Buna karşılık NavlunIQ, platform sürücülerinin bu ilanlar kapsamındaki davranışlarından doğan şikâyetleri inceler; kanıtlanan ihlallerde uyarı, askıya alma ve kalıcı engelleme dahil önlemler alır ve mağdur tarafa kayıtlarını mevzuat çerçevesinde sunarak destek olur. İlanlar yayından {{EXTERNAL_LIST_DAYS}} gün sonra listeden kaldırılır; ilan sahibi, ilanının kaldırılmasını her zaman isteyebilir.</li>
            <li data-clause="dis-kaynak-gizlilik"><strong>3.4 Dış Kaynak İlan Bilgilerinin Gizliliği:</strong> Dış kaynak ilanlarında gösterilen iletişim numarası ve ilan bilgileri yalnız premium sürücüye, yalnız o ilan için ilan sahibiyle iletişim kurmak amacıyla sunulur. Sürücü bu bilgileri kopyalayamaz, listeleyemez, başka grup, kanal, uygulama veya kişilere aktaramaz, reklam ya da toplu mesaj amacıyla kullanamaz ve üçüncü kişilerle hiçbir biçimde paylaşamaz. Bu yükümlülüğün ihlali halinde premium üyelik ve hesap, kalan süre için iade yapılmaksızın derhal sonlandırılır; NavlunIQ zarar gören ilan sahibinin yasal başvurularına kayıtlarıyla destek olur.</li>
        </ul>
    </div>

    <!-- Madde 4: Üyelik Koşulları, Çift Rol ve Kimlik Doğrulama (KYC) -->
    <div class="space-y-3">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 4: Üyelik Koşulları, Çift Rol ve Kimlik Doğrulama (KYC)</h3>
        <ul class="list-disc pl-5 space-y-2">
            <li><strong>4.1 Sürücü Belgeleri:</strong> Sürücünün teklif verebilmesi ve premium üyelik satın alabilmesi için sürücü belgesi, SRC belgesi, psikoteknik raporu, kimlikle çekilmiş fotoğraf ve araç ruhsatını yüklemesi, bu belgelerin yetkili personel tarafından incelenip onaylanması gerekir; K yetki belgesi ve taşıyıcı sorumluluk sigortası isteğe bağlıdır. Belgeler elle incelenir; sahte veya geçersiz evrak şüphesinde hesap orantılı biçimde sınırlandırılabilir, onay geri alınabilir; kanuni bildirim yükümlülüğü veya yetkili makam talebi bulunursa ilgili mercilere bilgi verilebilir.</li>
            <li><strong>4.2 Gönderici Doğrulaması:</strong> Gönderici ilan yayınlamak için kimlik doğrulaması yapmak zorunda değildir. Bireysel gönderici, ilk teklifi kabul etmeden önce T.C. kimlik numarası, ad soyad ve doğum yılının NVİ kimlik doğrulama servisinden teyidiyle bir kez doğrulanır; kurumsal gönderici, vergi kimlik numarası ve unvanının kayıtlı olmasıyla teklif kabul edebilir, şirket bilgilerinin yetkili personelce teyidi doğrulama rozetini sağlar. Göndericiden belge fotoğrafı istenmez. Kimlik bilgileri eşleşmeyen bireysel gönderici teklif kabul edemez; bilgilerini düzeltip yeniden deneyebilir. Doğrulanmış göndericiler sürücülere rozet ile gösterilir; bireysel göndericinin adı sürücü kartlarında kısaltılmış biçimde (“Ad S.”) yer alır.</li>
            <li><strong>4.3 Çift Rol Kuralı:</strong> Aynı telefon numarası ve e-posta adresiyle hem sürücü hem gönderici rolü kullanılabilir. İkinci rolün eklenmesi ve panel içinde roller arasında geçiş, güvenlik amacıyla kayıtlı e-posta adresine gönderilen tek kullanımlık <strong>e-posta doğrulama kodunun</strong> onaylanmasını gerektirir.</li>
            <li data-clause="bildirim-tercihi"><strong>4.4 Bildirimler ve E-posta Tercihi:</strong> Teklif sonucu, ödeme, belge ve hesap güvenliği gibi işlemsel bildirimler hizmetin gereği olarak gönderilir. Premium sürücülere aracına uygun yeni ilan yayınlandığında uygulama içi bildirim ve e-posta gönderilir; sürücü yeni ilan e-postalarını şoför panelindeki Premium sayfasından istediği zaman kapatabilir ve yeniden açabilir. Kapatma, uygulama içi bildirimleri ve premium haklarını etkilemez.</li>
            <li><strong>4.5 İletişim ve Platform Dışı Anlaşma Yasağı:</strong> Sistem ilanlarında tarafların telefon numaraları birbirine yalnız navlun bedeli ödendikten sonra gösterilir. Platform üzerinden eşleşen bir sevkiyatın bedelinin platform dışında ödenmesi ya da hizmet bedelini haksız biçimde bertaraf etmeye yönelik doğrulanmış ihlallerde olayın niteliğiyle orantılı hesap tedbirleri uygulanabilir ve kullanıcıya itiraz imkânı sağlanır. Uyuşmazlıklarda platform kayıtları diğer hukuka uygun delillerle birlikte değerlendirilebilir.</li>
            <li><strong>4.6 Hesap Güvenliği:</strong> Giriş, parola ve e-posta ile gönderilen tek kullanımlık kodla yapılır. Kullanıcı hesap bilgilerini gizli tutar ve hesabından yapılan işlemlerden sorumludur. Parola değiştirildiğinde diğer cihazlardaki oturumlar kapatılır; e-posta, telefon ve IBAN değişiklikleri parola doğrulaması ister, e-posta değişikliği yeni adrese gönderilen kodla tamamlanır.</li>
            <li><strong>4.7 Askıya Alma ve Engelleme:</strong> Sahte belge, dolandırıcılık şüphesi, Madde 3.4 ihlali, tekrarlanan vazgeçme ya da bu sözleşmeye aykırılık halinde NavlunIQ hesabı askıya alabilir veya kalıcı olarak engelleyebilir; askıdaki hesabın teklifleri işleme alınmaz. Kullanıcı, tedbire karşı destek kanalından itiraz edebilir.</li>
        </ul>
    </div>

    <!-- Madde 5: Güvenli Ödeme Sistemi ve Komisyon Kuralları -->
    <div class="space-y-3">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 5: Güvenli Ödeme Sistemi ve Komisyon Kuralları</h3>
        <ul class="list-disc pl-5 space-y-2">
            <li><strong>5.1 Teslimat Onaylı Ödeme:</strong> Gönderici, kabul ettiği teklifin navlun bedelini platformun sözleşmeli olduğu lisanslı ödeme kuruluşunun güvenli ödeme sayfasında kartla öder. NavlunIQ taraflar adına para tutan bir ödeme kuruluşu değildir; tahsilat, teslimat onayına kadar bekletme ve sürücüye aktarım işlemleri ilgili lisanslı ödeme kuruluşu ve bankalar tarafından kendi mevzuat ve işlem kurallarına göre yürütülür. Sürücü, teslim kanıtı fotoğrafını (POD) sisteme yükleyerek teslimatı bildirir; göndericinin onayı ve açık bir uyuşmazlık bulunmaması halinde sürücü hakedişi, platform hizmet bedeli düşülerek sürücünün kayıtlı banka hesabına aktarılır.</li>
            <li><strong>5.2 Ödeme Süresi:</strong> Gönderici, kabul ettiği teklifin navlun bedelini kabulden itibaren <strong>{{OFFER_PAYMENT_HOURS}} saat içinde</strong> öder; sürenin yarısında hatırlatma gönderilir. Süre dolarsa sürücü ataması kaldırılır ve ilan yeniden teklif almaya açılır. Ödeme ekranı açılmış bir ilan, ödeme kuruluşunun sonucu gelmeden kısa bir süre iptal edilemez.</li>
            <li><strong>5.3 Otomatik Onay Kuralı:</strong> Sürücü, teslim kanıtını sisteme yüklediği andan itibaren <strong>{{AUTO_APPROVAL_HOURS}} saat içerisinde</strong> gönderici onay vermez ya da uyuşmazlık açmazsa sevkiyat sistemde onaylanır ve sürücü hakedişi başlatılır. Hakedişin banka hesabına geçme zamanı ödeme kuruluşu ve banka işlem takvimine bağlıdır.</li>
            <li><strong>5.4 Komisyon ve Hizmet Bedeli:</strong> Platform üzerinden tamamlanan her sevkiyatta NavlunIQ, oranı ve vergisi teklif ve ödeme ekranlarında açıkça gösterilen bir <strong>“Aracılık Hizmet Komisyonu”</strong> alır. Komisyon sürücünün hakedişinden kesilir ve sürücü adına KDV dahil faturalandırılır; oran ödeme emri oluşturulduğu anda sabitlenir ve sonraki ayar değişikliklerinden etkilenmez. Göndericiden ayrıca bir hizmet bedeli alınıyorsa bu bedel ödeme öncesinde tutarla birlikte gösterilir. İptal ve iade halinde komisyon alınmaz.</li>
            <li><strong>5.5 Sürücü Hakedişi ve IBAN:</strong> Hakediş, sürücünün bildirdiği ve kendi adına ya da şirketine ait IBAN’a aktarılır. Ödeme kuruluşu mevzuatı gereği sürücü, gerçek kişi ise T.C. kimlik numarasını, şirket ise vergi kimlik numarasını bildirir. IBAN değişikliği parola doğrulaması ister ve dolandırıcılığa karşı değişiklikten sonraki kısa bir süre otomatik aktarım bekletilir; sürücü değişiklik hakkında bildirimle uyarılır.</li>
        </ul>
    </div>

    <!-- Madde 6: İptal, İade ve Uyuşmazlık Çözüm Protokolü (Dispute) -->
    <div class="space-y-3">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 6: İptal, İade ve Uyuşmazlık Çözüm Protokolü (Dispute)</h3>
        <ul class="list-disc pl-5 space-y-2">
            <li><strong>6.1 Ödeme Öncesi İptal:</strong> Navlun bedeli ödenmeden önce gönderici ilanını her zaman iptal edebilir; bekleyen ve kabul edilmiş teklifler kapanır, sürücüler bilgilendirilir. Aynı aşamada sürücü de kabul edilmiş teklifinden vazgeçebilir; ilan yeniden teklif almaya açılır. Bu aşamada para hareketi olmadığından iade söz konusu değildir.</li>
            <li><strong>6.2 Ödeme Sonrası, Yola Çıkılmadan Önce İptal:</strong> Sürücü yola çıktığını bildirmeden önce gönderici sevkiyatı “İptal et ve iade al” düğmesiyle iptal edebilir; tahsil edilmiş navlun bedelinin tamamı ödeme kuruluşu üzerinden göndericinin ödeme aracına iade edilir, komisyon alınmaz. Aynı aşamada sürücü de işten vazgeçebilir; bu halde de bedelin tamamı göndericiye iade edilir, ilan yeniden teklif almaya açılır ve vazgeçme sürücünün hesap kayıtlarında izlenir. Sürücü yükleme tarihinden sonra yola çıkmazsa taraflar ve operasyon ekibi uyarılır; gönderici iptal ve iade hakkını kullanabilir, operasyon ekibi de sevkiyatı iptal edip iadeyi başlatabilir.</li>
            <li><strong>6.3 Yola Çıkıldıktan Sonra:</strong> Sürücü yola çıktığını bildirdikten sonra tek taraflı iptal yapılmaz; sorunlar yalnız 6.4'teki uyuşmazlık süreciyle çözülür. Yük yoldayken verilen hakem kararı "sevkiyat devam eder" ya da "iptal ve tam iade" olabilir; sürücüye ödeme yalnız teslimat gerçekleşmişse yapılır.</li>
            <li><strong>6.4 Uyuşmazlık İnceleme Süreci:</strong> Gönderici, yoldaki veya teslim edilmiş bir sevkiyat için teslimat onayından önce uyuşmazlık açabilir; açıklamasını ve varsa fotoğrafını ekler, sürücü savunmasını ve kanıtını sunar. Uyuşmazlık açıkken otomatik onay ve hakediş durur. NavlunIQ <strong>"Kriz ve Uyuşmazlık Merkezi"</strong> üzerinden tarafların beyanlarını, teslim kanıtlarını ve konum kayıtlarını inceleyerek platform içi bir değerlendirme yapar: yoldaki sevkiyatta “devam” ya da “iptal ve tam iade”; teslim edilmiş sevkiyatta “hakediş sürücüye ödenir” ya da “navlun göndericiye iade edilir” kararı verir ve ödeme kuruluşu nezdinde ilgili işlemi başlatır. Gönderici uyuşmazlığı geri çekebilir; bu halde süreç kaldığı yerden devam eder. Bu değerlendirme tarafların mahkeme, tüketici hakem heyeti, ödeme itirazı ve diğer kanuni başvuru haklarını ortadan kaldırmaz.</li>
        </ul>
    </div>

    <!-- Madde 7: Sürücünün Taahhüt ve Sorumlulukları -->
    <div class="space-y-2">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 7: Sürücünün Taahhüt ve Sorumlulukları</h3>
        <p>
            Sürücü, taşıyacağı yükün cinsine uygun geçerli araç, kasa ve ruhsata, mevzuatın aradığı yetki belgelerine sahip olduğunu beyan eder; araç bilgilerini (plaka, araç sınıfı, kasa tipi) doğru girer ve güncel tutar; yükü gerekli özenle, mevzuata ve taraflarca kararlaştırılan teslim koşullarına uygun biçimde taşımakla, yola çıkış, teslim ve teslim kanıtı bildirimlerini zamanında yapmakla yükümlüdür. Hırsızlık, sahtecilik veya diğer hukuka aykırı fiil şüphesinde platform hesabı sınırlandırabilir, kanıtları koruyabilir ve gerekli hallerde yetkili mercilere başvurabilir.
        </p>
    </div>

    <!-- Madde 7A: Ticari Elektronik İleti -->
    <div class="space-y-2">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 7A: Ticari Elektronik İleti</h3>
        <p>
            Kampanya, tanıtım ve duyuru içerikli ticari elektronik iletiler yalnız kayıt sırasında ya da profil sayfasında <strong>ayrıca ve açıkça</strong> verilen onay üzerine gönderilir; onay üyelik için zorunlu değildir. Üye, her iletideki bağlantıyla ya da profilinden onayını dilediği an ücretsiz olarak geri alabilir; ret talebi en geç üç iş günü içinde uygulanır ve İleti Yönetim Sistemi'ne (İYS) kaydedilir. Teklif, ödeme, sevkiyat ve hesap güvenliği gibi hizmetin ifası için zorunlu bildirimler ticari elektronik ileti sayılmaz ve onaydan bağımsız olarak gönderilir.
        </p>
    </div>

    <!-- Madde 8: Fikri Mülkiyet ve Veri Güvenliği -->
    <div class="space-y-2">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 8: Fikri Mülkiyet ve Veri Güvenliği</h3>
        <p>
            NavlunIQ platformunun yazılım kodları, özgün tasarımı ve algoritmaları üzerindeki haklar NavlunIQ veya ilgili hak sahiplerine aittir; dış kaynaklı içeriklerin hakları kendi sahiplerinde kalır. Yazılı izin veya hukuki dayanak olmaksızın verilerin kazınması (scraping), kopyalanması ya da robot yazılımlarla taranması halinde erişim sınırlandırılabilir ve mevzuatın izin verdiği hukuki yollara başvurulabilir.
        </p>
    </div>

    <!-- Madde 8A: Sözleşme Değişiklikleri ve Yeniden Onay -->
    <div class="space-y-2" data-clause="surum-onay">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 8A: Sözleşme Değişiklikleri ve Yeniden Onay</h3>
        <p>
            NavlunIQ bu sözleşmeyi ve bağlı metinleri mevzuat ya da hizmet değişikliklerine göre güncelleyebilir. Anlam değiştiren her güncellemede sözleşme sürümü artırılır ve kullanıcı panele bir sonraki girişinde güncel metni görüp onaylar; onaylamayan kullanıcı panel işlemlerine devam edemez; çıkış yapabilir ya da hesabını silebilir. Yürürlükteki sürüm ve tarih sözleşmeler sayfasında gösterilir; yazım düzeltmeleri sürüm artırmaz. Yeni sürüm, onaydan önce kurulmuş sevkiyat ve ödemelere, o sevkiyatın koşullarını kullanıcı aleyhine değiştirecek biçimde uygulanmaz.
        </p>
    </div>

    <!-- Madde 8B: Süre, Fesih ve Hesabın Silinmesi -->
    <div class="space-y-2">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 8B: Süre, Fesih ve Hesabın Silinmesi</h3>
        <p>
            Sözleşme belirsiz sürelidir. Kullanıcı, panelindeki <strong>Hesabım</strong> bölümünden hesabını her zaman silebilir; açık teklifleri kapanır, belgeleri, konum kayıtları, kimlik ve vergi numarası ile araç plakası silinir, yasal saklama yükümlülüğü bulunan kayıtlar süresi boyunca korunur. Yolda ya da ödemesi teslimat onayı bekleyen bir sevkiyatı bulunan kullanıcı, bu sevkiyat sonuçlanmadan hesabını silemez. NavlunIQ, sözleşmeye aykırılık halinde Madde 4.7’ye göre hesabı askıya alabilir veya sözleşmeyi feshedebilir. Fesih, doğmuş hak ve borçları etkilemez.
        </p>
    </div>

    <!-- Madde 8C: Mücbir Sebep -->
    <div class="space-y-2">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 8C: Mücbir Sebep</h3>
        <p>
            Doğal afet, salgın, savaş, grev, yaygın internet ve enerji kesintisi, ödeme kuruluşu ya da banka altyapılarındaki arızalar, idari karar ve benzeri tarafların kontrolü dışındaki olaylar nedeniyle yükümlülüklerin yerine getirilememesi sözleşmeye aykırılık sayılmaz; etkilenen yükümlülükler olay süresince askıya alınır. Göndericiyle sürücü arasındaki taşıma ilişkisinde mücbir sebebin sonuçları Türk Ticaret Kanunu hükümlerine göre belirlenir.
        </p>
    </div>

    <!-- Madde 9: Uygulanacak Hukuk ve Yetkili Mahkeme -->
    <div class="p-4 bg-neutral-50 dark:bg-neutral-900/60 rounded-xl border border-neutral-200 dark:border-neutral-800 space-y-2">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 9: Uygulanacak Hukuk ve Yetkili Mahkeme</h3>
        <p class="text-xs">
            İşbu sözleşmenin uygulanmasında, yorumlanmasında ve uyuşmazlıkların çözümünde Türkiye Cumhuriyeti Kanunları uygulanır. Tacir ve ticari işletme niteliğindeki kullanıcılarla doğan uyuşmazlıklarda <strong>Ankara Mahkemeleri ve Ankara İcra Daireleri</strong> yetkilidir. 6502 sayılı Kanun kapsamında tüketici sayılan kullanıcılar için tüketici hakem heyetleri ve tüketici mahkemelerinin görev ve yetkisine ilişkin emredici hükümler saklıdır.
        </p>
    </div>
</div>
HTML;

        // 3. GİZLİLİK POLİTİKASI
        $privacy = <<<'HTML'
<div class="space-y-6 text-neutral-800 dark:text-neutral-200 leading-relaxed">
    <div class="border-b border-neutral-200 dark:border-neutral-800 pb-4">
        <span class="text-[11px] font-bold uppercase tracking-wider text-neutral-500 dark:text-neutral-400 block mb-1">Yasal Mevzuat ve Güvence</span>
        <h2 class="text-xl sm:text-2xl font-bold text-neutral-900 dark:text-white">NavlunIQ Gizlilik Politikası</h2>
        <span class="text-xs text-neutral-400">Son Güncelleme: {{LEGAL_DATE}}</span>
    </div>

    <p>
        <strong>{{COMPANY_NAME}}</strong> (“NavlunIQ” veya “Şirket”) olarak, kullanıcılarımızın kişisel verilerinin gizliliğini ve güvenliğini korumaya yönelik idari ve teknik tedbirler uyguluyoruz. Bu Gizlilik Politikası, navluniq.com web sitesi üzerinden işlenen verilerin toplanma, saklanma, korunma ve imha edilme kriterlerini açıklar; işlenen veri kategorileri, amaçlar ve hukuki sebepler KVKK Aydınlatma Metni'nde ayrıntılı olarak yer alır.
    </p>

    <!-- Madde 1: Veri Toplama Yöntemleri ve Amaçları -->
    <div class="space-y-3">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 1: Veri Toplama Yöntemleri ve Amaçları</h3>
        <p>Platformumuzun ilan, teklif, belge doğrulama ve teslimat onaylı ödeme hizmetlerini sunabilmesi amacıyla aşağıdaki veriler doğrudan kullanıcı girişiyle veya kullanıcı izinlerine bağlı cihaz özellikleri aracılığıyla, hizmetin gerektirdiği ölçüde toplanır:</p>
        <ul class="list-disc pl-5 space-y-1.5">
            <li><strong>Kişisel Tanımlayıcılar ve Belgeler:</strong> Ad, soyad, telefon numarası, e-posta adresi, parola özeti; şoförlerde sürücü belgesi, SRC, psikoteknik raporu, kimlikle çekilmiş fotoğraf ve araç ruhsatı görselleri ile araç plakası; yük sahiplerinde kimlik doğrulaması için T.C. kimlik numarası ve doğum yılı ya da vergi kimlik numarası. Belgeler yetkili personel tarafından elle incelenir; belgelerden otomatik veri çıkarma yapılmaz.</li>
            <li><strong>İşlem Güvenliği Verileri:</strong> IP adresi, giriş ve işlem kayıtları, e-posta doğrulama kodu kayıtları, tarayıcı bilgisi, sözleşme onay kayıtları.</li>
            <li><strong>Sevkiyat Verileri:</strong> Teklifler, ödeme emirleri, teslim kanıtı fotoğrafı, yoldaki sevkiyatta konum, puan ve yorumlar, uyuşmazlık ve destek kayıtları.</li>
        </ul>
    </div>

    <!-- Madde 2: Çerezler (Cookies) ve Çevrimiçi İzleme Teknolojileri -->
    <div class="space-y-2">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 2: Çerezler (Cookies) ve Çevrimiçi İzleme Teknolojileri</h3>
        <p>
            NavlunIQ yalnız hizmetin çalışması için zorunlu çerezler kullanır: oturum çerezi, form güvenliği (CSRF) çerezi ve girişte “Bu cihazda oturumum açık kalsın” seçilirse yalnız o cihazda tutulan hatırlama çerezi. Tema (açık/koyu) ve yazı boyutu tercihleri tarayıcınızın yerel deposunda saklanır ve sunucuya gönderilmez. <strong>Üçüncü taraf analitik, reklam ya da izleme çerezi kullanılmaz.</strong> Oturum, hareketsiz kalınan <strong>120 dakika</strong> sonunda kapanır; hatırlama çerezi parola değişince ve diğer cihazlardaki oturumlar sonlandırılınca geçersiz olur. Çerezleri tarayıcınızdan silebilir ya da engelleyebilirsiniz; zorunlu çerezler engellenirse giriş yapılamaz.
        </p>
    </div>

    <!-- Madde 3: Konum Bilgileri -->
    <div class="space-y-2">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 3: Konum Bilgileri ve Sevkiyat Takibi</h3>
        <p>
            Şoförün konumu yalnız “yolda” durumundaki bir sevkiyat sırasında, şoför iş sayfasını açıkken tarayıcısında konum iznini vermişse alınır; arka planda ya da sevkiyat dışında konum toplanmaz. Konum, o sevkiyatın yük sahibine sevkiyat sayfasında gösterilir; teslimat tamamlandığında ya da sevkiyat kapandığında takip sona erer. Kayıtlar enlem, boylam, hız, yön ve doğruluk değeri olarak erişim kontrolleri uygulanarak saklanır ve <strong>90 gün</strong> sonra kendiliğinden silinir. Uyuşmazlık incelemesinde bu kayıtlar kanıt olarak değerlendirilebilir.
        </p>
    </div>

    <!-- Madde 4: Veri Saklama Süresi ve İmha Politikası (Unutulma Hakkı) -->
    <div class="space-y-2">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 4: Veri Saklama Süresi ve İmha Politikası (Unutulma Hakkı)</h3>
        <p>
            Kullanıcılar, panellerindeki <strong>Hesabım</strong> bölümünden verilerinin bir kopyasını indirebilir ve hesaplarını silebilir. Hesap silinince belgeler, konum kayıtları, kimlik ve vergi numarası, araç plakası ve profil bilgileri silinir ya da anonimleştirilir; fatura, ödeme ve muhasebe kayıtları Türk Ticaret Kanunu ve Vergi Usul Kanunu uyarınca 10 yıl, uyuşmazlık ve sözleşme onay kayıtları yasal zamanaşımı süresince saklanır. Yolda ya da ödemesi teslimat onayı bekleyen sevkiyatı olan hesap, sevkiyat sonuçlanmadan silinemez. Diğer silme ve düzeltme taleplerinizi <strong>{{COMPANY_EMAIL}}</strong> adresine iletebilirsiniz; talepler en geç <strong>30 gün</strong> içinde sonuçlandırılır. Yedeklerdeki kopyalar olağan yedek yaşam döngüsü içinde erişilemez hale getirilir.
        </p>
    </div>

    <!-- Madde 5: Veri Güvenliği ve Siber Korunma Tedbirleri -->
    <div class="space-y-2">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 5: Veri Güvenliği ve Siber Korunma Tedbirleri</h3>
        <p>
            Yüklenen belgeler internetten doğrudan erişime kapalı <strong>kyc_private</strong> depolama alanında yetki kontrolleriyle tutulur ve yalnız yetkili personel görür. Parolalar tek yönlü özetleme algoritmalarıyla korunur; girişte parolaya ek olarak e-posta ile tek kullanımlık doğrulama kodu istenir; parola değişince diğer cihazlardaki oturumlar kapatılır; e-posta, telefon ve IBAN değişiklikleri parola doğrulaması ister. Tüm trafik HTTPS ile şifrelenir, kart bilgileri NavlunIQ sunucularına uğramaz, dış kaynak ilanlarındaki iletişim numaraları şifreli saklanır. Yönetici işlemleri etkinlik günlüğüne yazılır ve yedekler düzenli alınır.
        </p>
    </div>

    <!-- Madde 6: Verilerin Üçüncü Şahıslarla Paylaşımı -->
    <div class="p-4 bg-neutral-50 dark:bg-neutral-900/60 rounded-xl border border-neutral-200 dark:border-neutral-800 space-y-2">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 6: Verilerin Üçüncü Şahıslarla Paylaşımı</h3>
        <p class="text-xs">
            NavlunIQ, kullanıcı verilerini hukuki dayanak ve gerekli bilgilendirme olmaksızın üçüncü kişilerin bağımsız reklam amaçları için kullanmaz ve satmaz. Herkese açık taşımacılık gruplarından derlenen dış kaynak ilanlarındaki iletişim numaraları üçüncü kişilere satılmaz, devredilmez ve yalnız belge doğrulaması tamamlanmış premium sürücülere, o ilan için ilan sahibiyle iletişim amacıyla gösterilir; premium sürücüler bu bilgileri üçüncü kişilerle paylaşmamayı Kullanıcı Sözleşmesi md. 3.4 ile taahhüt eder. Verileriniz hizmetin gerektirdiği ölçüde sözleşmeli lisanslı ödeme kuruluşu ve bankalar, NVİ kimlik doğrulama servisi, sevkiyatın karşı tarafı (ödeme sonrası ad ve telefon) ve usulüne uygun bilgi talep eden adli/idari kurumlarla paylaşılabilir. Dış kaynak ilan metinlerinin yapay zeka ile ayrıştırılması ve e-posta/duyuru altyapısı kapsamındaki yurt dışına aktarımlar KVKK Aydınlatma Metni Madde 7'de açıklanmıştır.
        </p>
    </div>
</div>
HTML;

        // 4. MESAFELİ SATIŞ SÖZLEŞMESİ
        $distanceSale = <<<'HTML'
<div class="space-y-6 text-neutral-800 dark:text-neutral-200 leading-relaxed">
    <div class="border-b border-neutral-200 dark:border-neutral-800 pb-4">
        <span class="text-[11px] font-bold uppercase tracking-wider text-neutral-500 dark:text-neutral-400 block mb-1">Yasal Mevzuat ve Ticaret</span>
        <h2 class="text-xl sm:text-2xl font-bold text-neutral-900 dark:text-white">NavlunIQ Mesafeli Satış Sözleşmesi</h2>
        <span class="text-xs text-neutral-400">Son Güncelleme: {{LEGAL_DATE}}</span>
    </div>

    <p>
        İşbu sözleşme, 6502 sayılı Tüketicinin Korunması Hakkında Kanun ve Mesafeli Sözleşmeler Yönetmeliği hükümleri uyarınca, NavlunIQ platformu üzerinden satın alınan dijital Premium Sürücü Aboneliği ile tamamlanan sevkiyatlardan alınan aracılık hizmet komisyonunun satış, ödeme ve cayma koşullarını belirler. Satın alma ekranında gösterilen ön bilgiler bu sözleşmenin parçasıdır.
    </p>

    <!-- Madde 1: Taraflar ve İletişim Bilgileri -->
    <div class="p-4 bg-neutral-50 dark:bg-neutral-900/60 rounded-xl border border-neutral-200 dark:border-neutral-800 space-y-2 text-xs">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 1: Taraflar ve İletişim Bilgileri</h3>
        <ul class="space-y-1">
            <li><strong>Satıcı / Aracı Hizmet Sağlayıcı:</strong> {{COMPANY_NAME}} (MERSİS {{COMPANY_MERSIS_NO}}, Ticaret Sicil No {{COMPANY_TRADE_REGISTRY_NO}}, {{COMPANY_TAX_OFFICE}} / {{COMPANY_TAX_NO}})</li>
            <li><strong>Adres:</strong> {{COMPANY_ADDRESS}}</li>
            <li><strong>E-Posta:</strong> {{COMPANY_EMAIL}} | <strong>Telefon:</strong> {{COMPANY_PHONE}}</li>
            <li><strong>Alıcı (Kullanıcı):</strong> navluniq.com üzerinde kayıtlı olan, dijital hizmet alan şoförler (sürücüler) ve yük sahipleri (göndericiler); kimlik ve iletişim bilgileri hesap kayıtlarındaki gibidir.</li>
        </ul>
    </div>

    <!-- Madde 2: Sözleşmeli Hizmetin Konusu, Bedeli ve Ödeme Şartları -->
    <div class="space-y-3">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 2: Sözleşmeli Hizmetin Konusu, Bedeli ve Ödeme Şartları</h3>
        <ul class="list-disc pl-5 space-y-2">
            <li><strong>2.1 Premium Sürücü Aboneliği:</strong> Belgeleri onaylı sürücülere dış kaynak ilanlarına ve ilan sahibinin iletişim numarasına erişim, sistem ilanlarını diğer üyelerden <strong>{{PREMIUM_LEAD_MINUTES}} dakika</strong> önce görme (süre sıfırsa aynı anda açılır) ve aracına uygun yeni ilan bildirimleri sağlayan, satın alma ekranında seçilen <strong>1, 3, 6 ya da 12 aylık</strong> dönemler halinde, aynı ekranda gösterilen <strong>KDV dahil bedel</strong> (uzun dönemlerde ilan edilen indirim uygulanmış toplam) üzerinden sunulan dijital üyelik hizmetidir. Dönem, ödeme onaylandığı anda başlar; devam eden bir premium süre varsa yeni dönem onun bitimine eklenir. Abonelik otomatik yenilenmez. NavlunIQ, belgeleri onaylanan her sürücüye bir kez <strong>{{PREMIUM_TRIAL_DAYS}} gün</strong> ücretsiz deneme tanımlayabilir (süre sıfırsa deneme sunulmaz); deneme için ödeme aracı istenmez, deneme sonunda ücret tahsil edilmez ve üyelik kendiliğinden standart plana döner. Deneme, satın alma yerine geçmez ve bu sözleşmenin ücretli abonelik hükümlerini doğurmaz.</li>
            <li><strong>2.2 Aracılık Hizmet Komisyonu:</strong> Sürücü ile gönderici arasında platform vasıtasıyla eşleşen ve teslimatı onaylanan her sevkiyatın navlun bedeli üzerinden, oranı teklif ve ödeme ekranlarında gösterilen ve ödeme emrinde sabitlenen komisyondur. Komisyon sürücünün hakedişinden kesilir ve sürücü adına KDV dahil faturalandırılır; göndericiden alınan bir hizmet bedeli varsa ödeme öncesinde tutarla birlikte gösterilir.</li>
            <li><strong>2.3 Ödeme Yöntemi:</strong> Premium abonelik bedeli ve navlun bedeli, lisanslı ödeme kuruluşunun güvenli ödeme sayfasında kredi kartı ya da banka kartı ile tahsil edilir; kart bilgileri NavlunIQ tarafından görülmez. Ödeme sonucu kullanıcıya anında bildirilir; satın alma, ödeme kuruluşunun onayıyla tamamlanır.</li>
            <li><strong>2.4 Fatura:</strong> Premium abonelik ve komisyon bedelleri için fatura, hesap kayıtlarındaki ad/unvan ve kimlik ya da vergi bilgileriyle yürürlükteki vergi mevzuatına uygun olarak düzenlenir; fatura kayıtları şoför panelindeki Premium ve Hakediş sayfalarında, yük sahibi panelindeki Finans sayfasında görüntülenir. Fatura bilgilerinin doğruluğu kullanıcının sorumluluğundadır.</li>
        </ul>
    </div>

    <!-- Madde 3: Cayma Hakkı Sınırları ve Dijital İade İstisnaları -->
    <div class="space-y-3">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 3: Cayma Hakkı Sınırları ve Dijital İade İstisnaları</h3>
        <ul class="list-disc pl-5 space-y-2">
            <li><strong>3.1 Premium Abonelik:</strong> NavlunIQ Premium Sürücü Aboneliği, ödeme onaylandığı anda elektronik ortamda ifasına başlanan dijital bir hizmettir; satın alma ekranında bu durum ve cayma hakkının bulunmadığı açıkça gösterilir ve alıcının onayı alınır. Mesafeli Sözleşmeler Yönetmeliği’nin 15/1-ğ maddesindeki <em>"elektronik ortamda anında ifa edilen hizmetler veya tüketiciye anında teslim edilen gayri maddi mallar"</em> istisnası gereği cayma hakkı kullanılamaz ve abonelik bedeli iade edilmez. Abonelik otomatik yenilenmez; satın alınan dönemin sonuna kadar kullanılır ve dönem bitiminde kendiliğinden sona eder. Kullanıcı Sözleşmesi md. 3.4 ihlali nedeniyle sonlandırılan abonelikte kalan süre iade edilmez. Kullanıcının emredici kanuni hakları saklıdır.</li>
            <li><strong>3.2 Komisyon:</strong> Aracılık hizmet komisyonu yalnız teslimatı onaylanan sevkiyatlarda, sürücü hakedişi aktarılırken tahsil edilir; sürücü hakedişi aktarıldıktan sonra komisyon iade edilmez. Sevkiyat yola çıkılmadan iptal edildiğinde ya da uyuşmazlık sonucu navlun göndericiye iade edildiğinde komisyon alınmaz ve göndericiye navlun bedelinin tamamı iade edilir.</li>
        </ul>
    </div>

    <!-- Madde 4: İhtilafların Çözümü ve Yetkili Mahkemeler -->
    <div class="p-4 bg-neutral-50 dark:bg-neutral-900/60 rounded-xl border border-neutral-200 dark:border-neutral-800 space-y-2">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 4: İhtilafların Çözümü ve Yetkili Mahkemeler</h3>
        <p class="text-xs">
            İşbu sözleşmeden doğabilecek ihtilaflarda, alıcının 6502 sayılı Kanun kapsamında tüketici sayıldığı hallerde T.C. Ticaret Bakanlığı tarafından her yıl ilan edilen parasal sınırlar çerçevesinde alıcının yerleşim yerindeki <strong>Tüketici Hakem Heyetleri ve Tüketici Mahkemeleri</strong> görevli ve yetkilidir. Alıcının ticari ya da mesleki amaçla hareket eden tacir olduğu hallerde Kullanıcı Sözleşmesi Madde 9 uygulanır.
        </p>
    </div>
</div>
HTML;

        // 5. İADE VE İPTAL POLİTİKASI
        $cancellation = <<<'HTML'
<div class="space-y-6 text-neutral-800 dark:text-neutral-200 leading-relaxed">
    <div class="border-b border-neutral-200 dark:border-neutral-800 pb-4">
        <span class="text-[11px] font-bold uppercase tracking-wider text-neutral-500 dark:text-neutral-400 block mb-1">Yasal Mevzuat ve İade</span>
        <h2 class="text-xl sm:text-2xl font-bold text-neutral-900 dark:text-white">NavlunIQ İade Politikası</h2>
        <span class="text-xs text-neutral-400">Son Güncelleme: {{LEGAL_DATE}}</span>
    </div>

    <p>
        NavlunIQ platformu üzerinde gerçekleştirilen yük ilan iptalleri, navlun bedeli iadeleri, teslimat onaylı ödeme işlemleri ile komisyon ve abonelik bedellerine ait esaslar bu politikada aşamalara göre açıklanmıştır; emredici mevzuat ve işlem öncesi gösterilen özel koşullar saklıdır.
    </p>

    <!-- Madde 1: Genel İptal ve İade Şartları -->
    <div class="space-y-2">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 1: Genel İptal ve İade Şartları</h3>
        <p>
            Navlun bedeli, lisanslı ödeme kuruluşunun güvenli ödeme sayfasında kartla tahsil edilir ve teslimat onayına kadar ödeme kuruluşu nezdinde bekletilir; NavlunIQ parayı kendi hesabında tutmaz. İade kararı verildiğinde bedel, ödemenin alındığı kart ya da hesaba ödeme kuruluşu üzerinden geri gönderilir. İadelerde navlun bedelinin <strong>tamamı</strong> iade edilir; platform komisyonu yalnız teslimatı onaylanan sevkiyatlarda alınır.
        </p>
    </div>

    <!-- Madde 2: Yük İlan İptalleri ve Navlun Bedeli İadesi -->
    <div class="space-y-3" data-clause="iptal-asamalari">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 2: Aşamalara Göre İptal ve Navlun Bedeli İadesi</h3>
        <ul class="list-disc pl-5 space-y-2">
            <li><strong>2.1 Ödeme Öncesi (ilan yayında ya da teklif kabul edilmiş, ödeme yapılmamış):</strong> Yük sahibi ilanını her zaman iptal edebilir; bekleyen ve kabul edilmiş teklifler kapanır ve şoförler bilgilendirilir. Şoför de kabul edilmiş teklifinden vazgeçebilir; ilan yeniden teklif almaya açılır. Ödeme süresi: yük sahibi kabul ettiği teklifin bedelini <strong>{{OFFER_PAYMENT_HOURS}} saat</strong> içinde öder, sürenin yarısında hatırlatılır; süre dolarsa şoför ataması kendiliğinden kaldırılır ve ilan yeniden teklif almaya açılır. Bu aşamada para hareketi olmadığından iade söz konusu değildir. Ödeme ekranı açılmış bir ilan, ödeme kuruluşunun sonucu gelmeden 15 dakika iptal edilemez.</li>
            <li><strong>2.2 Ödeme Sonrası, Yola Çıkılmadan Önce (tam iade):</strong> Şoför yola çıktığını bildirmeden önce yük sahibi sevkiyat sayfasındaki “İptal et ve iade al” düğmesiyle sevkiyatı iptal edebilir; tahsil edilmiş navlun bedelinin tamamı ödeme kuruluşu üzerinden ödemenin alındığı karta iade edilir, komisyon alınmaz. Aynı aşamada şoför işten vazgeçerse de bedelin tamamı yük sahibine iade edilir ve ilan yeniden teklif almaya açılır; vazgeçme şoförün kayıtlarında izlenir. Şoför yükleme tarihinden sonra yola çıkmazsa (“şoför gelmedi”) yük sahibi, şoför ve operasyon ekibi uyarılır; yük sahibi bu maddedeki iptal ve iade hakkını kullanabilir, operasyon ekibi de sevkiyatı iptal edip iadeyi başlatabilir.</li>
            <li><strong>2.3 Yola Çıkıldıktan Sonra:</strong> Şoför yola çıktığını bildirdikten sonra tek taraflı iptal yapılmaz; sorunlar yalnız uyuşmazlık süreciyle çözülür. Yük sahibi açıklaması ve varsa fotoğrafıyla uyuşmazlık açar, şoför savunmasını sunar; uyuşmazlık açıkken otomatik onay ve hakediş durur. Yük yoldayken hakem kararı "sevkiyat devam eder" ya da "iptal ve tam iade" olabilir; şoföre ödeme yalnız teslimat gerçekleşmişse yapılır. Yük sahibi uyuşmazlığı geri çekerse süreç kaldığı yerden devam eder.</li>
            <li><strong>2.4 Teslimattan Sonra:</strong> Şoför teslim kanıtı fotoğrafını yükleyerek teslimatı bildirir. Yük sahibi teslimatı onaylarsa ya da <strong>{{AUTO_APPROVAL_HOURS}} saat</strong> içinde onay vermez ve uyuşmazlık açmazsa sevkiyat kendiliğinden onaylanır ve şoför hakedişi, komisyon düşülerek kayıtlı IBAN’ına aktarılır. Bu süre içinde açılan uyuşmazlıkta karar, hakedişin şoföre ödenmesi ya da navlun bedelinin tamamının yük sahibine iadesidir. Hakediş aktarıldıktan sonra platform üzerinden iade yapılmaz; tarafların kanuni hakları saklıdır.</li>
            <li><strong>2.5 İadenin Gerçekleşmesi:</strong> İade kararı anında ödeme kuruluşuna iletilir; kuruluş iadeyi hemen onaylamazsa sevkiyat “iade bekleniyor” durumuna geçer ve finans ekibi iadeyi tamamlar, sonucu yük sahibine bildirir. İptal edilmiş bir ilana sonradan ulaşan ödeme kendiliğinden iade edilir.</li>
        </ul>
    </div>

    <!-- Madde 3: Komisyon ve Abonelik Bedeli İadeleri -->
    <div class="space-y-3">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 3: Komisyon ve Abonelik Bedeli İadeleri</h3>
        <ul class="list-disc pl-5 space-y-2">
            <li><strong>3.1 Komisyon:</strong> Platform komisyonu yalnız teslimatı onaylanan sevkiyatlarda, şoför hakedişi aktarılırken kesilir ve şoför adına faturalandırılır. Yola çıkılmadan yapılan iptallerde ve uyuşmazlık sonucu navluna iade kararı verilen sevkiyatlarda komisyon alınmaz; yük sahibine navlun bedelinin tamamı iade edilir. Hakediş aktarıldıktan sonra komisyon iade edilmez.</li>
            <li><strong>3.2 Abonelik:</strong> Premium Sürücü Aboneliği (1, 3, 6 ya da 12 aylık dönem), ödeme onaylandığı anda başlayan dijital bir hizmettir; bedeli iade edilmez (Mesafeli Satış Sözleşmesi md. 3.1). Abonelik otomatik yenilenmez: satın alınan dönemin sonuna kadar kullanılır ve dönem bitiminde kendiliğinden sona erer; bitişten önce hatırlatma gönderilir. Kullanıcı dilediği zaman yeni bir dönem satın alabilir; süre mevcut dönemin bitiminden itibaren eklenir. Belgeleri onaylanan sürücüye bir kez tanımlanan <strong>{{PREMIUM_TRIAL_DAYS}} günlük</strong> ücretsiz deneme (süre sıfırsa sunulmaz) ödeme aracı gerektirmez, sonunda ücret alınmaz ve kendiliğinden sona erer. Kullanıcı Sözleşmesi md. 3.4 ihlali nedeniyle sonlandırılan abonelikte kalan süre iade edilmez.</li>
        </ul>
    </div>

    <!-- Madde 4: İade Süreçleri ve Ödeme Kanalları -->
    <div class="p-4 bg-neutral-50 dark:bg-neutral-900/60 rounded-xl border border-neutral-200 dark:border-neutral-800 space-y-2">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 4: İade Süreçleri ve Ödeme Kanalları</h3>
        <p class="text-xs">
            Onaylanan iadeler, ödemenin yapıldığı kredi kartına veya banka kartına <strong>ödemenin alındığı ödeme kuruluşu üzerinden iletilir</strong>; başka bir hesaba iade yapılmaz. İadenin karta yansıma süresi ödeme kuruluşu ve ilgili bankanın işlem takvimine göre değişir (genellikle 1-10 iş günü). NavlunIQ işlem durumunu izler, yük sahibini panel bildirimi ve e-posta ile bilgilendirir ve kendi kontrolündeki sorunlar için destek sağlar.
        </p>
    </div>
</div>
HTML;

        return [
            'contract_kvkk' => $kvkk,
            'contract_terms' => $terms,
            'contract_privacy' => $privacy,
            'contract_distance_sale' => $distanceSale,
            'contract_cancellation' => $cancellation,
        ];
    }
}
