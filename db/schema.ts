import {
  mysqlTable,
  mysqlEnum,
  serial,
  varchar,
  text,
  timestamp,
  int,
  bigint,
  decimal,
  boolean,
  date,
} from "drizzle-orm/mysql-core";

// ============================================================
// 1. USERS (OAuth) - Genişletilmiş
// ============================================================
export const users = mysqlTable("users", {
  id: serial("id").primaryKey(),
  unionId: varchar("unionId", { length: 255 }).notNull().unique(),
  name: varchar("name", { length: 255 }),
  email: varchar("email", { length: 320 }),
  avatar: text("avatar"),
  role: mysqlEnum("role", ["user", "admin", "super_admin"]).default("user").notNull(),
  status: mysqlEnum("status", ["active", "banned", "suspended"]).default("active").notNull(),
  createdAt: timestamp("createdAt").defaultNow().notNull(),
  updatedAt: timestamp("updatedAt").defaultNow().notNull().$onUpdate(() => new Date()),
  lastSignInAt: timestamp("lastSignInAt").defaultNow().notNull(),
});

export type User = typeof users.$inferSelect;
export type InsertUser = typeof users.$inferInsert;

// ============================================================
// 2. LOCAL USERS - Admin/Personel Local Auth
// ============================================================
export const localUsers = mysqlTable("local_users", {
  id: serial("id").primaryKey(),
  username: varchar("username", { length: 100 }).notNull().unique(),
  email: varchar("email", { length: 320 }).notNull().unique(),
  passwordHash: varchar("password_hash", { length: 255 }).notNull(),
  displayName: varchar("display_name", { length: 255 }).notNull(),
  avatar: text("avatar"),
  roleId: bigint("role_id", { mode: "number", unsigned: true }).references(() => roles.id),
  status: mysqlEnum("status", ["active", "inactive", "suspended"]).default("active").notNull(),
  otpSecret: varchar("otp_secret", { length: 255 }),
  otpEnabled: boolean("otp_enabled").default(true).notNull(),
  lastLoginAt: timestamp("last_login_at"),
  lastOtpAt: timestamp("last_otp_at"),
  sessionToken: varchar("session_token", { length: 512 }),
  sessionExpiry: timestamp("session_expiry"),
  createdAt: timestamp("created_at").defaultNow().notNull(),
  updatedAt: timestamp("updated_at").defaultNow().notNull().$onUpdate(() => new Date()),
});

export type LocalUser = typeof localUsers.$inferSelect;
export type InsertLocalUser = typeof localUsers.$inferInsert;

// ============================================================
// 3. ROLES
// ============================================================
export const roles = mysqlTable("roles", {
  id: serial("id").primaryKey(),
  name: varchar("name", { length: 100 }).notNull().unique(),
  displayName: varchar("display_name", { length: 255 }).notNull(),
  description: text("description"),
  isSystem: boolean("is_system").default(false).notNull(),
  createdAt: timestamp("created_at").defaultNow().notNull(),
});

export type Role = typeof roles.$inferSelect;

// ============================================================
// 4. PERMISSIONS
// ============================================================
export const permissions = mysqlTable("permissions", {
  id: serial("id").primaryKey(),
  name: varchar("name", { length: 100 }).notNull().unique(),
  module: varchar("module", { length: 50 }).notNull(),
  action: varchar("action", { length: 50 }).notNull(),
  description: text("description"),
  createdAt: timestamp("created_at").defaultNow().notNull(),
});

export type Permission = typeof permissions.$inferSelect;

// ============================================================
// 5. ROLE PERMISSIONS (Junction)
// ============================================================
export const rolePermissions = mysqlTable("role_permissions", {
  id: serial("id").primaryKey(),
  roleId: bigint("role_id", { mode: "number", unsigned: true }).notNull().references(() => roles.id, { onDelete: "cascade" }),
  permissionId: bigint("permission_id", { mode: "number", unsigned: true }).notNull().references(() => permissions.id, { onDelete: "cascade" }),
  createdAt: timestamp("created_at").defaultNow().notNull(),
});

// ============================================================
// 6. SHIPPERS (Yük Sahipleri)
// ============================================================
export const shippers = mysqlTable("shippers", {
  id: serial("id").primaryKey(),
  userId: bigint("user_id", { mode: "number", unsigned: true }).references(() => users.id),
  type: mysqlEnum("type", ["individual", "corporate"]).notNull(),
  firstName: varchar("first_name", { length: 100 }),
  lastName: varchar("last_name", { length: 100 }),
  companyName: varchar("company_name", { length: 255 }),
  taxNumber: varchar("tax_number", { length: 50 }),
  taxOffice: varchar("tax_office", { length: 100 }),
  tcNumber: varchar("tc_number", { length: 11 }),
  email: varchar("email", { length: 320 }).notNull(),
  phone: varchar("phone", { length: 20 }).notNull(),
  verified: boolean("verified").default(false).notNull(),
  kycStatus: mysqlEnum("kyc_status", ["pending", "approved", "rejected"]).default("pending").notNull(),
  kycVerifiedAt: timestamp("kyc_verified_at"),
  totalShipments: int("total_shipments").default(0).notNull(),
  rating: decimal("rating", { precision: 2, scale: 1 }).default("0.0").notNull(),
  status: mysqlEnum("status", ["active", "inactive", "banned"]).default("active").notNull(),
  createdAt: timestamp("created_at").defaultNow().notNull(),
  updatedAt: timestamp("updated_at").defaultNow().notNull().$onUpdate(() => new Date()),
});

export type Shipper = typeof shippers.$inferSelect;

// ============================================================
// 7. DRIVERS (Şoförler)
// ============================================================
export const drivers = mysqlTable("drivers", {
  id: serial("id").primaryKey(),
  userId: bigint("user_id", { mode: "number", unsigned: true }).references(() => users.id),
  firstName: varchar("first_name", { length: 100 }).notNull(),
  lastName: varchar("last_name", { length: 100 }).notNull(),
  email: varchar("email", { length: 320 }).notNull(),
  phone: varchar("phone", { length: 20 }).notNull(),
  licenseNumber: varchar("license_number", { length: 50 }),
  srcCertificate: varchar("src_certificate", { length: 50 }),
  psychotechnical: varchar("psychotechnical", { length: 50 }),
  kCertificate: varchar("k_certificate", { length: 50 }),
  profilePhoto: text("profile_photo"),
  selfieWithVehicle: text("selfie_with_vehicle"),
  verified: boolean("verified").default(false).notNull(),
  kycStatus: mysqlEnum("kyc_status", ["pending", "approved", "rejected"]).default("pending").notNull(),
  kycVerifiedAt: timestamp("kyc_verified_at"),
  totalDeliveries: int("total_deliveries").default(0).notNull(),
  rating: decimal("rating", { precision: 2, scale: 1 }).default("0.0").notNull(),
  isPremium: boolean("is_premium").default(false).notNull(),
  premiumExpiry: timestamp("premium_expiry"),
  status: mysqlEnum("status", ["active", "inactive", "banned"]).default("active").notNull(),
  iban: varchar("iban", { length: 26 }),
  bankAccountName: varchar("bank_account_name", { length: 255 }),
  createdAt: timestamp("created_at").defaultNow().notNull(),
  updatedAt: timestamp("updated_at").defaultNow().notNull().$onUpdate(() => new Date()),
});

export type Driver = typeof drivers.$inferSelect;

// ============================================================
// 8. VEHICLES (Araçlar)
// ============================================================
export const vehicles = mysqlTable("vehicles", {
  id: serial("id").primaryKey(),
  driverId: bigint("driver_id", { mode: "number", unsigned: true }).notNull().references(() => drivers.id, { onDelete: "cascade" }),
  type: mysqlEnum("type", [
    "automobile", "minivan", "mid_panelvan", "long_panelvan",
    "pickup", "six_wheel", "eight_wheel", "ten_wheel",
    "forty_foot", "truck"
  ]).notNull(),
  plate: varchar("plate", { length: 20 }).notNull(),
  brand: varchar("brand", { length: 100 }),
  model: varchar("model", { length: 100 }),
  year: int("year"),
  capacityKg: int("capacity_kg"),
  photos: text("photos"),
  registrationDoc: text("registration_doc"),
  insuranceDoc: text("insurance_doc"),
  insuranceExpiry: date("insurance_expiry"),
  isActive: boolean("is_active").default(true).notNull(),
  createdAt: timestamp("created_at").defaultNow().notNull(),
});

// ============================================================
// 9. LOADS (Yük İlanları)
// ============================================================
export const loads = mysqlTable("loads", {
  id: serial("id").primaryKey(),
  shipperId: bigint("shipper_id", { mode: "number", unsigned: true }).notNull().references(() => shippers.id, { onDelete: "cascade" }),
  title: varchar("title", { length: 255 }).notNull(),
  description: text("description"),
  originCity: varchar("origin_city", { length: 100 }).notNull(),
  originDistrict: varchar("origin_district", { length: 100 }),
  originAddress: text("origin_address"),
  destinationCity: varchar("destination_city", { length: 100 }).notNull(),
  destinationDistrict: varchar("destination_district", { length: 100 }),
  destinationAddress: text("destination_address"),
  loadType: mysqlEnum("load_type", [
    "palletized", "bulk", "household", "container",
    "heavy_machinery", "hazardous", "refrigerated", "other"
  ]).notNull(),
  weightKg: int("weight_kg"),
  volumeM3: decimal("volume_m3", { precision: 8, scale: 2 }),
  palletCount: int("pallet_count"),
  vehicleType: mysqlEnum("vehicle_type", [
    "automobile", "minivan", "mid_panelvan", "long_panelvan",
    "pickup", "six_wheel", "eight_wheel", "ten_wheel",
    "forty_foot", "truck"
  ]),
  loadingDate: date("loading_date").notNull(),
  deliveryDate: date("delivery_date"),
  budget: decimal("budget", { precision: 12, scale: 2 }),
  eWaybill: varchar("e_waybill", { length: 100 }),
  status: mysqlEnum("status", [
    "draft", "published", "bidding", "assigned",
    "in_transit", "delivered", "completed", "cancelled"
  ]).default("draft").notNull(),
  isUrgent: boolean("is_urgent").default(false).notNull(),
  viewCount: int("view_count").default(0).notNull(),
  bidCount: int("bid_count").default(0).notNull(),
  createdAt: timestamp("created_at").defaultNow().notNull(),
  updatedAt: timestamp("updated_at").defaultNow().notNull().$onUpdate(() => new Date()),
});

export type Load = typeof loads.$inferSelect;

// ============================================================
// 10. BIDS (Teklifler)
// ============================================================
export const bids = mysqlTable("bids", {
  id: serial("id").primaryKey(),
  loadId: bigint("load_id", { mode: "number", unsigned: true }).notNull().references(() => loads.id, { onDelete: "cascade" }),
  driverId: bigint("driver_id", { mode: "number", unsigned: true }).notNull().references(() => drivers.id, { onDelete: "cascade" }),
  vehicleId: bigint("vehicle_id", { mode: "number", unsigned: true }).references(() => vehicles.id),
  amount: decimal("amount", { precision: 12, scale: 2 }).notNull(),
  estimatedDeliveryDays: int("estimated_delivery_days"),
  message: text("message"),
  status: mysqlEnum("status", ["pending", "accepted", "rejected", "withdrawn"]).default("pending").notNull(),
  createdAt: timestamp("created_at").defaultNow().notNull(),
  updatedAt: timestamp("updated_at").defaultNow().notNull().$onUpdate(() => new Date()),
});

// ============================================================
// 11. ESCROW TRANSACTIONS (Havuz Ödemeleri)
// ============================================================
export const escrowTransactions = mysqlTable("escrow_transactions", {
  id: serial("id").primaryKey(),
  loadId: bigint("load_id", { mode: "number", unsigned: true }).notNull().references(() => loads.id),
  shipperId: bigint("shipper_id", { mode: "number", unsigned: true }).notNull().references(() => shippers.id),
  driverId: bigint("driver_id", { mode: "number", unsigned: true }).references(() => drivers.id),
  bidId: bigint("bid_id", { mode: "number", unsigned: true }).references(() => bids.id),
  amount: decimal("amount", { precision: 12, scale: 2 }).notNull(),
  commissionRate: decimal("commission_rate", { precision: 4, scale: 2 }).default("2.50").notNull(),
  commissionAmount: decimal("commission_amount", { precision: 12, scale: 2 }),
  insuranceAmount: decimal("insurance_amount", { precision: 12, scale: 2 }).default("0").notNull(),
  totalAmount: decimal("total_amount", { precision: 12, scale: 2 }).notNull(),
  paytrTransactionId: varchar("paytr_transaction_id", { length: 255 }),
  status: mysqlEnum("status", [
    "pending_payment", "in_escrow", "released",
    "refunded", "disputed", "cancelled"
  ]).default("pending_payment").notNull(),
  paidAt: timestamp("paid_at"),
  releasedAt: timestamp("released_at"),
  refundedAt: timestamp("refunded_at"),
  createdAt: timestamp("created_at").defaultNow().notNull(),
});

// ============================================================
// 12. KYC DOCUMENTS
// ============================================================
export const kycDocuments = mysqlTable("kyc_documents", {
  id: serial("id").primaryKey(),
  entityType: mysqlEnum("entity_type", ["shipper", "driver"]).notNull(),
  entityId: bigint("entity_id", { mode: "number", unsigned: true }).notNull(),
  documentType: mysqlEnum("document_type", [
    "license", "src", "psychotechnical", "k_certificate",
    "vehicle_registration", "insurance", "tax_certificate",
    "id_card", "selfie", "profile_photo", "vehicle_photo", "other"
  ]).notNull(),
  documentUrl: text("document_url").notNull(),
  ocrData: text("ocr_data"),
  aiStatus: mysqlEnum("ai_status", ["pending", "approved", "rejected", "manual_review"]).default("pending").notNull(),
  adminStatus: mysqlEnum("admin_status", ["pending", "approved", "rejected"]).default("pending").notNull(),
  adminNote: text("admin_note"),
  reviewedBy: bigint("reviewed_by", { mode: "number", unsigned: true }).references(() => localUsers.id),
  reviewedAt: timestamp("reviewed_at"),
  expiryDate: date("expiry_date"),
  createdAt: timestamp("created_at").defaultNow().notNull(),
});

// ============================================================
// 13. SUBSCRIPTIONS
// ============================================================
export const subscriptions = mysqlTable("subscriptions", {
  id: serial("id").primaryKey(),
  driverId: bigint("driver_id", { mode: "number", unsigned: true }).notNull().references(() => drivers.id, { onDelete: "cascade" }),
  plan: mysqlEnum("plan", ["free", "premium"]).default("free").notNull(),
  amount: decimal("amount", { precision: 10, scale: 2 }),
  currency: varchar("currency", { length: 3 }).default("TRY").notNull(),
  startDate: date("start_date").notNull(),
  endDate: date("end_date").notNull(),
  paytrTransactionId: varchar("paytr_transaction_id", { length: 255 }),
  autoRenew: boolean("auto_renew").default(true).notNull(),
  status: mysqlEnum("status", ["active", "expired", "cancelled"]).default("active").notNull(),
  cancelledAt: timestamp("cancelled_at"),
  createdAt: timestamp("created_at").defaultNow().notNull(),
});

// ============================================================
// 14. DISPUTES (Uyuşmazlık)
// ============================================================
export const disputes = mysqlTable("disputes", {
  id: serial("id").primaryKey(),
  loadId: bigint("load_id", { mode: "number", unsigned: true }).notNull().references(() => loads.id),
  escrowId: bigint("escrow_id", { mode: "number", unsigned: true }).references(() => escrowTransactions.id),
  shipperId: bigint("shipper_id", { mode: "number", unsigned: true }).notNull().references(() => shippers.id),
  driverId: bigint("driver_id", { mode: "number", unsigned: true }).notNull().references(() => drivers.id),
  initiatedBy: mysqlEnum("initiated_by", ["shipper", "driver"]).notNull(),
  disputeType: mysqlEnum("dispute_type", [
    "damage", "missing", "late_delivery", "payment_issue",
    "fraud", "other"
  ]).notNull(),
  description: text("description").notNull(),
  requestedResolution: mysqlEnum("requested_resolution", ["refund", "partial_refund", "release_payment", "other"]).notNull(),
  shipperStatement: text("shipper_statement"),
  driverStatement: text("driver_statement"),
  adminDecision: mysqlEnum("admin_decision", ["pending", "refund_shipper", "release_driver", "partial_split"]).default("pending").notNull(),
  adminNote: text("admin_note"),
  resolvedBy: bigint("resolved_by", { mode: "number", unsigned: true }).references(() => localUsers.id),
  resolvedAt: timestamp("resolved_at"),
  status: mysqlEnum("status", ["open", "under_review", "resolved", "closed", "escalated"]).default("open").notNull(),
  createdAt: timestamp("created_at").defaultNow().notNull(),
  updatedAt: timestamp("updated_at").defaultNow().notNull().$onUpdate(() => new Date()),
});

// ============================================================
// 15. DISPUTE EVIDENCE
// ============================================================
export const disputeEvidence = mysqlTable("dispute_evidence", {
  id: serial("id").primaryKey(),
  disputeId: bigint("dispute_id", { mode: "number", unsigned: true }).notNull().references(() => disputes.id, { onDelete: "cascade" }),
  submittedBy: mysqlEnum("submitted_by", ["shipper", "driver", "admin"]).notNull(),
  evidenceType: mysqlEnum("evidence_type", ["photo", "document", "location_log", "message"]).notNull(),
  fileUrl: text("file_url"),
  description: text("description"),
  createdAt: timestamp("created_at").defaultNow().notNull(),
});

// ============================================================
// 16. TICKETS (Destek Bileti)
// ============================================================
export const tickets = mysqlTable("tickets", {
  id: serial("id").primaryKey(),
  ticketNumber: varchar("ticket_number", { length: 20 }).notNull().unique(),
  userType: mysqlEnum("user_type", ["shipper", "driver", "guest"]).notNull(),
  userId: bigint("user_id", { mode: "number", unsigned: true }),
  name: varchar("name", { length: 255 }),
  email: varchar("email", { length: 320 }),
  phone: varchar("phone", { length: 20 }),
  category: mysqlEnum("category", [
    "technical_error", "bug", "subscription_pricing", "kyc",
    "payment_escrow", "load_route", "gps_pwa", "whatsapp_telegram",
    "dispute", "invoice", "security", "other"
  ]).notNull(),
  subject: varchar("subject", { length: 255 }).notNull(),
  message: text("message").notNull(),
  priority: mysqlEnum("priority", ["low", "medium", "high", "urgent"]).default("medium").notNull(),
  status: mysqlEnum("status", ["open", "in_progress", "waiting", "resolved", "closed"]).default("open").notNull(),
  assignedTo: bigint("assigned_to", { mode: "number", unsigned: true }).references(() => localUsers.id),
  closedAt: timestamp("closed_at"),
  createdAt: timestamp("created_at").defaultNow().notNull(),
  updatedAt: timestamp("updated_at").defaultNow().notNull().$onUpdate(() => new Date()),
});

// ============================================================
// 17. TICKET MESSAGES
// ============================================================
export const ticketMessages = mysqlTable("ticket_messages", {
  id: serial("id").primaryKey(),
  ticketId: bigint("ticket_id", { mode: "number", unsigned: true }).notNull().references(() => tickets.id, { onDelete: "cascade" }),
  senderType: mysqlEnum("sender_type", ["user", "admin", "system"]).notNull(),
  senderId: bigint("sender_id", { mode: "number", unsigned: true }),
  message: text("message").notNull(),
  isInternal: boolean("is_internal").default(false).notNull(),
  createdAt: timestamp("created_at").defaultNow().notNull(),
});

// ============================================================
// 18. SITE SETTINGS
// ============================================================
export const siteSettings = mysqlTable("site_settings", {
  id: serial("id").primaryKey(),
  key: varchar("key", { length: 100 }).notNull().unique(),
  value: text("value"),
  group: varchar("group", { length: 50 }).notNull(),
  label: varchar("label", { length: 255 }).notNull(),
  type: mysqlEnum("type", ["text", "number", "boolean", "textarea", "json"]).default("text").notNull(),
  isPublic: boolean("is_public").default(false).notNull(),
  createdAt: timestamp("created_at").defaultNow().notNull(),
  updatedAt: timestamp("updated_at").defaultNow().notNull().$onUpdate(() => new Date()),
});

// ============================================================
// 19. CMS PAGES
// ============================================================
export const cmsPages = mysqlTable("cms_pages", {
  id: serial("id").primaryKey(),
  slug: varchar("slug", { length: 100 }).notNull().unique(),
  title: varchar("title", { length: 255 }).notNull(),
  content: text("content").notNull(),
  metaTitle: varchar("meta_title", { length: 255 }),
  metaDescription: text("meta_description"),
  isPublished: boolean("is_published").default(true).notNull(),
  sortOrder: int("sort_order").default(0).notNull(),
  createdAt: timestamp("created_at").defaultNow().notNull(),
  updatedAt: timestamp("updated_at").defaultNow().notNull().$onUpdate(() => new Date()),
});

// ============================================================
// 20. TRANSLATIONS
// ============================================================
export const translations = mysqlTable("translations", {
  id: serial("id").primaryKey(),
  locale: varchar("locale", { length: 10 }).notNull(),
  key: varchar("key", { length: 255 }).notNull(),
  value: text("value").notNull(),
  group: varchar("group", { length: 50 }).default("general").notNull(),
  createdAt: timestamp("created_at").defaultNow().notNull(),
  updatedAt: timestamp("updated_at").defaultNow().notNull().$onUpdate(() => new Date()),
});

// ============================================================
// 21. COUPONS
// ============================================================
export const coupons = mysqlTable("coupons", {
  id: serial("id").primaryKey(),
  code: varchar("code", { length: 50 }).notNull().unique(),
  description: text("description"),
  discountType: mysqlEnum("discount_type", ["percentage", "fixed_amount"]).notNull(),
  discountValue: decimal("discount_value", { precision: 10, scale: 2 }).notNull(),
  maxUses: int("max_uses"),
  usedCount: int("used_count").default(0).notNull(),
  minOrderAmount: decimal("min_order_amount", { precision: 12, scale: 2 }),
  startDate: date("start_date"),
  endDate: date("end_date"),
  status: mysqlEnum("status", ["active", "inactive", "expired"]).default("active").notNull(),
  createdAt: timestamp("created_at").defaultNow().notNull(),
});

// ============================================================
// 22. COUPON USAGE
// ============================================================
export const couponUsage = mysqlTable("coupon_usage", {
  id: serial("id").primaryKey(),
  couponId: bigint("coupon_id", { mode: "number", unsigned: true }).notNull().references(() => coupons.id),
  driverId: bigint("driver_id", { mode: "number", unsigned: true }).notNull().references(() => drivers.id),
  subscriptionId: bigint("subscription_id", { mode: "number", unsigned: true }).references(() => subscriptions.id),
  discountAmount: decimal("discount_amount", { precision: 10, scale: 2 }).notNull(),
  createdAt: timestamp("created_at").defaultNow().notNull(),
});

// ============================================================
// 23. NOTIFICATIONS
// ============================================================
export const notifications = mysqlTable("notifications", {
  id: serial("id").primaryKey(),
  userType: mysqlEnum("user_type", ["shipper", "driver", "admin"]).notNull(),
  userId: bigint("user_id", { mode: "number", unsigned: true }).notNull(),
  title: varchar("title", { length: 255 }).notNull(),
  message: text("message").notNull(),
  type: mysqlEnum("type", ["info", "success", "warning", "error"]).default("info").notNull(),
  module: varchar("module", { length: 50 }),
  entityId: bigint("entity_id", { mode: "number", unsigned: true }),
  isRead: boolean("is_read").default(false).notNull(),
  createdAt: timestamp("created_at").defaultNow().notNull(),
});

// ============================================================
// 24. ADDRESSES
// ============================================================
export const addresses = mysqlTable("addresses", {
  id: serial("id").primaryKey(),
  ownerType: mysqlEnum("owner_type", ["shipper", "driver"]).notNull(),
  ownerId: bigint("owner_id", { mode: "number", unsigned: true }).notNull(),
  label: varchar("label", { length: 100 }).notNull(),
  city: varchar("city", { length: 100 }).notNull(),
  district: varchar("district", { length: 100 }),
  address: text("address").notNull(),
  postalCode: varchar("postal_code", { length: 20 }),
  isDefault: boolean("is_default").default(false).notNull(),
  createdAt: timestamp("created_at").defaultNow().notNull(),
});

// ============================================================
// 25. LOAD RATINGS
// ============================================================
export const loadRatings = mysqlTable("load_ratings", {
  id: serial("id").primaryKey(),
  loadId: bigint("load_id", { mode: "number", unsigned: true }).notNull().references(() => loads.id),
  shipperId: bigint("shipper_id", { mode: "number", unsigned: true }).notNull().references(() => shippers.id),
  driverId: bigint("driver_id", { mode: "number", unsigned: true }).notNull().references(() => drivers.id),
  shipperRating: int("shipper_rating"),
  shipperComment: text("shipper_comment"),
  driverRating: int("driver_rating"),
  driverComment: text("driver_comment"),
  createdAt: timestamp("created_at").defaultNow().notNull(),
});

// ============================================================
// 26. DELIVERY PROOFS (POD)
// ============================================================
export const deliveryProofs = mysqlTable("delivery_proofs", {
  id: serial("id").primaryKey(),
  loadId: bigint("load_id", { mode: "number", unsigned: true }).notNull().references(() => loads.id),
  driverId: bigint("driver_id", { mode: "number", unsigned: true }).notNull().references(() => drivers.id),
  photoUrl: text("photo_url").notNull(),
  eWaybillDoc: text("e_waybill_doc"),
  notes: text("notes"),
  latitude: decimal("latitude", { precision: 10, scale: 8 }),
  longitude: decimal("longitude", { precision: 11, scale: 8 }),
  isApproved: boolean("is_approved").default(false).notNull(),
  approvedBy: bigint("approved_by", { mode: "number", unsigned: true }).references(() => shippers.id),
  approvedAt: timestamp("approved_at"),
  createdAt: timestamp("created_at").defaultNow().notNull(),
});

// ============================================================
// 27. EXTERNAL SOURCES
// ============================================================
export const externalSources = mysqlTable("external_sources", {
  id: serial("id").primaryKey(),
  name: varchar("name", { length: 255 }).notNull(),
  sourceType: mysqlEnum("source_type", ["whatsapp_group", "telegram_channel", "facebook_group", "website", "other"]).notNull(),
  url: text("url"),
  config: text("config"),
  isActive: boolean("is_active").default(true).notNull(),
  lastScrapedAt: timestamp("last_scraped_at"),
  createdAt: timestamp("created_at").defaultNow().notNull(),
});

// ============================================================
// 28. SCRAPED LISTINGS
// ============================================================
export const scrapedListings = mysqlTable("scraped_listings", {
  id: serial("id").primaryKey(),
  sourceId: bigint("source_id", { mode: "number", unsigned: true }).references(() => externalSources.id),
  rawText: text("raw_text").notNull(),
  parsedOrigin: varchar("parsed_origin", { length: 100 }),
  parsedDestination: varchar("parsed_destination", { length: 100 }),
  parsedVehicleType: varchar("parsed_vehicle_type", { length: 50 }),
  parsedWeight: varchar("parsed_weight", { length: 50 }),
  parsedContact: varchar("parsed_contact", { length: 100 }),
  aiConfidence: decimal("ai_confidence", { precision: 3, scale: 2 }),
  status: mysqlEnum("status", ["new", "processed", "rejected"]).default("new").notNull(),
  createdAt: timestamp("created_at").defaultNow().notNull(),
});

// ============================================================
// 29. AI PROVIDERS
// ============================================================
export const aiProviders = mysqlTable("ai_providers", {
  id: serial("id").primaryKey(),
  name: varchar("name", { length: 100 }).notNull(),
  provider: mysqlEnum("provider", ["openai", "gemini", "claude", "custom"]).notNull(),
  apiKey: varchar("api_key", { length: 255 }),
  apiUrl: varchar("api_url", { length: 255 }),
  model: varchar("model", { length: 100 }),
  dailyQuota: int("daily_quota").default(1000).notNull(),
  usedQuota: int("used_quota").default(0).notNull(),
  costPerRequest: decimal("cost_per_request", { precision: 8, scale: 6 }).default("0.002000").notNull(),
  priority: int("priority").default(1).notNull(),
  isActive: boolean("is_active").default(true).notNull(),
  createdAt: timestamp("created_at").defaultNow().notNull(),
});

// ============================================================
// 30. ACTIVITY LOGS
// ============================================================
export const activityLogs = mysqlTable("activity_logs", {
  id: serial("id").primaryKey(),
  userType: mysqlEnum("user_type", ["local_user", "oauth_user"]).notNull(),
  userId: bigint("user_id", { mode: "number", unsigned: true }).notNull(),
  action: varchar("action", { length: 100 }).notNull(),
  module: varchar("module", { length: 50 }).notNull(),
  entityType: varchar("entity_type", { length: 50 }),
  entityId: bigint("entity_id", { mode: "number", unsigned: true }),
  oldValues: text("old_values"),
  newValues: text("new_values"),
  ipAddress: varchar("ip_address", { length: 45 }),
  userAgent: text("user_agent"),
  createdAt: timestamp("created_at").defaultNow().notNull(),
});

// ============================================================
// 31. IP BANS
// ============================================================
export const ipBans = mysqlTable("ip_bans", {
  id: serial("id").primaryKey(),
  ipAddress: varchar("ip_address", { length: 45 }).notNull(),
  reason: text("reason"),
  bannedBy: bigint("banned_by", { mode: "number", unsigned: true }).references(() => localUsers.id),
  expiresAt: timestamp("expires_at"),
  isPermanent: boolean("is_permanent").default(false).notNull(),
  createdAt: timestamp("created_at").defaultNow().notNull(),
});

// ============================================================
// 32. RATE LIMIT SETTINGS
// ============================================================
export const rateLimitSettings = mysqlTable("rate_limit_settings", {
  id: serial("id").primaryKey(),
  route: varchar("route", { length: 100 }).notNull(),
  maxRequests: int("max_requests").notNull(),
  windowSeconds: int("window_seconds").notNull(),
  isActive: boolean("is_active").default(true).notNull(),
  createdAt: timestamp("created_at").defaultNow().notNull(),
});

// ============================================================
// 33. QUEUED JOBS
// ============================================================
export const queuedJobs = mysqlTable("queued_jobs", {
  id: serial("id").primaryKey(),
  queue: varchar("queue", { length: 100 }).default("default").notNull(),
  jobType: varchar("job_type", { length: 100 }).notNull(),
  payload: text("payload"),
  attempts: int("attempts").default(0).notNull(),
  maxAttempts: int("max_attempts").default(3).notNull(),
  status: mysqlEnum("status", ["pending", "processing", "completed", "failed"]).default("pending").notNull(),
  error: text("error"),
  processedAt: timestamp("processed_at"),
  createdAt: timestamp("created_at").defaultNow().notNull(),
});

// ============================================================
// 34. SYSTEM LOGS
// ============================================================
export const systemLogs = mysqlTable("system_logs", {
  id: serial("id").primaryKey(),
  level: mysqlEnum("level", ["debug", "info", "warning", "error", "critical"]).notNull(),
  message: text("message").notNull(),
  context: text("context"),
  source: varchar("source", { length: 100 }),
  createdAt: timestamp("created_at").defaultNow().notNull(),
});

// ============================================================
// 35. ADMIN SESSIONS
// ============================================================
export const adminSessions = mysqlTable("admin_sessions", {
  id: serial("id").primaryKey(),
  userId: bigint("user_id", { mode: "number", unsigned: true }).notNull().references(() => localUsers.id, { onDelete: "cascade" }),
  token: varchar("token", { length: 512 }).notNull().unique(),
  ipAddress: varchar("ip_address", { length: 45 }),
  userAgent: text("user_agent"),
  expiresAt: timestamp("expires_at").notNull(),
  lastActiveAt: timestamp("last_active_at").defaultNow().notNull(),
  isRevoked: boolean("is_revoked").default(false).notNull(),
  createdAt: timestamp("created_at").defaultNow().notNull(),
});

// ============================================================
// 36. SOFT DELETES (Trash)
// ============================================================
export const softDeletes = mysqlTable("soft_deletes", {
  id: serial("id").primaryKey(),
  entityType: varchar("entity_type", { length: 50 }).notNull(),
  entityId: bigint("entity_id", { mode: "number", unsigned: true }).notNull(),
  originalData: text("original_data").notNull(),
  deletedBy: bigint("deleted_by", { mode: "number", unsigned: true }).references(() => localUsers.id),
  deletedAt: timestamp("deleted_at").defaultNow().notNull(),
  restoredAt: timestamp("restored_at"),
});

// ============================================================
// 37. SITE STATISTICS (For dashboard)
// ============================================================
export const siteStatistics = mysqlTable("site_statistics", {
  id: serial("id").primaryKey(),
  date: date("date").notNull(),
  activeShippers: int("active_shippers").default(0).notNull(),
  activeDrivers: int("active_drivers").default(0).notNull(),
  activeLoads: int("active_loads").default(0).notNull(),
  totalListings: int("total_listings").default(0).notNull(),
  completedShipments: int("completed_shipments").default(0).notNull(),
  escrowAmount: decimal("escrow_amount", { precision: 14, scale: 2 }).default("0").notNull(),
  commissionRevenue: decimal("commission_revenue", { precision: 14, scale: 2 }).default("0").notNull(),
  subscriptionRevenue: decimal("subscription_revenue", { precision: 14, scale: 2 }).default("0").notNull(),
  newRegistrations: int("new_registrations").default(0).notNull(),
  createdAt: timestamp("created_at").defaultNow().notNull(),
});
