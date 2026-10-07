// sibar-demo-data.js — Data dummy in-memory + localStorage persistence (demo statis GitHub Pages)

const SIBAR = (() => {
  const DB_KEY = 'sibar_demo_db_v1';

  const seed = {
    houses: [
      { id: 1, block: 'A1', number: '05', lane: 'Jalur Utama Timur',  status_huni: 'tetap' },
      { id: 2, block: 'A1', number: '06', lane: 'Jalur Utama Timur',  status_huni: 'kontrak' },
      { id: 3, block: 'B3', number: '11', lane: 'Jalur Utama Tengah', status_huni: 'tetap' },
      { id: 4, block: 'B3', number: '12', lane: 'Jalur Utama Tengah', status_huni: 'tetap' },
      { id: 5, block: 'B3', number: '13', lane: 'Jalur Utama Tengah', status_huni: 'tetap' },
      { id: 6, block: 'C2', number: '08', lane: 'Jalur Selatan',      status_huni: 'kontrak' },
      { id: 7, block: 'C2', number: '09', lane: 'Jalur Selatan',      status_huni: 'tetap' }
    ],
    users: [
      { id: 1, house_id: 1, username: 'a1-05', role: 'warga', name: 'Bpk. Budi Santoso',   phone: '081298765432' },
      { id: 2, house_id: 2, username: 'a1-06', role: 'warga', name: 'Ibu Dewi Lestari',    phone: '081311223344' },
      { id: 3, house_id: 3, username: 'b3-11', role: 'warga', name: 'Bpk. Agus Wijaya',    phone: '081355667788' },
      { id: 4, house_id: 4, username: 'b3-12', role: 'warga', name: 'Bpk. Hendra Pratama', phone: '081234567890' },
      { id: 5, house_id: 5, username: 'b3-13', role: 'warga', name: 'Bpk. Rudi Hartono',   phone: '081399887766' },
      { id: 6, house_id: 6, username: 'c2-08', role: 'warga', name: 'Ibu Siti Rahma',      phone: '081345678901' },
      { id: 7, house_id: 7, username: 'c2-09', role: 'warga', name: 'Bpk. Joko Susilo',    phone: '081377665544' },
      { id: 8, house_id: null, username: 'satpam_siang', role: 'satpam_siang', name: 'Bpk. Tono Wibowo',   phone: '081500011122' },
      { id: 9, house_id: null, username: 'satpam_malam', role: 'satpam_malam', name: 'Bpk. Slamet Riyadi', phone: '081500033344' },
      { id: 10, house_id: null, username: 'sampah', role: 'sampah', name: 'Bpk. Darma Putra', phone: '081500055566' },
      { id: 11, house_id: null, username: 'superadmin', role: 'super_admin', name: 'Admin RT 04', phone: '081500077788' }
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

  function load() {
    const raw = localStorage.getItem(DB_KEY);
    if (!raw) {
      localStorage.setItem(DB_KEY, JSON.stringify(seed));
      return JSON.parse(JSON.stringify(seed));
    }
    return JSON.parse(raw);
  }

  function save(db) {
    localStorage.setItem(DB_KEY, JSON.stringify(db));
  }

  function reset() {
    localStorage.removeItem(DB_KEY);
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

  // Bayar 1 bill: tandai paid + buat kwitansi
  function payBill(billId, method) {
    const db = load();
    const bill = db.bills.find(b => b.id === billId);
    if (!bill || bill.status === 'paid') return null;

    const house = db.houses.find(h => h.id === bill.house_id);
    const fee = feeById(db, bill.fee_type_id);
    const now = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    const ts = `${now.getFullYear()}${pad(now.getMonth()+1)}${pad(now.getDate())}`;
    const receipt = `INV-${ts}-${house.block}${house.number}-${fee.id}-${Math.floor(Math.random()*90+10)}`;
    const paidAt = `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())} ${pad(now.getHours())}:${pad(now.getMinutes())}`;

    bill.status = 'paid';
    db.payments.push({ id: Date.now(), bill_id: bill.id, receipt, amount: bill.amount, method, paid_at: paidAt });
    save(db);
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
      phone: data.phone
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
    currentUser, MONTHS, addUser, updateUser, deleteUser
  };
})();
