import { z } from "zod";
import bcrypt from "bcryptjs";
import jwt from "jsonwebtoken";
import { eq } from "drizzle-orm";
import { createRouter, publicQuery } from "./middleware";
import { getDb } from "./queries/connection";
import { localUsers } from "@db/schema";
import { TRPCError } from "@trpc/server";

const JWT_SECRET = process.env.LOCAL_JWT_SECRET || "navluniq-admin-secret-key-2026";
const OTP_EXPIRY_MINUTES = 10;

// In-memory OTP store (in production, use Redis)
const otpStore = new Map<string, { code: string; expiresAt: Date; attempts: number }>();

function generateOtp(): string {
  return Math.floor(100000 + Math.random() * 900000).toString();
}

function generateToken(userId: number): string {
  return jwt.sign(
    { userId, type: "local" },
    JWT_SECRET,
    { expiresIn: "24h" }
  );
}

export async function verifyLocalToken(token: string) {
  try {
    const decoded = jwt.verify(token, JWT_SECRET, { clockTolerance: 60 }) as {
      userId: number;
      type: string;
    };
    if (decoded.type !== "local") return undefined;

    const db = getDb();
    const user = await db.query.localUsers?.findFirst({
      where: eq(localUsers.id, decoded.userId),
      with: {
        role: true,
      },
    });

    if (!user || user.status !== "active") return undefined;

    return {
      id: user.id,
      username: user.username,
      email: user.email,
      displayName: user.displayName,
      role: user.role?.name || "admin",
      avatar: user.avatar,
    };
  } catch {
    return undefined;
  }
}

export const localAuthRouter = createRouter({
  login: publicQuery
    .input(
      z.object({
        username: z.string().min(1),
        password: z.string().min(1),
      })
    )
    .mutation(async ({ input }) => {
      const db = getDb();
      const user = await db.query.localUsers?.findFirst({
        where: eq(localUsers.username, input.username),
        with: {
          role: true,
        },
      });

      if (!user) {
        throw new TRPCError({
          code: "UNAUTHORIZED",
          message: "Geçersiz kullanıcı adı veya şifre",
        });
      }

      const isValid = await bcrypt.compare(input.password, user.passwordHash);
      if (!isValid) {
        throw new TRPCError({
          code: "UNAUTHORIZED",
          message: "Geçersiz kullanıcı adı veya şifre",
        });
      }

      if (user.status !== "active") {
        throw new TRPCError({
          code: "FORBIDDEN",
          message: "Hesabınız askıya alınmıştır",
        });
      }

      // Generate and send OTP
      const otp = generateOtp();
      const expiresAt = new Date(Date.now() + OTP_EXPIRY_MINUTES * 60 * 1000);
      otpStore.set(user.username, { code: otp, expiresAt, attempts: 0 });

      // TODO: Send OTP via email (SMTP)
      // For now, return OTP in development mode
      console.log(`[OTP] User: ${user.username}, Code: ${otp}`);

      return {
        success: true,
        requiresOtp: true,
        username: user.username,
        message: "Doğrulama kodu e-posta adresinize gönderildi",
        // Remove in production - only for development
        devOtp: otp,
      };
    }),

  verifyOtp: publicQuery
    .input(
      z.object({
        username: z.string().min(1),
        otp: z.string().length(6),
      })
    )
    .mutation(async ({ input }) => {
      const stored = otpStore.get(input.username);
      if (!stored) {
        throw new TRPCError({
          code: "BAD_REQUEST",
          message: "OTP süresi dolmuş veya geçersiz",
        });
      }

      if (new Date() > stored.expiresAt) {
        otpStore.delete(input.username);
        throw new TRPCError({
          code: "BAD_REQUEST",
          message: "Doğrulama kodunun süresi dolmuştur",
        });
      }

      if (stored.attempts >= 3) {
        otpStore.delete(input.username);
        throw new TRPCError({
          code: "BAD_REQUEST",
          message: "Çok fazla deneme yaptınız, lütfen tekrar giriş yapın",
        });
      }

      stored.attempts++;

      if (stored.code !== input.otp) {
        throw new TRPCError({
          code: "BAD_REQUEST",
          message: `Geçersiz doğrulama kodu (${stored.attempts}/3 deneme)`,
        });
      }

      // OTP verified - generate token
      const db = getDb();
      const user = await db.query.localUsers?.findFirst({
        where: eq(localUsers.username, input.username),
        with: {
          role: true,
        },
      });

      if (!user) {
        throw new TRPCError({
          code: "INTERNAL_SERVER_ERROR",
          message: "Kullanıcı bulunamadı",
        });
      }

      const token = generateToken(user.id);

      // Update last login
      await db.update(localUsers).set({
        lastLoginAt: new Date(),
        lastOtpAt: new Date(),
        sessionToken: token,
        sessionExpiry: new Date(Date.now() + 24 * 60 * 60 * 1000),
      }).where(eq(localUsers.id, user.id));

      otpStore.delete(input.username);

      return {
        success: true,
        token,
        user: {
          id: user.id,
          username: user.username,
          email: user.email,
          displayName: user.displayName,
          role: user.role?.name || "admin",
          avatar: user.avatar,
        },
      };
    }),

  me: publicQuery.query(async ({ ctx }) => {
    if (ctx.localUser) {
      return {
        id: ctx.localUser.id,
        username: ctx.localUser.username,
        email: ctx.localUser.email,
        displayName: ctx.localUser.displayName,
        role: ctx.localUser.role,
        avatar: ctx.localUser.avatar,
        isLocal: true,
      };
    }
    return null;
  }),

  logout: publicQuery.mutation(async ({ ctx }) => {
    if (ctx.localUser) {
      const db = getDb();
      await db.update(localUsers)
        .set({ sessionToken: null, sessionExpiry: null })
        .where(eq(localUsers.id, ctx.localUser.id));
    }
    return { success: true };
  }),
});
