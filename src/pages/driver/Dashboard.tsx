import { Link } from "react-router";
import {
  Wallet, Route, Star, TrendingUp,
  ArrowRight, MapPin, Clock, Package,
  ChevronRight, Truck, CircleDollarSign, Search, Handshake,
} from "lucide-react";

const stats = [
  { label: "Bu Ay Kazanç", value: "₺24.750", icon: Wallet, color: "text-emerald-500", bg: "bg-emerald-500/10", change: "+12%" },
  { label: "Aktif Taşıma", value: "2", icon: Route, color: "text-blue-500", bg: "bg-blue-500/10", change: "" },
  { label: "Puanım", value: "4.9", icon: Star, color: "text-amber-500", bg: "bg-amber-500/10", change: "231 sefer" },
  { label: "Tamamlanan", value: "18", icon: TrendingUp, color: "text-purple-500", bg: "bg-purple-500/10", change: "Bu ay" },
];

const activeShipments = [
  { id: 301, load: "Ankara → İstanbul - Paletli Gıda", status: "in_transit", progress: 65, pickup: "Ankara, Ostim", dropoff: "İstanbul, Tuzla", eta: "2 saat 15 dk", earnings: "₺8.500" },
  { id: 302, load: "İzmir → Bursa - Konteyner", status: "loading", progress: 10, pickup: "İzmir, Bornova", dropoff: "Bursa, Nilüfer", eta: "Hesaplanıyor", earnings: "₺12.000" },
];

const opportunities = [
  { id: 401, route: "İstanbul → Ankara", type: "Paletli Gıda", weight: "2500 kg", budget: "₺9.500", distance: "450 km", urgency: "Yüksek" },
  { id: 402, route: "Mersin → Gaziantep", type: "Tekstil", weight: "3200 kg", budget: "₺6.800", distance: "280 km", urgency: "Normal" },
  { id: 403, route: "Bursa → İzmir", type: "Konteyner", weight: "8000 kg", budget: "₺13.500", distance: "340 km", urgency: "Acil" },
];

const weeklyData = [
  { day: "Pzt", amount: 3200 },
  { day: "Sal", amount: 2800 },
  { day: "Çar", amount: 4500 },
  { day: "Per", amount: 5100 },
  { day: "Cum", amount: 3800 },
  { day: "Cmt", amount: 2900 },
  { day: "Paz", amount: 1950 },
];

const maxEarning = Math.max(...weeklyData.map(d => d.amount));

export default function DriverDashboard() {

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold text-white tracking-tight">Hoş Geldiniz, Ali Şen</h1>
          <p className="text-slate-500 text-sm mt-1">Sürücü paneline genel bakış</p>
        </div>
        <Link to="/driver/loads" className="flex items-center gap-2 px-5 py-2.5 bg-blue-500 hover:bg-blue-400 text-white font-semibold rounded-xl transition-all hover:scale-[1.02] text-sm">
          <Package className="w-4 h-4" /> Yük Ara
        </Link>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {stats.map((s) => {
          const Icon = s.icon;
          return (
            <div key={s.label} className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-4 hover:border-[#3E3E45] transition-colors">
              <div className="flex items-center justify-between mb-3">
                <div className={`w-10 h-10 rounded-lg ${s.bg} flex items-center justify-center`}>
                  <Icon className={`w-5 h-5 ${s.color}`} />
                </div>
                {s.change && <span className="text-emerald-400 text-xs font-medium">{s.change}</span>}
              </div>
              <p className="text-2xl font-bold text-white">{s.value}</p>
              <p className="text-slate-500 text-xs">{s.label}</p>
            </div>
          );
        })}
      </div>

      <div className="grid lg:grid-cols-3 gap-6">
        {/* Active Shipments */}
        <div className="lg:col-span-2 bg-[#18181C] border border-[#2E2E35] rounded-xl">
          <div className="px-5 py-4 border-b border-[#2E2E35] flex items-center justify-between">
            <h2 className="text-white font-semibold text-sm">Aktif Taşımalarım</h2>
            <Link to="/driver/shipments" className="text-blue-500 text-xs hover:text-blue-400 flex items-center gap-1">
              Tümü <ArrowRight className="w-3 h-3" />
            </Link>
          </div>
          <div className="divide-y divide-[#2E2E35]">
            {activeShipments.map((s) => (
              <div key={s.id} className="px-5 py-4 hover:bg-white/[0.02] transition-colors">
                <div className="flex items-center justify-between mb-3">
                  <div>
                    <p className="text-white text-sm font-medium">{s.load}</p>
                    <div className="flex items-center gap-3 mt-1">
                      <span className="text-slate-500 text-xs">#{s.id}</span>
                      <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${s.status === "in_transit" ? "bg-blue-500/10 text-blue-400" : "bg-amber-500/10 text-amber-400"}`}>
                        {s.status === "in_transit" ? "Yolda" : "Yükleniyor"}
                      </span>
                    </div>
                  </div>
                  <div className="text-right">
                    <p className="text-emerald-400 text-sm font-semibold">{s.earnings}</p>
                  </div>
                </div>
                <div className="space-y-2">
                  <div className="flex items-center gap-2 text-xs text-slate-500">
                    <MapPin className="w-3 h-3 text-blue-500" />
                    <span>{s.pickup} → {s.dropoff}</span>
                    <span className="text-slate-600">|</span>
                    <Clock className="w-3 h-3" />
                    <span className="text-blue-400">{s.eta}</span>
                  </div>
                  <div className="w-full h-2 bg-[#232328] rounded-full overflow-hidden">
                    <div className="h-full bg-gradient-to-r from-blue-500 to-blue-400 rounded-full transition-all" style={{ width: `${s.progress}%` }} />
                  </div>
                </div>
              </div>
            ))}
          </div>
        </div>

        {/* Weekly Earnings Chart */}
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5">
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-white font-semibold text-sm">Haftalık Kazanç</h2>
            <Link to="/driver/earnings" className="text-blue-500 text-xs hover:text-blue-400 flex items-center gap-1">
              Detay <ChevronRight className="w-3 h-3" />
            </Link>
          </div>
          <div className="flex items-end gap-2 h-40">
            {weeklyData.map((d) => (
              <div key={d.day} className="flex-1 flex flex-col items-center gap-1.5">
                <span className="text-emerald-400 text-[10px]">₺{(d.amount / 1000).toFixed(1)}k</span>
                <div className="w-full bg-[#232328] rounded-t-md overflow-hidden">
                  <div
                    className="w-full bg-blue-500 rounded-t-md transition-all hover:bg-blue-400"
                    style={{ height: `${(d.amount / maxEarning) * 100}px` }}
                  />
                </div>
                <span className="text-slate-500 text-[10px]">{d.day}</span>
              </div>
            ))}
          </div>
          <div className="mt-4 pt-3 border-t border-[#2E2E35] flex items-center justify-between">
            <span className="text-slate-500 text-xs">Toplam</span>
            <span className="text-white font-semibold text-sm">₺{weeklyData.reduce((a, b) => a + b.amount, 0).toLocaleString()}</span>
          </div>
        </div>
      </div>

      {/* Today's Opportunities */}
      <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl">
        <div className="px-5 py-4 border-b border-[#2E2E35] flex items-center justify-between">
          <h2 className="text-white font-semibold text-sm">Günün Fırsat Yükleri</h2>
          <Link to="/driver/loads" className="text-blue-500 text-xs hover:text-blue-400 flex items-center gap-1">
            Tümünü Gör <ArrowRight className="w-3 h-3" />
          </Link>
        </div>
        <div className="divide-y divide-[#2E2E35]">
          {opportunities.map((o) => (
            <div key={o.id} className="px-5 py-4 flex items-center justify-between hover:bg-white/[0.02] transition-colors">
              <div className="flex items-center gap-4">
                <div className="w-10 h-10 rounded-lg bg-blue-500/10 flex items-center justify-center shrink-0">
                  <Truck className="w-5 h-5 text-blue-500" />
                </div>
                <div>
                  <p className="text-white text-sm font-medium">{o.route}</p>
                  <div className="flex items-center gap-2 mt-1">
                    <span className="text-slate-500 text-xs">{o.type}</span>
                    <span className="text-slate-600">|</span>
                    <span className="text-slate-500 text-xs">{o.weight}</span>
                    <span className="text-slate-600">|</span>
                    <span className="text-slate-500 text-xs">{o.distance}</span>
                  </div>
                </div>
              </div>
              <div className="flex items-center gap-4">
                <div className="text-right hidden sm:block">
                  <p className="text-emerald-400 text-sm font-semibold">{o.budget}</p>
                  <span className={`text-xs ${o.urgency === "Acil" ? "text-red-400" : o.urgency === "Yüksek" ? "text-amber-400" : "text-slate-500"}`}>{o.urgency}</span>
                </div>
                <Link
                  to="/driver/loads"
                  className="px-4 py-2 bg-blue-500 hover:bg-blue-400 text-white text-sm font-medium rounded-lg transition-all"
                >
                  Teklif Ver
                </Link>
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* Quick Actions */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        {[
          { label: "Yük Ara", icon: Search, path: "/driver/loads", color: "text-blue-500", bg: "bg-blue-500/10" },
          { label: "Tekliflerim", icon: Handshake, path: "/driver/bids", color: "text-amber-500", bg: "bg-amber-500/10" },
          { label: "Aktif Taşıma", icon: Route, path: "/driver/shipments", color: "text-emerald-500", bg: "bg-emerald-500/10" },
          { label: "Kazançlar", icon: CircleDollarSign, path: "/driver/earnings", color: "text-purple-500", bg: "bg-purple-500/10" },
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
