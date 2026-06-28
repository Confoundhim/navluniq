import { trpc } from "@/providers/trpc";
import { HeartPulse, Activity } from "lucide-react";
import { useState } from "react";

export default function HealthPage() {
  const [level, setLevel] = useState("");
  const { data: logs } = trpc.admin.health.logs.useQuery({ level: level || undefined, limit: 50 });
  const { data: queues } = trpc.admin.health.queues.useQuery();

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-white tracking-tight">Sistem Sağlığı</h1>
        <p className="text-slate-500 text-sm mt-1">Sunucu durumu, kuyruklar ve loglar</p>
      </div>
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5">
          <h2 className="text-white font-semibold text-sm mb-4 flex items-center gap-2"><Activity className="w-4 h-4 text-emerald-500" /> Kuyruk Durumu</h2>
          <div className="space-y-2">
            {queues && queues.length > 0 ? queues.map(q => (
              <div key={q.id} className="flex items-center justify-between p-3 bg-[#232328] rounded-lg">
                <div>
                  <p className="text-white text-sm font-medium">{q.jobType}</p>
                  <p className="text-slate-500 text-xs">{q.queue}</p>
                </div>
                <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${q.status === "completed" ? "bg-emerald-500/10 text-emerald-400" : q.status === "failed" ? "bg-red-500/10 text-red-400" : q.status === "processing" ? "bg-blue-500/10 text-blue-400" : "bg-yellow-500/10 text-yellow-400"}`}>{q.status}</span>
              </div>
            )) : <p className="text-slate-600 text-sm text-center py-4">Kuyruk boş</p>}
          </div>
        </div>
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5">
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-white font-semibold text-sm flex items-center gap-2"><HeartPulse className="w-4 h-4 text-amber-500" /> Sistem Logları</h2>
            <div className="flex items-center gap-1">
              {["", "info", "warning", "error", "critical"].map(l => (
                <button key={l} onClick={() => setLevel(l)} className={`px-2 py-0.5 rounded text-xs transition-colors ${level === l ? "bg-amber-500 text-[#0F0F12]" : "bg-[#232328] text-slate-500 hover:text-slate-300"}`}>{l || "Tümü"}</button>
              ))}
            </div>
          </div>
          <div className="space-y-1 max-h-96 overflow-y-auto">
            {logs && logs.length > 0 ? logs.map(log => (
              <div key={log.id} className="flex items-start gap-2 p-2 rounded hover:bg-white/[0.02]">
                <span className={`w-2 h-2 rounded-full mt-1.5 shrink-0 ${log.level === "critical" || log.level === "error" ? "bg-red-500" : log.level === "warning" ? "bg-yellow-500" : "bg-emerald-500"}`} />
                <div className="min-w-0">
                  <p className="text-slate-300 text-xs truncate">{log.message}</p>
                  <p className="text-slate-600 text-[10px]">{new Date(log.createdAt).toLocaleString("tr-TR")}</p>
                </div>
              </div>
            )) : <p className="text-slate-600 text-sm text-center py-4">Log bulunmuyor</p>}
          </div>
        </div>
      </div>
    </div>
  );
}
