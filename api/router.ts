import { authRouter } from "./auth-router";
import { localAuthRouter } from "./local-auth-router";
import { adminRouter } from "./admin-router";
import { createRouter, publicQuery } from "./middleware";

export const appRouter = createRouter({
  ping: publicQuery.query(() => ({ ok: true, ts: Date.now() })),
  auth: authRouter,
  localAuth: localAuthRouter,
  admin: adminRouter,
});

export type AppRouter = typeof appRouter;
