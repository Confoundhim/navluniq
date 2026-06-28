import { trpc } from "@/providers/trpc";
import { BrainCircuit, Power, Plus } from "lucide-react";
import { useState } from "react";

export default function AiPage() {
  const { data: providers } = trpc.admin.ai.providers.useQuery();
  const utils = trpc.useUtils();
  const toggle = trpc.admin.ai.toggleProvider.useMutation({ onSuccess: () => utils.admin.ai.providers.invalidate() });
  const create = trpc.admin.ai.createProvider.useMutation({ onSuccess: () => { utils.admin.ai.providers.invalidate(); setShowForm(false); } });
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({ name: "", provider: "openai" as const, apiKey: "", model: "", dailyQuota: 1000 });

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-white tracking-tight">Yapay Zeka Merkezi</h1>
          <p className="text-slate-500 text-sm mt-1">AI API sağlayıcıları ve yönetimi</p>
        </div>
        <button onClick={() => setShowForm(!showForm)} className="flex items-center gap-2 px-4 py-2 bg-amber-500 hover:bg-amber-400 text-[#0F0F12] font-semibold rounded-lg text-sm transition-colors">
          <Plus className="w-4 h-4" /> Sağlayıcı Ekle
        </button>
      </div>
      {showForm && (
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5 space-y-3">
          <h3 className="text-white font-semibold text-sm">Yeni AI Sağlayıcı</h3>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
            <input type="text" value={form.name} onChange={e => setForm({...form, name: e.target.value})} placeholder="İsim" className="h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-amber-500" />
            <select value={form.provider} onChange={e => setForm({...form, provider: e.target.value as any})} className="h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-amber-500">
              <option value="openai">OpenAI</option>
              <option value="gemini">Gemini</option>
              <option value="claude">Claude</option>
            </select>
            <input type="text" value={form.model} onChange={e => setForm({...form, model: e.target.value})} placeholder="Model (gpt-4)" className="h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-amber-500" />
          </div>
          <div className="flex items-center gap-2">
            <button onClick={() => create.mutate(form)} disabled={!form.name} className="px-4 py-2 bg-amber-500 hover:bg-amber-400 text-[#0F0F12] font-semibold rounded-lg text-sm transition-colors disabled:opacity-50">Ekle</button>
            <button onClick={() => setShowForm(false)} className="px-4 py-2 bg-[#232328] text-slate-400 hover:text-white rounded-lg text-sm transition-colors">İptal</button>
          </div>
        </div>
      )}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {providers?.map(p => (
          <div key={p.id} className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5 hover:border-[#3E3E45] transition-colors">
            <div className="flex items-center justify-between mb-3">
              <div className="flex items-center gap-2">
                <BrainCircuit className="w-5 h-5 text-amber-500" />
                <h3 className="text-white font-semibold text-sm">{p.name}</h3>
              </div>
              <button onClick={() => toggle.mutate({ id: p.id })} className={`p-1.5 rounded-lg transition-colors ${p.isActive ? "bg-emerald-500/10 text-emerald-400" : "bg-red-500/10 text-red-400"}`}>
                <Power className="w-4 h-4" />
              </button>
            </div>
            <div className="space-y-1">
              <p className="text-slate-500 text-xs">Sağlayıcı: <span className="text-slate-300">{p.provider}</span></p>
              <p className="text-slate-500 text-xs">Model: <span className="text-slate-300">{p.model || "—"}</span></p>
              <p className="text-slate-500 text-xs">Kota: <span className="text-slate-300">{p.usedQuota} / {p.dailyQuota}</span></p>
              <p className="text-slate-500 text-xs">Maliyet: <span className="text-slate-300">${p.costPerRequest}/istek</span></p>
              <div className="w-full h-1.5 bg-[#232328] rounded-full mt-2 overflow-hidden">
                <div className="h-full bg-amber-500 rounded-full transition-all" style={{ width: `${Math.min(100, (p.usedQuota / p.dailyQuota) * 100)}%` }} />
              </div>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
