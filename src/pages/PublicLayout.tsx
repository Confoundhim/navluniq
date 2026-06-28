import { Link, useLocation } from "react-router";
import { useState, useEffect } from "react";
import { Truck, Menu, X, Instagram, MessageCircle, Navigation } from "lucide-react";

export default function PublicLayout({ children }: { children: React.ReactNode }) {
  const [mobileOpen, setMobileOpen] = useState(false);
  const [scrolled, setScrolled] = useState(false);
  const location = useLocation();

  useEffect(() => {
    setMobileOpen(false);
    window.scrollTo(0, 0);
  }, [location.pathname]);

  useEffect(() => {
    const handleScroll = () => setScrolled(window.scrollY > 20);
    window.addEventListener("scroll", handleScroll);
    return () => window.removeEventListener("scroll", handleScroll);
  }, []);

  const navLinks = [
    { label: "Anasayfa", href: "/" },
    { label: "Hakkımızda", href: "/#/about" },
    { label: "Nasıl Çalışır?", href: "/#/how-it-works" },
    { label: "Abonelik", href: "/#/pricing" },
    { label: "Hizmetlerimiz", href: "/#/services" },
    { label: "İletişim", href: "/#/contact" },
  ];

  return (
    <div className="min-h-screen bg-[#0F0F12] text-slate-300">
      {/* Navbar */}
      <nav className={`fixed top-0 left-0 right-0 z-50 transition-all duration-300 ${scrolled ? "bg-[#0F0F12]/95 backdrop-blur-md border-b border-[#2E2E35]" : "bg-transparent"}`}>
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="flex items-center justify-between h-16">
            <Link to="/" className="flex items-center gap-2">
              <div className="w-8 h-8 rounded-lg bg-amber-500 flex items-center justify-center">
                <Truck className="w-5 h-5 text-[#0F0F12]" />
              </div>
              <span className="text-white font-bold text-lg tracking-tight">Navlun<span className="text-amber-500">IQ</span></span>
            </Link>
            <div className="hidden lg:flex items-center gap-1">
              {navLinks.map(link => (
                <a key={link.label} href={link.href} className="px-3 py-2 text-sm text-slate-300 hover:text-white transition-colors rounded-lg hover:bg-white/5">
                  {link.label}
                </a>
              ))}
            </div>
            <div className="flex items-center gap-2">
              <a href="/#/login" className="hidden sm:flex items-center gap-2 px-4 py-2 text-sm text-slate-300 hover:text-white transition-colors">
                Giriş Yap
              </a>
              <button onClick={() => setMobileOpen(!mobileOpen)} className="lg:hidden p-2 text-slate-400 hover:text-white">
                {mobileOpen ? <X className="w-5 h-5" /> : <Menu className="w-5 h-5" />}
              </button>
            </div>
          </div>
        </div>
        {mobileOpen && (
          <div className="lg:hidden bg-[#0F0F12]/98 backdrop-blur-lg border-b border-[#2E2E35]">
            <div className="px-4 py-3 space-y-1">
              {navLinks.map(link => (
                <a key={link.label} href={link.href} onClick={() => setMobileOpen(false)} className="block px-3 py-2 text-sm text-slate-300 hover:text-white hover:bg-white/5 rounded-lg">
                  {link.label}
                </a>
              ))}
              <div className="pt-2 border-t border-[#2E2E35] mt-2">
                <a href="/#/login" onClick={() => setMobileOpen(false)} className="block px-3 py-2 text-sm text-slate-300 hover:text-white">Giriş Yap</a>
              </div>
            </div>
          </div>
        )}
      </nav>

      {/* Spacer for fixed navbar */}
      <div className="h-16" />

      {/* Content */}
      <main>{children}</main>

      {/* Footer */}
      <footer className="bg-[#0A0A0C] border-t border-[#2E2E35]">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
          <div className="grid grid-cols-2 md:grid-cols-4 gap-8">
            <div className="col-span-2 md:col-span-1">
              <div className="flex items-center gap-2 mb-3">
                <div className="w-7 h-7 rounded-md bg-amber-500 flex items-center justify-center">
                  <Truck className="w-4 h-4 text-[#0F0F12]" />
                </div>
                <span className="text-white font-bold">Navlun<span className="text-amber-500">IQ</span></span>
              </div>
              <p className="text-slate-500 text-sm">Lojistikte güvensizliği ortadan kaldıran akıllı taşımacılık ekosistemi.</p>
            </div>
            <div>
              <h4 className="text-white font-semibold text-sm mb-3">Keşfet</h4>
              <ul className="space-y-2">
                {[
                  { label: "Hakkımızda", href: "/#/about" },
                  { label: "Yük Sahibi Misiniz?", href: "/#/register-shipper" },
                  { label: "Şoför Müsünüz?", href: "/#/register-driver" },
                  { label: "Abonelik Sistemi", href: "/#/pricing" },
                  { label: "Hizmetlerimiz", href: "/#/services" },
                  { label: "Nasıl Çalışır?", href: "/#/how-it-works" },
                ].map(item => (
                  <li key={item.label}><a href={item.href} className="text-slate-500 hover:text-amber-500 text-sm transition-colors">{item.label}</a></li>
                ))}
              </ul>
            </div>
            <div>
              <h4 className="text-white font-semibold text-sm mb-3">Destek</h4>
              <ul className="space-y-2">
                {[
                  { label: "Sık Sorulan Sorular", href: "/#/" },
                  { label: "Müşteri Hizmetleri", href: "/#/contact" },
                  { label: "WhatsApp Destek Hattı", href: "/#/contact" },
                  { label: "info@navluniq.com", href: "mailto:info@navluniq.com" },
                  { label: "İletişim", href: "/#/contact" },
                ].map(item => (
                  <li key={item.label}><a href={item.href} className="text-slate-500 hover:text-amber-500 text-sm transition-colors">{item.label}</a></li>
                ))}
              </ul>
            </div>
            <div>
              <h4 className="text-white font-semibold text-sm mb-3">Yasal</h4>
              <ul className="space-y-2">
                {[
                  { label: "KVKK Aydınlatma Metni", href: "/#/legal/kvkk" },
                  { label: "Kullanıcı Sözleşmesi", href: "/#/legal/terms" },
                  { label: "Gizlilik Politikası", href: "/#/legal/privacy" },
                  { label: "Mesafeli Satış Sözleşmesi", href: "/#/legal/distance-sales" },
                  { label: "İade Politikası", href: "/#/legal/refund" },
                ].map(item => (
                  <li key={item.label}><a href={item.href} className="text-slate-500 hover:text-amber-500 text-sm transition-colors">{item.label}</a></li>
                ))}
              </ul>
            </div>
          </div>
          <div className="mt-10 pt-6 border-t border-[#2E2E35] flex flex-col sm:flex-row items-center justify-between gap-4">
            <p className="text-slate-600 text-sm">&copy; 2026 NavlunIQ. Tum Haklari Saklidir.</p>
            <div className="flex items-center gap-4">
              <span className="text-slate-500 hover:text-amber-500 transition-colors cursor-pointer"><Instagram className="w-5 h-5" /></span>
              <span className="text-slate-500 hover:text-emerald-500 transition-colors cursor-pointer"><MessageCircle className="w-5 h-5" /></span>
              <span className="text-slate-500 hover:text-blue-500 transition-colors cursor-pointer"><Navigation className="w-5 h-5" /></span>
            </div>
          </div>
        </div>
      </footer>
    </div>
  );
}
