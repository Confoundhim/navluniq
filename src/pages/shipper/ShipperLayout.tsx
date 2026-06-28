import { useState } from "react";
import { Link, useLocation } from "react-router";
import {
  LayoutDashboard, PlusCircle, ClipboardList, Handshake,
  MapPin, Wallet, FileCheck, Truck, UserCircle, Headphones,
  LogOut, Menu, ChevronLeft, Bell, Package,
} from "lucide-react";

const menuItems = [
  { id: "dashboard", label: "Ana Sayfa", icon: LayoutDashboard, path: "/shipper" },
  { id: "new-load", label: "Yeni İlan Oluştur", icon: PlusCircle, path: "/shipper/new-load" },
  { id: "loads", label: "İlanlarım", icon: ClipboardList, path: "/shipper/loads" },
  { id: "bids", label: "Gelen Teklifler", icon: Handshake, path: "/shipper/bids" },
  { id: "tracking", label: "Sevkiyat Takibi", icon: MapPin, path: "/shipper/tracking" },
  { id: "payments", label: "Ödemelerim", icon: Wallet, path: "/shipper/payments" },
  { id: "kyc", label: "KYC Belgelerim", icon: FileCheck, path: "/shipper/kyc" },
  { id: "vehicles", label: "Araç Yönetimi", icon: Truck, path: "/shipper/vehicles" },
  { id: "profile", label: "Profilim", icon: UserCircle, path: "/shipper/profile" },
  { id: "support", label: "Destek", icon: Headphones, path: "/shipper/support" },
];

export default function ShipperLayout({ children }: { children: React.ReactNode }) {
  const location = useLocation();
  const [collapsed, setCollapsed] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);

  const isActive = (path: string) => {
    if (path === "/shipper") return location.pathname === "/shipper";
    return location.pathname.startsWith(path);
  };

  return (
    <div className="flex h-screen bg-[#0F0F12] text-slate-300 overflow-hidden">
      {mobileOpen && (
        <div className="fixed inset-0 bg-black/60 z-40 lg:hidden" onClick={() => setMobileOpen(false)} />
      )}

      <aside className={`fixed lg:static inset-y-0 left-0 z-50 flex flex-col bg-[#0F0F12] border-r border-[#2E2E35] transition-all duration-300 ${collapsed ? "w-16" : "w-64"} ${mobileOpen ? "translate-x-0" : "-translate-x-full lg:translate-x-0"}`}>
        <div className={`flex items-center h-14 border-b border-[#2E2E35] ${collapsed ? "justify-center px-2" : "px-4 gap-3"}`}>
          <div className="w-8 h-8 rounded-lg bg-emerald-500 flex items-center justify-center shrink-0">
            <Package className="w-5 h-5 text-[#0F0F12]" />
          </div>
          {!collapsed && (
            <div className="flex items-center gap-1">
              <span className="text-white font-semibold text-lg tracking-tight">Yük</span>
              <span className="text-emerald-500 font-bold text-lg">Sahibi</span>
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
                className={`flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-all ${isActive(item.path) ? "text-emerald-500 bg-emerald-500/10 border-l-2 border-emerald-500" : "text-slate-400 hover:text-white hover:bg-white/5 border-l-2 border-transparent"}`}
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
                <div className="w-8 h-8 rounded-full bg-emerald-500/20 flex items-center justify-center">
                  <UserCircle className="w-4 h-4 text-emerald-500" />
                </div>
                <div className="flex-1 min-w-0">
                  <p className="text-white text-sm font-medium truncate">Yük Sahibi</p>
                  <p className="text-slate-500 text-xs truncate">+90 555 123 45 67</p>
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
