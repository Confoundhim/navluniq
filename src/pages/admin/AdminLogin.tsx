import { useState } from "react";
import { trpc } from "@/providers/trpc";
import { Truck, Shield, ArrowRight, Loader2, KeyRound, Mail } from "lucide-react";

export default function AdminLogin() {
  
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [otp, setOtp] = useState("");
  const [step, setStep] = useState<"login" | "otp">("login");
  const [error, setError] = useState("");
  const [currentUsername, setCurrentUsername] = useState("");

  const loginMutation = trpc.localAuth.login.useMutation({
    onSuccess: (data) => {
      if (data.requiresOtp) {
        setStep("otp");
        setCurrentUsername(data.username);
        setError("");
      }
    },
    onError: (err) => {
      setError(err.message);
    },
  });

  const verifyMutation = trpc.localAuth.verifyOtp.useMutation({
    onSuccess: (data) => {
      if (data.success && data.token) {
        localStorage.setItem("admin_token", data.token);
        window.location.hash = "#/admin";
        window.location.reload();
      }
    },
    onError: (err) => {
      setError(err.message);
    },
  });

  const handleLogin = (e: React.FormEvent) => {
    e.preventDefault();
    setError("");
    loginMutation.mutate({ username, password });
  };

  const handleVerify = (e: React.FormEvent) => {
    e.preventDefault();
    setError("");
    verifyMutation.mutate({ username: currentUsername, otp });
  };

  return (
    <div className="min-h-screen w-full flex items-center justify-center bg-[#0F0F12] relative overflow-hidden">
      {/* Background pattern */}
      <div className="absolute inset-0 opacity-5">
        <div className="absolute inset-0" style={{
          backgroundImage: `radial-gradient(circle at 1px 1px, #F59E0B 1px, transparent 0)`,
          backgroundSize: '40px 40px',
        }} />
      </div>

      <div className="relative w-full max-w-md mx-4">
        {/* Card */}
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
            <p className="text-slate-500 text-sm">Yönetim Paneli</p>
          </div>

          {step === "login" ? (
            <form onSubmit={handleLogin} className="space-y-5">
              {error && (
                <div className="p-3 rounded-lg bg-red-500/10 border-l-2 border-red-500 text-red-400 text-sm">
                  {error}
                </div>
              )}

              <div>
                <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">
                  Kullanıcı Adı
                </label>
                <div className="relative">
                  <Shield className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-600" />
                  <input
                    type="text"
                    value={username}
                    onChange={(e) => setUsername(e.target.value)}
                    placeholder="navluniq.com_admin"
                    className="w-full h-10 pl-10 pr-4 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm placeholder:text-slate-600 focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500/20 transition-all"
                    required
                  />
                </div>
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">
                  Şifre
                </label>
                <div className="relative">
                  <KeyRound className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-600" />
                  <input
                    type="password"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    placeholder="••••••••"
                    className="w-full h-10 pl-10 pr-4 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm placeholder:text-slate-600 focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500/20 transition-all"
                    required
                  />
                </div>
              </div>

              <button
                type="submit"
                disabled={loginMutation.isPending}
                className="w-full h-10 bg-amber-500 hover:bg-amber-400 text-[#0F0F12] font-semibold rounded-lg transition-all flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {loginMutation.isPending ? (
                  <Loader2 className="w-4 h-4 animate-spin" />
                ) : (
                  <>
                    <span>Giriş Yap</span>
                    <ArrowRight className="w-4 h-4" />
                  </>
                )}
              </button>
            </form>
          ) : (
            <form onSubmit={handleVerify} className="space-y-5">
              {error && (
                <div className="p-3 rounded-lg bg-red-500/10 border-l-2 border-red-500 text-red-400 text-sm">
                  {error}
                </div>
              )}

              <div className="text-center mb-4">
                <div className="w-12 h-12 rounded-full bg-amber-500/10 flex items-center justify-center mx-auto mb-3">
                  <Mail className="w-6 h-6 text-amber-500" />
                </div>
                <h3 className="text-white font-semibold mb-1">Doğrulama Kodu</h3>
                <p className="text-slate-500 text-sm">E-posta adresinize gönderilen 6 haneli kodu girin</p>
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">
                  OTP Kodu
                </label>
                <input
                  type="text"
                  value={otp}
                  onChange={(e) => setOtp(e.target.value.replace(/\D/g, "").slice(0, 6))}
                  placeholder="000000"
                  maxLength={6}
                  className="w-full h-10 px-4 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm text-center text-lg tracking-[0.5em] placeholder:text-slate-600 focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500/20 transition-all"
                  required
                />
              </div>

              <button
                type="submit"
                disabled={verifyMutation.isPending || otp.length !== 6}
                className="w-full h-10 bg-amber-500 hover:bg-amber-400 text-[#0F0F12] font-semibold rounded-lg transition-all flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {verifyMutation.isPending ? (
                  <Loader2 className="w-4 h-4 animate-spin" />
                ) : (
                  <>
                    <span>Doğrula</span>
                    <ArrowRight className="w-4 h-4" />
                  </>
                )}
              </button>

              <button
                type="button"
                onClick={() => { setStep("login"); setOtp(""); setError(""); }}
                className="w-full text-center text-sm text-slate-500 hover:text-slate-300 transition-colors"
              >
                Geri dön
              </button>
            </form>
          )}

          {/* Footer */}
          <div className="mt-6 pt-4 border-t border-[#2E2E35] text-center">
            <p className="text-slate-600 text-xs">
              NavlunIQ Admin Panel &copy; 2026
            </p>
          </div>
        </div>
      </div>
    </div>
  );
}
