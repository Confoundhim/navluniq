import { Link } from "react-router";
import {
  PlusCircle, ClipboardList, Handshake, MapPin, Wallet,
  ArrowRight, Truck, Star,
} from "lucide-react";

const stats = [
  { label: "Aktif İlanlarım", value: "5", icon: ClipboardList, color: "text-emerald-500", bg: "bg-emerald-500/10" },
  { label: "Gelen Teklif", value: "12", icon: Handshake, color: "text-amber-500", bg: "bg-amber-500/10" },
  { label: "Devam Eden", value: "3", icon: Truck, color: "text-blue-500", bg: "bg-blue-500/10" },
  { label: "Havuz Bakiyesi", value: "₺45.800", icon: Wallet, color: "text-purple-500", bg: "bg-purple-500/10" },
];

const activeLoads = [
  { id: 101, title: "Ankara → İstanbul - Paletli Gıda", status: "bidding", bids: 4, date: "28.06.2026", budget: "₺8.500" },
  { id: 102, title: "İzmir → Bursa - Konteyner", origin: "İzmir", dest: "Bursa", weight: "8000 kg", budget: "₺12.000", status: "assigned", date: "29.06.2026", bids: 0 },
  { id: 103, title: "Antalya → Ankara - Ev Eşyası", origin: "Antalya", dest: "Ankara", weight: "1500 kg", budget: "₺5.200", status: "in_transit", date: "27.06.2026", bids: 0 },
];

const recentBids = [
  { id: 1, driver: "Ahmet Yılmaz", rating: 4.8, trips: 142, vehicle: "10 Teker Kamyon", load: "Ankara → İstanbul - Paletli Gıda", amount: "₺8.000", original: "₺8.500", time: "2 saat önce", status: "pending" },
  { id: 2, driver: "Mehmet Kaya", rating: 4.5, trips: 89, vehicle: "Kırkayak", load: "Ankara → İstanbul - Paletli Gıda", amount: "₺7.500", original: "₺8.500", time: "3 saat önce", status: "pending" },
  { id: 3, driver: "Ali Şen", rating: 4.9, trips: 231, vehicle: "Tır", load: "Ankara → İstanbul - Paletli Gıda", amount: "₺8.200", original: "₺8.500", time: "5 saat önce", status: "pending" },
];

export default function ShipperDashboard() {
  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold text-white tracking-tight">Hoş Geldiniz</h1>
          <p className="text-slate-500 text-sm mt-1">Yük sahibi paneline genel bakış</p>
        </div>
        <Link to="/shipper/new-load" className="flex items-center gap-2 px-5 py-2.5 bg-emerald-500 hover:bg-emerald-400 text-[#0F0F12] font-semibold rounded-xl transition-all hover:scale-[1.02] text-sm">
          <PlusCircle className="w-4 h-4" /> Yeni İlan Oluştur
        </Link>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {stats.map((s) => {
          const Icon = s.icon;
          return (
            <div key={s.label} className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-4 hover:border-[#3E3E45] transition-colors">
              <div className="flex items-center justify-between mb-3">
                <div className={`w-10 h-10 rounded-lg ${s.bg} flex items-center justify-center`}>
                  <Icon className={`w-5 h-5 ${s.color}`} />
                </div>
              </div>
              <p className="text-2xl font-bold text-white">{s.value}</p>
              <p className="text-slate-500 text-xs">{s.label}</p>
            </div>
          );
        })}
      </div>

      <div className="grid lg:grid-cols-3 gap-6">
        <div className="lg:col-span-2 bg-[#18181C] border border-[#2E2E35] rounded-xl">
          <div className="px-5 py-4 border-b border-[#2E2E35] flex items-center justify-between">
            <h2 className="text-white font-semibold text-sm">Aktif İlanlarım</h2>
            <Link to="/shipper/loads" className="text-emerald-500 text-xs hover:text-emerald-400 flex items-center gap-1">
              Tümünü Gör <ArrowRight className="w-3 h-3" />
            </Link>
          </div>
          <div className="divide-y divide-[#2E2E35]">
            {activeLoads.map((load) => (
              <div key={load.id} className="px-5 py-4 flex items-center justify-between hover:bg-white/[0.02] transition-colors">
                <div>
                  <p className="text-white text-sm font-medium">{load.title}</p>
                  <div className="flex items-center gap-3 mt-1">
                    <span className="text-slate-500 text-xs">#{load.id}</span>
                    <span className="text-slate-500 text-xs">{load.date}</span>
                    <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${
                      load.status === "bidding" ? "bg-amber-500/10 text-amber-400" :
                      load.status === "assigned" ? "bg-blue-500/10 text-blue-400" :
                      "bg-emerald-500/10 text-emerald-400"
                    }`}>
                      {load.status === "bidding" ? "Teklif Alınıyor" : load.status === "assigned" ? "Atandı" : "Yolda"}
                    </span>
                  </div>
                </div>
                <div className="text-right">
                  <p className="text-white text-sm font-semibold">{load.budget}</p>
                  {load.bids > 0 && <p className="text-amber-500 text-xs">{load.bids} teklif</p>}
                </div>
              </div>
            ))}
          </div>
        </div>

        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl">
          <div className="px-5 py-4 border-b border-[#2E2E35] flex items-center justify-between">
            <h2 className="text-white font-semibold text-sm">Son Teklifler</h2>
            <Link to="/shipper/bids" className="text-emerald-500 text-xs hover:text-emerald-400 flex items-center gap-1">
              Tümü <ArrowRight className="w-3 h-3" />
            </Link>
          </div>
          <div className="divide-y divide-[#2E2E35]">
            {recentBids.map((bid) => (
              <div key={bid.id} className="px-5 py-4 hover:bg-white/[0.02] transition-colors">
                <div className="flex items-center justify-between mb-1">
                  <p className="text-white text-sm font-medium">{bid.driver}</p>
                  <div className="flex items-center gap-1">
                    <Star className="w-3.5 h-3.5 text-amber-500 fill-amber-500" />
                    <span className="text-amber-500 text-xs">{bid.rating}</span>
                  </div>
                </div>
                <p className="text-slate-500 text-xs mb-2">{bid.load}</p>
                <div className="flex items-center justify-between">
                  <span className="text-slate-600 text-xs">{bid.vehicle}</span>
                  <span className="text-emerald-400 text-sm font-semibold">{bid.amount}</span>
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>

      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        {[
          { label: "Yeni İlan", icon: PlusCircle, path: "/shipper/new-load", color: "text-emerald-500", bg: "bg-emerald-500/10" },
          { label: "İlanlarım", icon: ClipboardList, path: "/shipper/loads", color: "text-amber-500", bg: "bg-amber-500/10" },
          { label: "Takip", icon: MapPin, path: "/shipper/tracking", color: "text-blue-500", bg: "bg-blue-500/10" },
          { label: "Cüzdan", icon: Wallet, path: "/shipper/payments", color: "text-purple-500", bg: "bg-purple-500/10" },
        ].map((action) => {
          const Icon = action.icon;
          return (
            <Link key={action.label} to={action.path} className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-4 hover:border-[#3E3E45] transition-all group text-center">
              <div className={`w-12 h-12 rounded-xl ${action.bg} flex items-center justify-center mx-auto mb-3 group-hover:scale-110 transition-transform`}>
                <Icon className={`w-6 h-6 ${action.color}`} />
              </div>
              <p className="text-white text-sm font-medium">{action.label}</p>
            </Link>
          );
        })}
      </div>
    </div>
  );
}
