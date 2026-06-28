import { Truck, MapPin, Zap } from "lucide-react";
import PublicLayout from "./PublicLayout";

const services = [
  {
    icon: Zap,
    title: "NavlunIQ İlan Aboneligi",
    desc: "Yapay zeka radarimiz tarafindan WhatsApp gruplari ve web mecralarindan derlenen sicak yuk ilanlarina gercek zamanli erisim elde edin.",
    features: ["Otomatik AI İlan Tarama & Filtreleme", "Sari Rozetli Dis Kaynak İlanlarina Erisim", "Anlik Olarak Tum İlan Bildirimleri"],
    color: "text-amber-500",
    bg: "bg-amber-500/10",
  },
  {
    icon: Truck,
    title: "Sehir Ici Yuk Tasiciligi",
    desc: "Sehir ici kisa mesafeli ve acil sevkiyatlariniz icin optimize edilmis tasimacilik agi.",
    features: ["Dakikalar İcerisinde Sofoer Teklifleri", "Sehir Ici Rota Optimizasyonu", "Canli Takirli Guvenli Teslimat"],
    color: "text-blue-500",
    bg: "bg-blue-500/10",
  },
  {
    icon: MapPin,
    title: "Sehirler Arasi Yuk Tasiciligi",
    desc: "Turkiye geneli tum sehirler arasinda kesintisiz ve guvenli tasimacilik.",
    features: ["KYC Dogrulamali Guvenilir Sofoerler", "Arka Planda Kesintisiz Konum Takibi", "Akilli Donus Yuku Eslestirme Motoru"],
    color: "text-emerald-500",
    bg: "bg-emerald-500/10",
  },
];

export default function ServicesPage() {
  return (
    <PublicLayout>
      <section className="relative pt-20 pb-16 overflow-hidden">
        <div className="absolute inset-0 bg-gradient-to-br from-amber-500/10 via-[#0F0F12] to-[#0F0F12]" />
        <div className="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
          <span className="text-amber-500 text-xs font-semibold uppercase tracking-wider">Hizmetlerimiz</span>
          <h1 className="text-4xl sm:text-5xl font-bold text-white mt-4 tracking-tight">Kapsamli Lojistik <span className="text-amber-500">Cozumleri</span></h1>
          <p className="text-slate-400 mt-4 max-w-2xl mx-auto">Yuk sahipleri ve soforler icin tasarlanmis, yapay zeka destekli hizmetler.</p>
        </div>
      </section>

      <section className="py-16">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
            {services.map(s => {
              const Icon = s.icon;
              return (
                <div key={s.title} className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-6 hover:border-[#3E3E45] transition-all group">
                  <div className={`w-12 h-12 rounded-xl ${s.bg} flex items-center justify-center mb-4`}>
                    <Icon className={`w-6 h-6 ${s.color}`} />
                  </div>
                  <h3 className="text-white font-semibold text-lg mb-2">{s.title}</h3>
                  <p className="text-slate-400 text-sm mb-4">{s.desc}</p>
                  <ul className="space-y-2">
                    {s.features.map(f => (
                      <li key={f} className="flex items-center gap-2 text-slate-500 text-xs">
                        <span className="w-1 h-1 rounded-full bg-amber-500" />{f}
                      </li>
                    ))}
                  </ul>
                </div>
              );
            })}
          </div>
        </div>
      </section>
    </PublicLayout>
  );
}
