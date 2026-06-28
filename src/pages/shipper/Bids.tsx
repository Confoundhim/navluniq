import { useState } from "react";
import { Star, CheckCircle, XCircle, Truck, MessageSquare } from "lucide-react";

const demoBids = [
  { id: 1, driver: "Ahmet Yılmaz", rating: 4.8, trips: 142, vehicle: "10 Teker Kamyon", load: "Ankara → İstanbul - Paletli Gıda", amount: "₺8.000", original: "₺8.500", time: "2 saat önce", status: "pending" },
  { id: 2, driver: "Mehmet Kaya", rating: 4.5, trips: 89, vehicle: "Kırkayak", load: "Ankara → İstanbul - Paletli Gıda", amount: "₺7.500", original: "₺8.500", time: "3 saat önce", status: "pending" },
  { id: 3, driver: "Ali Şen", rating: 4.9, trips: 231, vehicle: "Tır", load: "Ankara → İstanbul - Paletli Gıda", amount: "₺8.200", original: "₺8.500", time: "5 saat önce", status: "pending" },
  { id: 4, driver: "Mustafa Demir", rating: 4.2, trips: 56, vehicle: "8 Teker Kamyon", load: "Ankara → İstanbul - Paletli Gıda", amount: "₺8.500", original: "₺8.500", time: "1 gün önce", status: "accepted" },
];

export default function Bids() {
  const [filter, setFilter] = useState("pending");

  const filtered = demoBids.filter(b => filter === "all" ? true : b.status === filter);

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-semibold text-white tracking-tight">Gelen Teklifler</h1>
        <p className="text-slate-500 text-sm mt-1">İlanlarınıza gelen şoför teklifleri</p>
      </div>
      <div className="flex items-center gap-2">
        {["pending", "accepted", "rejected", "all"].map(s => (
          <button key={s} onClick={() => setFilter(s)} className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-colors ${filter === s ? "bg-emerald-500 text-[#0F0F12]" : "bg-[#232328] text-slate-400 hover:text-white"}`}>
            {s === "pending" ? "Bekleyen" : s === "accepted" ? "Onaylanan" : s === "rejected" ? "Reddedilen" : "Tümü"}
          </button>
        ))}
      </div>
      <div className="space-y-3">
        {filtered.map(bid => (
          <div key={bid.id} className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5 hover:border-[#3E3E45] transition-all">
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
              <div className="flex-1">
                <div className="flex items-center gap-2 mb-1">
                  <p className="text-white font-semibold">{bid.driver}</p>
                  <div className="flex items-center gap-1"><Star className="w-3.5 h-3.5 text-amber-500 fill-amber-500" /><span className="text-amber-500 text-xs">{bid.rating}</span></div>
                  <span className="text-slate-600 text-xs">({bid.trips} sefer)</span>
                </div>
                <p className="text-slate-500 text-xs mb-2">{bid.load}</p>
                <div className="flex items-center gap-3 text-xs text-slate-500">
                  <span className="flex items-center gap-1"><Truck className="w-3 h-3" />{bid.vehicle}</span>
                  <span>{bid.time}</span>
                </div>
              </div>
              <div className="flex items-center gap-4">
                <div className="text-right">
                  <p className="text-emerald-400 text-xl font-bold">{bid.amount}</p>
                  <p className="text-slate-600 text-xs line-through">{bid.original}</p>
                </div>
                {bid.status === "pending" && (
                  <div className="flex items-center gap-2">
                    <button className="p-2 rounded-lg bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-400 transition-colors" title="Onayla"><CheckCircle className="w-5 h-5" /></button>
                    <button className="p-2 rounded-lg bg-red-500/10 hover:bg-red-500/20 text-red-400 transition-colors" title="Reddet"><XCircle className="w-5 h-5" /></button>
                    <button className="p-2 rounded-lg bg-[#232328] hover:bg-[#2E2E35] text-slate-400 transition-colors" title="Mesaj"><MessageSquare className="w-5 h-5" /></button>
                  </div>
                )}
                {bid.status === "accepted" && <span className="px-3 py-1 rounded-full bg-emerald-500/10 text-emerald-400 text-xs font-medium">Onaylandı</span>}
              </div>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
