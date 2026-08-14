const { default: makeWASocket, useMultiFileAuthState, DisconnectReason } = require('@whiskeysockets/baileys');
const qrcode = require('qrcode-terminal');
const express = require('express');
const pino = require('pino');

const app = express();
app.use(express.json());

let sock;

async function connectToWhatsApp() {
    // Menyimpan kredensial sesi ke folder 'auth_info_baileys'
    const { state, saveCreds } = await useMultiFileAuthState('auth_info_baileys');

    sock = makeWASocket({
        logger: pino({ level: 'silent' }), // Matikan log bawaan yang terlalu ramai
        auth: state,
        printQRInTerminal: false
    });

    // Simpan data sesi setiap kali ada perubahan/kredensial baru
    sock.ev.on('creds.update', saveCreds);

    // Pantau status koneksi
    sock.ev.on('connection.update', (update) => {
        const { connection, lastDisconnect, qr } = update;

        if (qr) {
            console.log('\n=================== SCAN QR CODE DI BAWAH INI ===================');
            qrcode.generate(qr, { small: true });
            console.log('=================================================================\n');
        }

        if (connection === 'close') {
            const shouldReconnect = (lastDisconnect?.error)?.output?.statusCode !== DisconnectReason.loggedOut;
            console.log('Koneksi terputus. Mencoba terhubung kembali...', shouldReconnect);
            if (shouldReconnect) {
                connectToWhatsApp();
            }
        } else if (connection === 'open') {
            console.log('✅ WA Baileys Berhasil Terhubung (Client is Ready)!');
        }
    });
}

// Jalankan koneksi WA
connectToWhatsApp();

/**
 * Endpoint HTTP untuk dipanggil oleh Laravel
 * Format Request JSON: { "number": "08123456789", "message": "Pesan notifikasi" }
 */
app.post('/send-message', async (req, res) => {
    try {
        const { number, message } = req.body;

        if (!number || !message) {
            return res.status(400).json({ status: false, error: 'Nomor dan pesan wajib diisi!' });
        }

        // Format nomor HP ke standar WhatsApp (misal: 08123 -> 628123)
        let formattedNumber = number.replace(/[^0-9]/g, '');
        if (formattedNumber.startsWith('0')) {
            formattedNumber = '62' + formattedNumber.substring(1);
        }
        
        // Format JID Baileys: 628123456789@s.whatsapp.net
        const jid = `${formattedNumber}@s.whatsapp.net`;

        // Kirim pesan
        await sock.sendMessage(jid, { text: message });

        return res.json({ status: true, message: 'Pesan berhasil dikirim!' });
    } catch (error) {
        console.error('Error sending message:', error);
        return res.status(500).json({ status: false, error: error.message });
    }
});

// Jalankan Server HTTP di Port 3000
app.listen(3000, () => {
    console.log('🚀 Baileys WA Sidecar berjalan di port 3000');
});
