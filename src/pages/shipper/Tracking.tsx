import { MapPin, Phone, MessageSquare } from "lucide-react";

const demoShipments = [
  { id: 201, load: "Ankara → İstanbul - Paletli Gıda", driver: "Ali Şen", phone: "+90 555 123 45 67", vehicle: "Tır 34 ABC 123", status: "in_transit", progress: 65, eta: "2 saat 15 dk", lastLocation: "Düzce, TEM Otoyolu" },
  { id: 202, load: "İzmir → Bursa - Konteyner", driver: "Mehmet Kaya", phone: "+90 555 987 65 43", vehicle: "Kırkayak 35 XYZ 789", status: "loading", progress: 10, eta: "Hesaplanıyor", lastLocation: "İzmir, Yükleme Noktası" },
];

export default function Tracking() {
  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-white tracking-tight">Sevkiyat Takibi</h1>
        <p className="text-slate-500 text-sm mt-1">Canlı sevkiyat durumu ve konum</p>
      </div>
      <div className="grid gap-4">
        {demoShipments.map(s => (
          <div key={s.id} className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5 hover:border-[#3E3E45] transition-all">
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-4">
              <div>
                <div className="flex items-center gap-2 mb-1">
                  <p className="text-white font-semibold">{s.load}</p>
                  <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${s.status === "in_transit" ? "bg-emerald-500/10 text-emerald-400" : "bg-amber-500/10 text-amber-400"}`}>{s.status === "in_transit" ? "Yolda" : "Yükleniyor"}</span>
                </div>
                <p className="text-slate-500 text-xs">#{s.id} | {s.vehicle}</p>
              </div>
              <div className="flex items-center gap-2">
                <button className="p-2 rounded-lg bg-[#232328] hover:bg-[#2E2E35] text-emerald-400 transition-colors"><Phone className="w-4 h-4" /></button>
                <button className="p-2 rounded-lg bg-[#232328] hover:bg-[#2E2E35] text-emerald-400 transition-colors"><MessageSquare className="w-4 h-4" /></button>
              </div>
            </div>
            <div className="space-y-2">
              <div className="flex items-center justify-between text-xs">
                <span className="text-slate-500">İlerleme: %{s.progress}</span>
                <span className="text-emerald-400">Tahmini: {s.eta}</span>
              </div>
              <div className="w-full h-2 bg-[#232328] rounded-full overflow-hidden">
                <div className="h-full bg-gradient-to-r from-emerald-500 to-emerald-400 rounded-full transition-all" style={{ width: `${s.progress}%` }} />
              </div>
              <div className="flex items-center gap-2 text-xs text-slate-500">
                <MapPin className="w-3 h-3 text-emerald-500" />
                <span>Son konum: {s.lastLocation}</span>
              </div>
            </div>
          </div>
        ))}
      </div>
      <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-6 flex flex-col items-center justify-center text-center min-h-[200px]">
        <MapPin className="w-12 h-12 text-[#2E2E35] mb-3" />
        <p className="text-slate-500 text-sm">Harita görünümü yakında aktif olacak.</p>
        <p className="text-slate-600 text-xs mt-1">Gerçek zamanlı GPS takibi ile sevkiyatlarınızı haritadan izleyebileceksiniz.</p>
      </div>
    </div>
  );
}
