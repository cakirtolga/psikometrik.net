<?php

// Güçlendirilmiş Hata Raporlama Ayarları (Geliştirme ortamı için)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Oturumu başlat
session_start();

// Veritabanı yapılandırma dosyasını dahil et
require_once __DIR__ . '/src/config.php'; // config.php yolu doğru olmalı

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


// Anketleri veritabanından çek (Gerekli sütunlar + money)
try {
    // Sadece gerekli sütunları ve yeni 'money' sütununu çekiyoruz
    $stmt = $pdo->query("SELECT id, title, description, created_at, money FROM surveys ORDER BY created_at DESC");
    $surveys = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Veritabanı hatası durumunda boş bir dizi ata ve hatayı logla
    $surveys = [];
    error_log("Index PDO Exception: " . $e->getMessage());
    // Kullanıcıya genel bir hata mesajı gösterilebilir (isteğe bağlı)
    $db_error_message = "Anketler yüklenirken bir sorun oluştu.";
}

?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anket Platformu</title>
    <link rel="icon" href="/favicon.png" type="image/png">

    <script src="https://cdn.tailwindcss.com"></script> <?php // Tailwind CSS hala kullanılıyor varsayımı ?>
    <style>
        /* Genel body stili */
        body {
            font-family: sans-serif; line-height: 1.6; background-color: #f8fafc;
            color: #334155; display: flex; flex-direction: column; min-height: 100vh;
        }
        main { flex-grow: 1; }

        /* Navigasyon çubuğu stilleri */
        nav {
            background-color: #ffffff; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center;
        }
        .logo-area { display: flex; align-items: center; gap: 0.75rem; }
        .logo-area img { height: 4rem; /* Biraz küçülttüm */ vertical-align: middle; } /* Logo */

        /* Sağ Navigasyon */
        .nav-actions { display: flex; align-items: center; gap: 0.75rem; /* Butonlar arası boşluk */}

        /* Anket kartları grid */
        .survey-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.5rem; }

        /* Anket kartı */
        .survey-card {
            background-color: #ffffff; padding: 1.5rem; border-radius: 0.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08); transition: box-shadow 0.3s ease-in-out;
            border: 1px solid #e2e8f0; display: flex; flex-direction: column;
        }
        .survey-card:hover { box-shadow: 0 5px 10px rgba(0, 0, 0, 0.1); }
        .survey-card h2 { font-size: 1.25rem; font-weight: 600; margin-bottom: 0.75rem; color: #1e293b; border-bottom: none; padding-bottom: 0; text-align: left;} /* Başlık stili güncellendi */
        .survey-card p { color: #475569; margin-bottom: 1rem; /* Boşluk azaltıldı */ line-height: 1.5; flex-grow: 1; font-size: 0.95rem; }
        .survey-card .card-actions { margin-top: auto; padding-top: 1rem; border-top: 1px solid #f1f5f9; text-align: right; } /* Buton alanı alta itildi ve sağa hizalandı */

        /* Genel Buton Stilleri - Ortak Stil */
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

        /* Navigasyon butonları için özel renkler */
        .btn-primary { background-color: #0ea5e9; } .btn-primary:hover { background-color: #0284c7; }
        .btn-secondary { background-color: #64748b; } .btn-secondary:hover { background-color: #475569; }
        .btn-danger { background-color: #ef4444; } .btn-danger:hover { background-color: #dc2626; }

        /* Kart içindeki butonlar ve Navigasyon Lisans butonu için renkler */
        /* Harekete geçirici yeşil buton stili (Uygula) */
        .btn-apply { background-color: #10b981; } /* Emerald 500 */
        .btn-apply:hover { background-color: #059669; } /* Emerald 600 */

        /* Lisans butonu stili (Ticari/Bilgilendirici Mavi) */
        .btn-license { background-color: #3b82f6; } /* Blue 500 */
        .btn-license:hover { background-color: #2563eb; } /* Blue 600 */


        /* İkon Buton (Giriş/Kayıt) */
        .icon-btn { padding: 0.6rem; border-radius: 0.375rem; color: white; transition: background-color 0.2s ease-in-out; display: inline-flex; align-items: center; justify-content: center; width: 2.75rem; height: 2.75rem; text-decoration: none; }
        .icon-btn svg { width: 1.5rem; height: 1.5rem; fill: currentColor; }
        .btn-primary.icon-btn { background-color: #0ea5e9; } .btn-primary.icon-btn:hover { background-color: #0284c7; }
        .btn-success.icon-btn { background-color: #22c55e; } .btn-success.icon-btn:hover { background-color: #16a34a; }

        /* Footer */
        footer { background-color: #e2e8f0; color: #475569; padding: 2rem 1rem; margin-top: 3rem; text-align: center; font-size: 0.875rem; }
        footer a { color: #334155; text-decoration: underline; margin: 0 0.5rem; transition: color 0.2s ease-in-out; }
        footer a:hover { color: #0ea5e9; }
        .footer-links span { margin: 0 0.25rem; }

        /* Yardımcı sınıflar */
        .container { width: 100%; max-width: 1100px; }
        .mx-auto { margin-left: auto; margin-right: auto; }
        .p-4 { padding: 1rem; }
        .mb-6 { margin-bottom: 1.5rem; }
        .text-2xl { font-size: 1.5rem; }
        .font-bold { font-weight: 700; }
        .mt-8 { margin-top: 2rem; }
        .mr-4 { margin-right: 1rem; }
        .text-gray-700 { color: #374151; }
        .min-h-screen { min-height: 100vh; }


    </style>
</head>
<body >
    <nav>
        <div class="logo-area">
            <a href="index.php"><img src="/assets/Psikometrik.png" alt="Psikometrik.Net Logo" ></a>
            </div>

        <div class="nav-actions"> <?php // Sağ taraf için sarmalayıcı ?>
            <a href="licensing.php" class="btn btn-license mr-4"> <?php // Sınıf btn-success yerine btn-license olarak değiştirildi ?>
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 inline-block mr-1" viewBox="0 0 20 20" fill="currentColor" style="height: 1em; width: auto; vertical-align: middle; margin-top: -2px;">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v2H7a1 1 0 100 2h2v2a1 1 0 102 0v-2h2a1 1 0 100-2h-2V7z" clip-rule="evenodd"/>
                </svg>
                Lisans
            </a>

            <?php if (isset($_SESSION['user_id'])): ?>
                <span class="mr-4 text-gray-700 font-medium">👤 <?php echo htmlspecialchars($_SESSION['username']); ?></span>
                <?php if (isset($_SESSION['role']) && ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'super-admin')): ?>
                    <a href="admin/dashboard.php" class="btn btn-secondary mr-2">Yönetim Paneli</a> <?php // Admin paneli butonu ?>
                <?php endif; ?>
                 <a href="logout.php" class="btn btn-danger">Çıkış</a> <?php // logout.php yolu doğru varsayıldı ?>
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

    <main class="container mx-auto p-4 mt-8">
        <h1 class="text-2xl font-bold mb-6 text-gray-800">Tüm Anketler ve Testler</h1>

         <?php if (isset($db_error_message)): ?>
             <div style="color: red; border: 1px solid red; padding: 10px; margin-bottom: 15px; background-color: #ffebeb;">
                 <?= htmlspecialchars($db_error_message) ?>
             </div>
         <?php endif; ?>

        <?php if (!empty($surveys)): ?>
            <div class="survey-grid">
                <?php foreach ($surveys as $survey): ?>
                    <div class="survey-card">
                        <h2><?php echo htmlspecialchars($survey['title']); ?></h2>
                        <p>
                            <?php echo nl2br(htmlspecialchars($survey['description'] ?? 'Açıklama bulunmuyor.')); ?>
                        </p>

                        <div class="card-actions" style="text-align: left; padding-top: 0.5rem; border-top: none;"> <?php // Butonları sola hizalamak ve üst boşluğu ayarlamak için stil eklendi ?>
                            <?php
                            // ---- BUTON KONTROLÜ ----
                            // 'money' sütunu varsa ve değeri 'free' ise Uygula butonunu göster
                            if (isset($survey['money']) && $survey['money'] === 'free'):
                                // Butonun linki take-survey-ID.php şeklinde olacak (admin_id olmadan)
                                $surveyLink = "take-survey-" . htmlspecialchars($survey['id']) . ".php";
                            ?>
                                <a href="<?= $surveyLink ?>" class="btn btn-apply">Uygula</a>
                            <?php
                            // 'money' sütunu varsa ve değeri 'free' değilse Lisans butonunu göster
                            elseif (isset($survey['money']) && $survey['money'] !== 'free'):
                            ?>
                                 <a href="https://psikometrik.net/licensing.php" class="btn btn-license">Lisans</a>
                            <?php endif; ?>
                            <?php // ---- BUTON KONTROLÜ SONU ---- ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php elseif (!isset($db_error_message)): // Hata mesajı yoksa ve anket de yoksa ?>
            <p class="text-gray-600">Henüz hiç anket veya test oluşturulmadı.</p>
        <?php endif; ?>
    </main>

    <footer class="w-full mt-12 py-6 bg-gray-200 text-gray-600 text-sm text-center">
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
