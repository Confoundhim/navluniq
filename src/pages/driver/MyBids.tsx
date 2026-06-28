import { useState } from "react";
import {
  Handshake, Clock, CheckCircle, XCircle, ArrowRight,
  MapPin, RotateCcw, MessageSquare, AlertTriangle,
} from "lucide-react";

const demoBids = [
  { id: 1, load: "Ankara → İstanbul - Paletli Gıda", origin: "Ankara", dest: "İstanbul", distance: "450 km", weight: "2500 kg", myBid: 8000, budget: 9500, status: "pending", time: "2 saat önce", shipper: "ABC Gıda" },
  { id: 2, load: "Mersin → Gaziantep - Tekstil", origin: "Mersin", dest: "Gaziantep", distance: "280 km", weight: "3200 kg", myBid: 6500, budget: 6800, status: "pending", time: "5 saat önce", shipper: "Tekstil A.Ş." },
  { id: 3, load: "İstanbul → Samsun - Mobilya", origin: "İstanbul", dest: "Samsun", distance: "730 km", weight: "4500 kg", myBid: 14200, budget: 15000, status: "accepted", time: "1 gün önce", shipper: "Mobilya Plus" },
  { id: 4, load: "Bursa → İzmir - Kimyasal", origin: "Bursa", dest: "İzmir", distance: "340 km", weight: "5000 kg", myBid: 10500, budget: 11200, status: "rejected", time: "2 gün önce", shipper: "ChemLogistics" },
  { id: 5, load: "Antalya → Ankara - Ev Eşyası", origin: "Antalya", dest: "Ankara", distance: "480 km", weight: "1500 kg", myBid: 5200, budget: 5500, status: "withdrawn", time: "3 gün önce", shipper: "Bireysel" },
];

export default function MyBids() {
  const [filter, setFilter] = useState("pending");
  const [bids, setBids] = useState(demoBids);
  const [withdrawConfirm, setWithdrawConfirm] = useState<number | null>(null);

  const filtered = bids.filter(b => filter === "all" ? true : b.status === filter);

  const statusConfig: Record<string, { label: string; color: string; icon: typeof Clock }> = {
    pending: { label: "Bekliyor", color: "bg-amber-500/10 text-amber-400", icon: Clock },
    accepted: { label: "Onaylandı", color: "bg-emerald-500/10 text-emerald-400", icon: CheckCircle },
    rejected: { label: "Reddedildi", color: "bg-red-500/10 text-red-400", icon: XCircle },
    withdrawn: { label: "Çekildi", color: "bg-slate-500/10 text-slate-400", icon: RotateCcw },
  };

  const handleWithdraw = (id: number) => {
    setBids(bids.map(b => b.id === id ? { ...b, status: "withdrawn" as const } : b));
    setWithdrawConfirm(null);
  };

  const savings = (bid: typeof demoBids[0]) => {
    const diff = bid.budget - bid.myBid;
    return diff > 0 ? `Yük sahibi ₺${diff.toLocaleString()} tasarruf ediyor` : `₺${Math.abs(diff).toLocaleString()} üzerinde teklif`;
  };

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-semibold text-white tracking-tight">Tekliflerim</h1>
        <p className="text-slate-500 text-sm mt-1">Verdiğiniz tüm tekliflerin durumu</p>
      </div>

      {/* Summary Cards */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
        {["pending", "accepted", "rejected", "all"].map(s => (
          <button
            key={s}
            onClick={() => setFilter(s)}
            className={`p-3 rounded-xl border transition-all text-left ${filter === s ? "border-blue-500 bg-blue-500/10" : "border-[#2E2E35] bg-[#18181C] hover:border-[#3E3E45]"}`}
          >
            <p className={`text-2xl font-bold ${filter === s ? "text-blue-500" : "text-white"}`}>
              {bids.filter(b => s === "all" ? true : b.status === s).length}
            </p>
            <p className="text-slate-500 text-xs">
              {s === "pending" ? "Bekleyen" : s === "accepted" ? "Onaylanan" : s === "rejected" ? "Reddedilen" : "Tümü"}
            </p>
          </button>
        ))}
      </div>

      {/* Filter tabs */}
      <div className="flex items-center gap-2">
        {["pending", "accepted", "rejected", "withdrawn", "all"].map(s => (
          <button
            key={s}
            onClick={() => setFilter(s)}
            className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-colors ${filter === s ? "bg-blue-500 text-white" : "bg-[#232328] text-slate-400 hover:text-white"}`}
          >
            {s === "pending" ? "Bekleyen" : s === "accepted" ? "Onaylanan" : s === "rejected" ? "Reddedilen" : s === "withdrawn" ? "Çekilen" : "Tümü"}
          </button>
        ))}
      </div>

      {/* Bids List */}
      <div className="space-y-3">
        {filtered.map(bid => {
          const config = statusConfig[bid.status];
          const StatusIcon = config.icon;
          return (
            <div key={bid.id} className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5 hover:border-[#3E3E45] transition-all">
              <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div className="flex-1">
                  <div className="flex items-center gap-2 mb-2">
                    <p className="text-white text-sm font-medium">{bid.load}</p>
                    <span className={`text-xs px-2 py-0.5 rounded-full flex items-center gap-1 ${config.color}`}>
                      <StatusIcon className="w-3 h-3" />{config.label}
                    </span>
                  </div>
                  <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500">
                    <span className="flex items-center gap-1"><MapPin className="w-3 h-3" />{bid.distance}</span>
                    <span>{bid.weight}</span>
                    <span>{bid.time}</span>
                    <span className="flex items-center gap-1"><Handshake className="w-3 h-3" />{bid.shipper}</span>
                  </div>
                  <p className={`text-xs mt-1.5 ${bid.myBid <= bid.budget ? "text-emerald-500" : "text-amber-500"}`}>
                    {savings(bid)}
                  </p>
                </div>

                <div className="flex items-center gap-4">
                  <div className="text-right">
                    <p className="text-white text-sm font-semibold">₺{bid.myBid.toLocaleString()}</p>
                    <p className="text-slate-600 text-xs line-through">Bütçe: ₺{bid.budget.toLocaleString()}</p>
                  </div>
                  <div className="flex items-center gap-1.5">
                    {bid.status === "pending" && (
                      <>
                        <button
                          onClick={() => setWithdrawConfirm(bid.id)}
                          className="px-3 py-1.5 bg-red-500/10 hover:bg-red-500/20 text-red-400 text-xs rounded-lg transition-colors"
                        >
                          Çek
                        </button>
                        <button className="p-2 rounded-lg bg-[#232328] hover:bg-[#2E2E35] text-slate-400 hover:text-blue-400 transition-colors">
                          <MessageSquare className="w-4 h-4" />
                        </button>
                      </>
                    )}
                    {bid.status === "accepted" && (
                      <button className="px-3 py-1.5 bg-blue-500 hover:bg-blue-400 text-white text-xs rounded-lg transition-colors flex items-center gap-1">
                        Detay <ArrowRight className="w-3 h-3" />
                      </button>
                    )}
                    {bid.status === "rejected" && (
                      <span className="text-xs text-slate-600 flex items-center gap-1"><AlertTriangle className="w-3 h-3" />Başka teklif</span>
                    )}
                  </div>
                </div>
              </div>

              {/* Withdraw Confirmation */}
              {withdrawConfirm === bid.id && (
                <div className="mt-3 pt-3 border-t border-[#2E2E35] flex items-center gap-2">
                  <AlertTriangle className="w-4 h-4 text-amber-500 shrink-0" />
                  <p className="text-amber-400 text-xs flex-1">Teklifi geri çekmek istediğinize emin misiniz? Bu işlem geri alınamaz.</p>
                  <button onClick={() => handleWithdraw(bid.id)} className="px-3 py-1 bg-red-500 hover:bg-red-400 text-white text-xs rounded-lg transition-colors">Evet, Çek</button>
                  <button onClick={() => setWithdrawConfirm(null)} className="px-3 py-1 bg-[#232328] text-slate-400 text-xs rounded-lg hover:text-white transition-colors">İptal</button>
                </div>
              )}
            </div>
          );
        })}
      </div>

      {filtered.length === 0 && (
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-12 text-center">
          <Handshake className="w-12 h-12 text-[#2E2E35] mx-auto mb-3" />
          <p className="text-slate-500 text-sm">Bu kategoride teklif bulunmuyor.</p>
        </div>
      )}
    </div>
  );
}
