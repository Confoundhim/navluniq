import { useState } from "react";
import { Link } from "react-router";
import {
  Truck, ArrowRight, ArrowLeft, CheckCircle, User, Phone, KeyRound,
  Building2, FileText, MapPin, Crown, Globe, Check, ShieldCheck
} from "lucide-react";

type Step = 1 | 2 | 3 | 4 | 5;

export default function ShipperRegister() {
  const [step, setStep] = useState<Step>(1);
  const [accountType, setAccountType] = useState<"individual" | "corporate">("individual");
  const [form, setForm] = useState({
    firstName: "", lastName: "", phone: "", password: "", confirmPassword: "",
    companyName: "", taxNumber: "", taxOffice: "",
    address: "", city: "", district: "",
    taxDoc: "", companyDoc: "",
    plan: "free",
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
        if (accountType === "corporate" && !form.companyName) { setError("Sirket unvani zorunludur."); return false; }
        return true;
      case 2:
        if (accountType === "corporate" && !form.taxDoc) { setError("Vergi levhasi yukleyin."); return false; }
        return true;
      case 3:
        if (!form.address || !form.city) { setError("Adres ve sehir bilgisi zorunludur."); return false; }
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
    { num: 2, label: "Belgeler", icon: FileText },
    { num: 3, label: "Adres", icon: MapPin },
    { num: 4, label: "Abonelik", icon: Crown },
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
            <p className="text-slate-500 text-xs mt-1">Yuk Sahibi Kayit - Adim {step}/5</p>
          </div>

          {/* Step indicator */}
          <div className="flex items-center justify-between mb-6 px-2">
            {steps.map(s => {
              const Icon = s.icon;
              return (
                <div key={s.num} className="flex flex-col items-center gap-1">
                  <div className={`w-8 h-8 rounded-full flex items-center justify-center transition-all ${step >= s.num ? "bg-amber-500 text-[#0F0F12]" : "bg-[#232328] text-slate-600"}`}>
                    <Icon className="w-4 h-4" />
                  </div>
                  <span className={`text-[10px] ${step >= s.num ? "text-amber-500" : "text-slate-600"}`}>{s.label}</span>
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
              <h3 className="text-white font-semibold mb-1">Hesap Bilgileri</h3>
              {/* Account Type */}
              <div className="flex gap-2 p-1 bg-[#232328] rounded-lg">
                <button onClick={() => setAccountType("individual")} className={`flex-1 py-2 rounded-md text-sm font-medium transition-all ${accountType === "individual" ? "bg-amber-500 text-[#0F0F12]" : "text-slate-400 hover:text-white"}`}>Bireysel</button>
                <button onClick={() => setAccountType("corporate")} className={`flex-1 py-2 rounded-md text-sm font-medium transition-all ${accountType === "corporate" ? "bg-amber-500 text-[#0F0F12]" : "text-slate-400 hover:text-white"}`}>Kurumsal</button>
              </div>
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
              {accountType === "corporate" && (
                <div>
                  <label className="block text-xs text-slate-400 mb-1.5">Sirket Unvani <span className="text-red-400">*</span></label>
                  <div className="relative">
                    <Building2 className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-600" />
                    <input type="text" value={form.companyName} onChange={e => update("companyName", e.target.value)} className="w-full h-9 pl-10 pr-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-amber-500" placeholder="ABC Lojistik Ltd. Sti." />
                  </div>
                </div>
              )}
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
              <p className="text-slate-500 text-xs">Belgeleriniz AI OCR ile taranacak ve dogrulanacaktir.</p>
              {accountType === "corporate" ? (
                <>
                  <div className="p-3 bg-[#232328] border border-[#2E2E35] rounded-lg">
                    <div className="flex items-center gap-3">
                      <div className="w-10 h-10 rounded-lg bg-amber-500/10 flex items-center justify-center shrink-0">
                        <FileText className="w-5 h-5 text-amber-500" />
                      </div>
                      <div className="flex-1">
                        <p className="text-white text-sm font-medium">Vergi Levhasi <span className="text-red-400">*</span></p>
                        <p className="text-slate-600 text-xs">PDF, JPG veya PNG</p>
                      </div>
                    </div>
                    <div className="mt-2">
                      <input type="file" accept=".pdf,.jpg,.jpeg,.png" onChange={e => update("taxDoc", e.target.files?.[0]?.name || "")} className="hidden" id="file-tax" />
                      <label htmlFor="file-tax" className="px-3 py-1.5 bg-[#2E2E35] hover:bg-[#3E3E45] text-slate-300 text-xs rounded-lg cursor-pointer transition-colors inline-flex items-center gap-1.5">
                        <FileText className="w-3.5 h-3.5" /> Belge Yukle
                      </label>
                      {form.taxDoc && <span className="ml-2 text-emerald-400 text-xs flex items-center gap-1 inline-flex"><Check className="w-3 h-3" /> Yuklendi</span>}
                    </div>
                  </div>
                  <div className="p-3 bg-[#232328] border border-[#2E2E35] rounded-lg">
                    <div className="flex items-center gap-3">
                      <div className="w-10 h-10 rounded-lg bg-blue-500/10 flex items-center justify-center shrink-0">
                        <Building2 className="w-5 h-5 text-blue-500" />
                      </div>
                      <div className="flex-1">
                        <p className="text-white text-sm font-medium">Sirket Beyani (Opsiyonel)</p>
                        <p className="text-slate-600 text-xs">Imzali sirket beyani</p>
                      </div>
                    </div>
                    <div className="mt-2">
                      <input type="file" accept=".pdf,.jpg,.jpeg,.png" onChange={e => update("companyDoc", e.target.files?.[0]?.name || "")} className="hidden" id="file-company" />
                      <label htmlFor="file-company" className="px-3 py-1.5 bg-[#2E2E35] hover:bg-[#3E3E45] text-slate-300 text-xs rounded-lg cursor-pointer transition-colors inline-flex items-center gap-1.5">
                        <FileText className="w-3.5 h-3.5" /> Belge Yukle
                      </label>
                      {form.companyDoc && <span className="ml-2 text-emerald-400 text-xs flex items-center gap-1 inline-flex"><Check className="w-3 h-3" /> Yuklendi</span>}
                    </div>
                  </div>
                </>
              ) : (
                <div className="p-4 bg-[#232328] border border-[#2E2E35] rounded-lg text-center">
                  <p className="text-slate-400 text-sm">Bireysel yuk sahipleri icin ek belge yuklemesi gerekmemektedir. Telefon dogrulamasi yeterlidir.</p>
                </div>
              )}
            </div>
          )}

          {/* Step 3: Address */}
          {step === 3 && (
            <div className="space-y-4">
              <h3 className="text-white font-semibold mb-1">Adres Bilgileri</h3>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs text-slate-400 mb-1.5">Sehir <span className="text-red-400">*</span></label>
                  <div className="relative">
                    <MapPin className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-600" />
                    <input type="text" value={form.city} onChange={e => update("city", e.target.value)} className="w-full h-9 pl-10 pr-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-amber-500" placeholder="Ankara" />
                  </div>
                </div>
                <div>
                  <label className="block text-xs text-slate-400 mb-1.5">İlce</label>
                  <input type="text" value={form.district} onChange={e => update("district", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-amber-500" placeholder="Cankaya" />
                </div>
              </div>
              <div>
                <label className="block text-xs text-slate-400 mb-1.5">Adres <span className="text-red-400">*</span></label>
                <textarea value={form.address} onChange={e => update("address", e.target.value)} rows={3} className="w-full px-3 py-2 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-amber-500 resize-none" placeholder="Acik adres..." />
              </div>
              {accountType === "corporate" && (
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block text-xs text-slate-400 mb-1.5">Vergi No</label>
                    <input type="text" value={form.taxNumber} onChange={e => update("taxNumber", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-amber-500" placeholder="1234567890" />
                  </div>
                  <div>
                    <label className="block text-xs text-slate-400 mb-1.5">Vergi Dairesi</label>
                    <input type="text" value={form.taxOffice} onChange={e => update("taxOffice", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-amber-500" placeholder="Cankaya VD" />
                  </div>
                </div>
              )}
            </div>
          )}

          {/* Step 4: Subscription */}
          {step === 4 && (
            <div className="space-y-4">
              <h3 className="text-white font-semibold mb-1">Abonelik Secimi</h3>
              <p className="text-slate-500 text-xs">Yuk sahibi olarak ucretsiz kullanmaya baslayabilirsiniz.</p>
              <div className="grid grid-cols-2 gap-3">
                <button onClick={() => update("plan", "free")} className={`p-4 rounded-xl border text-center transition-all ${form.plan === "free" ? "border-amber-500 bg-amber-500/10" : "border-[#2E2E35] hover:border-[#3E3E45]"}`}>
                  <Globe className="w-6 h-6 text-slate-400 mx-auto mb-2" />
                  <p className={`text-sm font-semibold ${form.plan === "free" ? "text-amber-500" : "text-white"}`}>Ucretsiz</p>
                  <p className="text-slate-500 text-xs mt-1">İlan verme, teklif alma</p>
                </button>
                <button onClick={() => update("plan", "premium")} className={`p-4 rounded-xl border text-center transition-all ${form.plan === "premium" ? "border-amber-500 bg-amber-500/10" : "border-[#2E2E35] hover:border-[#3E3E45]"}`}>
                  <Crown className="w-6 h-6 text-amber-500 mx-auto mb-2" />
                  <p className={`text-sm font-semibold ${form.plan === "premium" ? "text-amber-500" : "text-white"}`}>Premium</p>
                  <p className="text-slate-500 text-xs mt-1">Ekstra ozellikler</p>
                </button>
              </div>
              {form.plan === "premium" && (
                <div className="p-3 bg-amber-500/5 border border-amber-500/20 rounded-lg">
                  <p className="text-amber-400 text-xs">Premium yuk sahibi aboneligi ozellikleri: Oncelikli ilan listeleme, ozel destek hatti, detayli raporlama.</p>
                </div>
              )}
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
                <div className="flex justify-between text-sm"><span className="text-slate-500">Tip:</span><span className="text-white">{accountType === "corporate" ? "Kurumsal" : "Bireysel"}</span></div>
                {accountType === "corporate" && <div className="flex justify-between text-sm"><span className="text-slate-500">Sirket:</span><span className="text-white">{form.companyName}</span></div>}
                <div className="flex justify-between text-sm"><span className="text-slate-500">Adres:</span><span className="text-white">{form.city}{form.district ? `, ${form.district}` : ""}</span></div>
                <div className="flex justify-between text-sm"><span className="text-slate-500">Plan:</span><span className="text-amber-400">{form.plan === "premium" ? "Premium" : "Ucretsiz"}</span></div>
              </div>
              <p className="text-slate-500 text-xs">Bilgilerinizi onayliyor musunuz?</p>
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
              <button onClick={() => alert("Kayit basarili! Hos geldiniz.")} className="flex-1 flex items-center justify-center gap-1.5 py-2.5 bg-emerald-500 hover:bg-emerald-400 text-[#0F0F12] font-semibold rounded-lg text-sm transition-all">
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
