const {
    default: makeWASocket,
    useMultiFileAuthState,
    DisconnectReason,
    fetchLatestBaileysVersion,
    Browsers
} = require('@whiskeysockets/baileys');
const qrcode = require('qrcode-terminal');
const express = require('express');
const pino = require('pino');
const fs = require('fs');
const path = require('path');

const app = express();
app.use(express.json());

let sock;
let isConnectionReady = false;
let connectionReadySince = 0;

// ============================================================
// KONFIGURASI ANTI-DETEKSI / ANTI-SPAM (VERSI MAX PROTECTION)
// ============================================================
const CONFIG = {
    MIN_DELAY_MS: 5000,
    MAX_DELAY_MS: 15000,
    MIN_GAP_PER_NUMBER_MS: 2 * 60 * 1000,

    MAX_RETRY: 3,
    RETRY_BASE_DELAY_MS: 20000,
    RETRY_JITTER_MS: 8000,

    TYPING_MS_PER_CHAR: 35,
    TYPING_MIN_MS: 1500,
    TYPING_MAX_MS: 6000,

    MAX_PER_HOUR: 40,
    MAX_PER_DAY: 250,
    COOLDOWN_AFTER_CONNECT_MS: 20000,

    STATE_FILE: path.join(__dirname, 'wa-sidecar-state.json'),
    SAVE_INTERVAL_MS: 5000,

    // [BARU] Konfigurasi Hibrida (Bot + Manusia)
    AUTO_READ_MESSAGES: false, // Set 'false' agar pesan masuk tidak otomatis centang biru oleh bot, sehingga Anda bisa membacanya sendiri
    AUTO_REJECT_CALLS: false,   // Tolak panggilan otomatis agar bot tidak error saat ditelepon orang
};

function randomBetween(min, max) {
    return Math.floor(Math.random() * (max - min + 1)) + min;
}

function delay(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

// [BARU] Fungsi Mutasi Teks (Mengecoh Deteksi Hash Meta)
// Menambahkan karakter Zero-Width (tidak terlihat) secara acak
function injectAntiSpamHash(text) {
    const hiddenChars = ['\u200B', '\u200C', '\u200D', '\uFEFF'];
    let hash = '';
    // Sisipkan 2-5 karakter tak kasat mata di akhir pesan
    const hashLength = randomBetween(2, 5);
    for(let i = 0; i < hashLength; i++) {
        hash += hiddenChars[Math.floor(Math.random() * hiddenChars.length)];
    }
    return text + hash;
}

// ============================================================
// PERSISTENSI STATE
// ============================================================
let messageQueue = [];
let lastSentPerNumber = new Map();
let sendLog = [];
let isProcessingQueue = false;

function loadState() {
    try {
        if (fs.existsSync(CONFIG.STATE_FILE)) {
            const raw = JSON.parse(fs.readFileSync(CONFIG.STATE_FILE, 'utf-8'));
            messageQueue = raw.messageQueue || [];
            lastSentPerNumber = new Map(raw.lastSentPerNumber || []);
            sendLog = raw.sendLog || [];
            console.log(`📂 State dipulihkan: ${messageQueue.length} pesan tertunda di antrean.`);
        }
    } catch (err) {
        console.error('⚠️ Gagal memuat state sebelumnya:', err.message);
    }
}

let saveScheduled = false;
function saveStateDebounced() {
    if (saveScheduled) return;
    saveScheduled = true;
    setTimeout(() => {
        saveScheduled = false;
        try {
            const raw = JSON.stringify({
                messageQueue,
                lastSentPerNumber: Array.from(lastSentPerNumber.entries()),
                sendLog: sendLog.slice(-1000),
            });
            fs.writeFileSync(CONFIG.STATE_FILE, raw);
        } catch (err) { }
    }, CONFIG.SAVE_INTERVAL_MS);
}
loadState();

// ============================================================
// KUOTA PENGIRIMAN
// ============================================================
function pruneSendLog() {
    const cutoff = Date.now() - 24 * 60 * 60 * 1000;
    sendLog = sendLog.filter((ts) => ts > cutoff);
}

function quotaStatus() {
    pruneSendLog();
    const now = Date.now();
    const perHour = sendLog.filter((ts) => ts > now - 60 * 60 * 1000).length;
    return { perHour, perDay: sendLog.length };
}

function isQuotaExceeded() {
    const { perHour, perDay } = quotaStatus();
    return perHour >= CONFIG.MAX_PER_HOUR || perDay >= CONFIG.MAX_PER_DAY;
}

// ============================================================
// ANTREAN PESAN
// ============================================================
function enqueueMessage(jid, message, retryCount = 0) {
    messageQueue.push({ jid, message, retryCount, enqueuedAt: Date.now() });
    saveStateDebounced();
    processQueue();
}

async function processQueue() {
    if (isProcessingQueue) return;
    isProcessingQueue = true;

    while (messageQueue.length > 0) {
        if (!isConnectionReady) {
            await delay(3000);
            continue;
        }

        const sinceReady = Date.now() - connectionReadySince;
        if (sinceReady < CONFIG.COOLDOWN_AFTER_CONNECT_MS) await delay(CONFIG.COOLDOWN_AFTER_CONNECT_MS - sinceReady);

        if (isQuotaExceeded()) {
            console.log(`⏸️ Kuota penuh. Menunggu 5 menit...`);
            await delay(5 * 60 * 1000);
            continue;
        }

        const item = messageQueue.shift();
        saveStateDebounced();

        const lastSent = lastSentPerNumber.get(item.jid) || 0;
        const gapSinceLast = Date.now() - lastSent;
        if (gapSinceLast < CONFIG.MIN_GAP_PER_NUMBER_MS) {
            await delay(CONFIG.MIN_GAP_PER_NUMBER_MS - gapSinceLast);
        }

        await sendWithTypingSimulation(item);

        if (messageQueue.length > 0) {
            await delay(randomBetween(CONFIG.MIN_DELAY_MS, CONFIG.MAX_DELAY_MS));
        }
    }
    isProcessingQueue = false;
}

async function sendWithTypingSimulation(item) {
    const { jid, message, retryCount } = item;
    try {
        const [check] = await sock.onWhatsApp(jid).catch(() => []);
        if (!check?.exists) {
            console.warn(`⚠️ Nomor ${jid} tidak terdaftar di WhatsApp.`);
            return;
        }
        const resolvedJid = check.jid || jid;

        // [BARU] Mutasi Teks: Pengacak String Anti-Spam
        const safeMessage = injectAntiSpamHash(message);

        await sock.presenceSubscribe(resolvedJid).catch(() => {});
        const typingDuration = Math.min(CONFIG.TYPING_MAX_MS, Math.max(CONFIG.TYPING_MIN_MS, safeMessage.length * CONFIG.TYPING_MS_PER_CHAR));

        await sock.sendPresenceUpdate('composing', resolvedJid);
        await delay(typingDuration);
        await sock.sendPresenceUpdate('paused', resolvedJid);

        await sock.sendMessage(resolvedJid, { text: safeMessage });

        lastSentPerNumber.set(resolvedJid, Date.now());
        sendLog.push(Date.now());
        saveStateDebounced();
        console.log(`✅ Pesan terkirim ke ${resolvedJid}`);
    } catch (error) {
        console.error(`❌ Gagal kirim ke ${jid}:`, error.message);
        if (retryCount < CONFIG.MAX_RETRY) {
            const backoff = CONFIG.RETRY_BASE_DELAY_MS * Math.pow(2, retryCount) + randomBetween(0, CONFIG.RETRY_JITTER_MS);
            setTimeout(() => enqueueMessage(jid, message, retryCount + 1), backoff);
        }
    }
}

// ============================================================
// KONEKSI WHATSAPP
// ============================================================
async function connectToWhatsApp() {
    const { state, saveCreds } = await useMultiFileAuthState('auth_info_baileys');
    const { version, isLatest } = await fetchLatestBaileysVersion();

    sock = makeWASocket({
        version,
        logger: pino({ level: 'silent' }),
        auth: state,
        printQRInTerminal: false,
        markOnlineOnConnect: false, // Sangat penting agar status "Online" tidak menyala 24/7
        browser: Browsers.macOS('Chrome'),
        syncFullHistory: false,
        generateHighQualityLinkPreview: false // Nonaktifkan agar tidak memberatkan request ke server saat kirim link
    });

    sock.ev.on('creds.update', saveCreds);

    // [BARU] Handler Panggilan (Call)
    sock.ev.on('call', async (calls) => {
        if (!CONFIG.AUTO_REJECT_CALLS) return;
        for (const call of calls) {
            if (call.status === 'offer') {
                console.log(`🔕 Menolak panggilan masuk dari ${call.from}`);
                // Tolak panggilan secara halus
                await sock.rejectCall(call.id, call.from).catch(() => {});
            }
        }
    });

    // Handler Auto-Read (Dibuat Kondisional agar tidak bentrok dengan pemakaian manual)
    sock.ev.on('messages.upsert', async ({ messages, type }) => {
        if (type === 'notify' && CONFIG.AUTO_READ_MESSAGES) {
            for (const msg of messages) {
                if (!msg.key.fromMe && msg.key.remoteJid !== 'status@broadcast') {
                    const readDelay = randomBetween(4000, 15000);
                    setTimeout(async () => {
                        await sock.readMessages([msg.key]).catch(() => {});
                    }, readDelay);
                }
            }
        }
    });

    sock.ev.on('connection.update', (update) => {
        const { connection, lastDisconnect, qr } = update;
        if (qr) qrcode.generate(qr, { small: true });

        if (connection === 'close') {
            isConnectionReady = false;
            const shouldReconnect = (lastDisconnect?.error)?.output?.statusCode !== DisconnectReason.loggedOut;
            if (shouldReconnect) setTimeout(connectToWhatsApp, randomBetween(3000, 8000));
        } else if (connection === 'open') {
            isConnectionReady = true;
            connectionReadySince = Date.now();
            console.log('✅ WA Baileys Ready & Terlindungi!');
        }
    });
}
connectToWhatsApp();

// ============================================================
// ENDPOINT HTTP
// ============================================================
app.post('/send-message', async (req, res) => {
    try {
        const { number, message } = req.body;
        if (!number || !message) return res.status(400).json({ status: false, error: 'Nomor/pesan kosong!' });

        let formattedNumber = number.replace(/[^0-9]/g, '');
        if (formattedNumber.startsWith('0')) formattedNumber = '62' + formattedNumber.substring(1);

        enqueueMessage(`${formattedNumber}@s.whatsapp.net`, message);
        return res.json({ status: true, message: 'Masuk antrean.', queue_position: messageQueue.length });
    } catch (error) {
        return res.status(500).json({ status: false, error: error.message });
    }
});

app.listen(3000, () => console.log('🚀 WA Sidecar berjalan (Versi Max Protect)'));
process.on('SIGINT', () => { saveStateDebounced(); setTimeout(() => process.exit(0), 1000); });
process.on('SIGTERM', () => { saveStateDebounced(); setTimeout(() => process.exit(0), 1000); });
