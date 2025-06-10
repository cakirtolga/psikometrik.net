<?php
// Veritabanı bilgileri
define('DB_HOST', 'localhost');
define('DB_USER', 'dahisinc_anketdb');
define('DB_PASS', '3WDvyf*$fZ~s');
define('DB_NAME', 'dahisinc_anket');

// Veritabanı bağlantısını oluştur
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS
    );
    // PDO hata modunu ayarla
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Veritabanı bağlantı hatası: ' . $e->getMessage());
}
?>
