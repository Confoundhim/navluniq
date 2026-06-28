import { trpc } from "@/providers/trpc";
import { Plus } from "lucide-react";
import { useState } from "react";

export default function MarketingPage() {
  const [page] = useState(1);
  const { data } = trpc.admin.marketing.listCoupons.useQuery({ page, limit: 20 });
  const utils = trpc.useUtils();
  const createCoupon = trpc.admin.marketing.createCoupon.useMutation({
    onSuccess: () => { utils.admin.marketing.listCoupons.invalidate(); setShowForm(false); }
  });
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({ code: "", description: "", discountType: "percentage" as const, discountValue: 0, maxUses: undefined as number | undefined });

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-white tracking-tight">Pazarlama & CRM</h1>
          <p className="text-slate-500 text-sm mt-1">Kupon ve pazarlama yönetimi</p>
        </div>
        <button onClick={() => setShowForm(!showForm)} className="flex items-center gap-2 px-4 py-2 bg-amber-500 hover:bg-amber-400 text-[#0F0F12] font-semibold rounded-lg text-sm transition-colors">
          <Plus className="w-4 h-4" /> Yeni Kupon
        </button>
      </div>
      {showForm && (
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5 space-y-3">
          <h3 className="text-white font-semibold text-sm">Yeni Kupon Oluştur</h3>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div>
              <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">Kod</label>
              <input type="text" value={form.code} onChange={e => setForm({...form, code: e.target.value})} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-amber-500" placeholder="NAVLUN50" />
            </div>
            <div>
              <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">Tip</label>
              <select value={form.discountType} onChange={e => setForm({...form, discountType: e.target.value as any})} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-amber-500">
                <option value="percentage">Yüzde (%)</option>
                <option value="fixed_amount">Sabit Tutar</option>
              </select>
            </div>
            <div>
              <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">Değer</label>
              <input type="number" value={form.discountValue} onChange={e => setForm({...form, discountValue: Number(e.target.value)})} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-amber-500" />
            </div>
          </div>
          <div className="flex items-center gap-2">
            <button onClick={() => createCoupon.mutate(form)} disabled={!form.code || form.discountValue <= 0} className="px-4 py-2 bg-amber-500 hover:bg-amber-400 text-[#0F0F12] font-semibold rounded-lg text-sm transition-colors disabled:opacity-50">Oluştur</button>
            <button onClick={() => setShowForm(false)} className="px-4 py-2 bg-[#232328] text-slate-400 hover:text-white rounded-lg text-sm transition-colors">İptal</button>
          </div>
        </div>
      )}
      <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl overflow-x-auto">
        <table className="w-full">
          <thead>
            <tr className="bg-[#232328] text-slate-400 text-xs uppercase tracking-wider">
              <th className="text-left px-4 py-3">Kod</th>
              <th className="text-left px-4 py-3">Açıklama</th>
              <th className="text-left px-4 py-3">İndirim</th>
              <th className="text-left px-4 py-3">Kullanım</th>
              <th className="text-left px-4 py-3">Durum</th>
              <th className="text-left px-4 py-3">Tarih</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-[#2E2E35]">
            {data?.items.length === 0 ? (
              <tr><td colSpan={6} className="px-4 py-8 text-center text-slate-600">Kayıt bulunamadı</td></tr>
            ) : (
              data?.items.map((c) => (
                <tr key={c.id} className="hover:bg-amber-500/[0.02] transition-colors">
                  <td className="px-4 py-3 text-amber-400 text-sm font-medium">{c.code}</td>
                  <td className="px-4 py-3 text-slate-300 text-sm">{c.description || "—"}</td>
                  <td className="px-4 py-3 text-white text-sm">{c.discountType === "percentage" ? `%${c.discountValue}` : `₺${c.discountValue}`}</td>
                  <td className="px-4 py-3 text-slate-400 text-sm">{c.usedCount} / {c.maxUses || "∞"}</td>
                  <td className="px-4 py-3"><span className={`px-2 py-0.5 rounded-full text-xs font-medium ${c.status === "active" ? "bg-emerald-500/10 text-emerald-400" : "bg-red-500/10 text-red-400"}`}>{c.status}</span></td>
                  <td className="px-4 py-3 text-slate-500 text-xs">{new Date(c.createdAt).toLocaleDateString("tr-TR")}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
