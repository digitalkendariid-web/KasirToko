<?php
require 'config.php';
checkLogin();
if ($_SESSION['role'] != 'admin') { header("Location: index.php"); exit; }

// API DASHBOARD
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];
    $filter = $_POST['filter'] ?? 'today'; // today, yesterday, month, all

    // Tentukan Query Tanggal Berdasarkan Filter
    $dateQuery = "";
    if ($filter == 'today') { $dateQuery = "AND DATE(t.created_at) = CURDATE()"; } 
    elseif ($filter == 'yesterday') { $dateQuery = "AND DATE(t.created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)"; } 
    elseif ($filter == 'month') { $dateQuery = "AND MONTH(t.created_at) = MONTH(CURDATE()) AND YEAR(t.created_at) = YEAR(CURDATE())"; }

    // 1. DATA KARTU STATISTIK
    if ($action == 'get_stats') {
        $trx = $conn->query("SELECT COUNT(id) as total_trx, SUM(total) as pendapatan FROM transactions t WHERE 1=1 $dateQuery")->fetch_assoc();
        
        $modalQuery = "SELECT SUM(ti.qty) as barang, SUM(ti.qty * p.modal_price) as modal 
                       FROM transaction_items ti 
                       JOIN transactions t ON ti.transaction_id = t.id 
                       JOIN products p ON ti.product_id = p.id 
                       WHERE 1=1 $dateQuery";
        $modal = $conn->query($modalQuery)->fetch_assoc();

        $pendapatan = $trx['pendapatan'] ?? 0;
        $modal = $modal['modal'] ?? 0;

        echo json_encode([
            'pendapatan' => $pendapatan,
            'modal' => $modal,
            'untung' => $pendapatan - $modal,
            'trx' => $trx['total_trx'] ?? 0,
            'barang' => $modal['barang'] ?? 0
        ]); 
        exit;
    }

    // 2. TOP 5 PRODUK TERLARIS
    if ($action == 'get_top_products') {
        $query = "SELECT p.name, SUM(ti.qty) as total_qty, SUM(ti.subtotal) as total_pendapatan 
                  FROM transaction_items ti 
                  JOIN products p ON ti.product_id = p.id 
                  JOIN transactions t ON ti.transaction_id = t.id 
                  WHERE 1=1 $dateQuery 
                  GROUP BY p.id ORDER BY total_qty DESC LIMIT 5";
        $res = $conn->query($query);
        $data = [];
        while ($row = $res->fetch_assoc()) { $data[] = $row; }
        echo json_encode($data); exit;
    }

    // 3. GRAFIK 7 HARI TERAKHIR
    if ($action == 'get_chart') {
        $data = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $displayDate = date('d M', strtotime("-$i days"));
            $res = $conn->query("SELECT SUM(total) as total FROM transactions WHERE DATE(created_at) = '$date'")->fetch_assoc();
            $data[] = ['label' => $displayDate, 'total' => $res['total'] ?? 0];
        }
        echo json_encode($data); exit;
    }

    // 4. TRANSAKSI TERBARU (5 Terakhir)
    if ($action == 'get_recent_trx') {
        $res = $conn->query("SELECT t.id, t.invoice_no, t.total, t.created_at, u.username FROM transactions t JOIN users u ON t.kasir_id = u.id ORDER BY t.id DESC LIMIT 5");
        $data = [];
        while ($row = $res->fetch_assoc()) { $data[] = $row; }
        echo json_encode($data); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - RuangGuru Market</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Chart.js untuk Grafik -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style> 
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; } 
        ::-webkit-scrollbar { width: 6px; } 
        .card-gradient-1 { background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); }
        .card-gradient-2 { background: linear-gradient(135deg, #f59e0b 0%, #b45309 100%); }
        .card-gradient-3 { background: linear-gradient(135deg, #10b981 0%, #047857 100%); }
        .card-gradient-4 { background: linear-gradient(135deg, #8b5cf6 0%, #5b21b6 100%); }
        .glass { background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(10px); }
    </style>
</head>
<body class="h-screen overflow-hidden flex text-gray-800">
    
    <!-- Sidebar -->
    <aside class="w-64 bg-white border-r border-gray-200 flex flex-col hidden md:flex z-20 shadow-sm">
        <div class="h-16 flex items-center px-6 border-b border-gray-100">
            <i class="fa-solid fa-store text-blue-600 text-xl mr-3"></i>
            <span class="font-bold text-lg text-gray-800">RuangGuru</span>
        </div>
        <nav class="flex-1 p-4 space-y-2">
            <a href="index.php" class="flex items-center px-4 py-3 text-gray-600 hover:bg-gray-50 rounded-xl font-medium transition"><i class="fa-solid fa-cash-register w-6"></i> Kasir</a>
            <a href="laporan.php" class="flex items-center px-4 py-3 text-gray-600 hover:bg-gray-50 rounded-xl font-medium transition"><i class="fa-solid fa-file-invoice-dollar w-6"></i> Laporan</a>
            <a href="produk.php" class="flex items-center px-4 py-3 text-gray-600 hover:bg-gray-50 rounded-xl font-medium transition"><i class="fa-solid fa-box w-6"></i> Produk</a>
            <a href="dashboard.php" class="flex items-center px-4 py-3 bg-blue-50 text-blue-600 rounded-xl font-medium transition"><i class="fa-solid fa-chart-pie w-6"></i> Dashboard</a>
        </nav>
        <div class="p-4 border-t border-gray-100">
            <a href="?logout=1" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-red-50 rounded-lg transition font-medium block"><i class="fa-solid fa-sign-out-alt mr-2"></i> Logout</a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 p-4 md:p-8 overflow-y-auto">
        
        <!-- Header & Filter -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">Ringkasan Bisnis</h1>
                <p class="text-gray-500 mt-1">Pantau performa toko Anda secara real-time</p>
            </div>
            
            <div class="flex bg-white rounded-xl shadow-sm border p-1">
                <button onclick="changeFilter('today')" id="btn-today" class="px-4 py-2 text-sm font-medium rounded-lg bg-blue-600 text-white transition">Hari Ini</button>
                <button onclick="changeFilter('yesterday')" id="btn-yesterday" class="px-4 py-2 text-sm font-medium rounded-lg text-gray-600 hover:bg-gray-100 transition">Kemarin</button>
                <button onclick="changeFilter('month')" id="btn-month" class="px-4 py-2 text-sm font-medium rounded-lg text-gray-600 hover:bg-gray-100 transition">Bulan Ini</button>
                <button onclick="changeFilter('all')" id="btn-all" class="px-4 py-2 text-sm font-medium rounded-lg text-gray-600 hover:bg-gray-100 transition">Semua</button>
            </div>
        </div>

        <!-- KARTU METRIK UTAMA -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="card-gradient-1 rounded-2xl p-6 text-white shadow-xl relative overflow-hidden transform hover:-translate-y-1 transition duration-300">
                <i class="fa-solid fa-money-bill-wave absolute -right-4 -bottom-4 text-8xl opacity-20"></i>
                <p class="text-sm font-medium opacity-90 mb-1">Total Pendapatan</p>
                <h3 class="text-3xl font-bold" id="valPendapatan">Rp 0</h3>
                <div class="mt-4 flex items-center text-xs bg-white/20 w-max px-2 py-1 rounded-md"><i class="fa-solid fa-arrow-up mr-1"></i> Omset Kotor</div>
            </div>

            <div class="card-gradient-2 rounded-2xl p-6 text-white shadow-xl relative overflow-hidden transform hover:-translate-y-1 transition duration-300">
                <i class="fa-solid fa-boxes-stacked absolute -right-4 -bottom-4 text-8xl opacity-20"></i>
                <p class="text-sm font-medium opacity-90 mb-1">Total Modal (HPP)</p>
                <h3 class="text-3xl font-bold" id="valModal">Rp 0</h3>
                <div class="mt-4 flex items-center text-xs bg-white/20 w-max px-2 py-1 rounded-md"><i class="fa-solid fa-cubes mr-1"></i> <span id="valBarang">0</span> Barang Terjual</div>
            </div>

            <div class="card-gradient-3 rounded-2xl p-6 text-white shadow-xl relative overflow-hidden transform hover:-translate-y-1 transition duration-300">
                <i class="fa-solid fa-hand-holding-dollar absolute -right-4 -bottom-4 text-8xl opacity-20"></i>
                <p class="text-sm font-medium opacity-90 mb-1">Laba Bersih</p>
                <h3 class="text-3xl font-bold" id="valUntung">Rp 0</h3>
                <div class="mt-4 flex items-center text-xs bg-white/20 w-max px-2 py-1 rounded-md"><i class="fa-solid fa-chart-line mr-1"></i> Keuntungan</div>
            </div>

            <div class="card-gradient-4 rounded-2xl p-6 text-white shadow-xl relative overflow-hidden transform hover:-translate-y-1 transition duration-300">
                <i class="fa-solid fa-receipt absolute -right-4 -bottom-4 text-8xl opacity-20"></i>
                <p class="text-sm font-medium opacity-90 mb-1">Total Transaksi</p>
                <h3 class="text-3xl font-bold" id="valTrx">0</h3>
                <div class="mt-4 flex items-center text-xs bg-white/20 w-max px-2 py-1 rounded-md"><i class="fa-solid fa-check-circle mr-1"></i> Nota Terbit</div>
            </div>
        </div>

        <!-- BARIS KEDUA: GRAFIK & PRODUK TERLARIS -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            
            <!-- Grafik Penjualan (2/3 lebar) -->
            <div class="lg:col-span-2 bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="font-bold text-lg text-gray-800">Tren Penjualan (7 Hari Terakhir)</h3>
                    <i class="fa-solid fa-chart-area text-gray-400"></i>
                </div>
                <div class="relative h-72 w-full">
                    <canvas id="salesChart"></canvas>
                </div>
            </div>

            <!-- Produk Terlaris (1/3 lebar) -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 flex flex-col">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="font-bold text-lg text-gray-800">Top 5 Produk</h3>
                    <i class="fa-solid fa-fire text-orange-500"></i>
                </div>
                <div id="topProductsList" class="flex-1 space-y-4 overflow-y-auto pr-2"></div>
            </div>
        </div>

        <!-- BARIS KETIGA: TRANSAKSI TERBARU -->
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="font-bold text-lg text-gray-800">Transaksi Terbaru</h3>
                <a href="laporan.php" class="text-sm text-blue-600 hover:underline">Lihat Semua <i class="fa-solid fa-arrow-right ml-1"></i></a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                        <tr>
                            <th class="p-3 border-b rounded-tl-lg">Invoice</th>
                            <th class="p-3 border-b">Kasir</th>
                            <th class="p-3 border-b">Total</th>
                            <th class="p-3 border-b">Waktu</th>
                            <th class="p-3 border-b text-center rounded-tr-lg">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="recentTrxBody" class="text-sm divide-y divide-gray-100"></tbody>
                </table>
            </div>
        </div>
    </main>

<script>
    let currentFilter = 'today';
    let chartInstance = null;
    const formatRp = (angka) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(angka);

    // 1. FUNGSI GANTI FILTER
    function changeFilter(filter) {
        currentFilter = filter;
        
        // Reset styling tombol
        ['today', 'yesterday', 'month', 'all'].forEach(f => {
            document.getElementById('btn-' + f).className = "px-4 py-2 text-sm font-medium rounded-lg text-gray-600 hover:bg-gray-100 transition";
        });
        // Set active button
        document.getElementById('btn-' + filter).className = "px-4 py-2 text-sm font-medium rounded-lg bg-blue-600 text-white shadow-sm transition";

        loadDashboard();
    }

    // 2. LOAD STATISTIK KARTU
    function loadStats() {
        const formData = new FormData(); 
        formData.append('action', 'get_stats');
        formData.append('filter', currentFilter);

        fetch('dashboard.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            document.getElementById('valPendapatan').innerText = formatRp(data.pendapatan);
            document.getElementById('valModal').innerText = formatRp(data.modal);
            document.getElementById('valUntung').innerText = formatRp(data.untung);
            document.getElementById('valTrx').innerText = data.trx;
            document.getElementById('valBarang').innerText = data.barang;
        });
    }

    // 3. LOAD GRAFIK (Selalu 7 hari terakhir, tidak terpengaruh filter)
    function loadChart() {
        const formData = new FormData(); formData.append('action', 'get_chart');
        fetch('dashboard.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            const labels = data.map(d => d.label);
            const values = data.map(d => d.total);

            if (chartInstance) { chartInstance.destroy(); } // Hapus chart lama jika ada

            const ctx = document.getElementById('salesChart').getContext('2d');
            const gradient = ctx.createLinearGradient(0, 0, 0, 400);
            gradient.addColorStop(0, 'rgba(59, 130, 246, 0.5)');
            gradient.addColorStop(1, 'rgba(59, 130, 246, 0)');

            chartInstance = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Pendapatan (Rp)',
                        data: values,
                        borderColor: '#3b82f6',
                        backgroundColor: gradient,
                        borderWidth: 3,
                        pointBackgroundColor: '#fff',
                        pointBorderColor: '#3b82f6',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        fill: true,
                        tension: 0.4 // Membuat garis melengkung halus
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) { return formatRp(context.parsed.y); }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { callback: function(value) { return 'Rp ' + (value/1000) + 'k'; } },
                            grid: { borderDash: [5, 5] }
                        },
                        x: { grid: { display: false } }
                    }
                }
            });
        });
    }

    // 4. LOAD TOP PRODUK
    function loadTopProducts() {
        const formData = new FormData(); 
        formData.append('action', 'get_top_products');
        formData.append('filter', currentFilter);

        fetch('dashboard.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            const container = document.getElementById('topProductsList');
            container.innerHTML = '';
            if(data.length === 0) {
                container.innerHTML = '<div class="text-center text-gray-400 mt-10"><i class="fa-solid fa-box-open text-4xl mb-2"></i><p class="text-sm">Belum ada penjualan</p></div>';
                return;
            }
            
            data.forEach((p, index) => {
                // Warna badge ranking
                let badgeColor = 'bg-gray-100 text-gray-600';
                if(index === 0) badgeColor = 'bg-yellow-100 text-yellow-700';
                else if(index === 1) badgeColor = 'bg-gray-200 text-gray-700';
                else if(index === 2) badgeColor = 'bg-orange-100 text-orange-700';

                container.innerHTML += `
                    <div class="flex items-center p-3 rounded-xl hover:bg-gray-50 transition border border-transparent hover:border-gray-100">
                        <div class="w-8 h-8 rounded-lg ${badgeColor} flex items-center justify-center font-bold mr-3">#${index+1}</div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-gray-800 truncate">${p.name}</p>
                            <p class="text-xs text-gray-500">${p.total_qty} terjual</p>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-bold text-blue-600">${formatRp(p.total_pendapatan)}</p>
                        </div>
                    </div>
                `;
            });
        });
    }

    // 5. LOAD TRANSAKSI TERBARU
    function loadRecentTrx() {
        const formData = new FormData(); formData.append('action', 'get_recent_trx');
        fetch('dashboard.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            const tbody = document.getElementById('recentTrxBody');
            tbody.innerHTML = '';
            if(data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="p-4 text-center text-gray-500">Belum ada transaksi</td></tr>';
                return;
            }
            data.forEach(t => {
                tbody.innerHTML += `
                    <tr class="hover:bg-gray-50">
                        <td class="p-3 font-medium text-blue-600 text-xs">${t.invoice_no}</td>
                        <td class="p-3 capitalize text-gray-700">${t.username}</td>
                        <td class="p-3 font-semibold">${formatRp(t.total)}</td>
                        <td class="p-3 text-xs text-gray-500">${new Date(t.created_at).toLocaleString('id-ID')}</td>
                        <td class="p-3 text-center">
                            <a href="laporan.php" class="text-gray-400 hover:text-blue-600"><i class="fa-solid fa-eye"></i></a>
                        </td>
                    </tr>
                `;
            });
        });
    }

    function loadDashboard() {
        loadStats();
        loadTopProducts();
        // Grafik dan Recent Trx tidak perlu di-refresh setiap ganti filter, tapi kita load awal saja.
    }

    // Init First Load
    loadDashboard();
    loadChart();
    loadRecentTrx();
</script>
</body>
</html>