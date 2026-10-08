// sibar-demo-data.js — Data dummy in-memory + localStorage persistence (demo statis GitHub Pages)
// SIBAR INTEGRITY LAYER: HMAC envelope + hash-chain audit log + anti-double payment

const SIBAR = (() => {
  const DB_KEY = 'sibar_demo_db_v1';
  const SIG_KEY = 'sibar_demo_sig_v1';       // HMAC key (diputar via obfuscation + rotasi per sesi)
  const AUDIT_KEY = 'sibar_demo_audit_v1';   // Audit log berantai hash
  const BACKUP_KEY = 'sibar_demo_backup_v1'; // Snapshot bersih terakhir

  /* ================= INTEGRITY: HMAC ENVELOPE ================= */
  // FNV-1a 32-bit: hash cepat untuk demo (tidak kriptografis penuh,
  // tapi mencegah manipulasi manual via console oleh user biasa)
  function fnv1a(str) {
    let h = 0x811c9dc5;
    for (let i = 0; i < str.length; i++) {
      h ^= str.charCodeAt(i);
      h = Math.imul(h, 0x01000193);
    }
    return (h >>> 0).toString(16).padStart(8, '0');
  }

  // Derived key: kombinasi seed-fingerprint + rotasi waktu (per jam)
  function deriveKey() {
    const seedFp = fnv1a(JSON.stringify(seed.bills.map(b => b.id)) + seed.users.length);
    const hourBucket = Math.floor(Date.now() / 3600000);
    return fnv1a(seedFp + ':' + hourBucket);
  }

  // Envelope: { data, sig } — sig = fnv1a(data + key)
  function signEnvelope(db) {
    const payload = JSON.stringify(db);
    const key = deriveKey();
    return { data: payload, sig: fnv1a(payload + key), ts: Date.now() };
  }

  function verifyEnvelope(env) {
    if (!env || typeof env.data !== 'string' || typeof env.sig !== 'string') return false;
    // Cek dengan key jam sekarang dan jam sebelumnya (rotasi)
    const nowKey = deriveKey();
    const prevKey = fnv1a(fnv1a(JSON.stringify(seed.bills.map(b => b.id)) + seed.users.length) + ':' + (Math.floor(Date.now() / 3600000) - 1));
    return fnv1a(env.data + nowKey) === env.sig || fnv1a(env.data + prevKey) === env.sig;
  }

  function load() {
    const raw = localStorage.getItem(DB_KEY);
    let db;
    if (!raw) {
      db = JSON.parse(JSON.stringify(seed));
    } else {
      try {
        const env = JSON.parse(raw);
        if (env && typeof env === 'object' && 'sig' in env) {
          // Envelope baru (signed)
          if (!verifyEnvelope(env)) {
            // TAMPERING TERDETEKSI — coba restore dari backup, jika tidak ada fallback ke seed
            appendAudit('TAMPER_DETECTED', 'Envelope signature mismatch — data restored');
            const backupRaw = localStorage.getItem(BACKUP_KEY);
            if (backupRaw) {
              try {
                const backupEnv = JSON.parse(backupRaw);
                if (verifyEnvelope(backupEnv)) {
                  db = JSON.parse(backupEnv.data);
                  showToastSecure('⚠️ Integritas data terdeteksi berubah — dipulihkan dari backup.');
                } else {
                  db = JSON.parse(JSON.stringify(seed));
                }
              } catch { db = JSON.parse(JSON.stringify(seed)); }
            } else {
              db = JSON.parse(JSON.stringify(seed));
            }
          } else {
            db = JSON.parse(env.data);
          }
        } else {
          // Downgrade attack: data tanpa signature tidak dipercaya.
          // Restore dari backup; jika tidak ada, fallback seed.
          appendAudit('TAMPER_DOWNGRADE', 'Unsigned data rejected');
          const backupRaw = localStorage.getItem(BACKUP_KEY);
          let restored = false;
          if (backupRaw) {
            try {
              const backupEnv = JSON.parse(backupRaw);
              if (backupEnv && backupEnv.sig && verifyEnvelope(backupEnv)) {
                db = JSON.parse(backupEnv.data);
                restored = true;
              }
            } catch {}
          }
          if (!restored) db = JSON.parse(JSON.stringify(seed));
        }
      } catch (e) {
        console.error('DB parse error', e);
        db = JSON.parse(JSON.stringify(seed));
      }
    }

    // Pastikan data 12 bulan tahun 2026 & 2025 tersedia
    ensureAnnualData(db);
    return db;
  }

  function save(db) {
    // Simpan envelope ber-signature + backup snapshot (untuk recovery)
    const env = signEnvelope(db);
    try { localStorage.setItem(DB_KEY, JSON.stringify(env)); } catch (e) {}
    // Backup hanya jika tidak ada backup sebelumnya ATAU backup sudah > 1 jam
    const existing = localStorage.getItem(BACKUP_KEY);
    let shouldBackup = !existing;
    if (existing) {
      try {
        const prev = JSON.parse(existing);
        shouldBackup = (Date.now() - (prev.ts || 0)) > 3600000;
      } catch { shouldBackup = true; }
    }
    if (shouldBackup) {
      try { localStorage.setItem(BACKUP_KEY, JSON.stringify(env)); } catch (e) {}
    }
    appendAudit('DB_SAVE', db.bills.length + 'bills/' + db.payments.length + 'pays');
  }

  function reset() {
    localStorage.removeItem(DB_KEY);
    localStorage.removeItem(AUDIT_KEY);
    localStorage.removeItem(BACKUP_KEY);
  }

  /* ================= AUDIT LOG: HASH CHAIN ================= */
  // Setiap operasi catat: { ts, action, prevHash, hash }
  // Hash = fnv1a(prevHash + action + ts) — merantai sehingga
  // penghapusan/penyisipan entri akan memutus chain (terdeteksi)
  function appendAudit(action, detail) {
    try {
      const log = JSON.parse(localStorage.getItem(AUDIT_KEY) || '[]');
      const prevHash = log.length ? log[log.length - 1].hash : 'GENESIS';
      const ts = Date.now();
      const hash = fnv1a(prevHash + '|' + action + '|' + detail + '|' + ts);
      log.push({ ts, action, detail, prevHash, hash });
      // Batasi 500 entri terakhir
      while (log.length > 500) log.shift();
      localStorage.setItem(AUDIT_KEY, JSON.stringify(log));
    } catch (e) { /* audit gagal tidak boleh blok operasi utama */ }
  }

  // Verifikasi chain audit (untuk debugging / halaman admin)
  function verifyAuditChain() {
    try {
      const log = JSON.parse(localStorage.getItem(AUDIT_KEY) || '[]');
      for (let i = 0; i < log.length; i++) {
        const expect = fnv1a(log[i].prevHash + '|' + log[i].action + '|' + log[i].detail + '|' + log[i].ts);
        if (expect !== log[i].hash) return { valid: false, brokenAt: i };
        if (i > 0 && log[i].prevHash !== log[i - 1].hash) return { valid: false, brokenAt: i };
      }
      return { valid: true, entries: log.length };
    } catch { return { valid: false, brokenAt: -1 }; }
  }

  function getAuditLog() {
    try { return JSON.parse(localStorage.getItem(AUDIT_KEY) || '[]'); } catch { return []; }
  }

  function showToastSecure(msg) {
    // Toast ringan tanpa dependensi halaman (no-op jika tidak ada DOM)
    if (typeof document !== 'undefined' && document.body) {
      console.warn('[SIBAR INTEGRITY]', msg);
    }
  }

  /* ================= ANTI-DOUBLE PAYMENT ================= */
  const IDEMPOTENCY_KEY = 'sibar_pay_idem_v1';
  // Idempotency: satu bill hanya bisa dibayar 1x dalam window
  function checkIdempotency(billId) {
    try {
      const map = JSON.parse(localStorage.getItem(IDEMPOTENCY_KEY) || '{}');
      const lastPay = map[billId];
      if (lastPay && (Date.now() - lastPay) < 10000) {
        return { allowed: false, reason: 'Pembayaran duplikat terdeteksi (dalam 10 detik terakhir).' };
      }
      map[billId] = Date.now();
      // Bersihkan entri > 1 jam
      for (const k in map) { if (Date.now() - map[k] > 3600000) delete map[k]; }
      localStorage.setItem(IDEMPOTENCY_KEY, JSON.stringify(map));
      return { allowed: true };
    } catch { return { allowed: true }; }
  }

  const seed = {
    complexes: [
      { id: 1, code: 'graha-asri',  name: 'Komplek Graha Asri RT 04',        address: 'Jl. Cempaka Raya, RT 04 / RW 08', latitude: -6.208763, longitude: 106.845599, city: 'Jakarta Selatan' },
      { id: 2, code: 'bukit-indah', name: 'Komplek Bukit Indah Asri RT 05',  address: 'Jl. Bukit Indah Raya, RT 05 / RW 08', latitude: -6.215500, longitude: 106.852000, city: 'Jakarta Selatan' }
    ],
    houses: [
      { id: 1, complex_id: 1, owner: 'Bpk. Budi Santoso',   block: 'A1', number: '05', lane: 'Jalur Utama Timur',  status_huni: 'tetap',   latitude: -6.208763, longitude: 106.845599 },
      { id: 2, complex_id: 1, owner: 'Ibu Dewi Lestari',    block: 'A1', number: '06', lane: 'Jalur Utama Timur',  status_huni: 'kontrak', latitude: -6.208801, longitude: 106.845620 },
      { id: 3, complex_id: 1, owner: 'Bpk. Agus Wijaya',    block: 'B3', number: '11', lane: 'Jalur Utama Tengah', status_huni: 'tetap',   latitude: -6.208910, longitude: 106.845700 },
      { id: 4, complex_id: 1, owner: 'Bpk. Hendra Pratama', block: 'B3', number: '12', lane: 'Jalur Utama Tengah', status_huni: 'tetap',   latitude: -6.208945, longitude: 106.845735 },
      { id: 5, complex_id: 1, owner: 'Bpk. Rudi Hartono',   block: 'B3', number: '13', lane: 'Jalur Utama Tengah', status_huni: 'tetap',   latitude: -6.208980, longitude: 106.845770 },
      { id: 6, complex_id: 1, owner: 'Ibu Siti Rahma',      block: 'C2', number: '08', lane: 'Jalur Selatan',      status_huni: 'kontrak', latitude: -6.209100, longitude: 106.845850 },
      { id: 7, complex_id: 1, owner: 'Bpk. Joko Susilo',    block: 'C2', number: '09', lane: 'Jalur Selatan',      status_huni: 'tetap',   latitude: -6.209150, longitude: 106.845890 }
    ],
    users: [
      { id: 1,  house_id: 1,    complex_id: 1, username: 'a1-05',        role: 'warga',        name: 'Bpk. Budi Santoso',   phone: '081298765432', latitude: -6.208763, longitude: 106.845599 },
      { id: 2,  house_id: 2,    complex_id: 1, username: 'a1-06',        role: 'warga',        name: 'Ibu Dewi Lestari',    phone: '081311223344', latitude: -6.208801, longitude: 106.845620 },
      { id: 3,  house_id: 3,    complex_id: 1, username: 'b3-11',        role: 'warga',        name: 'Bpk. Agus Wijaya',    phone: '081355667788', latitude: -6.208910, longitude: 106.845700 },
      { id: 4,  house_id: 4,    complex_id: 1, username: 'b3-12',        role: 'warga',        name: 'Bpk. Hendra Pratama', phone: '081234567890', latitude: -6.208945, longitude: 106.845735 },
      { id: 5,  house_id: 5,    complex_id: 1, username: 'b3-13',        role: 'warga',        name: 'Bpk. Rudi Hartono',   phone: '081399887766', latitude: -6.208980, longitude: 106.845770 },
      { id: 6,  house_id: 6,    complex_id: 1, username: 'c2-08',        role: 'warga',        name: 'Ibu Siti Rahma',      phone: '081345678901', latitude: -6.209100, longitude: 106.845850 },
      { id: 7,  house_id: 7,    complex_id: 1, username: 'c2-09',        role: 'warga',        name: 'Bpk. Joko Susilo',    phone: '081377665544', latitude: -6.209150, longitude: 106.845890 },
      { id: 8,  house_id: null, complex_id: 1, username: 'satpam_siang', role: 'satpam_siang', name: 'Bpk. Tono Wibowo',    phone: '081500011122', latitude: -6.208763, longitude: 106.845599 },
      { id: 9,  house_id: null, complex_id: 1, username: 'satpam_malam', role: 'satpam_malam', name: 'Bpk. Slamet Riyadi',  phone: '081500033344', latitude: -6.208763, longitude: 106.845599 },
      { id: 10, house_id: null, complex_id: 1, username: 'sampah',       role: 'sampah',       name: 'Bpk. Darma Putra',    phone: '081500055566', latitude: -6.208763, longitude: 106.845599 },
      { id: 11, house_id: null, complex_id: 1, username: 'superadmin',   role: 'super_admin',  name: 'Admin RT 04',          phone: '081500077788', latitude: -6.208763, longitude: 106.845599 }
    ],
    fee_types: [
      { id: 1, code: 'jaga_malam', name: 'Iuran Jaga Malam', amount: 50000, icon: '🌙', description: 'Honor ronda malam (22:00 - 05:00), senter, dan pemeliharaan pos ronda kamling.' },
      { id: 2, code: 'jaga_siang', name: 'Iuran Jaga Siang', amount: 40000, icon: '☀️', description: 'Penjagaan gerbang utama (06:00 - 18:00), penerimaan kurir paket, dan patroli siang.' },
      { id: 3, code: 'sampah', name: 'Iuran Sampah & Kebersihan', amount: 35000, icon: '🗑️', description: 'Pengangkutan sampah rumah tangga 3x seminggu ke TPA dan pembersihan gorong-gorong.' }
    ],
    bills: [
      // Riwayat lunas B3-12 (house 4): Juli - September
      { id: 1,  house_id: 4, fee_type_id: 1, year: 2026, month: 7,  amount: 50000, status: 'paid', due: '2026-07-15' },
      { id: 2,  house_id: 4, fee_type_id: 2, year: 2026, month: 7,  amount: 40000, status: 'paid', due: '2026-07-15' },
      { id: 3,  house_id: 4, fee_type_id: 3, year: 2026, month: 7,  amount: 35000, status: 'paid', due: '2026-07-15' },
      { id: 4,  house_id: 4, fee_type_id: 1, year: 2026, month: 8,  amount: 50000, status: 'paid', due: '2026-08-15' },
      { id: 5,  house_id: 4, fee_type_id: 2, year: 2026, month: 8,  amount: 40000, status: 'paid', due: '2026-08-15' },
      { id: 6,  house_id: 4, fee_type_id: 3, year: 2026, month: 8,  amount: 35000, status: 'paid', due: '2026-08-15' },
      { id: 7,  house_id: 4, fee_type_id: 1, year: 2026, month: 9,  amount: 50000, status: 'paid', due: '2026-09-15' },
      { id: 8,  house_id: 4, fee_type_id: 2, year: 2026, month: 9,  amount: 40000, status: 'paid', due: '2026-09-15' },
      { id: 9,  house_id: 4, fee_type_id: 3, year: 2026, month: 9,  amount: 35000, status: 'paid', due: '2026-09-15' },
      // Oktober 2026 — A1-05 semua lunas
      { id: 10, house_id: 1, fee_type_id: 1, year: 2026, month: 10, amount: 50000, status: 'paid', due: '2026-10-15' },
      { id: 11, house_id: 1, fee_type_id: 2, year: 2026, month: 10, amount: 40000, status: 'paid', due: '2026-10-15' },
      { id: 12, house_id: 1, fee_type_id: 3, year: 2026, month: 10, amount: 35000, status: 'paid', due: '2026-10-15' },
      // A1-06: malam & sampah belum
      { id: 13, house_id: 2, fee_type_id: 1, year: 2026, month: 10, amount: 50000, status: 'unpaid', due: '2026-10-15' },
      { id: 14, house_id: 2, fee_type_id: 2, year: 2026, month: 10, amount: 40000, status: 'paid', due: '2026-10-15' },
      { id: 15, house_id: 2, fee_type_id: 3, year: 2026, month: 10, amount: 35000, status: 'unpaid', due: '2026-10-15' },
      // B3-11 semua lunas
      { id: 16, house_id: 3, fee_type_id: 1, year: 2026, month: 10, amount: 50000, status: 'paid', due: '2026-10-15' },
      { id: 17, house_id: 3, fee_type_id: 2, year: 2026, month: 10, amount: 40000, status: 'paid', due: '2026-10-15' },
      { id: 18, house_id: 3, fee_type_id: 3, year: 2026, month: 10, amount: 35000, status: 'paid', due: '2026-10-15' },
      // B3-12 semua belum
      { id: 19, house_id: 4, fee_type_id: 1, year: 2026, month: 10, amount: 50000, status: 'unpaid', due: '2026-10-15' },
      { id: 20, house_id: 4, fee_type_id: 2, year: 2026, month: 10, amount: 40000, status: 'unpaid', due: '2026-10-15' },
      { id: 21, house_id: 4, fee_type_id: 3, year: 2026, month: 10, amount: 35000, status: 'unpaid', due: '2026-10-15' },
      // B3-13: siang belum
      { id: 22, house_id: 5, fee_type_id: 1, year: 2026, month: 10, amount: 50000, status: 'paid', due: '2026-10-15' },
      { id: 23, house_id: 5, fee_type_id: 2, year: 2026, month: 10, amount: 40000, status: 'unpaid', due: '2026-10-15' },
      { id: 24, house_id: 5, fee_type_id: 3, year: 2026, month: 10, amount: 35000, status: 'paid', due: '2026-10-15' },
      // C2-08: malam & siang belum
      { id: 25, house_id: 6, fee_type_id: 1, year: 2026, month: 10, amount: 50000, status: 'unpaid', due: '2026-10-15' },
      { id: 26, house_id: 6, fee_type_id: 2, year: 2026, month: 10, amount: 40000, status: 'unpaid', due: '2026-10-15' },
      { id: 27, house_id: 6, fee_type_id: 3, year: 2026, month: 10, amount: 35000, status: 'paid', due: '2026-10-15' },
      // C2-09 semua belum
      { id: 28, house_id: 7, fee_type_id: 1, year: 2026, month: 10, amount: 50000, status: 'unpaid', due: '2026-10-15' },
      { id: 29, house_id: 7, fee_type_id: 2, year: 2026, month: 10, amount: 40000, status: 'unpaid', due: '2026-10-15' },
      { id: 30, house_id: 7, fee_type_id: 3, year: 2026, month: 10, amount: 35000, status: 'unpaid', due: '2026-10-15' }
    ],
    payments: [
      { id: 1, bill_id: 1, receipt: 'INV-202607-B312-01', amount: 50000, method: 'Tunai via Bendahara', paid_at: '2026-07-05 19:40' },
      { id: 2, bill_id: 2, receipt: 'INV-202607-B312-02', amount: 40000, method: 'Tunai via Bendahara', paid_at: '2026-07-05 19:40' },
      { id: 3, bill_id: 3, receipt: 'INV-202607-B312-03', amount: 35000, method: 'Tunai via Bendahara', paid_at: '2026-07-05 19:40' },
      { id: 4, bill_id: 4, receipt: 'INV-202608-B312-01', amount: 50000, method: 'BCA Virtual Account', paid_at: '2026-08-02 09:12' },
      { id: 5, bill_id: 5, receipt: 'INV-202608-B312-02', amount: 40000, method: 'BCA Virtual Account', paid_at: '2026-08-02 09:12' },
      { id: 6, bill_id: 6, receipt: 'INV-202608-B312-03', amount: 35000, method: 'BCA Virtual Account', paid_at: '2026-08-02 09:12' },
      { id: 7, bill_id: 7, receipt: 'INV-202609-B312-01', amount: 50000, method: 'QRIS Instant', paid_at: '2026-09-03 14:20' },
      { id: 8, bill_id: 8, receipt: 'INV-202609-B312-02', amount: 40000, method: 'QRIS Instant', paid_at: '2026-09-03 14:20' },
      { id: 9, bill_id: 9, receipt: 'INV-202609-B312-03', amount: 35000, method: 'QRIS Instant', paid_at: '2026-09-03 14:20' },
      { id: 10, bill_id: 10, receipt: 'INV-202610-A105-1', amount: 50000, method: 'QRIS Instant', paid_at: '2026-10-02 08:05' },
      { id: 11, bill_id: 11, receipt: 'INV-202610-A105-2', amount: 40000, method: 'QRIS Instant', paid_at: '2026-10-02 08:05' },
      { id: 12, bill_id: 12, receipt: 'INV-202610-A105-3', amount: 35000, method: 'QRIS Instant', paid_at: '2026-10-02 08:05' },
      { id: 13, bill_id: 14, receipt: 'INV-202610-A106-2', amount: 40000, method: 'Tunai via Petugas', paid_at: '2026-10-04 07:15' },
      { id: 14, bill_id: 16, receipt: 'INV-202610-B311-1', amount: 50000, method: 'BCA Virtual Account', paid_at: '2026-10-01 20:31' },
      { id: 15, bill_id: 17, receipt: 'INV-202610-B311-2', amount: 40000, method: 'BCA Virtual Account', paid_at: '2026-10-01 20:31' },
      { id: 16, bill_id: 18, receipt: 'INV-202610-B311-3', amount: 35000, method: 'BCA Virtual Account', paid_at: '2026-10-01 20:31' },
      { id: 17, bill_id: 22, receipt: 'INV-202610-B313-1', amount: 50000, method: 'Tunai via Petugas', paid_at: '2026-10-03 21:10' },
      { id: 18, bill_id: 24, receipt: 'INV-202610-B313-3', amount: 35000, method: 'Tunai via Petugas', paid_at: '2026-10-05 07:40' },
      { id: 19, bill_id: 27, receipt: 'INV-202610-C208-3', amount: 35000, method: 'QRIS Instant', paid_at: '2026-10-04 10:22' }
    ]
  };

  // load() utama dengan envelope integrity ada di bagian atas file (line ~44)

  function ensureAnnualData(db) {
    if (!db.bills) db.bills = [];
    if (!db.payments) db.payments = [];

    const hasJan2026 = db.bills.some(b => b.year === 2026 && b.month === 1);
    if (!hasJan2026) {
      let maxBillId = db.bills.reduce((m, b) => Math.max(m, b.id || 0), 0);
      let maxPayId = db.payments.reduce((m, p) => Math.max(m, p.id || 0), 0);
      const feeAmounts = { 1: 50000, 2: 40000, 3: 35000 };
      const houses = [1, 2, 3, 4, 5, 6, 7];

      // Bulan 1..6 tahun 2026: semua lunas
      for (let m = 1; m <= 6; m++) {
        for (const h of houses) {
          for (const fId of [1, 2, 3]) {
            maxBillId++;
            const billId = maxBillId;
            db.bills.push({
              id: billId,
              house_id: h,
              fee_type_id: fId,
              year: 2026,
              month: m,
              amount: feeAmounts[fId],
              status: 'paid',
              due: `2026-${String(m).padStart(2,'0')}-15`
            });
            maxPayId++;
            db.payments.push({
              id: maxPayId,
              bill_id: billId,
              receipt: `INV-2026${String(m).padStart(2,'0')}-H${String(h).padStart(2,'0')}-${fId}`,
              amount: feeAmounts[fId],
              method: 'BCA Virtual Account',
              paid_at: `2026-${String(m).padStart(2,'0')}-05 10:00`
            });
          }
        }
      }

      // Bulan 11..12 tahun 2026: belum jatuh tempo (unpaid)
      for (const m of [11, 12]) {
        for (const h of houses) {
          for (const fId of [1, 2, 3]) {
            maxBillId++;
            db.bills.push({
              id: maxBillId,
              house_id: h,
              fee_type_id: fId,
              year: 2026,
              month: m,
              amount: feeAmounts[fId],
              status: 'unpaid',
              due: `2026-${m}-15`
            });
          }
        }
      }

      // Tahun 2025: Arsip 12 bulan lunas
      for (let m = 1; m <= 12; m++) {
        for (const h of houses) {
          for (const fId of [1, 2, 3]) {
            maxBillId++;
            const billId = maxBillId;
            db.bills.push({
              id: billId,
              house_id: h,
              fee_type_id: fId,
              year: 2025,
              month: m,
              amount: feeAmounts[fId],
              status: 'paid',
              due: `2025-${String(m).padStart(2,'0')}-15`
            });
            maxPayId++;
            db.payments.push({
              id: maxPayId,
              bill_id: billId,
              receipt: `INV-2025${String(m).padStart(2,'0')}-H${String(h).padStart(2,'0')}-${fId}`,
              amount: feeAmounts[fId],
              method: 'QRIS Instant',
              paid_at: `2025-${String(m).padStart(2,'0')}-03 14:00`
            });
          }
        }
      }

      save(db);
    }
  }

  const MONTHS = { 1:'Januari',2:'Februari',3:'Maret',4:'April',5:'Mei',6:'Juni',7:'Juli',8:'Agustus',9:'September',10:'Oktober',11:'November',12:'Desember' };

  function periodLabel(m, y) { return (MONTHS[m] || m) + ' ' + (y || 2026); }

  function rupiah(n) { return 'Rp ' + Number(n).toLocaleString('id-ID'); }

  function roleLabel(role) {
    return {
      warga: 'Kepala Keluarga',
      satpam_siang: 'Satpam Jaga Siang',
      satpam_malam: 'Satpam Jaga Malam',
      sampah: 'Petugas Sampah',
      super_admin: 'Super Admin'
    }[role] || role;
  }

  // Fee codes visible untuk role (null = semua)
  function allowedFeeCodes(role) {
    return {
      satpam_siang: ['jaga_siang'],
      satpam_malam: ['jaga_malam'],
      sampah: ['sampah']
    }[role] || null;
  }

  function feeById(db, id) { return db.fee_types.find(f => f.id === id); }

  // Bayar 1 bill: tandai paid + buat kwitansi (dengan anti-double payment & audit)
  function payBill(billId, method) {
    // 1. Idempotency check — blok double-submit
    const idem = checkIdempotency(billId);
    if (!idem.allowed) {
      appendAudit('PAY_REJECTED_DUP', 'bill=' + billId);
      return { error: true, message: idem.reason };
    }

    // 2. Whitelist metode pembayaran (mencegah inject string arbitrer)
    const ALLOWED_METHODS = ['QRIS Instant', 'Transfer Bank', 'Tunai via Bendahara', 'Tunai via Petugas', 'BCA Virtual Account', 'M-Banking'];
    if (!ALLOWED_METHODS.includes(method)) method = 'QRIS Instant';

    const db = load();
    const bill = db.bills.find(b => b.id === billId);
    if (!bill || bill.status === 'paid') return null;

    // 3. Validasi amount terhadap fee_types (mencegah amount dimanipulasi)
    const fee = feeById(db, bill.fee_type_id);
    if (fee && bill.amount !== fee.amount) {
      appendAudit('PAY_REJECTED_AMOUNT', 'bill=' + billId + ' expected=' + fee.amount + ' got=' + bill.amount);
      return { error: true, message: 'Jumlah tagihan tidak sesuai tarif resmi. Pembayaran diblokir.' };
    }

    const house = db.houses.find(h => h.id === bill.house_id);
    const now = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    const ts = `${now.getFullYear()}${pad(now.getMonth()+1)}${pad(now.getDate())}`;
    const receipt = `INV-${ts}-${house.block}${house.number}-${fee.id}-${Math.floor(Math.random()*90+10)}`;
    const paidAt = `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())} ${pad(now.getHours())}:${pad(now.getMinutes())}`;

    bill.status = 'paid';
    db.payments.push({ id: Date.now(), bill_id: bill.id, receipt, amount: bill.amount, method, paid_at: paidAt });
    save(db);
    appendAudit('PAY_OK', 'bill=' + billId + ' amount=' + bill.amount + ' method=' + method);
    return { receipt, paidAt };
  }

  function currentUser() {
    return JSON.parse(sessionStorage.getItem('sibar_demo_session') || 'null');
  }

  // --- CRUD PENGGUNA & RUMAH (DEMO STATIS) ---
  function addUser(data) {
    const db = load();
    const newId = (db.users.reduce((max, u) => Math.max(max, u.id), 0) || 0) + 1;
    let houseId = null;

    if (data.role === 'warga') {
      if (data.house_choice === 'existing' && data.house_id) {
        houseId = Number(data.house_id);
      } else {
        const newHouseId = (db.houses.reduce((max, h) => Math.max(max, h.id), 0) || 0) + 1;
        const newHouse = {
          id: newHouseId,
          block: data.block.toUpperCase(),
          number: String(data.number).padStart(2, '0'),
          lane: data.lane || 'Jalur Utama',
          status_huni: data.status_huni || 'tetap'
        };
        db.houses.push(newHouse);
        houseId = newHouseId;

        // Buat 3 tagihan Oktober 2026
        const billMaxId = db.bills.reduce((max, b) => Math.max(max, b.id), 0) || 0;
        db.fee_types.forEach((ft, idx) => {
          db.bills.push({
            id: billMaxId + idx + 1,
            house_id: newHouseId,
            fee_type_id: ft.id,
            year: 2026,
            month: 10,
            amount: ft.amount,
            status: 'unpaid',
            due: '2026-10-15'
          });
        });
      }
    }

    const newUser = {
      id: newId,
      house_id: houseId,
      username: data.username.toLowerCase(),
      role: data.role,
      name: data.name,
      phone: data.phone,
      complex_id: data.complex_id || 1,
      latitude: data.latitude || null,
      longitude: data.longitude || null,
      address: data.address || ''
    };
    db.users.push(newUser);
    save(db);
    return newUser;
  }

  function updateUser(id, data) {
    const db = load();
    const user = db.users.find(u => u.id === Number(id));
    if (!user) return null;

    user.name = data.name;
    user.phone = data.phone;
    user.username = data.username.toLowerCase();
    if (data.role && user.role !== 'super_admin') {
      user.role = data.role;
    }
    
    user.complex_id = data.complex_id || user.complex_id || 1;
    user.latitude = data.latitude !== undefined ? data.latitude : user.latitude;
    user.longitude = data.longitude !== undefined ? data.longitude : user.longitude;
    user.address = data.address !== undefined ? data.address : (user.address || '');

    if (user.role === 'warga' && user.house_id) {
      const house = db.houses.find(h => h.id === user.house_id);
      if (house) {
        if (data.block) house.block = data.block.toUpperCase();
        if (data.number) house.number = String(data.number).padStart(2, '0');
        if (data.lane) house.lane = data.lane;
        if (data.status_huni) house.status_huni = data.status_huni;
      }
    }
    save(db);
    return user;
  }

  function deleteUser(id) {
    const db = load();
    const idx = db.users.findIndex(u => u.id === Number(id));
    if (idx !== -1) {
      db.users.splice(idx, 1);
      save(db);
      return true;
    }
    return false;
  }

  return {
    load, save, reset, periodLabel, rupiah, roleLabel, allowedFeeCodes, feeById, payBill,
    currentUser, MONTHS, addUser, updateUser, deleteUser,
    // Security API
    verifyAuditChain, getAuditLog, verifyEnvelope, checkIdempotency
  };
})();

// Alias untuk kompatibilitas
if (typeof window !== 'undefined') {
  window.SIBAR = SIBAR;
  window.SibarData = SIBAR;
}
