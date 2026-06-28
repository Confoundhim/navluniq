import { trpc } from "@/providers/trpc";
import { CheckCircle, XCircle } from "lucide-react";
import { useState } from "react";

export default function KycPage() {
  const [page] = useState(1);
  const [status, setStatus] = useState("");
  const { data, isLoading } = trpc.admin.kyc.list.useQuery({ page, limit: 20, status: status || undefined });
  const utils = trpc.useUtils();
  const review = trpc.admin.kyc.review.useMutation({
    onSuccess: () => utils.admin.kyc.list.invalidate()
  });

  const filters = [
    { label: "Tümü", value: "" },
    { label: "Bekliyor", value: "pending" },
    { label: "Onaylı", value: "approved" },
    { label: "Reddedildi", value: "rejected" },
  ];

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-white tracking-tight">KYC Belgeleri</h1>
          <p className="text-slate-500 text-sm mt-1">Kimlik doğrulama belgeleri</p>
        </div>
      </div>
      <div className="flex items-center gap-2">
        {filters.map(f => (
          <button
            key={f.value}
            onClick={() => setStatus(f.value)}
            className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-colors ${
              status === f.value ? "bg-amber-500 text-[#0F0F12]" : "bg-[#232328] text-slate-400 hover:text-white"
            }`}
          >
            {f.label}
          </button>
        ))}
      </div>
      <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl overflow-x-auto">
        <table className="w-full">
          <thead>
            <tr className="bg-[#232328] text-slate-400 text-xs uppercase tracking-wider">
              <th className="text-left px-4 py-3">ID</th>
              <th className="text-left px-4 py-3">Tip</th>
              <th className="text-left px-4 py-3">Belge Türü</th>
              <th className="text-left px-4 py-3">AI Durum</th>
              <th className="text-left px-4 py-3">Admin Durum</th>
              <th className="text-left px-4 py-3">Tarih</th>
              <th className="text-left px-4 py-3">İşlem</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-[#2E2E35]">
            {isLoading ? (
              <tr><td colSpan={7} className="px-4 py-8 text-center text-slate-600">Yükleniyor...</td></tr>
            ) : data?.items.length === 0 ? (
              <tr><td colSpan={7} className="px-4 py-8 text-center text-slate-600">Kayıt bulunamadı</td></tr>
            ) : (
              data?.items.map((doc) => (
                <tr key={doc.id} className="hover:bg-amber-500/[0.02] transition-colors">
                  <td className="px-4 py-3 text-slate-400 text-sm">#{doc.id}</td>
                  <td className="px-4 py-3">
                    <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${doc.entityType === "shipper" ? "bg-blue-500/10 text-blue-400" : "bg-emerald-500/10 text-emerald-400"}`}>
                      {doc.entityType === "shipper" ? "Yük Sahibi" : "Şoför"}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-white text-sm">{doc.documentType}</td>
                  <td className="px-4 py-3">
                    <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${
                      doc.aiStatus === "approved" ? "bg-emerald-500/10 text-emerald-400" :
                      doc.aiStatus === "rejected" ? "bg-red-500/10 text-red-400" :
                      "bg-yellow-500/10 text-yellow-400"
                    }`}>{doc.aiStatus}</span>
                  </td>
                  <td className="px-4 py-3">
                    <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${
                      doc.adminStatus === "approved" ? "bg-emerald-500/10 text-emerald-400" :
                      doc.adminStatus === "rejected" ? "bg-red-500/10 text-red-400" :
                      "bg-yellow-500/10 text-yellow-400"
                    }`}>{doc.adminStatus}</span>
                  </td>
                  <td className="px-4 py-3 text-slate-500 text-xs">{new Date(doc.createdAt).toLocaleDateString("tr-TR")}</td>
                  <td className="px-4 py-3 flex items-center gap-1">
                    <button onClick={() => review.mutate({ id: doc.id, status: "approved" })} className="p-1.5 rounded-lg hover:bg-emerald-500/10 text-slate-400 hover:text-emerald-400 transition-colors" title="Onayla">
                      <CheckCircle className="w-4 h-4" />
                    </button>
                    <button onClick={() => review.mutate({ id: doc.id, status: "rejected", note: "Admin reddetti" })} className="p-1.5 rounded-lg hover:bg-red-500/10 text-slate-400 hover:text-red-400 transition-colors" title="Reddet">
                      <XCircle className="w-4 h-4" />
                    </button>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
