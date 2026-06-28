import { trpc } from "@/providers/trpc";
import { Search } from "lucide-react";
import { useState } from "react";

export default function OperationsPage() {
  const [page] = useState(1);
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const { data, isLoading } = trpc.admin.loads.list.useQuery({ page, limit: 20, search: search || undefined, status: status || undefined });

  const statuses = [
    { label: "Tümü", value: "" },
    { label: "Taslak", value: "draft" },
    { label: "Yayında", value: "published" },
    { label: "Teklif", value: "bidding" },
    { label: "Atanmış", value: "assigned" },
    { label: "Yolda", value: "in_transit" },
    { label: "Teslim", value: "delivered" },
    { label: "Tamamlandı", value: "completed" },
  ];

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-white tracking-tight">Operasyon & İlan Havuzu</h1>
          <p className="text-slate-500 text-sm mt-1">Tüm yük ilanları ve operasyonlar</p>
        </div>
      </div>
      <div className="flex flex-wrap items-center gap-2">
        <div className="flex items-center gap-2 bg-[#18181C] border border-[#2E2E35] rounded-lg px-3 py-1.5 flex-1 min-w-[200px]">
          <Search className="w-4 h-4 text-slate-600" />
          <input type="text" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Ara..." className="bg-transparent text-white text-sm placeholder:text-slate-600 focus:outline-none flex-1" />
        </div>
        {statuses.map(s => (
          <button key={s.value} onClick={() => setStatus(s.value)} className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-colors ${status === s.value ? "bg-amber-500 text-[#0F0F12]" : "bg-[#232328] text-slate-400 hover:text-white"}`}>{s.label}</button>
        ))}
      </div>
      <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl overflow-x-auto">
        <table className="w-full">
          <thead>
            <tr className="bg-[#232328] text-slate-400 text-xs uppercase tracking-wider">
              <th className="text-left px-4 py-3">ID</th>
              <th className="text-left px-4 py-3">İlan Başlığı</th>
              <th className="text-left px-4 py-3">Rota</th>
              <th className="text-left px-4 py-3">Yük Tipi</th>
              <th className="text-left px-4 py-3">Bütçe</th>
              <th className="text-left px-4 py-3">Durum</th>
              <th className="text-left px-4 py-3">Tarih</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-[#2E2E35]">
            {isLoading ? (
              <tr><td colSpan={7} className="px-4 py-8 text-center text-slate-600">Yükleniyor...</td></tr>
            ) : data?.items.length === 0 ? (
              <tr><td colSpan={7} className="px-4 py-8 text-center text-slate-600">Kayıt bulunamadı</td></tr>
            ) : (
              data?.items.map((load) => (
                <tr key={load.id} className="hover:bg-amber-500/[0.02] transition-colors">
                  <td className="px-4 py-3 text-slate-400 text-sm">#{load.id}</td>
                  <td className="px-4 py-3 text-white text-sm font-medium">{load.title}</td>
                  <td className="px-4 py-3 text-slate-300 text-sm">{load.originCity} → {load.destinationCity}</td>
                  <td className="px-4 py-3 text-slate-400 text-sm">{load.loadType}</td>
                  <td className="px-4 py-3 text-amber-400 text-sm">{load.budget ? `₺${Number(load.budget).toLocaleString("tr-TR")}` : "—"}</td>
                  <td className="px-4 py-3"><span className={`px-2 py-0.5 rounded-full text-xs font-medium ${load.status === "completed" ? "bg-emerald-500/10 text-emerald-400" : load.status === "in_transit" ? "bg-blue-500/10 text-blue-400" : load.status === "cancelled" ? "bg-red-500/10 text-red-400" : "bg-yellow-500/10 text-yellow-400"}`}>{load.status}</span></td>
                  <td className="px-4 py-3 text-slate-500 text-xs">{new Date(load.createdAt).toLocaleDateString("tr-TR")}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
