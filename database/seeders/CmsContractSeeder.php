<?php

namespace Database\Seeders;

use App\Models\CmsContent;
use Illuminate\Database\Seeder;

class CmsContractSeeder extends Seeder
{
    public const KEYS = ['contract_kvkk', 'contract_terms', 'contract_privacy', 'contract_distance_sale', 'contract_cancellation'];

    /**
     * Beş yasal metni yükler. Şirket künyesi yer tutucu olarak kalır ve gösterimde panelden doldurulur.
     * Metinler hukuk danışmanı onayından geçirilmelidir; bu seed yalnızca başlangıç içeriğidir.
     */
    public function run(): void
    {
        // Şirket künyesi yer tutucuları ({{COMPANY_*}}) metinde saklanır; sayfada gösterilirken
        // App\Support\Company::fillTokens() panelden yönetilen güncel künyeyle doldurur.
        $tokens = [
            '{{LEGAL_DATE}}' => (string) (config('company.legal_effective_date') ?: now()->translatedFormat('d F Y')),
        ];

        // 1. KVKK AYDINLATMA METNİ
        $kvkk = <<<'HTML'
<div class="space-y-6 text-neutral-800 dark:text-neutral-200 leading-relaxed">
    <div class="border-b border-neutral-200 dark:border-neutral-800 pb-4">
        <span class="text-[11px] font-bold uppercase tracking-wider text-neutral-500 dark:text-neutral-400 block mb-1">Yasal Mevzuat ve KVKK Uyumu</span>
        <h2 class="text-xl sm:text-2xl font-bold text-neutral-900 dark:text-white">6698 Sayılı Kişisel Verilerin Korunması Kanunu Aydınlatma Metni</h2>
        <span class="text-xs text-neutral-400">Son Güncelleme: {{LEGAL_DATE}}</span>
    </div>

    <p>
        <strong>{{COMPANY_NAME}}</strong> (“NavlunIQ” veya “Şirket”) olarak, 6698 sayılı Kişisel Verilerin Korunması Kanunu (“KVKK”) ve ilgili mevzuat uyarınca, <strong>"Veri Sorumlusu"</strong> sıfatıyla, kişisel verilerinizin toplanması, işlenmesi, saklanması, aktarılması ve imha edilmesi süreçleri hakkında sizi bilgilendiriyoruz. <strong>navluniq.com</strong> web sitesi, mobil uygulamalar ve lojistik servislerin kullanımı bu metnin erişilebilir olmasını sağlar; sözleşme veya açık rıza gerektiren işlemler ayrıca açık bir onayla kayıt altına alınır.
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
        <p>Platformumuzdaki akıllı eşleşme, kimlik doğrulama, ödeme süreçleri ve güvenli lojistik operasyonları kapsamında aşağıdaki kişisel verileriniz, ilgili hizmetin gerektirdiği ölçüde işlenebilmektedir:</p>
        <ul class="list-disc pl-5 space-y-2">
            <li><strong>Kimlik Bilgileri:</strong> Ad, soyad, doğum tarihi, T.C. Kimlik Numarası (Sürücü ehliyeti, SRC belgesi ve vergi levhasından uzman ekibimizce kontrol edilerek doğrulanan ve gerektiğinde kullanıcı veya yetkili personel tarafından doğrulanan veriler dahil).</li>
            <li><strong>İletişim Bilgileri:</strong> Cep telefonu numarası, kurumsal e-posta adresi, şirket açık adresi, teslimat ve varış noktası adresleri.</li>
            <li><strong>Mesleki Belgeler ve Onboarding (KYC) Verileri:</strong> Sürücü ehliyeti, SRC belgesi, psikoteknik raporu, tır ruhsatı, araç tescil belgesi, K Yetki Belgesi, profil fotoğrafları, araç fotoğrafları, Taşıyıcı Mali Mesuliyet Sigortası Poliçesi ve vergi levhası görselleri ile bu görsellerden ayrıştırılan yasal belgeler.</li>
            <li><strong>Fotoğraf Verileri:</strong> Kimlikle birlikte çekilen fotoğraf ve profil fotoğrafı yalnız kimlik doğrulama amacıyla, biyometrik işleme yapılmaksızın saklanan veriler yalnız ayrı bilgilendirme, gerekli açık rıza ve uygulanabilir mevzuat şartları sağlanarak işlenir.</li>
            <li><strong>Finansal ve Muhasebe Verileri:</strong> Banka hesap bilgileri, IBAN numaraları, fatura detayları, hizmet bedeli ödeme geçmişleri ve ödeme altyapısı işlem günlükleri.</li>
            <li><strong>Coğrafi Konum Bilgileri:</strong> Sürücülerin platform üzerinden aktif olarak yük taşıdıkları esnada, kullanıcının cihaz izni verdiği PWA konum servisleri vasıtasıyla toplanan enlem, boylam, hız ve rota koordinat verileri.</li>
            <li><strong>İşlem Güvenliği Verileri:</strong> IP adresi, port bilgileri, web sitesi giriş-çıkış logları, e-posta doğrulama kodu ve diğer hesap güvenliği günlükleri, cihaz marka/model ve tarayıcı bilgileri.</li>
        </ul>
    </div>

    <!-- Madde 3: Kişisel Verilerin İşlenme Amaçları -->
    <div class="space-y-3">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 3: Kişisel Verilerin İşlenme Amaçları</h3>
        <p>Kişisel verileriniz, KVKK'nın 5. ve 6. maddelerinde belirtilen kişisel veri işleme şartları dahilinde aşağıdaki amaçlar doğrultusunda işlenmektedir:</p>
        <ul class="list-disc pl-5 space-y-1.5">
            <li>Yük sahipleri ve şoförlerin platform üzerinde güvenli, hızlı ve akıllı algoritmalarla eşleştirilmesi,</li>
            <li>Şoförlerin yasal taşıma belgelerinin (ehliyet, SRC, vergi levhası) <strong>AI OCR</strong> teknolojisiyle ön incelemeye tabi tutulması, gerektiğinde yetkili personelce doğrulanması ve sahte evrak riskinin azaltılması,</li>
            <li>Taşıma esnasında yükün güvenliğinin sağlanması amacıyla sürücünün izin verdiği konumun aktif sevkiyat süresince gerekli kapsamda ilgili göndericiye gösterilmesi,</li>
            <li data-clause="bildirim-tercihi">Premium sürücülere aracına uygun yeni ilan yayınlandığında uygulama içi bildirim ve e-posta gönderilmesi (yeni ilan e-postaları şoför panelinden her zaman kapatılıp açılabilir; işlemsel bildirimler hizmetin gereğidir),</li>
            <li><strong>Ulaştırma ve Altyapı Bakanlığı U-ETDS</strong> bildirimlerinin ilgili işlem ve kullanıcı bakımından yükümlülük doğduğu ölçüde yürütülmesi,</li>
            <li><strong>BDDK/TCMB lisanslı ödeme kuruluşları ve banka altyapıları</strong> üzerinden tahsilat, mutabakat, iade ve sürücü ödeme süreçlerinin yürütülmesi,</li>
            <li>Sistem genelinde tahsil edilen aracılık komisyonlarının ve aylık aboneliklerin yasal olarak faturalandırılması ve muhasebeleştirilmesi,</li>
            <li>Müşteri ilişkileri süreçlerinin yürütülmesi, destek taleplerinin alınması ve uyuşmazlıkların çözümlenmesi.</li>
        </ul>
    </div>

    <!-- Madde 4: Kişisel Veri Toplamanın Yöntemi ve Hukuki Sebebi -->
    <div class="space-y-3">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 4: Kişisel Veri Toplamanın Yöntemi ve Hukuki Sebebi</h3>
        <p>Kişisel verileriniz, <strong>navluniq.com</strong> web sitesi, mobil uygulamalar, PWA konum servisleri ve yapay zeka destekli evrak analiz araçları dahil olmak üzere, kullanıcı işlemleri ve yetkilendirilmiş entegrasyonlar vasıtasıyla ağırlıklı olarak elektronik ortamda toplanmaktadır. Kişisel verilerinizin işlenmesindeki hukuki sebeplerimiz şunlardır:</p>
        <ul class="list-disc pl-5 space-y-1.5">
            <li><strong>Sözleşmenin Kurulması ve İfası (KVKK m. 5/2-c):</strong> Üyelik işlemlerinin tamamlanması, yük ve sürücü eşleşmelerinin yapılması, taşıma sürecinin koordinasyonu ve güvenli ödeme süreçlerinin yönetilmesi.</li>
            <li><strong>Veri Sorumlusunun Hukuki Yükümlülüğü (KVKK m. 5/2-ç):</strong> Faturalandırma, vergi beyannameleri, resmi makamların taleplerine uyum, U-ETDS ve taşımacılık mevzuatından kaynaklanan bildirim yükümlülükleri.</li>
            <li><strong>Açık Rıza (KVKK m. 5/1 ve m. 6/2):</strong> İlgili işlem için açık rıza gerektiği ölçüde aktif sevkiyat konumunun işlenmesi ve ayrıca etkinleştirilmesi halinde biyometrik veri (profil fotoğrafı ve selfie) doğrulaması.</li>
            <li data-clause="dis-kaynak"><strong>Meşru Menfaat (KVKK m. 5/2-f) – Dış Kaynak İlanları:</strong> Herkese açık ya da yöneticisinin paylaşıma izin verdiği taşımacılık gruplarında (WhatsApp ve Facebook grupları dahil) alenen paylaşılan yük ilanları, ilan sahibiyle iletişim kurulabilmesi amacıyla derlenir; yalnız ilan metni (güzergâh, yük, araç, fiyat) ve ilan sahibinin iletişim numarası standart ilan kartına dönüştürülerek işlenir, gönderen adı saklanmaz. İletişim numarası şifreli saklanır, herkese açık gösterimlerde maskelenir ve yalnız kimlik ve belge doğrulaması tamamlanmış premium sürücülere gösterilir; bu sürücüler numarayı yalnız o ilan için ilan sahibiyle iletişim amacıyla kullanır, üçüncü kişilerle paylaşamaz (Kullanıcı Sözleşmesi md. 3.4). İlanlar en geç 30 gün sonunda listeden kaldırılır. İlan sahibi, <strong>{{COMPANY_EMAIL}}</strong> adresine veya iletişim formuna yazarak ilanının derhal kaldırılmasını isteyebilir; talep en geç 48 saat içinde yerine getirilir.</li>
        </ul>
    </div>

    <!-- Madde 5: Verilerin Saklanması ve Alınan Siber Tedbirler -->
    <div class="space-y-3">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 5: Verilerin Saklanması ve Alınan Siber Tedbirler</h3>
        <p>
            Kişisel verileriniz, yasal saklama süreleri ve ticari mutabakat sınırları çerçevesinde muhafaza edilir. Evrak görselleriniz (ehliyet, SRC vb.) doğrudan herkese açık erişime kapalı <strong>kyc_private</strong> depolama alanında erişim kontrolleri uygulanarak tutulur. Dosya bütünlüğü kontrollerinde <strong>SHA-256</strong>, kullanıcı parolalarının tek yönlü özetlenmesinde <strong>Bcrypt</strong> veya Laravel tarafından desteklenen güncel güvenli algoritmalar kullanılır; oturum ve sıfırlama anahtarları amaçlarına uygun güvenlik tedbirleriyle korunur.
        </p>
    </div>

    <!-- Madde 6: İşlenen Kişisel Verilerin Aktarılması -->
    <div class="space-y-3">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 6: İşlenen Kişisel Verilerin Aktarılması</h3>
        <p>
            Kişisel verileriniz, hukuki dayanak ve gerekli bilgilendirme olmaksızın üçüncü kişilerin bağımsız reklam veya pazarlama amaçları için aktarılmaz. Veriler; hizmetin gerektirdiği ölçüde sözleşmeli lisanslı ödeme kuruluşu ve bankalar, iletişim altyapısı sağlayıcıları, yetkili sigorta ve e-belge iş ortakları, Ulaştırma Bakanlığı (U-ETDS) ve usulüne uygun bilgi talep eden adli/idari kurumlarla Kanun’un 8. ve 9. maddelerindeki şartlara uygun olarak paylaşılabilir.
        </p>
    </div>

    <!-- Madde 7: Veri Sahibi Olarak Haklarınız -->
    <div class="p-4 bg-neutral-50 dark:bg-neutral-900/60 rounded-xl border border-neutral-200 dark:border-neutral-800 space-y-2">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 7: Veri Sahibi Olarak Haklarınız (KVKK Madde 11)</h3>
        <p>
            Kanun'un 11. maddesi uyarınca <strong>{{COMPANY_EMAIL}}</strong> adresimize veya ilan edilen diğer başvuru kanallarına usulüne uygun şekilde başvurarak; verilerinizin işlenip işlenmediğini öğrenme, işlenme amacına uygun kullanılıp kullanılmadığını sorma, eksik veya yanlış işlenmişse düzeltilmesini isteme ve kanuni şartları oluştuğunda silinmesini veya yok edilmesini (<strong>Unutulma Hakkı</strong>) talep etme haklarına sahipsiniz. Başvurularınız, kimlik doğrulaması ve uygulanabilir mevzuattaki süre ve ücret kuralları çerçevesinde sonuçlandırılır; yasal saklama zorunluluğu bulunan kayıtlar bu süre boyunca korunabilir.
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
        Lütfen bu sözleşmeyi onaylamadan önce tüm içeriği dikkatlice okuyun. Sözleşme, güncel metin ve sürüm kullanıcıya gösterildikten sonra onay işleminin sistem kayıtlarına alınmasıyla yürürlüğe girer; yalnızca platformu ziyaret etmek sözleşmenin kabul edildiği anlamına gelmez.
    </p>

    <!-- Madde 1: Taraflar ve Tanımlar -->
    <div class="p-4 bg-neutral-50 dark:bg-neutral-900/60 rounded-xl border border-neutral-200 dark:border-neutral-800 space-y-2">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 1: Taraflar ve Tanımlar</h3>
        <ul class="space-y-1.5 text-xs">
            <li><strong>1.1 Hizmet Sağlayıcı:</strong> {{COMPANY_ADDRESS}} adresinde mukim <strong>{{COMPANY_NAME}}</strong> (“NavlunIQ”, Ticaret Sicil No {{COMPANY_TRADE_REGISTRY_NO}}, MERSİS {{COMPANY_MERSIS_NO}}).</li>
            <li><strong>1.2 Sürücü (Şoför):</strong> Ticari taşımacılık yapmaya yetkili olan ve platform aracılığıyla yük taşıma teklifi sunan gerçek kişi kullanıcıyı ifade eder.</li>
            <li><strong>1.3 Gönderici (Yük Sahibi):</strong> Platform üzerinden navlun ilanı yayınlayarak yükünün taşınmasını talep eden gerçek veya tüzel kişi kullanıcıyı ifade eder.</li>
        </ul>
    </div>

    <!-- Madde 2: Sözleşmenin Konusu ve Kapsamı -->
    <div class="space-y-2">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 2: Sözleşmenin Konusu ve Kapsamı</h3>
        <p>
            İşbu sözleşmenin konusu; NavlunIQ'nun göndericiler ile sürücüleri akıllı eşleşme, yapay zeka destekli evrak ön inceleme, izinli konum takibi ve lisanslı ödeme kuruluşları üzerinden yürütülen güvenli ödeme altyapısıyla buluşturduğu dijital lojistik platformunun kullanım şartlarının, tarafların karşılıklı hak, borç ve sorumluluklarının belirlenmesidir.
        </p>
    </div>

    <!-- Madde 3: Platformun Hukuki Niteliği ve Sorumluluk Sınırı -->
    <div class="space-y-3">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 3: Platformun Hukuki Niteliği ve Sorumluluk Sınırı (Aracılık Beyanı)</h3>
        <ul class="list-disc pl-5 space-y-2">
            <li><strong>3.1 Teknoloji Sağlayıcı Beyanı:</strong> NavlunIQ, göndericiler ile sürücüleri dijital ortamda buluşturan ve sunduğu hizmetin niteliği ölçüsünde 6563 sayılı Kanun ve ilgili mevzuat kapsamında <strong>"Aracı Hizmet Sağlayıcı"</strong> olarak faaliyet gösterir. NavlunIQ, açıkça ayrıca üstlenmediği işlemlerde yükün fiili taşıyıcısı, kargo firması veya taşıma işleri organizatörü sıfatıyla hareket etmez.</li>
            <li><strong>3.2 Sorumluluk Muafiyeti:</strong> Fiili taşıma, kural olarak yük sahibi ile taşıma hizmetini üstlenen sürücü/taşıyıcı arasındaki anlaşmaya göre yürütülür. Taraflar kendi beyan, belge, yükleme, taşıma ve teslim yükümlülüklerinden sorumludur. Bu hüküm NavlunIQ’nun kendi kusuru, veri güvenliği, ödeme yönlendirmesi veya emredici mevzuattan doğan sorumluluklarını kaldırmaz. İsteğe bağlı sigorta yalnız yetkili sağlayıcının poliçe düzenlemesiyle teminat oluşturur.</li>
            <li data-clause="dis-kaynak"><strong>3.3 Dış Kaynak İlanları:</strong> Paylaşımına izin verilen taşımacılık gruplarından derlenen ilanlar bilgilendirme amacıyla, yalnız belge doğrulaması tamamlanmış sürücülere gösterilir. Bu ilanlarda NavlunIQ taraf, aracı veya taşıma organizatörü değildir; teklif, anlaşma ve ödeme doğrudan ilan sahibi ile sürücü arasında yapılır ve NavlunIQ güvenli ödeme sistemi kapsamına girmez. NavlunIQ ilan içeriğinin doğruluğunu, ilan sahibinin kimliğini veya taşımanın ifasını garanti etmez. Buna karşılık NavlunIQ, platform sürücülerinin bu ilanlar kapsamındaki davranışlarından doğan şikâyetleri inceler; kanıtlanan ihlallerde uyarı, askıya alma ve kalıcı engelleme dahil önlemler alır ve mağdur tarafa kayıtlarını mevzuat çerçevesinde sunarak destek olur. İlan sahibi, ilanının kaldırılmasını her zaman isteyebilir.</li>
            <li data-clause="dis-kaynak-gizlilik"><strong>3.4 Dış Kaynak İlan Bilgilerinin Gizliliği:</strong> Dış kaynak ilanlarında gösterilen iletişim numarası ve ilan bilgileri yalnız premium sürücüye, yalnız o ilan için ilan sahibiyle iletişim kurmak amacıyla sunulur. Sürücü bu bilgileri kopyalayamaz, listeleyemez, başka grup, kanal, uygulama veya kişilere aktaramaz, reklam ya da toplu mesaj amacıyla kullanamaz ve üçüncü kişilerle hiçbir biçimde paylaşamaz. Bu yükümlülüğün ihlali halinde premium üyelik ve hesap, kalan süre için iade yapılmaksızın derhal sonlandırılır; NavlunIQ zarar gören ilan sahibinin yasal başvurularına kayıtlarıyla destek olur.</li>
        </ul>
    </div>

    <!-- Madde 4: Üyelik Koşulları, Çift Rol ve Kimlik Doğrulama (KYC) -->
    <div class="space-y-3">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 4: Üyelik Koşulları, Çift Rol ve Kimlik Doğrulama (KYC)</h3>
        <ul class="list-disc pl-5 space-y-2">
            <li><strong>4.1 KYC Doğrulaması:</strong> Platformun tüm özelliklerine erişebilmek için kullanıcıların KYC evrak onay süreçlerini başarıyla tamamlaması gerekir. Sürücülerin yasal belgeleri (Ehliyet, SRC vb.) ve Göndericilerin belgeleri (Vergi Levhası vb.) yapay zeka OCR teknolojisiyle ön incelemeye tabi tutulabilir ve gerektiğinde yetkili personelce doğrulanır. Sahte veya geçersiz evrak şüphesinde hesap orantılı biçimde sınırlandırılabilir; kanuni bildirim yükümlülüğü veya yetkili makam talebi bulunursa ilgili mercilere bilgi verilebilir.</li>
            <li><strong>4.2 Çift Rol (Multi-Account) Kuralı:</strong> Aynı telefon numarasıyla hem şoför hem yük sahibi hesabı açılabilir. Ancak güvenlik amacıyla, panel içinden diğer hesaba geçiş yapmak isteyen kullanıcıların kayıtlı e-posta adresine gönderilecek olan tek kullanımlık <strong>E-posta OTP</strong> doğrulama kodunu başarıyla doğrulamaları şarttır.</li>
            <li><strong>4.3 Platform İçi İletişim ve Harici Anlaşma Yasağı:</strong> Yük sahibi ve sürücü arasındaki operasyonel iletişimin güvenlik ve kayıt bütünlüğü için sistem üzerindeki mesajlaşma modülünden yürütülmesi esastır. Platform hizmet bedelini haksız biçimde bertaraf etmeye yönelik doğrulanmış ihlallerde olayın niteliğiyle orantılı hesap tedbirleri uygulanabilir ve kullanıcıya itiraz imkanı sağlanır. Uyuşmazlıklarda platform kayıtları diğer hukuka uygun delillerle birlikte değerlendirilebilir.</li>
            <li data-clause="bildirim-tercihi"><strong>4.4 Bildirimler ve E-posta Tercihi:</strong> Teklif sonucu, ödeme, belge ve hesap güvenliği gibi işlemsel bildirimler hizmetin gereği olarak gönderilir. Premium sürücülere aracına uygun yeni ilan yayınlandığında uygulama içi bildirim ve e-posta gönderilir; sürücü yeni ilan e-postalarını şoför panelindeki Premium sayfasından veya Profil → Bildirim tercihleri'nden istediği zaman kapatabilir ve yeniden açabilir. Kapatma, uygulama içi bildirimleri ve premium haklarını etkilemez.</li>
        </ul>
    </div>

    <!-- Madde 5: Güvenli Ödeme Sistemi ve Komisyon Kuralları -->
    <div class="space-y-3">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 5: Güvenli Ödeme Sistemi ve Komisyon Kuralları</h3>
        <ul class="list-disc pl-5 space-y-2">
            <li><strong>5.1 Teslimat Onaylı Ödeme:</strong> Gönderici, anlaşılan navlun bedelini platformun sözleşmeli olduğu lisanslı ödeme kuruluşunun güvenli ödeme altyapısı üzerinden öder. NavlunIQ, taraflar adına para tutan bir ödeme kuruluşu değildir; tahsilat, saklama ve sürücüye ödeme işlemleri ilgili lisanslı ödeme kuruluşu ve bankalar tarafından kendi mevzuat ve işlem kurallarına göre yürütülür. Teslim onay belgesinin (POD) sisteme yüklenmesi, göndericinin onayı ve açık bir uyuşmazlık bulunmaması halinde sürücü ödemesi, platform hizmet bedeli düşülerek sürücünün kayıtlı banka hesabına yapılır.</li>
            <li><strong>5.2 24 Saatlik Otomatik Onay Kuralı:</strong> Sürücü, teslim onay belgesini sisteme yüklediği andan itibaren <strong>24 saat içerisinde</strong> yük sahibi onay veya itiraz belirtmezse sevkiyat sistemde onaylanabilir ve sürücü ödemesi başlatılabilir. Ödemenin banka hesabına geçme zamanı ödeme kuruluşu ve banka işlem takvimine bağlıdır.</li>
            <li><strong>5.3 Komisyon ve Bilgi Ücreti:</strong> Başarıyla eşleşen her ilan ve navlun mutabakatı üzerinden NavlunIQ, işlem öncesinde oranı ve vergileri açıkça gösterilen bir "Aracılık Hizmet Komisyonu" tahsil edebilir.</li>
        </ul>
    </div>

    <!-- Madde 6: İptal, İade ve Uyuşmazlık Çözüm Protokolü (Dispute) -->
    <div class="space-y-3">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 6: İptal, İade ve Uyuşmazlık Çözüm Protokolü (Dispute)</h3>
        <ul class="list-disc pl-5 space-y-2">
            <li><strong>6.1 Fiziksel Yükleme Öncesi İptal:</strong> Fiziksel yükleme işlemi başlamadan önce gönderici ilanı tek tıkla iptal edebilir; bu durumda tahsil edilmiş bedel, işlemin durumu ve uygulanabilir ödeme/iade kuralları çerçevesinde göndericiye iade edilir.</li>
            <li><strong>6.2 Kriz ve Uyuşmazlık İnceleme Süreci:</strong> Yükleme onaylandıktan sonra meydana gelen kriz veya hasar durumlarında süreç kilitlenir. NavlunIQ <strong>"Kriz ve Uyuşmazlık Merkezi"</strong> yönetim ekranından, sürücünün teslimat kanıtlarını (anlık konum ve fotoğraflar) ve göndericinin hasar iddialarını inceleyerek sunulan kayıtlar çerçevesinde platform içi bir değerlendirme yapar; tutarın tamamen veya kısmen göndericiye iadesi ya da sürücüye ödenmesi için ödeme kuruluşu nezdinde işlem başlatabilir. Bu değerlendirme tarafların mahkeme, tüketici hakem heyeti, ödeme itirazı ve diğer kanuni başvuru haklarını ortadan kaldırmaz.</li>
        </ul>
    </div>

    <!-- Madde 7: Sürücünün Taahhüt ve Sorumlulukları -->
    <div class="space-y-2">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 7: Sürücünün Taahhüt ve Sorumlulukları</h3>
        <p>
            Sürücü, taşıyacağı yükün cinsine uygun geçerli dorse, tır ruhsatı ve K Yetki Belgesine sahip olduğunu, yükü gerekli özenle, mevzuata ve taraflarca kararlaştırılan teslim koşullarına uygun biçimde taşımakla yükümlüdür. Hırsızlık, sahtecilik veya diğer hukuka aykırı fiil şüphesinde platform hesabı sınırlandırabilir, kanıtları koruyabilir ve gerekli hallerde yetkili mercilere başvurabilir.
        </p>
    </div>

    <!-- Madde 8: Fikri Mülkiyet ve Veri Güvenliği -->
    <div class="space-y-2">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 8: Fikri Mülkiyet ve Veri Güvenliği</h3>
        <p>
            NavlunIQ platformunun yazılım kodları, özgün tasarımı ve algoritmaları üzerindeki haklar NavlunIQ veya ilgili hak sahiplerine aittir; dış kaynaklı içeriklerin hakları kendi sahiplerinde kalır. Yazılı izin veya hukuki dayanak olmaksızın verilerin kazınması (scraping), kopyalanması ya da robot yazılımlarla taranması halinde erişim sınırlandırılabilir ve mevzuatın izin verdiği hukuki yollara başvurulabilir.
        </p>
    </div>

    <!-- Madde 9: Uygulanacak Hukuk ve Yetkili Mahkeme -->
    <div class="p-4 bg-neutral-50 dark:bg-neutral-900/60 rounded-xl border border-neutral-200 dark:border-neutral-800 space-y-2">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 9: Uygulanacak Hukuk ve Yetkili Mahkeme</h3>
        <p class="text-xs">
            İşbu sözleşmenin uygulanmasında, yorumlanmasında ve uyuşmazlıkların çözümünde Türkiye Cumhuriyeti Kanunları uygulanacaktır. Yetkiye ilişkin emredici hükümler ve tüketicilerin kanuni başvuru hakları saklı olmak üzere, uyuşmazlıklarda <strong>Ankara Mahkemeleri ve Ankara İcra Daireleri</strong> yetkili olabilir.
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
        <strong>{{COMPANY_NAME}}</strong> (“NavlunIQ” veya “Şirket”) olarak, kullanıcılarımızın kişisel verilerinin gizliliğini ve güvenliğini korumaya yönelik idari ve teknik tedbirler uyguluyoruz. Bu Gizlilik Politikası, navluniq.com web sitesi ve platform uygulamaları üzerinden işlenen verilerin saklanma, korunma ve imha edilme kriterlerini ayrıntılı olarak açıklar.
    </p>

    <!-- Madde 1: Veri Toplama Yöntemleri ve Amaçları -->
    <div class="space-y-3">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 1: Veri Toplama Yöntemleri ve Amaçları</h3>
        <p>Platformumuzun akıllı eşleşme ve güvenli lojistik hizmetlerini sunabilmesi amacıyla aşağıdaki veriler doğrudan kullanıcı girişiyle veya kullanıcı izinlerine bağlı cihaz özellikleri aracılığıyla, hizmetin gerektirdiği ölçüde toplanabilmektedir:</p>
        <ul class="list-disc pl-5 space-y-1.5">
            <li><strong>Kişisel Tanımlayıcılar:</strong> Ad, soyad, telefon numarası, parola özetleri, sürücü ehliyeti, SRC belgesi ve vergi levhası görselleri ile bu görsellerden AI OCR teknolojisiyle yardımcı olarak çıkarılan yasal kimlik ve tescil verileri.</li>
            <li><strong>İşlem Güvenliği Verileri:</strong> IP adresi, port bilgileri, web sitesi giriş-çıkış günlükleri (loglar), OTP şifre doğrulama günlükleri, cihaz marka/model ve tarayıcı bilgileri.</li>
        </ul>
    </div>

    <!-- Madde 2: Çerezler (Cookies) ve Çevrimiçi İzleme Teknolojileri -->
    <div class="space-y-2">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 2: Çerezler (Cookies) ve Çevrimiçi İzleme Teknolojileri</h3>
        <p>
            Oturumunuzun güvenli ve kesintisiz sürdürülebilmesi adına tarayıcınızda geçici çerezler saklanır. Bu çerezler platform içi tercihlerinizi, oturum anahtarlarınızı ve dil seçimlerinizi hafızada tutar. Oturum süresi güvenlik yapılandırmasına göre <strong>1440 dakikaya (24 saate)</strong> kadar belirlenebilir. Süre dolduğunda veya güvenlik gerektirdiğinde oturum sonlandırılabilir; çerezlerin saklanması tarayıcı ve kullanıcı tercihlerine göre değişebilir.
        </p>
    </div>

    <!-- Madde 3: Konum Bilgileri ve PWA Arka Plan Geolocation Protokolü -->
    <div class="space-y-2">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 3: Konum Bilgileri ve PWA Arka Plan Geolocation Protokolü</h3>
        <p>
            Sürücülerin konum takibi, gerekli cihaz izniyle ve aktif navlun sevkiyatı süresince PWA konum servisleri üzerinden yapılır. Uygulama hareket ve sevkiyat durumuna göre veri iletim sıklığını azaltabilir; teslimat tamamlandığında aktif takip sonlandırılır. Toplanan konumlar yetkili ilgili göndericiye gerekli kapsamda gösterilir ve veritabanında <strong>MySQL Spatial POINT (SRID 4326)</strong> formatında erişim kontrolleri uygulanarak saklanır.
        </p>
    </div>

    <!-- Madde 4: Veri Saklama Süresi ve İmha Politikası (Unutulma Hakkı) -->
    <div class="space-y-2">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 4: Veri Saklama Süresi ve İmha Politikası (Unutulma Hakkı)</h3>
        <p>
            Kullanıcılarımızın, kişisel verilerinin sistemden tamamen kalıcı olarak silinmesini talep etme hakkı (unutulma hakkı) vardır. Veri silme taleplerinizi dilediğiniz an <strong>{{COMPANY_EMAIL}}</strong> adresimize iletebilirsiniz; talebiniz <strong>uygulanabilir mevzuattaki süreler içinde</strong> değerlendirilir. Yasal saklama yükümlülüğü veya devam eden uyuşmazlık bulunmayan veriler silinir, yok edilir ya da anonimleştirilir; yedeklerdeki kopyalar olağan yedek yaşam döngüsü içinde erişilemez hale getirilir.
        </p>
    </div>

    <!-- Madde 5: Veri Güvenliği ve Siber Korunma Tedbirleri -->
    <div class="space-y-2">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 5: Veri Güvenliği ve Siber Korunma Tedbirleri</h3>
        <p>
            Tarafımızca işlenen ehliyet, SRC ve vergi levhası gibi yasal evraklar, internetten doğrudan herkese açık erişime kapalı <strong>kyc_private</strong> depolama alanında yetki kontrolleriyle tutulur. Parolalar Laravel tarafından desteklenen güvenli tek yönlü özetleme algoritmalarıyla korunur; oturum ve şifre sıfırlama anahtarları amaçlarına uygun süre, erişim ve kötüye kullanım önleme kontrollerine tabidir.
        </p>
    </div>

    <!-- Madde 6: Verilerin Üçüncü Şahıslarla Paylaşımı -->
    <div class="p-4 bg-neutral-50 dark:bg-neutral-900/60 rounded-xl border border-neutral-200 dark:border-neutral-800 space-y-2">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 6: Verilerin Üçüncü Şahıslarla Paylaşımı</h3>
        <p class="text-xs">
            NavlunIQ, kullanıcı verilerini hukuki dayanak ve gerekli bilgilendirme olmaksızın üçüncü kişilerin bağımsız reklam amaçları için kullanmaz. Herkese açık taşımacılık gruplarından (WhatsApp, Facebook) derlenen dış kaynak ilanlarındaki iletişim numaraları üçüncü kişilere satılmaz, devredilmez ve yalnız belge doğrulaması tamamlanmış premium sürücülere, o ilan için ilan sahibiyle iletişim amacıyla gösterilir; premium sürücüler bu bilgileri üçüncü kişilerle paylaşmamayı Kullanıcı Sözleşmesi md. 3.4 ile taahhüt eder. Verileriniz hizmetin gerektirdiği ölçüde sözleşmeli lisanslı ödeme kuruluşu ve bankalar, yetkili sigorta ve e-belge sağlayıcıları ile usulüne uygun bilgi talep eden adli/idari kurumlarla paylaşılabilir.
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
        İşbu sözleşme, 6502 sayılı Tüketicinin Korunması Hakkında Kanun ve Mesafeli Sözleşmeler Yönetmeliği hükümleri uyarınca, NavlunIQ platformu üzerinden satın alınan dijital Premium Sürücü Abonelikleri ve başarılı yük eşleşmelerinden tahsil edilen aracı hizmet komisyonlarının satış ve cayma koşullarını belirler.
    </p>

    <!-- Madde 1: Taraflar ve İletişim Bilgileri -->
    <div class="p-4 bg-neutral-50 dark:bg-neutral-900/60 rounded-xl border border-neutral-200 dark:border-neutral-800 space-y-2 text-xs">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 1: Taraflar ve İletişim Bilgileri</h3>
        <ul class="space-y-1">
            <li><strong>Satıcı / Aracı Hizmet Sağlayıcı:</strong> {{COMPANY_NAME}}</li>
            <li><strong>Adres:</strong> {{COMPANY_ADDRESS}}</li>
            <li><strong>E-Posta:</strong> {{COMPANY_EMAIL}} | <strong>Telefon:</strong> {{COMPANY_PHONE}}</li>
            <li><strong>Alıcı (Kullanıcı):</strong> navluniq.com üzerinde kayıtlı olan, dijital hizmet alan şoförler (sürücüler) ve yük sahipleri (göndericiler).</li>
        </ul>
    </div>

    <!-- Madde 2: Sözleşmeli Hizmetin Konusu, Bedeli ve Ödeme Şartları -->
    <div class="space-y-3">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 2: Sözleşmeli Hizmetin Konusu, Bedeli ve Ödeme Şartları</h3>
        <ul class="list-disc pl-5 space-y-2">
            <li><strong>2.1 Premium Sürücü Aboneliği:</strong> Sürücülere paylaşım izni doğrulanmış dış kaynak ilanlarına erişim, sistem ilanlarına 20 dakikaya kadar erken erişim ve bildirim özellikleri sağlayan, aylık <strong>satın alma ekranında gösterilen KDV dahil bedel</strong> üzerinden sunulan dijital üyelik hizmetidir.</li>
            <li><strong>2.2 Aracılık Hizmet Komisyonu:</strong> Sürücü ile Gönderici arasında platform vasıtasıyla başarılı bir şekilde eşleşen her navlun işlemi üzerinden, mutabakat bedeli üzerinden hesaplanan veya sabit olarak tahsil edilen aracı hizmet komisyonudur.</li>
            <li><strong>2.3 Tüm Komisyon ve Abonelik Bedelleri:</strong> lisanslı ödeme kuruluşları ve bankalar aracılığıyla sunulan kredi kartı, banka kartı ve havale/EFT gibi ödeme yöntemleriyle tahsil edilir; KDV dâhil e-Fatura/e-Arşiv belgesi yetkili e-belge sağlayıcısının başarılı yanıtı sonrasında oluşturularak panel ve/veya e-posta üzerinden sunulur.</li>
        </ul>
    </div>

    <!-- Madde 3: Cayma Hakkı Sınırları ve Dijital İade İstisnaları -->
    <div class="space-y-3">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 3: Cayma Hakkı Sınırları ve Dijital İade İstisnaları</h3>
        <ul class="list-disc pl-5 space-y-2">
            <li><strong>3.1 Premium Abonelik İade Sınırı:</strong> NavlunIQ Premium Sürücü Aboneliği elektronik ortamda sunulan dijital bir hizmettir. Cayma hakkına ilişkin <em>"elektronik ortamda anında ifa edilen hizmetler veya tüketiciye anında teslim edilen gayri maddi mallar"</em> istisnası, yalnız yürürlükteki mevzuatın aradığı bilgilendirme, açık talep/onay ve diğer koşullar oluştuğu ölçüde uygulanır; kullanıcının emredici kanuni hakları saklıdır.</li>
            <li><strong>3.2 Komisyon İade Sınırı:</strong> Sürücü ve Gönderici arasında eşleşme gerçekleştikten ve yükleme onaylandıktan sonra, platform aracılık hizmetinin ifa edilen kısmı işlem kayıtlarına göre belirlenir. İptal halinde komisyonun iadesi; hizmetin gerçekleşme düzeyi, kusur, işlem öncesi gösterilen koşullar ve emredici mevzuat dikkate alınarak değerlendirilir.</li>
        </ul>
    </div>

    <!-- Madde 4: İhtilafların Çözümü ve Yetkili Mahkemeler -->
    <div class="p-4 bg-neutral-50 dark:bg-neutral-900/60 rounded-xl border border-neutral-200 dark:border-neutral-800 space-y-2">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 4: İhtilafların Çözümü ve Yetkili Mahkemeler</h3>
        <p class="text-xs">
            İşbu sözleşmeden doğabilecek her türlü ihtilafta, T.C. Ticaret Bakanlığı tarafından ilan edilen parasal sınırlar ve emredici yetki kuralları çerçevesinde Alıcının yerleşim yerindeki <strong>Tüketici Hakem Heyetleri, Tüketici Mahkemeleri ve diğer kanunen yetkili merciler</strong> görevli ve yetkili olabilir.
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
        NavlunIQ platformu üzerinde gerçekleştirilen yük ilan iptalleri, ödeme iadeleri ve teslimat onaylı ödeme işlemlerine ait genel esaslar bu politikada açıklanmıştır; emredici mevzuat ve işlem öncesi özel koşullar saklıdır.
    </p>

    <!-- Madde 1: Genel İptal ve İade Şartları -->
    <div class="space-y-2">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 1: Genel İptal ve İade Şartları</h3>
        <p>
            NavlunIQ üzerinden uygun nitelikteki finansal işlemler, alıcı ve satıcı haklarını korumaya yönelik, lisanslı ödeme kuruluşları ve bankalar üzerinden işletilen güvenli ödeme altyapısıyla yürütülür. Hizmetin gerçekleşmemesi veya taraflardan birinin kusurunun doğrulanması halinde iade ve hak ediş süreçleri bu politika, işlem kayıtları ve uygulanabilir mevzuata göre işletilir.
        </p>
    </div>

    <!-- Madde 2: Yük İlan İptalleri ve Navlun Bedeli İadesi -->
    <div class="space-y-3">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 2: Yük İlan İptalleri ve Navlun Bedeli İadesi</h3>
        <ul class="list-disc pl-5 space-y-2">
            <li><strong>2.1 Fiziksel Yükleme Öncesi İptal:</strong> Sürücü ile anlaşma sağlanıp süreç başlatıldıktan sonra, yükleme fiziksel olarak başlamadan önce gönderici ilanı tek tıkla iptal etme hakkına sahiptir. Bu durumda tahsil edilmiş navlun bedeli, işlemin durumu ve uygulanabilir ödeme/iade kuralları çerçevesinde göndericinin ödeme aracına iade edilir.</li>
            <li><strong>2.2 Yükleme Onayı Sonrası İptal:</strong> Yükleme işlemi onaylandıktan sonra gerçekleştirilecek iptaller tarafların ortak rızasına tabidir. Sürücü kusurundan ötürü (yükleme noktasına gelmeme, sahte evrak tespiti vb.) yaşanan iptallerde iade ve sürücüye uygulanabilecek hesap tedbirleri, kanıtlanan kusur ve olayın niteliğiyle orantılı olarak belirlenir.</li>
        </ul>
    </div>

    <!-- Madde 3: Komisyon ve Abonelik Bedeli İadeleri -->
    <div class="space-y-3">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 3: Komisyon ve Abonelik Bedeli İadeleri</h3>
        <ul class="list-disc pl-5 space-y-2">
            <li><strong>3.1 Komisyon İadesi:</strong> Platform komisyonunun iade edilip edilmeyeceği, aracılık hizmetinin gerçekleşen kısmı, iptal nedeni, kusur, işlem öncesi bildirilen koşullar ve emredici mevzuata göre belirlenir. Mücbir sebeplerde olayın etkisi ve ilgili sağlayıcı maliyetleri ayrıca değerlendirilir.</li>
            <li><strong>3.2 Abonelik İadesi:</strong> Aylık Premium Sürücü Aboneliğinin iptali gelecek dönem yenilemesini durdurur. Mevcut dönem iadesi, hizmetin kullanım durumu, satın alma sırasında verilen onaylar ve emredici tüketici mevzuatına göre değerlendirilir.</li>
        </ul>
    </div>

    <!-- Madde 4: İade Süreçleri ve Ödeme Kanalları -->
    <div class="p-4 bg-neutral-50 dark:bg-neutral-900/60 rounded-xl border border-neutral-200 dark:border-neutral-800 space-y-2">
        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Madde 4: İade Süreçleri ve Ödeme Kanalları</h3>
        <p class="text-xs">
            Onaylanan iade işlemleri, mümkün olduğunda ödemenin yapıldığı kredi kartına veya banka hesabına <strong>ödemenin alındığı ödeme altyapısı üzerinden iletilir</strong>. İadenin hesaba yansıma süresi ödeme kuruluşu ve ilgili bankanın işlem takvimine göre değişebilir; NavlunIQ işlem durumunu izler ve kendi kontrolündeki sorunlar için destek sağlar.
        </p>
    </div>
</div>
HTML;

        // Veritabanına kaydet
        CmsContent::updateOrCreate(['key' => 'contract_kvkk'], ['value' => strtr($kvkk, $tokens)]);
        CmsContent::updateOrCreate(['key' => 'contract_terms'], ['value' => strtr($terms, $tokens)]);
        CmsContent::updateOrCreate(['key' => 'contract_privacy'], ['value' => strtr($privacy, $tokens)]);
        CmsContent::updateOrCreate(['key' => 'contract_distance_sale'], ['value' => strtr($distanceSale, $tokens)]);
        CmsContent::updateOrCreate(['key' => 'contract_cancellation'], ['value' => strtr($cancellation, $tokens)]);
    }
}
