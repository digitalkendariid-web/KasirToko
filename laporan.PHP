<?php
require 'config.php';
checkLogin();
if ($_SESSION['role'] != 'admin') { header("Location: index.php"); exit; }

// API LAPORAN
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    // 1. READ DATA TRANSAKSI
    if ($action == 'read') {
        $query = "SELECT t.*, u.username as kasir, 
                  GROUP_CONCAT(CONCAT(p.name, '|', ti.qty, '|', ti.price, '|', ti.subtotal) SEPARATOR '||') as list_produk
                  FROM transactions t 
                  JOIN users u ON t.kasir_id = u.id 
                  LEFT JOIN transaction_items ti ON t.id = ti.transaction_id 
                  LEFT JOIN products p ON ti.product_id = p.id 
                  GROUP BY t.id 
                  ORDER BY t.id DESC";
                  
        $res = $conn->query($query);
        $data = [];
        while ($row = $res->fetch_assoc()) { $data[] = $row; }
        echo json_encode($data); exit;
    }

    // 2. AMBIL DETAIL NOTA
    if ($action == 'get_detail') {
        $trx_id = $_POST['id'];
        $header = $conn->query("SELECT t.*, u.username as kasir FROM transactions t JOIN users u ON t.kasir_id = u.id WHERE t.id = $trx_id")->fetch_assoc();
        $items = [];
        $queryItems = "SELECT ti.*, p.name, p.unit FROM transaction_items ti JOIN products p ON ti.product_id = p.id WHERE ti.transaction_id = $trx_id";
        $resItems = $conn->query($queryItems);
        while ($row = $resItems->fetch_assoc()) { $items[] = $row; }
        echo json_encode(['header' => $header, 'items' => $items]); 
        exit;
    }

    // 3. DASHBOARD STATISTIK
    if ($action == 'get_stats') {
        $resTrx = $conn->query("SELECT COUNT(id) as total_trx, SUM(total) as total_pendapatan FROM transactions")->fetch_assoc();
        $queryModal = "SELECT SUM(ti.qty) as total_barang, SUM(ti.qty * p.modal_price) as total_modal FROM transaction_items ti JOIN products p ON ti.product_id = p.id";
        $resModal = $conn->query($queryModal)->fetch_assoc();

        $total_pendapatan = $resTrx['total_pendapatan'] ?? 0;
        $total_modal = $resModal['total_modal'] ?? 0;
        $total_untung = $total_pendapatan - $total_modal;

        echo json_encode([
            'pendapatan' => $total_pendapatan,
            'modal' => $total_modal,
            'untung' => $total_untung,
            'trx' => $resTrx['total_trx'] ?? 0,
            'barang' => $resModal['total_barang'] ?? 0
        ]); 
        exit;
    }

    // 4. UPDATE TRANSAKSI
    if ($action == 'update') {
        $id = $_POST['id']; $total = $_POST['total']; $pay = $_POST['pay'];
        $change = $pay - $total;
        $stmt = $conn->prepare("UPDATE transactions SET total=?, pay=?, change_amount=? WHERE id=?");
        $stmt->bind_param("dddi", $total, $pay, $change, $id);
        if ($stmt->execute()) echo json_encode(['status' => 'success']); else echo json_encode(['status' => 'error']);
        exit;
    }

    // 5. HAPUS TRANSAKSI (RESTORASI STOK & UANG LACI & HAPUS LOG)
    if ($action == 'delete') {
        $id = $_POST['id'];
        
        $conn->begin_transaction();
        try {
            // A. Ambil info transaksi sebelum dihapus
            $trxInfo = $conn->query("SELECT * FROM transactions WHERE id = $id")->fetch_assoc();
            
            // B. Kembalikan Stok Produk
            $items = $conn->query("SELECT product_id, qty FROM transaction_items WHERE transaction_id = $id");
            while ($item = $items->fetch_assoc()) {
                $stmtStock = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
                $stmtStock->bind_param("ii", $item['qty'], $item['product_id']);
                $stmtStock->execute();
            }

            // C. Kembalikan Uang Laci & Hapus Log Laci (Jika transaksi Cash)
            if ($trxInfo['payment_method'] === 'Cash') {
                // Kembalikan saldo laci (Laci - Total + Kembalian)
                $laci_revert = "UPDATE settings SET setting_value = setting_value - ? + ? WHERE setting_key = 'uang_laci'";
                $stmtLaci = $conn->prepare($laci_revert);
                $stmtLaci->bind_param("dd", $trxInfo['total'], $trxInfo['change_amount']);
                $stmtLaci->execute();

                // HAPUS log mutasi yang terikat dengan transaksi ini
                $stmtDelLog = $conn->prepare("DELETE FROM cash_drawer_logs WHERE transaction_id = ?");
                $stmtDelLog->bind_param("i", $id);
                $stmtDelLog->execute();

                // Tambahkan log baru sebagai penanda penghapusan (Opsional, agar audit trail rapi)
                $logDelete = "Hapus Laporan Nota " . $trxInfo['invoice_no'];
                $stmtLogDelete = $conn->prepare("INSERT INTO cash_drawer_logs (transaction_id, type, amount, description) VALUES (NULL, 'OUT', ?, ?)");
                $stmtLogDelete->bind_param("ds", $trxInfo['total'], $logDelete);
                $stmtLogDelete->execute();
            }

            // D. Hapus Transaksi (Detail items terhapus otomatis via CASCADE)
            $stmt = $conn->prepare("DELETE FROM transactions WHERE id=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();

            $conn->commit();
            echo json_encode(['status' => 'success']); 
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan - RuangGuru Market</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style> 
        body { font-family: 'Inter', sans-serif; background-color: #f3f4f6; } 
        ::-webkit-scrollbar { width: 6px; } 
        .card-gradient-1 { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        .card-gradient-2 { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        .card-gradient-3 { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .card-gradient-4 { background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); }
    </style>
</head>
<body class="h-screen overflow-hidden flex text-gray-800">
    <!-- Sidebar -->
    <aside class="w-64 bg-white border-r border-gray-200 flex flex-col hidden md:flex z-20">
        <div class="h-16 flex items-center px-6 border-b border-gray-100">
            <i class="fa-solid fa-store text-blue-600 text-xl mr-3"></i>
            <span class="font-bold text-lg text-gray-800">RuangGuru</span>
        </div>
        <nav class="flex-1 p-4 space-y-2">
            <a href="dashboard.php" class="flex items-center px-4 py-3 text-gray-600 hover:bg-gray-50 rounded-xl font-medium transition"><i class="fa-solid fa-chart-pie w-6"></i> Dashboard</a>
            <a href="index.php" class="flex items-center px-4 py-3 text-gray-600 hover:bg-gray-50 rounded-xl font-medium transition"><i class="fa-solid fa-cash-register w-6"></i> Kasir</a>
            <a href="laporan.php" class="flex items-center px-4 py-3 bg-blue-50 text-blue-600 rounded-xl font-medium transition"><i class="fa-solid fa-chart-line w-6"></i> Laporan</a>
            <a href="produk.php" class="flex items-center px-4 py-3 text-gray-600 hover:bg-gray-50 rounded-xl font-medium transition"><i class="fa-solid fa-box w-6"></i> Produk</a>
        </nav>
        <div class="p-4 border-t border-gray-100">
            <div class="flex items-center mb-4 px-2">
                <div class="w-9 h-9 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold mr-3 uppercase">
                    <?= substr($_SESSION['username'], 0, 1) ?>
                </div>
                <div>
                    <p class="text-sm font-semibold text-gray-800 capitalize"><?= $_SESSION['username'] ?></p>
                    <p class="text-xs text-gray-500 capitalize"><?= $_SESSION['role'] ?></p>
                </div>
            </div>
            <a href="?logout=1" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-red-50 rounded-lg transition font-medium block"><i class="fa-solid fa-sign-out-alt mr-2"></i> Logout</a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 p-4 md:p-6 overflow-y-auto">
        
        <!-- HEADER -->
        <div class="flex justify-between items-center mb-6">
            <div>
                <h2 class="text-2xl font-bold text-gray-800">Dashboard Analitik</h2>
                <p class="text-sm text-gray-500 mt-1">Ringkasan performa penjualan toko Anda</p>
            </div>
            <button onclick="loadAll()" class="bg-white border px-4 py-2 rounded-lg shadow-sm text-sm font-medium hover:bg-gray-50 transition flex items-center text-gray-700">
                <i class="fa-solid fa-rotate-right mr-2"></i> Refresh Data
            </button>
        </div>

        <!-- DASHBOARD CARDS -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <div class="card-gradient-1 rounded-2xl p-6 text-white shadow-lg relative overflow-hidden">
                <i class="fa-solid fa-wallet absolute -right-4 -bottom-4 text-7xl opacity-20"></i>
                <p class="text-sm font-medium opacity-90 mb-1">Total Pendapatan</p>
                <h3 class="text-3xl font-bold" id="statPendapatan">Rp 0</h3>
                <p class="text-xs mt-3 opacity-80"><i class="fa-solid fa-receipt mr-1"></i> <span id="statTrx">0</span> Transaksi</p>
            </div>
            <div class="card-gradient-2 rounded-2xl p-6 text-white shadow-lg relative overflow-hidden">
                <i class="fa-solid fa-boxes-stacked absolute -right-4 -bottom-4 text-7xl opacity-20"></i>
                <p class="text-sm font-medium opacity-90 mb-1">Total Modal (HPP)</p>
                <h3 class="text-3xl font-bold" id="statModal">Rp 0</h3>
                <p class="text-xs mt-3 opacity-80"><i class="fa-solid fa-cubes mr-1"></i> <span id="statBarang">0</span> Barang Terjual</p>
            </div>
            <div class="card-gradient-3 rounded-2xl p-6 text-white shadow-lg relative overflow-hidden">
                <i class="fa-solid fa-money-bill-trend-up absolute -right-4 -bottom-4 text-7xl opacity-20"></i>
                <p class="text-sm font-medium opacity-90 mb-1">Total Keuntungan</p>
                <h3 class="text-3xl font-bold" id="statUntung">Rp 0</h3>
                <p class="text-xs mt-3 opacity-80"><i class="fa-solid fa-arrow-trend-up mr-1"></i> Laba Bersih</p>
            </div>
            <div class="card-gradient-4 rounded-2xl p-6 text-white shadow-lg relative overflow-hidden">
                <i class="fa-solid fa-calendar-check absolute -right-4 -bottom-4 text-7xl opacity-20"></i>
                <p class="text-sm font-medium opacity-90 mb-1">Status Toko</p>
                <h3 class="text-3xl font-bold">Aktif</h3>
                <p class="text-xs mt-3 opacity-80"><i class="fa-solid fa-check-circle mr-1"></i> Sistem berjalan normal</p>
            </div>
        </div>

        <!-- TABEL LAPORAN -->
        <div class="flex justify-between items-end mb-4">
            <h3 class="text-lg font-bold text-gray-800">Riwayat Transaksi</h3>
            <p class="text-xs text-gray-500"><i class="fa-solid fa-circle-info mr-1"></i> Klik Invoice untuk melihat detail nota</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse whitespace-nowrap">
                    <thead class="bg-gray-50 text-gray-600 text-xs uppercase tracking-wider">
                        <tr>
                            <th class="p-4 border-b font-semibold">Invoice</th>
                            <th class="p-4 border-b font-semibold min-w-[300px]">Produk Dibeli</th>
                            <th class="p-4 border-b font-semibold">Kasir</th>
                            <th class="p-4 border-b font-semibold">Total</th>
                            <th class="p-4 border-b font-semibold">Waktu</th>
                            <th class="p-4 border-b font-semibold text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="laporanBody" class="text-sm divide-y divide-gray-100"></tbody>
                </table>
            </div>
        </div>
    </main>

<script>
    const Toast = Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false, timer: 2000 });
    let laporanData = [];

    const formatRp = (angka) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(angka);

    // 1. LOAD STATISTIK
    function loadStats() {
        const formData = new FormData(); formData.append('action', 'get_stats');
        fetch('laporan.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            document.getElementById('statPendapatan').innerText = formatRp(data.pendapatan);
            document.getElementById('statModal').innerText = formatRp(data.modal);
            document.getElementById('statUntung').innerText = formatRp(data.untung);
            document.getElementById('statTrx').innerText = data.trx;
            document.getElementById('statBarang').innerText = data.barang;
        });
    }

    // 2. LOAD TABEL TRANSAKSI
    function loadData() {
        const formData = new FormData(); formData.append('action', 'read');
        fetch('laporan.php', { method: 'POST', body: formData }).then(res => res.json()).then(data => {
            laporanData = data;
            const tbody = document.getElementById('laporanBody');
            tbody.innerHTML = '';
            if (data.length === 0) { 
                tbody.innerHTML = '<tr><td colspan="6" class="p-8 text-center text-gray-500"><i class="fa-solid fa-inbox text-4xl mb-2 block text-gray-300"></i> Belum ada transaksi.</td></tr>'; 
                return; 
            }
            data.forEach(t => {
                let produkHTML = '<span class="text-gray-400 italic text-xs">-</span>';
                if (t.list_produk) {
                    const items = t.list_produk.split('||');
                    produkHTML = '<div class="space-y-1.5">';
                    items.forEach(item => {
                        const [nama, qty, harga, subtotal] = item.split('|');
                        produkHTML += `
                            <div class="flex justify-between items-start gap-3 text-xs leading-tight">
                                <div class="flex items-start text-gray-700">
                                    <span class="text-blue-500 mr-1.5 mt-0.5">•</span>
                                    <div>
                                        <span class="font-medium">${nama}</span>
                                        <span class="text-[10px] text-gray-500 ml-1">(${qty}x)</span>
                                    </div>
                                </div>
                                <div class="font-semibold text-gray-800 shrink-0">
                                    ${formatRp(subtotal)}
                                </div>
                            </div>`;
                    });
                    produkHTML += '</div>';
                }
                
                tbody.innerHTML += `
                    <tr class="hover:bg-blue-50/50 transition duration-150 align-top">
                        <td class="p-4 font-medium">
                            <button onclick="lihatNota(${t.id})" class="text-blue-600 hover:text-blue-800 hover:underline text-xs flex items-center mt-0.5">
                                <i class="fa-solid fa-receipt mr-1"></i> ${t.invoice_no}
                            </button>
                        </td>
                        <td class="p-4">${produkHTML}</td>
                        <td class="p-4 capitalize font-medium text-gray-700 text-xs mt-0.5">${t.kasir}</td>
                        <td class="p-4 font-bold text-blue-600 text-sm mt-0.5">${formatRp(t.total)}</td>
                        <td class="p-4 text-gray-500 text-xs mt-0.5">${new Date(t.created_at).toLocaleString('id-ID')}</td>
                        <td class="p-4 text-center mt-0.5">
                            <button onclick="editData(${t.id})" class="text-yellow-500 hover:text-yellow-700 mx-1 p-2 hover:bg-yellow-50 rounded-lg transition" title="Edit"><i class="fa-solid fa-pen-to-square"></i></button>
                            <button onclick="hapusData(${t.id})" class="text-red-500 hover:text-red-700 mx-1 p-2 hover:bg-red-50 rounded-lg transition" title="Hapus"><i class="fa-solid fa-trash"></i></button>
                        </td>
                    </tr>`;
            });
        });
    }

    function loadAll() {
        loadStats();
        loadData();
        Toast.fire({icon: 'success', title: 'Data berhasil di-refresh'});
    }

    // 3. FUNGSI LIHAT NOTA
    function lihatNota(id) {
        Swal.fire({ title: 'Memuat Nota...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });

        const formData = new FormData(); 
        formData.append('action', 'get_detail'); 
        formData.append('id', id);

        fetch('laporan.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            const t = data.header;
            const items = data.items;

            let listBarang = '';
            items.forEach(item => {
                listBarang += `
                    <div class="flex justify-between items-start py-2 border-b border-dashed border-gray-300 text-sm">
                        <div class="flex-1">
                            <p class="font-semibold text-gray-800">${item.name}</p>
                            <p class="text-xs text-gray-500">${item.qty} ${item.unit} x ${formatRp(item.price)}</p>
                        </div>
                        <div class="font-medium text-gray-800">${formatRp(item.subtotal)}</div>
                    </div>
                `;
            });

            Swal.fire({
                title: 'Detail Nota Belanja',
                html: `
                    <div class="text-left font-mono text-sm mt-2 bg-gray-50 p-4 rounded-lg border border-gray-200">
                        <div class="text-center border-b border-dashed border-gray-400 pb-3 mb-3">
                            <h3 class="font-bold text-lg text-gray-800">RuangGuru Market</h3>
                            <p class="text-xs text-gray-500">${new Date(t.created_at).toLocaleString('id-ID')}</p>
                            <p class="text-xs text-gray-500 mt-1">Invoice: <b>${t.invoice_no}</b></p>
                            <p class="text-xs text-gray-500">Kasir: <span class="capitalize">${t.kasir}</span></p>
                        </div>
                        
                        <div class="max-h-60 overflow-y-auto mb-3 pr-1">
                            ${listBarang}
                        </div>

                        <div class="border-t border-gray-400 pt-3 space-y-1">
                            <div class="flex justify-between font-bold text-base">
                                <span>TOTAL</span>
                                <span>${formatRp(t.total)}</span>
                            </div>
                            <div class="flex justify-between text-gray-600">
                                <span>Bayar</span>
                                <span>${formatRp(t.pay)}</span>
                            </div>
                            <div class="flex justify-between text-gray-600">
                                <span>Kembali</span>
                                <span>${formatRp(t.change_amount)}</span>
                            </div>
                        </div>
                        
                        <div class="text-center text-xs text-gray-400 mt-4 border-t border-dashed border-gray-400 pt-3">
                            Terima Kasih Telah Berbelanja
                        </div>
                    </div>
                `,
                width: '400px',
                showCloseButton: true,
                confirmButtonText: '<i class="fa-solid fa-print"></i> Cetak',
                confirmButtonColor: '#2563eb',
                showCancelButton: true,
                cancelButtonText: 'Tutup',
                cancelButtonColor: '#6b7280'
            }).then((result) => {
                if (result.isConfirmed) { window.print(); }
            });
        });
    }

    // 4. EDIT TRANSAKSI
    function editData(id) {
        const data = laporanData.find(t => t.id == id);
        Swal.fire({
            title: 'Edit Transaksi',
            html: `
                <div class="text-left space-y-3 mt-4">
                    <div><label class="text-sm font-medium text-gray-700">Total Belanja (Rp)</label><input id="swal-total" type="number" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 outline-none" value="${data.total}"></div>
                    <div><label class="text-sm font-medium text-gray-700">Uang Bayar (Rp)</label><input id="swal-pay" type="number" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 outline-none" value="${data.pay}"></div>
                </div>`,
            showCancelButton: true, confirmButtonText: 'Simpan Perubahan', confirmButtonColor: '#2563eb',
            preConfirm: () => {
                return { total: document.getElementById('swal-total').value, pay: document.getElementById('swal-pay').value }
            }
        }).then((result) => {
            if (result.isConfirmed) {
                const formData = new FormData();
                formData.append('action', 'update'); formData.append('id', id);
                formData.append('total', result.value.total); formData.append('pay', result.value.pay);
                fetch('laporan.php', { method: 'POST', body: formData }).then(res => res.json()).then(res => {
                    if (res.status === 'success') { Toast.fire({icon: 'success', title: 'Data diperbarui'}); loadAll(); }
                    else Swal.fire('Gagal', 'Terjadi kesalahan', 'error');
                });
            }
        });
    }

    // 5. HAPUS TRANSAKSI (SINKRONISASI STOK & LACI)
    function hapusData(id) {
        Swal.fire({
            title: 'Hapus Laporan?', 
            text: "Data transaksi akan dihapus, stok barang DAN riwayat uang laci akan otomatis dikembalikan/disesuaikan!", 
            icon: 'warning',
            showCancelButton: true, 
            confirmButtonColor: '#ef4444', 
            confirmButtonText: 'Ya, Hapus & Kembalikan',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({ title: 'Memproses...', text: 'Mengembalikan stok dan uang laci', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
                const formData = new FormData(); formData.append('action', 'delete'); formData.append('id', id);
                fetch('laporan.php', { method: 'POST', body: formData }).then(res => res.json()).then(res => {
                    if (res.status === 'success') { 
                        Toast.fire({icon: 'success', title: 'Transaksi & riwayat laci dihapus'}); 
                        loadAll(); 
                    } else {
                        Swal.fire('Gagal', res.message || 'Terjadi kesalahan', 'error');
                    }
                });
            }
        });
    }

    // Jalankan saat halaman dimuat
    loadStats();
    loadData();
</script>
</body>
</html>