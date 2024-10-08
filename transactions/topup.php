<?php
/*
*******************************************************************************************************************
* Warning!!!, Tidak untuk diperjual belikan!, Cukup pakai sendiri atau share kepada orang lain secara gratis
*******************************************************************************************************************
* Dibuat oleh Ikromul Umam https://t.me/arnetadotid
*******************************************************************************************************************
* © 2024 Arneta.ID By https://fb.me/umblox
*******************************************************************************************************************
*/

session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '/www/raddash/telegram/handlers/topup_handler.php';
#require_once '/www/raddash/config/config.php';
require_once '/www/raddash/config/database.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$db = getDbConnection();

if (!$db) {
    die('Connection failed: ' . mysqli_connect_error());
}

// Cek apakah pengguna sudah login
if (!isset($_SESSION['username'])) {
    header('Location: /raddash/views/login.php');
    exit();
}

// Ambil informasi pengguna dari session
$username = $_SESSION['username'];

// Periksa apakah pengguna adalah admin
$isAdmin = false;
$query = 'SELECT is_admin FROM users WHERE username = ?';
$stmt = $db->prepare($query);
if ($stmt === false) {
    die('Error prepare statement: ' . $db->error);
}
$stmt->bind_param('s', $username);
$stmt->execute();
$stmt->bind_result($is_admin);
$stmt->fetch();
$stmt->close();
$isAdmin = $is_admin == 1;

// Fungsi untuk mendapatkan saldo pengguna
function getUserBalance($username) {
    global $db;

    $query = 'SELECT balance FROM users WHERE username = ?';
    $stmt = $db->prepare($query);
    if ($stmt === false) {
        die('Error prepare statement: ' . $db->error);
    }
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $stmt->bind_result($balance);
    $stmt->fetch();
    $stmt->close();

    return $balance;
}

// Fungsi untuk mengirim notifikasi (implementasikan sesuai dengan kebutuhan Anda)
function sendNotification($username, $message) {
    // Token bot Telegram
    $token = 'BOT_TOKEN';

    // ID chat admin
    $adminChatId = 'ADMIN_CHAT_ID';

    // URL API Telegram
    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';

    // Data notifikasi
    $data = array(
        'chat_id' => $adminChatId,
        'text' => $message
    );

    // Kirim notifikasi
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);
}

// Tangani permintaan top-up jika POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isAdmin && isset($_POST['amount'])) {
    $amount = floatval($_POST['amount']);

    // Daftar jumlah top-up default
    $defaultAmounts = [3000, 5000, 10000, 20000, 50000, 100000];

    if (!in_array($amount, $defaultAmounts)) {
        $_SESSION['status_message'] = "Jumlah top-up tidak valid. Pilih jumlah yang sesuai.";
        header('Location: /raddash/transactions/topup.php');
        exit();
    }

    // Cek apakah ada permintaan top-up yang belum dikonfirmasi dalam 1 hari terakhir
    $query = 'SELECT COUNT(*) FROM topup_requests WHERE username = ? AND amount = ? AND status = "pending" AND created_at >= NOW() - INTERVAL 1 DAY';
    $stmt = $db->prepare($query);
    if ($stmt === false) {
        die('Error prepare statement: ' . $db->error);
    }
    $stmt->bind_param('sd', $username, $amount);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    if ($count > 0) {
        $_SESSION['status_message'] = "Anda sudah memiliki permintaan top-up yang menunggu konfirmasi untuk jumlah ini.";
        header('Location: /raddash/transactions/topup.php');
        exit();
    }

// Ambil user_id dan telegram_id dari username
$query = 'SELECT id, telegram_id FROM users WHERE username = ?';
$stmt = $db->prepare($query);
if ($stmt === false) {
    die('Error prepare statement: ' . $db->error);
}
$stmt->bind_param('s', $username);
$stmt->execute();
$stmt->bind_result($user_id, $from_id);
$stmt->fetch();
$stmt->close();

// Masukkan permintaan top-up baru
$query = 'INSERT INTO topup_requests (user_id, username, amount, status) VALUES (?, ?, ?, "pending")';
$stmt = $db->prepare($query);
if ($stmt === false) {
    die('Error prepare statement: ' . $db->error);
}
$stmt->bind_param('ssd', $user_id, $username, $amount);
$stmt->execute();
$stmt->close();

// Notifikasi ke admin untuk konfirmasi
$admin_message = "Permintaan top-up baru:\n\n";
$admin_message .= "Telegram ID: $from_id\n";
$admin_message .= "Username: @$username\n";
$admin_message .= "Jumlah: $amount kredit\n\n";
$admin_message .= "Silakan konfirmasi atau tolak permintaan ini.";

// Dapatkan semua admin
$admins = getAdminIds();

foreach ($admins as $admin_id) {
    $keyboard = [
        [
            ['text' => '✅ Terima', 'callback_data' => "admin_confirm_topup,$user_id,$amount"],
            ['text' => '❌ Tolak', 'callback_data' => "admin_reject_topup,$user_id"]
        ]
    ];
    $reply_markup = ['inline_keyboard' => $keyboard];

    sendMessage($admin_id, $admin_message, $reply_markup);
}

// Definisikan amount yang dipilih user sebagai $selected_amount
$selected_amount = $amount;

$_SESSION['status_message'] = "Permintaan top-up sebesar $amount kredit sedang menunggu konfirmasi admin.";
header('Location: /raddash/transactions/topup.php');
exit();
}
// Jika admin, tangani konfirmasi atau penolakan top-up
if ($isAdmin && isset($_GET['action']) && isset($_GET['username']) && isset($_GET['amount'])) {
    $action = $_GET['action'];
    $username = $_GET['username'];
    $amount = floatval($_GET['amount']);

    if ($action === 'confirm') {
        // Cek apakah permintaan top-up ada
        $query = 'SELECT user_id FROM topup_requests WHERE username = ? AND amount = ? AND status = "pending"';
        $stmt = $db->prepare($query);
        if ($stmt === false) {
            die('Error prepare statement: ' . $db->error);
        }
        $stmt->bind_param('sd', $username, $amount);
        $stmt->execute();
        $stmt->bind_result($user_id);
        $stmt->fetch();
        $stmt->close();
 // Hapus tombol konfirmasi di bot Telegram
 //   $telegram_id = $user_id;
 //   $message_id = $message_id;
 //   editMessage($telegram_id, $message_id, "Permintaan top-up telah dikonfirmasi.");
 //
        if ($user_id) {
            // Tambahkan saldo pengguna
            $query = 'UPDATE users SET balance = balance + ? WHERE username = ?';
            $stmt = $db->prepare($query);
            if ($stmt === false) {
                die('Error prepare statement: ' . $db->error);
            }
            $stmt->bind_param('ds', $amount, $username);
            $stmt->execute();
            $stmt->close();

            // Ubah status permintaan top-up
            $query = 'UPDATE topup_requests SET status = "confirmed" WHERE username = ? AND amount = ? AND status = "pending"';
            $stmt = $db->prepare($query);
            if ($stmt === false) {
                die('Error prepare statement: ' . $db->error);
            }
            $stmt->bind_param('sd', $username, $amount);
            $stmt->execute();
            $stmt->close();

            // Kirim notifikasi ke pelanggan
            $message = "Permintaan top-up Anda sebesar $amount telah dikonfirmasi. Saldo Anda saat ini adalah " . getUserBalance($username);
            sendNotification($username, $message);

            $_SESSION['status_message'] = "Top-up untuk pengguna @$username sebesar $amount telah dikonfirmasi.";
        } else {
            $_SESSION['status_message'] = "Data top-up tidak ditemukan atau sudah diproses.";
        }
        header('Location: /raddash/views/admin.php');
        exit();
    } elseif ($action === 'reject') {
        // Cek apakah permintaan top-up ada
        $query = 'SELECT amount FROM topup_requests WHERE username = ? AND amount = ? AND status = "pending"';
        $stmt = $db->prepare($query);
        if ($stmt === false) {
            die('Error prepare statement: ' . $db->error);
        }
        $stmt->bind_param('sd', $username, $amount);
        $stmt->execute();
        $stmt->bind_result($amount_found);
        $stmt->fetch();
        $stmt->close();
 // Hapus tombol konfirmasi di bot Telegram
 //   $telegram_id = $user_id;
 //   $message_id = $message_id;
 //   editMessage($telegram_id, $message_id, "Permintaan top-up telah ditolak.");
 //
        if ($amount_found) {
            // Ubah status permintaan top-up
            $query = 'UPDATE topup_requests SET status = "rejected" WHERE username = ? AND amount = ? AND status = "pending"';
            $stmt = $db->prepare($query);
            if ($stmt === false) {
                die('Error prepare statement: ' . $db->error);
            }
            $stmt->bind_param('sd', $username, $amount);
            $stmt->execute();
            $stmt->close();

            // Kirim notifikasi ke pelanggan
            $message = "Permintaan top-up Anda sebesar $amount telah ditolak. Saldo Anda tetap " . getUserBalance($username);
            sendNotification($username, $message);

            $_SESSION['status_message'] = "Top-up untuk pengguna @$username sebesar $amount telah ditolak.";
        } else {
            $_SESSION['status_message'] = "Data top-up tidak ditemukan atau sudah diproses.";
        }
        header('Location: /raddash/views/admin.php');
        exit();
    } else {
        $_SESSION['status_message'] = "Aksi tidak dikenal atau Anda tidak memiliki izin.";
        header('Location: /raddash/views/admin.php');
        exit();
    }
}

// Ambil saldo pengguna jika bukan admin
$pendingRequest = false;
$statusMessage = '';
if (!$isAdmin) {
    $query = 'SELECT balance FROM users WHERE username = ?';
    $stmt = $db->prepare($query);
    if ($stmt === false) {
        die('Error prepare statement: ' . $db->error);
    }
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $stmt->bind_result($balance);
    $stmt->fetch();
    $stmt->close();

    // Cek status permintaan top-up
    $query = 'SELECT amount, status FROM topup_requests WHERE username = ? ORDER BY created_at DESC LIMIT 1';
    $stmt = $db->prepare($query);
    if ($stmt === false) {
        die('Error prepare statement: ' . $db->error);
    }
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $stmt->bind_result($amount, $status);
    if ($stmt->fetch()) {
        if ($status === 'pending') {
            $pendingRequest = true;
            $statusMessage = "Permintaan top-up sebesar $amount sedang menunggu konfirmasi.
Pastikan sudah melakukan pembayaran kepada admin sebesar $amount secara cash ataupun via dana/ovo/shoppepay ke nomor 085729038722";
        } elseif ($status === 'confirmed') {
            $statusMessage = "Permintaan top-up sebesar $amount telah dikonfirmasi. Saldo Anda saat ini adalah $balance.";
        } elseif ($status === 'rejected') {
            $statusMessage = "Permintaan top-up sebesar $amount telah ditolak. Saldo Anda tetap $balance.";
        }
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Top-Up Saldo Pelanggan Arneta.ID</title>
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="/raddash/assets/css/bootstrap.min.css">
    <style>
        .topup-form-container {
            max-width: 400px; /* Membatasi lebar form */
            margin: 0 auto; /* Menempatkan form di tengah */
            padding: 20px;
            background-color: #d0efff; /* Latar belakang biru muda */
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        .topup-form-container label {
            font-weight: bold;
            color: blue;
        }
        .btn-custom {
            background-color: #007bff;
            color: white;
            padding: 10px 20px;
            font-size: 16px;
            border-radius: 5px;
        }
        .btn-custom:hover {
            background-color: #0056b3;
        }
    </style>
</head>
<body>
    <header>
        <!-- Menggunakan Bootstrap class untuk styling header -->
        <div class="bg-primary text-white text-center py-4">
            <?php if ($isAdmin): ?>
               <h4>Pilih nominal top-up:</h4>
                <?php
                $query = 'SELECT username, amount, created_at, status FROM topup_requests WHERE status = "pending" ORDER BY created_at DESC';
                $result = $db->query($query);
                if ($result->num_rows > 0): ?>
                    <table class="table table-striped table-bordered mt-4">
                        <thead class="thead-dark">
                            <tr>
                                <th>Username</th>
                                <th>Jumlah</th>
                                <th>Tanggal</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['username']); ?></td>
                                    <td>Rp <?php echo number_format($row['amount'], 0, ',', '.'); ?></td>
                                    <td><?php echo htmlspecialchars($row['created_at']); ?></td>
                                    <td>
                                        <a href="?action=confirm&username=<?php echo urlencode($row['username']); ?>&amount=<?php echo urlencode($row['amount']); ?>" class="btn btn-success btn-sm">Konfirmasi</a>
                                        <a href="?action=reject&username=<?php echo urlencode($row['username']); ?>&amount=<?php echo urlencode($row['amount']); ?>" class="btn btn-danger btn-sm">Tolak</a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="mt-4">Belum ada permintaan top-up.</p>
                <?php endif; ?>
            <?php else: ?>
                <h1 class="display-4">Top Up Saldo Arneta.ID</h1>
                <p class="lead">Saldo Anda saat ini: Rp <?php echo number_format($balance, 0, ',', '.'); ?></p>
                <?php if ($pendingRequest): ?>
                    <div class="alert alert-info mt-4" role="alert">
                        <?php echo htmlspecialchars($statusMessage); ?>
                    </div>
                    <form action="/raddash/views/dashboard.php" method="GET" class="mt-4">
                        <button type="submit" class="btn btn-primary">Kembali ke Dashboard</button>
                    </form>
                <?php else: ?>
    <div class="container mt-5">
        <div class="topup-form-container">
<!-- Gantikan dropdown dengan daftar pilihan nominal -->
<h4 style="color: #265ad4;">Pilih nominal top-up:</h4>
<form id="topupForm" method="POST" action="topup.php"> <!-- Ganti dengan action yang sesuai -->
    <input type="hidden" id="selectedAmount" name="amount" value=""> <!-- Menyimpan nominal yang dipilih -->
    <?php
    $defaultAmounts = [3000, 5000, 10000, 20000, 50000, 100000]; // Nominal yang tersedia
    foreach ($defaultAmounts as $amount) {
        echo '<div class="nominal-item">
                <span class="nominal-amount">Rp ' . number_format($amount, 0, ',', '.') . '</span>
                <button type="button" class="btn btn-primary pilih-nominal" data-amount="' . $amount . '">Pilih</button>
              </div>';
    }
    ?>
</form>

<div id="konfirmasiPopup">
    <div class="popup-content">
        <h4>Konfirmasi Top-Up</h4>
        <p>Anda yakin ingin melakukan top-up sebesar <span id="jumlahTopup"></span>?</p>
        <button class="btn-confirm" style="background-color: #28a745; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer;">Ya</button>
        <button class="btn-cancel" style="background-color: #dc3545; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer;">Tidak</button>
    </div>
</div>

<!-- CSS -->
<style>
.nominal-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px;
    margin-bottom: 10px;
    border: 1px solid #ddd;
    border-radius: 5px;
    background-color: #265ad4;
}

.nominal-item:hover {
    background-color: #35f06a;
}

.nominal-amount {
    font-size: 16px;
    font-weight: bold;
}

.pilih-nominal {
    background-color: green;
    color: white;
    border: none;
    padding: 6px 12px;
    font-size: 14px;
    border-radius: 5px;
    cursor: pointer;
}

.pilih-nominal:hover {
    background-color: #0056b3;
}

.pilih-nominal:focus {
    outline: none;
}

/* Popup CSS */
#konfirmasiPopup {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    display: none;
    justify-content: center;
    align-items: center;
}

#konfirmasiPopup .popup-content {
    background-color: #265ad4;
    padding: 20px;
    border-radius: 5px;
    width: 300px;
    text-align: center;
}

#konfirmasiPopup .popup-content h4 {
    margin-bottom: 20px;
}

#konfirmasiPopup .btn-confirm, #konfirmasiPopup .btn-cancel {
    margin: 5px;
    padding: 10px 20px;
    cursor: pointer;
}
</style>

<!-- Tambahkan JavaScript untuk menampilkan popup -->
<script>
document.querySelectorAll('.pilih-nominal').forEach(function(button) {
    button.addEventListener('click', function() {
        var amount = this.getAttribute('data-amount');
        document.getElementById('jumlahTopup').innerText = 'Rp ' + new Intl.NumberFormat('id-ID').format(amount);
        document.getElementById('selectedAmount').value = amount; // Menyimpan nominal terpilih di input hidden
        document.getElementById('konfirmasiPopup').style.display = 'flex';
    });
});

document.querySelector('.btn-cancel').addEventListener('click', function() {
    document.getElementById('konfirmasiPopup').style.display = 'none';
});

document.querySelector('.btn-confirm').addEventListener('click', function() {
    document.getElementById('topupForm').submit(); // Submit form setelah konfirmasi
});
</script>

                    <form action="/raddash/views/dashboard.php" method="GET" class="mt-4">
                        <button type="submit" class="btn btn-primary">Kembali ke Dashboard</button>
                    </form>

        </div>
    </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </header>

    <!-- Bootstrap JS (opsional jika diperlukan interaksi JS Bootstrap) -->
    <script src="/raddash/assets/js/bootstrap.bundle.min.js"></script>
</body>

</html>
