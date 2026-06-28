import { Check, Upload } from "lucide-react";
import { useState } from "react";

const kycItems = [
  { id: "license", label: "Ehliyet (Surucu Belgesi)", status: "verified", icon: "🪪" },
  { id: "src", label: "SRC Belgesi", status: "verified", icon: "📜" },
  { id: "psycho", label: "Psikoteknik Raporu", status: "verified", icon: "🧠" },
  { id: "k_belge", label: "K Belgesi", status: "optional", icon: "📋" },
  { id: "vehicle", label: "Arac Fotograflari", status: "pending", icon: "🚛" },
  { id: "registration", label: "Arac Ruhsati", status: "verified", icon: "📄" },
  { id: "insurance", label: "Arac Sigorta Policesi", status: "pending", icon: "🛡️" },
];

export default function KycDocs() {
  const [docs, setDocs] = useState(kycItems);

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-white tracking-tight">KYC Belgelerim</h1>
        <p className="text-slate-500 text-sm mt-1">Kimlik dogrulama belgeleriniz</p>
      </div>
      <div className="grid sm:grid-cols-3 gap-4">
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-4 text-center">
          <p className="text-emerald-400 text-2xl font-bold">{docs.filter(d => d.status === "verified").length}</p>
          <p className="text-slate-500 text-xs">Onayli</p>
        </div>
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-4 text-center">
          <p className="text-amber-400 text-2xl font-bold">{docs.filter(d => d.status === "pending").length}</p>
          <p className="text-slate-500 text-xs">Beklemede</p>
        </div>
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-4 text-center">
          <p className="text-slate-400 text-2xl font-bold">{docs.filter(d => d.status === "optional").length}</p>
          <p className="text-slate-500 text-xs">Opsiyonel</p>
        </div>
      </div>
      <div className="space-y-3">
        {docs.map(doc => (
          <div key={doc.id} className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-4 flex items-center gap-4 hover:border-[#3E3E45] transition-all">
            <div className="w-10 h-10 rounded-lg bg-[#232328] flex items-center justify-center text-lg">{doc.icon}</div>
            <div className="flex-1">
              <p className="text-white text-sm font-medium">{doc.label}</p>
              <span className={`text-xs px-2 py-0.5 rounded-full ${doc.status === "verified" ? "bg-emerald-500/10 text-emerald-400" : doc.status === "pending" ? "bg-amber-500/10 text-amber-400" : "bg-slate-500/10 text-slate-400"}`}>
                {doc.status === "verified" ? "Onayli" : doc.status === "pending" ? "Beklemede" : "Opsiyonel"}
              </span>
            </div>
            <div>
              {doc.status === "verified" ? (
                <div className="w-8 h-8 rounded-full bg-emerald-500/10 flex items-center justify-center"><Check className="w-4 h-4 text-emerald-500" /></div>
              ) : (
                <label className="p-2 rounded-lg bg-[#232328] hover:bg-[#2E2E35] text-slate-400 hover:text-emerald-400 transition-colors cursor-pointer flex items-center gap-1">
                  <Upload className="w-4 h-4" />
                  <input type="file" accept=".pdf,.jpg,.jpeg,.png" className="hidden" onChange={() => { const updated = docs.map(d => d.id === doc.id ? {...d, status: "verified" as const} : d); setDocs(updated); }} />
                </label>
              )}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
