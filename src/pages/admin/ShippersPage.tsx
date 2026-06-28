import { trpc } from "@/providers/trpc";
import { Search, Ban, CheckCircle } from "lucide-react";
import { useState } from "react";

export default function ShippersPage() {
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState("");
  const { data, isLoading } = trpc.admin.users.listShippers.useQuery({ page, limit: 20, search: search || undefined });
  const utils = trpc.useUtils();
  const updateStatus = trpc.admin.users.updateShipperStatus.useMutation({
    onSuccess: () => utils.admin.users.listShippers.invalidate()
  });

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-white tracking-tight">Yük Sahipleri</h1>
          <p className="text-slate-500 text-sm mt-1">Platformdaki tüm yük sahibi kullanıcılar</p>
        </div>
      </div>
      <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl">
        <div className="p-4 border-b border-[#2E2E35] flex items-center gap-3">
          <Search className="w-4 h-4 text-slate-600" />
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Ara..."
            className="bg-transparent text-white text-sm placeholder:text-slate-600 focus:outline-none flex-1"
          />
        </div>
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead>
              <tr className="bg-[#232328] text-slate-400 text-xs uppercase tracking-wider">
                <th className="text-left px-4 py-3 font-semibold">ID</th>
                <th className="text-left px-4 py-3 font-semibold">Ad / Şirket</th>
                <th className="text-left px-4 py-3 font-semibold">Tip</th>
                <th className="text-left px-4 py-3 font-semibold">Telefon</th>
                <th className="text-left px-4 py-3 font-semibold">KYC</th>
                <th className="text-left px-4 py-3 font-semibold">Durum</th>
                <th className="text-left px-4 py-3 font-semibold">İşlem</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[#2E2E35]">
              {isLoading ? (
                <tr><td colSpan={7} className="px-4 py-8 text-center text-slate-600">Yükleniyor...</td></tr>
              ) : data?.items.length === 0 ? (
                <tr><td colSpan={7} className="px-4 py-8 text-center text-slate-600">Kayıt bulunamadı</td></tr>
              ) : (
                data?.items.map((s) => (
                  <tr key={s.id} className="hover:bg-amber-500/[0.02] transition-colors">
                    <td className="px-4 py-3 text-slate-400 text-sm">#{s.id}</td>
                    <td className="px-4 py-3">
                      <div className="text-white text-sm font-medium">{s.companyName || `${s.firstName} ${s.lastName}`}</div>
                      <div className="text-slate-500 text-xs">{s.email}</div>
                    </td>
                    <td className="px-4 py-3">
                      <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${s.type === "corporate" ? "bg-blue-500/10 text-blue-400" : "bg-slate-500/10 text-slate-400"}`}>
                        {s.type === "corporate" ? "Kurumsal" : "Bireysel"}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-slate-300 text-sm">{s.phone}</td>
                    <td className="px-4 py-3">
                      <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${
                        s.kycStatus === "approved" ? "bg-emerald-500/10 text-emerald-400" :
                        s.kycStatus === "rejected" ? "bg-red-500/10 text-red-400" :
                        "bg-yellow-500/10 text-yellow-400"
                      }`}>
                        {s.kycStatus === "approved" ? "Onaylı" : s.kycStatus === "rejected" ? "Reddedildi" : "Bekliyor"}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${
                        s.status === "active" ? "bg-emerald-500/10 text-emerald-400" :
                        s.status === "banned" ? "bg-red-500/10 text-red-400" :
                        "bg-slate-500/10 text-slate-400"
                      }`}>
                        {s.status === "active" ? "Aktif" : s.status === "banned" ? "Banlı" : "Pasif"}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <button
                        onClick={() => updateStatus.mutate({ id: s.id, status: s.status === "active" ? "banned" : "active" })}
                        className="p-1.5 rounded-lg hover:bg-white/5 text-slate-400 hover:text-red-400 transition-colors"
                        title={s.status === "active" ? "Banla" : "Aktifleştir"}
                      >
                        {s.status === "active" ? <Ban className="w-4 h-4" /> : <CheckCircle className="w-4 h-4" />}
                      </button>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
        {data && data.total > data.limit && (
          <div className="p-4 border-t border-[#2E2E35] flex items-center justify-between">
            <span className="text-slate-500 text-xs">Toplam {data.total} kayıt</span>
            <div className="flex items-center gap-2">
              <button onClick={() => setPage(p => Math.max(1, p - 1))} disabled={page === 1} className="px-3 py-1 rounded-lg bg-[#232328] text-slate-400 text-xs hover:bg-[#2E2E35] disabled:opacity-30">Önceki</button>
              <span className="text-slate-400 text-xs">Sayfa {page}</span>
              <button onClick={() => setPage(p => p + 1)} disabled={data.items.length < data.limit} className="px-3 py-1 rounded-lg bg-[#232328] text-slate-400 text-xs hover:bg-[#2E2E35] disabled:opacity-30">Sonraki</button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
