<?php

// Güçlendirilmiş Hata Raporlama Ayarları (Geliştirme ortamı için)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Oturumu başlat
session_start();

// Eğer kullanıcı zaten oturum açmış ve admin yetkisine sahipse, dashboard'a yönlendir
// $_SESSION['is_admin'] değişkeninin tanımlı olup olmadığını ve true olup olmadığını kontrol et
if (isset($_SESSION['user_id']) && isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) {
    header('Location: dashboard.php');
    exit();
}

// Veritabanı yapılandırma dosyasını dahil et
// Kullanıcının orijinal kodundaki mutlak yolu kullanıyorum.
// Eğer bu yol sunucunuzda sorun çıkarırsa, './src/config.php' gibi göreceli bir yol deneyebilirsiniz.
require_once '/home/dahisinc/public_html/testanket/src/config.php';

$error = ''; // Hata mesajı için değişken

// Form gönderildiyse
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? ''; // Şifre trimlenmez

    if (empty($email) || empty($password)) {
        $error = "Lütfen e-posta ve şifrenizi girin.";
    } else {
        try {
            // Kullanıcıyı e-postaya göre veritabanında ara ve role sütununu çek
            // Düzeltme: SELECT sorgusunda 'is_admin' yerine 'role' kullanıldı
            $stmt = $pdo->prepare("SELECT id, username, password, role FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Kullanıcı bulunduysa ve şifre doğruysa
            if ($user && password_verify($password, $user['password'])) {
                // Kullanıcının admin veya süper admin rolüne sahip olup olmadığını kontrol et
                // Düzeltme: 'is_admin' kontrolü yerine 'role' kontrolü yapıldı
                if ($user['role'] === 'admin' || $user['role'] === 'super_admin') {
                    // Giriş başarılı, oturum değişkenlerini ayarla
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    // Oturumda admin yetkisini boolean olarak işaretle
                    $_SESSION['is_admin'] = true;

                    // Dashboard sayfasına yönlendir
                    header('Location: dashboard.php');
                    exit();
                } else {
                    // Kullanıcı bulundu ama admin veya süper admin değil
                    $error = "Bu sayfaya erişim yetkiniz yok.";
                }
            } else {
                // Kullanıcı bulunamadı veya şifre yanlış
                $error = "Geçersiz e-posta veya şifre.";
            }

        } catch (PDOException $e) {
            $error = "Veritabanı hatası oluştu: " . $e->getMessage();
            error_log("Login PDO Exception: " . $e->getMessage());
            // Üretim ortamında daha genel bir hata mesajı gösterilebilir
            // $error = "Giriş yapılırken bir hata oluştu.";
        }
    }
}

?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Giriş | Anket Platformu</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Genel body stili */
        body {
            font-family: sans-serif;
            line-height: 1.6;
            background-color: #f8fafc; /* Çok açık mavi-gri arka plan */
            color: #334155; /* Koyu gri metin rengi */
            display: flex; /* İçeriği ortalamak için flex kullan */
            justify-content: center; /* Yatayda ortala */
            align-items: center; /* Dikeyde ortala */
            min-height: 100vh; /* En az ekran yüksekliği kadar yer kapla */
            padding: 20px; /* Küçük ekranlarda padding */
        }

        /* Logo alanı stili */
        .logo-area {
            display: flex;
            align-items: center; /* Öğeleri dikeyde ortala */
            gap: 0.75rem; /* Logo ve yazı arasına boşluk */
            margin-bottom: 2rem; /* Formun üstünde boşluk */
            justify-content: center; /* Logo alanını yatayda ortala */
        }

        /* Logo resmi stili */
        .logo-area img {
            height: 6rem; /* Logo yüksekliği ayarlandı */
            vertical-align: middle;
        }

        /* Site adı linki stili */
        .logo-area a {
            font-size: 1.5rem; /* text-xl */
            font-weight: bold; /* font-bold */
            color: #0e7490; /* Çivit mavisi tonu */
            text-decoration: none; /* Alt çizgiyi kaldır */
        }

        /* Giriş formu konteyneri */
        .login-container {
            background-color: #ffffff; /* Beyaz arka plan */
            padding: 2.5rem; /* p-10 gibi */
            border-radius: 0.5rem; /* rounded */
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1); /* Gölge */
            width: 100%;
            max-width: 400px; /* Maksimum genişlik */
            text-align: left; /* Metin hizalaması */
        }

        /* Form başlığı */
        .login-container h1 {
            font-size: 1.75rem; /* text-2xl gibi */
            font-weight: bold;
            text-align: center;
            margin-bottom: 1.5rem; /* mb-6 gibi */
            color: #1e293b; /* Koyu gri */
        }

        /* Form grupları (label + input) */
        .form-group {
            margin-bottom: 1.5rem; /* mb-6 gibi */
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem; /* mb-2 gibi */
            font-weight: 600; /* font-semibold */
            color: #475569; /* Orta gri */
        }

        .form-group input[type="email"],
        .form-group input[type="password"] {
            width: 100%;
            padding: 0.75rem; /* p-3 gibi */
            border: 1px solid #cbd5e1; /* Tailwind border-gray-300 */
            border-radius: 0.375rem; /* rounded-md gibi */
            box-sizing: border-box; /* Padding ve border genişliğe dahil */
            font-size: 1rem;
            color: #334155; /* Koyu gri metin */
        }

        .form-group input[type="email"]:focus,
        .form-group input[type="password"]:focus {
            outline: none;
            border-color: #0ea5e9; /* Tailwind sky-500 */
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.2); /* Mavi odak halkası */
        }

        /* Giriş butonu */
        .btn-login {
            width: 100%;
            padding: 0.75rem; /* p-3 gibi */
            border-radius: 0.375rem; /* rounded-md gibi */
            background-color: #0ea5e9; /* Tailwind sky-500 */
            color: white;
            font-weight: 600; /* font-semibold */
            border: none;
            cursor: pointer;
            transition: background-color 0.2s ease-in-out;
        }

        .btn-login:hover {
            background-color: #0284c7; /* Tailwind sky-600 */
        }

        /* Hata mesajı stili */
        .error-message {
             color: #b91c1c; /* Tailwind red-700 */
             background-color: #fee2e2; /* Tailwind red-100 */
             padding: 1rem;
             border-radius: 0.5rem;
             margin-bottom: 1.5rem;
             border: 1px solid #fca5a5; /* Light red border */
             font-weight: bold; /* Make text bold */
             text-align: center;
        }

    </style>
</head>
<body>

    <div class="login-container">
        <div class="logo-area">
            <a href="index.php"><img src="/assets/Psikometrik.png" alt="Psikometrik.Net Logo" ></a>
        </div>

        <h1>Admin Girişi</h1>

        <?php if (!empty($error)): ?>
            <div class="error-message"><?= $error ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label for="email">E-posta Adresi:</label>
                <input type="email" name="email" id="email" required>
            </div>

            <div class="form-group">
                <label for="password">Şifre:</label>
                <input type="password" name="password" id="password" required>
            </div>

            <button type="submit" class="btn-login">Giriş Yap</button>
        </form>
    </div>

</body>
</html>
