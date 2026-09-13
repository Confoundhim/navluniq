<?php

namespace Database\Seeders;

use App\Models\Faq;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FaqSeeder extends Seeder
{
    public function run(): void
    {
        $faqs = [
            [
                'order_num' => 1,
                'question' => 'Güvenli ödeme süreci nasıl çalışacak?',
                'answer' => 'Ödeme altyapısı kullanıma açıldığında tahsilat, iade ve sürücü hak edişleri yetkili ödeme kuruluşunun doğrulanmış işlem sonuçlarına göre yürütülecektir. Kesin aktarım süresi ve koşulları ödeme ekranında gösterilecektir.',
            ],
            [
                'order_num' => 2,
                'question' => 'KYC sürecinde hangi belgeler istenir?',
                'answer' => 'İstenen belgeler hesap türüne ve yürürlükteki mevzuata göre değişebilir. Belgeler yüklenmeden önce güncel liste, işleme amacı ve saklama koşulları kullanıcıya gösterilecektir. Otomatik analiz tek başına nihai onay anlamına gelmez.',
            ],
            [
                'order_num' => 3,
                'question' => 'Canlı konum takibi ne zaman kullanılır?',
                'answer' => 'Konum özelliği kullanıma açıldığında yalnız gerekli izinler alındıktan ve aktif sevkiyat başladıktan sonra çalışacaktır. Takip sıklığı, görünürlük ve saklama süresi kullanıcıya ayrıca bildirilecektir.',
            ],
            [
                'order_num' => 4,
                'question' => 'Premium Sürücü aboneliği hangi özellikleri içerir?',
                'answer' => 'Premium planın güncel fiyatı, deneme süresi ve erişim hakları satın alma öncesinde açıkça gösterilecektir. Henüz etkinleştirilmemiş özellikler abonelik kapsamında sunulmuş kabul edilmez.',
            ],
            [
                'order_num' => 5,
                'question' => 'Ücretsiz ve Premium erişim arasındaki fark nedir?',
                'answer' => 'Erişim farkları, ilan kaynağının paylaşım iznine ve aktif abonelik planına göre belirlenir. Güncel kapsam ve varsa erken erişim süresi plan ayrıntılarında açıklanacaktır.',
            ],
            [
                'order_num' => 6,
                'question' => 'Dış kaynaklı ilanlar sisteme nasıl alınır?',
                'answer' => 'Yalnız kullanım ve paylaşım izni doğrulanmış kaynaklardan alınan içerikler işlenebilir. Sistem benzer kayıtları ayıklamaya ve ilan alanlarını otomatik çıkarmaya çalışır; sonuçlar hatalı olabileceğinden kullanıcı doğrulaması gerekebilir.',
            ],
            [
                'order_num' => 7,
                'question' => 'NavlunIQ bir nakliye firması mıdır?',
                'answer' => 'NavlunIQ, yük sahipleri ile taşıma hizmeti sunan kullanıcıları dijital ortamda buluşturmayı amaçlayan bir teknoloji platformudur. Platformun hukuki rolü ve tarafların sorumlulukları güncel kullanıcı sözleşmesinde açıklanacaktır.',
            ],
            [
                'order_num' => 8,
                'question' => 'Uyuşmazlık süreci nasıl işler?',
                'answer' => 'Uyuşmazlık modülü kullanıma açıldığında taraflar açıklama ve kanıtlarını sisteme iletebilecektir. İade veya ödeme işlemleri sözleşme, ödeme kuruluşu kuralları ve uygulanabilir mevzuata göre değerlendirilecektir.',
            ],
            [
                'order_num' => 9,
                'question' => 'Aynı hesapla hem şoför hem yük sahibi olabilir miyim?',
                'answer' => 'Uygun profiller oluşturulduğunda aynı kullanıcı hesabı yük sahibi ve şoför panelleri arasında doğrulama koduyla geçiş yapabilir. Her rolün KYC ve yetki koşulları ayrıca uygulanır.',
            ],
            [
                'order_num' => 10,
                'question' => 'Yük sigortası otomatik olarak sağlanır mı?',
                'answer' => 'Hayır. Bir sigorta seçeneği sunulursa teminat sağlayıcısı, kapsam, istisnalar ve ücret kullanıcı onayından önce ayrıca gösterilecektir. Açıkça düzenlenmiş bir poliçe bulunmadan yük sigortalı kabul edilmemelidir.',
            ],
            [
                'order_num' => 11,
                'question' => 'Yüklediğim belgeler nasıl korunur?',
                'answer' => 'Belgelerin erişimi yetkilendirme, özel depolama ve kayıt izleme kontrolleriyle sınırlandırılacaktır. İşleme amacı, saklama süresi ve kullanıcı hakları güncel aydınlatma metninde açıklanacaktır.',
            ],
            [
                'order_num' => 12,
                'question' => 'Komisyonlar ve faturalar nasıl hesaplanır?',
                'answer' => 'Geçerli komisyon, vergi ve diğer ücretler işlem onayından önce kullanıcıya gösterilecektir. Resmi faturalar yalnız yetkili ERP/e-belge sağlayıcısından başarılı sonuç alındıktan sonra düzenlenmiş sayılacaktır.',
            ],
            [
                'order_num' => 13,
                'question' => 'Bir ilanı veya sevkiyatı nasıl iptal edebilirim?',
                'answer' => 'İptal koşulları işlemin aşamasına göre değişebilir. Henüz taşıma başlamadıysa ve ödeme oluşmadıysa ilan panelden kapatılabilir; ödeme veya sevkiyat başladıysa sözleşmedeki iptal ve iade kuralları uygulanır.',
            ],
            [
                'order_num' => 14,
                'question' => 'Premium abonelik nasıl iptal edilir?',
                'answer' => 'Abonelik kullanıma açıldığında iptal işlemi panelden yapılabilecektir. Dönem sonu, yenileme ve varsa iade koşulları satın alma öncesinde gösterilen sözleşmeye ve uygulanabilir mevzuata göre belirlenir.',
            ],
            [
                'order_num' => 15,
                'question' => 'Destek ekibine nasıl ulaşabilirim?',
                'answer' => 'Destek talepleri platformdaki destek formu veya yayımlanan resmi iletişim kanalları üzerinden iletilebilir. Güncel çalışma saatleri ve yanıt hedefleri iletişim sayfasında gösterilecektir.',
            ],
        ];

        DB::transaction(function () use ($faqs): void {
            foreach ($faqs as $faq) {
                Faq::updateOrCreate(
                    ['question' => $faq['question']],
                    [
                        'answer' => $faq['answer'],
                        'order_num' => $faq['order_num'],
                        'is_active' => true,
                    ]
                );
            }
        });
    }
}
