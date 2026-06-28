import { useState } from "react";
import {
  Headphones, Send, CheckCircle, MessageSquare,
  Mail, Clock, ChevronDown, ChevronUp,
  AlertTriangle, FileText, HelpCircle, BookOpen, Shield,
} from "lucide-react";

const faqs = [
  { q: "Teklifim ne zaman onaylanır?", a: "Yük sahipleri genellikle 24 saat içinde teklifleri değerlendirir. Acil ilanlarda bu süre çok daha kısa olabilir." },
  { q: "Ödememi ne zaman alırım?", a: "Taşıma tamamlandıktan ve yük sahibi onay verdikten sonra 24 saat içinde ödemeniz cüzdanınıza aktarılır." },
  { q: "Komisyon oranı nedir?", a: "NavlunIQ platform komisyonu taşıma bedelinin %2.5'idir. Bu oran piyasanın en düşük oranlarından biridir." },
  { q: "Nasıl daha yüksek puan alabilirim?", a: "Zamanında teslimat, profesyonel iletişim, yük güvenliği ve yük sahibi memnuniyeti puanınızı yükseltir." },
  { q: "Birden fazla aracım var, hepsini ekleyebilir miyim?", a: "Evet, Araçlarım bölümünden sınırsız sayıda araç ekleyebilirsiniz. Her araç için ayrı evrak yüklemeniz gerekir." },
  { q: "Teklifimi geri çekebilir miyim?", a: "Evet, yük sahibi teklifinizi onaylamadan önce istediğiniz zaman geri çekebilirsiniz." },
];

export default function DriverSupport() {
  const [submitted, setSubmitted] = useState(false);
  const [form, setForm] = useState({ subject: "", category: "general", message: "", priority: "normal" });
  const [openFaq, setOpenFaq] = useState<number | null>(null);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitted(true);
    setTimeout(() => setSubmitted(false), 4000);
    setForm({ subject: "", category: "general", message: "", priority: "normal" });
  };

  return (
    <div className="space-y-6 max-w-3xl">
      <div>
        <h1 className="text-2xl font-semibold text-white tracking-tight">Destek</h1>
        <p className="text-slate-500 text-sm mt-1">Yardım merkezi ve iletişim</p>
      </div>

      {/* Quick Contact Cards */}
      <div className="grid sm:grid-cols-3 gap-4">
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5 text-center hover:border-green-500/30 transition-all group">
          <div className="w-12 h-12 rounded-xl bg-green-500/10 flex items-center justify-center mx-auto mb-3 group-hover:scale-110 transition-transform">
            <MessageSquare className="w-6 h-6 text-green-500" />
          </div>
          <p className="text-white font-medium text-sm">WhatsApp</p>
          <p className="text-slate-500 text-xs mt-1">+90 555 000 00 00</p>
          <p className="text-green-500 text-[10px] mt-1 flex items-center justify-center gap-1">
            <Clock className="w-3 h-3" /> 7/24
          </p>
        </div>
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5 text-center hover:border-blue-500/30 transition-all group">
          <div className="w-12 h-12 rounded-xl bg-blue-500/10 flex items-center justify-center mx-auto mb-3 group-hover:scale-110 transition-transform">
            <Headphones className="w-6 h-6 text-blue-500" />
          </div>
          <p className="text-white font-medium text-sm">Çağrı Merkezi</p>
          <p className="text-slate-500 text-xs mt-1">+90 850 123 45 67</p>
          <p className="text-slate-600 text-[10px] mt-1">09:00 - 18:00</p>
        </div>
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5 text-center hover:border-purple-500/30 transition-all group">
          <div className="w-12 h-12 rounded-xl bg-purple-500/10 flex items-center justify-center mx-auto mb-3 group-hover:scale-110 transition-transform">
            <Mail className="w-6 h-6 text-purple-500" />
          </div>
          <p className="text-white font-medium text-sm">E-posta</p>
          <p className="text-slate-500 text-xs mt-1">destek@navluniq.com</p>
          <p className="text-slate-600 text-[10px] mt-1">24 saat içinde yanıt</p>
        </div>
      </div>

      <div className="grid lg:grid-cols-2 gap-6">
        {/* FAQ */}
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5">
          <div className="flex items-center gap-2 mb-4">
            <HelpCircle className="w-5 h-5 text-blue-500" />
            <h3 className="text-white font-semibold text-sm">Sık Sorulan Sorular</h3>
          </div>
          <div className="space-y-2">
            {faqs.map((faq, idx) => (
              <div key={idx} className="border border-[#2E2E35] rounded-lg overflow-hidden">
                <button
                  onClick={() => setOpenFaq(openFaq === idx ? null : idx)}
                  className="w-full flex items-center justify-between px-4 py-3 text-left hover:bg-white/[0.02] transition-colors"
                >
                  <span className="text-white text-sm">{faq.q}</span>
                  {openFaq === idx ? <ChevronUp className="w-4 h-4 text-slate-500 shrink-0" /> : <ChevronDown className="w-4 h-4 text-slate-500 shrink-0" />}
                </button>
                {openFaq === idx && (
                  <div className="px-4 pb-3 border-t border-[#2E2E35]">
                    <p className="text-slate-400 text-xs mt-2">{faq.a}</p>
                  </div>
                )}
              </div>
            ))}
          </div>
        </div>

        {/* Contact Form */}
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5">
          <div className="flex items-center gap-2 mb-4">
            <FileText className="w-5 h-5 text-blue-500" />
            <h3 className="text-white font-semibold text-sm">Bize Yazın</h3>
          </div>
          {submitted ? (
            <div className="text-center py-8">
              <div className="w-14 h-14 rounded-full bg-emerald-500/10 flex items-center justify-center mx-auto mb-3">
                <CheckCircle className="w-7 h-7 text-emerald-500" />
              </div>
              <p className="text-white font-medium">Mesajınız gönderildi!</p>
              <p className="text-slate-500 text-xs mt-1">En kısa sürede size dönüş yapacağız.</p>
            </div>
          ) : (
            <form onSubmit={handleSubmit} className="space-y-3">
              <div>
                <label className="block text-xs text-slate-400 mb-1.5">Konu</label>
                <input value={form.subject} onChange={e => setForm({ ...form, subject: e.target.value })} required className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-blue-500" />
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs text-slate-400 mb-1.5">Kategori</label>
                  <select value={form.category} onChange={e => setForm({ ...form, category: e.target.value })} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm">
                    <option value="general">Genel</option>
                    <option value="payment">Ödeme</option>
                    <option value="load">Yük / Teklif</option>
                    <option value="technical">Teknik</option>
                    <option value="complaint">Şikayet</option>
                  </select>
                </div>
                <div>
                  <label className="block text-xs text-slate-400 mb-1.5">Öncelik</label>
                  <select value={form.priority} onChange={e => setForm({ ...form, priority: e.target.value })} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm">
                    <option value="normal">Normal</option>
                    <option value="high">Yüksek</option>
                    <option value="urgent">Acil</option>
                  </select>
                </div>
              </div>
              <div>
                <label className="block text-xs text-slate-400 mb-1.5">Mesaj</label>
                <textarea value={form.message} onChange={e => setForm({ ...form, message: e.target.value })} required rows={4} className="w-full px-3 py-2 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm resize-none focus:outline-none focus:border-blue-500" />
              </div>
              {form.priority === "urgent" && (
                <div className="flex items-center gap-2 p-3 bg-red-500/5 border border-red-500/20 rounded-lg">
                  <AlertTriangle className="w-4 h-4 text-red-500 shrink-0" />
                  <p className="text-red-400 text-xs">Acil talepleriniz en öncelikli olarak işleme alınacaktır.</p>
                </div>
              )}
              <button type="submit" className="w-full flex items-center justify-center gap-2 py-2.5 bg-blue-500 hover:bg-blue-400 text-white font-semibold rounded-lg text-sm transition-all">
                <Send className="w-4 h-4" /> Gönder
              </button>
            </form>
          )}
        </div>
      </div>

      {/* Quick Links */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        {[
          { label: "Kullanım Kılavuzu", icon: BookOpen },
          { label: "Gizlilik Politikası", icon: Shield },
          { label: "KVKK Aydınlatma", icon: FileText },
          { label: "Sık Sorulan Sorular", icon: HelpCircle },
        ].map(link => {
          const Icon = link.icon;
          return (
            <button key={link.label} className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-4 hover:border-blue-500/30 transition-all group text-center">
              <Icon className="w-5 h-5 text-slate-500 mx-auto mb-2 group-hover:text-blue-500 transition-colors" />
              <p className="text-slate-400 text-xs group-hover:text-white transition-colors">{link.label}</p>
            </button>
          );
        })}
      </div>
    </div>
  );
}
