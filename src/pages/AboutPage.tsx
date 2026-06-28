import { ShieldCheck, Heart, Zap, Target, Truck } from "lucide-react";
import PublicLayout from "./PublicLayout";

export default function AboutPage() {
  return (
    <PublicLayout>
      {/* Hero */}
      <section className="relative pt-20 pb-16 overflow-hidden">
        <div className="absolute inset-0 bg-gradient-to-br from-amber-500/10 via-[#0F0F12] to-[#0F0F12]" />
        <div className="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
          <span className="text-amber-500 text-xs font-semibold uppercase tracking-wider">Hakkimizda</span>
          <h1 className="text-4xl sm:text-5xl font-bold text-white mt-4 tracking-tight">
            Sadece Bir Yazilim Degil, <br /><span className="text-amber-500">Guven Koprusu</span>
          </h1>
        </div>
      </section>

      {/* Story */}
      <section className="py-16">
        <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-8">
          <p className="text-slate-300 text-lg leading-relaxed">
            Biz sadece bir lojistik yazilimi kodlamadik. Biz, gece gunduz direksiyon basinda omur tuketen soforlerimiz ile, alin terini ve tum sermayesini o yuke emanet eden is insanlarimizin arasina <strong className="text-white">sarsilmaz bir guven koprusu</strong> kurduk.
          </p>

          <div className="grid sm:grid-cols-2 gap-6">
            <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-6">
              <Truck className="w-8 h-8 text-amber-500 mb-4" />
              <h3 className="text-white font-semibold text-lg mb-2">Soforlerimizin Yanindayiz</h3>
              <p className="text-slate-400 text-sm leading-relaxed">
                Her soforumuzun dikiz aynasinda ozlemle baktigi bir aile var. Sirf onlar icin uykusuz kalan soforlerimizin alin terini korumak en kutsal gorevimizdir. NavlunIQ ile hak ettikleri kazanca rotarsiz ulasirlar.
              </p>
            </div>
            <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-6">
              <ShieldCheck className="w-8 h-8 text-emerald-500 mb-4" />
              <h3 className="text-white font-semibold text-lg mb-2">Emeğinizi Koruyoruz</h3>
              <p className="text-slate-400 text-sm leading-relaxed">
                Yola cikan her koli, yuk sahibinin aylarca verdigi emegin ta kendisidir. Yapay zekayla dogrulanmis guvenilir soforleri yukunuzle bulusturarak riskleri sifira indiriyoruz.
              </p>
            </div>
          </div>

          <div className="text-center py-8">
            <p className="text-2xl text-white italic border-l-4 border-amber-500 pl-6 inline-block text-left">
              "Lojistikte en degerli olan, yuk sahibi ve sofor arasinda kurulan sarsilmaz guveni saglamaktir."
            </p>
            <p className="text-amber-500 mt-3 font-medium">-- NavlunIQ</p>
          </div>
        </div>
      </section>

      {/* Values */}
      <section className="py-16 bg-[#0A0A0C]">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="text-center mb-12">
            <h2 className="text-3xl font-bold text-white tracking-tight">Degerlerimiz</h2>
          </div>
          <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
            {[
              { icon: ShieldCheck, title: "Guven", desc: "KYC dogrulamali, havuz sistemli %100 guvenli platform." },
              { icon: Zap, title: "Hiz", desc: "AI destekli anlik eslestirme ve 10 sn evrak onayi." },
              { icon: Heart, title: "Samimiyet", desc: "Sofor ve yuk sahibi arasinda gercek bir kopru." },
              { icon: Target, title: "Seffaflik", desc: "Her asama canli takip edilebilir, kayitlar saklanir." },
            ].map(v => {
              const Icon = v.icon;
              return (
                <div key={v.title} className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5 text-center hover:border-[#3E3E45] transition-all">
                  <div className="w-12 h-12 rounded-xl bg-amber-500/10 flex items-center justify-center mx-auto mb-3">
                    <Icon className="w-6 h-6 text-amber-500" />
                  </div>
                  <h3 className="text-white font-semibold mb-1">{v.title}</h3>
                  <p className="text-slate-500 text-sm">{v.desc}</p>
                </div>
              );
            })}
          </div>
        </div>
      </section>
    </PublicLayout>
  );
}
