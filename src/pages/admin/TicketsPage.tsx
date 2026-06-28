import { trpc } from "@/providers/trpc";

import { useState } from "react";

export default function TicketsPage() {
  const [page] = useState(1);
  const [status, setStatus] = useState("");
  const { data, isLoading } = trpc.admin.tickets.list.useQuery({ page, limit: 20, status: status || undefined });

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-semibold text-white tracking-tight">Destek Biletleri</h1>
        <p className="text-slate-500 text-sm mt-1">Müşteri destek talepleri</p>
      </div>
      <div className="flex items-center gap-2">
        {["", "open", "in_progress", "waiting", "resolved", "closed"].map(s => (
          <button key={s} onClick={() => setStatus(s)} className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-colors ${status === s ? "bg-amber-500 text-[#0F0F12]" : "bg-[#232328] text-slate-400 hover:text-white"}`}>
            {s === "" ? "Tümü" : s === "open" ? "Açık" : s === "in_progress" ? "İşlemde" : s === "waiting" ? "Bekliyor" : s === "resolved" ? "Çözüldü" : "Kapalı"}
          </button>
        ))}
      </div>
      <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl overflow-x-auto">
        <table className="w-full">
          <thead>
            <tr className="bg-[#232328] text-slate-400 text-xs uppercase tracking-wider">
              <th className="text-left px-4 py-3">Bilet No</th>
              <th className="text-left px-4 py-3">Konu</th>
              <th className="text-left px-4 py-3">Kategori</th>
              <th className="text-left px-4 py-3">Öncelik</th>
              <th className="text-left px-4 py-3">Durum</th>
              <th className="text-left px-4 py-3">Tarih</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-[#2E2E35]">
            {isLoading ? (
              <tr><td colSpan={6} className="px-4 py-8 text-center text-slate-600">Yükleniyor...</td></tr>
            ) : data?.items.length === 0 ? (
              <tr><td colSpan={6} className="px-4 py-8 text-center text-slate-600">Kayıt bulunamadı</td></tr>
            ) : (
              data?.items.map((t) => (
                <tr key={t.id} className="hover:bg-amber-500/[0.02] transition-colors">
                  <td className="px-4 py-3 text-amber-400 text-sm font-medium">{t.ticketNumber}</td>
                  <td className="px-4 py-3 text-white text-sm">{t.subject}</td>
                  <td className="px-4 py-3 text-slate-400 text-xs">{t.category}</td>
                  <td className="px-4 py-3"><span className={`px-2 py-0.5 rounded-full text-xs font-medium ${t.priority === "urgent" ? "bg-red-500/10 text-red-400" : t.priority === "high" ? "bg-orange-500/10 text-orange-400" : "bg-slate-500/10 text-slate-400"}`}>{t.priority}</span></td>
                  <td className="px-4 py-3"><span className={`px-2 py-0.5 rounded-full text-xs font-medium ${t.status === "resolved" || t.status === "closed" ? "bg-emerald-500/10 text-emerald-400" : t.status === "in_progress" ? "bg-blue-500/10 text-blue-400" : "bg-yellow-500/10 text-yellow-400"}`}>{t.status}</span></td>
                  <td className="px-4 py-3 text-slate-500 text-xs">{new Date(t.createdAt).toLocaleDateString("tr-TR")}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
