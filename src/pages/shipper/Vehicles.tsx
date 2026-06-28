import { useState } from "react";
import { Truck, Plus, Edit, Trash2, Check } from "lucide-react";

const vehicleTypes = ["Otomobil", "Minivan", "Orta Panelvan", "Uzun Panelvan", "Kamyonet", "6 Teker", "8 Teker", "10 Teker", "Kirkayak", "Tir"];

const demoVehicles = [
  { id: 1, type: "10 Teker", plate: "34 ABC 123", brand: "Mercedes", model: "Actros", year: 2020, capacity: 15000, status: "active" },
  { id: 2, type: "Kamyonet", plate: "06 XYZ 456", brand: "Ford", model: "Transit", year: 2022, capacity: 2000, status: "active" },
];

export default function Vehicles() {
  const [vehicles, setVehicles] = useState(demoVehicles);
  const [showAdd, setShowAdd] = useState(false);
  const [form, setForm] = useState({ type: "", plate: "", brand: "", model: "", year: "", capacity: "" });

  const addVehicle = () => {
    if (!form.type || !form.plate) return;
    setVehicles([...vehicles, { id: Date.now(), type: form.type, plate: form.plate, brand: form.brand, model: form.model, year: Number(form.year) || 2020, capacity: Number(form.capacity) || 0, status: "active" as const }]);
    setForm({ type: "", plate: "", brand: "", model: "", year: "", capacity: "" });
    setShowAdd(false);
  };

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-white tracking-tight">Arac Yonetimi</h1>
          <p className="text-slate-500 text-sm mt-1">Tasima araclariniz</p>
        </div>
        <button onClick={() => setShowAdd(!showAdd)} className="flex items-center gap-2 px-4 py-2 bg-emerald-500 hover:bg-emerald-400 text-[#0F0F12] font-semibold rounded-lg text-sm transition-all"><Plus className="w-4 h-4" /> Arac Ekle</button>
      </div>
      {showAdd && (
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5 space-y-3">
          <h3 className="text-white font-semibold text-sm">Yeni Arac</h3>
          <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
            <select value={form.type} onChange={e => setForm({...form, type: e.target.value})} className="h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm"><option value="">Tip</option>{vehicleTypes.map(v => <option key={v}>{v}</option>)}</select>
            <input value={form.plate} onChange={e => setForm({...form, plate: e.target.value})} className="h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm" placeholder="Plaka" />
            <input value={form.brand} onChange={e => setForm({...form, brand: e.target.value})} className="h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm" placeholder="Marka" />
            <input value={form.model} onChange={e => setForm({...form, model: e.target.value})} className="h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm" placeholder="Model" />
            <input value={form.year} onChange={e => setForm({...form, year: e.target.value})} className="h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm" placeholder="Yil" />
            <input value={form.capacity} onChange={e => setForm({...form, capacity: e.target.value})} className="h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm" placeholder="Kapasite (kg)" />
          </div>
          <div className="flex gap-2">
            <button onClick={addVehicle} className="px-4 py-2 bg-emerald-500 text-[#0F0F12] font-semibold rounded-lg text-sm"><Check className="w-4 h-4 inline" /> Kaydet</button>
            <button onClick={() => setShowAdd(false)} className="px-4 py-2 bg-[#232328] text-slate-400 rounded-lg text-sm">İptal</button>
          </div>
        </div>
      )}
      <div className="space-y-3">
        {vehicles.map(v => (
          <div key={v.id} className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-4 flex items-center gap-4 hover:border-[#3E3E45] transition-all">
            <div className="w-12 h-12 rounded-xl bg-emerald-500/10 flex items-center justify-center shrink-0"><Truck className="w-6 h-6 text-emerald-500" /></div>
            <div className="flex-1">
              <p className="text-white text-sm font-medium">{v.brand} {v.model} ({v.year})</p>
              <p className="text-slate-500 text-xs">{v.type} | {v.plate} | {Number(v.capacity).toLocaleString()} kg</p>
            </div>
            <div className="flex items-center gap-1">
              <button className="p-1.5 rounded-lg hover:bg-white/5 text-slate-400 hover:text-amber-400"><Edit className="w-4 h-4" /></button>
              <button onClick={() => setVehicles(vehicles.filter(x => x.id !== v.id))} className="p-1.5 rounded-lg hover:bg-white/5 text-slate-400 hover:text-red-400"><Trash2 className="w-4 h-4" /></button>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
