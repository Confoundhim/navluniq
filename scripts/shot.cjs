// Ekran doğrulama yardımcısı: bir panele giriş yapıp sayfayı telefon boyutunda (390×844) parçalar hâlinde çeker.
//
//   PW_MODULE=/yol/node_modules/playwright CHROME=/opt/pw-browsers/chromium-1194/chrome-linux/chrome \
//     node scripts/shot.cjs driver /panel/sofor/dashboard normal cikti
//
//   kim:   driver | cargo | admin | public        (LocalDemoSeeder hesapları; şifre Sifre12345!, kod 123456)
//   adres: /panel/sofor/ilan-havuzu?tab=external gibi
//   kip:   normal | large (büyük yazı)
//   önek:  çıktı dosyaları önek-0.png, önek-1.png …
//
// Playwright depoda bağımlılık değildir: `npm i --no-save playwright` ya da PW_MODULE ile yol verin.
const { chromium } = require(process.env.PW_MODULE || 'playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8085';
const [, , who = 'driver', url = '/', mode = 'normal', prefix = 'shot'] = process.argv;
const ACC = { driver: ['sofor@test.local', '/giris'], cargo: ['yuk@test.local', '/giris'], admin: ['admin@test.local', '/adminsystem'], public: null };

(async () => {
  const b = await chromium.launch({ executablePath: process.env.CHROME });
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  await ctx.addInitScript((m) => { try { localStorage.setItem('textSize', m); } catch (e) {} }, mode);
  const page = await ctx.newPage();
  if (ACC[who]) {
    await page.goto(BASE + ACC[who][1], { waitUntil: 'networkidle' });
    await page.fill('input[wire\\:model="identifier"]', ACC[who][0]);
    await page.fill('input[wire\\:model="password"]', process.env.PASS || 'Sifre12345!');
    await page.click('form button[type="submit"]');
    await page.waitForSelector('input[wire\\:model="otp"]', { timeout: 15000 });
    await page.fill('input[wire\\:model="otp"]', process.env.OTP || '123456');
    await page.click('form button[type="submit"]');
    await page.waitForTimeout(2500);
  }
  await page.goto(BASE + url, { waitUntil: 'networkidle' });
  await page.waitForTimeout(500);
  const h = await page.evaluate(() => document.documentElement.scrollHeight);
  const parts = Math.min(8, Math.ceil(h / 844));
  for (let i = 0; i < parts; i++) {
    await page.evaluate((y) => window.scrollTo(0, y), i * 844);
    await page.waitForTimeout(250);
    await page.screenshot({ path: `${prefix}-${i}.png` });
  }
  console.log('parts', parts, 'height', h, page.url());
  await b.close();
})().catch((e) => { console.error('ERR', e.message); process.exit(1); });
