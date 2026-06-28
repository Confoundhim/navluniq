import { useState, useEffect, useRef } from "react";
import { Link } from "react-router";
import {
  Truck, ShieldCheck, Zap, MapPin, ChevronRight,
  ChevronLeft, ChevronDown, Menu, X,
  Users, Route, Wallet, Instagram,
  MessageCircle, Navigation, Package, CheckCircle,
  ArrowRight, Play, Pause, Globe
} from "lucide-react";

// ============================================================
// NAVBAR
// ============================================================
function Navbar() {
  const [mobileOpen, setMobileOpen] = useState(false);
  const [scrolled, setScrolled] = useState(false);

  useEffect(() => {
    const handleScroll = () => setScrolled(window.scrollY > 20);
    window.addEventListener("scroll", handleScroll);
    return () => window.removeEventListener("scroll", handleScroll);
  }, []);

  const navLinks = [
    { label: "Anasayfa", href: "#" },
    { label: "Hakkımızda", href: "#about" },
    { label: "Nasıl Çalışır?", href: "#how" },
    { label: "Abonelik", href: "#pricing" },
    { label: "Hizmetlerimiz", href: "#services" },
    { label: "İletişim", href: "#contact" },
  ];

  return (
    <nav className={`fixed top-0 left-0 right-0 z-50 transition-all duration-300 ${scrolled ? "bg-[#0F0F12]/95 backdrop-blur-md border-b border-[#2E2E35]" : "bg-transparent"}`}>
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="flex items-center justify-between h-16">
          {/* Logo */}
          <Link to="/" className="flex items-center gap-2">
            <div className="w-8 h-8 rounded-lg bg-amber-500 flex items-center justify-center">
              <Truck className="w-5 h-5 text-[#0F0F12]" />
            </div>
            <span className="text-white font-bold text-lg tracking-tight">Navlun<span className="text-amber-500">IQ</span></span>
          </Link>

          {/* Desktop Nav */}
          <div className="hidden lg:flex items-center gap-1">
            {navLinks.map(link => (
              <a key={link.label} href={link.href} className="px-3 py-2 text-sm text-slate-300 hover:text-white transition-colors rounded-lg hover:bg-white/5">
                {link.label}
              </a>
            ))}
          </div>

          {/* Right Side */}
          <div className="flex items-center gap-2">
            <a href="/#/login" className="hidden sm:flex items-center gap-2 px-4 py-2 text-sm text-slate-300 hover:text-white transition-colors">
              Giriş Yap
            </a>
            <a href="#register" className="hidden sm:flex items-center gap-1.5 px-4 py-2 bg-amber-500 hover:bg-amber-400 text-[#0F0F12] font-semibold text-sm rounded-lg transition-colors">
              Şoför Ol <ArrowRight className="w-3.5 h-3.5" />
            </a>
            <button onClick={() => setMobileOpen(!mobileOpen)} className="lg:hidden p-2 text-slate-400 hover:text-white">
              {mobileOpen ? <X className="w-5 h-5" /> : <Menu className="w-5 h-5" />}
            </button>
          </div>
        </div>
      </div>

      {/* Mobile Menu */}
      {mobileOpen && (
        <div className="lg:hidden bg-[#0F0F12]/98 backdrop-blur-lg border-b border-[#2E2E35]">
          <div className="px-4 py-3 space-y-1">
            {navLinks.map(link => (
              <a key={link.label} href={link.href} onClick={() => setMobileOpen(false)} className="block px-3 py-2 text-sm text-slate-300 hover:text-white hover:bg-white/5 rounded-lg">
                {link.label}
              </a>
            ))}
            <div className="pt-2 border-t border-[#2E2E35] mt-2 space-y-2">
              <Link to="/admin/login" onClick={() => setMobileOpen(false)} className="block px-3 py-2 text-sm text-slate-300 hover:text-white">Giriş Yap</Link>
              <a href="#register" onClick={() => setMobileOpen(false)} className="block px-3 py-2 text-sm bg-amber-500 text-[#0F0F12] font-semibold rounded-lg text-center">Şoför Ol</a>
            </div>
          </div>
        </div>
      )}
    </nav>
  );
}

// ============================================================
// HERO SLIDER
// ============================================================
function HeroSlider() {
  const [current, setCurrent] = useState(0);
  const [isPlaying, setIsPlaying] = useState(true);

  const slides = [
    {
      tag: "YÜK SAHİPLERİ İÇİN",
      title: "Ödemeler Havuz Sistemi Güvencesinde!",
      subtitle: "Gerçek Zamanlı Eşleşme, Sıfır Risk.",
      description: "İlanlarınıza gelen şoför tekliflerini anlık olarak değerlendirip onaylayabilirsiniz. Ödemeleriniz havuz sistemi ile yükleriniz KYC doğrulamalı güvenilir şoförlerle korunmaktadır.",
      cta: "Yük Sahibi Olarak Katılın",
      ctaLink: "#register-shipper",
      bg: "from-amber-500/20 via-[#0F0F12] to-[#0F0F12]",
      icon: ShieldCheck,
    },
    {
      tag: "ŞOFÖRLER İÇİN",
      title: "Onlarca Grubu Manuel Takip Etmeyin!",
      subtitle: "Akıllı Dönüş Radarı ile Yükün Seni Bulsun.",
      description: "WhatsApp'ınızda paylaşılan karmaşık ilanlar anında panelinizde listelenir. Teslimat için yola çıktığınızda akıllı dönüş radarları dönüş yükünüzü sizin için araştırır.",
      cta: "Şoför Olarak Katılın",
      ctaLink: "#register-driver",
      bg: "from-blue-500/20 via-[#0F0F12] to-[#0F0F12]",
      icon: Route,
    },
  ];

  useEffect(() => {
    if (!isPlaying) return;
    const timer = setInterval(() => setCurrent(c => (c + 1) % slides.length), 6000);
    return () => clearInterval(timer);
  }, [isPlaying, slides.length]);

  const slide = slides[current];
  const Icon = slide.icon;

  return (
    <section className="relative min-h-[90vh] flex items-center pt-16 overflow-hidden">
      {/* Background gradient */}
      <div className={`absolute inset-0 bg-gradient-to-br ${slide.bg} transition-all duration-1000`} />
      <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_rgba(245,158,11,0.15)_0%,_transparent_50%)]" />

      <div className="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 w-full">
        <div className="grid lg:grid-cols-2 gap-12 items-center">
          <div className="space-y-6">
            <span className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-amber-500/10 border border-amber-500/20 text-amber-400 text-xs font-semibold tracking-wider uppercase">
              <Icon className="w-3.5 h-3.5" /> {slide.tag}
            </span>
            <h1 className="text-4xl sm:text-5xl lg:text-6xl font-bold text-white leading-tight tracking-tight transition-all duration-500">
              {slide.title}
            </h1>
            <p className="text-xl text-amber-500 font-medium">{slide.subtitle}</p>
            <p className="text-slate-400 text-lg leading-relaxed max-w-lg">{slide.description}</p>
            <div className="flex flex-wrap gap-3 pt-2">
              <a href={slide.ctaLink} className="inline-flex items-center gap-2 px-6 py-3 bg-amber-500 hover:bg-amber-400 text-[#0F0F12] font-bold rounded-xl transition-all hover:scale-[1.02]">
                {slide.cta} <ArrowRight className="w-5 h-5" />
              </a>
              <a href="#how" className="inline-flex items-center gap-2 px-6 py-3 bg-white/5 hover:bg-white/10 text-white font-medium rounded-xl border border-[#2E2E35] transition-all">
                Nasıl Çalışır? <Play className="w-4 h-4" />
              </a>
            </div>
          </div>
          <div className="hidden lg:flex items-center justify-center">
            <div className="relative">
              <div className="w-80 h-80 rounded-3xl bg-gradient-to-br from-amber-500/20 to-blue-500/10 border border-[#2E2E35] flex items-center justify-center">
                <div className="w-64 h-64 rounded-2xl bg-[#18181C] border border-[#2E2E35] flex flex-col items-center justify-center p-6 space-y-4">
                  <div className="w-16 h-16 rounded-2xl bg-amber-500/10 flex items-center justify-center">
                    <Icon className="w-8 h-8 text-amber-500" />
                  </div>
                  <div className="text-center">
                    <p className="text-white font-semibold">Navlun<span className="text-amber-500">IQ</span></p>
                    <p className="text-slate-500 text-sm">Akıllı Lojistik Ağı</p>
                  </div>
                  <div className="flex items-center gap-2 text-xs text-slate-400">
                    <span className="flex items-center gap-1"><ShieldCheck className="w-3 h-3 text-emerald-500" /> KYC Onaylı</span>
                    <span className="flex items-center gap-1"><Zap className="w-3 h-3 text-amber-500" /> Anlık</span>
                  </div>
                </div>
              </div>
              {/* Decorative elements */}
              <div className="absolute -top-4 -right-4 w-20 h-20 rounded-xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center">
                <Users className="w-8 h-8 text-amber-500" />
              </div>
              <div className="absolute -bottom-4 -left-4 w-16 h-16 rounded-lg bg-blue-500/10 border border-blue-500/20 flex items-center justify-center">
                <MapPin className="w-6 h-6 text-blue-500" />
              </div>
            </div>
          </div>
        </div>

        {/* Slide Controls */}
        <div className="flex items-center gap-4 mt-12">
          {slides.map((_, i) => (
            <button key={i} onClick={() => setCurrent(i)} className={`h-1.5 rounded-full transition-all duration-300 ${i === current ? "w-8 bg-amber-500" : "w-4 bg-[#2E2E35] hover:bg-[#3E3E45]"}`} />
          ))}
          <button onClick={() => setIsPlaying(!isPlaying)} className="ml-2 p-1.5 rounded-lg hover:bg-white/5 text-slate-500 hover:text-slate-300 transition-colors">
            {isPlaying ? <Pause className="w-3.5 h-3.5" /> : <Play className="w-3.5 h-3.5" />}
          </button>
        </div>
      </div>
    </section>
  );
}

// ============================================================
// STATS BAR
// ============================================================
function StatsBar() {
  const stats = [
    { value: "2,500+", label: "Aktif Araç", icon: Truck },
    { value: "1,800+", label: "Sistem İlanı", icon: Package },
    { value: "3,200+", label: "Web İlanı", icon: Globe },
    { value: "15,000+", label: "Başarılı Sevkiyat", icon: CheckCircle },
  ];

  return (
    <section className="relative -mt-8 z-10">
      <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
          {stats.map((stat) => {
            const Icon = stat.icon;
            return (
              <div key={stat.label} className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-4 flex items-center gap-3 hover:border-[#3E3E45] transition-colors">
                <div className="w-10 h-10 rounded-lg bg-amber-500/10 flex items-center justify-center shrink-0">
                  <Icon className="w-5 h-5 text-amber-500" />
                </div>
                <div>
                  <p className="text-white text-lg font-bold">{stat.value}</p>
                  <p className="text-slate-500 text-xs">{stat.label}</p>
                </div>
              </div>
            );
          })}
        </div>
      </div>
    </section>
  );
}

// ============================================================
// NAVLUN NEDIR
// ============================================================
function WhatIsNavlun() {
  return (
    <section id="about" className="py-20">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="grid lg:grid-cols-2 gap-12 items-center">
          <div className="space-y-6">
            <span className="text-amber-500 text-xs font-semibold uppercase tracking-wider">Navlun Nedir?</span>
            <h2 className="text-3xl sm:text-4xl font-bold text-white tracking-tight">
              Deniz, kara, hava veya demir yoluyla taşınan yükler için taşıyıcı firmaya ödenen taşıma ücretidir.
            </h2>
            <div className="w-16 h-1 bg-amber-500 rounded-full" />
          </div>
          <div className="space-y-6">
            <h3 className="text-2xl font-bold text-white">NavlunIQ <span className="text-amber-500">Kimdir?</span></h3>
            <p className="text-slate-400 leading-relaxed">
              Biz sadece bir lojistik yazılımı kodlamadık. Biz, gece gündüz direksiyon başında ömür tüketen şoförlerimiz ile, alın terini ve tüm sermayesini o yüke emanet eden iş insanlarımızın arasına <strong className="text-white">sarsılmaz bir güven köprüsü</strong> kurduk.
            </p>
            <div className="grid sm:grid-cols-2 gap-4 pt-2">
              <div className="p-4 bg-[#18181C] border border-[#2E2E35] rounded-xl">
                <Truck className="w-6 h-6 text-amber-500 mb-2" />
                <p className="text-white font-medium text-sm">Şoförlerimizin Yanındayız</p>
                <p className="text-slate-500 text-xs mt-1">Hak ettikleri kazanca rötarsız ulaşırlar.</p>
              </div>
              <div className="p-4 bg-[#18181C] border border-[#2E2E35] rounded-xl">
                <ShieldCheck className="w-6 h-6 text-emerald-500 mb-2" />
                <p className="text-white font-medium text-sm">Emeğinizi Koruyoruz</p>
                <p className="text-slate-500 text-xs mt-1">Yükleriniz KYC doğrulamalı şoförlerle güvende.</p>
              </div>
            </div>
            <p className="text-slate-300 italic border-l-2 border-amber-500 pl-4">
              "Lojistikte en değerli olan, yük sahibi ve şoför arasında kurulan sarsılmaz güveni sağlamaktır."
            </p>
          </div>
        </div>
      </div>
    </section>
  );
}

// ============================================================
// 3 ADIMDA NAVLUNIQ
// ============================================================
function HowItWorks() {
  const steps = [
    {
      number: "01",
      title: "Akıllı Eşleşme",
      description: "Akıllı lojistik ağımız, yük sahiplerinin ilanlarını tarar. Yüklere uygun araçları eşleştirir ve onaylanmış sürücülere bildirimler iletir. Şoförler yük sahiplerine teklifler verirler ve fiyatta anlaşırlar.",
      icon: Zap,
      color: "text-amber-500",
      bg: "bg-amber-500/10",
    },
    {
      number: "02",
      title: "Güvenli Havuz Ödemesi",
      description: "Yük sahibi parayı havuz sistemine yatırır teslimat başlar. Süreç çift taraflı canlı takip edilir. Şoförler için dönüş yükü radarları ilanlar araştırır ve bulduğu ilanları şoför panelinde listeler.",
      icon: Wallet,
      color: "text-emerald-500",
      bg: "bg-emerald-500/10",
    },
    {
      number: "03",
      title: "Teslimat & Ödeme",
      description: "Teslimat belgesi yüklenir, süreç tamamlanır. Yük sahibinin parası korunmuş olurken, şoförün hak edişi ise hesabına 1 gün sonra aktarılır.",
      icon: CheckCircle,
      color: "text-blue-500",
      bg: "bg-blue-500/10",
    },
  ];

  return (
    <section id="how" className="py-20 bg-[#0A0A0C]">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="text-center mb-16">
          <span className="text-amber-500 text-xs font-semibold uppercase tracking-wider">Süreç</span>
          <h2 className="text-3xl sm:text-4xl font-bold text-white mt-3 tracking-tight">3 Adımda NavlunIQ</h2>
          <p className="text-slate-500 mt-3 max-w-xl mx-auto">Yük sahibi ve şoförler için basit, güvenli ve şeffaf bir süreç.</p>
        </div>
        <div className="grid md:grid-cols-3 gap-6">
          {steps.map((step) => {
            const Icon = step.icon;
            return (
              <div key={step.number} className="relative group">
                <div className="bg-[#18181C] border border-[#2E2E35] rounded-2xl p-6 hover:border-[#3E3E45] transition-all h-full">
                  <div className="flex items-center justify-between mb-4">
                    <span className="text-4xl font-bold text-[#2E2E35] group-hover:text-amber-500/20 transition-colors">{step.number}</span>
                    <div className={`w-12 h-12 rounded-xl ${step.bg} flex items-center justify-center`}>
                      <Icon className={`w-6 h-6 ${step.color}`} />
                    </div>
                  </div>
                  <h3 className="text-white font-semibold text-lg mb-2">{step.title}</h3>
                  <p className="text-slate-400 text-sm leading-relaxed">{step.description}</p>
                </div>
              </div>
            );
          })}
        </div>
      </div>
    </section>
  );
}

// ============================================================
// VEHICLE TYPES
// ============================================================
function VehicleTypes() {
  const vehicles = [
    { name: "Otomobil", icon: "🚗" },
    { name: "Minivan", icon: "🚐" },
    { name: "Orta Panelvan", icon: "🚙" },
    { name: "Uzun Panelvan", icon: "🚛" },
    { name: "Kamyonet", icon: "🚚" },
    { name: "6 Teker Kamyon", icon: "🚛" },
    { name: "8 Teker Kamyon", icon: "🚛" },
    { name: "10 Teker Kamyon", icon: "🚛" },
    { name: "Kırkayak", icon: "🚛" },
    { name: "Tır", icon: "🚛" },
  ];

  const scrollRef = useRef<HTMLDivElement>(null);

  const scroll = (dir: "left" | "right") => {
    if (scrollRef.current) {
      scrollRef.current.scrollBy({ left: dir === "left" ? -300 : 300, behavior: "smooth" });
    }
  };

  return (
    <section id="services" className="py-20">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="text-center mb-12">
          <span className="text-amber-500 text-xs font-semibold uppercase tracking-wider">Filomuz</span>
          <h2 className="text-3xl sm:text-4xl font-bold text-white mt-3 tracking-tight">Desteklenen Araç Türleri</h2>
          <p className="text-slate-500 mt-3">Motosiklet dışındaki tüm araç tiplerini kapsarız.</p>
        </div>
        <div className="relative">
          <button onClick={() => scroll("left")} className="absolute left-0 top-1/2 -translate-y-1/2 z-10 w-10 h-10 rounded-full bg-[#18181C] border border-[#2E2E35] flex items-center justify-center text-slate-400 hover:text-white hover:border-amber-500 transition-colors">
            <ChevronLeft className="w-5 h-5" />
          </button>
          <button onClick={() => scroll("right")} className="absolute right-0 top-1/2 -translate-y-1/2 z-10 w-10 h-10 rounded-full bg-[#18181C] border border-[#2E2E35] flex items-center justify-center text-slate-400 hover:text-white hover:border-amber-500 transition-colors">
            <ChevronRight className="w-5 h-5" />
          </button>
          <div ref={scrollRef} className="flex gap-4 overflow-x-auto scrollbar-hide px-12 py-2" style={{ scrollbarWidth: "none", msOverflowStyle: "none" }}>
            {vehicles.map((v) => (
              <div key={v.name} className="flex-shrink-0 w-40 bg-[#18181C] border border-[#2E2E35] rounded-xl p-4 flex flex-col items-center text-center hover:border-amber-500/50 transition-all hover:scale-[1.02] cursor-pointer">
                <Truck className="w-10 h-10 text-amber-500 mb-3" />
                <p className="text-white text-sm font-medium">{v.name}</p>
              </div>
            ))}
          </div>
        </div>
      </div>
    </section>
  );
}

// ============================================================
// FAQ
// ============================================================
function FAQ() {
  const [openIndex, setOpenIndex] = useState<number | null>(0);

  const faqs = [
    {
      q: "Güvenli Havuz (Escrow) Sistemi nedir ve param ne zaman sürücüye aktarılır?",
      a: "Yük veren tarafından ödenen bedel, PayTR güvencesiyle bloke bir havuz hesabında saklanır. Sürücü yükü hasarsız teslim edip teslim onay belgesini sisteme yüklediğinde ve gönderici bunu onayladığında blokaj çözülerek para saniyeler içinde sürücünün hesabına aktarılır.",
    },
    {
      q: "Yapay zeka ile evrak onay (KYC) süreci ne kadar sürer?",
      a: "Sürücüler için Ehliyet, SRC Belgesi ve Psikoteknik Raporu; Yük Sahipleri için ise Vergi Levhası veya Şirket Beyanı zorunludur. Yüklediğiniz belgeler, yapay zeka motorumuz tarafından taranarak 10 saniye içinde yapısal olarak onaylanır.",
    },
    {
      q: "Premium Sürücü Aboneliği bana ne kazandırır?",
      a: "Premium sürücülerimiz, web siteleri ve WhatsApp gruplarından derlenen 'Sarı Rozetli' dış kaynak ilanlarına yaklaşık 20 dakika erken erişim hakkı elde eder. Ayrıca kendi üye oldukları WhatsApp gruplarını sisteme bağlayarak tüm ilanları tek ekrandan tarayabilirler.",
    },
    {
      q: "Aynı telefon numarası ile hem şoför hem yük sahibi olabilir miyim?",
      a: "Evet. Platformumuz çift hesap (multi-account) desteği sunar. Aynı numara ve şifreyle hem şoför hem yük sahibi hesabı açabilir, panelinizdeki güvenlik modülünden onay kodu ile saniyeler içinde rolleriniz arasında geçiş yapabilirsiniz.",
    },
    {
      q: "Premium aboneliğimi dilediğim zaman iptal edebilir miyim?",
      a: "Aboneliğinizi dilediğiniz an tek tıkla iptal edebilirsiniz. Ancak Premium abonelik elektronik ortamda anında teslim edilen gayri maddi bir dijital hizmet olduğundan, Mesafeli Satış Sözleşmesi uyarınca geçmişe dönük cayma ve ücret iadesi hakkı bulunmamaktadır.",
    },
  ];

  return (
    <section className="py-20 bg-[#0A0A0C]">
      <div className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="text-center mb-12">
          <span className="text-amber-500 text-xs font-semibold uppercase tracking-wider">SSS</span>
          <h2 className="text-3xl sm:text-4xl font-bold text-white mt-3 tracking-tight">Sıkça Sorulan Sorular</h2>
        </div>
        <div className="space-y-3">
          {faqs.map((faq, i) => (
            <div key={i} className="bg-[#18181C] border border-[#2E2E35] rounded-xl overflow-hidden">
              <button onClick={() => setOpenIndex(openIndex === i ? null : i)} className="w-full flex items-center justify-between px-5 py-4 text-left hover:bg-white/[0.02] transition-colors">
                <span className="text-white text-sm font-medium pr-4">{faq.q}</span>
                <ChevronDown className={`w-5 h-5 text-slate-500 shrink-0 transition-transform ${openIndex === i ? "rotate-180" : ""}`} />
              </button>
              {openIndex === i && (
                <div className="px-5 pb-4 text-slate-400 text-sm leading-relaxed border-t border-[#2E2E35] pt-3">
                  {faq.a}
                </div>
              )}
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

// ============================================================
// CTA SECTION
// ============================================================
function CTASection() {
  return (
    <section id="register" className="py-20">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="grid md:grid-cols-2 gap-6">
          {/* Shipper CTA */}
          <div id="register-shipper" className="relative overflow-hidden bg-gradient-to-br from-amber-500/10 to-[#18181C] border border-amber-500/20 rounded-2xl p-8 hover:border-amber-500/40 transition-all">
            <div className="relative z-10">
              <ShieldCheck className="w-10 h-10 text-amber-500 mb-4" />
              <h3 className="text-2xl font-bold text-white mb-2">Yük Sahibi Olarak Katılın</h3>
              <p className="text-slate-400 mb-6">En uygun maliyetlerle, güvenli nakliye hizmeti. Hemen ilanınızı verin, yapay zekanın belgelerini doğruladığı profesyonel şoförlerden teklif toplayın.</p>
              <a href="/#/register-shipper" className="inline-flex items-center gap-2 px-6 py-3 bg-amber-500 hover:bg-amber-400 text-[#0F0F12] font-bold rounded-xl transition-all">
                Kayıt Ol <ArrowRight className="w-4 h-4" />
              </a>
            </div>
          </div>
          {/* Driver CTA */}
          <div id="register-driver" className="relative overflow-hidden bg-gradient-to-br from-blue-500/10 to-[#18181C] border border-blue-500/20 rounded-2xl p-8 hover:border-blue-500/40 transition-all">
            <div className="relative z-10">
              <Route className="w-10 h-10 text-blue-500 mb-4" />
              <h3 className="text-2xl font-bold text-white mb-2">Şoför Olarak Katılın</h3>
              <p className="text-slate-400 mb-6">Boş kilometre yapmaya son verin. KYC belgelerinizi yükleyip onaylatın. Size en uygun ilanları anasayfanızdan değerlendirerek yüklere teklifler verin.</p>
              <a href="/#/register-driver" className="inline-flex items-center gap-2 px-6 py-3 bg-blue-500 hover:bg-blue-400 text-white font-bold rounded-xl transition-all">
                Kayıt Ol <ArrowRight className="w-4 h-4" />
              </a>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}

// ============================================================
// FOOTER
// ============================================================
function Footer() {
  return (
    <footer id="contact" className="bg-[#0A0A0C] border-t border-[#2E2E35]">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div className="grid grid-cols-2 md:grid-cols-4 gap-8">
          {/* Brand */}
          <div className="col-span-2 md:col-span-1">
            <div className="flex items-center gap-2 mb-3">
              <div className="w-7 h-7 rounded-md bg-amber-500 flex items-center justify-center">
                <Truck className="w-4 h-4 text-[#0F0F12]" />
              </div>
              <span className="text-white font-bold">Navlun<span className="text-amber-500">IQ</span></span>
            </div>
            <p className="text-slate-500 text-sm">Lojistikte güvensizliği ortadan kaldıran akıllı taşımacılık ekosistemi.</p>
          </div>
          {/* Discover */}
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
          {/* Support */}
          <div>
            <h4 className="text-white font-semibold text-sm mb-3">Destek</h4>
            <ul className="space-y-2">
              {[
                { label: "Sık Sorulan Sorular", href: "/#" },
                { label: "Müşteri Hizmetleri", href: "/#/contact" },
                { label: "WhatsApp Destek Hattı", href: "/#/contact" },
                { label: "info@navluniq.com", href: "mailto:info@navluniq.com" },
                { label: "İletişim", href: "/#/contact" },
              ].map(item => (
                <li key={item.label}><a href={item.href} className="text-slate-500 hover:text-amber-500 text-sm transition-colors">{item.label}</a></li>
              ))}
            </ul>
          </div>
          {/* Legal */}
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
          <p className="text-slate-600 text-sm">&copy; 2026 NavlunIQ. Tüm Hakları Saklıdır.</p>
          <div className="flex items-center gap-4">
            <a href="#" className="text-slate-500 hover:text-amber-500 transition-colors"><Instagram className="w-5 h-5" /></a>
            <a href="#" className="text-slate-500 hover:text-emerald-500 transition-colors"><MessageCircle className="w-5 h-5" /></a>
            <a href="#" className="text-slate-500 hover:text-blue-500 transition-colors"><Navigation className="w-5 h-5" /></a>
          </div>
        </div>
      </div>
    </footer>
  );
}

// ============================================================
// PAYMENT PARTNER
// ============================================================
function PaymentPartner() {
  return (
    <section className="py-12 border-y border-[#2E2E35]">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
        <p className="text-slate-500 text-sm mb-4">Güvenli Ödeme Partnerimiz</p>
        <div className="flex items-center justify-center gap-3">
          <Wallet className="w-8 h-8 text-amber-500" />
          <span className="text-white text-xl font-bold">PayTR</span>
          <span className="text-slate-600">|</span>
          <span className="text-slate-400 text-sm">Havuz Sistemi</span>
          <span className="text-slate-600">|</span>
          <span className="text-slate-400 text-sm">256-Bit SSL Secured</span>
        </div>
      </div>
    </section>
  );
}

// ============================================================
// MAIN HOME PAGE
// ============================================================
export default function Home() {
  return (
    <div className="min-h-screen bg-[#0F0F12] text-slate-300">
      <Navbar />
      <HeroSlider />
      <StatsBar />
      <WhatIsNavlun />
      <HowItWorks />
      <CTASection />
      <VehicleTypes />
      <PaymentPartner />
      <FAQ />
      <Footer />
    </div>
  );
}
