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
// KONFIGURASI ANTI-DETEKSI / ANTI-SPAM (VERSI ULTIMATE)
// ============================================================
const CONFIG = {
    // Pengaturan Jeda (Delay)
    MIN_DELAY_MS: 5000,
    MAX_DELAY_MS: 15000,
    MIN_GAP_PER_NUMBER_MS: 2 * 60 * 1000,

    // Pengaturan Retry
    MAX_RETRY: 3,
    RETRY_BASE_DELAY_MS: 20000,
    RETRY_JITTER_MS: 8000,

    // Pengaturan Ketikan
    TYPING_MS_PER_CHAR: 35,
    TYPING_MIN_MS: 1500,
    TYPING_MAX_MS: 6000,

    // Kuota Maksimal (Akan diatur oleh sistem Warm-up)
    MAX_PER_HOUR_TARGET: 40,
    MAX_PER_DAY_TARGET: 250,
    COOLDOWN_AFTER_CONNECT_MS: 20000,

    // Jam Operasional (Format 24 Jam)
    WORK_START_HOUR: 8, // Mulai jam 08:00 pagi
    WORK_END_HOUR: 16,  // Berhenti jam 20:00 malam

    // File State
    STATE_FILE: path.join(__dirname, 'wa-sidecar-state.json'),
    SAVE_INTERVAL_MS: 5000,

    // Konfigurasi Hibrida
    AUTO_READ_MESSAGES: false,
    AUTO_REJECT_CALLS: false,
};

// ============================================================
// FUNGSI UTILITAS & ALGORITMA ANTI-SPAM
// ============================================================

function randomBetween(min, max) {
    return Math.floor(Math.random() * (max - min + 1)) + min;
}

function delay(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Menghasilkan delay dengan Distribusi Gaussian (Kurva Lonceng)
 */
function gaussianDelay(mean, stdDev, min, max) {
    let u1 = Math.random();
    let u2 = Math.random();
    while (u1 === 0) u1 = Math.random();
    const z0 = Math.sqrt(-2.0 * Math.log(u1)) * Math.cos(2.0 * Math.PI * u2);
    let result = Math.floor(mean + z0 * stdDev);
    return Math.max(min, Math.min(max, result));
}

/**
 * Mengubah string format Spintax {a|b|c} menjadi teks acak
 */
function parseSpintax(text) {
    const spintaxRegex = /\{([^{}]+)\}/g;
    while (spintaxRegex.test(text)) {
        text = text.replace(spintaxRegex, (match, choices) => {
            const options = choices.split('|');
            return options[Math.floor(Math.random() * options.length)];
        });
    }
    return text;
}

/**
 * Menyisipkan karakter tak terlihat (Zero-Width) untuk mutasi hash
 */
function injectAntiSpamHash(text) {
    const hiddenChars = ['\u200B', '\u200C', '\u200D', '\uFEFF'];
    let hash = '';
    const hashLength = randomBetween(2, 5);
    for(let i = 0; i < hashLength; i++) {
        hash += hiddenChars[Math.floor(Math.random() * hiddenChars.length)];
    }
    return text + hash;
}

// ============================================================
// PERSISTENSI STATE & WARM-UP AKUN
// ============================================================
let messageQueue = [];
let lastSentPerNumber = new Map();
let sendLog = [];
let isProcessingQueue = false;
let accountStartDate = Date.now(); // Untuk melacak umur akun (Warm-up)

function loadState() {
    try {
        if (fs.existsSync(CONFIG.STATE_FILE)) {
            const raw = JSON.parse(fs.readFileSync(CONFIG.STATE_FILE, 'utf-8'));
            messageQueue = raw.messageQueue || [];
            lastSentPerNumber = new Map(raw.lastSentPerNumber || []);
            sendLog = raw.sendLog || [];
            accountStartDate = raw.accountStartDate || Date.now();
            console.log(`📂 State dipulihkan: ${messageQueue.length} pesan tertunda.`);
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
                accountStartDate
            });
            fs.writeFileSync(CONFIG.STATE_FILE, raw);
        } catch (err) { }
    }, CONFIG.SAVE_INTERVAL_MS);
}
loadState();

// ============================================================
// MANAJEMEN KUOTA & JADWAL OPERASIONAL
// ============================================================

/**
 * Mengecek apakah saat ini berada dalam jam kerja bot
 */
function isWithinWorkingHours() {
    const currentHour = new Date().getHours();
    return currentHour >= CONFIG.WORK_START_HOUR && currentHour < CONFIG.WORK_END_HOUR;
}

/**
 * Menghitung kuota dinamis berdasarkan umur akun (Warm-up Schedule)
 */
function getDynamicQuota() {
    const daysActive = Math.floor((Date.now() - accountStartDate) / (24 * 60 * 60 * 1000));

    if (daysActive < 7) {
        return { maxPerHour: 5, maxPerDay: 20 }; // Minggu 1: Sangat aman
    } else if (daysActive < 14) {
        return { maxPerHour: 15, maxPerDay: 80 }; // Minggu 2: Bertahap naik
    } else if (daysActive < 21) {
        return { maxPerHour: 25, maxPerDay: 150 }; // Minggu 3: Mendekati target
    } else {
        return { maxPerHour: CONFIG.MAX_PER_HOUR_TARGET, maxPerDay: CONFIG.MAX_PER_DAY_TARGET }; // Normal
    }
}

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
    const currentQuota = getDynamicQuota();
    return perHour >= currentQuota.maxPerHour || perDay >= currentQuota.maxPerDay;
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

        // Cek Jam Operasional (Sleep Schedule)
        if (!isWithinWorkingHours()) {
            console.log(`🌙 Di luar jam kerja. Bot beristirahat. Menunggu hingga jam ${CONFIG.WORK_START_HOUR}:00...`);
            await delay(60 * 60 * 1000); // Cek ulang setiap 1 jam
            continue;
        }

        const sinceReady = Date.now() - connectionReadySince;
        if (sinceReady < CONFIG.COOLDOWN_AFTER_CONNECT_MS) await delay(CONFIG.COOLDOWN_AFTER_CONNECT_MS - sinceReady);

        if (isQuotaExceeded()) {
            const currentQuota = getDynamicQuota();
            console.log(`⏸️ Kuota penuh (Batas Saat Ini: ${currentQuota.maxPerHour}/jam, ${currentQuota.maxPerDay}/hari). Menunggu 5 menit...`);
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
            // [BARU] Menggunakan Gaussian Delay untuk jeda antar pesan
            const nextDelay = gaussianDelay(10000, 3000, CONFIG.MIN_DELAY_MS, CONFIG.MAX_DELAY_MS);
            await delay(nextDelay);
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

        // [BARU] 1. Terapkan Spintax Parser
        const dynamicMessage = parseSpintax(message);

        // [BARU] 2. Mutasi Teks: Pengacak String Anti-Spam
        const safeMessage = injectAntiSpamHash(dynamicMessage);

        await sock.presenceSubscribe(resolvedJid).catch(() => {});

        // [BARU] 3. Simulasikan waktu ketik menggunakan Gaussian Delay
        // Rata-rata 3 detik, Standar Deviasi 800ms
        const typingDuration = gaussianDelay(3000, 800, CONFIG.TYPING_MIN_MS, CONFIG.TYPING_MAX_MS);

        await sock.sendPresenceUpdate('composing', resolvedJid);
        await delay(typingDuration);
        await sock.sendPresenceUpdate('paused', resolvedJid);

        await sock.sendMessage(resolvedJid, { text: safeMessage });

        lastSentPerNumber.set(resolvedJid, Date.now());
        sendLog.push(Date.now());
        saveStateDebounced();
        console.log(`✅ Pesan terkirim ke ${resolvedJid} | Pratinjau: "${safeMessage.substring(0, 20)}..."`);
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
        markOnlineOnConnect: false,
        browser: Browsers.macOS('Chrome'),
        syncFullHistory: false,
        generateHighQualityLinkPreview: false
    });

    sock.ev.on('creds.update', saveCreds);

    sock.ev.on('call', async (calls) => {
        if (!CONFIG.AUTO_REJECT_CALLS) return;
        for (const call of calls) {
            if (call.status === 'offer') {
                console.log(`🔕 Menolak panggilan masuk dari ${call.from}`);
                await sock.rejectCall(call.id, call.from).catch(() => {});
            }
        }
    });

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

            const currentQuota = getDynamicQuota();
            const daysActive = Math.floor((Date.now() - accountStartDate) / (24 * 60 * 60 * 1000));
            console.log(`✅ WA Baileys Ready! (Umur Akun: ${daysActive} hari | Kuota Harian Saat Ini: ${currentQuota.maxPerDay})`);
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

        // Pesan sekarang dikirim mentah, Spintax akan dieksekusi tepat sebelum dikirim
        enqueueMessage(`${formattedNumber}@s.whatsapp.net`, message);
        return res.json({ status: true, message: 'Masuk antrean.', queue_position: messageQueue.length });
    } catch (error) {
        return res.status(500).json({ status: false, error: error.message });
    }
});

app.listen(3000, () => console.log('🚀 WA Sidecar berjalan (Versi Ultimate Protect)'));
process.on('SIGINT', () => { saveStateDebounced(); setTimeout(() => process.exit(0), 1000); });
process.on('SIGTERM', () => { saveStateDebounced(); setTimeout(() => process.exit(0), 1000); });
