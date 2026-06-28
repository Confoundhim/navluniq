import { useState } from "react";
import { Headphones, Send, CheckCircle, MessageSquare } from "lucide-react";

export default function Support() {
  const [submitted, setSubmitted] = useState(false);
  const [form, setForm] = useState({ subject: "", category: "general", message: "" });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitted(true);
    setTimeout(() => setSubmitted(false), 4000);
    setForm({ subject: "", category: "general", message: "" });
  };

  return (
    <div className="space-y-6 max-w-2xl">
      <div>
        <h1 className="text-2xl font-semibold text-white tracking-tight">Destek</h1>
        <p className="text-slate-500 text-sm mt-1">Yardim mi ihtiyaciniz var?</p>
      </div>
      <div className="grid sm:grid-cols-2 gap-4">
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5 text-center hover:border-[#3E3E45] transition-all">
          <div className="w-12 h-12 rounded-xl bg-emerald-500/10 flex items-center justify-center mx-auto mb-3"><MessageSquare className="w-6 h-6 text-emerald-500" /></div>
          <p className="text-white font-medium text-sm">WhatsApp</p>
          <p className="text-slate-500 text-xs mt-1">+90 555 000 00 00</p>
        </div>
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5 text-center hover:border-[#3E3E45] transition-all">
          <div className="w-12 h-12 rounded-xl bg-blue-500/10 flex items-center justify-center mx-auto mb-3"><Headphones className="w-6 h-6 text-blue-500" /></div>
          <p className="text-white font-medium text-sm">E-posta</p>
          <p className="text-slate-500 text-xs mt-1">info@navluniq.com</p>
        </div>
      </div>
      <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-6">
        <h3 className="text-white font-semibold text-sm mb-4">Bize Yazin</h3>
        {submitted ? (
          <div className="text-center py-8">
            <div className="w-14 h-14 rounded-full bg-emerald-500/10 flex items-center justify-center mx-auto mb-3"><CheckCircle className="w-7 h-7 text-emerald-500" /></div>
            <p className="text-white font-medium">Mesajiniz gonderildi!</p>
            <p className="text-slate-500 text-xs mt-1">En kisa surede donus yapacagiz.</p>
          </div>
        ) : (
          <form onSubmit={handleSubmit} className="space-y-4">
            <div><label className="block text-xs text-slate-400 mb-1.5">Konu</label><input value={form.subject} onChange={e => setForm({...form, subject: e.target.value})} required className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-emerald-500" /></div>
            <div><label className="block text-xs text-slate-400 mb-1.5">Kategori</label><select value={form.category} onChange={e => setForm({...form, category: e.target.value})} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm"><option value="general">Genel</option><option value="payment">Odeme</option><option value="load">İlan</option><option value="technical">Teknik</option></select></div>
            <div><label className="block text-xs text-slate-400 mb-1.5">Mesaj</label><textarea value={form.message} onChange={e => setForm({...form, message: e.target.value})} required rows={4} className="w-full px-3 py-2 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm resize-none focus:outline-none focus:border-emerald-500" /></div>
            <button type="submit" className="flex items-center gap-2 px-6 py-2.5 bg-emerald-500 hover:bg-emerald-400 text-[#0F0F12] font-semibold rounded-lg text-sm transition-all"><Send className="w-4 h-4" /> Gonder</button>
          </form>
        )}
      </div>
    </div>
  );
}
