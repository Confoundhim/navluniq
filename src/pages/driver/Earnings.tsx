import { useState } from "react";
import {
  Wallet, ArrowDownLeft, ArrowUpRight, TrendingUp,
  Download, Building2, CircleDollarSign, Clock,
  AlertTriangle, CheckCircle, X,
} from "lucide-react";

const transactions = [
  { id: "TX-101", type: "earning" as const, amount: 8500, desc: "Ankara → İstanbul taşıması", date: "28.06.2026", status: "completed" },
  { id: "TX-102", type: "earning" as const, amount: 12000, desc: "İzmir → Bursa taşıması", date: "27.06.2026", status: "completed" },
  { id: "TX-103", type: "commission" as const, amount: -512.5, desc: "Platform komisyonu (%2.5)", date: "28.06.2026", status: "completed" },
  { id: "TX-104", type: "withdrawal" as const, amount: -5000, desc: "Banka hesabına çekim", date: "26.06.2026", status: "completed" },
  { id: "TX-105", type: "earning" as const, amount: 6800, desc: "Mersin → Adana taşıması", date: "25.06.2026", status: "completed" },
  { id: "TX-106", type: "withdrawal" as const, amount: -3000, desc: "Banka hesabına çekim", date: "22.06.2026", status: "pending" },
  { id: "TX-107", type: "bonus" as const, amount: 250, desc: "Yüksek puan bonusu", date: "20.06.2026", status: "completed" },
];

const bankAccounts = [
  { id: 1, bank: "Garanti BBVA", iban: "TR12 3456 7890 1234 5678 9012 34", accountHolder: "Ali Şen", isDefault: true },
  { id: 2, bank: "Akbank", iban: "TR98 7654 3210 9876 5432 1098 76", accountHolder: "Ali Şen", isDefault: false },
];

export default function Earnings() {
  const [showWithdraw, setShowWithdraw] = useState(false);
  const [withdrawAmount, setWithdrawAmount] = useState("");
  const [selectedAccount, setSelectedAccount] = useState(1);
  const [withdrawSubmitted, setWithdrawSubmitted] = useState(false);
  const [filter, setFilter] = useState("all");

  const balance = transactions
    .filter(t => t.status === "completed")
    .reduce((sum, t) => sum + t.amount, 0);

  const pendingWithdrawal = transactions
    .filter(t => t.type === "withdrawal" && t.status === "pending")
    .reduce((sum, t) => sum + Math.abs(t.amount), 0);

  const filtered = transactions.filter(t => {
    if (filter === "all") return true;
    if (filter === "income") return t.amount > 0;
    if (filter === "outgoing") return t.amount < 0;
    return t.type === filter;
  });

  const monthlyData = [
    { month: "Ocak", amount: 18500 },
    { month: "Şub", amount: 22100 },
    { month: "Mar", amount: 19800 },
    { month: "Nis", amount: 24500 },
    { month: "May", amount: 28300 },
    { month: "Haz", amount: 24750 },
  ];

  const maxMonthly = Math.max(...monthlyData.map(d => d.amount));

  const handleWithdraw = (e: React.FormEvent) => {
    e.preventDefault();
    setWithdrawSubmitted(true);
    setTimeout(() => {
      setWithdrawSubmitted(false);
      setShowWithdraw(false);
      setWithdrawAmount("");
    }, 2500);
  };

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-white tracking-tight">Kazançlarım</h1>
        <p className="text-slate-500 text-sm mt-1">Cüzdanınız ve finansal özet</p>
      </div>

      {/* Balance Cards */}
      <div className="grid sm:grid-cols-3 gap-4">
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5">
          <div className="flex items-center justify-between mb-2">
            <p className="text-slate-500 text-xs">Kullanılabilir Bakiye</p>
            <Wallet className="w-4 h-4 text-blue-500" />
          </div>
          <p className="text-2xl font-bold text-white">₺{balance.toLocaleString("tr-TR", { minimumFractionDigits: 2 })}</p>
          <button
            onClick={() => setShowWithdraw(true)}
            className="mt-3 w-full py-2 bg-blue-500 hover:bg-blue-400 text-white text-sm font-medium rounded-lg transition-all flex items-center justify-center gap-1.5"
          >
            <ArrowUpRight className="w-3.5 h-3.5" /> Çekim Talebi Oluştur
          </button>
        </div>
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5">
          <div className="flex items-center justify-between mb-2">
            <p className="text-slate-500 text-xs">Bekleyen Çekim</p>
            <Clock className="w-4 h-4 text-amber-500" />
          </div>
          <p className="text-2xl font-bold text-amber-400">₺{pendingWithdrawal.toLocaleString()}</p>
          <p className="text-slate-600 text-xs mt-2">24 saat içinde hesabınıza aktarılacak</p>
        </div>
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5">
          <div className="flex items-center justify-between mb-2">
            <p className="text-slate-500 text-xs">Bu Ay Toplam</p>
            <TrendingUp className="w-4 h-4 text-emerald-500" />
          </div>
          <p className="text-2xl font-bold text-emerald-400">₺24.750</p>
          <p className="text-emerald-500 text-xs mt-2">+12% geçen aya göre</p>
        </div>
      </div>

      <div className="grid lg:grid-cols-3 gap-6">
        {/* Monthly Chart */}
        <div className="lg:col-span-2 bg-[#18181C] border border-[#2E2E35] rounded-xl p-5">
          <h2 className="text-white font-semibold text-sm mb-4">Aylık Kazanç Geçmişi</h2>
          <div className="flex items-end gap-3 h-48">
            {monthlyData.map((d) => (
              <div key={d.month} className="flex-1 flex flex-col items-center gap-2">
                <span className="text-emerald-400 text-[10px]">₺{(d.amount / 1000).toFixed(1)}k</span>
                <div className="w-full bg-[#232328] rounded-t-md overflow-hidden">
                  <div
                    className="w-full bg-blue-500 rounded-t-md transition-all hover:bg-blue-400"
                    style={{ height: `${(d.amount / maxMonthly) * 120}px` }}
                  />
                </div>
                <span className="text-slate-500 text-[10px]">{d.month}</span>
              </div>
            ))}
          </div>
        </div>

        {/* Summary */}
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5 space-y-4">
          <h2 className="text-white font-semibold text-sm">Finansal Özet</h2>
          <div className="space-y-3">
            <div className="flex items-center justify-between py-2 border-b border-[#2E2E35]">
              <span className="text-slate-500 text-xs">Toplam Kazanç</span>
              <span className="text-emerald-400 text-sm font-medium">₺137.850</span>
            </div>
            <div className="flex items-center justify-between py-2 border-b border-[#2E2E35]">
              <span className="text-slate-500 text-xs">Toplam Komisyon</span>
              <span className="text-red-400 text-sm font-medium">-₺3.446</span>
            </div>
            <div className="flex items-center justify-between py-2 border-b border-[#2E2E35]">
              <span className="text-slate-500 text-xs">Toplam Çekim</span>
              <span className="text-slate-300 text-sm font-medium">₺85.000</span>
            </div>
            <div className="flex items-center justify-between py-2 border-b border-[#2E2E35]">
              <span className="text-slate-500 text-xs">Bonuslar</span>
              <span className="text-emerald-400 text-sm font-medium">+₺1.250</span>
            </div>
            <div className="flex items-center justify-between py-2">
              <span className="text-slate-400 text-xs font-medium">Net Kazanç</span>
              <span className="text-white text-sm font-bold">₺50.654</span>
            </div>
          </div>
        </div>
      </div>

      {/* Transactions */}
      <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl">
        <div className="px-5 py-4 border-b border-[#2E2E35] flex flex-wrap items-center justify-between gap-2">
          <h2 className="text-white font-semibold text-sm">İşlem Geçmişi</h2>
          <div className="flex items-center gap-2">
            {["all", "income", "outgoing"].map(f => (
              <button key={f} onClick={() => setFilter(f)} className={`px-2.5 py-1 rounded-md text-xs transition-colors ${filter === f ? "bg-blue-500 text-white" : "bg-[#232328] text-slate-400 hover:text-white"}`}>
                {f === "all" ? "Tümü" : f === "income" ? "Gelen" : "Giden"}
              </button>
            ))}
            <button className="flex items-center gap-1.5 px-3 py-1.5 bg-[#232328] hover:bg-[#2E2E35] text-slate-400 text-xs rounded-lg transition-colors">
              <Download className="w-3.5 h-3.5" /> PDF
            </button>
          </div>
        </div>
        <div className="divide-y divide-[#2E2E35]">
          {filtered.map(tx => (
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
                <span className={`text-xs ${tx.status === "completed" ? "text-emerald-500" : "text-amber-500"}`}>{tx.status === "completed" ? "Tamamlandı" : "Bekliyor"}</span>
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* Withdraw Modal */}
      {showWithdraw && (
        <div className="fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4" onClick={() => setShowWithdraw(false)}>
          <div className="bg-[#18181C] border border-[#2E2E35] rounded-2xl max-w-md w-full p-6 space-y-4" onClick={e => e.stopPropagation()}>
            {withdrawSubmitted ? (
              <div className="text-center py-6 space-y-3">
                <div className="w-16 h-16 rounded-full bg-emerald-500/10 flex items-center justify-center mx-auto">
                  <CheckCircle className="w-8 h-8 text-emerald-500" />
                </div>
                <h3 className="text-white font-semibold text-lg">Çekim Talebi Oluşturuldu!</h3>
                <p className="text-slate-500 text-sm">Talebiniz 24 saat içinde işleme alınacak.</p>
              </div>
            ) : (
              <>
                <div className="flex items-center justify-between">
                  <h3 className="text-white font-semibold">Çekim Talebi</h3>
                  <button onClick={() => setShowWithdraw(false)} className="p-1 rounded hover:bg-white/5 text-slate-500"><X className="w-5 h-5" /></button>
                </div>
                <div className="bg-blue-500/5 border border-blue-500/20 rounded-xl p-4">
                  <p className="text-slate-400 text-xs">Kullanılabilir Bakiye</p>
                  <p className="text-white text-xl font-bold">₺{balance.toLocaleString("tr-TR", { minimumFractionDigits: 2 })}</p>
                </div>
                <form onSubmit={handleWithdraw} className="space-y-3">
                  <div>
                    <label className="block text-xs text-slate-400 mb-1.5">Çekim Tutarı (₺)</label>
                    <div className="relative">
                      <CircleDollarSign className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-600" />
                      <input
                        type="number"
                        value={withdrawAmount}
                        onChange={e => setWithdrawAmount(e.target.value)}
                        max={balance}
                        className="w-full h-10 pl-10 pr-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-blue-500"
                        placeholder="5000"
                        required
                      />
                    </div>
                    <button type="button" onClick={() => setWithdrawAmount(Math.floor(balance).toString())} className="text-blue-500 text-xs mt-1 hover:underline">Tümünü Çek</button>
                  </div>
                  <div>
                    <label className="block text-xs text-slate-400 mb-1.5">Hesap Seçin</label>
                    <div className="space-y-2">
                      {bankAccounts.map(acc => (
                        <button
                          key={acc.id}
                          type="button"
                          onClick={() => setSelectedAccount(acc.id)}
                          className={`w-full p-3 rounded-xl border text-left transition-all ${selectedAccount === acc.id ? "border-blue-500 bg-blue-500/10" : "border-[#2E2E35] hover:border-[#3E3E45]"}`}
                        >
                          <div className="flex items-center gap-2">
                            <Building2 className="w-4 h-4 text-slate-500" />
                            <span className="text-white text-sm">{acc.bank}</span>
                            {acc.isDefault && <span className="text-xs px-1.5 py-0.5 bg-blue-500/10 text-blue-400 rounded">Varsayılan</span>}
                          </div>
                          <p className="text-slate-600 text-xs mt-1">{acc.iban}</p>
                        </button>
                      ))}
                    </div>
                  </div>
                  <div className="flex items-center gap-2 p-3 bg-amber-500/5 border border-amber-500/20 rounded-lg">
                    <AlertTriangle className="w-4 h-4 text-amber-500 shrink-0" />
                    <p className="text-amber-400 text-xs">Minimum çekim tutarı ₺100'dür. Çekimler 24 saat içinde işleme alınır.</p>
                  </div>
                  <button type="submit" className="w-full py-2.5 bg-blue-500 hover:bg-blue-400 text-white font-semibold rounded-lg transition-all flex items-center justify-center gap-2">
                    <ArrowUpRight className="w-4 h-4" /> Çekim Talebi Oluştur
                  </button>
                </form>
              </>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
