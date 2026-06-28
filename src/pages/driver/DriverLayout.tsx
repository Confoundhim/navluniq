import { useState } from "react";
import { Link, useLocation } from "react-router";
import {
  LayoutDashboard, Search, Handshake, Route,
  Wallet, Truck, FileCheck, UserCircle, Headphones,
  LogOut, Menu, ChevronLeft, Bell, Star,
} from "lucide-react";

const menuItems = [
  { id: "dashboard", label: "Ana Sayfa", icon: LayoutDashboard, path: "/driver" },
  { id: "loads", label: "Mevcut Yükler", icon: Search, path: "/driver/loads" },
  { id: "bids", label: "Tekliflerim", icon: Handshake, path: "/driver/bids" },
  { id: "shipments", label: "Aktif Taşımalar", icon: Route, path: "/driver/shipments" },
  { id: "earnings", label: "Kazançlarım", icon: Wallet, path: "/driver/earnings" },
  { id: "vehicles", label: "Araçlarım", icon: Truck, path: "/driver/vehicles" },
  { id: "kyc", label: "KYC / Evraklar", icon: FileCheck, path: "/driver/kyc" },
  { id: "profile", label: "Profilim", icon: UserCircle, path: "/driver/profile" },
  { id: "support", label: "Destek", icon: Headphones, path: "/driver/support" },
];

export default function DriverLayout({ children }: { children: React.ReactNode }) {
  const location = useLocation();
  const [collapsed, setCollapsed] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);

  const isActive = (path: string) => {
    if (path === "/driver") return location.pathname === "/driver";
    return location.pathname.startsWith(path);
  };

  return (
    <div className="flex h-screen bg-[#0F0F12] text-slate-300 overflow-hidden">
      {mobileOpen && (
        <div className="fixed inset-0 bg-black/60 z-40 lg:hidden" onClick={() => setMobileOpen(false)} />
      )}

      <aside className={`fixed lg:static inset-y-0 left-0 z-50 flex flex-col bg-[#0F0F12] border-r border-[#2E2E35] transition-all duration-300 ${collapsed ? "w-16" : "w-64"} ${mobileOpen ? "translate-x-0" : "-translate-x-full lg:translate-x-0"}`}>
        <div className={`flex items-center h-14 border-b border-[#2E2E35] ${collapsed ? "justify-center px-2" : "px-4 gap-3"}`}>
          <div className="w-8 h-8 rounded-lg bg-blue-500 flex items-center justify-center shrink-0">
            <Truck className="w-5 h-5 text-[#0F0F12]" />
          </div>
          {!collapsed && (
            <div className="flex items-center gap-1">
              <span className="text-white font-semibold text-lg tracking-tight">Navlun</span>
              <span className="text-blue-500 font-bold text-lg">Şoför</span>
            </div>
          )}
        </div>

        <nav className="flex-1 overflow-y-auto py-3 px-2 space-y-1">
          {menuItems.map((item) => {
            const Icon = item.icon;
            return (
              <Link
                key={item.id}
                to={item.path}
                onClick={() => setMobileOpen(false)}
                className={`flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-all ${isActive(item.path) ? "text-blue-500 bg-blue-500/10 border-l-2 border-blue-500" : "text-slate-400 hover:text-white hover:bg-white/5 border-l-2 border-transparent"}`}
              >
                <Icon className="w-5 h-5 shrink-0" />
                {!collapsed && <span>{item.label}</span>}
              </Link>
            );
          })}
        </nav>

        <div className="border-t border-[#2E2E35] p-2">
          {!collapsed && (
            <div className="px-3 py-2 mb-2">
              <div className="flex items-center gap-3">
                <div className="w-8 h-8 rounded-full bg-blue-500/20 flex items-center justify-center">
                  <UserCircle className="w-4 h-4 text-blue-500" />
                </div>
                <div className="flex-1 min-w-0">
                  <p className="text-white text-sm font-medium truncate">Ali Şen</p>
                  <div className="flex items-center gap-1">
                    <Star className="w-3 h-3 text-amber-500 fill-amber-500" />
                    <span className="text-amber-500 text-xs">4.9</span>
                    <span className="text-slate-600 text-xs">(231 sefer)</span>
                  </div>
                </div>
              </div>
            </div>
          )}
          <button className={`flex items-center gap-3 px-3 py-2 rounded-lg text-sm text-red-400 hover:text-red-300 hover:bg-red-400/10 transition-colors w-full ${collapsed ? "justify-center" : ""}`}>
            <LogOut className="w-5 h-5 shrink-0" />
            {!collapsed && <span>Çıkış Yap</span>}
          </button>
        </div>
      </aside>

      <div className="flex-1 flex flex-col min-w-0 overflow-hidden">
        <header className="h-14 flex items-center justify-between px-4 lg:px-6 border-b border-[#2E2E35] bg-[#0F0F12] shrink-0">
          <div className="flex items-center gap-3">
            <button onClick={() => setMobileOpen(true)} className="lg:hidden p-2 rounded-lg hover:bg-white/5 text-slate-400">
              <Menu className="w-5 h-5" />
            </button>
            <button onClick={() => setCollapsed(!collapsed)} className="hidden lg:flex p-2 rounded-lg hover:bg-white/5 text-slate-400">
              {collapsed ? <Menu className="w-5 h-5" /> : <ChevronLeft className="w-5 h-5" />}
            </button>
          </div>
          <div className="flex items-center gap-2">
            <button className="relative p-2 rounded-lg hover:bg-white/5 text-slate-400 hover:text-white transition-colors">
              <Bell className="w-5 h-5" />
              <span className="absolute top-1.5 right-1.5 w-2 h-2 bg-red-500 rounded-full" />
            </button>
          </div>
        </header>

        <main className="flex-1 overflow-y-auto p-4 lg:p-6">
          {children}
        </main>
      </div>
    </div>
  );
}
