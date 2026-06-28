import { z } from "zod";
import { eq, and, desc, asc, sql, count, gte, isNull } from "drizzle-orm";
import { createRouter, adminQuery, superAdminQuery } from "./middleware";
import { getDb } from "./queries/connection";
import {
  localUsers, roles, shippers, drivers, loads,
  escrowTransactions, kycDocuments, subscriptions,
  disputes, tickets, siteSettings, cmsPages,
  coupons, activityLogs, ipBans,
  softDeletes, systemLogs, siteStatistics, externalSources,
  aiProviders, queuedJobs,
} from "@db/schema";

// ============================================================
// DASHBOARD MODULE
// ============================================================
const dashboardRouter = createRouter({
  stats: adminQuery.query(async () => {
    const db = getDb();
    const [
      shipperCount, driverCount, activeLoadCount,
      completedShipmentCount, escrowTotal, premiumCount,
      disputeCount, ticketCount,
    ] = await Promise.all([
      db.select({ count: count() }).from(shippers),
      db.select({ count: count() }).from(drivers),
      db.select({ count: count() }).from(loads).where(eq(loads.status, "in_transit")),
      db.select({ count: count() }).from(loads).where(eq(loads.status, "completed")),
      db.select({ total: sql<number>`COALESCE(SUM(amount), 0)` }).from(escrowTransactions).where(eq(escrowTransactions.status, "in_escrow")),
      db.select({ count: count() }).from(drivers).where(eq(drivers.isPremium, true)),
      db.select({ count: count() }).from(disputes).where(eq(disputes.status, "open")),
      db.select({ count: count() }).from(tickets).where(eq(tickets.status, "open")),
    ]);

    return {
      activeShippers: shipperCount[0]?.count ?? 0,
      activeDrivers: driverCount[0]?.count ?? 0,
      activeLoads: activeLoadCount[0]?.count ?? 0,
      totalShipments: completedShipmentCount[0]?.count ?? 0,
      escrowTotal: escrowTotal[0]?.total ?? 0,
      premiumDrivers: premiumCount[0]?.count ?? 0,
      openDisputes: disputeCount[0]?.count ?? 0,
      openTickets: ticketCount[0]?.count ?? 0,
    };
  }),

  recentActivity: adminQuery.query(async () => {
    const db = getDb();
    const logs = await db.select()
      .from(activityLogs)
      .orderBy(desc(activityLogs.createdAt))
      .limit(20);
    return logs;
  }),

  financialSummary: adminQuery.query(async () => {
    const db = getDb();
    const thirtyDaysAgo = new Date(Date.now() - 30 * 24 * 60 * 60 * 1000);
    const [commissions, subscriptionsRev] = await Promise.all([
      db.select({
        total: sql<number>`COALESCE(SUM(commission_amount), 0)`,
      }).from(escrowTransactions)
        .where(
          and(
            eq(escrowTransactions.status, "released"),
            gte(escrowTransactions.releasedAt, thirtyDaysAgo)
          )
        ),
      db.select({
        total: sql<number>`COALESCE(SUM(amount), 0)`,
      }).from(subscriptions)
        .where(
          and(
            eq(subscriptions.status, "active"),
            gte(subscriptions.createdAt, thirtyDaysAgo)
          )
        ),
    ]);

    return {
      commissionRevenue: commissions[0]?.total ?? 0,
      subscriptionRevenue: subscriptionsRev[0]?.total ?? 0,
    };
  }),

  monthlyStats: adminQuery.query(async () => {
    const db = getDb();
    const stats = await db.select()
      .from(siteStatistics)
      .orderBy(desc(siteStatistics.date))
      .limit(12);
    return stats.reverse();
  }),
});

// ============================================================
// USERS & KYC MODULE
// ============================================================
const usersRouter = createRouter({
  listShippers: adminQuery
    .input(z.object({
      page: z.number().default(1),
      limit: z.number().default(20),
      search: z.string().optional(),
      status: z.string().optional(),
      kycStatus: z.string().optional(),
      type: z.string().optional(),
    }).optional())
    .query(async ({ input }) => {
      const db = getDb();
      const page = input?.page ?? 1;
      const limit = input?.limit ?? 20;
      const offset = (page - 1) * limit;

      const conditions = [];
      if (input?.search) {
        conditions.push(
          sql`(${shippers.firstName} LIKE ${`%${input.search}%`} OR ${shippers.lastName} LIKE ${`%${input.search}%`} OR ${shippers.companyName} LIKE ${`%${input.search}%`} OR ${shippers.email} LIKE ${`%${input.search}%`})`
        );
      }
      if (input?.status) conditions.push(eq(shippers.status, input.status as any));
      if (input?.kycStatus) conditions.push(eq(shippers.kycStatus, input.kycStatus as any));
      if (input?.type) conditions.push(eq(shippers.type, input.type as any));

      const whereClause = conditions.length > 0 ? and(...conditions) : undefined;

      const [items, totalResult] = await Promise.all([
        db.select().from(shippers).where(whereClause).limit(limit).offset(offset).orderBy(desc(shippers.createdAt)),
        db.select({ count: count() }).from(shippers).where(whereClause),
      ]);

      return { items, total: totalResult[0]?.count ?? 0, page, limit };
    }),

  listDrivers: adminQuery
    .input(z.object({
      page: z.number().default(1),
      limit: z.number().default(20),
      search: z.string().optional(),
      status: z.string().optional(),
      kycStatus: z.string().optional(),
      isPremium: z.boolean().optional(),
    }).optional())
    .query(async ({ input }) => {
      const db = getDb();
      const page = input?.page ?? 1;
      const limit = input?.limit ?? 20;
      const offset = (page - 1) * limit;

      const conditions = [];
      if (input?.search) {
        conditions.push(
          sql`(${drivers.firstName} LIKE ${`%${input.search}%`} OR ${drivers.lastName} LIKE ${`%${input.search}%`} OR ${drivers.email} LIKE ${`%${input.search}%`} OR ${drivers.phone} LIKE ${`%${input.search}%`})`
        );
      }
      if (input?.status) conditions.push(eq(drivers.status, input.status as any));
      if (input?.kycStatus) conditions.push(eq(drivers.kycStatus, input.kycStatus as any));
      if (input?.isPremium !== undefined) conditions.push(eq(drivers.isPremium, input.isPremium));

      const whereClause = conditions.length > 0 ? and(...conditions) : undefined;

      const [items, totalResult] = await Promise.all([
        db.select().from(drivers).where(whereClause).limit(limit).offset(offset).orderBy(desc(drivers.createdAt)),
        db.select({ count: count() }).from(drivers).where(whereClause),
      ]);

      return { items, total: totalResult[0]?.count ?? 0, page, limit };
    }),

  getShipper: adminQuery
    .input(z.object({ id: z.number() }))
    .query(async ({ input }) => {
      const db = getDb();
      const shipper = await db.select().from(shippers).where(eq(shippers.id, input.id)).limit(1);
      return shipper[0] ?? null;
    }),

  getDriver: adminQuery
    .input(z.object({ id: z.number() }))
    .query(async ({ input }) => {
      const db = getDb();
      const driver = await db.select().from(drivers).where(eq(drivers.id, input.id)).limit(1);
      return driver[0] ?? null;
    }),

  updateShipperStatus: adminQuery
    .input(z.object({ id: z.number(), status: z.string() }))
    .mutation(async ({ input }) => {
      const db = getDb();
      await db.update(shippers).set({ status: input.status as any }).where(eq(shippers.id, input.id));
      return { success: true };
    }),

  updateDriverStatus: adminQuery
    .input(z.object({ id: z.number(), status: z.string() }))
    .mutation(async ({ input }) => {
      const db = getDb();
      await db.update(drivers).set({ status: input.status as any }).where(eq(drivers.id, input.id));
      return { success: true };
    }),
});

// ============================================================
// KYC DOCUMENTS MODULE
// ============================================================
const kycRouter = createRouter({
  list: adminQuery
    .input(z.object({
      page: z.number().default(1),
      limit: z.number().default(20),
      status: z.string().optional(),
      entityType: z.string().optional(),
    }).optional())
    .query(async ({ input }) => {
      const db = getDb();
      const page = input?.page ?? 1;
      const limit = input?.limit ?? 20;
      const offset = (page - 1) * limit;

      const conditions = [];
      if (input?.status) conditions.push(eq(kycDocuments.adminStatus, input.status as any));
      if (input?.entityType) conditions.push(eq(kycDocuments.entityType, input.entityType as any));

      const whereClause = conditions.length > 0 ? and(...conditions) : undefined;

      const [items, totalResult] = await Promise.all([
        db.select().from(kycDocuments).where(whereClause).limit(limit).offset(offset).orderBy(desc(kycDocuments.createdAt)),
        db.select({ count: count() }).from(kycDocuments).where(whereClause),
      ]);

      return { items, total: totalResult[0]?.count ?? 0, page, limit };
    }),

  review: adminQuery
    .input(z.object({
      id: z.number(),
      status: z.enum(["approved", "rejected"]),
      note: z.string().optional(),
    }))
    .mutation(async ({ input, ctx }) => {
      const db = getDb();
      await db.update(kycDocuments).set({
        adminStatus: input.status,
        adminNote: input.note ?? null,
        reviewedBy: ctx.localUser?.id ?? ctx.user?.id ?? null,
        reviewedAt: new Date(),
      }).where(eq(kycDocuments.id, input.id));
      return { success: true };
    }),
});

// ============================================================
// LOADS MODULE
// ============================================================
const loadsRouter = createRouter({
  list: adminQuery
    .input(z.object({
      page: z.number().default(1),
      limit: z.number().default(20),
      status: z.string().optional(),
      search: z.string().optional(),
    }).optional())
    .query(async ({ input }) => {
      const db = getDb();
      const page = input?.page ?? 1;
      const limit = input?.limit ?? 20;
      const offset = (page - 1) * limit;

      const conditions = [];
      if (input?.status) conditions.push(eq(loads.status, input.status as any));
      if (input?.search) {
        conditions.push(
          sql`(${loads.title} LIKE ${`%${input.search}%`} OR ${loads.originCity} LIKE ${`%${input.search}%`} OR ${loads.destinationCity} LIKE ${`%${input.search}%`})`
        );
      }

      const whereClause = conditions.length > 0 ? and(...conditions) : undefined;

      const [items, totalResult] = await Promise.all([
        db.select().from(loads).where(whereClause).limit(limit).offset(offset).orderBy(desc(loads.createdAt)),
        db.select({ count: count() }).from(loads).where(whereClause),
      ]);

      return { items, total: totalResult[0]?.count ?? 0, page, limit };
    }),

  updateStatus: adminQuery
    .input(z.object({ id: z.number(), status: z.string() }))
    .mutation(async ({ input }) => {
      const db = getDb();
      await db.update(loads).set({ status: input.status as any }).where(eq(loads.id, input.id));
      return { success: true };
    }),
});

// ============================================================
// FINANCE MODULE
// ============================================================
const financeRouter = createRouter({
  escrowList: adminQuery
    .input(z.object({
      page: z.number().default(1),
      limit: z.number().default(20),
      status: z.string().optional(),
    }).optional())
    .query(async ({ input }) => {
      const db = getDb();
      const page = input?.page ?? 1;
      const limit = input?.limit ?? 20;
      const offset = (page - 1) * limit;

      const where = input?.status ? eq(escrowTransactions.status, input.status as any) : undefined;

      const [items, totalResult] = await Promise.all([
        db.select().from(escrowTransactions).where(where).limit(limit).offset(offset).orderBy(desc(escrowTransactions.createdAt)),
        db.select({ count: count() }).from(escrowTransactions).where(where),
      ]);

      return { items, total: totalResult[0]?.count ?? 0, page, limit };
    }),

  payouts: adminQuery
    .input(z.object({
      page: z.number().default(1),
      limit: z.number().default(20),
    }).optional())
    .query(async ({ input }) => {
      const db = getDb();
      const page = input?.page ?? 1;
      const limit = input?.limit ?? 20;
      const offset = (page - 1) * limit;

      const [items, totalResult] = await Promise.all([
        db.select().from(escrowTransactions)
          .where(eq(escrowTransactions.status, "released"))
          .limit(limit).offset(offset).orderBy(desc(escrowTransactions.releasedAt)),
        db.select({ count: count() }).from(escrowTransactions).where(eq(escrowTransactions.status, "released")),
      ]);

      return { items, total: totalResult[0]?.count ?? 0, page, limit };
    }),
});

// ============================================================
// DISPUTES MODULE
// ============================================================
const disputesRouter = createRouter({
  list: adminQuery
    .input(z.object({
      page: z.number().default(1),
      limit: z.number().default(20),
      status: z.string().optional(),
    }).optional())
    .query(async ({ input }) => {
      const db = getDb();
      const page = input?.page ?? 1;
      const limit = input?.limit ?? 20;
      const offset = (page - 1) * limit;

      const where = input?.status ? eq(disputes.status, input.status as any) : undefined;

      const [items, totalResult] = await Promise.all([
        db.select().from(disputes).where(where).limit(limit).offset(offset).orderBy(desc(disputes.createdAt)),
        db.select({ count: count() }).from(disputes).where(where),
      ]);

      return { items, total: totalResult[0]?.count ?? 0, page, limit };
    }),

  resolve: adminQuery
    .input(z.object({
      id: z.number(),
      decision: z.enum(["refund_shipper", "release_driver", "partial_split"]),
      note: z.string().optional(),
    }))
    .mutation(async ({ input, ctx }) => {
      const db = getDb();
      await db.update(disputes).set({
        status: "resolved",
        adminDecision: input.decision,
        adminNote: input.note ?? null,
        resolvedBy: ctx.localUser?.id ?? ctx.user?.id ?? null,
        resolvedAt: new Date(),
      }).where(eq(disputes.id, input.id));
      return { success: true };
    }),
});

// ============================================================
// TICKETS MODULE
// ============================================================
const ticketsRouter = createRouter({
  list: adminQuery
    .input(z.object({
      page: z.number().default(1),
      limit: z.number().default(20),
      status: z.string().optional(),
      priority: z.string().optional(),
    }).optional())
    .query(async ({ input }) => {
      const db = getDb();
      const page = input?.page ?? 1;
      const limit = input?.limit ?? 20;
      const offset = (page - 1) * limit;

      const conditions = [];
      if (input?.status) conditions.push(eq(tickets.status, input.status as any));
      if (input?.priority) conditions.push(eq(tickets.priority, input.priority as any));

      const whereClause = conditions.length > 0 ? and(...conditions) : undefined;

      const [items, totalResult] = await Promise.all([
        db.select().from(tickets).where(whereClause).limit(limit).offset(offset).orderBy(desc(tickets.createdAt)),
        db.select({ count: count() }).from(tickets).where(whereClause),
      ]);

      return { items, total: totalResult[0]?.count ?? 0, page, limit };
    }),

  updateStatus: adminQuery
    .input(z.object({ id: z.number(), status: z.string() }))
    .mutation(async ({ input }) => {
      const db = getDb();
      await db.update(tickets).set({ status: input.status as any }).where(eq(tickets.id, input.id));
      return { success: true };
    }),
});

// ============================================================
// MARKETING MODULE
// ============================================================
const marketingRouter = createRouter({
  listCoupons: adminQuery
    .input(z.object({
      page: z.number().default(1),
      limit: z.number().default(20),
    }).optional())
    .query(async ({ input }) => {
      const db = getDb();
      const page = input?.page ?? 1;
      const limit = input?.limit ?? 20;
      const offset = (page - 1) * limit;

      const [items, totalResult] = await Promise.all([
        db.select().from(coupons).limit(limit).offset(offset).orderBy(desc(coupons.createdAt)),
        db.select({ count: count() }).from(coupons),
      ]);

      return { items, total: totalResult[0]?.count ?? 0, page, limit };
    }),

  createCoupon: adminQuery
    .input(z.object({
      code: z.string().min(1),
      description: z.string().optional(),
      discountType: z.enum(["percentage", "fixed_amount"]),
      discountValue: z.number().positive(),
      maxUses: z.number().optional(),
      minOrderAmount: z.number().optional(),
      startDate: z.string().optional(),
      endDate: z.string().optional(),
    }))
    .mutation(async ({ input }) => {
      const db = getDb();
      await db.insert(coupons).values({
        code: input.code,
        description: input.description ?? null,
        discountType: input.discountType,
        discountValue: input.discountValue.toString(),
        maxUses: input.maxUses ?? null,
        minOrderAmount: input.minOrderAmount?.toString() ?? null,
        startDate: input.startDate ? new Date(input.startDate) : null,
        endDate: input.endDate ? new Date(input.endDate) : null,
      });
      return { success: true };
    }),
});

// ============================================================
// CMS MODULE
// ============================================================
const cmsRouter = createRouter({
  listPages: adminQuery.query(async () => {
    const db = getDb();
    return db.select().from(cmsPages).orderBy(asc(cmsPages.sortOrder));
  }),

  getPage: adminQuery
    .input(z.object({ id: z.number() }))
    .query(async ({ input }) => {
      const db = getDb();
      const page = await db.select().from(cmsPages).where(eq(cmsPages.id, input.id)).limit(1);
      return page[0] ?? null;
    }),

  createPage: adminQuery
    .input(z.object({
      slug: z.string().min(1),
      title: z.string().min(1),
      content: z.string().min(1),
      metaTitle: z.string().optional(),
      metaDescription: z.string().optional(),
    }))
    .mutation(async ({ input }) => {
      const db = getDb();
      await db.insert(cmsPages).values({
        slug: input.slug,
        title: input.title,
        content: input.content,
        metaTitle: input.metaTitle ?? null,
        metaDescription: input.metaDescription ?? null,
      });
      return { success: true };
    }),

  updatePage: adminQuery
    .input(z.object({
      id: z.number(),
      title: z.string().optional(),
      content: z.string().optional(),
      metaTitle: z.string().optional(),
      metaDescription: z.string().optional(),
      isPublished: z.boolean().optional(),
    }))
    .mutation(async ({ input }) => {
      const db = getDb();
      const { id, ...data } = input;
      await db.update(cmsPages).set(data).where(eq(cmsPages.id, id));
      return { success: true };
    }),

  deletePage: adminQuery
    .input(z.object({ id: z.number() }))
    .mutation(async ({ input }) => {
      const db = getDb();
      await db.delete(cmsPages).where(eq(cmsPages.id, input.id));
      return { success: true };
    }),
});

// ============================================================
// SETTINGS MODULE
// ============================================================
const settingsRouter = createRouter({
  list: adminQuery.query(async () => {
    const db = getDb();
    const allSettings = await db.select().from(siteSettings).orderBy(asc(siteSettings.group));
    return allSettings;
  }),

  getByGroup: adminQuery
    .input(z.object({ group: z.string() }))
    .query(async ({ input }) => {
      const db = getDb();
      return db.select().from(siteSettings).where(eq(siteSettings.group, input.group));
    }),

  update: adminQuery
    .input(z.object({
      key: z.string(),
      value: z.string(),
    }))
    .mutation(async ({ input }) => {
      const db = getDb();
      await db.update(siteSettings)
        .set({ value: input.value })
        .where(eq(siteSettings.key, input.key));
      return { success: true };
    }),

  bulkUpdate: adminQuery
    .input(z.array(z.object({ key: z.string(), value: z.string() })))
    .mutation(async ({ input }) => {
      const db = getDb();
      for (const item of input) {
        await db.update(siteSettings)
          .set({ value: item.value })
          .where(eq(siteSettings.key, item.key));
      }
      return { success: true };
    }),
});

// ============================================================
// PERSONNEL & ROLES MODULE
// ============================================================
const personnelRouter = createRouter({
  listRoles: adminQuery.query(async () => {
    const db = getDb();
    return db.select().from(roles).orderBy(asc(roles.name));
  }),

  createRole: superAdminQuery
    .input(z.object({
      name: z.string().min(1),
      displayName: z.string().min(1),
      description: z.string().optional(),
    }))
    .mutation(async ({ input }) => {
      const db = getDb();
      await db.insert(roles).values({
        name: input.name,
        displayName: input.displayName,
        description: input.description ?? null,
      });
      return { success: true };
    }),

  listPersonnel: adminQuery.query(async () => {
    const db = getDb();
    return db.select({
      id: localUsers.id,
      username: localUsers.username,
      email: localUsers.email,
      displayName: localUsers.displayName,
      status: localUsers.status,
      lastLoginAt: localUsers.lastLoginAt,
      createdAt: localUsers.createdAt,
    }).from(localUsers).orderBy(desc(localUsers.createdAt));
  }),
});

// ============================================================
// SECURITY MODULE
// ============================================================
const securityRouter = createRouter({
  listIpBans: adminQuery
    .input(z.object({
      page: z.number().default(1),
      limit: z.number().default(20),
    }).optional())
    .query(async ({ input }) => {
      const db = getDb();
      const page = input?.page ?? 1;
      const limit = input?.limit ?? 20;
      const offset = (page - 1) * limit;

      const [items, totalResult] = await Promise.all([
        db.select().from(ipBans).limit(limit).offset(offset).orderBy(desc(ipBans.createdAt)),
        db.select({ count: count() }).from(ipBans),
      ]);

      return { items, total: totalResult[0]?.count ?? 0, page, limit };
    }),

  banIp: adminQuery
    .input(z.object({
      ipAddress: z.string().min(1),
      reason: z.string().optional(),
      isPermanent: z.boolean().default(false),
      expiresAt: z.string().optional(),
    }))
    .mutation(async ({ input, ctx }) => {
      const db = getDb();
      await db.insert(ipBans).values({
        ipAddress: input.ipAddress,
        reason: input.reason ?? null,
        isPermanent: input.isPermanent,
        expiresAt: input.expiresAt ? new Date(input.expiresAt) : null,
        bannedBy: ctx.localUser?.id ?? ctx.user?.id ?? null,
      });
      return { success: true };
    }),

  unbanIp: adminQuery
    .input(z.object({ id: z.number() }))
    .mutation(async ({ input }) => {
      const db = getDb();
      await db.delete(ipBans).where(eq(ipBans.id, input.id));
      return { success: true };
    }),
});

// ============================================================
// SYSTEM HEALTH MODULE
// ============================================================
const healthRouter = createRouter({
  logs: adminQuery
    .input(z.object({
      level: z.string().optional(),
      limit: z.number().default(50),
    }).optional())
    .query(async ({ input }) => {
      const db = getDb();
      const where = input?.level ? eq(systemLogs.level, input.level as any) : undefined;
      return db.select().from(systemLogs).where(where).limit(input?.limit ?? 50).orderBy(desc(systemLogs.createdAt));
    }),

  queues: adminQuery
    .input(z.object({
      queue: z.string().optional(),
      status: z.string().optional(),
    }).optional())
    .query(async ({ input }) => {
      const db = getDb();
      const conditions = [];
      if (input?.queue) conditions.push(eq(queuedJobs.queue, input.queue));
      if (input?.status) conditions.push(eq(queuedJobs.status, input.status as any));
      const where = conditions.length > 0 ? and(...conditions) : undefined;
      return db.select().from(queuedJobs).where(where).orderBy(desc(queuedJobs.createdAt));
    }),
});

// ============================================================
// ACTIVITY & RECOVERY MODULE
// ============================================================
const recoveryRouter = createRouter({
  activityLogs: adminQuery
    .input(z.object({
      module: z.string().optional(),
      limit: z.number().default(50),
    }).optional())
    .query(async ({ input }) => {
      const db = getDb();
      const where = input?.module ? eq(activityLogs.module, input.module) : undefined;
      return db.select().from(activityLogs)
        .where(where)
        .limit(input?.limit ?? 50)
        .orderBy(desc(activityLogs.createdAt));
    }),

  trash: adminQuery
    .input(z.object({
      page: z.number().default(1),
      limit: z.number().default(20),
    }).optional())
    .query(async ({ input }) => {
      const db = getDb();
      const page = input?.page ?? 1;
      const limit = input?.limit ?? 20;
      const offset = (page - 1) * limit;

      const [items, totalResult] = await Promise.all([
        db.select().from(softDeletes)
          .where(isNull(softDeletes.restoredAt))
          .limit(limit).offset(offset)
          .orderBy(desc(softDeletes.deletedAt)),
        db.select({ count: count() }).from(softDeletes).where(isNull(softDeletes.restoredAt)),
      ]);

      return { items, total: totalResult[0]?.count ?? 0, page, limit };
    }),

  restore: adminQuery
    .input(z.object({ id: z.number() }))
    .mutation(async ({ input }) => {
      const db = getDb();
      await db.update(softDeletes)
        .set({ restoredAt: new Date() })
        .where(eq(softDeletes.id, input.id));
      return { success: true };
    }),
});

// ============================================================
// AI & SCRAPING MODULE
// ============================================================
const aiRouter = createRouter({
  providers: adminQuery.query(async () => {
    const db = getDb();
    return db.select().from(aiProviders).orderBy(asc(aiProviders.priority));
  }),

  createProvider: adminQuery
    .input(z.object({
      name: z.string().min(1),
      provider: z.enum(["openai", "gemini", "claude", "custom"]),
      apiKey: z.string().optional(),
      apiUrl: z.string().optional(),
      model: z.string().optional(),
      dailyQuota: z.number().default(1000),
      priority: z.number().default(1),
    }))
    .mutation(async ({ input }) => {
      const db = getDb();
      await db.insert(aiProviders).values({
        name: input.name,
        provider: input.provider,
        apiKey: input.apiKey ?? null,
        apiUrl: input.apiUrl ?? null,
        model: input.model ?? null,
        dailyQuota: input.dailyQuota,
        priority: input.priority,
      });
      return { success: true };
    }),

  toggleProvider: adminQuery
    .input(z.object({ id: z.number() }))
    .mutation(async ({ input }) => {
      const db = getDb();
      const provider = await db.select().from(aiProviders).where(eq(aiProviders.id, input.id)).limit(1);
      if (provider[0]) {
        await db.update(aiProviders)
          .set({ isActive: !provider[0].isActive })
          .where(eq(aiProviders.id, input.id));
      }
      return { success: true };
    }),
});

// ============================================================
// EXTERNAL SOURCES MODULE
// ============================================================
const sourcesRouter = createRouter({
  list: adminQuery.query(async () => {
    const db = getDb();
    return db.select().from(externalSources).orderBy(desc(externalSources.createdAt));
  }),

  create: adminQuery
    .input(z.object({
      name: z.string().min(1),
      sourceType: z.enum(["whatsapp_group", "telegram_channel", "facebook_group", "website", "other"]),
      url: z.string().optional(),
      config: z.string().optional(),
    }))
    .mutation(async ({ input }) => {
      const db = getDb();
      await db.insert(externalSources).values({
        name: input.name,
        sourceType: input.sourceType,
        url: input.url ?? null,
        config: input.config ?? null,
      });
      return { success: true };
    }),

  toggle: adminQuery
    .input(z.object({ id: z.number() }))
    .mutation(async ({ input }) => {
      const db = getDb();
      const source = await db.select().from(externalSources).where(eq(externalSources.id, input.id)).limit(1);
      if (source[0]) {
        await db.update(externalSources)
          .set({ isActive: !source[0].isActive })
          .where(eq(externalSources.id, input.id));
      }
      return { success: true };
    }),
});

// ============================================================
// MAIN ADMIN ROUTER
// ============================================================
export const adminRouter = createRouter({
  dashboard: dashboardRouter,
  users: usersRouter,
  kyc: kycRouter,
  loads: loadsRouter,
  finance: financeRouter,
  disputes: disputesRouter,
  tickets: ticketsRouter,
  marketing: marketingRouter,
  cms: cmsRouter,
  settings: settingsRouter,
  personnel: personnelRouter,
  security: securityRouter,
  health: healthRouter,
  recovery: recoveryRouter,
  ai: aiRouter,
  sources: sourcesRouter,
});
