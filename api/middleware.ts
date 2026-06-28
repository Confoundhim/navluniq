import { ErrorMessages } from "@contracts/constants";
import { initTRPC, TRPCError } from "@trpc/server";
import superjson from "superjson";
import type { TrpcContext } from "./context";

const t = initTRPC.context<TrpcContext>().create({
  transformer: superjson,
});

export const createRouter = t.router;
export const publicQuery = t.procedure;

const requireAuth = t.middleware(async (opts) => {
  const { ctx, next } = opts;

  if (!ctx.user && !ctx.localUser) {
    throw new TRPCError({
      code: "UNAUTHORIZED",
      message: ErrorMessages.unauthenticated,
    });
  }

  return next({ ctx: { ...ctx, user: ctx.user, localUser: ctx.localUser } });
});

function requireRole(role: string) {
  return t.middleware(async (opts) => {
    const { ctx, next } = opts;

    const userRole = ctx.user?.role || ctx.localUser?.role;

    if (!userRole || (userRole !== role && userRole !== "super_admin")) {
      throw new TRPCError({
        code: "FORBIDDEN",
        message: ErrorMessages.insufficientRole,
      });
    }

    return next({ ctx: { ...ctx, user: ctx.user, localUser: ctx.localUser } });
  });
}

// Any authenticated user (OAuth or local)
export const authedQuery = t.procedure.use(requireAuth);

// Admin only (role = admin or super_admin)
export const adminQuery = authedQuery.use(requireRole("admin"));

// Super admin only
export const superAdminQuery = authedQuery.use(
  t.middleware(async (opts) => {
    const { ctx, next } = opts;
    const userRole = ctx.user?.role || ctx.localUser?.role;

    if (!userRole || userRole !== "super_admin") {
      throw new TRPCError({
        code: "FORBIDDEN",
        message: "Super admin access required",
      });
    }

    return next({ ctx });
  })
);
