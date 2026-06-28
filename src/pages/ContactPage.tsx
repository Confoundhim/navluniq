import { Mail, MapPin, Send, MessageCircle, Headphones } from "lucide-react";
import { useState } from "react";
import PublicLayout from "./PublicLayout";

export default function ContactPage() {
  const [form, setForm] = useState({ name: "", phone: "", email: "", role: "guest", category: "other", message: "" });
  const [submitted, setSubmitted] = useState(false);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitted(true);
    setTimeout(() => setSubmitted(false), 3000);
  };

  return (
    <PublicLayout>
      <section className="relative pt-20 pb-16 overflow-hidden">
        <div className="absolute inset-0 bg-gradient-to-br from-amber-500/10 via-[#0F0F12] to-[#0F0F12]" />
        <div className="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
          <span className="text-amber-500 text-xs font-semibold uppercase tracking-wider">İletisim</span>
          <h1 className="text-4xl sm:text-5xl font-bold text-white mt-4 tracking-tight">Yolculugunuzun Her Aninda <span className="text-amber-500">Bize Ulasin</span></h1>
          <p className="text-slate-400 mt-4 max-w-2xl mx-auto">Sorulariniz, isbirligi teklifleriniz veya sistemle ilgili her turlu destek talebi icin ekibimiz kesintisiz hizmet vermektedir.</p>
        </div>
      </section>

      <section className="py-16">
        <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="grid lg:grid-cols-3 gap-8">
            {/* Contact Info */}
            <div className="space-y-4">
              <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5">
                <div className="w-10 h-10 rounded-lg bg-amber-500/10 flex items-center justify-center mb-3">
                  <Headphones className="w-5 h-5 text-amber-500" />
                </div>
                <h3 className="text-white font-semibold mb-1">Musteri Hizmetleri</h3>
                <p className="text-slate-500 text-sm">7/24 destek hattimizdan bize ulasabilirsiniz.</p>
              </div>
              <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5">
                <div className="w-10 h-10 rounded-lg bg-emerald-500/10 flex items-center justify-center mb-3">
                  <MessageCircle className="w-5 h-5 text-emerald-500" />
                </div>
                <h3 className="text-white font-semibold mb-1">WhatsApp Destek</h3>
                <p className="text-slate-500 text-sm">Anlik destek icin WhatsApp hattimiza yazin.</p>
              </div>
              <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5">
                <div className="w-10 h-10 rounded-lg bg-blue-500/10 flex items-center justify-center mb-3">
                  <Mail className="w-5 h-5 text-blue-500" />
                </div>
                <h3 className="text-white font-semibold mb-1">E-Posta</h3>
                <p className="text-slate-400 text-sm">info@navluniq.com</p>
              </div>
              <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5">
                <div className="w-10 h-10 rounded-lg bg-purple-500/10 flex items-center justify-center mb-3">
                  <MapPin className="w-5 h-5 text-purple-500" />
                </div>
                <h3 className="text-white font-semibold mb-1">Adres</h3>
                <p className="text-slate-400 text-sm">Ankara, Turkiye</p>
              </div>
            </div>

            {/* Contact Form */}
            <div className="lg:col-span-2 bg-[#18181C] border border-[#2E2E35] rounded-xl p-6">
              <h2 className="text-white font-semibold text-lg mb-6">Bize Mesaj Gonderin</h2>
              {submitted ? (
                <div className="flex flex-col items-center justify-center py-12 text-center">
                  <div className="w-16 h-16 rounded-full bg-emerald-500/10 flex items-center justify-center mb-4">
                    <Send className="w-8 h-8 text-emerald-500" />
                  </div>
                  <h3 className="text-white font-semibold text-lg mb-2">Mesajiniz Gonderildi!</h3>
                  <p className="text-slate-500 text-sm">En kisa surede size donus yapacagiz.</p>
                </div>
              ) : (
                <form onSubmit={handleSubmit} className="space-y-4">
                  <div className="grid sm:grid-cols-2 gap-4">
                    <div>
                      <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5">Adiniz Soyadiniz</label>
                      <input type="text" value={form.name} onChange={e => setForm({...form, name: e.target.value})} required className="w-full h-10 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-amber-500" />
                    </div>
                    <div>
                      <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5">Telefon Numaraniz</label>
                      <input type="tel" value={form.phone} onChange={e => setForm({...form, phone: e.target.value})} className="w-full h-10 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-amber-500" />
                    </div>
                  </div>
                  <div className="grid sm:grid-cols-2 gap-4">
                    <div>
                      <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5">Platform Rolu</label>
                      <select value={form.role} onChange={e => setForm({...form, role: e.target.value})} className="w-full h-10 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-amber-500">
                        <option value="guest">Misafir</option>
                        <option value="shipper">Yuk Sahibi</option>
                        <option value="driver">Sofor</option>
                      </select>
                    </div>
                    <div>
                      <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5">Konu Kategorisi</label>
                      <select value={form.category} onChange={e => setForm({...form, category: e.target.value})} className="w-full h-10 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-amber-500">
                        <option value="technical_error">Teknik Hata</option>
                        <option value="subscription_pricing">Abonelik & Fiyatlandirma</option>
                        <option value="kyc">Evrak Analizi (KYC)</option>
                        <option value="payment">PayTR Odeme & Escrow</option>
                        <option value="load_route">İlan & Rota Sorunlari</option>
                        <option value="other">Diger / Genel Sorular</option>
                      </select>
                    </div>
                  </div>
                  <div>
                    <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5">E-Posta Adresiniz</label>
                    <input type="email" value={form.email} onChange={e => setForm({...form, email: e.target.value})} required className="w-full h-10 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-amber-500" />
                  </div>
                  <div>
                    <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5">Mesajiniz</label>
                    <textarea value={form.message} onChange={e => setForm({...form, message: e.target.value})} rows={5} required className="w-full px-3 py-2 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-amber-500 resize-y" />
                  </div>
                  <button type="submit" className="w-full py-3 bg-amber-500 hover:bg-amber-400 text-[#0F0F12] font-bold rounded-xl transition-all flex items-center justify-center gap-2">
                    <Send className="w-4 h-4" /> Gonder
                  </button>
                </form>
              )}
            </div>
          </div>
        </div>
      </section>
    </PublicLayout>
  );
}
