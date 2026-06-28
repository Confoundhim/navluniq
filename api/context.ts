import type { FetchCreateContextFnOptions } from "@trpc/server/adapters/fetch";
import type { User } from "@db/schema";
import { authenticateRequest } from "./kimi/auth";
import { verifyLocalToken } from "./local-auth-router";

export type TrpcContext = {
  req: Request;
  resHeaders: Headers;
  user?: User;
  localUser?: {
    id: number;
    username: string;
    email: string;
    displayName: string;
    role: string;
    avatar?: string | null;
  };
};

export async function createContext(
  opts: FetchCreateContextFnOptions,
): Promise<TrpcContext> {
  const ctx: TrpcContext = { req: opts.req, resHeaders: opts.resHeaders };

  // Try OAuth authentication
  try {
    ctx.user = await authenticateRequest(opts.req.headers);
  } catch {
    // OAuth auth failed, try local auth
  }

  // Try local admin authentication
  if (!ctx.user) {
    try {
      const token = opts.req.headers.get("x-local-auth-token");
      if (token) {
        ctx.localUser = await verifyLocalToken(token);
      }
    } catch {
      // Local auth also failed
    }
  }

  return ctx;
}
