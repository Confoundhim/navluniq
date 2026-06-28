import { useParams, Link } from "react-router";
import { Shield, FileText, Lock, ShoppingCart, RotateCcw, ArrowLeft } from "lucide-react";
import PublicLayout from "./PublicLayout";

const legalContent: Record<string, { title: string; icon: React.ElementType; content: string }> = {
  kvkk: {
    title: "KVKK Aydinlatma Metni",
    icon: Shield,
    content: `NavlunIQ olarak, 6698 sayili Kisisel Verilerin Korunmasi Kanunu ("KVKK") kapsaminda, kisisel verilerinizin korunmasina buyuk onem veriyoruz.

## 1. Veri Sorumlusu
NavlunIQ Teknoloji A.S., kisisel verilerinizin islenmesinden ve korunmasindan sorumludur.

## 2. Islenen Kisisel Veriler
Platform uzerinden toplanan kisisel verileriniz:
- Kimlik bilgileri (ad, soyad, T.C. kimlik no)
- Iletisim bilgileri (telefon, e-posta, adres)
- Sirket bilgileri (vergi no, vergi dairesi, sirket unvani)
- Arac ve ehliyet bilgileri (soforler icin)
- Lokasyon ve GPS verileri (sevkiyat sirasinda)
- Finansal bilgiler (IBAN, odeme gecmisi)

## 3. Veri Isleme Amaclari
Kisisel verileriniz asagidaki amaclarla islenmektedir:
- Platform uyesi kayit islemleri
- Yuk-sofor eslestirme hizmetleri
- Odeme ve havuz islemleri (PayTR uzerinden)
- KYC dogrulama surecleri
- Musteri hizmetleri ve destek
- Yasal yukumluluklerin yerine getirilmesi

## 4. Veri Aktarimi
Kisisel verileriniz, yalnizca asagidaki durumlarda ucuncu taraflarla paylasilir:
- PayTR (odemelerin islenmesi)
- NVI ve GIB (KYC dogrulama)
- Yetkili kamu kurumlari (yasal talepler)

## 5. Haklariniz
KVKK kapsaminda asagidaki haklara sahipsiniz:
- Bilgi talep etme
- Duzeltme talep etme
- Silinme talep etme
- Islenmeye itiraz etme
- Aktarimi engelleme

Bu haklarinizi kullanmak icin info@navluniq.com adresine basvurabilirsiniz.

## 6. Iletisim
Veri sorumlusuna basvuru icin:
E-posta: info@navluniq.com
Adres: Ankara, Turkiye`,
  },
  terms: {
    title: "Kullanici Sozlesmesi",
    icon: FileText,
    content: `## 1. Taraflar
Isbu Kullanici Sozlesmesi ("Sozlesme"), NavlunIQ Teknoloji A.S. ("NavlunIQ") ile platformu kullanan gercek ve/veya tuzel kisiler ("Kullanici") arasinda akdedilmistir.

## 2. Sozlesmenin Konusu
Bu Sozlesme, NavlunIQ platformunun kullanim sartlarini, taraflarin hak ve yukumluluklerini duzenler. Platform; yuk sahipleri ile tasiyici soforleri bir araya getiren dijital bir lojistik ekosistemidir.

## 3. Uyelik ve Kayit
- 18 yasindan buyuk bireyler uyelik olusturabilir.
- Gercek ve tuzel kisiler farkli uyelik tipleri secebilir.
- Kullanici, verdigi bilgilerin dogrulugundan sorumludur.
- Cift hesap (multi-account) destegi mevcuttur.

## 4. Hizmetler
NavlunIQ asagidaki hizmetleri sunar:
- Yuk ilani olusturma ve yayinlama
- Sofor-yuk eslestirme
- Havuz (escrow) odeme sistemi
- Canli sevkiyat takibi
- KYC belge dogrulama
- AI destekli ilan tarama

## 5. Odeme ve Komisyon
- Yuk sahipleri: %2.5 komisyon
- Soforler: %5 komisyon
- Premium uyelik: 900 TL/ay
- Tum odemeler PayTR guvencesiyle islenir.

## 6. Yururluk
Isbu Sozlesme, Kullanici'nin platforma kayit oldugu tarihte yururluge girer.

Son guncelleme: 01.01.2026`,
  },
  privacy: {
    title: "Gizlilik Politikasi",
    icon: Lock,
    content: `## 1. Giris
NavlunIQ, kullanicilarinin gizliligini en ust duzeyde korur. Bu politika, hangi verilerin toplandigini, nasil kullanildigini ve korundugunu aciklar.

## 2. Toplanan Veriler
- **Kimlik Bilgileri**: Ad, soyad, T.C. no
- **Iletisim**: Telefon, e-posta, adres
- **Lokasyon**: GPS koordinatlari (sevkiyat sirasinda)
- **Cihaz Bilgileri**: IP adresi, tarayici turu, isletim sistemi
- **Kullanim Verileri**: Platform etkilesimleri, tercihler

## 3. Cerezler (Cookies)
Platform, kullanici deneyimini iyilestirmek icin cerezler kullanir:
- **Zorunlu Cerezler**: Oturum yonetimi, guvenlik
- **Analitik Cerezler**: Kullanim istatistikleri
- **Tercih Cerezleri**: Dil, tema tercihleri

## 4. Veri Guvenligi
- 256-bit SSL sifreleme
- PayTR havuz sistemi
- Duzenli guvenlik denetimleri
- Veri ihlali bildirim proseduru

## 5. Haklariniz
- Verilerinize erisim talep etme
- Yanlis verilerin duzeltilmesi
- Verilerinizin silinmesi (unutma hakki)
- Veri islemeye itiraz etme

Iletisim: info@navluniq.com`,
  },
  "distance-sales": {
    title: "Mesafeli Satis Sozlesmesi",
    icon: ShoppingCart,
    content: `## 1. TARAFLAR
SATICI: NavlunIQ Teknoloji A.S. - Ankara/Turkiye
ALICI: Platform uzerinden hizmet satin alan gercek/tuzel kisi

## 2. SOZLESME KONUSU
Isbu sozlesme, 6502 sayili Tuketici Kanunu ve Mesafeli Sozlesmeler Yonetmeligi kapsaminda duzenlenmistir.

## 3. HIZMETIN TANIMI
NavlunIQ, yuk sahipleri ile tasiyici soforleri dijital platformda bir araya getiren araci hizmet saglayicisidir.

## 4. UCRET ve ODEME
- Premium Sofor Aboneligi: 900 TL/ay
- Komisyon Oranlari: Yuk sahibi %2.5, Sofor %5
- Odeme araci: PayTR (Havuz sistemi)

## 5. CAYMA HAKKI
Alici, hizmetin ifasindan once cayma hakkini kullanabilir. Dijital icerik ve hizmetlerde (Premium abonelik), elektronik ortamda aninda teslim edilen gayri maddi hizmetler icin cayma hakki bulunmamaktadir.

## 6. UYUSMAZLIK
Turkiye Cumhuriyeti yasalari uygulanir. Ankara Mahkemeleri yetkilidir.

Son guncelleme: 01.01.2026`,
  },
  refund: {
    title: "Iade Politikasi",
    icon: RotateCcw,
    content: `## 1. Genel Ilkeler
NavlunIQ, mustteri memnuniyetini on planda tutar. Ancak dijital hizmetlerin dogasi geregi iade kosullari asagidaki sekilde duzenlenmistir.

## 2. Iade Kosullari

### 2.1 Premium Abonelik
- Premium abonelik, elektronik ortamda aninda teslim edilen gayri maddi bir dijital hizmettir.
- Mesafeli Satis Sozlesmesi uyariunca geriye donuk cayma ve iade hakki bulunmamaktadir.
- Abonelik dilediginiz an iptal edilebilir, ancak odenen donem iade edilmez.
- Iptal sonrasi donem icin yeniden ucretlendirilmez.

### 2.2 Havuz (Escrow) Odemeleri
- Yuk sahibi tarafindan havuza yatirilan tutarlar, teslimat gerceklesmeden sofere aktarilmaz.
- Uyusmazlik durumunda admin karari gecerlidir.
- Haksiz yere bloke edilen tutarlar iade edilir.

### 2.3 Komisyon Iadeleri
- Platform komisyonlari (%2.5 yuk sahibi, %5 sofor) iade edilmez.
- Hizmet kullanilmadan iptal edilen ilanlarda komisyon iadesi yapilabilir.

## 3. Iade Sureci
1. Iade talebi info@navluniq.com adresine yazili olarak iletilir.
2. Talep 7 is gunu icinde degerlendirilir.
3. Onaylanan iadeler 14 is gunu icinde isleme alinir.
4. Iade, orijinal odeme araci uzerinden yapilir.

## 4. Iletisim
Iade talepleri icin: info@navluniq.com

Son guncelleme: 01.01.2026`,
  },
};

export default function LegalPage() {
  const { slug } = useParams<{ slug: string }>();
  const page = slug ? legalContent[slug] : null;

  if (!page) {
    return (
      <PublicLayout>
        <div className="min-h-[60vh] flex items-center justify-center">
          <div className="text-center">
            <p className="text-slate-500 mb-4">Sayfa bulunamadi.</p>
            <Link to="/" className="text-amber-500 hover:text-amber-400 text-sm">Anasayfaya Don</Link>
          </div>
        </div>
      </PublicLayout>
    );
  }

  const Icon = page.icon;
  const paragraphs = page.content.split("\n\n");

  return (
    <PublicLayout>
      <section className="pt-20 pb-16">
        <div className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
          {/* Back */}
          <a href="/#/" className="inline-flex items-center gap-1.5 text-slate-500 hover:text-slate-300 text-sm mb-6 transition-colors">
            <ArrowLeft className="w-4 h-4" /> Anasayfa
          </a>

          {/* Header */}
          <div className="flex items-center gap-3 mb-8">
            <div className="w-10 h-10 rounded-lg bg-amber-500/10 flex items-center justify-center">
              <Icon className="w-5 h-5 text-amber-500" />
            </div>
            <h1 className="text-2xl font-bold text-white tracking-tight">{page.title}</h1>
          </div>

          {/* Content */}
          <div className="space-y-4">
            {paragraphs.map((p, i) => {
              if (p.startsWith("## ")) {
                return <h2 key={i} className="text-lg font-semibold text-white mt-6">{p.replace("## ", "")}</h2>;
              }
              if (p.startsWith("- ")) {
                return (
                  <ul key={i} className="space-y-1 ml-4">
                    {p.split("\n").map((item, j) => (
                      <li key={j} className="flex items-start gap-2 text-slate-400 text-sm">
                        <span className="w-1 h-1 rounded-full bg-amber-500 mt-2 shrink-0" />
                        {item.replace("- ", "")}
                      </li>
                    ))}
                  </ul>
                );
              }
              return <p key={i} className="text-slate-400 text-sm leading-relaxed">{p}</p>;
            })}
          </div>

          {/* Last updated */}
          <p className="text-slate-600 text-xs mt-10 pt-4 border-t border-[#2E2E35]">Son guncelleme: 01.01.2026</p>
        </div>
      </section>
    </PublicLayout>
  );
}
