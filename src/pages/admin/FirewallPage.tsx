import { trpc } from "@/providers/trpc";
import { Ban, Lock } from "lucide-react";
import { useState } from "react";

export default function FirewallPage() {
  const [page] = useState(1);
  const { data, isLoading } = trpc.admin.security.listIpBans.useQuery({ page, limit: 20 });
  const utils = trpc.useUtils();
  const banIp = trpc.admin.security.banIp.useMutation({ onSuccess: () => { utils.admin.security.listIpBans.invalidate(); setShowForm(false); } });
  const unbanIp = trpc.admin.security.unbanIp.useMutation({ onSuccess: () => utils.admin.security.listIpBans.invalidate() });
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({ ipAddress: "", reason: "", isPermanent: false });

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-white tracking-tight">Güvenlik Duvarı</h1>
          <p className="text-slate-500 text-sm mt-1">IP engelleme ve güvenlik yönetimi</p>
        </div>
        <button onClick={() => setShowForm(!showForm)} className="flex items-center gap-2 px-4 py-2 bg-red-500 hover:bg-red-400 text-white font-semibold rounded-lg text-sm transition-colors">
          <Ban className="w-4 h-4" /> IP Engelle
        </button>
      </div>
      {showForm && (
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5 space-y-3">
          <h3 className="text-white font-semibold text-sm">IP Engelle</h3>
          <div className="flex items-center gap-3">
            <input type="text" value={form.ipAddress} onChange={e => setForm({...form, ipAddress: e.target.value})} placeholder="IP Adresi" className="flex-1 h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-red-500" />
            <input type="text" value={form.reason} onChange={e => setForm({...form, reason: e.target.value})} placeholder="Sebep" className="flex-1 h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-red-500" />
            <label className="flex items-center gap-2 text-slate-400 text-sm">
              <input type="checkbox" checked={form.isPermanent} onChange={e => setForm({...form, isPermanent: e.target.checked})} className="rounded" /> Kalıcı
            </label>
            <button onClick={() => banIp.mutate(form)} disabled={!form.ipAddress} className="px-4 py-2 bg-red-500 hover:bg-red-400 text-white font-semibold rounded-lg text-sm transition-colors disabled:opacity-50">Engelle</button>
          </div>
        </div>
      )}
      <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl overflow-x-auto">
        <table className="w-full">
          <thead>
            <tr className="bg-[#232328] text-slate-400 text-xs uppercase tracking-wider">
              <th className="text-left px-4 py-3">IP Adresi</th>
              <th className="text-left px-4 py-3">Sebep</th>
              <th className="text-left px-4 py-3">Tip</th>
              <th className="text-left px-4 py-3">Tarih</th>
              <th className="text-left px-4 py-3">İşlem</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-[#2E2E35]">
            {isLoading ? (
              <tr><td colSpan={5} className="px-4 py-8 text-center text-slate-600">Yükleniyor...</td></tr>
            ) : data?.items.length === 0 ? (
              <tr><td colSpan={5} className="px-4 py-8 text-center text-slate-600">Kayıt bulunamadı</td></tr>
            ) : (
              data?.items.map((ban) => (
                <tr key={ban.id} className="hover:bg-red-500/[0.02] transition-colors">
                  <td className="px-4 py-3 text-red-400 text-sm font-medium font-mono">{ban.ipAddress}</td>
                  <td className="px-4 py-3 text-slate-300 text-sm">{ban.reason || "—"}</td>
                  <td className="px-4 py-3"><span className={`px-2 py-0.5 rounded-full text-xs font-medium ${ban.isPermanent ? "bg-red-500/10 text-red-400" : "bg-yellow-500/10 text-yellow-400"}`}>{ban.isPermanent ? "Kalıcı" : "Geçici"}</span></td>
                  <td className="px-4 py-3 text-slate-500 text-xs">{new Date(ban.createdAt).toLocaleDateString("tr-TR")}</td>
                  <td className="px-4 py-3">
                    <button onClick={() => { if (confirm("Kaldırmak istediğinize emin misiniz?")) unbanIp.mutate({ id: ban.id }); }} className="p-1.5 rounded-lg hover:bg-emerald-500/10 text-slate-400 hover:text-emerald-400 transition-colors">
                      <Lock className="w-4 h-4" />
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
