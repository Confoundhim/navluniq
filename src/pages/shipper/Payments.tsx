import { ArrowDownLeft, ArrowUpRight, Download } from "lucide-react";

const transactions = [
  { id: "TX-001", type: "escrow_in", amount: 8500, desc: "Ankara → İstanbul - Havuz", date: "28.06.2026", status: "completed" },
  { id: "TX-002", type: "commission", amount: -212.5, desc: "Platform komisyonu (%2.5)", date: "28.06.2026", status: "completed" },
  { id: "TX-003", type: "escrow_release", amount: -8287.5, desc: "Sofor odemesi - Ali Sen", date: "29.06.2026", status: "completed" },
  { id: "TX-004", type: "escrow_in", amount: 12000, desc: "İzmir → Bursa - Havuz", date: "29.06.2026", status: "pending" },
];

export default function Payments() {
  const balance = transactions.reduce((sum, t) => sum + t.amount, 0);

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-white tracking-tight">Odemelerim</h1>
        <p className="text-slate-500 text-sm mt-1">Havuz bakiyesi ve islem gecmisi</p>
      </div>
      <div className="grid sm:grid-cols-3 gap-4">
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5">
          <p className="text-slate-500 text-xs mb-1">Havuz Bakiyesi</p>
          <p className="text-2xl font-bold text-white">₺{balance.toLocaleString("tr-TR", { minimumFractionDigits: 2 })}</p>
        </div>
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5">
          <p className="text-slate-500 text-xs mb-1">Toplam Giren</p>
          <p className="text-2xl font-bold text-emerald-400">₺20,500.00</p>
        </div>
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5">
          <p className="text-slate-500 text-xs mb-1">Toplam Cikan</p>
          <p className="text-2xl font-bold text-red-400">₺8,500.00</p>
        </div>
      </div>
      <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl">
        <div className="px-5 py-4 border-b border-[#2E2E35] flex items-center justify-between">
          <h2 className="text-white font-semibold text-sm">Islem Gecmisi</h2>
          <button className="flex items-center gap-1.5 px-3 py-1.5 bg-[#232328] hover:bg-[#2E2E35] text-slate-400 text-xs rounded-lg transition-colors"><Download className="w-3.5 h-3.5" /> PDF Indir</button>
        </div>
        <div className="divide-y divide-[#2E2E35]">
          {transactions.map(tx => (
            <div key={tx.id} className="px-5 py-4 flex items-center justify-between hover:bg-white/[0.02] transition-colors">
              <div className="flex items-center gap-3">
                <div className={`w-9 h-9 rounded-lg flex items-center justify-center ${tx.amount > 0 ? "bg-emerald-500/10" : "bg-red-500/10"}`}>
                  {tx.amount > 0 ? <ArrowDownLeft className="w-4 h-4 text-emerald-500" /> : <ArrowUpRight className="w-4 h-4 text-red-400" />}
                </div>
                <div>
                  <p className="text-white text-sm">{tx.desc}</p>
                  <p className="text-slate-600 text-xs">{tx.id} | {tx.date}</p>
                </div>
              </div>
              <div className="text-right">
                <p className={`text-sm font-semibold ${tx.amount > 0 ? "text-emerald-400" : "text-red-400"}`}>{tx.amount > 0 ? "+" : ""}₺{Math.abs(tx.amount).toLocaleString("tr-TR")}</p>
                <span className={`text-xs ${tx.status === "completed" ? "text-emerald-500" : "text-amber-500"}`}>{tx.status === "completed" ? "Tamamlandi" : "Bekliyor"}</span>
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
