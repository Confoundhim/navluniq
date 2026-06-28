import { trpc } from "@/providers/trpc";
import { UserCog, Shield } from "lucide-react";

export default function PersonnelPage() {
  const { data: roles } = trpc.admin.personnel.listRoles.useQuery();
  const { data: personnel } = trpc.admin.personnel.listPersonnel.useQuery();

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-white tracking-tight">Personel & Roller</h1>
        <p className="text-slate-500 text-sm mt-1">Rol ve personel yönetimi</p>
      </div>
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5">
          <h2 className="text-white font-semibold text-sm mb-4 flex items-center gap-2"><Shield className="w-4 h-4 text-amber-500" /> Roller</h2>
          <div className="space-y-2">
            {roles?.map(role => (
              <div key={role.id} className="flex items-center justify-between p-3 bg-[#232328] rounded-lg">
                <div>
                  <p className="text-white text-sm font-medium">{role.displayName}</p>
                  <p className="text-slate-500 text-xs">{role.name}</p>
                </div>
                {role.isSystem && <span className="px-2 py-0.5 rounded-full text-xs font-medium bg-blue-500/10 text-blue-400">Sistem</span>}
              </div>
            ))}
          </div>
        </div>
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5">
          <h2 className="text-white font-semibold text-sm mb-4 flex items-center gap-2"><UserCog className="w-4 h-4 text-amber-500" /> Personel</h2>
          <div className="space-y-2">
            {personnel?.map(p => (
              <div key={p.id} className="flex items-center justify-between p-3 bg-[#232328] rounded-lg">
                <div>
                  <p className="text-white text-sm font-medium">{p.displayName}</p>
                  <p className="text-slate-500 text-xs">{p.email}</p>
                </div>
                <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${p.status === "active" ? "bg-emerald-500/10 text-emerald-400" : "bg-red-500/10 text-red-400"}`}>{p.status}</span>
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}
