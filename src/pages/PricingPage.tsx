import { Crown, Check, X, Globe } from "lucide-react";
import PublicLayout from "./PublicLayout";

export default function PricingPage() {
  return (
    <PublicLayout>
      <section className="relative pt-20 pb-16 overflow-hidden">
        <div className="absolute inset-0 bg-gradient-to-br from-amber-500/10 via-[#0F0F12] to-[#0F0F12]" />
        <div className="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
          <span className="text-amber-500 text-xs font-semibold uppercase tracking-wider">Abonelik</span>
          <h1 className="text-4xl sm:text-5xl font-bold text-white mt-4 tracking-tight">Yuk Bulma Hizini <span className="text-amber-500">Zirveye Tasiyin</span></h1>
          <p className="text-slate-400 mt-4 max-w-2xl mx-auto">NavlunIQ'nun soforler icin gelistirdigi abonelik hizmetidir.</p>
        </div>
      </section>

      <section className="py-16">
        <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="grid md:grid-cols-2 gap-6">
            {/* Free */}
            <div className="bg-[#18181C] border border-[#2E2E35] rounded-2xl p-8 hover:border-[#3E3E45] transition-all">
              <div className="flex items-center gap-3 mb-4">
                <div className="w-10 h-10 rounded-xl bg-slate-500/10 flex items-center justify-center">
                  <Globe className="w-5 h-5 text-slate-400" />
                </div>
                <div>
                  <h3 className="text-white font-semibold text-lg">Ucretsiz Kanal</h3>
                  <p className="text-slate-500 text-xs">Telegram Haberlesme Rotali ilan akisi</p>
                </div>
              </div>
              <div className="mb-6">
                <span className="text-4xl font-bold text-white">0</span>
                <span className="text-slate-500 ml-1">TL / Ucretsiz</span>
              </div>
              <ul className="space-y-3 mb-8">
                {[
                  { text: "Web siteleri ve WhatsApp gruplarindan derlenen ilanlar", ok: true },
                  { text: "Telegram kanalimizda yaklasik 20 dakika icinde paylasilir", ok: true },
                  { text: "Sinirsiz sureyle katilim garantisi", ok: true },
                  { text: "Anlik bildirimler", ok: false },
                  { text: "WhatsApp Grup Entegrasyonu", ok: false },
                  { text: "Sofor kontrol paneline tam erisim", ok: false },
                ].map(f => (
                  <li key={f.text} className="flex items-start gap-2 text-sm">
                    {f.ok ? <Check className="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /> : <X className="w-4 h-4 text-slate-600 shrink-0 mt-0.5" />}
                    <span className={f.ok ? "text-slate-300" : "text-slate-600"}>{f.text}</span>
                  </li>
                ))}
              </ul>
              <button className="w-full py-3 bg-[#232328] hover:bg-[#2E2E35] text-white font-semibold rounded-xl border border-[#2E2E35] transition-all">
                Telegram Kanalimiza Katil
              </button>
            </div>

            {/* Premium */}
            <div className="relative bg-gradient-to-br from-amber-500/10 to-[#18181C] border border-amber-500/30 rounded-2xl p-8 hover:border-amber-500/50 transition-all">
              <div className="absolute -top-3 left-1/2 -translate-x-1/2 px-3 py-1 bg-amber-500 text-[#0F0F12] text-xs font-bold rounded-full">
                EN POPULER
              </div>
              <div className="flex items-center gap-3 mb-4">
                <div className="w-10 h-10 rounded-xl bg-amber-500/10 flex items-center justify-center">
                  <Crown className="w-5 h-5 text-amber-500" />
                </div>
                <div>
                  <h3 className="text-white font-semibold text-lg">Premium Uyelik</h3>
                  <p className="text-amber-500 text-xs">Hizli erisim, aninda bildirimler</p>
                </div>
              </div>
              <div className="mb-6">
                <span className="text-4xl font-bold text-white">900</span>
                <span className="text-slate-500 ml-1">TL / ay</span>
              </div>
              <ul className="space-y-3 mb-8">
                {[
                  { text: "Tum platform ve web ilanlarini panelinizden ANINDA gorun", ok: true },
                  { text: "WhatsApp, Telegram ve Web Push uzerinden anlik anons bildirimleri", ok: true },
                  { text: "WhatsApp Grup Entegrasyonu: Kendi gruplarinizi sisteme baglayin", ok: true },
                  { text: "Sofor kontrol paneline tam erisim saglayin", ok: true },
                  { text: "Sari Rozetli dis kaynak ilanlarina 20 dk erken erisim", ok: true },
                  { text: "AI ile otomatik ilan filtreleme ve temizleme", ok: true },
                ].map(f => (
                  <li key={f.text} className="flex items-start gap-2 text-sm">
                    <Check className="w-4 h-4 text-amber-500 shrink-0 mt-0.5" />
                    <span className="text-slate-300">{f.text}</span>
                  </li>
                ))}
              </ul>
              <button className="w-full py-3 bg-amber-500 hover:bg-amber-400 text-[#0F0F12] font-bold rounded-xl transition-all">
                Premium Sofor Ol
              </button>
            </div>
          </div>

          {/* FAQ */}
          <div className="mt-16 bg-[#18181C] border border-[#2E2E35] rounded-xl p-6">
            <h3 className="text-white font-semibold mb-4">Sikca Sorulan Sorular</h3>
            <div className="space-y-3">
              <div className="border-b border-[#2E2E35] pb-3">
                <p className="text-white text-sm font-medium mb-1">Ucretsiz Telegram Kanali ile Premium paneli arasindaki fark nedir?</p>
                <p className="text-slate-500 text-xs">Ucretsiz kanalimizda ilanlar yaklasik 20 dakika gecikmeyle paylasilir. Premium uyeler ise tum sicak ilanlari panelden anlik gorerek teklif verebilirler.</p>
              </div>
              <div className="border-b border-[#2E2E35] pb-3">
                <p className="text-white text-sm font-medium mb-1">Premium aboneliğimi iptal edebilir miyim?</p>
                <p className="text-slate-500 text-xs">Evet, dilediğiniz an tek tikla iptal edebilirsiniz. Ancak dijital hizmet oldugundan geriye donuk iade yapilamaz.</p>
              </div>
            </div>
          </div>
        </div>
      </section>
    </PublicLayout>
  );
}
