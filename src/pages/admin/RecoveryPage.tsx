import { trpc } from "@/providers/trpc";
import { History, RotateCcw } from "lucide-react";
import { useState } from "react";

export default function RecoveryPage() {
  const [page] = useState(1);
  const { data: activityLogs } = trpc.admin.recovery.activityLogs.useQuery({ module: "", limit: 50 });
  const { data: trashData } = trpc.admin.recovery.trash.useQuery({ page, limit: 20 });
  const utils = trpc.useUtils();
  const restore = trpc.admin.recovery.restore.useMutation({ onSuccess: () => utils.admin.recovery.trash.invalidate() });

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-white tracking-tight">Geri Yükleme & Zaman Makinesi</h1>
        <p className="text-slate-500 text-sm mt-1">Aktivite logları ve çöp kutusu</p>
      </div>
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5">
          <h2 className="text-white font-semibold text-sm mb-4 flex items-center gap-2"><History className="w-4 h-4 text-amber-500" /> Son Aktiviteler</h2>
          <div className="space-y-1 max-h-96 overflow-y-auto">
            {activityLogs && activityLogs.length > 0 ? activityLogs.map(log => (
              <div key={log.id} className="flex items-start gap-2 p-2 rounded hover:bg-white/[0.02]">
                <span className="w-2 h-2 rounded-full bg-amber-500 mt-1.5 shrink-0" />
                <div>
                  <p className="text-slate-300 text-xs"><span className="text-amber-400 font-medium">{log.action}</span> — {log.module}</p>
                  <p className="text-slate-600 text-[10px]">{new Date(log.createdAt).toLocaleString("tr-TR")} {log.ipAddress && `• ${log.ipAddress}`}</p>
                </div>
              </div>
            )) : <p className="text-slate-600 text-sm text-center py-4">Aktivite bulunmuyor</p>}
          </div>
        </div>
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5">
          <h2 className="text-white font-semibold text-sm mb-4 flex items-center gap-2"><RotateCcw className="w-4 h-4 text-emerald-500" /> Çöp Kutusu</h2>
          <div className="space-y-2">
            {trashData?.items.length === 0 ? (
              <p className="text-slate-600 text-sm text-center py-4">Çöp kutusu boş</p>
            ) : (
              trashData?.items.map(item => (
                <div key={item.id} className="flex items-center justify-between p-3 bg-[#232328] rounded-lg">
                  <div>
                    <p className="text-white text-sm font-medium">{item.entityType} #{item.entityId}</p>
                    <p className="text-slate-500 text-xs">{new Date(item.deletedAt).toLocaleString("tr-TR")}</p>
                  </div>
                  <button onClick={() => restore.mutate({ id: item.id })} className="p-1.5 rounded-lg hover:bg-emerald-500/10 text-slate-400 hover:text-emerald-400 transition-colors">
                    <RotateCcw className="w-4 h-4" />
                  </button>
                </div>
              ))
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
