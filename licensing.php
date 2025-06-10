<?php
// Oturumu başlat (Navigasyon çubuğundaki kullanıcı bilgisi için gerekebilir)
session_start();

// Opsiyonel: Veritabanı yapılandırması (Eğer header/footer gibi bölümler DB bağlantısı gerektiriyorsa)
// require_once '/home/dahisinc/public_html/testanket/src/config.php';

// Opsiyonel: Hata raporlama (Geliştirme aşamasında faydalı)
error_reporting(E_ALL);
ini_set('display_errors', 1);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lisanslama Seçenekleri - Psikometrik.Net</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" href="/favicon.png" type="image/png">
    <style>
        /* index.php'deki stilleri buraya da kopyalayabilir veya */
        /* stilleri ayrı bir CSS dosyasına taşıyıp iki sayfada da çağırabilirsiniz. */
        /* Şimdilik temel Tailwind yeterli olacaktır varsayımıyla ilerliyoruz. */
        body {
            font-family: sans-serif;
            line-height: 1.6;
            background-color: #f8fafc;
            color: #334155;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        main {
            flex-grow: 1;
        }
        /* index.php'den alınan bazı temel stiller (Gerekirse genişletilebilir) */
        nav { background-color: #ffffff; box-shadow: 0 2px 4px rgba(0,0,0,0.05); padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; }
        .logo-area img { height: 5rem; vertical-align: middle;}
        footer { background-color: #e2e8f0; color: #475569; padding: 2rem 1rem; margin-top: 3rem; text-align: center; font-size: 0.875rem; }
        footer a { color: #334155; text-decoration: underline; margin: 0 0.5rem; transition: color 0.2s ease-in-out; }
        footer a:hover { color: #0ea5e9; }
        .footer-links span { margin: 0 0.25rem; }
        .container { width: 100%; max-width: 1200px; /* Konteyner biraz daha geniş */ }
        .btn { padding: 0.75rem 1.5rem; border-radius: 0.375rem; color: white; font-weight: 600; transition: background-color 0.2s ease-in-out; display: inline-block; text-align: center; text-decoration: none; }
        .btn-primary { background-color: #0ea5e9; }
        .btn-primary:hover { background-color: #0284c7; }
        .btn-success { background-color: #22c55e; }
        .btn-success:hover { background-color: #16a34a; }
        .btn-danger { background-color: #ef4444; }
        .btn-danger:hover { background-color: #dc2626; }
        .icon-btn { padding: 0.6rem; border-radius: 0.375rem; color: white; transition: background-color 0.2s ease-in-out; display: inline-flex; align-items: center; justify-content: center; width: 2.75rem; height: 2.75rem; text-decoration: none; }
        .icon-btn svg { width: 1.5rem; height: 1.5rem; fill: currentColor; }
        .btn-primary.icon-btn { background-color: #0ea5e9; }
        .btn-primary.icon-btn:hover { background-color: #0284c7; }
        .btn-success.icon-btn { background-color: #22c55e; }
        .btn-success.icon-btn:hover { background-color: #16a34a; }

        /* Lisans Kartı Stilleri */
        .license-card {
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 0.5rem;
            padding: 2rem; /* Daha fazla iç boşluk */
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            display: flex;
            flex-direction: column;
            height: 100%; /* Kartların aynı yükseklikte olmasını sağlar */
        }
        .license-card h3 {
            font-size: 1.5rem; /* text-2xl */
            font-weight: 700; /* font-bold */
            color: #1e293b;
            margin-bottom: 0.5rem;
        }
        .license-card .subtitle {
            font-size: 1rem; /* text-base */
            color: #475569;
            margin-bottom: 1.5rem;
        }
        .license-card ul {
            list-style: none; /* Liste işaretini kaldır */
            padding: 0;
            margin-bottom: 1.5rem;
            flex-grow: 1; /* Özellikler listesinin alanı doldurmasını sağlar */
        }
        .license-card ul li {
            margin-bottom: 0.75rem; /* Liste elemanları arasına boşluk */
            display: flex;
            align-items: center;
            color: #334155;
        }
         .license-card ul li svg { /* Check icon */
             width: 1.25rem;
             height: 1.25rem;
             margin-right: 0.5rem;
             color: #22c55e; /* Yeşil tik */
             flex-shrink: 0; /* İkonun küçülmesini engelle */
         }
        .license-card .price {
            font-size: 2.5rem; /* text-4xl */
            font-weight: 700; /* font-bold */
            color: #0ea5e9; /* Mavi fiyat */
            margin-bottom: 1.5rem;
            text-align: center;
        }
         .license-card .note {
             font-size: 0.875rem; /* text-sm */
             color: #ef4444; /* Kırmızı tonu */
             font-weight: 600;
             margin-bottom: 1rem;
             text-align: center;
         }
        .license-card .buy-button {
            margin-top: auto; /* Butonu kartın altına iter */
            display: block; /* Butonun tüm genişliği kaplaması için */
            width: 100%;
        }

    </style>
</head>
<body class="bg-gray-100">

    <?php // --- Navigasyon Barını Dahil Et (index.php'deki ile aynı) ---
          // İdeal olarak bu kısım ayrı bir dosyada (örn: partials/header.php) olmalı
          // ve require ile çağrılmalı. Şimdilik direkt kopyalıyoruz.
    ?>
    <nav>
        <div class="logo-area">
            <a href="index.php"><img src="/assets/Psikometrik.png" alt="Psikometrik.Net Logo" ></a>
        </div>
        <div class="flex items-center">
             <a href="licensing.php" class="btn btn-success hover:bg-green-600 font-semibold mr-4">
    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 inline-block mr-1" viewBox="0 0 20 20" fill="currentColor">
        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v2H7a1 1 0 100 2h2v2a1 1 0 102 0v-2h2a1 1 0 100-2h-2V7z" clip-rule="evenodd"/>
    </svg>
    Lisans
</a> <?php // Aktif sayfa olduğu için vurgulu ?>
            <?php if (isset($_SESSION['user_id'])): ?>
                <span class="mr-4 text-gray-700">👤 <?php echo htmlspecialchars($_SESSION['username']); ?></span>
                <?php if (isset($_SESSION['role']) && ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'super-admin')): ?>
                    <a href="admin/dashboard.php" class="btn btn-primary mr-2">Admin Paneli</a>
                <?php endif; ?>
                 <a href="logout.php" class="btn btn-danger">Çıkış</a>
            <?php else: ?>
                <a href="login.php" class="icon-btn btn-primary mr-2" title="Giriş Yap">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.38 0 2.5 1.12 2.5 2.5S13.38 10 12 10 9.5 8.88 9.5 7.5 10.62 5 12 5zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"/></svg>
                </a>
                <a href="register.php" class="icon-btn btn-success" title="Kayıt Ol">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm5 11h-4v4h-2v-4H7v-2h4V7h2v4h4v2z"/></svg>
                </a>
            <?php endif; ?>
        </div>
    </nav>

    <main class="container mx-auto p-4 md:p-8 mt-8">
        <h1 class="text-3xl md:text-4xl font-bold mb-8 text-center text-gray-800">Lisanslama Seçenekleri</h1>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 md:gap-8">

            <?php // --- Lisans Kartı 1: Psikometrik 3 --- ?>
            <div class="license-card">
                <h3>Psikometrik 3</h3>
                <p class="subtitle">Psikometrik.Net 3 Aylık Lisans</p>
                <ul>
                    <li><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>1 Kullanıcı</li>
                    <li><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>Tüm test ve anketler</li>
                    <li><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>Sınırsız uygulama</li>
                    <li><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>Raporlama ve genel sonuç</li>
                    <li><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>Sınırsız sonuç kaydı</li>
                </ul>
                <div class="price">1.650 TL</div>
                <a href="https://www.shopier.com/35568133" target="_blank" rel="noopener noreferrer" class="btn btn-primary buy-button">Satın Al</a>
            </div>

            <?php // --- Lisans Kartı 2: Psikometrik 6 --- ?>
            <div class="license-card">
                <h3>Psikometrik 6</h3>
                <p class="subtitle">Psikometrik.Net 6 Aylık Lisans</p>
                 <ul>
                    <li><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>1 Kullanıcı</li>
                    <li><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>Tüm test ve anketler</li>
                    <li><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>Sınırsız uygulama</li>
                    <li><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>Raporlama ve genel sonuç</li>
                    <li><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>Sınırsız sonuç kaydı</li>
                    <li><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>Öncelikli destek</li>
                </ul>
                <div class="price">3.200 TL</div>
                 <a href="https://www.shopier.com/35568162" target="_blank" rel="noopener noreferrer" class="btn btn-primary buy-button">Satın Al</a>
            </div>

            <?php // --- Lisans Kartı 3: Psikometrik 12 --- ?>
            <div class="license-card">
                <h3>Psikometrik 12</h3>
                <p class="subtitle">Psikometrik.Net 12 Aylık Lisans</p>
                 <ul>
                    <li><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>2 Kullanıcı</li>
                    <li><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>Tüm test ve anketler</li>
                    <li><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>Sınırsız uygulama</li>
                    <li><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>Raporlama ve genel sonuç</li>
                    <li><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>Sınırsız sonuç kaydı</li>
                    <li><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>Yüksek Öncelikli destek</li>
                </ul>
                <div class="price">5.500 TL</div>
                 <a href="https://www.shopier.com/35568233" target="_blank" rel="noopener noreferrer" class="btn btn-primary buy-button">Satın Al</a>
            </div>

            <?php // --- Lisans Kartı 4: Psikometrik 12 (Limitli) --- ?>
            <div class="license-card border-2 border-red-500"> <?php // Özel vurgu için kırmızı çerçeve ?>
                <h3>Psikometrik 12 (Limitli)</h3>
                <p class="subtitle">Psikometrik.Net 12 Aylık Lisans</p>
                <p class="note">🔥 Sadece 50 Lisans!</p> <?php // Özel not ?>
                 <ul>
                     <li><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>2 Kullanıcı</li>
                    <li><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>Tüm test ve anketler</li>
                    <li><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>Sınırsız uygulama</li>
                    <li><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>Raporlama ve genel sonuç</li>
                    <li><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>Sınırsız sonuç kaydı</li>
                    <li><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>Yüksek Öncelikli destek</li>
                </ul>
                <div class="price">4.999 TL</div>
                 <a href="https://www.shopier.com/35568189" target="_blank" rel="noopener noreferrer" class="btn btn-primary buy-button">Satın Al</a>
            </div>

        </div> <?php // --- Grid Sonu --- ?>

    </main>

    <?php // --- Footer Alanı (index.php'deki ile aynı) ---
          // İdeal olarak bu kısım ayrı bir dosyada (örn: partials/footer.php) olmalı
          // ve require ile çağrılmalı. Şimdilik direkt kopyalıyoruz.
    ?>
    <footer class="w-full mt-12 py-6 bg-gray-200 text-gray-600 text-sm text-center">
        <div class="container mx-auto footer-links">
             <a href="kullanim-kosullari.php" class="hover:text-blue-600">Kullanım Koşulları</a>
             <span>|</span>
             <a href="kvkk.php" class="hover:text-blue-600">KVKK</a>
             <span>|</span>
             <a href="cerez-politikasi.php" class="hover:text-blue-600">Çerez Politikası</a>
             <span>|</span>
             <a href="uyelik-sozlesmesi.php" class="hover:text-blue-600">Üyelik Sözleşmesi</a>
             <br class="sm:hidden"> <span class="hidden sm:inline">|</span>
             <a href="acik-riza-metni.php" class="hover:text-blue-600">Açık Rıza Metni</a>
             <span>|</span>
             <a href="veri-ihlali.php" class="hover:text-blue-600">Veri İhlali Bildirim Prosedürü</a>
             <span>|</span>
             <a href="yasal-uyari.php" class="hover:text-blue-600">Yasal Uyarı</a>
        </div>
        <p class="mt-4">&copy; <?= date('Y') ?> Psikometrik.Net - Tüm hakları saklıdır.</p>
    </footer>

</body>
</html>