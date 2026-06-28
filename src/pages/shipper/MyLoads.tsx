import { useState } from "react";
import { Search, Eye, Edit, Trash2 } from "lucide-react";

const demoLoads = [
  { id: 101, title: "Ankara → İstanbul - Paletli Gıda", origin: "Ankara", dest: "İstanbul", weight: "2500 kg", budget: "₺8.500", status: "bidding", date: "28.06.2026", bids: 4 },
  { id: 102, title: "İzmir → Bursa - Konteyner", origin: "İzmir", dest: "Bursa", weight: "8000 kg", budget: "₺12.000", status: "assigned", date: "29.06.2026", bids: 0 },
  { id: 103, title: "Antalya → Ankara - Ev Eşyası", origin: "Antalya", dest: "Ankara", weight: "1500 kg", budget: "₺5.200", status: "in_transit", date: "27.06.2026", bids: 0 },
  { id: 104, title: "Gaziantep → Mersin - Tekstil", origin: "Gaziantep", dest: "Mersin", weight: "4000 kg", budget: "₺9.800", status: "completed", date: "25.06.2026", bids: 0 },
  { id: 105, title: "Samsun → Trabzon - Mobilya", origin: "Samsun", dest: "Trabzon", weight: "3200 kg", budget: "₺6.500", status: "draft", date: "30.06.2026", bids: 0 },
];

export default function MyLoads() {
  const [filter, setFilter] = useState("all");
  const [search, setSearch] = useState("");

  const filtered = demoLoads.filter(l => {
    if (filter !== "all" && l.status !== filter) return false;
    if (search && !l.title.toLowerCase().includes(search.toLowerCase())) return false;
    return true;
  });

  const statusBadge = (s: string) => {
    const map: Record<string, string> = {
      draft: "bg-slate-500/10 text-slate-400", bidding: "bg-amber-500/10 text-amber-400",
      assigned: "bg-blue-500/10 text-blue-400", in_transit: "bg-emerald-500/10 text-emerald-400",
      completed: "bg-purple-500/10 text-purple-400", cancelled: "bg-red-500/10 text-red-400",
    };
    const labels: Record<string, string> = { draft: "Taslak", bidding: "Teklif Alınıyor", assigned: "Atandı", in_transit: "Yolda", completed: "Tamamlandı", cancelled: "İptal" };
    return <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${map[s] || ""}`}>{labels[s] || s}</span>;
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-white tracking-tight">İlanlarım</h1>
          <p className="text-slate-500 text-sm mt-1">Tüm yük ilanlarınız</p>
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <div className="flex items-center gap-2 bg-[#18181C] border border-[#2E2E35] rounded-lg px-3 py-1.5 flex-1 min-w-[200px]">
          <Search className="w-4 h-4 text-slate-600" />
          <input type="text" value={search} onChange={e => setSearch(e.target.value)} placeholder="Ara..." className="bg-transparent text-white text-sm placeholder:text-slate-600 focus:outline-none flex-1" />
        </div>
        {["all", "bidding", "assigned", "in_transit", "completed"].map(s => (
          <button key={s} onClick={() => setFilter(s)} className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-colors ${filter === s ? "bg-emerald-500 text-[#0F0F12]" : "bg-[#232328] text-slate-400 hover:text-white"}`}>
            {s === "all" ? "Tümü" : s === "bidding" ? "Teklif" : s === "assigned" ? "Atandı" : s === "in_transit" ? "Yolda" : "Tamamlandı"}
          </button>
        ))}
      </div>

      <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl overflow-x-auto">
        <table className="w-full">
          <thead>
            <tr className="bg-[#232328] text-slate-400 text-xs uppercase tracking-wider">
              <th className="text-left px-4 py-3">İlan</th>
              <th className="text-left px-4 py-3">Rota</th>
              <th className="text-left px-4 py-3">Bütçe</th>
              <th className="text-left px-4 py-3">Durum</th>
              <th className="text-left px-4 py-3">Tarih</th>
              <th className="text-left px-4 py-3">İşlem</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-[#2E2E35]">
            {filtered.map(l => (
              <tr key={l.id} className="hover:bg-emerald-500/[0.02] transition-colors">
                <td className="px-4 py-3">
                  <p className="text-white text-sm font-medium">{l.title}</p>
                  <p className="text-slate-600 text-xs">#{l.id} | {l.weight}</p>
                </td>
                <td className="px-4 py-3 text-slate-300 text-sm">{l.origin} → {l.dest}</td>
                <td className="px-4 py-3 text-emerald-400 text-sm font-medium">{l.budget}</td>
                <td className="px-4 py-3">{statusBadge(l.status)}</td>
                <td className="px-4 py-3 text-slate-500 text-xs">{l.date}</td>
                <td className="px-4 py-3 flex items-center gap-1">
                  <button className="p-1.5 rounded-lg hover:bg-white/5 text-slate-400 hover:text-emerald-400 transition-colors"><Eye className="w-4 h-4" /></button>
                  {l.status === "draft" && <button className="p-1.5 rounded-lg hover:bg-white/5 text-slate-400 hover:text-amber-400 transition-colors"><Edit className="w-4 h-4" /></button>}
                  <button className="p-1.5 rounded-lg hover:bg-white/5 text-slate-400 hover:text-red-400 transition-colors"><Trash2 className="w-4 h-4" /></button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
