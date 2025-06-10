<?php
session_start();
// config.php dosyasının yolu projenizin yapısına göre değişebilir
// Lütfen kendi sunucu yolunuzu kontrol edin.
require_once __DIR__ . '/src/config.php';

// Veritabanı bağlantı kontrolü
if (!isset($pdo) || !$pdo instanceof PDO) {
    error_log("CRITICAL: PDO connection object (\$pdo) not created or found in src/config.php");
    // Kullanıcı dostu hata gösterimi
     echo '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><title>Hata</title></head><body>';
     echo '<div style="border: 1px solid red; padding: 15px; margin: 20px; background-color: #fee2e2; color: #b91c1c; font-family: sans-serif;">';
     echo '<b>Kritik Hata:</b> Veritabanı bağlantısı kurulamadı. Lütfen sistem yöneticisi ile iletişime geçin.';
     echo '</div></body></html>';
    exit; // Betiği durdur
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Formdan gelen verileri al ve trim ile boşlukları temizle
    $username = trim($_POST['username'] ?? '');
    $full_name = trim($_POST['full_name'] ?? ''); // Yeni: Ad Soyad alındı
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? ''; // Şifre trim edilmez
    $institution_name = trim($_POST['institution_name'] ?? ''); // Kurum adı alındı

    // Tüm alanların dolu olup olmadığını kontrol et
    if (empty($username) || empty($full_name) || empty($email) || empty($password) || empty($institution_name)) {
        $error = "Lütfen tüm alanları doldurun.";
    } else {
        try {
            // Kullanıcı (e-posta) zaten var mı kontrol et
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->rowCount() > 0) {
                $error = "Bu e-posta adresi zaten kullanılıyor.";
            } else {
                // Şifreyi hashle (Güvenlik için)
                $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

                // Kullanıcıyı 'users' tablosuna kaydet
                // full_name ve institution_name sütunlarını INSERT sorgusuna ekledik
                // role sütunu veritabanında DEFAULT 'user' olarak ayarlı olmalı
                $stmt = $pdo->prepare("INSERT INTO users (username, full_name, email, password, institution_name) VALUES (?, ?, ?, ?, ?)");

                // Sorguyu çalıştır
                // execute metoduna full_name değeri eklendi
                if ($stmt->execute([$username, $full_name, $email, $hashedPassword, $institution_name])) {
                    // Kayıt başarılı ise giriş sayfasına yönlendir
                    $_SESSION['success'] = "Kayıt başarılı! Şimdi giriş yapabilirsiniz.";
                    header('Location: login.php');
                    exit();
                } else {
                    // Sorgu çalışırken hata oluşursa
                    $error = "Kayıt sırasında bir veritabanı hatası oluştu.";
                    error_log("User registration DB error: " . print_r($stmt->errorInfo(), true)); // Hata detayını logla
                }
            }
        } catch (PDOException $e) {
            // PDO hatalarını yakala
            $error = "Kayıt sırasında bir hata oluştu: " . $e->getMessage();
            error_log("User registration PDO Exception: " . $e->getMessage());
        } catch (Exception $e) {
             // Diğer hataları yakala
             $error = "Kayıt sırasında beklenmeyen bir hata oluştu: " . $e->getMessage();
             error_log("User registration Exception: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Kayıt Ol - Psikometrik.Net</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="icon" href="/favicon.png" type="image/png">
  <style>
    /* Genel body stili - index.php ile uyumlu */
    body {
        font-family: sans-serif;
        line-height: 1.6;
        background-color: #f8fafc; /* index.php ile aynı arkaplan */
        color: #334155;
        display: flex;
        flex-direction: column;
        min-height: 100vh;
    }
    main {
        flex-grow: 1;
        display: flex; /* İçeriği ortalamak için flexbox */
        justify-content: center; /* Yatayda ortala */
        align-items: center; /* Dikeyda ortala */
        padding: 1rem; /* Küçük ekranlarda boşluk */
    }

    /* Navigasyon çubuğu stilleri - index.php ile aynı */
    nav {
        background-color: #ffffff;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        padding: 1rem 2rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .logo-area { display: flex; align-items: center; gap: 0.75rem; }
    .logo-area img { height: 4rem; vertical-align: middle; }

    /* Sağ Navigasyon */
    .nav-actions { display: flex; align-items: center; gap: 0.75rem; }

     /* Genel Buton Stilleri - index.php'den alındı */
    .btn {
        padding: 0.6rem 1.25rem;
        border-radius: 0.375rem;
        color: white;
        font-weight: 500;
        transition: background-color 0.2s ease-in-out;
        display: inline-block;
        text-align: center;
        text-decoration: none;
        border: none;
        cursor: pointer;
    }

    /* Navigasyon butonları için renkler - index.php'den alındı */
    .btn-primary { background-color: #0ea5e9; } .btn-primary:hover { background-color: #0284c7; }
    .btn-secondary { background-color: #64748b; } .btn-secondary:hover { background-color: #475569; }
    .btn-danger { background-color: #ef4444; } .btn-danger:hover { background-color: #dc2626; }
    .btn-success { background-color: #22c55e; } .btn-success:hover { background-color: #16a34a; } /* Kayıt butonu için kullanılabilir */


    /* İkon Buton (Giriş/Kayıt) - index.php'den alındı */
    .icon-btn { padding: 0.6rem; border-radius: 0.375rem; color: white; transition: background-color 0.2s ease-in-out; display: inline-flex; align-items: center; justify-content: center; width: 2.75rem; height: 2.75rem; text-decoration: none; }
    .icon-btn svg { width: 1.5rem; height: 1.5rem; fill: currentColor; }
    .btn-primary.icon-btn { background-color: #0ea5e9; } .btn-primary.icon-btn:hover { background-color: #0284c7; }
    .btn-success.icon-btn { background-color: #22c55e; } .btn-success.icon-btn:hover { background-color: #16a34a; }


    /* Kayıt Formu Container Stili */
    .register-container {
      background-color: #ffffff;
      padding: 2rem; /* padding artırıldı */
      border-radius: 0.5rem;
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); /* Daha belirgin gölge */
      width: 100%;
      max-width: 450px; /* Maksimum genişlik biraz artırıldı */
      margin-top: 2rem; /* Navigasyon altından boşluk */
      margin-bottom: 2rem; /* Footer üstünden boşluk */
    }

    .register-container h1 {
      text-align: center;
      color: #1e293b; /* Koyu gri */
      font-size: 1.75rem; /* Başlık boyutu */
      font-weight: 700;
      margin-bottom: 1.5rem;
      border-bottom: 2px solid #e2e8f0; /* Alt çizgi */
      padding-bottom: 0.75rem;
    }

    /* Form Elemanları Stili */
    .form-input {
      width: 100%;
      padding: 0.75rem; /* Padding artırıldı */
      margin-bottom: 1rem; /* Boşluk artırıldı */
      border: 1px solid #cbd5e1; /* Açık gri kenarlık */
      border-radius: 0.375rem; /* Köşe yuvarlama */
      box-sizing: border-box; /* Padding ve border genişliğe dahil */
      font-size: 1rem;
      color: #475569;
    }
     .form-input:focus {
         outline: none;
         border-color: #0ea5e9; /* Mavi odak rengi */
         box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.2); /* Mavi odak gölgesi */
     }


    /* Hata Mesajı Stili */
    .error-message {
      background-color: #fee2e2; /* Kırmızı arkaplan */
      color: #b91c1c; /* Koyu kırmızı metin */
      padding: 0.75rem;
      margin-bottom: 1.5rem;
      border-radius: 0.375rem;
      border: 1px solid #fca5a5; /* Kırmızı kenarlık */
      font-size: 0.9rem;
      text-align: left;
    }

    /* Footer - index.php ile aynı */
    footer {
        background-color: #e2e8f0;
        color: #475569;
        padding: 2rem 1rem;
        margin-top: auto; /* Sayfanın en altına it */
        text-align: center;
        font-size: 0.875rem;
    }
    footer a { color: #334155; text-decoration: underline; margin: 0 0.5rem; transition: color 0.2s ease-in-out; }
    footer a:hover { color: #0ea5e9; }
    .footer-links span { margin: 0 0.25rem; }

     /* Responsive Ayarlar (Tailwind'in md breakpoint'i gibi) */
     @media (min-width: 768px) {
         .container {
             padding: 0; /* Orta ve büyük ekranlarda container padding'i */
         }
         main {
              padding: 2rem; /* Orta ve büyük ekranlarda main padding'i */
         }
     }

  </style>
</head>
<body>
    <nav>
        <div class="logo-area">
            <a href="index.php"><img src="/assets/Psikometrik.png" alt="Psikometrik.Net Logo" ></a>
        </div>

        <div class="nav-actions">
            <?php if (isset($_SESSION['user_id'])): ?>
                <span class="mr-4 text-gray-700 font-medium">👤 <?php echo htmlspecialchars($_SESSION['username']); ?></span>
                <?php if (isset($_SESSION['role']) && ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'super-admin')): ?>
                    <a href="admin/dashboard.php" class="btn btn-secondary mr-2">Yönetim Paneli</a>
                <?php endif; ?>
                 <a href="logout.php" class="btn btn-danger">Çıkış</a>
            <?php else: ?>
                 <a href="login.php" class="icon-btn btn-primary mr-2" title="Giriş Yap">
                     <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.38 0 2.5 1.12 2.5 2.5S13.38 10 12 10 9.5 8.88 9.5 7.5 10.62 5 12 5zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"/></svg>
                 </a>
                 <a href="register.php" class="icon-btn btn-success" title="Kayıt Ol">
                     <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm5 11h-4v4h-2v-4H7v-2h4V7h2v4h4v2z"/></svg>
                 </a>
            <?php endif; ?>
        </div>
    </nav>

    <main>
        <div class="register-container">
            <h1>Kayıt Ol</h1>

            <?php if (isset($error)): ?>
              <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="text" name="username" placeholder="Kullanıcı Adı" class="form-input" required>
                <input type="text" name="full_name" placeholder="Ad Soyad" class="form-input" required>
                <input type="email" name="email" placeholder="E-posta" class="form-input" required>
                <input type="password" name="password" placeholder="Şifre" class="form-input" required>
                <input type="text" name="institution_name" placeholder="Kurum Adı" class="form-input" required>

                <button type="submit" class="btn btn-success w-full mt-4">Kayıt Ol</button>
            </form>

            <p class="mt-6 text-center text-gray-600">
                Zaten üye misiniz?
                <a href="login.php" class="text-blue-600 hover:underline">Giriş Yap</a>
            </p>
        </div>
    </main>

    <footer class="w-full py-6 bg-gray-200 text-gray-600 text-sm text-center">
        <div class="container mx-auto footer-links">
             <a href="kullanim-kosullari.php">Kullanım Koşulları</a> <span>|</span>
             <a href="kvkk.php">KVKK</a> <span>|</span>
             <a href="cerez-politikasi.php">Çerez Politikası</a> <span>|</span>
             <a href="uyelik-sozlesmesi.php">Üyelik Sözleşmesi</a> <br class="sm:hidden"> <span class="hidden sm:inline">|</span>
             <a href="acik-riza-metni.php">Açık Rıza Metni</a> <span>|</span>
             <a href="veri-ihlali.php">Veri İhlali Bildirim Prosedürü</a> <span>|</span>
             <a href="yasal-uyari.php">Yasal Uyarı</a>
        </div>
        <p class="mt-4">&copy; <?= date('Y') ?> Psikometrik.Net - Tüm hakları saklıdır.</p>
    </footer>

</body>
</html>
