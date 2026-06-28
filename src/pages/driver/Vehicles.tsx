import { useState } from "react";
import {
  Truck, Plus, Edit, Trash2, Check, X,
  FileText, Star, AlertTriangle,
} from "lucide-react";

const vehicleTypes = ["Otomobil", "Minivan", "Orta Panelvan", "Uzun Panelvan", "Kamyonet", "6 Teker", "8 Teker", "10 Teker", "Kırkayak", "Tır"];

const bodyTypes = ["Kapalı Kasa", "Açık Kasa", "Konteyner Taşıyıcı", "Tanker", "Damperli", "Lowbed", "Soğutuculu", "Tenteli"];

interface Vehicle {
  id: number;
  type: string;
  bodyType: string;
  plate: string;
  brand: string;
  model: string;
  year: number;
  capacity: number;
  length: number;
  width: number;
  height: number;
  status: "active" | "maintenance" | "inactive";
  documents: { name: string; status: "valid" | "expired" | "pending" }[];
}

const demoVehicles: Vehicle[] = [
  {
    id: 1, type: "Tır", bodyType: "Kapalı Kasa", plate: "34 ABC 123", brand: "Mercedes", model: "Actros 1845", year: 2020, capacity: 24000,
    length: 13.6, width: 2.45, height: 2.7, status: "active",
    documents: [
      { name: "Ruhsat", status: "valid" },
      { name: "Sigorta", status: "valid" },
      { name: "Kasko", status: "valid" },
      { name: "Egzoz Emisyon", status: "valid" },
    ],
  },
  {
    id: 2, type: "10 Teker", bodyType: "Açık Kasa", plate: "06 XYZ 456", brand: "Ford", model: "Cargo 2533", year: 2022, capacity: 15000,
    length: 9.0, width: 2.45, height: 2.5, status: "active",
    documents: [
      { name: "Ruhsat", status: "valid" },
      { name: "Sigorta", status: "valid" },
      { name: "Kasko", status: "expired" },
    ],
  },
];

export default function Vehicles() {
  const [vehicles, setVehicles] = useState<Vehicle[]>(demoVehicles);
  const [showAdd, setShowAdd] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [expandedId, setExpandedId] = useState<number | null>(1);
  const [form, setForm] = useState({
    type: "", bodyType: "", plate: "", brand: "", model: "", year: "",
    capacity: "", length: "", width: "", height: "",
  });

  const updateForm = (field: string, value: string) => setForm(prev => ({ ...prev, [field]: value }));

  const addVehicle = () => {
    if (!form.type || !form.plate || !form.brand) return;
    const newVehicle: Vehicle = {
      id: Date.now(),
      type: form.type, bodyType: form.bodyType, plate: form.plate, brand: form.brand,
      model: form.model, year: Number(form.year) || 2024, capacity: Number(form.capacity) || 0,
      length: Number(form.length) || 0, width: Number(form.width) || 0, height: Number(form.height) || 0,
      status: "active",
      documents: [
        { name: "Ruhsat", status: "pending" },
        { name: "Sigorta", status: "pending" },
      ],
    };
    setVehicles([...vehicles, newVehicle]);
    resetForm();
  };

  const updateVehicle = () => {
    if (!editingId) return;
    setVehicles(vehicles.map(v => v.id === editingId ? {
      ...v,
      type: form.type || v.type, bodyType: form.bodyType || v.bodyType, plate: form.plate || v.plate,
      brand: form.brand || v.brand, model: form.model || v.model,
      year: Number(form.year) || v.year, capacity: Number(form.capacity) || v.capacity,
      length: Number(form.length) || v.length, width: Number(form.width) || v.width, height: Number(form.height) || v.height,
    } : v));
    resetForm();
  };

  const resetForm = () => {
    setForm({ type: "", bodyType: "", plate: "", brand: "", model: "", year: "", capacity: "", length: "", width: "", height: "" });
    setShowAdd(false);
    setEditingId(null);
  };

  const startEdit = (v: Vehicle) => {
    setForm({
      type: v.type, bodyType: v.bodyType, plate: v.plate, brand: v.brand,
      model: v.model, year: String(v.year), capacity: String(v.capacity),
      length: String(v.length), width: String(v.width), height: String(v.height),
    });
    setEditingId(v.id);
    setShowAdd(true);
  };

  const statusConfig = {
    active: { label: "Aktif", color: "bg-emerald-500/10 text-emerald-400" },
    maintenance: { label: "Bakımda", color: "bg-amber-500/10 text-amber-400" },
    inactive: { label: "Pasif", color: "bg-slate-500/10 text-slate-400" },
  };

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-white tracking-tight">Araçlarım</h1>
          <p className="text-slate-500 text-sm mt-1">Taşıma araçlarınızı yönetin</p>
        </div>
        <button onClick={() => { resetForm(); setShowAdd(true); }} className="flex items-center gap-2 px-4 py-2 bg-blue-500 hover:bg-blue-400 text-white font-semibold rounded-lg text-sm transition-all">
          <Plus className="w-4 h-4" /> Araç Ekle
        </button>
      </div>

      {/* Add/Edit Form */}
      {showAdd && (
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5 space-y-4">
          <h3 className="text-white font-semibold text-sm">{editingId ? "Araç Düzenle" : "Yeni Araç"}</h3>
          <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
            <div>
              <label className="block text-xs text-slate-400 mb-1.5">Araç Tipi *</label>
              <select value={form.type} onChange={e => updateForm("type", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm">
                <option value="">Seçin</option>
                {vehicleTypes.map(v => <option key={v}>{v}</option>)}
              </select>
            </div>
            <div>
              <label className="block text-xs text-slate-400 mb-1.5">Kasa Tipi</label>
              <select value={form.bodyType} onChange={e => updateForm("bodyType", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm">
                <option value="">Seçin</option>
                {bodyTypes.map(v => <option key={v}>{v}</option>)}
              </select>
            </div>
            <div>
              <label className="block text-xs text-slate-400 mb-1.5">Plaka *</label>
              <input value={form.plate} onChange={e => updateForm("plate", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm" placeholder="34 ABC 123" />
            </div>
            <div>
              <label className="block text-xs text-slate-400 mb-1.5">Marka *</label>
              <input value={form.brand} onChange={e => updateForm("brand", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm" placeholder="Mercedes" />
            </div>
            <div>
              <label className="block text-xs text-slate-400 mb-1.5">Model</label>
              <input value={form.model} onChange={e => updateForm("model", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm" placeholder="Actros 1845" />
            </div>
            <div>
              <label className="block text-xs text-slate-400 mb-1.5">Yıl</label>
              <input value={form.year} onChange={e => updateForm("year", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm" placeholder="2022" />
            </div>
            <div>
              <label className="block text-xs text-slate-400 mb-1.5">Kapasite (kg)</label>
              <input value={form.capacity} onChange={e => updateForm("capacity", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm" placeholder="24000" />
            </div>
            <div>
              <label className="block text-xs text-slate-400 mb-1.5">Uzunluk (m)</label>
              <input value={form.length} onChange={e => updateForm("length", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm" placeholder="13.6" />
            </div>
            <div>
              <label className="block text-xs text-slate-400 mb-1.5">Genişlik (m)</label>
              <input value={form.width} onChange={e => updateForm("width", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm" placeholder="2.45" />
            </div>
            <div>
              <label className="block text-xs text-slate-400 mb-1.5">Yükseklik (m)</label>
              <input value={form.height} onChange={e => updateForm("height", e.target.value)} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm" placeholder="2.7" />
            </div>
          </div>
          <div className="flex gap-2">
            <button onClick={editingId ? updateVehicle : addVehicle} className="px-4 py-2 bg-blue-500 text-white font-semibold rounded-lg text-sm flex items-center gap-1.5 hover:bg-blue-400 transition-all">
              <Check className="w-4 h-4" /> {editingId ? "Güncelle" : "Kaydet"}
            </button>
            <button onClick={resetForm} className="px-4 py-2 bg-[#232328] text-slate-400 rounded-lg text-sm hover:text-white transition-colors flex items-center gap-1.5">
              <X className="w-4 h-4" /> İptal
            </button>
          </div>
        </div>
      )}

      {/* Vehicle Cards */}
      <div className="space-y-3">
        {vehicles.map(v => {
          const isExpanded = expandedId === v.id;
          const status = statusConfig[v.status];
          const docIssues = v.documents.filter(d => d.status === "expired").length;

          return (
            <div key={v.id} className="bg-[#18181C] border border-[#2E2E35] rounded-xl overflow-hidden">
              <div
                className="p-4 flex items-center gap-4 cursor-pointer hover:bg-white/[0.02] transition-colors"
                onClick={() => setExpandedId(isExpanded ? null : v.id)}
              >
                <div className="w-12 h-12 rounded-xl bg-blue-500/10 flex items-center justify-center shrink-0">
                  <Truck className="w-6 h-6 text-blue-500" />
                </div>
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2 flex-wrap">
                    <p className="text-white text-sm font-medium">{v.brand} {v.model} ({v.year})</p>
                    <span className={`text-xs px-2 py-0.5 rounded-full ${status.color}`}>{status.label}</span>
                    {docIssues > 0 && (
                      <span className="text-xs px-2 py-0.5 rounded-full bg-red-500/10 text-red-400 flex items-center gap-1">
                        <AlertTriangle className="w-3 h-3" />{docIssues} Evrak
                      </span>
                    )}
                  </div>
                  <p className="text-slate-500 text-xs">{v.type} | {v.plate} | {v.capacity.toLocaleString()} kg | {v.bodyType}</p>
                </div>
                <div className="flex items-center gap-1">
                  <button onClick={e => { e.stopPropagation(); startEdit(v); }} className="p-1.5 rounded-lg hover:bg-white/5 text-slate-400 hover:text-amber-400 transition-colors">
                    <Edit className="w-4 h-4" />
                  </button>
                  <button onClick={e => { e.stopPropagation(); setVehicles(vehicles.filter(x => x.id !== v.id)); }} className="p-1.5 rounded-lg hover:bg-white/5 text-slate-400 hover:text-red-400 transition-colors">
                    <Trash2 className="w-4 h-4" />
                  </button>
                </div>
              </div>

              {isExpanded && (
                <div className="border-t border-[#2E2E35] px-4 py-4 space-y-4">
                  <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div className="bg-[#232328] rounded-lg p-3 text-center">
                      <p className="text-slate-500 text-[10px]">Kapasite</p>
                      <p className="text-white text-sm font-semibold">{v.capacity.toLocaleString()} kg</p>
                    </div>
                    <div className="bg-[#232328] rounded-lg p-3 text-center">
                      <p className="text-slate-500 text-[10px]">Hacim</p>
                      <p className="text-white text-sm font-semibold">{(v.length * v.width * v.height).toFixed(1)} m³</p>
                    </div>
                    <div className="bg-[#232328] rounded-lg p-3 text-center">
                      <p className="text-slate-500 text-[10px]">Boyutlar</p>
                      <p className="text-white text-sm font-semibold">{v.length}x{v.width}x{v.height}m</p>
                    </div>
                    <div className="bg-[#232328] rounded-lg p-3 text-center">
                      <p className="text-slate-500 text-[10px]">Kasa</p>
                      <p className="text-white text-sm font-semibold">{v.bodyType}</p>
                    </div>
                  </div>

                  {/* Documents */}
                  <div>
                    <h4 className="text-white text-xs font-medium mb-2 flex items-center gap-1.5">
                      <FileText className="w-3.5 h-3.5 text-slate-500" /> Evrak Durumu
                    </h4>
                    <div className="flex flex-wrap gap-2">
                      {v.documents.map(doc => (
                        <span key={doc.name} className={`text-xs px-2.5 py-1 rounded-full flex items-center gap-1 ${
                          doc.status === "valid" ? "bg-emerald-500/10 text-emerald-400" :
                          doc.status === "expired" ? "bg-red-500/10 text-red-400" :
                          "bg-amber-500/10 text-amber-400"
                        }`}>
                          <Star className="w-3 h-3" />{doc.name}
                        </span>
                      ))}
                    </div>
                  </div>
                </div>
              )}
            </div>
          );
        })}
      </div>
    </div>
  );
}
