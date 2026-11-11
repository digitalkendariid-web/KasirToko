<?php
require 'config.php';

// LOGIN PROSES
if (isset($_POST['action']) && $_POST['action'] == 'login') {
    $username = $_POST['username'];
    $password = $_POST['password'];
    
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        if (password_verify($password, $row['password']) || ($username == 'admin' && $password == 'admin123') || ($username == 'kasir' && $password == 'kasir123')) {
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['username'] = $row['username'];
            $_SESSION['role'] = $row['role'];
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Password salah!']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Username tidak ditemukan!']);
    }
    exit;
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// API KASIR
if (isset($_SESSION['user_id']) && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action == 'get_products') {
        $res = $conn->query("SELECT * FROM products ORDER BY name ASC");
        $data = [];
        while ($row = $res->fetch_assoc()) { $data[] = $row; }
        $laci = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'uang_laci'")->fetch_assoc();
        $uang_laci = $laci ? $laci['setting_value'] : 0;
        echo json_encode(['products' => $data, 'uang_laci' => $uang_laci]); exit;
    }

    if ($action == 'set_uang_laci') {
        if ($_SESSION['role'] != 'admin') { echo json_encode(['status' => 'error', 'message' => 'Akses ditolak!']); exit; }
        $nominal = $_POST['nominal'];
        $stmt = $conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'uang_laci'");
        $stmt->bind_param("d", $nominal);
        if ($stmt->execute()) {
            $logDesc = "Set Uang Laci oleh Admin";
            $stmtLog = $conn->prepare("INSERT INTO cash_drawer_logs (transaction_id, user_id, type, amount, description) VALUES (NULL, ?, 'SET', ?, ?)");
            $stmtLog->bind_param("ids", $_SESSION['user_id'], $nominal, $logDesc);
            $stmtLog->execute();
            echo json_encode(['status' => 'success']); 
        } else { echo json_encode(['status' => 'error']); }
        exit;
    }

    if ($action == 'get_laci_logs') {
        if ($_SESSION['role'] != 'admin') { echo json_encode(['status' => 'error', 'message' => 'Akses ditolak!']); exit; }
        $query = "SELECT cl.*, u.username as kasir FROM cash_drawer_logs cl LEFT JOIN users u ON cl.user_id = u.id ORDER BY cl.id DESC LIMIT 50";
        $res = $conn->query($query);
        $logs = [];
        while ($row = $res->fetch_assoc()) { $logs[] = $row; }
        $sumQuery = $conn->query("SELECT 
            SUM(CASE WHEN type = 'IN' THEN amount ELSE 0 END) as total_in,
            SUM(CASE WHEN type = 'OUT' THEN amount ELSE 0 END) as total_out,
            SUM(CASE WHEN type = 'SET' THEN amount ELSE 0 END) as total_set
            FROM cash_drawer_logs")->fetch_assoc();
        $laci = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'uang_laci'")->fetch_assoc();
        $total_cash = $laci ? $laci['setting_value'] : 0;
        echo json_encode(['logs' => $logs, 'total_cash' => $total_cash, 'summary_in' => $sumQuery['total_in'] ?? 0, 'summary_out' => $sumQuery['total_out'] ?? 0, 'summary_set' => $sumQuery['total_set'] ?? 0]); exit;
    }

    if ($action == 'reset_laci_logs') {
        if ($_SESSION['role'] != 'admin') { echo json_encode(['status' => 'error', 'message' => 'Akses ditolak!']); exit; }
        if ($conn->query("TRUNCATE TABLE cash_drawer_logs")) { echo json_encode(['status' => 'success']); } else { echo json_encode(['status' => 'error']); }
        exit;
    }

    if ($action == 'checkout') {
        $cart = json_decode($_POST['cart'], true);
        $total = $_POST['total'];
        $pay = $_POST['pay'];
        $payment_method = $_POST['payment_method'];
        $change = $pay - $total;
        $kasir_id = $_SESSION['user_id'];
        $invoice = "INV-" . date('YmdHis') . "-" . rand(100, 999);

        if ($pay < $total) { echo json_encode(['status' => 'error', 'message' => 'Uang pembayaran kurang!']); exit; }
        if (empty($cart)) { echo json_encode(['status' => 'error', 'message' => 'Keranjang kosong!']); exit; }

        if ($payment_method === 'Cash') {
            $laci = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'uang_laci'")->fetch_assoc();
            $saldo_laci = $laci ? $laci['setting_value'] : 0;
            if ($saldo_laci < $change) { echo json_encode(['status' => 'error', 'message' => "Uang kembalian melebihi saldo Uang Laci."]); exit; }
        }

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO transactions (invoice_no, kasir_id, total, pay, change_amount, payment_method) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("siddds", $invoice, $kasir_id, $total, $pay, $change, $payment_method);
            $stmt->execute();
            $trx_id = $conn->insert_id;

            $receipt_items = [];
            foreach ($cart as $item) {
                $check = $conn->query("SELECT stock FROM products WHERE id = " . $item['id'])->fetch_assoc();
                if ($check['stock'] < $item['qty']) throw new Exception("Stok " . $item['name'] . " tidak mencukupi!");
                $subtotal = $item['sell_price'] * $item['qty'];
                $stmt2 = $conn->prepare("INSERT INTO transaction_items (transaction_id, product_id, qty, price, subtotal) VALUES (?, ?, ?, ?, ?)");
                $stmt2->bind_param("iiidd", $trx_id, $item['id'], $item['qty'], $item['sell_price'], $subtotal);
                $stmt2->execute();
                $stmt3 = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
                $stmt3->bind_param("ii", $item['qty'], $item['id']);
                $stmt3->execute();
                $receipt_items[] = ['id' => $item['id'], 'name' => $item['name'], 'qty' => $item['qty'], 'price' => $item['sell_price'], 'subtotal' => $subtotal, 'unit' => $item['unit']];
            }

            if ($payment_method === 'Cash') {
                $laci_update = "UPDATE settings SET setting_value = setting_value + ? - ? WHERE setting_key = 'uang_laci'";
                $stmtLaci = $conn->prepare($laci_update);
                $stmtLaci->bind_param("dd", $total, $change);
                $stmtLaci->execute();
                $logIn = "Pembayaran Cash Nota " . $invoice;
                $stmtLogIn = $conn->prepare("INSERT INTO cash_drawer_logs (transaction_id, user_id, type, amount, description) VALUES (?, ?, 'IN', ?, ?)");
                $stmtLogIn->bind_param("iids", $trx_id, $kasir_id, $total, $logIn);
                $stmtLogIn->execute();
                if ($change > 0) {
                    $logOut = "Uang Kembalian Nota " . $invoice;
                    $stmtLogOut = $conn->prepare("INSERT INTO cash_drawer_logs (transaction_id, user_id, type, amount, description) VALUES (?, ?, 'OUT', ?, ?)");
                    $stmtLogOut->bind_param("iids", $trx_id, $kasir_id, $change, $logOut);
                    $stmtLogOut->execute();
                }
            }

            $conn->commit();
            $laci_now = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'uang_laci'")->fetch_assoc();
            echo json_encode(['status' => 'success', 'trx_id' => $trx_id, 'invoice' => $invoice, 'change' => $change, 'total' => $total, 'pay' => $pay, 'kasir' => $_SESSION['username'], 'payment_method' => $payment_method, 'items' => $receipt_items, 'uang_laci_baru' => $laci_now['setting_value']]); exit;
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); exit;
        }
    }

    if ($action == 'cancel_order') {
        $trx_id = $_POST['trx_id'];
        $conn->begin_transaction();
        try {
            $trxInfo = $conn->query("SELECT * FROM transactions WHERE id = $trx_id")->fetch_assoc();
            $items = $conn->query("SELECT product_id, qty FROM transaction_items WHERE transaction_id = $trx_id");
            while ($item = $items->fetch_assoc()) {
                $stmtStock = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
                $stmtStock->bind_param("ii", $item['qty'], $item['product_id']);
                $stmtStock->execute();
            }
            if ($trxInfo['payment_method'] === 'Cash') {
                $laci_revert = "UPDATE settings SET setting_value = setting_value - ? + ? WHERE setting_key = 'uang_laci'";
                $stmtLaci = $conn->prepare($laci_revert);
                $stmtLaci->bind_param("dd", $trxInfo['total'], $trxInfo['change_amount']);
                $stmtLaci->execute();
                $logVoid = "Void Nota " . $trxInfo['invoice_no'];
                $stmtLogVoid = $conn->prepare("INSERT INTO cash_drawer_logs (transaction_id, user_id, type, amount, description) VALUES (NULL, ?, 'OUT', ?, ?)");
                $stmtLogVoid->bind_param("ids", $_SESSION['user_id'], $trxInfo['total'], $logVoid);
                $stmtLogVoid->execute();
            }
            $stmt = $conn->prepare("DELETE FROM transactions WHERE id=?");
            $stmt->bind_param("i", $trx_id);
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
    <title>Kasir - RuangGuru Market</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f3f4f6; overflow: hidden; }
        .hide { display: none !important; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .img-product { transition: transform 0.3s ease; }
        .product-card:hover .img-product { transform: scale(1.05); }
        .payment-active { border-color: #2563eb !important; background-color: #eff6ff !important; color: #2563eb !important; box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.2); }
        
        /* Sidebar Ramping */
        .sidebar { transition: all 0.3s ease; width: 14rem; } /* w-56 */
        .sidebar-hidden { margin-left: -14rem; opacity: 0; pointer-events: none; }
        
        /* Floating Button (Kiri Atas - Desktop Saja) */
        .floating-btn {
            position: fixed;
            top: 1rem;
            left: 1rem;
            z-index: 9999;
            background: #2563eb;
            color: white;
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: none;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .floating-btn:hover { transform: scale(1.1); background: #1d4ed8; }
        .floating-btn.show { display: flex; }

        /* LOGIKA TOMBOL: Sembunyikan floating di Mobile */
        @media (max-width: 767px) {
            .floating-btn { display: none !important; }
        }

        /* Class helper untuk menggeser konten saat sidebar hidden (Hanya Desktop) */
        @media (min-width: 768px) {
            .content-shifted { padding-left: 4.5rem !important; }
        }

        /* === PERBAIKAN RESPONSIVE MOBILE/TABLET (UX DIJAMIN NYAMAN) === */
        @media (max-width: 1023px) {
            .main-content-wrapper { 
                height: 100%; 
                display: flex; 
                flex-direction: column; 
                overflow: hidden;
            }
            
            /* Katalog produk di atas */
            .product-area-mobile { 
                flex: 1; 
                min-height: 0; 
                overflow-y: auto; 
            }
            
            /* Keranjang di bawah */
            .cart-area-mobile { 
                height: 60vh; /* Diperbesar sedikit ke 60% layar agar lega */
                flex-shrink: 0; 
                border-top: 1px solid #e5e7eb;
                display: flex;
                flex-direction: column;
            }

            /* Bagian List Pesanan */
            .cart-area-mobile .cart-list {
                flex: 1; /* Mengambil semua sisa ruang */
                overflow-y: auto;
                min-height: 100px; /* Minimal ada ruang untuk 1-2 barang */
            }

            /* Bagian Footer (Pembayaran) */
            .cart-area-mobile .cart-footer {
                flex-shrink: 0;
                background: white;
                box-shadow: 0 -4px 6px -1px rgba(0,0,0,0.05); /* Shadow ke atas */
            }

            /* Perkecil padding di HP agar hemat tempat */
            .cart-footer .compact-mobile { padding: 0.5rem !important; }
            .cart-footer .compact-mobile-input { padding: 0.5rem !important; font-size: 1rem !important; }
            .cart-footer .compact-mobile-btn { padding: 0.75rem !important; font-size: 1rem !important; }
        }
    </style>
</head>
<body class="h-screen flex text-gray-800">

<?php if (!isset($_SESSION['user_id'])): ?>
    <!-- LOGIN SCREEN -->
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900 bg-opacity-95">
        <div class="bg-white p-8 rounded-2xl shadow-2xl w-full max-w-md">
            <div class="text-center mb-8">
                <div class="bg-blue-600 text-white w-16 h-16 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg shadow-blue-200">
                    <i class="fa-solid fa-store text-2xl"></i>
                </div>
                <h1 class="text-2xl font-bold text-gray-800">RuangGuru Market</h1>
                <p class="text-gray-500 text-sm mt-1">Silakan login untuk melanjutkan</p>
            </div>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                    <input type="text" id="username" placeholder="admin / kasir" class="w-full px-4 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                    <input type="password" id="password" placeholder="admin123 / kasir123" class="w-full px-4 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <button onclick="doLogin()" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 rounded-xl transition duration-200 mt-2 shadow-lg shadow-blue-200">
                    Login <i class="fa-solid fa-arrow-right ml-1"></i>
                </button>
                <div class="text-center text-xs text-gray-400 mt-4">
                    <p>Gunakan: admin/admin123 atau kasir/kasir123</p>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>
    <!-- TOMBOL MELAYANG -->
    <div id="floatingBtn" class="floating-btn" onclick="toggleSidebar()" title="Tampilkan Menu">
        <i class="fa-solid fa-bars text-lg"></i>
    </div>

    <!-- MAIN APP -->
    <div class="flex w-full h-full relative">
        <!-- Sidebar -->
        <aside id="sidebar" class="sidebar bg-white border-r border-gray-200 flex flex-col absolute md:relative h-full z-40 shadow-xl md:shadow-none">
            <div class="h-16 flex items-center justify-between px-4 border-b border-gray-100">
                <div class="flex items-center">
                    <i class="fa-solid fa-store text-blue-600 text-xl mr-2"></i>
                    <span class="font-bold text-lg tracking-tight text-gray-800">RuangGuru</span>
                </div>
                <button onclick="toggleSidebar()" class="text-gray-400 hover:text-red-500 transition" title="Sembunyikan Menu">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>
            <nav class="flex-1 p-3 space-y-2 overflow-y-auto">
                <?php if ($_SESSION['role'] == 'admin'): ?>
                    <a href="dashboard.php" class="flex items-center px-3 py-3 text-gray-600 hover:bg-gray-50 rounded-xl font-medium transition">
                        <i class="fa-solid fa-chart-pie w-6 text-center"></i> <span class="ml-2">Dashboard</span>
                    </a>
                <?php endif; ?>
                <a href="index.php" class="flex items-center px-3 py-3 bg-blue-50 text-blue-600 rounded-xl font-medium transition">
                    <i class="fa-solid fa-cash-register w-6 text-center"></i> <span class="ml-2">Kasir</span>
                </a>
                <?php if ($_SESSION['role'] == 'admin'): ?>
                    <a href="laporan.php" class="flex items-center px-3 py-3 text-gray-600 hover:bg-gray-50 rounded-xl font-medium transition">
                        <i class="fa-solid fa-chart-line w-6 text-center"></i> <span class="ml-2">Laporan</span>
                    </a>
                    <a href="produk.php" class="flex items-center px-3 py-3 text-gray-600 hover:bg-gray-50 rounded-xl font-medium transition">
                        <i class="fa-solid fa-box w-6 text-center"></i> <span class="ml-2">Produk</span>
                    </a>
                <?php endif; ?>
            </nav>
            <div class="p-3 border-t border-gray-100">
                <div class="flex items-center mb-3 px-2">
                    <div class="w-9 h-9 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold mr-3 uppercase">
                        <?= substr($_SESSION['username'], 0, 1) ?>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-gray-800 capitalize"><?= $_SESSION['username'] ?></p>
                        <p class="text-xs text-gray-500 capitalize"><?= $_SESSION['role'] ?></p>
                    </div>
                </div>
                <a href="?logout=1" class="w-full text-left px-3 py-2 text-sm text-red-600 hover:bg-red-50 rounded-lg transition font-medium block">
                    <i class="fa-solid fa-sign-out-alt mr-2"></i> Logout
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main id="mainContent" class="flex-1 flex flex-col h-full overflow-hidden relative transition-all duration-300">
            <header class="md:hidden bg-white h-14 border-b border-gray-200 flex items-center justify-between px-4 shadow-sm z-10">
                <div class="flex items-center">
                    <button onclick="toggleSidebar()" class="text-blue-600 mr-3 text-lg p-1"><i class="fa-solid fa-bars"></i></button>
                    <span class="font-bold text-gray-800">RuangGuru</span>
                </div>
                <a href="?logout=1" class="text-red-500 text-sm font-medium">Keluar</a>
            </header>

            <!-- INFO UANG LACI -->
            <div class="bg-white border-b border-gray-200 px-4 py-1.5 flex justify-center items-center text-xs md:text-sm relative z-10">
                <div class="flex items-center text-gray-600">
                    <i class="fa-solid fa-vault mr-1.5 text-blue-500"></i>
                    <span>Uang Laci: </span>
                    <span id="displayUangLaci" class="font-bold text-gray-800 ml-1">Rp 0</span>
                </div>
                <?php if ($_SESSION['role'] == 'admin'): ?>
                    <div class="absolute right-2 md:right-4 flex space-x-1 md:space-x-2">
                        <button onclick="lihatRiwayatLaci()" class="text-[10px] md:text-xs bg-gray-100 text-gray-700 hover:bg-gray-200 px-2 md:px-3 py-1 md:py-1.5 rounded-md font-medium transition flex items-center">
                            <i class="fa-solid fa-clock-rotate-left md:mr-1"></i> <span class="hidden md:inline">Riwayat</span>
                        </button>
                        <button onclick="editUangLaci()" class="text-[10px] md:text-xs bg-blue-100 text-blue-700 hover:bg-blue-200 px-2 md:px-3 py-1 md:py-1.5 rounded-md font-medium transition flex items-center">
                            <i class="fa-solid fa-pen-to-square md:mr-1"></i> <span class="hidden md:inline">Set Uang</span>
                        </button>
                    </div>
                <?php endif; ?>
            </div>

            <div class="flex-1 flex flex-col lg:flex-row overflow-hidden main-content-wrapper">
                <!-- Area Produk -->
                <div class="flex-1 p-3 md:p-6 overflow-y-auto product-area-mobile">
                    <h2 class="text-lg md:text-2xl font-bold text-gray-800 mb-3 md:mb-4">Katalog Produk</h2>
                    <div class="relative mb-3 md:mb-6">
                        <i class="fa-solid fa-search absolute left-3 md:left-4 top-2.5 md:top-3 text-gray-400 text-sm"></i>
                        <input type="text" id="searchInput" onkeyup="filterProducts()" placeholder="Cari nama produk..." class="w-full pl-9 md:pl-10 pr-4 py-1.5 md:py-2 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white shadow-sm text-sm">
                    </div>
                    <!-- GRID PRODUK -->
                    <div id="productGrid" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-5 gap-2 md:gap-4 pb-4"></div>
                </div>

                <!-- Area Keranjang Belanja -->
                <div class="w-full lg:w-[380px] bg-white border-l border-gray-200 flex flex-col cart-area-mobile shadow-lg z-10">
                    
                    <!-- Header Keranjang (Kecil di HP) -->
                    <div class="p-2 md:p-4 border-b border-gray-100 bg-gray-50 flex justify-between items-center flex-shrink-0">
                        <h3 class="font-bold text-sm md:text-lg text-gray-800"><i class="fa-solid fa-shopping-cart text-blue-600 mr-1 md:mr-2"></i> Pesanan</h3>
                        <button onclick="clearCart()" class="text-[10px] md:text-xs text-red-500 hover:text-red-700 font-medium"><i class="fa-solid fa-trash-can mr-0.5"></i> Hapus</button>
                    </div>
                    
                    <!-- List Barang (SCROLLABLE) -->
                    <div id="cartItems" class="flex-1 p-2 md:p-3 overflow-y-auto space-y-1 md:space-y-2 cart-list"></div>
                    
                    <!-- Area Pembayaran (DIKUNCI DI BAWAH) -->
                    <div class="p-2 md:p-4 border-t border-gray-100 bg-white cart-footer">
                        
                        <div class="flex justify-between text-xs md:text-sm mb-1 text-gray-600">
                            <span>Total Item</span><span id="totalItems" class="font-semibold text-gray-800">0</span>
                        </div>
                        <div class="flex justify-between text-base md:text-xl font-bold text-gray-800 mb-2 md:mb-4">
                            <span>Total</span><span id="totalPrice" class="text-blue-600">Rp 0</span>
                        </div>

                        <!-- Metode Pembayaran (Compact di HP) -->
                        <div class="mb-2 md:mb-4 compact-mobile">
                            <label class="block text-[10px] md:text-sm font-medium text-gray-700 mb-1">Metode</label>
                            <div class="grid grid-cols-2 gap-2">
                                <button type="button" id="btnCash" onclick="setPaymentMethod('Cash')" class="payment-active border-2 border-gray-200 rounded-lg py-1.5 md:py-2 flex flex-col items-center justify-center transition hover:bg-gray-50">
                                    <i class="fa-solid fa-money-bill-wave text-sm md:text-lg mb-0.5 md:mb-1"></i>
                                    <span class="text-[10px] md:text-xs font-bold">CASH</span>
                                </button>
                                <button type="button" id="btnQris" onclick="setPaymentMethod('QRIS')" class="border-2 border-gray-200 rounded-lg py-1.5 md:py-2 flex flex-col items-center justify-center transition text-gray-500 hover:bg-gray-50">
                                    <i class="fa-solid fa-qrcode text-sm md:text-lg mb-0.5 md:mb-1"></i>
                                    <span class="text-[10px] md:text-xs font-bold">QRIS</span>
                                </button>
                            </div>
                        </div>

                        <!-- Input Uang Bayar -->
                        <div class="mb-2 md:mb-4 compact-mobile">
                            <label class="block text-[10px] md:text-sm font-medium text-gray-700 mb-1">Uang Bayar (Rp)</label>
                            <input type="text" id="payAmount" onkeyup="formatInputRupiah(this); calculateChange()" placeholder="Rp 0" class="w-full px-3 py-1.5 md:py-2 border border-gray-300 rounded-lg md:rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 text-right font-bold text-sm md:text-lg compact-mobile-input">
                        </div>
                        
                        <!-- Kembalian -->
                        <div class="flex justify-between text-[10px] md:text-sm mb-2 md:mb-4 text-gray-600 compact-mobile">
                            <span>Kembalian</span><span id="changeAmount" class="font-bold text-green-600">Rp 0</span>
                        </div>
                        
                        <!-- Tombol Proses -->
                        <button onclick="processPayment(event)" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 md:py-3 rounded-lg md:rounded-xl transition duration-200 shadow-lg shadow-blue-200 text-sm md:text-base compact-mobile-btn">
                            Proses Pembayaran
                        </button>
                    </div>
                </div>
            </div>
        </main>
    </div>
<?php endif; ?>

<script>
    const Toast = Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false, timer: 3000, timerProgressBar: true });
    let cart = [];
    let products = [];
    let currentPaymentMethod = 'Cash';
    let uangLaci = 0;
    const formatRp = (angka) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(angka);

    // FUNGSI TOGGLE SIDEBAR
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const floatingBtn = document.getElementById('floatingBtn');
        const mainContent = document.getElementById('mainContent');
        
        if (sidebar.classList.contains('sidebar-hidden')) {
            sidebar.classList.remove('sidebar-hidden');
            if (floatingBtn) floatingBtn.classList.remove('show');
            if (mainContent) mainContent.classList.remove('content-shifted');
            localStorage.setItem('sidebarHidden', 'false');
        } else {
            sidebar.classList.add('sidebar-hidden');
            if (floatingBtn) floatingBtn.classList.add('show');
            if (mainContent) mainContent.classList.add('content-shifted');
            localStorage.setItem('sidebarHidden', 'true');
        }
    }

    // Cek status sidebar saat halaman dimuat
    document.addEventListener('DOMContentLoaded', () => {
        const sidebar = document.getElementById('sidebar');
        const floatingBtn = document.getElementById('floatingBtn');
        const mainContent = document.getElementById('mainContent');
        if (localStorage.getItem('sidebarHidden') === 'true') {
            sidebar.classList.add('sidebar-hidden');
            if (floatingBtn) floatingBtn.classList.add('show');
            if (mainContent) mainContent.classList.add('content-shifted');
        }
    });

    function formatInputRupiah(input) {
        let value = input.value.replace(/[^0-9]/g, '');
        if (value) { value = parseInt(value, 10).toLocaleString('id-ID'); }
        input.value = value ? 'Rp ' + value : '';
    }

    function editUangLaci() {
        Swal.fire({ title: 'Set Uang Laci', text: "Masukkan jumlah uang kembalian yang ada di laci saat ini:", input: 'number', inputValue: uangLaci, inputAttributes: { min: 0, step: 1000 }, showCancelButton: true, confirmButtonText: 'Simpan', confirmButtonColor: '#2563eb', cancelButtonText: 'Batal' })
        .then((result) => {
            if (result.isConfirmed && result.value !== '') {
                const formData = new FormData(); formData.append('action', 'set_uang_laci'); formData.append('nominal', result.value);
                fetch('index.php', { method: 'POST', body: formData }).then(res => res.json()).then(res => {
                    if (res.status === 'success') { uangLaci = parseFloat(result.value); document.getElementById('displayUangLaci').innerText = formatRp(uangLaci); Toast.fire({ icon: 'success', title: 'Uang Laci diperbarui' }); }
                    else { Swal.fire('Gagal', res.message, 'error'); }
                });
            }
        });
    }

    function lihatRiwayatLaci() {
        Swal.fire({
            title: 'Mutasi Uang Laci',
            html: `
                <div class="mt-4">
                    <div class="flex justify-end mb-2">
                        <button onclick="resetRiwayatLaci()" class="text-xs bg-red-100 text-red-700 hover:bg-red-200 px-3 py-1.5 rounded-lg font-medium transition flex items-center"><i class="fa-solid fa-trash-can mr-1"></i> Reset Riwayat</button>
                    </div>
                    <div class="grid grid-cols-2 gap-2 mb-4">
                        <div class="bg-green-50 p-2 rounded-lg border border-green-100 text-left"><p class="text-[10px] text-green-700 font-medium">Uang Masuk (Cash)</p><p class="text-sm font-bold text-green-700" id="sumIn">Rp 0</p></div>
                        <div class="bg-red-50 p-2 rounded-lg border border-red-100 text-left"><p class="text-[10px] text-red-700 font-medium">Uang Keluar (Kembali)</p><p class="text-sm font-bold text-red-700" id="sumOut">Rp 0</p></div>
                        <div class="bg-blue-50 p-2 rounded-lg border border-blue-100 text-left"><p class="text-[10px] text-blue-700 font-medium">Set Awal (Admin)</p><p class="text-sm font-bold text-blue-700" id="sumSet">Rp 0</p></div>
                        <div class="bg-gray-800 p-2 rounded-lg border border-gray-700 text-left"><p class="text-[10px] text-gray-300 font-medium">Saldo Akhir Laci</p><p class="text-sm font-bold text-white" id="sumTotal">Rp 0</p></div>
                    </div>
                    <div id="laciLogsContainer" class="max-h-64 overflow-y-auto border rounded-lg">
                        <table class="w-full text-left border-collapse">
                            <thead class="bg-gray-100 text-xs text-gray-600 sticky top-0 z-10"><tr><th class="p-2 border-b font-semibold">Waktu</th><th class="p-2 border-b font-semibold">Keterangan</th><th class="p-2 border-b font-semibold text-right">Mutasi</th></tr></thead>
                            <tbody id="laciLogsBody" class="text-xs"><tr><td colspan="3" class="p-4 text-center text-gray-500">Memuat...</td></tr></tbody>
                        </table>
                    </div>
                </div>`,
            width: '600px', showCloseButton: true, showConfirmButton: false, didOpen: () => { loadLaciLogsData(); }
        });
    }

    function loadLaciLogsData() {
        const formData = new FormData(); formData.append('action', 'get_laci_logs');
        fetch('index.php', { method: 'POST', body: formData }).then(res => res.json()).then(data => {
            document.getElementById('sumIn').innerText = formatRp(data.summary_in);
            document.getElementById('sumOut').innerText = formatRp(data.summary_out);
            document.getElementById('sumSet').innerText = formatRp(data.summary_set);
            document.getElementById('sumTotal').innerText = formatRp(data.total_cash);
            const tbody = document.getElementById('laciLogsBody');
            if (data.logs.length === 0) { tbody.innerHTML = '<tr><td colspan="3" class="p-4 text-center text-gray-500">Belum ada riwayat.</td></tr>'; return; }
            tbody.innerHTML = '';
            data.logs.forEach(log => {
                const isIn = log.type === 'IN' || log.type === 'SET';
                const colorClass = isIn ? 'text-green-600 font-bold' : 'text-red-600 font-bold';
                const icon = isIn ? 'fa-arrow-down text-green-500' : 'fa-arrow-up text-red-500';
                const kasirName = log.kasir ? `<span class="text-[10px] text-gray-400 capitalize block">oleh: ${log.kasir}</span>` : '';
                tbody.innerHTML += `
                    <tr class="hover:bg-gray-50 border-b border-gray-100">
                        <td class="p-2 text-gray-500 whitespace-nowrap align-top">${new Date(log.created_at).toLocaleString('id-ID', {day:'2-digit', month:'short', hour:'2-digit', minute:'2-digit'})}</td>
                        <td class="p-2"><div class="flex items-start"><i class="fa-solid ${icon} mr-2 text-[10px] mt-1"></i><div><span class="text-gray-700 whitespace-normal leading-tight">${log.description || '-'}</span>${kasirName}</div></div></td>
                        <td class="p-2 text-right ${colorClass} whitespace-nowrap align-top">${isIn ? '+' : '-'} ${formatRp(log.amount)}</td>
                    </tr>`;
            });
        });
    }

    function resetRiwayatLaci() {
        Swal.fire({ title: 'Reset Riwayat Laci?', text: "Semua riwayat mutasi akan dihapus permanen. Saldo Uang Laci TIDAK akan berubah.", icon: 'warning', showCancelButton: true, confirmButtonColor: '#ef4444', confirmButtonText: 'Ya, Reset', cancelButtonText: 'Batal' })
        .then((result) => {
            if (result.isConfirmed) {
                const formData = new FormData(); formData.append('action', 'reset_laci_logs');
                fetch('index.php', { method: 'POST', body: formData }).then(res => res.json()).then(res => {
                    if (res.status === 'success') { Toast.fire({ icon: 'success', title: 'Riwayat berhasil di-reset' }); loadLaciLogsData(); }
                    else { Swal.fire('Gagal', res.message, 'error'); }
                });
            }
        });
    }

    function setPaymentMethod(method) {
        currentPaymentMethod = method;
        const btnCash = document.getElementById('btnCash'); const btnQris = document.getElementById('btnQris'); const payInput = document.getElementById('payAmount');
        btnCash.className = 'border-2 border-gray-200 rounded-lg py-1.5 md:py-2 flex flex-col items-center justify-center transition text-gray-500 hover:bg-gray-50';
        btnQris.className = 'border-2 border-gray-200 rounded-lg py-1.5 md:py-2 flex flex-col items-center justify-center transition text-gray-500 hover:bg-gray-50';
        if (method === 'Cash') {
            btnCash.classList.add('payment-active'); payInput.disabled = false; payInput.value = ''; payInput.classList.remove('bg-gray-100', 'cursor-not-allowed'); calculateChange();
        } else {
            btnQris.classList.add('payment-active'); payInput.disabled = true; payInput.classList.add('bg-gray-100', 'cursor-not-allowed');
            let total = cart.reduce((sum, item) => sum + (item.sell_price * item.qty), 0);
            payInput.value = total > 0 ? 'Rp ' + total.toLocaleString('id-ID') : 'Rp 0'; calculateChange();
        }
    }

    function doLogin() {
        const user = document.getElementById('username').value; const pass = document.getElementById('password').value;
        const formData = new FormData(); formData.append('action', 'login'); formData.append('username', user); formData.append('password', pass);
        fetch('index.php', { method: 'POST', body: formData }).then(res => res.json()).then(data => {
            if (data.status === 'success') window.location.reload(); else Swal.fire('Gagal Login', data.message, 'error');
        });
    }

    function loadProducts() {
        const formData = new FormData(); formData.append('action', 'get_products');
        fetch('index.php', { method: 'POST', body: formData }).then(res => res.json()).then(data => { 
            products = data.products; uangLaci = parseFloat(data.uang_laci);
            document.getElementById('displayUangLaci').innerText = formatRp(uangLaci); renderProducts(products); 
        });
    }

    function renderProducts(data) {
        const grid = document.getElementById('productGrid'); grid.innerHTML = '';
        if(data.length === 0) { grid.innerHTML = `<div class="col-span-full text-center text-gray-500 mt-10">Produk tidak ditemukan.</div>`; return; }
        data.forEach(p => {
            const price = formatRp(p.sell_price);
            const outOfStock = p.stock <= 0;
            const imgSrc = p.image ? `uploads/${p.image}` : `https://placehold.co/400x300/e2e8f0/64748b?text=No+Image`;
            grid.innerHTML += `
                <div onclick="${outOfStock ? '' : `addToCart(${p.id})`}" class="product-card bg-white rounded-xl shadow-sm border border-gray-100 ${outOfStock ? 'opacity-50 cursor-not-allowed' : 'hover:shadow-lg hover:border-blue-300 cursor-pointer'} transition-all active:scale-95 flex flex-col overflow-hidden h-full relative group">
                    <div class="w-full h-24 md:h-28 bg-gray-100 relative overflow-hidden">
                        <img src="${imgSrc}" class="w-full h-full object-cover img-product">
                        <div class="absolute top-1 right-1"><span class="text-[10px] font-bold shadow-sm ${p.stock > 5 ? 'text-green-700 bg-green-100/90' : 'text-red-700 bg-red-100/90'} px-1.5 py-0.5 rounded backdrop-blur-sm">Stok: ${p.stock}</span></div>
                        ${outOfStock ? '<div class="absolute inset-0 bg-black/40 flex items-center justify-center"><span class="text-white font-bold border-2 border-white px-2 py-0.5 rounded text-xs transform -rotate-12">HABIS</span></div>' : ''}
                    </div>
                    <div class="p-2 flex-1 flex flex-col justify-between">
                        <div>
                            <h3 class="font-semibold text-xs text-gray-800 leading-tight line-clamp-2">${p.name}</h3>
                            <p class="text-[10px] text-gray-500 mt-0.5">${p.isi} ${p.unit}</p>
                        </div>
                        <div class="mt-2 flex justify-between items-end">
                            <p class="text-blue-600 font-bold text-xs">${price}</p>
                            <button class="w-6 h-6 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center group-hover:bg-blue-600 group-hover:text-white transition"><i class="fa-solid fa-plus text-[10px]"></i></button>
                        </div>
                    </div>
                </div>`;
        });
    }

    function filterProducts() {
        const query = document.getElementById('searchInput').value.toLowerCase();
        renderProducts(products.filter(p => p.name.toLowerCase().includes(query)));
    }

    function addToCart(id) {
        const product = products.find(p => p.id == id);
        const existing = cart.find(item => item.id == id);
        if (existing) {
            if (existing.qty >= product.stock) { Toast.fire({icon: 'warning', title: 'Stok tidak mencukupi!'}); return; }
            existing.qty++;
        } else { cart.push({ ...product, qty: 1 }); }
        renderCart(); if (currentPaymentMethod === 'QRIS') setPaymentMethod('QRIS');
    }

    function updateQty(id, change) {
        const index = cart.findIndex(item => item.id == id);
        if (index > -1) { cart[index].qty += change; if (cart[index].qty <= 0) cart.splice(index, 1); }
        renderCart(); if (currentPaymentMethod === 'QRIS') setPaymentMethod('QRIS');
    }

    function clearCart() {
        if (cart.length === 0) return;
        Swal.fire({ title: 'Hapus Pesanan?', text: "Keranjang akan dikosongkan", icon: 'warning', showCancelButton: true, confirmButtonColor: '#ef4444', cancelButtonColor: '#6b7280', confirmButtonText: 'Ya, Hapus' })
        .then((result) => { if (result.isConfirmed) { cart = []; renderCart(); setPaymentMethod('Cash'); } });
    }

    function renderCart() {
        const container = document.getElementById('cartItems'); container.innerHTML = '';
        let total = 0, qtyTotal = 0;
        if (cart.length === 0) {
            container.innerHTML = `<div class="text-center text-gray-400 mt-4"><i class="fa-solid fa-basket-shopping text-4xl mb-2 text-gray-300"></i><p class="text-xs">Belum ada pesanan</p></div>`;
            document.getElementById('totalItems').innerText = "0"; document.getElementById('totalPrice').innerText = "Rp 0"; document.getElementById('changeAmount').innerText = "Rp 0"; return;
        }
        cart.forEach(item => {
            const subtotal = item.sell_price * item.qty;
            total += subtotal; qtyTotal += item.qty;
            container.innerHTML += `
                <div class="flex items-center justify-between border-b border-gray-50 pb-1.5 mb-1.5">
                    <div class="flex-1 pr-2">
                        <h4 class="text-[11px] font-semibold text-gray-800 line-clamp-1">${item.name}</h4>
                        <p class="text-[9px] text-gray-500">${formatRp(item.sell_price)}</p>
                    </div>
                    <div class="flex items-center space-x-1 bg-gray-100 rounded-lg p-0.5">
                        <button onclick="updateQty(${item.id}, -1)" class="w-5 h-5 flex items-center justify-center bg-white rounded shadow-sm text-gray-600 hover:text-red-500"><i class="fa-solid fa-minus text-[9px]"></i></button>
                        <span class="text-[11px] font-semibold w-4 text-center">${item.qty}</span>
                        <button onclick="updateQty(${item.id}, 1)" class="w-5 h-5 flex items-center justify-center bg-white rounded shadow-sm text-gray-600 hover:text-green-500"><i class="fa-solid fa-plus text-[9px]"></i></button>
                    </div>
                    <div class="w-16 text-right font-semibold text-[11px]">${formatRp(subtotal)}</div>
                </div>`;
        });
        document.getElementById('totalItems').innerText = qtyTotal;
        document.getElementById('totalPrice').innerText = formatRp(total);
        calculateChange();
    }

    function calculateChange() {
        let total = cart.reduce((sum, item) => sum + (item.sell_price * item.qty), 0);
        let payString = document.getElementById('payAmount').value.replace(/[^0-9]/g, '');
        const pay = parseInt(payString) || 0;
        const change = pay - total;
        const el = document.getElementById('changeAmount');
        if (pay > 0 && change >= 0) { el.innerText = formatRp(change); el.className = 'font-bold text-green-600'; }
        else if (pay > 0 && change < 0) { el.innerText = "Uang Kurang!"; el.className = 'font-bold text-red-600'; }
        else { el.innerText = "Rp 0"; el.className = 'font-bold text-gray-400'; }
    }

    function processPayment(event) {
        let total = cart.reduce((sum, item) => sum + (item.sell_price * item.qty), 0);
        let payString = document.getElementById('payAmount').value.replace(/[^0-9]/g, '');
        const pay = parseInt(payString) || 0;
        if (cart.length === 0) { Toast.fire({icon: 'warning', title: 'Keranjang kosong!'}); return; }
        if (pay < total) { Toast.fire({icon: 'error', title: 'Uang pembayaran kurang!'}); return; }
        if (currentPaymentMethod === 'Cash') {
            const change = pay - total;
            if (uangLaci < change) { Swal.fire('Uang Laci Kurang', `Uang kembalian Rp ${change.toLocaleString('id-ID')} melebihi saldo Uang Laci.`, 'error'); return; }
        }
        const btn = event.target;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Proses...'; btn.disabled = true;
        const formData = new FormData();
        formData.append('action', 'checkout'); formData.append('cart', JSON.stringify(cart)); formData.append('total', total); formData.append('pay', pay); formData.append('payment_method', currentPaymentMethod);
        fetch('index.php', { method: 'POST', body: formData }).then(res => res.json()).then(data => {
            btn.innerHTML = 'Proses Pembayaran'; btn.disabled = false;
            if (data.status === 'success') {
                uangLaci = parseFloat(data.uang_laci_baru); document.getElementById('displayUangLaci').innerText = formatRp(uangLaci);
                let listBarang = '';
                data.items.forEach(item => {
                    listBarang += `<div class="flex justify-between items-start py-1.5 border-b border-dashed border-gray-300 text-sm"><div class="flex-1"><p class="font-semibold text-gray-800">${item.name}</p><p class="text-xs text-gray-500">${item.qty} ${item.unit} x ${formatRp(item.price)}</p></div><div class="font-medium text-gray-800">${formatRp(item.subtotal)}</div></div>`;
                });
                Swal.fire({
                    title: 'Transaksi Berhasil!',
                    html: `
                        <div class="text-left font-mono text-sm mt-2 bg-gray-50 p-4 rounded-lg border border-gray-200 relative">
                            <button onclick="Swal.close(); batalTransaksi(${data.trx_id}, '${data.invoice}');" class="absolute top-2 right-2 text-red-500 hover:text-red-700 bg-red-50 hover:bg-red-100 p-1.5 rounded-full transition" title="Batalkan Transaksi"><i class="fa-solid fa-xmark text-lg"></i></button>
                            <div class="text-center border-b border-dashed border-gray-400 pb-3 mb-3">
                                <h3 class="font-bold text-lg text-gray-800">RuangGuru Market</h3>
                                <p class="text-xs text-gray-500">${new Date().toLocaleString('id-ID')}</p>
                                <p class="text-xs text-gray-500 mt-1">Invoice: <b>${data.invoice}</b></p>
                                <p class="text-xs text-gray-500">Kasir: <span class="capitalize">${data.kasir}</span></p>
                                <p class="text-xs font-bold text-blue-600 mt-1 uppercase">${data.payment_method}</p>
                            </div>
                            <div class="max-h-60 overflow-y-auto mb-3 pr-1">${listBarang}</div>
                            <div class="border-t border-gray-400 pt-3 space-y-1">
                                <div class="flex justify-between font-bold text-base"><span>TOTAL</span><span>${formatRp(data.total)}</span></div>
                                <div class="flex justify-between text-gray-600"><span>Bayar</span><span>${formatRp(data.pay)}</span></div>
                                <div class="flex justify-between text-gray-600"><span>Kembali</span><span>${formatRp(data.change)}</span></div>
                            </div>
                            <div class="text-center text-xs text-gray-400 mt-4 border-t border-dashed border-gray-400 pt-3">Terima Kasih Telah Berbelanja</div>
                        </div>`,
                    width: '450px', showCloseButton: true, showConfirmButton: true, confirmButtonText: '<i class="fa-solid fa-check"></i> Selesai', confirmButtonColor: '#10b981', showDenyButton: true, denyButtonText: '<i class="fa-solid fa-print"></i> Cetak', denyButtonColor: '#2563eb', showCancelButton: true, cancelButtonText: '<i class="fa-solid fa-xmark"></i> Batalkan', cancelButtonColor: '#ef4444', allowOutsideClick: false, allowEscapeKey: false
                }).then((result) => {
                    if (result.isDenied) { window.print(); resetAfterTransaction(); setTimeout(() => { Swal.fire({icon: 'success', title: 'Transaksi Berhasil!', text: `Laporan penjualan ${data.invoice} telah dikirim ke Admin.`, confirmButtonColor: '#10b981', confirmButtonText: 'OK'}); }, 500); }
                    else if (result.isConfirmed) { resetAfterTransaction(); setTimeout(() => { Swal.fire({icon: 'success', title: 'Transaksi Berhasil!', text: `Laporan penjualan ${data.invoice} telah dikirim ke Admin.`, confirmButtonColor: '#10b981', confirmButtonText: 'OK'}); }, 500); }
                    else if (result.dismiss === Swal.DismissReason.cancel || result.dismiss === Swal.DismissReason.close) { batalTransaksi(data.trx_id, data.invoice); }
                });
            } else { Swal.fire('Gagal', data.message, 'error'); }
        });
    }

    function batalTransaksi(trx_id, invoice) {
        Swal.fire({ title: 'Batalkan Transaksi?', text: "Stok barang akan dikembalikan seperti semula dan uang laci disesuaikan!", icon: 'warning', showCancelButton: true, confirmButtonColor: '#ef4444', confirmButtonText: 'Ya, Batalkan', cancelButtonText: 'Tidak' })
        .then((voidRes) => {
            if (voidRes.isConfirmed) {
                const voidData = new FormData(); voidData.append('action', 'cancel_order'); voidData.append('trx_id', trx_id);
                fetch('index.php', { method: 'POST', body: voidData }).then(r => r.json()).then(vr => {
                    if (vr.status === 'success') { Swal.fire('Dibatalkan!', 'Transaksi dibatalkan, stok dan uang laci telah dikembalikan.', 'success'); resetAfterTransaction(); }
                    else { Swal.fire('Gagal', vr.message, 'error'); }
                });
            } else { resetAfterTransaction(); }
        });
    }

    function resetAfterTransaction() {
        cart = []; document.getElementById('payAmount').value = ''; renderCart(); loadProducts();
    }

    <?php if (isset($_SESSION['user_id'])): ?> loadProducts(); <?php endif; ?>
</script>
</body>
</html>