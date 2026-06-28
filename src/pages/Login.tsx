import { useState } from "react";
import { Link } from "react-router";
import { Truck, ArrowRight, Loader2, KeyRound, Phone, Shield, User } from "lucide-react";

type LoginStep = "phone" | "password" | "otp";
type UserRole = "shipper" | "driver";

export default function Login() {
  const [step, setStep] = useState<LoginStep>("phone");
  const [role, setRole] = useState<UserRole>("shipper");
  const [phone, setPhone] = useState("");
  const [password, setPassword] = useState("");
  const [otp, setOtp] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const [showForgot, setShowForgot] = useState(false);

  const handlePhoneSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setError("");
    if (phone.length < 10) {
      setError("Gecerli bir telefon numarasi girin.");
      return;
    }
    setStep("password");
  };

  const handlePasswordSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setError("");
    if (password.length < 6) {
      setError("Sifre en az 6 karakter olmalidir.");
      return;
    }
    setLoading(true);
    // Simulate OTP send
    setTimeout(() => {
      setLoading(false);
      setStep("otp");
    }, 1000);
  };

  const handleOtpSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setError("");
    if (otp.length !== 6) {
      setError("6 haneli guvenlik kodunu girin.");
      return;
    }
    setLoading(true);
    setTimeout(() => {
      setLoading(false);
      window.location.hash = role === "shipper" ? "/shipper" : "/driver";
    }, 1000);
  };

  const formatPhone = (val: string) => {
    const cleaned = val.replace(/\D/g, "").slice(0, 11);
    if (cleaned.length <= 4) return cleaned;
    if (cleaned.length <= 7) return `${cleaned.slice(0, 4)} ${cleaned.slice(4)}`;
    if (cleaned.length <= 9) return `${cleaned.slice(0, 4)} ${cleaned.slice(4, 7)} ${cleaned.slice(7)}`;
    return `${cleaned.slice(0, 4)} ${cleaned.slice(4, 7)} ${cleaned.slice(7, 9)} ${cleaned.slice(9)}`;
  };

  return (
    <div className="min-h-screen w-full flex items-center justify-center bg-[#0F0F12] relative overflow-hidden">
      {/* Background */}
      <div className="absolute inset-0 opacity-5" style={{ backgroundImage: `radial-gradient(circle at 1px 1px, #F59E0B 1px, transparent 0)`, backgroundSize: '40px 40px' }} />

      <div className="relative w-full max-w-md mx-4">
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-2xl p-8 shadow-2xl">
          {/* Logo */}
          <div className="flex flex-col items-center mb-8">
            <div className="w-14 h-14 rounded-xl bg-amber-500 flex items-center justify-center mb-4">
              <Truck className="w-7 h-7 text-[#0F0F12]" />
            </div>
            <div className="flex items-center gap-1 mb-1">
              <span className="text-white font-bold text-2xl tracking-tight">Navlun</span>
              <span className="text-amber-500 font-bold text-2xl">IQ</span>
            </div>
            <p className="text-slate-500 text-sm">Hesabiniza giris yapin</p>
          </div>

          {/* Role Selector */}
          <div className="flex gap-2 mb-6 p-1 bg-[#232328] rounded-lg">
            <button onClick={() => setRole("shipper")} className={`flex-1 flex items-center justify-center gap-2 py-2.5 rounded-md text-sm font-medium transition-all ${role === "shipper" ? "bg-amber-500 text-[#0F0F12]" : "text-slate-400 hover:text-white"}`}>
              <User className="w-4 h-4" /> Yuk Sahibi
            </button>
            <button onClick={() => setRole("driver")} className={`flex-1 flex items-center justify-center gap-2 py-2.5 rounded-md text-sm font-medium transition-all ${role === "driver" ? "bg-amber-500 text-[#0F0F12]" : "text-slate-400 hover:text-white"}`}>
              <Truck className="w-4 h-4" /> Sofor
            </button>
          </div>

          {error && (
            <div className="p-3 rounded-lg bg-red-500/10 border-l-2 border-red-500 text-red-400 text-sm mb-4">{error}</div>
          )}

          {step === "phone" && (
            <form onSubmit={handlePhoneSubmit} className="space-y-5">
              <div>
                <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Telefon Numaraniz</label>
                <div className="relative">
                  <Phone className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-600" />
                  <input type="tel" value={phone} onChange={(e) => setPhone(formatPhone(e.target.value))} placeholder="5XX XXX XX XX" className="w-full h-10 pl-10 pr-4 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm placeholder:text-slate-600 focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500/20 transition-all" required />
                </div>
              </div>
              <button type="submit" className="w-full h-10 bg-amber-500 hover:bg-amber-400 text-[#0F0F12] font-semibold rounded-lg transition-all flex items-center justify-center gap-2">
                <span>Devam Et</span><ArrowRight className="w-4 h-4" />
              </button>
              <div className="flex items-center justify-between text-sm">
                <Link to="/" className="text-slate-500 hover:text-slate-300 transition-colors">Anasayfaya Don</Link>
                <Link to={`/register-${role}`} className="text-amber-500 hover:text-amber-400 transition-colors">Hesap Olustur</Link>
              </div>
            </form>
          )}

          {step === "password" && (
            <form onSubmit={handlePasswordSubmit} className="space-y-5">
              <div>
                <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Sifre</label>
                <div className="relative">
                  <KeyRound className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-600" />
                  <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} placeholder="********" className="w-full h-10 pl-10 pr-4 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm placeholder:text-slate-600 focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500/20 transition-all" required />
                </div>
              </div>
              <button type="submit" disabled={loading} className="w-full h-10 bg-amber-500 hover:bg-amber-400 text-[#0F0F12] font-semibold rounded-lg transition-all flex items-center justify-center gap-2 disabled:opacity-50">
                {loading ? <Loader2 className="w-4 h-4 animate-spin" /> : <><span>Giris Yap</span><ArrowRight className="w-4 h-4" /></>}
              </button>
              <div className="flex items-center justify-between text-sm">
                <button type="button" onClick={() => setStep("phone")} className="text-slate-500 hover:text-slate-300 transition-colors">Geri</button>
                <button type="button" onClick={() => setShowForgot(!showForgot)} className="text-amber-500 hover:text-amber-400 transition-colors">Sifremi Unuttum</button>
              </div>
              {showForgot && (
                <div className="p-3 rounded-lg bg-blue-500/10 border-l-2 border-blue-500 text-blue-400 text-xs">
                  Sifre sifirlama baglantisi telefon numaraniza gonderilecektir. Lutfen musteri hizmetleri ile iletisime gecin: info@navluniq.com
                </div>
              )}
            </form>
          )}

          {step === "otp" && (
            <form onSubmit={handleOtpSubmit} className="space-y-5">
              <div className="text-center mb-2">
                <div className="w-12 h-12 rounded-full bg-amber-500/10 flex items-center justify-center mx-auto mb-3">
                  <Shield className="w-6 h-6 text-amber-500" />
                </div>
                <h3 className="text-white font-semibold">Guvenlik Kodu</h3>
                <p className="text-slate-500 text-xs mt-1">Telefonunuza gonderilen 6 haneli kodu girin</p>
                <p className="text-slate-600 text-xs mt-1 font-mono">{phone}</p>
              </div>
              <input type="text" value={otp} onChange={(e) => setOtp(e.target.value.replace(/\D/g, "").slice(0, 6))} placeholder="000000" maxLength={6} className="w-full h-12 px-4 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-center text-lg tracking-[0.5em] placeholder:text-slate-600 focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500/20 transition-all" required />
              <button type="submit" disabled={loading || otp.length !== 6} className="w-full h-10 bg-amber-500 hover:bg-amber-400 text-[#0F0F12] font-semibold rounded-lg transition-all flex items-center justify-center gap-2 disabled:opacity-50">
                {loading ? <Loader2 className="w-4 h-4 animate-spin" /> : <><span>Dogrula</span><ArrowRight className="w-4 h-4" /></>}
              </button>
              <div className="flex items-center justify-between text-sm">
                <button type="button" onClick={() => setStep("password")} className="text-slate-500 hover:text-slate-300 transition-colors">Geri</button>
                <button type="button" onClick={() => { setOtp(""); setError("Yeni kod gonderildi."); }} className="text-amber-500 hover:text-amber-400 transition-colors text-xs">Kodu Tekrar Gonder</button>
              </div>
            </form>
          )}

          {/* Register links */}
          <div className="mt-6 pt-4 border-t border-[#2E2E35] space-y-2">
            <p className="text-slate-600 text-xs text-center">Henuz hesabiniz yok mu?</p>
            <div className="flex gap-2">
              <Link to="/register-shipper" className="flex-1 py-2 bg-[#232328] hover:bg-[#2E2E35] text-slate-300 hover:text-white text-sm font-medium rounded-lg border border-[#2E2E35] transition-all text-center flex items-center justify-center gap-1.5">
                <User className="w-3.5 h-3.5" /> Yuk Sahibi Kayit
              </Link>
              <Link to="/register-driver" className="flex-1 py-2 bg-[#232328] hover:bg-[#2E2E35] text-slate-300 hover:text-white text-sm font-medium rounded-lg border border-[#2E2E35] transition-all text-center flex items-center justify-center gap-1.5">
                <Truck className="w-3.5 h-3.5" /> Sofor Kayit
              </Link>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
