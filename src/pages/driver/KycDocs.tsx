import { useState } from "react";
import {
  Check, Upload, AlertTriangle, Shield, FileCheck,
  Clock, X, ChevronRight,
} from "lucide-react";

interface KycDoc {
  id: string;
  label: string;
  status: "verified" | "pending" | "expired" | "missing";
  required: boolean;
  description: string;
  icon: typeof FileCheck;
}

const kycDocs: KycDoc[] = [
  { id: "license", label: "Sürücü Belgesi (Ehliyet)", status: "verified", required: true, description: "Geçerli B, C, D veya E sınıfı ehliyet", icon: FileCheck },
  { id: "src", label: "SRC Belgesi", status: "verified", required: true, description: "1-2-3-4 numaralı SRC belgeleri", icon: FileCheck },
  { id: "psycho", label: "Psikoteknik Rapor", status: "verified", required: true, description: "Trafik psikoteknik değerlendirme", icon: FileCheck },
  { id: "k_belge", label: "K Belgesi (K1-K2-K3)", status: "pending", required: true, description: "Yetiştirme kursu belgesi", icon: Clock },
  { id: "adr", label: "ADR Sertifikası", status: "missing", required: false, description: "Tehlikeli madde taşımacılığı (opsiyonel)", icon: AlertTriangle },
  { id: "criminal", label: "Adli Sicil Kaydı", status: "verified", required: true, description: "Güncel sabıka kaydı", icon: FileCheck },
  { id: "identity", label: "Nüfus Cüzdanı / Pasaport", status: "verified", required: true, description: "Kimlik teyit belgesi", icon: FileCheck },
  { id: "health", label: "Sağlık Raporu", status: "expired", required: true, description: "Sürücü sağlık raporu", icon: AlertTriangle },
  { id: "photo", label: "Profil Fotoğrafı", status: "verified", required: true, description: "Son 6 ay içinde çekilmiş", icon: FileCheck },
];

export default function DriverKyc() {
  const [docs, setDocs] = useState<KycDoc[]>(kycDocs);
  const [selectedDoc, setSelectedDoc] = useState<KycDoc | null>(null);
  const [uploadSuccess, setUploadSuccess] = useState(false);

  const handleUpload = () => {
    setUploadSuccess(true);
    setTimeout(() => {
      setUploadSuccess(false);
      if (selectedDoc) {
        setDocs(docs.map(d => d.id === selectedDoc.id ? { ...d, status: "pending" as const } : d));
        setSelectedDoc(null);
      }
    }, 2000);
  };

  const verified = docs.filter(d => d.status === "verified").length;
  const pending = docs.filter(d => d.status === "pending").length;
  const expired = docs.filter(d => d.status === "expired").length;
  const missing = docs.filter(d => d.status === "missing").length;
  const required = docs.filter(d => d.required);
  const verifiedRequired = required.filter(d => d.status === "verified").length;

  const statusColor = (s: string) => {
    if (s === "verified") return "bg-emerald-500/10 text-emerald-400";
    if (s === "pending") return "bg-amber-500/10 text-amber-400";
    if (s === "expired") return "bg-red-500/10 text-red-400";
    return "bg-slate-500/10 text-slate-400";
  };
  const statusLabel = (s: string) => {
    if (s === "verified") return "Onaylı";
    if (s === "pending") return "Beklemede";
    if (s === "expired") return "Süresi Doldu";
    return "Eksik";
  };

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-white tracking-tight">KYC / Evraklarım</h1>
        <p className="text-slate-500 text-sm mt-1">Kimlik doğrulama ve evrak yönetimi</p>
      </div>

      {/* Progress Overview */}
      <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5">
        <div className="flex items-center justify-between mb-3">
          <div className="flex items-center gap-2">
            <Shield className="w-5 h-5 text-blue-500" />
            <span className="text-white text-sm font-medium">KYC Tamamlanma Durumu</span>
          </div>
          <span className="text-blue-400 text-sm font-bold">%{Math.round((verifiedRequired / required.length) * 100)}</span>
        </div>
        <div className="w-full h-3 bg-[#232328] rounded-full overflow-hidden">
          <div className="h-full bg-gradient-to-r from-blue-500 to-emerald-500 rounded-full transition-all" style={{ width: `${(verifiedRequired / required.length) * 100}%` }} />
        </div>
        <div className="grid grid-cols-4 gap-3 mt-4">
          <div className="text-center p-2 bg-emerald-500/5 rounded-lg">
            <p className="text-emerald-400 text-xl font-bold">{verified}</p>
            <p className="text-slate-500 text-[10px]">Onaylı</p>
          </div>
          <div className="text-center p-2 bg-amber-500/5 rounded-lg">
            <p className="text-amber-400 text-xl font-bold">{pending}</p>
            <p className="text-slate-500 text-[10px]">Beklemede</p>
          </div>
          <div className="text-center p-2 bg-red-500/5 rounded-lg">
            <p className="text-red-400 text-xl font-bold">{expired}</p>
            <p className="text-slate-500 text-[10px]">Süresi Doldu</p>
          </div>
          <div className="text-center p-2 bg-slate-500/5 rounded-lg">
            <p className="text-slate-400 text-xl font-bold">{missing}</p>
            <p className="text-slate-500 text-[10px]">Eksik</p>
          </div>
        </div>
        {expired > 0 && (
          <div className="mt-3 flex items-center gap-2 p-3 bg-red-500/5 border border-red-500/20 rounded-lg">
            <AlertTriangle className="w-4 h-4 text-red-500 shrink-0" />
            <p className="text-red-400 text-xs">{expired} belgenin süresi dolmuş. Yenileme yapmanız gerekiyor.</p>
          </div>
        )}
      </div>

      {/* Document List */}
      <div className="space-y-2">
        {docs.map(doc => {
          const Icon = doc.icon;
          return (
            <div
              key={doc.id}
              onClick={() => doc.status !== "verified" ? setSelectedDoc(doc) : undefined}
              className={`bg-[#18181C] border border-[#2E2E35] rounded-xl p-4 flex items-center gap-4 transition-all ${doc.status !== "verified" ? "cursor-pointer hover:border-blue-500/50" : "hover:border-[#3E3E45]"}`}
            >
              <div className={`w-10 h-10 rounded-lg flex items-center justify-center ${statusColor(doc.status)}`}>
                {doc.status === "verified" ? <Check className="w-5 h-5" /> : <Icon className="w-5 h-5" />}
              </div>
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2">
                  <p className="text-white text-sm font-medium">{doc.label}</p>
                  {doc.required && <span className="text-[10px] px-1.5 py-0.5 bg-red-500/10 text-red-400 rounded">Zorunlu</span>}
                </div>
                <p className="text-slate-600 text-xs">{doc.description}</p>
              </div>
              <div className="flex items-center gap-2">
                <span className={`text-xs px-2.5 py-1 rounded-full ${statusColor(doc.status)}`}>{statusLabel(doc.status)}</span>
                {doc.status !== "verified" && <ChevronRight className="w-4 h-4 text-slate-600" />}
              </div>
            </div>
          );
        })}
      </div>

      {/* Upload Modal */}
      {selectedDoc && (
        <div className="fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4" onClick={() => setSelectedDoc(null)}>
          <div className="bg-[#18181C] border border-[#2E2E35] rounded-2xl max-w-md w-full p-6 space-y-4" onClick={e => e.stopPropagation()}>
            {uploadSuccess ? (
              <div className="text-center py-6 space-y-3">
                <div className="w-16 h-16 rounded-full bg-emerald-500/10 flex items-center justify-center mx-auto">
                  <Check className="w-8 h-8 text-emerald-500" />
                </div>
                <h3 className="text-white font-semibold">Belge Yüklendi!</h3>
                <p className="text-slate-500 text-sm">Onay sürecine alındı. En kısa sürede değerlendirilecek.</p>
              </div>
            ) : (
              <>
                <div className="flex items-center justify-between">
                  <h3 className="text-white font-semibold">Belge Yükle</h3>
                  <button onClick={() => setSelectedDoc(null)} className="p-1 rounded hover:bg-white/5 text-slate-500"><X className="w-5 h-5" /></button>
                </div>
                <div className="bg-blue-500/5 border border-blue-500/20 rounded-xl p-4">
                  <p className="text-white text-sm font-medium">{selectedDoc.label}</p>
                  <p className="text-slate-500 text-xs mt-1">{selectedDoc.description}</p>
                  {selectedDoc.required && <span className="text-[10px] px-1.5 py-0.5 bg-red-500/10 text-red-400 rounded mt-2 inline-block">Zorunlu Belge</span>}
                </div>
                <div className="border-2 border-dashed border-[#2E2E35] rounded-xl p-8 text-center hover:border-blue-500/50 transition-colors cursor-pointer">
                  <Upload className="w-10 h-10 text-slate-600 mx-auto mb-2" />
                  <p className="text-slate-500 text-sm">Belgeyi yüklemek için tıklayın</p>
                  <p className="text-slate-600 text-xs mt-1">PDF, JPG veya PNG (max 10MB)</p>
                </div>
                <button onClick={handleUpload} className="w-full py-2.5 bg-blue-500 hover:bg-blue-400 text-white font-semibold rounded-lg transition-all flex items-center justify-center gap-2">
                  <Upload className="w-4 h-4" /> Yükle ve Gönder
                </button>
              </>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
