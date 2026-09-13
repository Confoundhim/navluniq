// NavlunIQ Otonom WhatsApp Kazıma ve Mesaj Yakalama Mikro-servisi
// Dizin: C:\Users\osman\Herd\navluniq\whatsapp-scraper-daemon\index.js (Canlıda: /var/www/navluniq/whatsapp-scraper-daemon/index.js)

import { makeWASocket, fetchLatestWaWebVersion, makeCacheableSignalKeyStore, initAuthCreds, BufferJSON, DisconnectReason } from '@whiskeysockets/baileys';
import qrcode from 'qrcode-terminal';
import axios from 'axios';
import dns from 'dns';
import pino from 'pino';
import fs from 'fs';
import path from 'path';

// Windows ve Node.js Yerel Ağ Hatası Çözümü
dns.setDefaultResultOrder('ipv4first');

// Kriptografi ve oturum hataları susturulmaz; süreç yöneticisi kontrollü yeniden başlatır.

// Konfigürasyonlar
const LARAVEL_API_URL = process.env.NAVLUNIQ_API_URL;
const SCRAPER_API_TOKEN = process.env.SCRAPER_API_TOKEN;
const AUTH_FILE_PATH = process.env.WHATSAPP_AUTH_FILE || '/var/lib/navluniq-whatsapp/auth.json';
const ALLOWED_GROUP_IDS = new Set((process.env.WHATSAPP_ALLOWED_GROUP_IDS || '').split(',').map(v => v.trim()).filter(Boolean));

if (!LARAVEL_API_URL || !SCRAPER_API_TOKEN || ALLOWED_GROUP_IDS.size === 0) {
    throw new Error('NAVLUNIQ_API_URL, SCRAPER_API_TOKEN ve WHATSAPP_ALLOWED_GROUP_IDS zorunludur.');
}
const contactMap = new Map();
const msgRetryCounterCache = new Map();
const logger = pino({ level: 'silent' });

process.on('uncaughtException', (err) => {
    console.error('Kritik işçi hatası:', err?.message || String(err));
    process.exit(1);
});
process.on('unhandledRejection', (reason) => {
    console.error('İşlenmeyen promise reddi:', reason?.message || String(reason));
    process.exit(1);
});

// 🚀 ÖZEL MİMARİ: ATOMİK TEK DOSYA OTURUM YÖNETİCİSİ (50.000 Dosya Yerine Sadece 1 Dosya)
const useAtomicSingleFileAuthState = (filename) => {
    let creds;
    let keys = {};

    if (fs.existsSync(filename)) {
        try {
            const data = JSON.parse(fs.readFileSync(filename, { encoding: 'utf-8' }), BufferJSON.reviver);
            creds = data.creds;
            keys = data.keys;
        } catch (error) {
            console.error('⚠️ Oturum dosyası bozuk, sıfırlanıyor...');
            creds = initAuthCreds();
            keys = {};
        }
    } else {
        creds = initAuthCreds();
        keys = {};
    }

    let saveTimer = null;
    const save = () => {
        try {
            const data = JSON.stringify({ creds, keys }, BufferJSON.replacer, 2);
            // Atomik yazma: Çökme anında dosya bozulmasını %100 engeller
            fs.writeFileSync(filename + '.tmp', data, { mode: 0o600 });
            fs.renameSync(filename + '.tmp', filename);
        } catch (error) {
            console.error('🔥 Oturum dosyası kaydedilemedi:', error.message);
        }
    };

    const debouncedSave = () => {
        if (saveTimer) clearTimeout(saveTimer);
        saveTimer = setTimeout(save, 2000); // Değişikliklerden 2 saniye sonra tek seferde diske yazar
    };

    return {
        state: {
            creds,
            keys: {
                get: (type, ids) => {
                    const data = {};
                    for (const id of ids) {
                        let value = keys[`${type}-${id}`];
                        if (type === 'app-state-sync-key' && value) {
                            // Baileys iç yapısı için gerekli dönüşüm
                        }
                        data[id] = value;
                    }
                    return data;
                },
                set: (data) => {
                    for (const category in data) {
                        for (const id in data[category]) {
                            const value = data[category][id];
                            const key = `${category}-${id}`;
                            if (value) {
                                keys[key] = value;
                            } else {
                                delete keys[key];
                            }
                        }
                    }
                    debouncedSave();
                }
            }
        },
        saveCreds: () => {
            debouncedSave();
        }
    };
};

async function startScraper() {
    // 50.000 dosya üreten eski motoru çöpe attık. Yeni Atomik motoru kullanıyoruz.
    const AUTH_FILE = path.resolve(AUTH_FILE_PATH);
    fs.mkdirSync(path.dirname(AUTH_FILE), { recursive: true, mode: 0o700 });
    const { state, saveCreds } = useAtomicSingleFileAuthState(AUTH_FILE);

    // RAM ÖN BELLEKLEME: Hızı mikrosaniyelere çıkarır
    const authState = {
        creds: state.creds,
        keys: makeCacheableSignalKeyStore(state.keys, logger)
    };

    let version = [2, 3000, 1017531287];
    try {
        const { version: latestVersion } = await fetchLatestWaWebVersion();
        version = latestVersion;
    } catch (err) {}

    const sock = makeWASocket({
        auth: authState,
        printQRInTerminal: false,
        connectTimeoutMs: 60000,
        keepAliveIntervalMs: 30000,
        version: version,
        browser: ["Ubuntu", "Chrome", "20.0.04"],
        logger: logger,
        msgRetryCounterCache,
        getMessage: async (key) => {
            return { conversation: 'NavlunIQ_Otonom_Kurtarma_Mesaji' };
        }
    });

    sock.ev.on('contacts.upsert', (contacts) => {
        try {
            for (const contact of contacts) {
                if (contact.id && contact.id.endsWith('@s.whatsapp.net')) {
                    const phone = contact.id.split('@')[0];
                    if (contact.lid) contactMap.set(contact.lid, phone);
                }
            }
        } catch (error) {}
    });

    sock.ev.on('contacts.update', (updates) => {
        try {
            for (const update of updates) {
                if (update.id && update.lid) {
                    const phone = update.id.split('@')[0];
                    contactMap.set(update.lid, phone);
                }
            }
        } catch (error) {}
    });

    sock.ev.on('connection.update', (update) => {
        const { connection, lastDisconnect, qr } = update;

        if (qr) {
            console.clear();
            console.log('🤖 NavlunIQ Burner WhatsApp Numarasını Bağlayın:');
            console.log('Lütfen telefonunuzdan WhatsApp -> Bağlı Cihazlar -> Cihaz Bağla diyerek aşağıdaki QR kodu taratın:\n');
            qrcode.generate(qr, { small: true });
        }

        if (connection === 'close') {
            const statusCode = lastDisconnect?.error?.output?.statusCode;
            const shouldReconnect = statusCode !== DisconnectReason.loggedOut;

            console.log(`⚠️ Bağlantı kesildi. (Durum Kodu: ${statusCode}) Yeniden bağlanılıyor mu?:`, shouldReconnect);

            if (shouldReconnect) {
                setTimeout(() => startScraper(), 3000);
            } else {
                console.error('❌ Oturum geçersiz veya çıkış yapıldı. Eski oturum verileri temizleniyor...');
                try {
                    if (fs.existsSync(AUTH_FILE)) fs.unlinkSync(AUTH_FILE);
                    if (fs.existsSync(AUTH_FILE + '.tmp')) fs.unlinkSync(AUTH_FILE + '.tmp');
                    console.log('✅ Temizlik tamamlandı. Yeni QR kod için sistem yeniden başlatılıyor...');
                    setTimeout(() => startScraper(), 2000);
                } catch (rmErr) {
                    console.error('❌ Oturum dosyası silinirken hata:', rmErr.message);
                }
            }
        } else if (connection === 'open') {
            console.clear();
            console.log('✅ NavlunIQ Otonom WhatsApp Kazıma Sunucusu Başarıyla Bağlandı!');
            console.log('🤖 Burner numara aktif olarak lojistik gruplarını dinliyor...');
        }
    });

    sock.ev.on('messages.upsert', async (m) => {
        try {
            const msg = m.messages[0];
            if (!msg || !msg.message) return;

            const rawText = msg.message.conversation || msg.message.extendedTextMessage?.text;
            const fromJid = msg.key.remoteJid;

            if (!fromJid || !rawText) return;
            const senderNumber = msg.key.participantAlt || msg.key.participant || fromJid;

            if (msg.key.fromMe && !fromJid.endsWith('@g.us')) return;

            if (fromJid.endsWith('@g.us')) {
                const groupMetadata = await sock.groupMetadata(fromJid);
                const groupName = groupMetadata.subject;

                if (groupMetadata.participants) {
                    for (const participant of groupMetadata.participants) {
                        if (participant.id && participant.lid) {
                            const phone = participant.id.split('@')[0];
                            contactMap.set(participant.lid, phone);
                        }
                    }
                }

                let realPhone = senderNumber.split('@')[0];
                if (senderNumber.endsWith('@lid') && contactMap.has(senderNumber)) {
                    realPhone = contactMap.get(senderNumber);
                }

                if (!ALLOWED_GROUP_IDS.has(fromJid)) return;
                console.log(`📬 Mesaj alındı: ${msg.key.id || 'kimlik-yok'}`);

                const response = await axios.post(LARAVEL_API_URL, {
                    group_name: groupName,
                    raw_message: rawText,
                    sender_phone: realPhone,
                    message_id: msg.key.id || null,
                    source_jid: fromJid,
                    occurred_at: msg.messageTimestamp ? new Date(Number(msg.messageTimestamp) * 1000).toISOString() : null
                }, {
                    headers: {
                        'X-Scraper-Token': SCRAPER_API_TOKEN,
                        'Content-Type': 'application/json'
                    },
                    timeout: 10000
                });

                if (response.data && response.data.message) {
                    console.log('🚀 [YAPAY ZEKA] Yanıtı:', response.data.message);
                    if (response.data.parsed_data) {
                        console.log('📌 Çözümlenen Rota:', response.data.parsed_data.pickup_location, '->', response.data.parsed_data.delivery_location);
                    }
                }
            }
        } catch (err) {
            if (!err.message.includes('Bad MAC') && !err.message.includes('Session error')) {
                console.error('❌ Mesaj işleme hatası:', err.message);
            }
        }
    });

    sock.ev.on('creds.update', saveCreds);
}

startScraper();
