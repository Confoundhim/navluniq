import { useState } from "react";
import { UserCircle, Building2, Phone, Mail, MapPin, Save, CheckCircle } from "lucide-react";

export default function Profile() {
  const [saved, setSaved] = useState(false);
  const [form, setForm] = useState({ firstName: "Ahmet", lastName: "Yılmaz", phone: "+90 555 123 45 67", email: "ahmet@firma.com", company: "ABC Lojistik Ltd.", taxNo: "1234567890", city: "Ankara", district: "Çankaya", address: "Atatürk Bulvarı No:123" });

  const handleSave = () => {
    setSaved(true);
    setTimeout(() => setSaved(false), 3000);
  };

  const update = (f: string, v: string) => setForm({...form, [f]: v});

  return (
    <div className="space-y-6 max-w-2xl">
      <div>
        <h1 className="text-2xl font-semibold text-white tracking-tight">Profilim</h1>
        <p className="text-slate-500 text-sm mt-1">Hesap bilgilerinizi yonetin</p>
      </div>
      <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-6 space-y-4">
        <div className="flex items-center gap-4 mb-6">
          <div className="w-16 h-16 rounded-full bg-emerald-500/10 flex items-center justify-center"><UserCircle className="w-8 h-8 text-emerald-500" /></div>
          <div><p className="text-white font-semibold">{form.firstName} {form.lastName}</p><p className="text-slate-500 text-xs">{form.company || "Bireysel"}</p></div>
        </div>
        <div className="grid sm:grid-cols-2 gap-4">
          <div><label className="block text-xs text-slate-400 mb-1.5">Ad</label><input value={form.firstName} onChange={e => update("firstName", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-emerald-500" /></div>
          <div><label className="block text-xs text-slate-400 mb-1.5">Soyad</label><input value={form.lastName} onChange={e => update("lastName", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-emerald-500" /></div>
          <div><label className="block text-xs text-slate-400 mb-1.5">Telefon</label><div className="relative"><Phone className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-600" /><input value={form.phone} onChange={e => update("phone", e.target.value)} className="w-full h-9 pl-10 pr-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-emerald-500" /></div></div>
          <div><label className="block text-xs text-slate-400 mb-1.5">E-posta</label><div className="relative"><Mail className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-600" /><input value={form.email} onChange={e => update("email", e.target.value)} className="w-full h-9 pl-10 pr-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-emerald-500" /></div></div>
          <div><label className="block text-xs text-slate-400 mb-1.5">Sirket</label><div className="relative"><Building2 className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-600" /><input value={form.company} onChange={e => update("company", e.target.value)} className="w-full h-9 pl-10 pr-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-emerald-500" /></div></div>
          <div><label className="block text-xs text-slate-400 mb-1.5">Vergi No</label><input value={form.taxNo} onChange={e => update("taxNo", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-emerald-500" /></div>
          <div><label className="block text-xs text-slate-400 mb-1.5">Sehir</label><input value={form.city} onChange={e => update("city", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-emerald-500" /></div>
          <div><label className="block text-xs text-slate-400 mb-1.5">İlce</label><input value={form.district} onChange={e => update("district", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-emerald-500" /></div>
          <div className="sm:col-span-2"><label className="block text-xs text-slate-400 mb-1.5">Adres</label><div className="relative"><MapPin className="absolute left-3 top-2.5 w-4 h-4 text-slate-600" /><textarea value={form.address} onChange={e => update("address", e.target.value)} rows={3} className="w-full pl-10 pr-3 py-2 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm resize-none focus:outline-none focus:border-emerald-500" /></div></div>
        </div>
        <div className="border-t border-[#2E2E35] pt-4">
          <button onClick={handleSave} className="flex items-center gap-2 px-6 py-2.5 bg-emerald-500 hover:bg-emerald-400 text-[#0F0F12] font-semibold rounded-lg text-sm transition-all">
            {saved ? <><CheckCircle className="w-4 h-4" /> Kaydedildi</> : <><Save className="w-4 h-4" /> Kaydet</>}
          </button>
        </div>
      </div>
    </div>
  );
}
