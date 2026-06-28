import { trpc } from "@/providers/trpc";
import { Wallet } from "lucide-react";
import { useState } from "react";

export default function FinancePage() {
  const [page] = useState(1);
  const [status] = useState("");
  const { data: escrowData } = trpc.admin.finance.escrowList.useQuery({ page, limit: 20, status: status || undefined });
  

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-white tracking-tight">Finans & Muhasebe</h1>
        <p className="text-slate-500 text-sm mt-1">Havuz ödemeleri ve hak edişler</p>
      </div>
      <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5">
        <h2 className="text-white font-semibold text-sm mb-4 flex items-center gap-2"><Wallet className="w-4 h-4 text-amber-500" /> Havuz İşlemleri</h2>
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead>
              <tr className="bg-[#232328] text-slate-400 text-xs uppercase tracking-wider">
                <th className="text-left px-4 py-3">ID</th>
                <th className="text-left px-4 py-3">Tutar</th>
                <th className="text-left px-4 py-3">Komisyon</th>
                <th className="text-left px-4 py-3">Durum</th>
                <th className="text-left px-4 py-3">Tarih</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[#2E2E35]">
              {escrowData?.items.length === 0 ? (
                <tr><td colSpan={5} className="px-4 py-8 text-center text-slate-600">Kayıt bulunamadı</td></tr>
              ) : (
                escrowData?.items.map((tx) => (
                  <tr key={tx.id} className="hover:bg-amber-500/[0.02] transition-colors">
                    <td className="px-4 py-3 text-slate-400 text-sm">#{tx.id}</td>
                    <td className="px-4 py-3 text-white text-sm font-medium">₺{Number(tx.amount).toLocaleString("tr-TR", { minimumFractionDigits: 2 })}</td>
                    <td className="px-4 py-3 text-slate-400 text-sm">₺{Number(tx.commissionAmount || 0).toLocaleString("tr-TR", { minimumFractionDigits: 2 })}</td>
                    <td className="px-4 py-3"><span className={`px-2 py-0.5 rounded-full text-xs font-medium ${tx.status === "released" ? "bg-emerald-500/10 text-emerald-400" : tx.status === "in_escrow" ? "bg-blue-500/10 text-blue-400" : "bg-yellow-500/10 text-yellow-400"}`}>{tx.status}</span></td>
                    <td className="px-4 py-3 text-slate-500 text-xs">{new Date(tx.createdAt).toLocaleDateString("tr-TR")}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
