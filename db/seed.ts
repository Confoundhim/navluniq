import { getDb } from "../api/queries/connection";
import { roles, localUsers, siteSettings, permissions, rolePermissions } from "./schema";
import bcrypt from "bcryptjs";

async function seed() {
  const db = getDb();
  console.log("Seeding database...");

  // 1. Create default roles
  const existingRoles = await db.select().from(roles);
  if (existingRoles.length === 0) {
    await db.insert(roles).values([
      { name: "super_admin", displayName: "Süper Admin", description: "Tam erişim sahibi sistem yöneticisi", isSystem: true },
      { name: "admin", displayName: "Admin", description: "Sistem yöneticisi", isSystem: true },
      { name: "finance_manager", displayName: "Finans Sorumlusu", description: "Finans ve muhasebe erişimi", isSystem: false },
      { name: "kyc_verifier", displayName: "Evrak Onaycı", description: "KYC belge onaylama yetkisi", isSystem: false },
      { name: "support_agent", displayName: "Destek Temsilcisi", description: "Destek bileti yönetimi", isSystem: false },
      { name: "content_editor", displayName: "İçerik Editörü", description: "CMS ve içerik yönetimi", isSystem: false },
    ]);
    console.log("✅ Roles created");
  }

  // 2. Create default admin user
  const existingAdmins = await db.select().from(localUsers);
  if (existingAdmins.length === 0) {
    const adminRole = await db.select().from(roles).where(eq(roles.name, "super_admin")).limit(1);
    const roleId = adminRole[0]?.id ?? 1;

    const passwordHash = await bcrypt.hash("Lptr.AyP.6368Tq.!@", 12);
    await db.insert(localUsers).values({
      username: "navluniq.com_admin",
      email: "osmanyilmz@outlook.com.tr",
      passwordHash,
      displayName: "NavlunIQ Admin",
      roleId,
      status: "active",
      otpEnabled: true,
    });
    console.log("✅ Default admin user created (username: navluniq.com_admin, password: Lptr.AyP.6368Tq.!@)");
  }

  // 3. Create default permissions
  const existingPerms = await db.select().from(permissions);
  if (existingPerms.length === 0) {
    const permissionData = [
      // Dashboard
      { name: "dashboard.view", module: "dashboard", action: "view", description: "Dashboard görüntüleme" },
      // Users
      { name: "users.view", module: "users", action: "view", description: "Kullanıcıları görüntüleme" },
      { name: "users.manage", module: "users", action: "manage", description: "Kullanıcıları yönetme" },
      // KYC
      { name: "kyc.view", module: "kyc", action: "view", description: "KYC belgelerini görüntüleme" },
      { name: "kyc.review", module: "kyc", action: "review", description: "KYC belgelerini onaylama" },
      // Loads
      { name: "loads.view", module: "loads", action: "view", description: "İlanları görüntüleme" },
      { name: "loads.manage", module: "loads", action: "manage", description: "İlanları yönetme" },
      // Finance
      { name: "finance.view", module: "finance", action: "view", description: "Finans görüntüleme" },
      { name: "finance.manage", module: "finance", action: "manage", description: "Finans yönetme" },
      // Disputes
      { name: "disputes.view", module: "disputes", action: "view", description: "Uyuşmazlıkları görüntüleme" },
      { name: "disputes.resolve", module: "disputes", action: "resolve", description: "Uyuşmazlık çözme" },
      // Tickets
      { name: "tickets.view", module: "tickets", action: "view", description: "Biletleri görüntüleme" },
      { name: "tickets.manage", module: "tickets", action: "manage", description: "Bilet yönetme" },
      // Marketing
      { name: "marketing.view", module: "marketing", action: "view", description: "Pazarlama görüntüleme" },
      { name: "marketing.manage", module: "marketing", action: "manage", description: "Pazarlama yönetme" },
      // CMS
      { name: "cms.view", module: "cms", action: "view", description: "CMS görüntüleme" },
      { name: "cms.manage", module: "cms", action: "manage", description: "CMS yönetme" },
      // Settings
      { name: "settings.view", module: "settings", action: "view", description: "Ayarları görüntüleme" },
      { name: "settings.manage", module: "settings", action: "manage", description: "Ayarları yönetme" },
      // Personnel
      { name: "personnel.view", module: "personnel", action: "view", description: "Personel görüntüleme" },
      { name: "personnel.manage", module: "personnel", action: "manage", description: "Personel yönetme" },
      // Security
      { name: "security.view", module: "security", action: "view", description: "Güvenlik görüntüleme" },
      { name: "security.manage", module: "security", action: "manage", description: "Güvenlik yönetme" },
      // Health
      { name: "health.view", module: "health", action: "view", description: "Sistem sağlığı görüntüleme" },
      // AI
      { name: "ai.view", module: "ai", action: "view", description: "AI merkezi görüntüleme" },
      { name: "ai.manage", module: "ai", action: "manage", description: "AI merkezi yönetme" },
    ];

    await db.insert(permissions).values(permissionData);
    console.log("✅ Permissions created");
  }

  // 4. Assign all permissions to super_admin
  const superAdminRole = await db.select().from(roles).where(eq(roles.name, "super_admin")).limit(1);
  if (superAdminRole[0]) {
    const allPerms = await db.select().from(permissions);
    const existingRolePerms = await db.select().from(rolePermissions)
      .where(eq(rolePermissions.roleId, superAdminRole[0].id));

    if (existingRolePerms.length === 0) {
      for (const perm of allPerms) {
        await db.insert(rolePermissions).values({
          roleId: superAdminRole[0].id,
          permissionId: perm.id,
        });
      }
      console.log("✅ All permissions assigned to super_admin");
    }
  }

  // 5. Create default site settings
  const existingSettings = await db.select().from(siteSettings);
  if (existingSettings.length === 0) {
    await db.insert(siteSettings).values([
      { key: "site_title", value: "NavlunIQ - Akıllı Lojistik Ağı", group: "general", label: "Site Başlığı", type: "text", isPublic: true },
      { key: "site_description", value: "Yük sahipleri ile şoförler arasında güvenli lojistik platformu", group: "general", label: "Site Açıklaması", type: "textarea", isPublic: true },
      { key: "commission_rate_shipper", value: "2.50", group: "finance", label: "Yük Sahibi Komisyon Oranı (%)", type: "number", isPublic: false },
      { key: "commission_rate_driver", value: "5.00", group: "finance", label: "Şoför Komisyon Oranı (%)", type: "number", isPublic: false },
      { key: "premium_price", value: "900", group: "finance", label: "Premium Üyelik Fiyatı (TL)", type: "number", isPublic: true },
      { key: "currency", value: "TRY", group: "general", label: "Varsayılan Para Birimi", type: "text", isPublic: true },
      { key: "timezone", value: "Europe/Istanbul", group: "general", label: "Zaman Dilimi", type: "text", isPublic: false },
      { key: "maintenance_mode", value: "false", group: "general", label: "Bakım Modu", type: "boolean", isPublic: false },
      { key: "paytr_mode", value: "test", group: "payment", label: "PayTR Modu", type: "text", isPublic: false },
      { key: "smtp_host", value: "", group: "email", label: "SMTP Host", type: "text", isPublic: false },
      { key: "smtp_port", value: "587", group: "email", label: "SMTP Port", type: "number", isPublic: false },
      { key: "smtp_user", value: "", group: "email", label: "SMTP Kullanıcı", type: "text", isPublic: false },
      { key: "sms_provider", value: "netgsm", group: "sms", label: "SMS Sağlayıcı", type: "text", isPublic: false },
      { key: "otp_email_subject", value: "NavlunIQ - Doğrulama Kodunuz", group: "email", label: "OTP E-posta Konusu", type: "text", isPublic: false },
      { key: "otp_email_template", value: "Sayın kullanıcı, NavlunIQ admin paneli doğrulama kodunuz: {{CODE}}\nBu kod 10 dakika geçerlidir.", group: "email", label: "OTP E-posta Şablonu", type: "textarea", isPublic: false },
    ]);
    console.log("✅ Site settings created");
  }

  console.log("\n🎉 Seed completed successfully!");
  console.log("Admin login: username=navluniq.com_admin, password=Lptr.AyP.6368Tq.!@");
}

// Need to import eq for seed
import { eq } from "drizzle-orm";

seed().catch(console.error);
