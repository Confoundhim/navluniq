import { trpc } from "@/providers/trpc";
import { Plus, Trash2 } from "lucide-react";
import { useState } from "react";

export default function CmsPage() {
  const { data: pagesList } = trpc.admin.cms.listPages.useQuery();
  const utils = trpc.useUtils();
  const createPage = trpc.admin.cms.createPage.useMutation({ onSuccess: () => { utils.admin.cms.listPages.invalidate(); setShowForm(false); } });
  const deletePage = trpc.admin.cms.deletePage.useMutation({ onSuccess: () => utils.admin.cms.listPages.invalidate() });
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({ slug: "", title: "", content: "", metaTitle: "", metaDescription: "" });

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-white tracking-tight">CMS</h1>
          <p className="text-slate-500 text-sm mt-1">İçerik yönetim sistemi</p>
        </div>
        <button onClick={() => setShowForm(!showForm)} className="flex items-center gap-2 px-4 py-2 bg-amber-500 hover:bg-amber-400 text-[#0F0F12] font-semibold rounded-lg text-sm transition-colors">
          <Plus className="w-4 h-4" /> Yeni Sayfa
        </button>
      </div>
      {showForm && (
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5 space-y-3">
          <h3 className="text-white font-semibold text-sm">Yeni Sayfa</h3>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">Slug</label>
              <input type="text" value={form.slug} onChange={e => setForm({...form, slug: e.target.value})} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-amber-500" placeholder="hakkimizda" />
            </div>
            <div>
              <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">Başlık</label>
              <input type="text" value={form.title} onChange={e => setForm({...form, title: e.target.value})} className="w-full h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-amber-500" placeholder="Hakkımızda" />
            </div>
          </div>
          <div>
            <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">İçerik (HTML)</label>
            <textarea value={form.content} onChange={e => setForm({...form, content: e.target.value})} rows={4} className="w-full px-3 py-2 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-amber-500 resize-y" placeholder="<p>İçerik...</p>" />
          </div>
          <div className="flex items-center gap-2">
            <button onClick={() => createPage.mutate(form)} disabled={!form.slug || !form.title || !form.content} className="px-4 py-2 bg-amber-500 hover:bg-amber-400 text-[#0F0F12] font-semibold rounded-lg text-sm transition-colors disabled:opacity-50">Oluştur</button>
            <button onClick={() => setShowForm(false)} className="px-4 py-2 bg-[#232328] text-slate-400 hover:text-white rounded-lg text-sm transition-colors">İptal</button>
          </div>
        </div>
      )}
      <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl overflow-x-auto">
        <table className="w-full">
          <thead>
            <tr className="bg-[#232328] text-slate-400 text-xs uppercase tracking-wider">
              <th className="text-left px-4 py-3">Slug</th>
              <th className="text-left px-4 py-3">Başlık</th>
              <th className="text-left px-4 py-3">Durum</th>
              <th className="text-left px-4 py-3">Tarih</th>
              <th className="text-left px-4 py-3">İşlem</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-[#2E2E35]">
            {pagesList?.length === 0 ? (
              <tr><td colSpan={5} className="px-4 py-8 text-center text-slate-600">Kayıt bulunamadı</td></tr>
            ) : (
              pagesList?.map((p) => (
                <tr key={p.id} className="hover:bg-amber-500/[0.02] transition-colors">
                  <td className="px-4 py-3 text-amber-400 text-sm font-medium">/{p.slug}</td>
                  <td className="px-4 py-3 text-white text-sm">{p.title}</td>
                  <td className="px-4 py-3"><span className={`px-2 py-0.5 rounded-full text-xs font-medium ${p.isPublished ? "bg-emerald-500/10 text-emerald-400" : "bg-slate-500/10 text-slate-400"}`}>{p.isPublished ? "Yayında" : "Taslak"}</span></td>
                  <td className="px-4 py-3 text-slate-500 text-xs">{new Date(p.updatedAt).toLocaleDateString("tr-TR")}</td>
                  <td className="px-4 py-3">
                    <button onClick={() => { if (confirm("Silmek istediğinize emin misiniz?")) deletePage.mutate({ id: p.id }); }} className="p-1.5 rounded-lg hover:bg-red-500/10 text-slate-400 hover:text-red-400 transition-colors">
                      <Trash2 className="w-4 h-4" />
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
