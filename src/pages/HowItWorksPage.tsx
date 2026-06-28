import { UserPlus, FileSearch, Handshake, CreditCard } from "lucide-react";
import PublicLayout from "./PublicLayout";

const shipperSteps = [
  { icon: FileSearch, title: "Yuk İlani Yayinlayin", desc: "İlan sihirbazimiz ile dorse tipi, rota ve butce stratejinizi girerek ilaninizi aninda acin." },
  { icon: CreditCard, title: "Guvenli Havuz Odemesi", desc: "Anlasma saglandiginda navlun bedeli PayTR guvencesiyle havuzda bloke edilir." },
  { icon: Handshake, title: "Secim Yapin ve Izleyin", desc: "Belgeleri onaylanan profesyonel soforlerden gelen teklifleri secin, sevkiyati haritadan anlik izleyin." },
];

const driverSteps = [
  { icon: UserPlus, title: "1 Dakikada Kaydolun", desc: "Kisisel bilgilerinizle ve gerekli belgeler ile hizlica kayit olun. Belgeleriniz AI OCR ile taranir." },
  { icon: FileSearch, title: "Filtre Ayarlarinizi Yapin", desc: "Web ilanlarindan, WhatsApp entegrasyonunuzdan ve sitemizde acilan ilanlari filtreleyin." },
  { icon: Handshake, title: "Teklif Verin ve Yola Cikin", desc: "Konumunuza veya rotaniza en uygun yuklere teklif verin. PayTR guvenceli havuz sistemiyle navlun bedeli garantilensin." },
];

export default function HowItWorksPage() {
  return (
    <PublicLayout>
      <section className="relative pt-20 pb-16 overflow-hidden">
        <div className="absolute inset-0 bg-gradient-to-br from-amber-500/10 via-[#0F0F12] to-[#0F0F12]" />
        <div className="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
          <span className="text-amber-500 text-xs font-semibold uppercase tracking-wider">Platform Rehberi</span>
          <h1 className="text-4xl sm:text-5xl font-bold text-white mt-4 tracking-tight">Nasil <span className="text-amber-500">Calisir?</span></h1>
          <p className="text-slate-400 mt-4 max-w-2xl mx-auto">NavlunIQ ekosisteminde soforlerin yuk bulma ve yuk sahiplerinin sevkiyat yonetimi surecleri tamamen dijitallesmistir.</p>
        </div>
      </section>

      {/* Shipper Flow */}
      <section className="py-16">
        <div className="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="text-center mb-12">
            <h2 className="text-2xl font-bold text-white">Yuk Sahipleri İcin</h2>
            <p className="text-slate-500 mt-2">Guvenilir soforlerle yuklerinizi yonetin.</p>
          </div>
          <div className="space-y-6">
            {shipperSteps.map((step, i) => {
              const Icon = step.icon;
              return (
                <div key={step.title} className="flex items-start gap-6 bg-[#18181C] border border-[#2E2E35] rounded-xl p-6 hover:border-[#3E3E45] transition-all">
                  <div className="w-14 h-14 rounded-xl bg-amber-500/10 flex items-center justify-center shrink-0">
                    <Icon className="w-7 h-7 text-amber-500" />
                  </div>
                  <div>
                    <div className="flex items-center gap-2 mb-1">
                      <span className="text-amber-500 text-xs font-bold">0{i + 1}</span>
                      <h3 className="text-white font-semibold text-lg">{step.title}</h3>
                    </div>
                    <p className="text-slate-400 text-sm">{step.desc}</p>
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      </section>

      {/* Driver Flow */}
      <section className="py-16 bg-[#0A0A0C]">
        <div className="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="text-center mb-12">
            <h2 className="text-2xl font-bold text-white">Soforler İcin</h2>
            <p className="text-slate-500 mt-2">Bos kilometre kaybi olmadan yuk bulun.</p>
          </div>
          <div className="space-y-6">
            {driverSteps.map((step, i) => {
              const Icon = step.icon;
              return (
                <div key={step.title} className="flex items-start gap-6 bg-[#18181C] border border-[#2E2E35] rounded-xl p-6 hover:border-[#3E3E45] transition-all">
                  <div className="w-14 h-14 rounded-xl bg-blue-500/10 flex items-center justify-center shrink-0">
                    <Icon className="w-7 h-7 text-blue-500" />
                  </div>
                  <div>
                    <div className="flex items-center gap-2 mb-1">
                      <span className="text-blue-500 text-xs font-bold">0{i + 1}</span>
                      <h3 className="text-white font-semibold text-lg">{step.title}</h3>
                    </div>
                    <p className="text-slate-400 text-sm">{step.desc}</p>
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      </section>
    </PublicLayout>
  );
}
