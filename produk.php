<?php
require 'config.php';
checkLogin();
if ($_SESSION['role'] != 'admin') { header("Location: index.php"); exit; }

// API PRODUK
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action == 'read') {
        $res = $conn->query("SELECT * FROM products ORDER BY id DESC");
        $data = [];
        while ($row = $res->fetch_assoc()) { $data[] = $row; }
        echo json_encode($data); exit;
    }

    // API TAMBAH STOK
    if ($action == 'add_stock') {
        $id = $_POST['id'];
        $tambah_stok = (int)$_POST['jumlah'];
        
        if ($tambah_stok <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Jumlah stok harus lebih dari 0!']); exit;
        }

        $stmt = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
        $stmt->bind_param("ii", $tambah_stok, $id);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success']); 
        } else {
            echo json_encode(['status' => 'error', 'message' => $conn->error]);
        }
        exit;
    }

    if ($action == 'create' || $action == 'update') {
        $name = $_POST['name']; $qty = $_POST['qty']; $unit = $_POST['unit'];
        $isi = $_POST['isi']; $harga_beli = $_POST['harga_beli']; $modal = $_POST['modal'];
        $sell = $_POST['sell']; $untung_pcs = $_POST['untung_pcs']; $untung_box = $_POST['untung_box'];
        $stock = $_POST['stock'];
        
        // Handle Upload Foto
        $image_name = $_POST['old_image'] ?? '';
        if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
            $target_dir = "uploads/";
            if (!file_exists($target_dir)) { mkdir($target_dir, 0777, true); }
            $file_ext = strtolower(pathinfo($_FILES["image"]["name"], PATHINFO_EXTENSION));
            $image_name = time() . "_" . uniqid() . "." . $file_ext;
            $target_file = $target_dir . $image_name;
            move_uploaded_file($_FILES["image"]["tmp_name"], $target_file);
        }

        if ($action == 'create') {
            $stmt = $conn->prepare("INSERT INTO products (name, qty, unit, isi, harga_beli, modal_price, sell_price, untung_pcs, untung_box, stock, image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sisddddddss", $name, $qty, $unit, $isi, $harga_beli, $modal, $sell, $untung_pcs, $untung_box, $stock, $image_name);
            if ($stmt->execute()) echo json_encode(['status' => 'success']); else echo json_encode(['status' => 'error', 'message' => $conn->error]);
        } else {
            $id = $_POST['id'];
            $stmt = $conn->prepare("UPDATE products SET name=?, qty=?, unit=?, isi=?, harga_beli=?, modal_price=?, sell_price=?, untung_pcs=?, untung_box=?, stock=?, image=? WHERE id=?");
            $stmt->bind_param("sisddddddssi", $name, $qty, $unit, $isi, $harga_beli, $modal, $sell, $untung_pcs, $untung_box, $stock, $image_name, $id);
            if ($stmt->execute()) echo json_encode(['status' => 'success']); else echo json_encode(['status' => 'error']);
        }
        exit;
    }

    if ($action == 'delete') {
        $id = $_POST['id'];
        // Hapus foto fisik jika ada
        $res = $conn->query("SELECT image FROM products WHERE id=$id")->fetch_assoc();
        if ($res && $res['image'] && file_exists('uploads/'.$res['image'])) { unlink('uploads/'.$res['image']); }
        
        $stmt = $conn->prepare("DELETE FROM products WHERE id=?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) echo json_encode(['status' => 'success']); else echo json_encode(['status' => 'error']);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Produk - RuangGuru Market</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style> body { font-family: 'Inter', sans-serif; background-color: #f3f4f6; } ::-webkit-scrollbar { width: 6px; } .hide { display: none !important; } </style>
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
            <a href="laporan.php" class="flex items-center px-4 py-3 text-gray-600 hover:bg-gray-50 rounded-xl font-medium transition"><i class="fa-solid fa-chart-line w-6"></i> Laporan</a>
            <a href="produk.php" class="flex items-center px-4 py-3 bg-blue-50 text-blue-600 rounded-xl font-medium transition"><i class="fa-solid fa-box w-6"></i> Produk</a>
        </nav>
        <div class="p-4 border-t border-gray-100">
            <div class="flex items-center mb-4 px-2">
                <div class="w-9 h-9 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold mr-3 uppercase"><?= substr($_SESSION['username'], 0, 1) ?></div>
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
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
            <div>
                <h2 class="text-2xl font-bold text-gray-800">Manajemen Produk</h2>
                <p class="text-sm text-gray-500 mt-1">Kelola stok, harga, dan foto produk Anda</p>
            </div>
            <button onclick="tambahData()" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-xl shadow-lg shadow-blue-200 text-sm font-bold transition flex items-center">
                <i class="fa-solid fa-plus mr-2"></i> Tambah Produk
            </button>
        </div>
        
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse whitespace-nowrap">
                    <thead class="bg-gray-50 text-gray-600 text-xs uppercase tracking-wider">
                        <tr>
                            <th class="p-4 border-b font-semibold">Foto</th>
                            <th class="p-4 border-b font-semibold">Nama Produk</th>
                            <th class="p-4 border-b font-semibold">Qty/Satuan</th>
                            <th class="p-4 border-b font-semibold">Isi</th>
                            <th class="p-4 border-b font-semibold">Harga Beli</th>
                            <th class="p-4 border-b font-semibold">Modal Ecer</th>
                            <th class="p-4 border-b font-semibold">Harga Jual</th>
                            <th class="p-4 border-b font-semibold text-green-600">Untung Pcs</th>
                            <th class="p-4 border-b font-semibold text-green-600">Untung Box</th>
                            <th class="p-4 border-b font-semibold">Stok</th>
                            <th class="p-4 border-b font-semibold text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="produkBody" class="text-sm divide-y divide-gray-100"></tbody>
                </table>
            </div>
        </div>
    </main>

<script>
    const Toast = Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false, timer: 2000 });
    const formatRp = (angka) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(angka);

    function loadData() {
        const formData = new FormData(); formData.append('action', 'read');
        fetch('produk.php', { method: 'POST', body: formData }).then(res => res.json()).then(data => {
            const tbody = document.getElementById('produkBody');
            tbody.innerHTML = '';
            if (data.length === 0) { tbody.innerHTML = '<tr><td colspan="11" class="p-8 text-center text-gray-500">Belum ada produk.</td></tr>'; return; }
            
            data.forEach(p => {
                // Cek gambar
                const imgSrc = p.image ? `uploads/${p.image}` : `https://placehold.co/100x100/e2e8f0/64748b?text=No+Img`;
                
                tbody.innerHTML += `
                    <tr class="hover:bg-blue-50/50 transition duration-150">
                        <td class="p-4"><img src="${imgSrc}" class="w-12 h-12 rounded-lg object-cover border shadow-sm"></td>
                        <td class="p-4 font-semibold text-gray-800">${p.name}</td>
                        <td class="p-4 text-gray-600">${p.qty} ${p.unit}</td>
                        <td class="p-4 text-gray-600">${p.isi}</td>
                        <td class="p-4 text-gray-600">${formatRp(p.harga_beli)}</td>
                        <td class="p-4 text-gray-600">${formatRp(p.modal_price)}</td>
                        <td class="p-4 font-bold text-blue-600">${formatRp(p.sell_price)}</td>
                        <td class="p-4 text-green-600 font-medium">${formatRp(p.untung_pcs)}</td>
                        <td class="p-4 text-green-600 font-medium">${formatRp(p.untung_box)}</td>
                        <td class="p-4"><span class="${p.stock > 5 ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'} px-2.5 py-1 rounded-md text-xs font-bold">${p.stock}</span></td>
                        <td class="p-4 text-center">
                            <!-- Tombol Tambah Stok (Ikon Kotak Plus) -->
                            <button onclick="tambahStok(${p.id}, '${p.name}', ${p.stock})" class="text-blue-500 hover:text-blue-700 p-2 hover:bg-blue-50 rounded-lg transition mx-1" title="Tambah Stok"><i class="fa-solid fa-boxes-packing"></i></button>
                            
                            <button onclick='editData(${JSON.stringify(p)})' class="text-yellow-500 hover:text-yellow-700 p-2 hover:bg-yellow-50 rounded-lg transition mx-1" title="Edit"><i class="fa-solid fa-pen-to-square"></i></button>
                            <button onclick="hapusData(${p.id})" class="text-red-500 hover:text-red-700 p-2 hover:bg-red-50 rounded-lg transition mx-1" title="Hapus"><i class="fa-solid fa-trash"></i></button>
                        </td>
                    </tr>`;
            });
        });
    }

    // FUNGSI MODAL TAMBAH STOK
    function tambahStok(id, nama, stokSekarang) {
        Swal.fire({
            title: 'Tambah Stok Masuk',
            html: `
                <div class="text-left mt-4">
                    <p class="text-sm text-gray-600 mb-2">Produk: <b>${nama}</b></p>
                    <p class="text-sm text-gray-600 mb-4">Stok saat ini: <span class="font-bold text-blue-600">${stokSekarang}</span></p>
                    <label class="text-xs font-semibold text-gray-600">Jumlah Stok Masuk</label>
                    <input id="swal-stok" type="number" min="1" class="w-full px-4 py-3 border rounded-xl focus:ring-2 focus:ring-blue-500 outline-none text-lg font-bold text-center" placeholder="0">
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: '<i class="fa-solid fa-plus"></i> Tambahkan',
            confirmButtonColor: '#2563eb',
            cancelButtonText: 'Batal',
            didOpen: () => {
                document.getElementById('swal-stok').focus();
                // Tekan Enter untuk submit
                document.getElementById('swal-stok').addEventListener('keypress', function (e) {
                    if (e.key === 'Enter') { Swal.clickConfirm(); }
                });
            },
            preConfirm: () => {
                const jumlah = document.getElementById('swal-stok').value;
                if (!jumlah || jumlah <= 0) {
                    Swal.showValidationMessage('Masukkan jumlah yang valid (minimal 1)');
                    return false;
                }
                return jumlah;
            }
        }).then((result) => {
            if (result.isConfirmed) {
                const formData = new FormData();
                formData.append('action', 'add_stock');
                formData.append('id', id);
                formData.append('jumlah', result.value);

                fetch('produk.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(res => {
                    if (res.status === 'success') {
                        Toast.fire({ icon: 'success', title: `+${result.value} stok berhasil ditambahkan` });
                        loadData(); // Refresh tabel
                    } else {
                        Swal.fire('Gagal', res.message, 'error');
                    }
                });
            }
        });
    }

    // FUNGSI AUTO KALKULASI SAAT INPUT
    function autoCalculate() {
        const qty = parseFloat(document.getElementById('f-qty').value) || 1;
        const isi = parseFloat(document.getElementById('f-isi').value) || 1;
        const hargaBeli = parseFloat(document.getElementById('f-hargabeli').value) || 0;
        const jualEcer = parseFloat(document.getElementById('f-sell').value) || 0;
        const jualBox = parseFloat(document.getElementById('f-jualbox').value) || 0;

        // Rumus sesuai Excel
        const modalEcer = hargaBeli / (qty * isi);
        const untungPcs = jualEcer - modalEcer;
        
        // Jika jualBox kosong, hitung otomatis dari jualEcer * isi
        const hargaJualBox = jualBox > 0 ? jualBox : (jualEcer * isi);
        const untungBox = (hargaJualBox - (modalEcer * isi)) * qty;

        document.getElementById('f-modal').value = modalEcer.toFixed(2);
        document.getElementById('f-untungpcs').value = untungPcs.toFixed(2);
        document.getElementById('f-untungbox').value = untungBox.toFixed(2);
    }

    function formHTML(p = null) {
        return `
            <div class="text-left space-y-3 mt-2 text-sm">
                <!-- Upload Foto -->
                <div>
                    <label class="text-xs font-semibold text-gray-600">Foto Produk (Opsional)</label>
                    <input type="file" id="f-image" accept="image/*" class="w-full px-3 py-2 border rounded-lg bg-gray-50 text-xs">
                    ${p && p.image ? `<p class="text-xs text-blue-500 mt-1">Foto saat ini: ${p.image}</p>` : ''}
                </div>
                
                <div><label class="text-xs font-semibold text-gray-600">Nama Produk</label><input id="f-name" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 outline-none" value="${p ? p.name : ''}"></div>
                
                <div class="grid grid-cols-3 gap-2">
                    <div><label class="text-xs font-semibold text-gray-600">Qty</label><input id="f-qty" type="number" oninput="autoCalculate()" class="w-full px-3 py-2 border rounded-lg focus:ring-2 outline-none" value="${p ? p.qty : 1}"></div>
                    <div><label class="text-xs font-semibold text-gray-600">Satuan</label><input id="f-unit" class="w-full px-3 py-2 border rounded-lg focus:ring-2 outline-none" value="${p ? p.unit : 'pak'}"></div>
                    <div><label class="text-xs font-semibold text-gray-600">Isi (pcs)</label><input id="f-isi" type="number" oninput="autoCalculate()" class="w-full px-3 py-2 border rounded-lg focus:ring-2 outline-none" value="${p ? p.isi : 1}"></div>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div><label class="text-xs font-semibold text-gray-600">Harga Beli (Total)</label><input id="f-hargabeli" type="number" oninput="autoCalculate()" class="w-full px-3 py-2 border rounded-lg focus:ring-2 outline-none" value="${p ? p.harga_beli : ''}"></div>
                    <div><label class="text-xs font-semibold text-gray-600">Modal Ecer (Auto)</label><input id="f-modal" readonly class="w-full px-3 py-2 border rounded-lg bg-gray-100 text-gray-500 outline-none" value="${p ? p.modal_price : ''}"></div>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div><label class="text-xs font-semibold text-gray-600">Harga Jual Ecer</label><input id="f-sell" type="number" oninput="autoCalculate()" class="w-full px-3 py-2 border rounded-lg focus:ring-2 outline-none" value="${p ? p.sell_price : ''}"></div>
                    <div><label class="text-xs font-semibold text-gray-600">Harga Jual Box (Opsional)</label><input id="f-jualbox" type="number" oninput="autoCalculate()" class="w-full px-3 py-2 border rounded-lg focus:ring-2 outline-none" value=""></div>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div><label class="text-xs font-semibold text-gray-600">Untung pcs (Auto)</label><input id="f-untungpcs" readonly class="w-full px-3 py-2 border rounded-lg bg-gray-100 text-green-600 font-bold outline-none" value="${p ? p.untung_pcs : ''}"></div>
                    <div><label class="text-xs font-semibold text-gray-600">Untung Box (Auto)</label><input id="f-untungbox" readonly class="w-full px-3 py-2 border rounded-lg bg-gray-100 text-green-600 font-bold outline-none" value="${p ? p.untung_box : ''}"></div>
                </div>

                <div><label class="text-xs font-semibold text-gray-600">Stok Awal</label><input id="f-stock" type="number" class="w-full px-3 py-2 border rounded-lg focus:ring-2 outline-none" value="${p ? p.stock : ''}"></div>
            </div>`;
    }

    function tambahData() {
        Swal.fire({
            title: 'Tambah Produk Baru',
            html: formHTML(),
            width: '600px',
            showCancelButton: true, confirmButtonText: 'Simpan Produk', confirmButtonColor: '#2563eb',
            didOpen: () => { autoCalculate(); },
            preConfirm: () => {
                const form = new FormData();
                form.append('action', 'create');
                form.append('name', document.getElementById('f-name').value);
                form.append('qty', document.getElementById('f-qty').value);
                form.append('unit', document.getElementById('f-unit').value);
                form.append('isi', document.getElementById('f-isi').value);
                form.append('harga_beli', document.getElementById('f-hargabeli').value);
                form.append('modal', document.getElementById('f-modal').value);
                form.append('sell', document.getElementById('f-sell').value);
                form.append('untung_pcs', document.getElementById('f-untungpcs').value);
                form.append('untung_box', document.getElementById('f-untungbox').value);
                form.append('stock', document.getElementById('f-stock').value);
                
                const fileInput = document.getElementById('f-image');
                if (fileInput.files.length > 0) { form.append('image', fileInput.files[0]); }
                
                return form;
            }
        }).then((result) => {
            if (result.isConfirmed) {
                fetch('produk.php', { method: 'POST', body: result.value }).then(res => res.json()).then(res => {
                    if (res.status === 'success') { Toast.fire({icon: 'success', title: 'Produk ditambahkan'}); loadData(); }
                    else Swal.fire('Gagal', res.message, 'error');
                });
            }
        });
    }

    function editData(p) {
        Swal.fire({
            title: 'Edit Produk',
            html: formHTML(p),
            width: '600px',
            showCancelButton: true, confirmButtonText: 'Update Produk', confirmButtonColor: '#2563eb',
            preConfirm: () => {
                const form = new FormData();
                form.append('action', 'update');
                form.append('id', p.id);
                form.append('old_image', p.image || '');
                form.append('name', document.getElementById('f-name').value);
                form.append('qty', document.getElementById('f-qty').value);
                form.append('unit', document.getElementById('f-unit').value);
                form.append('isi', document.getElementById('f-isi').value);
                form.append('harga_beli', document.getElementById('f-hargabeli').value);
                form.append('modal', document.getElementById('f-modal').value);
                form.append('sell', document.getElementById('f-sell').value);
                form.append('untung_pcs', document.getElementById('f-untungpcs').value);
                form.append('untung_box', document.getElementById('f-untungbox').value);
                form.append('stock', document.getElementById('f-stock').value);
                
                const fileInput = document.getElementById('f-image');
                if (fileInput.files.length > 0) { form.append('image', fileInput.files[0]); }
                
                return form;
            }
        }).then((result) => {
            if (result.isConfirmed) {
                fetch('produk.php', { method: 'POST', body: result.value }).then(res => res.json()).then(res => {
                    if (res.status === 'success') { Toast.fire({icon: 'success', title: 'Produk diperbarui'}); loadData(); }
                    else Swal.fire('Gagal', 'Terjadi kesalahan', 'error');
                });
            }
        });
    }

    function hapusData(id) {
        Swal.fire({
            title: 'Hapus Produk?', text: "Produk akan dihapus dari sistem!", icon: 'warning',
            showCancelButton: true, confirmButtonColor: '#ef4444', confirmButtonText: 'Ya, Hapus'
        }).then((result) => {
            if (result.isConfirmed) {
                const formData = new FormData(); formData.append('action', 'delete'); formData.append('id', id);
                fetch('produk.php', { method: 'POST', body: formData }).then(res => res.json()).then(res => {
                    if (res.status === 'success') { Toast.fire({icon: 'success', title: 'Produk dihapus'}); loadData(); }
                    else Swal.fire('Gagal', 'Terjadi kesalahan', 'error');
                });
            }
        });
    }

    loadData();
</script>
</body>
</html>