import { trpc } from "@/providers/trpc";
import { CheckCircle } from "lucide-react";
import { useState } from "react";

export default function DisputesPage() {
  const [page] = useState(1);
  const [status, setStatus] = useState("");
  const { data, isLoading } = trpc.admin.disputes.list.useQuery({ page, limit: 20, status: status || undefined });
  const utils = trpc.useUtils();
  const resolve = trpc.admin.disputes.resolve.useMutation({ onSuccess: () => utils.admin.disputes.list.invalidate() });

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-semibold text-white tracking-tight">Uyuşmazlıklar</h1>
        <p className="text-slate-500 text-sm mt-1">Teslimat uyuşmazlıkları ve çözümleri</p>
      </div>
      <div className="flex items-center gap-2">
        {["", "open", "under_review", "resolved"].map(s => (
          <button key={s} onClick={() => setStatus(s)} className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-colors ${status === s ? "bg-amber-500 text-[#0F0F12]" : "bg-[#232328] text-slate-400 hover:text-white"}`}>
            {s === "" ? "Tümü" : s === "open" ? "Açık" : s === "under_review" ? "İncelemede" : "Çözüldü"}
          </button>
        ))}
      </div>
      <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl overflow-x-auto">
        <table className="w-full">
          <thead>
            <tr className="bg-[#232328] text-slate-400 text-xs uppercase tracking-wider">
              <th className="text-left px-4 py-3">ID</th>
              <th className="text-left px-4 py-3">Tip</th>
              <th className="text-left px-4 py-3">Açıklama</th>
              <th className="text-left px-4 py-3">Durum</th>
              <th className="text-left px-4 py-3">Karar</th>
              <th className="text-left px-4 py-3">Tarih</th>
              <th className="text-left px-4 py-3">İşlem</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-[#2E2E35]">
            {isLoading ? (
              <tr><td colSpan={7} className="px-4 py-8 text-center text-slate-600">Yükleniyor...</td></tr>
            ) : data?.items.length === 0 ? (
              <tr><td colSpan={7} className="px-4 py-8 text-center text-slate-600">Kayıt bulunamadı</td></tr>
            ) : (
              data?.items.map((d) => (
                <tr key={d.id} className="hover:bg-amber-500/[0.02] transition-colors">
                  <td className="px-4 py-3 text-slate-400 text-sm">#{d.id}</td>
                  <td className="px-4 py-3"><span className="px-2 py-0.5 rounded-full text-xs font-medium bg-red-500/10 text-red-400">{d.disputeType}</span></td>
                  <td className="px-4 py-3 text-white text-sm max-w-[200px] truncate">{d.description}</td>
                  <td className="px-4 py-3"><span className={`px-2 py-0.5 rounded-full text-xs font-medium ${d.status === "resolved" ? "bg-emerald-500/10 text-emerald-400" : d.status === "under_review" ? "bg-blue-500/10 text-blue-400" : "bg-red-500/10 text-red-400"}`}>{d.status}</span></td>
                  <td className="px-4 py-3 text-slate-400 text-xs">{d.adminDecision || "—"}</td>
                  <td className="px-4 py-3 text-slate-500 text-xs">{new Date(d.createdAt).toLocaleDateString("tr-TR")}</td>
                  <td className="px-4 py-3">
                    {d.status === "open" && (
                      <button onClick={() => resolve.mutate({ id: d.id, decision: "release_driver" })} className="p-1.5 rounded-lg hover:bg-emerald-500/10 text-slate-400 hover:text-emerald-400 transition-colors" title="Çöz">
                        <CheckCircle className="w-4 h-4" />
                      </button>
                    )}
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
