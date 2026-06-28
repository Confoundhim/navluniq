import { trpc } from "@/providers/trpc";
import { useCallback, useMemo } from "react";

export function useLocalAuth() {
  const utils = trpc.useUtils();

  const {
    data: localUser,
    isLoading,
    error,
  } = trpc.localAuth.me.useQuery(undefined, {
    staleTime: 1000 * 60 * 5,
    retry: false,
  });

  const logoutMutation = trpc.localAuth.logout.useMutation({
    onSuccess: async () => {
      localStorage.removeItem("admin_token");
      await utils.invalidate();
      window.location.reload();
    },
  });

  const logout = useCallback(() => {
    logoutMutation.mutate();
  }, [logoutMutation]);

  return useMemo(
    () => ({
      user: localUser ?? null,
      isAuthenticated: !!localUser,
      isLoading: isLoading || logoutMutation.isPending,
      isAdmin: localUser?.role === "admin" || localUser?.role === "super_admin",
      isSuperAdmin: localUser?.role === "super_admin",
      error,
      logout,
    }),
    [localUser, isLoading, logoutMutation.isPending, error, logout],
  );
}
