import { trpc } from "@/providers/trpc";
import { Settings } from "lucide-react";

export default function SettingsPage() {
  const { data: settings, isLoading } = trpc.admin.settings.list.useQuery();
  const utils = trpc.useUtils();
  const updateSetting = trpc.admin.settings.update.useMutation({ onSuccess: () => utils.admin.settings.list.invalidate() });
  

  const groups = settings ? Array.from(new Set(settings.map(s => s.group))) : [];

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-white tracking-tight">Sistem Ayarları</h1>
        <p className="text-slate-500 text-sm mt-1">Platform yapılandırması</p>
      </div>
      {isLoading ? (
        <div className="text-center text-slate-600 py-8">Yükleniyor...</div>
      ) : (
        groups.map(group => (
          <div key={group} className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5">
            <h2 className="text-white font-semibold text-sm mb-4 capitalize flex items-center gap-2"><Settings className="w-4 h-4 text-amber-500" /> {group}</h2>
            <div className="space-y-3">
              {settings?.filter(s => s.group === group).map(setting => (
                <div key={setting.id} className="flex items-center gap-3">
                  <label className="text-slate-400 text-xs w-48 shrink-0">{setting.label}</label>
                  {setting.type === "textarea" ? (
                    <textarea
                      defaultValue={setting.value ?? ""}
                      onBlur={e => updateSetting.mutate({ key: setting.key, value: e.target.value })}
                      rows={3}
                      className="flex-1 px-3 py-2 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-amber-500 resize-y"
                    />
                  ) : setting.type === "boolean" ? (
                    <button
                      onClick={() => updateSetting.mutate({ key: setting.key, value: setting.value === "true" ? "false" : "true" })}
                      className={`w-11 h-6 rounded-full transition-colors ${setting.value === "true" ? "bg-amber-500" : "bg-[#2E2E35]"}`}
                    >
                      <span className={`block w-4 h-4 bg-white rounded-full transition-transform ${setting.value === "true" ? "translate-x-6" : "translate-x-1"}`} />
                    </button>
                  ) : (
                    <input
                      type={setting.type === "number" ? "number" : "text"}
                      defaultValue={setting.value ?? ""}
                      onBlur={e => updateSetting.mutate({ key: setting.key, value: e.target.value })}
                      className="flex-1 h-9 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-amber-500"
                    />
                  )}
                </div>
              ))}
            </div>
          </div>
        ))
      )}
    </div>
  );
}
