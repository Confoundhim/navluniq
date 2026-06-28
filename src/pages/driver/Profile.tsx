import { useState } from "react";
import {
  UserCircle, Phone, Mail, Save, CheckCircle,
  Star, Truck, Bell, Shield, Moon, Globe,
} from "lucide-react";

export default function DriverProfile() {
  const [saved, setSaved] = useState(false);
  const [activeTab, setActiveTab] = useState("personal");
  const [form, setForm] = useState({
    firstName: "Ali", lastName: "Şen", phone: "+90 555 123 45 67",
    email: "ali.sen@email.com", city: "İstanbul", district: "Kadıköy",
    licenseNo: "12345678", licenseClass: "E", experience: "8",
    preferredRoutes: "İstanbul-Ankara-İzmir",
    maxDistance: "800",
    bio: "8 yıllık profesyonel şoförüm. Tır ve kırkayak tecrübem var.",
  });
  const [preferences, setPreferences] = useState({
    smsNotifications: true,
    emailNotifications: false,
    pushNotifications: true,
    nightMode: true,
    autoBid: false,
    publicProfile: true,
  });

  const handleSave = () => {
    setSaved(true);
    setTimeout(() => setSaved(false), 3000);
  };

  const update = (f: string, v: string) => setForm({ ...form, [f]: v });
  const togglePref = (f: keyof typeof preferences) => setPreferences({ ...preferences, [f]: !preferences[f] });

  return (
    <div className="space-y-6 max-w-3xl">
      <div>
        <h1 className="text-2xl font-semibold text-white tracking-tight">Profilim</h1>
        <p className="text-slate-500 text-sm mt-1">Hesap bilgilerinizi ve tercihlerinizi yönetin</p>
      </div>

      {/* Profile Header */}
      <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-6">
        <div className="flex flex-col sm:flex-row items-center gap-4">
          <div className="w-20 h-20 rounded-full bg-blue-500/10 flex items-center justify-center">
            <UserCircle className="w-10 h-10 text-blue-500" />
          </div>
          <div className="text-center sm:text-left flex-1">
            <p className="text-white text-lg font-semibold">{form.firstName} {form.lastName}</p>
            <div className="flex items-center justify-center sm:justify-start gap-2 mt-1">
              <div className="flex items-center gap-1">
                <Star className="w-4 h-4 text-amber-500 fill-amber-500" />
                <span className="text-amber-500 text-sm font-medium">4.9</span>
              </div>
              <span className="text-slate-600">|</span>
              <span className="text-slate-500 text-sm">231 sefer</span>
              <span className="text-slate-600">|</span>
              <span className="text-slate-500 text-sm">{form.experience} yıl tecrübe</span>
            </div>
            <p className="text-slate-600 text-xs mt-1 flex items-center justify-center sm:justify-start gap-1">
              <Shield className="w-3 h-3 text-emerald-500" /> Onaylı Sürücü
            </p>
          </div>
        </div>
      </div>

      {/* Tabs */}
      <div className="flex items-center gap-1 bg-[#18181C] border border-[#2E2E35] rounded-xl p-1">
        {[
          { id: "personal", label: "Kişisel", icon: UserCircle },
          { id: "professional", label: "Profesyonel", icon: Truck },
          { id: "preferences", label: "Tercihler", icon: Bell },
        ].map(tab => {
          const Icon = tab.icon;
          return (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id)}
              className={`flex-1 flex items-center justify-center gap-1.5 py-2 rounded-lg text-xs font-medium transition-all ${activeTab === tab.id ? "bg-blue-500 text-white" : "text-slate-400 hover:text-white"}`}
            >
              <Icon className="w-3.5 h-3.5" />{tab.label}
            </button>
          );
        })}
      </div>

      {/* Personal Tab */}
      {activeTab === "personal" && (
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-6 space-y-4">
          <h3 className="text-white font-semibold text-sm">Kişisel Bilgiler</h3>
          <div className="grid sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-xs text-slate-400 mb-1.5">Ad</label>
              <input value={form.firstName} onChange={e => update("firstName", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-blue-500" />
            </div>
            <div>
              <label className="block text-xs text-slate-400 mb-1.5">Soyad</label>
              <input value={form.lastName} onChange={e => update("lastName", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-blue-500" />
            </div>
            <div>
              <label className="block text-xs text-slate-400 mb-1.5">Telefon</label>
              <div className="relative">
                <Phone className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-600" />
                <input value={form.phone} onChange={e => update("phone", e.target.value)} className="w-full h-9 pl-10 pr-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-blue-500" />
              </div>
            </div>
            <div>
              <label className="block text-xs text-slate-400 mb-1.5">E-posta</label>
              <div className="relative">
                <Mail className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-600" />
                <input value={form.email} onChange={e => update("email", e.target.value)} className="w-full h-9 pl-10 pr-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-blue-500" />
              </div>
            </div>
            <div>
              <label className="block text-xs text-slate-400 mb-1.5">Şehir</label>
              <input value={form.city} onChange={e => update("city", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-blue-500" />
            </div>
            <div>
              <label className="block text-xs text-slate-400 mb-1.5">İlçe</label>
              <input value={form.district} onChange={e => update("district", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-blue-500" />
            </div>
          </div>
        </div>
      )}

      {/* Professional Tab */}
      {activeTab === "professional" && (
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-6 space-y-4">
          <h3 className="text-white font-semibold text-sm">Profesyonel Bilgiler</h3>
          <div className="grid sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-xs text-slate-400 mb-1.5">Ehliyet Numarası</label>
              <input value={form.licenseNo} onChange={e => update("licenseNo", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-blue-500" />
            </div>
            <div>
              <label className="block text-xs text-slate-400 mb-1.5">Ehliyet Sınıfı</label>
              <select value={form.licenseClass} onChange={e => update("licenseClass", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-blue-500">
                <option>B</option><option>C</option><option>D</option><option>E</option>
              </select>
            </div>
            <div>
              <label className="block text-xs text-slate-400 mb-1.5">Tecrübe (Yıl)</label>
              <input type="number" value={form.experience} onChange={e => update("experience", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-blue-500" />
            </div>
            <div>
              <label className="block text-xs text-slate-400 mb-1.5">Maksimum Mesafe (km)</label>
              <input type="number" value={form.maxDistance} onChange={e => update("maxDistance", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-blue-500" />
            </div>
            <div className="sm:col-span-2">
              <label className="block text-xs text-slate-400 mb-1.5">Tercih Ettiğim Rotalar</label>
              <input value={form.preferredRoutes} onChange={e => update("preferredRoutes", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-blue-500" placeholder="İstanbul-Ankara-İzmir" />
            </div>
            <div className="sm:col-span-2">
              <label className="block text-xs text-slate-400 mb-1.5">Hakkımda</label>
              <textarea value={form.bio} onChange={e => update("bio", e.target.value)} rows={3} className="w-full px-3 py-2 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm resize-none focus:outline-none focus:border-blue-500" />
            </div>
          </div>
        </div>
      )}

      {/* Preferences Tab */}
      {activeTab === "preferences" && (
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-6 space-y-4">
          <h3 className="text-white font-semibold text-sm">Bildirim ve Tercihler</h3>
          <div className="space-y-3">
            {[
              { key: "smsNotifications" as const, label: "SMS Bildirimleri", desc: "Teklif durumları ve önemli güncellemeler", icon: Phone },
              { key: "emailNotifications" as const, label: "E-posta Bildirimleri", desc: "Haftalık özet ve finansal raporlar", icon: Mail },
              { key: "pushNotifications" as const, label: "Anlık Bildirimler", desc: "Yeni yük ilanları ve acil teklifler", icon: Bell },
              { key: "nightMode" as const, label: "Koyu Tema", desc: "Koyu renk temasını kullan", icon: Moon },
              { key: "autoBid" as const, label: "Otomatik Teklif", desc: "Belirlenen kriterlere uygun yükler için otomatik teklif", icon: Globe },
              { key: "publicProfile" as const, label: "Herkese Açık Profil", desc: "Yük sahipleri profilinizi görebilsin", icon: Shield },
            ].map(item => {
              const Icon = item.icon;
              return (
                <div key={item.key} className="flex items-center justify-between py-3 border-b border-[#2E2E35] last:border-0">
                  <div className="flex items-center gap-3">
                    <Icon className="w-4 h-4 text-slate-500" />
                    <div>
                      <p className="text-white text-sm">{item.label}</p>
                      <p className="text-slate-600 text-xs">{item.desc}</p>
                    </div>
                  </div>
                  <button
                    onClick={() => togglePref(item.key)}
                    className={`w-10 h-5 rounded-full transition-all relative ${preferences[item.key] ? "bg-blue-500" : "bg-[#2E2E35]"}`}
                  >
                    <div className={`absolute top-0.5 w-4 h-4 rounded-full bg-white transition-all ${preferences[item.key] ? "left-5" : "left-0.5"}`} />
                  </button>
                </div>
              );
            })}
          </div>
        </div>
      )}

      {/* Save Button */}
      <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-4 flex justify-end">
        <button onClick={handleSave} className="flex items-center gap-2 px-6 py-2.5 bg-blue-500 hover:bg-blue-400 text-white font-semibold rounded-lg text-sm transition-all">
          {saved ? <><CheckCircle className="w-4 h-4" /> Kaydedildi</> : <><Save className="w-4 h-4" /> Kaydet</>}
        </button>
      </div>
    </div>
  );
}
