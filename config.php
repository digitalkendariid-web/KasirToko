<?php
// config.php
session_start();
$host = "localhost";
$user = "root";
$pass = "";
$db   = "ruangguru_market";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) { die("Koneksi gagal: " . $conn->connect_error); }

// Fungsi Helper Format Rupiah
function rupiah($angka) {
    return "Rp " . number_format($angka, 0, ',', '.');
}

// Cek Login
function checkLogin() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: index.php");
        exit;
    }
}
?>