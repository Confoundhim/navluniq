import { useState, useEffect } from "react";
import { useNavigate, useLocation, Link } from "react-router";
import { useLocalAuth } from "@/hooks/useLocalAuth";
import {
  LayoutDashboard, Truck, BrainCircuit, Users,
  Wallet, Scale, Megaphone, FileText, Settings,
  UserCog, Shield, ChevronLeft,
  ChevronRight, Search, Bell, LogOut, Menu,
  ChevronDown, Loader2
} from "lucide-react";

const menuItems = [
  { id: "dashboard", label: "Dashboard", icon: LayoutDashboard, path: "/admin" },
  { id: "operations", label: "Operasyon & İlan Havuzu", icon: Truck, path: "/admin/operations" },
  { id: "ai", label: "Yapay Zeka Merkezi", icon: BrainCircuit, path: "/admin/ai" },
  {
    id: "users", label: "Kullanıcılar & KYC", icon: Users,
    children: [
      { id: "shippers", label: "Yük Sahipleri", path: "/admin/shippers" },
      { id: "drivers", label: "Şoförler", path: "/admin/drivers" },
      { id: "kyc", label: "KYC Belgeleri", path: "/admin/kyc" },
    ]
  },
  { id: "finance", label: "Finans & Muhasebe", icon: Wallet, path: "/admin/finance" },
  {
    id: "disputes", label: "Uyuşmazlık & Destek", icon: Scale,
    children: [
      { id: "disputes", label: "Uyuşmazlıklar", path: "/admin/disputes" },
      { id: "tickets", label: "Destek Biletleri", path: "/admin/tickets" },
    ]
  },
  { id: "marketing", label: "Pazarlama & CRM", icon: Megaphone, path: "/admin/marketing" },
  { id: "cms", label: "CMS", icon: FileText, path: "/admin/cms" },
  { id: "settings", label: "Sistem Ayarları", icon: Settings, path: "/admin/settings" },
  { id: "personnel", label: "Personel & Roller", icon: UserCog, path: "/admin/personnel" },
  {
    id: "security", label: "Güvenlik & Sistem", icon: Shield,
    children: [
      { id: "firewall", label: "Güvenlik Duvarı", path: "/admin/firewall" },
      { id: "health", label: "Sistem Sağlığı", path: "/admin/health" },
      { id: "recovery", label: "Geri Yükleme", path: "/admin/recovery" },
    ]
  },
];

export default function AdminLayout({ children }: { children: React.ReactNode }) {
  const navigate = useNavigate();
  const location = useLocation();
  const { user, isAuthenticated, isLoading, logout } = useLocalAuth();
  const [collapsed, setCollapsed] = useState(false);
  const [expandedMenus, setExpandedMenus] = useState<string[]>(["users", "disputes", "security"]);
  const [mobileOpen, setMobileOpen] = useState(false);
  const [searchOpen, setSearchOpen] = useState(false);

  useEffect(() => {
    if (!isLoading && !isAuthenticated) {
      navigate("/admin/login");
    }
  }, [isLoading, isAuthenticated, navigate]);

  if (isLoading) {
    return (
      <div className="h-screen w-screen flex items-center justify-center bg-[#0F0F12]">
        <div className="flex flex-col items-center gap-4">
          <Loader2 className="w-8 h-8 text-amber-500 animate-spin" />
          <p className="text-slate-400 text-sm">Yükleniyor...</p>
        </div>
      </div>
    );
  }

  if (!isAuthenticated) return null;

  const toggleMenu = (id: string) => {
    setExpandedMenus(prev =>
      prev.includes(id) ? prev.filter(i => i !== id) : [...prev, id]
    );
  };

  const isActive = (path: string) => {
    if (path === "/admin") return location.pathname === "/admin";
    return location.pathname.startsWith(path);
  };

  return (
    <div className="flex h-screen bg-[#0F0F12] text-slate-300 overflow-hidden">
      {/* Mobile overlay */}
      {mobileOpen && (
        <div
          className="fixed inset-0 bg-black/60 z-40 lg:hidden"
          onClick={() => setMobileOpen(false)}
        />
      )}

      {/* Sidebar */}
      <aside
        className={`fixed lg:static inset-y-0 left-0 z-50 flex flex-col bg-[#0F0F12] border-r border-[#2E2E35] transition-all duration-250 ${
          collapsed ? "w-16" : "w-64"
        } ${mobileOpen ? "translate-x-0" : "-translate-x-full lg:translate-x-0"}`}
      >
        {/* Logo */}
        <div className={`flex items-center h-14 border-b border-[#2E2E35] ${collapsed ? "justify-center px-2" : "px-4 gap-3"}`}>
          <div className="w-8 h-8 rounded-lg bg-amber-500 flex items-center justify-center shrink-0">
            <Truck className="w-5 h-5 text-[#0F0F12]" />
          </div>
          {!collapsed && (
            <div className="flex items-center gap-1">
              <span className="text-white font-semibold text-lg tracking-tight">Navlun</span>
              <span className="text-amber-500 font-bold text-lg">IQ</span>
            </div>
          )}
          {collapsed && !mobileOpen && (
            <button
              onClick={() => setCollapsed(false)}
              className="absolute -right-3 top-16 w-6 h-6 rounded-full bg-[#232328] border border-[#2E2E35] flex items-center justify-center hover:bg-[#2E2E35] transition-colors"
            >
              <ChevronRight className="w-3 h-3 text-slate-400" />
            </button>
          )}
        </div>

        {/* Navigation */}
        <nav className="flex-1 overflow-y-auto py-3 px-2 space-y-1">
          {menuItems.map((item) => {
            const Icon = item.icon;
            if (item.children) {
              const isExpanded = expandedMenus.includes(item.id);
              const hasActiveChild = item.children.some(c => isActive(c.path));
              return (
                <div key={item.id}>
                  <button
                    onClick={() => toggleMenu(item.id)}
                    className={`w-full flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors ${
                      hasActiveChild
                        ? "text-amber-500 bg-amber-500/10"
                        : "text-slate-400 hover:text-white hover:bg-white/5"
                    }`}
                  >
                    {Icon && <Icon className="w-5 h-5 shrink-0" />}
                    {!collapsed && (
                      <>
                        <span className="flex-1 text-left">{item.label}</span>
                        <ChevronDown className={`w-4 h-4 transition-transform ${isExpanded ? "rotate-180" : ""}`} />
                      </>
                    )}
                  </button>
                  {isExpanded && !collapsed && (
                    <div className="ml-9 mt-1 space-y-1">
                      {item.children.map((child) => (
                        <Link
                          key={child.id}
                          to={child.path}
                          onClick={() => setMobileOpen(false)}
                          className={`block px-3 py-1.5 rounded-md text-sm transition-colors ${
                            isActive(child.path)
                              ? "text-amber-500 bg-amber-500/10"
                              : "text-slate-500 hover:text-slate-300 hover:bg-white/5"
                          }`}
                        >
                          {child.label}
                        </Link>
                      ))}
                    </div>
                  )}
                </div>
              );
            }
            return (
              <Link
                key={item.id}
                to={item.path}
                onClick={() => setMobileOpen(false)}
                className={`flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-all ${
                  isActive(item.path)
                    ? "text-amber-500 bg-amber-500/10 border-l-2 border-amber-500"
                    : "text-slate-400 hover:text-white hover:bg-white/5 border-l-2 border-transparent"
                }`}
              >
                {Icon && <Icon className="w-5 h-5 shrink-0" />}
                {!collapsed && <span>{item.label}</span>}
              </Link>
            );
          })}
        </nav>

        {/* Bottom */}
        <div className="border-t border-[#2E2E35] p-2">
          {!collapsed && (
            <div className="px-3 py-2 mb-2">
              <div className="flex items-center gap-3">
                <div className="w-8 h-8 rounded-full bg-amber-500/20 flex items-center justify-center">
                  <UserCog className="w-4 h-4 text-amber-500" />
                </div>
                <div className="flex-1 min-w-0">
                  <p className="text-white text-sm font-medium truncate">{user?.displayName ?? "Admin"}</p>
                  <p className="text-slate-500 text-xs truncate">{user?.email ?? ""}</p>
                </div>
              </div>
            </div>
          )}
          <button
            onClick={logout}
            className={`flex items-center gap-3 px-3 py-2 rounded-lg text-sm text-red-400 hover:text-red-300 hover:bg-red-400/10 transition-colors w-full ${collapsed ? "justify-center" : ""}`}
          >
            <LogOut className="w-5 h-5 shrink-0" />
            {!collapsed && <span>Çıkış Yap</span>}
          </button>
        </div>
      </aside>

      {/* Main Content */}
      <div className="flex-1 flex flex-col min-w-0 overflow-hidden">
        {/* Header */}
        <header className="h-14 flex items-center justify-between px-4 lg:px-6 border-b border-[#2E2E35] bg-[#0F0F12] shrink-0">
          <div className="flex items-center gap-3">
            <button
              onClick={() => setMobileOpen(true)}
              className="lg:hidden p-2 rounded-lg hover:bg-white/5 text-slate-400"
            >
              <Menu className="w-5 h-5" />
            </button>
            <button
              onClick={() => setCollapsed(!collapsed)}
              className="hidden lg:flex p-2 rounded-lg hover:bg-white/5 text-slate-400"
            >
              {collapsed ? <ChevronRight className="w-5 h-5" /> : <ChevronLeft className="w-5 h-5" />}
            </button>
          </div>

          <div className="flex items-center gap-2">
            <button
              onClick={() => setSearchOpen(!searchOpen)}
              className="p-2 rounded-lg hover:bg-white/5 text-slate-400 hover:text-white transition-colors"
            >
              <Search className="w-5 h-5" />
            </button>
            <button className="relative p-2 rounded-lg hover:bg-white/5 text-slate-400 hover:text-white transition-colors">
              <Bell className="w-5 h-5" />
              <span className="absolute top-1.5 right-1.5 w-2 h-2 bg-red-500 rounded-full" />
            </button>
          </div>
        </header>

        {/* Page Content */}
        <main className="flex-1 overflow-y-auto p-4 lg:p-6">
          <div className="animate-in fade-in duration-200 slide-in-from-bottom-2">
            {children}
          </div>
        </main>
      </div>
    </div>
  );
}
