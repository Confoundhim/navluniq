import { useState } from "react";
import { Link } from "react-router";
import {
  ArrowRight, ArrowLeft, CheckCircle, Package, Scale, MapPin,
  Truck, Wallet, Calendar, FileText, AlertTriangle
} from "lucide-react";

const loadTypes = [
  { id: "palletized", label: "Paletli", desc: "Paletize edilmiş ürünler" },
  { id: "bulk", label: "Dökme", desc: "Dökme yükler" },
  { id: "container", label: "Konteyner", desc: "Konteyner taşımacılığı" },
  { id: "household", label: "Ev Eşyası", desc: "Taşınma/depolama" },
  { id: "heavy", label: "Ağır Makine", desc: "İş makineleri, vinç gerektiren" },
  { id: "refrigerated", label: "Soğuk Zincir", desc: "Gıda, ilaç vb." },
  { id: "hazardous", label: "Tehlikeli Madde", desc: "ADR sertifikalı taşıma" },
];

const vehicleTypes = ["Otomobil", "Minivan", "Orta Panelvan", "Uzun Panelvan", "Kamyonet", "6 Teker", "8 Teker", "10 Teker", "Kırkayak", "Tır"];

const cities = ["Adana", "Ankara", "Antalya", "Bursa", "Denizli", "Diyarbakır", "Eskişehir", "Gaziantep", "Hatay", "İstanbul", "İzmir", "Kayseri", "Konya", "Malatya", "Manisa", "Mersin", "Muğla", "Sakarya", "Samsun", "Trabzon", "Van"];

export default function NewLoad() {
  const [step, setStep] = useState(1);
  const [form, setForm] = useState({
    loadType: "", weight: "", length: "", width: "", height: "",
    palletCount: "",
    originCity: "", originDistrict: "", originAddress: "",
    destCity: "", destDistrict: "", destAddress: "",
    vehicleType: "", needsTrailer: false, liftNeeded: false,
    budget: "", priceType: "fixed",
    loadingDate: "", deliveryDate: "", flexible: false,
    title: "", description: "", contactName: "", contactPhone: "",
    eWaybill: false, insurance: false,
  });
  const [error, setError] = useState("");

  const update = (field: string, val: string | boolean) => {
    setForm(prev => ({ ...prev, [field]: val }));
    setError("");
  };

  const validate = () => {
    setError("");
    switch (step) {
      case 1: if (!form.loadType) { setError("Yük türü seçin."); return false; } break;
      case 2: if (!form.weight) { setError("Ağırlık girin."); return false; } break;
      case 3: if (!form.originCity || !form.destCity) { setError("Yükleme ve teslimat şehri girin."); return false; } break;
      case 4: if (!form.vehicleType) { setError("Araç tipi seçin."); return false; } break;
      case 5: if (!form.budget) { setError("Bütçe girin."); return false; } break;
      case 6: if (!form.loadingDate) { setError("Yükleme tarihi seçin."); return false; } break;
      case 7: if (!form.title) { setError("İlan başlığı girin."); return false; } break;
    }
    return true;
  };

  const next = () => { if (validate()) setStep(s => Math.min(8, s + 1)); };
  const prev = () => { setError(""); setStep(s => Math.max(1, s - 1)); };

  const steps = [
    { num: 1, label: "Yük Türü", icon: Package },
    { num: 2, label: "Kapasite", icon: Scale },
    { num: 3, label: "Rota", icon: MapPin },
    { num: 4, label: "Araç", icon: Truck },
    { num: 5, label: "Fiyat", icon: Wallet },
    { num: 6, label: "Tarih", icon: Calendar },
    { num: 7, label: "Detay", icon: FileText },
  ];

  if (step === 8) {
    return (
      <div className="max-w-lg mx-auto text-center space-y-6 py-12">
        <div className="w-20 h-20 rounded-full bg-emerald-500/10 flex items-center justify-center mx-auto">
          <CheckCircle className="w-10 h-10 text-emerald-500" />
        </div>
        <h2 className="text-2xl font-bold text-white">İlanınız Oluşturuldu!</h2>
        <p className="text-slate-400">İlanınız onay sürecine alındı. Onaylandıktan sonra şoförler teklif vermeye başlayacak.</p>
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-4 text-left space-y-2">
          <p className="text-white font-medium">{form.title}</p>
          <p className="text-slate-500 text-xs">{form.originCity} → {form.destCity} | {form.weight} kg | Bütçe: ₺{form.budget}</p>
        </div>
        <div className="flex gap-3 justify-center">
          <Link to="/shipper/loads" className="px-6 py-2.5 bg-emerald-500 hover:bg-emerald-400 text-[#0F0F12] font-semibold rounded-lg transition-all text-sm">İlanlarım</Link>
          <Link to="/shipper" className="px-6 py-2.5 bg-[#232328] hover:bg-[#2E2E35] text-white rounded-lg transition-all text-sm">Ana Sayfa</Link>
        </div>
      </div>
    );
  }

  return (
    <div className="max-w-3xl mx-auto space-y-6">
      {/* Header */}
      <div>
        <h1 className="text-2xl font-semibold text-white tracking-tight">Yeni İlan Oluştur</h1>
        <p className="text-slate-500 text-sm mt-1">Adım {step}/7 — {steps[step-1].label}</p>
      </div>

      {/* Progress */}
      <div className="flex items-center gap-2">
        {steps.map(s => (
          <div key={s.num} className="flex-1">
            <div className={`h-1.5 rounded-full transition-all ${step >= s.num ? "bg-emerald-500" : "bg-[#2E2E35]"}`} />
          </div>
        ))}
      </div>

      {error && (
        <div className="p-3 rounded-lg bg-red-500/10 border-l-2 border-red-500 text-red-400 text-sm">{error}</div>
      )}

      <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-6">
        {/* Step 1: Load Type */}
        {step === 1 && (
          <div className="space-y-4">
            <h3 className="text-white font-semibold">Yük Türünü Seçin</h3>
            <div className="grid sm:grid-cols-2 gap-3">
              {loadTypes.map(t => (
                <button key={t.id} onClick={() => update("loadType", t.id)} className={`p-4 rounded-xl border text-left transition-all ${form.loadType === t.id ? "border-emerald-500 bg-emerald-500/10" : "border-[#2E2E35] hover:border-[#3E3E45]"}`}>
                  <p className={`text-sm font-medium ${form.loadType === t.id ? "text-emerald-500" : "text-white"}`}>{t.label}</p>
                  <p className="text-slate-500 text-xs mt-1">{t.desc}</p>
                </button>
              ))}
            </div>
          </div>
        )}

        {/* Step 2: Capacity */}
        {step === 2 && (
          <div className="space-y-4">
            <h3 className="text-white font-semibold">Kapasite ve Ölçüler</h3>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="block text-xs text-slate-400 mb-1.5">Ağırlık (kg) *</label>
                <input type="number" value={form.weight} onChange={e => update("weight", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-emerald-500" placeholder="5000" />
              </div>
              <div>
                <label className="block text-xs text-slate-400 mb-1.5">Palet Sayısı</label>
                <input type="number" value={form.palletCount} onChange={e => update("palletCount", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-emerald-500" placeholder="10" />
              </div>
            </div>
            <p className="text-slate-500 text-xs">Hacim (Opsiyonel)</p>
            <div className="grid grid-cols-3 gap-3">
              <div><label className="block text-xs text-slate-400 mb-1.5">Uzunluk (m)</label><input type="number" value={form.length} onChange={e => update("length", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm" placeholder="2" /></div>
              <div><label className="block text-xs text-slate-400 mb-1.5">Genişlik (m)</label><input type="number" value={form.width} onChange={e => update("width", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm" placeholder="1" /></div>
              <div><label className="block text-xs text-slate-400 mb-1.5">Yükseklik (m)</label><input type="number" value={form.height} onChange={e => update("height", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm" placeholder="1.5" /></div>
            </div>
          </div>
        )}

        {/* Step 3: Route */}
        {step === 3 && (
          <div className="space-y-4">
            <h3 className="text-white font-semibold">Rota Bilgileri</h3>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="block text-xs text-slate-400 mb-1.5">Yükleme Şehri *</label>
                <select value={form.originCity} onChange={e => update("originCity", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-emerald-500">
                  <option value="">Seçin</option>
                  {cities.map(c => <option key={c} value={c}>{c}</option>)}
                </select>
              </div>
              <div>
                <label className="block text-xs text-slate-400 mb-1.5">İlçe</label>
                <input type="text" value={form.originDistrict} onChange={e => update("originDistrict", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm" placeholder="Çankaya" />
              </div>
            </div>
            <div>
              <label className="block text-xs text-slate-400 mb-1.5">Yükleme Adresi</label>
              <textarea value={form.originAddress} onChange={e => update("originAddress", e.target.value)} rows={2} className="w-full px-3 py-2 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm resize-none" placeholder="Açık adres..." />
            </div>
            <div className="border-t border-[#2E2E35] pt-4">
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs text-slate-400 mb-1.5">Teslimat Şehri *</label>
                  <select value={form.destCity} onChange={e => update("destCity", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-emerald-500">
                    <option value="">Seçin</option>
                    {cities.map(c => <option key={c} value={c}>{c}</option>)}
                  </select>
                </div>
                <div>
                  <label className="block text-xs text-slate-400 mb-1.5">İlçe</label>
                  <input type="text" value={form.destDistrict} onChange={e => update("destDistrict", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm" placeholder="Kadıköy" />
                </div>
              </div>
            </div>
          </div>
        )}

        {/* Step 4: Vehicle */}
        {step === 4 && (
          <div className="space-y-4">
            <h3 className="text-white font-semibold">Araç ve Ekipman</h3>
            <label className="block text-xs text-slate-400 mb-1.5">İstenen Araç Tipi *</label>
            <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
              {vehicleTypes.map(v => (
                <button key={v} onClick={() => update("vehicleType", v)} className={`p-2.5 rounded-lg border text-sm transition-all ${form.vehicleType === v ? "border-emerald-500 bg-emerald-500/10 text-emerald-500" : "border-[#2E2E35] text-slate-400 hover:border-[#3E3E45]"}`}>
                  {v}
                </button>
              ))}
            </div>
            <div className="space-y-2 pt-2">
              <label className="flex items-center gap-2 text-sm text-slate-300 cursor-pointer">
                <input type="checkbox" checked={form.needsTrailer} onChange={e => update("needsTrailer", e.target.checked)} className="rounded border-[#2E2E35]" /> Dorse Gerekiyor
              </label>
              <label className="flex items-center gap-2 text-sm text-slate-300 cursor-pointer">
                <input type="checkbox" checked={form.liftNeeded} onChange={e => update("liftNeeded", e.target.checked)} className="rounded border-[#2E2E35]" /> Kepçe/Yükleme Rampası Gerekiyor
              </label>
            </div>
          </div>
        )}

        {/* Step 5: Price */}
        {step === 5 && (
          <div className="space-y-4">
            <h3 className="text-white font-semibold">Fiyatlandırma</h3>
            <div className="flex gap-2 p-1 bg-[#232328] rounded-lg w-fit">
              <button onClick={() => update("priceType", "fixed")} className={`px-4 py-2 rounded-md text-sm transition-all ${form.priceType === "fixed" ? "bg-emerald-500 text-[#0F0F12] font-medium" : "text-slate-400"}`}>Sabit Fiyat</button>
              <button onClick={() => update("priceType", "auction")} className={`px-4 py-2 rounded-md text-sm transition-all ${form.priceType === "auction" ? "bg-emerald-500 text-[#0F0F12] font-medium" : "text-slate-400"}`}>Teklif Al</button>
            </div>
            <div>
              <label className="block text-xs text-slate-400 mb-1.5">{form.priceType === "fixed" ? "Navlun Bedeli (₺) *" : "Maksimum Bütçe (₺) *"}</label>
              <input type="number" value={form.budget} onChange={e => update("budget", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-emerald-500" placeholder="8500" />
            </div>
            {form.priceType === "auction" && (
              <div className="p-3 bg-amber-500/5 border border-amber-500/20 rounded-lg flex items-start gap-2">
                <AlertTriangle className="w-4 h-4 text-amber-500 shrink-0 mt-0.5" />
                <p className="text-amber-400 text-xs">Teklif al modunda, şoförler bütçenizin altında teklif verebilir. En uygun teklifi siz seçersiniz.</p>
              </div>
            )}
          </div>
        )}

        {/* Step 6: Date */}
        {step === 6 && (
          <div className="space-y-4">
            <h3 className="text-white font-semibold">Tarih ve Zaman</h3>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="block text-xs text-slate-400 mb-1.5">Yükleme Tarihi *</label>
                <input type="date" value={form.loadingDate} onChange={e => update("loadingDate", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-emerald-500" />
              </div>
              <div>
                <label className="block text-xs text-slate-400 mb-1.5">Teslimat Tarihi</label>
                <input type="date" value={form.deliveryDate} onChange={e => update("deliveryDate", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-emerald-500" />
              </div>
            </div>
            <label className="flex items-center gap-2 text-sm text-slate-300 cursor-pointer">
              <input type="checkbox" checked={form.flexible} onChange={e => update("flexible", e.target.checked)} className="rounded border-[#2E2E35]" /> Tarih Esnekliği Var (+/- 1 gün)
            </label>
          </div>
        )}

        {/* Step 7: Details */}
        {step === 7 && (
          <div className="space-y-4">
            <h3 className="text-white font-semibold">İlan Detayları</h3>
            <div>
              <label className="block text-xs text-slate-400 mb-1.5">İlan Başlığı *</label>
              <input type="text" value={form.title} onChange={e => update("title", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-emerald-500" placeholder={`${form.originCity} → ${form.destCity} - ${form.loadType}`} />
            </div>
            <div>
              <label className="block text-xs text-slate-400 mb-1.5">Açıklama</label>
              <textarea value={form.description} onChange={e => update("description", e.target.value)} rows={3} className="w-full px-3 py-2 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm resize-none" placeholder="Yük hakkında detaylı bilgi..." />
            </div>
            <div className="space-y-2">
              <label className="flex items-center gap-2 text-sm text-slate-300 cursor-pointer">
                <input type="checkbox" checked={form.eWaybill} onChange={e => update("eWaybill", e.target.checked)} className="rounded border-[#2E2E35]" /> E-Fatura / E-İrsaliye Var
              </label>
              <label className="flex items-center gap-2 text-sm text-slate-300 cursor-pointer">
                <input type="checkbox" checked={form.insurance} onChange={e => update("insurance", e.target.checked)} className="rounded border-[#2E2E35]" /> Kargo Sigortası İstiyorum
              </label>
            </div>
          </div>
        )}
      </div>

      {/* Navigation */}
      <div className="flex items-center gap-3">
        {step > 1 && (
          <button onClick={prev} className="flex items-center gap-1.5 px-5 py-2.5 bg-[#232328] hover:bg-[#2E2E35] text-slate-300 rounded-lg text-sm font-medium transition-all">
            <ArrowLeft className="w-4 h-4" /> Geri
          </button>
        )}
        <button onClick={next} className="flex-1 flex items-center justify-center gap-1.5 py-2.5 bg-emerald-500 hover:bg-emerald-400 text-[#0F0F12] font-semibold rounded-lg text-sm transition-all">
          {step === 7 ? <><CheckCircle className="w-4 h-4" /> İlanı Yayınla</> : <><span>Devam</span><ArrowRight className="w-4 h-4" /></>}
        </button>
      </div>
    </div>
  );
}
