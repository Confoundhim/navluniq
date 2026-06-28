import { useState } from "react";
import { Link } from "react-router";
import {
  Truck, ArrowRight, ArrowLeft, CheckCircle, User, Phone, KeyRound,
  CreditCard, FileCheck, Stethoscope, Award, Camera, Car,
  MessageSquare, QrCode, Check, ShieldCheck
} from "lucide-react";

type Step = 1 | 2 | 3 | 4 | 5;

const vehicleTypes = [
  "Otomobil", "Minivan", "Orta Panelvan", "Uzun Panelvan",
  "Kamyonet", "6 Teker Kamyon", "8 Teker Kamyon", "10 Teker Kamyon", "Kirkayak", "Tir"
];

export default function DriverRegister() {
  const [step, setStep] = useState<Step>(1);
  const [form, setForm] = useState({
    firstName: "", lastName: "", phone: "", password: "", confirmPassword: "",
    license: "", src: "", psycho: "", kBelge: "",
    vehicleType: "", plate: "", capacity: "", brand: "", model: "", year: "",
    whatsappGroups: [] as string[],
  });
  const [error, setError] = useState("");

  const update = (field: string, val: string) => {
    setForm(prev => ({ ...prev, [field]: val }));
    setError("");
  };

  const validateStep = () => {
    setError("");
    switch (step) {
      case 1:
        if (!form.firstName || !form.lastName) { setError("Ad ve soyad zorunludur."); return false; }
        if (form.phone.length < 10) { setError("Gecerli telefon numarasi girin."); return false; }
        if (form.password.length < 6) { setError("Sifre en az 6 karakter olmalidir."); return false; }
        if (form.password !== form.confirmPassword) { setError("Sifreler eslesmiyor."); return false; }
        return true;
      case 2:
        if (!form.license || !form.src || !form.psycho) { setError("Ehliyet, SRC ve Psikoteknik belgeleri zorunludur."); return false; }
        return true;
      case 3:
        if (!form.vehicleType || !form.plate) { setError("Arac tipi ve plaka zorunludur."); return false; }
        return true;
      default:
        return true;
    }
  };

  const next = () => { if (validateStep()) setStep(s => Math.min(5, s + 1) as Step); };
  const prev = () => { setError(""); setStep(s => Math.max(1, s - 1) as Step); };

  const formatPhone = (val: string) => {
    const cleaned = val.replace(/\D/g, "").slice(0, 11);
    if (cleaned.length <= 4) return cleaned;
    if (cleaned.length <= 7) return `${cleaned.slice(0, 4)} ${cleaned.slice(4)}`;
    if (cleaned.length <= 9) return `${cleaned.slice(0, 4)} ${cleaned.slice(4, 7)} ${cleaned.slice(7)}`;
    return `${cleaned.slice(0, 4)} ${cleaned.slice(4, 7)} ${cleaned.slice(7, 9)} ${cleaned.slice(9)}`;
  };

  const steps = [
    { num: 1, label: "Kisisel", icon: User },
    { num: 2, label: "Belgeler", icon: FileCheck },
    { num: 3, label: "Arac", icon: Car },
    { num: 4, label: "WhatsApp", icon: MessageSquare },
    { num: 5, label: "Onay", icon: CheckCircle },
  ];

  return (
    <div className="min-h-screen w-full flex items-center justify-center bg-[#0F0F12] relative overflow-hidden py-8">
      <div className="absolute inset-0 opacity-5" style={{ backgroundImage: `radial-gradient(circle at 1px 1px, #F59E0B 1px, transparent 0)`, backgroundSize: '40px 40px' }} />
      <div className="relative w-full max-w-lg mx-4">
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-2xl p-6 shadow-2xl">
          {/* Logo */}
          <div className="flex flex-col items-center mb-6">
            <div className="w-12 h-12 rounded-xl bg-amber-500 flex items-center justify-center mb-3">
              <Truck className="w-6 h-6 text-[#0F0F12]" />
            </div>
            <span className="text-white font-bold text-lg">Navlun<span className="text-amber-500">IQ</span></span>
            <p className="text-slate-500 text-xs mt-1">Sofor Kayit - Adim {step}/5</p>
          </div>

          {/* Step indicator */}
          <div className="flex items-center justify-between mb-6 px-2">
            {steps.map((s, i) => {
              const Icon = s.icon;
              return (
                <div key={s.num} className="flex flex-col items-center gap-1">
                  <div className={`w-8 h-8 rounded-full flex items-center justify-center transition-all ${step >= s.num ? "bg-amber-500 text-[#0F0F12]" : "bg-[#232328] text-slate-600"}`}>
                    <Icon className="w-4 h-4" />
                  </div>
                  <span className={`text-[10px] ${step >= s.num ? "text-amber-500" : "text-slate-600"}`}>{s.label}</span>
                  {i < steps.length - 1 && <div className="hidden" />}
                </div>
              );
            })}
          </div>

          {error && (
            <div className="p-3 rounded-lg bg-red-500/10 border-l-2 border-red-500 text-red-400 text-sm mb-4">{error}</div>
          )}

          {/* Step 1: Personal Info */}
          {step === 1 && (
            <div className="space-y-4">
              <h3 className="text-white font-semibold mb-1">Kisisel Bilgiler</h3>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs text-slate-400 mb-1.5">Ad</label>
                  <input type="text" value={form.firstName} onChange={e => update("firstName", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-amber-500" placeholder="Ali" />
                </div>
                <div>
                  <label className="block text-xs text-slate-400 mb-1.5">Soyad</label>
                  <input type="text" value={form.lastName} onChange={e => update("lastName", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-amber-500" placeholder="Veli" />
                </div>
              </div>
              <div>
                <label className="block text-xs text-slate-400 mb-1.5">Telefon</label>
                <div className="relative">
                  <Phone className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-600" />
                  <input type="tel" value={form.phone} onChange={e => update("phone", formatPhone(e.target.value))} className="w-full h-9 pl-10 pr-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-amber-500" placeholder="5XX XXX XX XX" />
                </div>
              </div>
              <div>
                <label className="block text-xs text-slate-400 mb-1.5">Sifre</label>
                <div className="relative">
                  <KeyRound className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-600" />
                  <input type="password" value={form.password} onChange={e => update("password", e.target.value)} className="w-full h-9 pl-10 pr-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-amber-500" placeholder="Min. 6 karakter" />
                </div>
              </div>
              <div>
                <label className="block text-xs text-slate-400 mb-1.5">Sifre Tekrar</label>
                <input type="password" value={form.confirmPassword} onChange={e => update("confirmPassword", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-amber-500" placeholder="Sifrenizi tekrar girin" />
              </div>
            </div>
          )}

          {/* Step 2: KYC Documents */}
          {step === 2 && (
            <div className="space-y-4">
              <h3 className="text-white font-semibold mb-1">KYC Belgeleri</h3>
              <p className="text-slate-500 text-xs">Belgeleriniz AI OCR ile taranacak ve 10 saniyede dogrulanacaktir.</p>
              {[
                { field: "license", label: "Ehliyet (Surucu Belgesi)", icon: CreditCard, required: true },
                { field: "src", label: "SRC Belgesi (Sertifika)", icon: Award, required: true },
                { field: "psycho", label: "Psikoteknik Raporu", icon: Stethoscope, required: true },
                { field: "kBelge", label: "K Belgesi (Opsiyonel)", icon: FileCheck, required: false },
              ].map(doc => {
                const Icon = doc.icon;
                return (
                  <div key={doc.field} className="p-3 bg-[#232328] border border-[#2E2E35] rounded-lg hover:border-[#3E3E45] transition-all">
                    <div className="flex items-center gap-3">
                      <div className="w-10 h-10 rounded-lg bg-amber-500/10 flex items-center justify-center shrink-0">
                        <Icon className="w-5 h-5 text-amber-500" />
                      </div>
                      <div className="flex-1">
                        <p className="text-white text-sm font-medium">{doc.label} {doc.required && <span className="text-red-400">*</span>}</p>
                        <p className="text-slate-600 text-xs">PDF, JPG veya PNG yukleyin</p>
                      </div>
                    </div>
                    <div className="mt-2 flex items-center gap-2">
                      <input type="file" accept=".pdf,.jpg,.jpeg,.png" onChange={e => update(doc.field, e.target.files?.[0]?.name || "")} className="hidden" id={`file-${doc.field}`} />
                      <label htmlFor={`file-${doc.field}`} className="px-3 py-1.5 bg-[#2E2E35] hover:bg-[#3E3E45] text-slate-300 text-xs rounded-lg cursor-pointer transition-colors flex items-center gap-1.5">
                        <Camera className="w-3.5 h-3.5" /> Belge Yukle
                      </label>
                      {form[doc.field as keyof typeof form] && (
                        <span className="flex items-center gap-1 text-emerald-400 text-xs"><Check className="w-3 h-3" /> Yuklendi</span>
                      )}
                    </div>
                  </div>
                );
              })}
            </div>
          )}

          {/* Step 3: Vehicle Info */}
          {step === 3 && (
            <div className="space-y-4">
              <h3 className="text-white font-semibold mb-1">Arac Bilgileri</h3>
              <div>
                <label className="block text-xs text-slate-400 mb-1.5">Arac Turu <span className="text-red-400">*</span></label>
                <select value={form.vehicleType} onChange={e => update("vehicleType", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-amber-500">
                  <option value="">Secin</option>
                  {vehicleTypes.map(v => <option key={v} value={v}>{v}</option>)}
                </select>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs text-slate-400 mb-1.5">Plaka <span className="text-red-400">*</span></label>
                  <input type="text" value={form.plate} onChange={e => update("plate", e.target.value.toUpperCase())} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-amber-500" placeholder="34 ABC 123" />
                </div>
                <div>
                  <label className="block text-xs text-slate-400 mb-1.5">Kapasite (kg)</label>
                  <input type="number" value={form.capacity} onChange={e => update("capacity", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-amber-500" placeholder="5000" />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs text-slate-400 mb-1.5">Marka</label>
                  <input type="text" value={form.brand} onChange={e => update("brand", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-amber-500" placeholder="Mercedes" />
                </div>
                <div>
                  <label className="block text-xs text-slate-400 mb-1.5">Model</label>
                  <input type="text" value={form.model} onChange={e => update("model", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-amber-500" placeholder="Actros" />
                </div>
              </div>
              <div>
                <label className="block text-xs text-slate-400 mb-1.5">Model Yili</label>
                <input type="number" value={form.year} onChange={e => update("year", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-amber-500" placeholder="2020" />
              </div>
            </div>
          )}

          {/* Step 4: WhatsApp Integration */}
          {step === 4 && (
            <div className="space-y-4">
              <h3 className="text-white font-semibold mb-1">WhatsApp Grup Entegrasyonu</h3>
              <p className="text-slate-500 text-xs">Premium uyelik ile kendi WhatsApp gruplarinizi sisteme baglayabilirsiniz. simdilik bu adimi atlayabilirsiniz.</p>
              <div className="p-6 bg-[#232328] border border-[#2E2E35] rounded-xl flex flex-col items-center text-center">
                <div className="w-20 h-20 rounded-2xl bg-emerald-500/10 flex items-center justify-center mb-3">
                  <QrCode className="w-10 h-10 text-emerald-500" />
                </div>
                <p className="text-white font-medium text-sm mb-1">WhatsApp QR Kod ile Baglama</p>
                <p className="text-slate-500 text-xs mb-4">QR kodu taratarak gruplarinizi otomatik senkronize edin.</p>
                <button type="button" onClick={() => alert("WhatsApp QR kod entegrasyonu simulasyonu")} className="px-4 py-2 bg-emerald-500 hover:bg-emerald-400 text-[#0F0F12] font-semibold text-sm rounded-lg transition-all flex items-center gap-2">
                  <MessageSquare className="w-4 h-4" /> QR Kod Tara
                </button>
              </div>
              <div className="text-center">
                <button type="button" onClick={next} className="text-slate-500 hover:text-slate-300 text-xs transition-colors">Bu adimi atla &raquo;</button>
              </div>
            </div>
          )}

          {/* Step 5: Summary */}
          {step === 5 && (
            <div className="space-y-4 text-center">
              <div className="w-16 h-16 rounded-full bg-emerald-500/10 flex items-center justify-center mx-auto">
                <ShieldCheck className="w-8 h-8 text-emerald-500" />
              </div>
              <h3 className="text-white font-semibold text-lg">Kayit Ozeti</h3>
              <div className="bg-[#232328] rounded-xl p-4 text-left space-y-2">
                <div className="flex justify-between text-sm"><span className="text-slate-500">Ad Soyad:</span><span className="text-white">{form.firstName} {form.lastName}</span></div>
                <div className="flex justify-between text-sm"><span className="text-slate-500">Telefon:</span><span className="text-white">{form.phone}</span></div>
                <div className="flex justify-between text-sm"><span className="text-slate-500">Arac:</span><span className="text-white">{form.vehicleType || "-"} ({form.plate || "-"})</span></div>
                <div className="flex justify-between text-sm"><span className="text-slate-500">Belgeler:</span><span className="text-emerald-400">{[form.license, form.src, form.psycho].filter(Boolean).length}/3 Yuklendi</span></div>
              </div>
              <p className="text-slate-500 text-xs">Bilgilerinizi onayliyor musunuz? KYC evraklariniz yapay zeka tarafindan incelenecektir.</p>
            </div>
          )}

          {/* Buttons */}
          <div className="flex items-center gap-3 mt-6">
            {step > 1 && (
              <button onClick={prev} className="flex items-center justify-center gap-1.5 px-4 py-2.5 bg-[#232328] hover:bg-[#2E2E35] text-slate-300 rounded-lg text-sm font-medium transition-all">
                <ArrowLeft className="w-4 h-4" /> Geri
              </button>
            )}
            {step < 5 ? (
              <button onClick={next} className="flex-1 flex items-center justify-center gap-1.5 py-2.5 bg-amber-500 hover:bg-amber-400 text-[#0F0F12] font-semibold rounded-lg text-sm transition-all">
                <span>Devam</span><ArrowRight className="w-4 h-4" />
              </button>
            ) : (
              <button onClick={() => alert("Kayit basarili! KYC evraklariniz inceleniyor.")} className="flex-1 flex items-center justify-center gap-1.5 py-2.5 bg-emerald-500 hover:bg-emerald-400 text-[#0F0F12] font-semibold rounded-lg text-sm transition-all">
                <CheckCircle className="w-4 h-4" /> Kaydi Tamamla
              </button>
            )}
          </div>

          <div className="mt-4 text-center">
            <Link to="/login" className="text-slate-500 hover:text-slate-300 text-xs transition-colors">Zaten hesabiniz var mi? Giris Yap</Link>
          </div>
        </div>
      </div>
    </div>
  );
}
