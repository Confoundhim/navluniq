import { Link } from "react-router";
import { Truck, Home, ArrowLeft } from "lucide-react";

export default function NotFound() {
  return (
    <div className="min-h-screen bg-[#0F0F12] flex items-center justify-center px-4">
      <div className="text-center space-y-6">
        <div className="w-20 h-20 rounded-2xl bg-amber-500/10 flex items-center justify-center mx-auto">
          <Truck className="w-10 h-10 text-amber-500" />
        </div>
        <div>
          <h1 className="text-6xl font-bold text-white mb-2">404</h1>
          <p className="text-slate-500 text-lg">Sayfa bulunamadi</p>
          <p className="text-slate-600 text-sm mt-2">Aradiginiz sayfa tasindi, kaldirildi veya hic var olmamis olabilir.</p>
        </div>
        <div className="flex items-center justify-center gap-3">
          <button
            onClick={() => window.history.back()}
            className="flex items-center gap-2 px-5 py-2.5 bg-[#232328] hover:bg-[#2E2E35] text-slate-300 rounded-xl transition-all text-sm"
          >
            <ArrowLeft className="w-4 h-4" /> Geri Don
          </button>
          <Link
            to="/"
            className="flex items-center gap-2 px-5 py-2.5 bg-amber-500 hover:bg-amber-400 text-[#0F0F12] font-semibold rounded-xl transition-all text-sm"
          >
            <Home className="w-4 h-4" /> Ana Sayfa
          </Link>
        </div>
      </div>
    </div>
  );
}
