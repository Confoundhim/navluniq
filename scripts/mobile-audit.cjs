/**
 * Mobil uyumluluk denetimi: tüm sayfaları 390 px genişlikte, normal ve büyük yazı kipinde açar;
 * yatay taşma (sayfa genişliği > ekran) ve sağa taşan ögeleri raporlar, taşan sayfaların ekran görüntüsünü alır.
 *
 * Kullanım (yerelde, sunucu 8085'te çalışırken):
 *   BASE=http://127.0.0.1:8085 CHROME=/opt/pw-browsers/chromium-1194/chrome-linux/chrome node scripts/mobile-audit.cjs
 * Giriş: review_login_emails ayarında olan hesaplar sabit OTP (review_login_code) ile girer.
 */
const { chromium } = require(process.env.PW_MODULE || 'playwright');
const fs = require('fs');
const BASE = process.env.BASE || 'http://127.0.0.1:8085';
const OUT = process.env.OUT || 'audit-out';
const OTP = process.env.OTP || '123456';
const WIDTH = parseInt(process.env.WIDTH || '390', 10);
const ACCOUNTS = {
  driver: { email: process.env.DRIVER_EMAIL || 'sofor@test.local', pass: process.env.PASS || 'Sifre12345!', login: '/giris' },
  cargo: { email: process.env.CARGO_EMAIL || 'yuk@test.local', pass: process.env.PASS || 'Sifre12345!', login: '/giris' },
  admin: { email: process.env.ADMIN_EMAIL || 'admin@test.local', pass: process.env.PASS || 'Sifre12345!', login: '/adminsystem' },
};
const PAGES = {
  public: ['/', '/abonelik', '/hakkimizda', '/hizmetlerimiz', '/iletisim', '/nasil-calisir', '/soforler-icin', '/yuk-sahipleri-icin', '/giris', '/kayit/sofor', '/kayit/yuk-sahibi', '/sifremi-unuttum', '/adminsystem'],
  driver: ['/panel/sofor/dashboard', '/panel/sofor/ilan-havuzu', '/panel/sofor/ilan-havuzu?tab=external', '/panel/sofor/ilan-havuzu?tab=offers', '/panel/sofor/araclarim', '/panel/sofor/bildirimler', '/panel/sofor/odemelerim', '/panel/sofor/premium', '/panel/sofor/premium/odeme', '/panel/sofor/profil', '/panel/sofor/sevkiyatlarim', '/panel/sofor/uyusmazliklar'],
  cargo: ['/panel/yuk-sahibi/dashboard', '/panel/yuk-sahibi/adres-defteri', '/panel/yuk-sahibi/bildirimler', '/panel/yuk-sahibi/destek', '/panel/yuk-sahibi/finans-ve-faturalar', '/panel/yuk-sahibi/ilan-olustur', '/panel/yuk-sahibi/ilanlarim', '/panel/yuk-sahibi/profil', '/panel/yuk-sahibi/sevkiyatlarim', '/panel/yuk-sahibi/uyusmazliklar'],
  admin: ['/adminsystem/dashboard', '/adminsystem/users', '/adminsystem/kyc', '/adminsystem/notifications', '/adminsystem/operations', '/adminsystem/scrapers', '/adminsystem/scrapers?sekme=published', '/adminsystem/scrapers?sekme=events', '/adminsystem/scrapers?sekme=sources', '/adminsystem/scrapers?sekme=lexicon', '/adminsystem/finance', '/adminsystem/disputes', '/adminsystem/crm', '/adminsystem/cms', '/adminsystem/languages', '/adminsystem/settings', '/adminsystem/settings?tab=scraper', '/adminsystem/staff', '/adminsystem/rollback', '/adminsystem/health', '/adminsystem/firewall', '/adminsystem/backups'],
};

async function login(page, acc) {
  await page.goto(BASE + acc.login, { waitUntil: 'networkidle' });
  await page.fill('input[wire\\:model="identifier"]', acc.email);
  await page.fill('input[wire\\:model="password"]', acc.pass);
  await page.click('form button[type="submit"]');
  await page.waitForSelector('input[wire\\:model="otp"]', { timeout: 15000 });
  await page.fill('input[wire\\:model="otp"]', OTP);
  await page.click('form button[type="submit"]');
  // Livewire yönlendirmesi JS ile gelir; giriş sayfasından ayrılmayı bekle.
  await page.waitForFunction(() => !/\/(giris|adminsystem)$/.test(location.pathname), null, { timeout: 15000 }).catch(() => {});
  await page.waitForLoadState('networkidle');
}

async function measure(page) {
  return page.evaluate(() => {
    const vw = window.innerWidth;
    const doc = document.documentElement;
    const offenders = [];
    for (const el of document.querySelectorAll('body *')) {
      const cs = getComputedStyle(el);
      if (cs.display === 'none' || cs.visibility === 'hidden' || cs.position === 'fixed') continue;
      const r = el.getBoundingClientRect();
      if (r.width === 0 && r.height === 0) continue;
      if (r.right > vw + 1 && r.right - Math.max(r.left, 0) > 2) { // sağa taşan (solda gizli kenar çubuğu sayılmaz)
        // kaydırılabilir kapsayıcı içindeyse (responsive-scroll / overflow-x-auto) sayılmaz
        let p = el.parentElement, scrollable = false;
        while (p && p !== document.body) { const pcs = getComputedStyle(p); if (/(auto|scroll|hidden|clip)/.test(pcs.overflowX) || /(auto|scroll|hidden|clip)/.test(pcs.overflow)) { scrollable = true; break; } p = p.parentElement; }
        if (scrollable) continue;
        const tag = el.tagName.toLowerCase();
        const cls = (el.getAttribute('class') || '').split(/\s+/).slice(0, 6).join(' ');
        const text = (el.innerText || '').trim().replace(/\s+/g, ' ').slice(0, 60);
        offenders.push({ tag, cls, text, right: Math.round(r.right), left: Math.round(r.left), width: Math.round(r.width) });
      }
    }
    return { vw, scrollWidth: doc.scrollWidth, bodyScroll: document.body.scrollWidth, offenders: offenders.slice(0, 8) };
  });
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: process.env.CHROME || undefined });
  const report = [];
  for (const [group, urls] of Object.entries(PAGES)) {
    for (const mode of ['normal', 'large']) {
      const ctx = await browser.newContext({ viewport: { width: WIDTH, height: 844 }, deviceScaleFactor: 1, isMobile: true, hasTouch: true });
      await ctx.addInitScript((m) => { try { localStorage.setItem('textSize', m); } catch (e) {} }, mode);
      const page = await ctx.newPage();
      if (group !== 'public') {
        try { await login(page, ACCOUNTS[group]); } catch (e) { report.push({ group, mode, url: 'LOGIN', error: e.message }); await ctx.close(); continue; }
      }
      for (const url of urls) {
        try {
          const resp = await page.goto(BASE + url, { waitUntil: 'networkidle', timeout: 30000 });
          await page.waitForTimeout(400);
          const m = await measure(page);
          const overflow = m.scrollWidth > m.vw + 1 || m.bodyScroll > m.vw + 1 || m.offenders.length > 0;
          const entry = { group, mode, url, status: resp && resp.status(), final: page.url().replace(BASE, ''), overflow, scrollWidth: m.scrollWidth, offenders: m.offenders };
          report.push(entry);
          if (overflow || process.env.SHOTS === 'all') {
            const name = `${group}-${mode}-${url.replace(/[^a-z0-9]+/gi, '_')}.png`;
            await page.screenshot({ path: `${OUT}/${name}`, fullPage: true });
            entry.shot = name;
          }
        } catch (e) {
          report.push({ group, mode, url, error: e.message.slice(0, 120) });
        }
      }
      await ctx.close();
    }
  }
  await browser.close();
  fs.writeFileSync(`${OUT}/report.json`, JSON.stringify(report, null, 2));
  const bad = report.filter((r) => r.overflow || r.error);
  console.log(`Sayfa: ${report.length} · taşan/hatalı: ${bad.length}`);
  for (const r of bad) {
    console.log(`- [${r.group}/${r.mode}] ${r.url} ${r.error ? 'HATA ' + r.error : 'genişlik ' + r.scrollWidth + ' → ' + (r.final || '')}`);
    for (const o of r.offenders || []) console.log(`    <${o.tag} class="${o.cls}"> sağ=${o.right} gen=${o.width} "${o.text}"`);
  }
})().catch((e) => { console.error('ERR', e); process.exit(1); });
