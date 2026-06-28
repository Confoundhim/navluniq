import { trpc } from "@/providers/trpc";
import {
  Users, Truck, Wallet, Scale, Ticket, Crown, ShieldCheck,
  TrendingUp, Activity, AlertTriangle
} from "lucide-react";

const statCards = [
  { key: "activeShippers", label: "Aktif Yük Sahibi", icon: Users, color: "text-amber-500", bg: "bg-amber-500/10" },
  { key: "activeDrivers", label: "Aktif Şoför", icon: Truck, color: "text-emerald-500", bg: "bg-emerald-500/10" },
  { key: "activeLoads", label: "Aktif Sevkiyat", icon: Activity, color: "text-blue-500", bg: "bg-blue-500/10" },
  { key: "totalShipments", label: "Toplam Sevkiyat", icon: ShieldCheck, color: "text-purple-500", bg: "bg-purple-500/10" },
  { key: "escrowTotal", label: "Havuz Bakiyesi", icon: Wallet, color: "text-amber-500", bg: "bg-amber-500/10", format: "currency" },
  { key: "premiumDrivers", label: "Premium Şoför", icon: Crown, color: "text-yellow-500", bg: "bg-yellow-500/10" },
  { key: "openDisputes", label: "Açık Uyuşmazlık", icon: Scale, color: "text-red-500", bg: "bg-red-500/10" },
  { key: "openTickets", label: "Açık Bilet", icon: Ticket, color: "text-orange-500", bg: "bg-orange-500/10" },
];

export default function Dashboard() {
  const { data: stats, isLoading: statsLoading } = trpc.admin.dashboard.stats.useQuery();
  const { data: finance } = trpc.admin.dashboard.financialSummary.useQuery();
  const { data: recentActivity } = trpc.admin.dashboard.recentActivity.useQuery();

  const formatValue = (key: string, value: number | undefined) => {
    if (value === undefined || value === null) return "—";
    if (key === "escrowTotal") {
      return new Intl.NumberFormat('tr-TR', { style: 'currency', currency: 'TRY' }).format(value);
    }
    return value.toLocaleString('tr-TR');
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-white tracking-tight">Dashboard</h1>
          <p className="text-slate-500 text-sm mt-1">NavlunIQ sistemi genel bakış</p>
        </div>
        <div className="flex items-center gap-2">
          <span className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-500/10 text-emerald-400 text-xs font-medium">
            <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse" />
            Sistem Aktif
          </span>
        </div>
      </div>

      {/* Stats Grid */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {statCards.map((card) => {
          const Icon = card.icon;
          const value = stats?.[card.key as keyof typeof stats];
          return (
            <div
              key={card.key}
              className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5 hover:border-[#3E3E45] transition-colors"
            >
              <div className="flex items-start justify-between mb-3">
                <div className={`w-10 h-10 rounded-lg ${card.bg} flex items-center justify-center`}>
                  <Icon className={`w-5 h-5 ${card.color}`} />
                </div>
              </div>
              <div className="text-2xl font-bold text-white mb-1">
                {statsLoading ? (
                  <div className="h-8 w-20 bg-[#232328] rounded animate-pulse" />
                ) : (
                  formatValue(card.key, value as number)
                )}
              </div>
              <p className="text-slate-500 text-xs">{card.label}</p>
            </div>
          );
        })}
      </div>

      {/* Financial Summary Row */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {/* Commission Revenue */}
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5">
          <div className="flex items-center gap-2 mb-4">
            <div className="w-8 h-8 rounded-lg bg-emerald-500/10 flex items-center justify-center">
              <TrendingUp className="w-4 h-4 text-emerald-500" />
            </div>
            <div>
              <p className="text-slate-400 text-xs">30 Günlük Komisyon Geliri</p>
              <p className="text-white text-lg font-bold">
                {finance ? new Intl.NumberFormat('tr-TR', { style: 'currency', currency: 'TRY' }).format(finance.commissionRevenue) : "—"}
              </p>
            </div>
          </div>
        </div>

        {/* Subscription Revenue */}
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5">
          <div className="flex items-center gap-2 mb-4">
            <div className="w-8 h-8 rounded-lg bg-blue-500/10 flex items-center justify-center">
              <Crown className="w-4 h-4 text-blue-500" />
            </div>
            <div>
              <p className="text-slate-400 text-xs">30 Günlük Abonelik Geliri</p>
              <p className="text-white text-lg font-bold">
                {finance ? new Intl.NumberFormat('tr-TR', { style: 'currency', currency: 'TRY' }).format(finance.subscriptionRevenue) : "—"}
              </p>
            </div>
          </div>
        </div>

        {/* System Alerts */}
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5">
          <div className="flex items-center gap-2 mb-3">
            <div className="w-8 h-8 rounded-lg bg-red-500/10 flex items-center justify-center">
              <AlertTriangle className="w-4 h-4 text-red-500" />
            </div>
            <p className="text-white font-medium text-sm">Sistem Uyarıları</p>
          </div>
          <div className="space-y-2">
            {stats && stats.openDisputes > 0 && (
              <div className="flex items-center gap-2 text-xs">
                <span className="w-1.5 h-1.5 rounded-full bg-red-500" />
                <span className="text-slate-400">{stats.openDisputes} açık uyuşmazlık bekliyor</span>
              </div>
            )}
            {stats && stats.openTickets > 0 && (
              <div className="flex items-center gap-2 text-xs">
                <span className="w-1.5 h-1.5 rounded-full bg-orange-500" />
                <span className="text-slate-400">{stats.openTickets} açık destek bileti</span>
              </div>
            )}
            {(!stats || (stats.openDisputes === 0 && stats.openTickets === 0)) && (
              <div className="flex items-center gap-2 text-xs">
                <span className="w-1.5 h-1.5 rounded-full bg-emerald-500" />
                <span className="text-slate-400">Aktif uyarı bulunmuyor</span>
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Recent Activity */}
      <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl">
        <div className="px-5 py-4 border-b border-[#2E2E35]">
          <h2 className="text-white font-semibold text-sm">Son Aktiviteler</h2>
        </div>
        <div className="divide-y divide-[#2E2E35]">
          {recentActivity && recentActivity.length > 0 ? (
            recentActivity.slice(0, 10).map((log) => (
              <div key={log.id} className="px-5 py-3 flex items-center gap-3 hover:bg-white/[0.02] transition-colors">
                <div className="w-2 h-2 rounded-full bg-amber-500 shrink-0" />
                <div className="flex-1 min-w-0">
                  <p className="text-slate-300 text-sm truncate">
                    <span className="text-amber-500 font-medium">{log.action}</span>
                    {' '}— {log.module}
                  </p>
                  <p className="text-slate-600 text-xs">
                    {new Date(log.createdAt).toLocaleString('tr-TR')}
                  </p>
                </div>
              </div>
            ))
          ) : (
            <div className="px-5 py-8 text-center">
              <Activity className="w-8 h-8 text-slate-700 mx-auto mb-2" />
              <p className="text-slate-600 text-sm">Henüz aktivite kaydı bulunmuyor</p>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
