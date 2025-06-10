<?php

// Güçlendirilmiş Hata Raporlama Ayarları (Geliştirme ortamı için)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Oturumu başlat
session_start();

// Veritabanı yapılandırma dosyasını dahil et
require_once '/home/dahisinc/public_html/testanket/src/config.php';

// Anketleri veritabanından çek (Oluşturulma tarihine göre yeniden eskiye)
try {
    $stmt = $pdo->query("SELECT * FROM surveys ORDER BY created_at DESC");
    $surveys = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Veritabanı hatası durumunda boş bir dizi ata ve hatayı logla
    $surveys = [];
    error_log("Index PDO Exception: " . $e->getMessage());
    // İsterseniz kullanıcıya bir mesaj da gösterebilirsiniz
    // echo "<p>Anketler yüklenirken bir sorun oluştu.</p>";
}

?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anket Platformu</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Genel body stili */
        body {
            font-family: sans-serif;
            line-height: 1.6;
            background-color: #f8fafc; /* Çok açık mavi-gri arka plan */
            color: #334155; /* Koyu gri metin rengi */
            display: flex; /* Footer'ı en alta itmek için */
            flex-direction: column; /* Dikey flex */
            min-height: 100vh; /* En az ekran yüksekliği */
        }

        /* Ana içerik alanı */
        main {
             flex-grow: 1; /* İçeriğin mevcut alanı doldurmasını sağla */
        }


        /* Navigasyon çubuğu stilleri */
        nav {
            background-color: #ffffff; /* Beyaz arka plan */
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05); /* Daha yumuşak gölge */
            padding: 1rem 2rem; /* Yatayda biraz daha boşluk */
            display: flex; /* flex */
            justify-content: space-between; /* justify-between */
            align-items: flex-end; /* Öğeleri alta hizala */
        }

        /* Logo alanı stili */
        .logo-area {
            display: flex;
            align-items: center; /* Öğeleri dikeyde ortala */
            gap: 0.75rem; /* Logo ve yazı arasına boşluk */
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


        /* Anket kartları için grid düzeni */
        .survey-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); /* Responsive grid */
            gap: 1.5rem; /* Tailwind gap-6 */
        }

        /* Anket kartı stili */
        .survey-card {
            background-color: #ffffff; /* Beyaz arka plan */
            padding: 1.5rem; /* p-6 */
            border-radius: 0.5rem; /* rounded */
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08); /* Daha ince gölge */
            transition: box-shadow 0.3s ease-in-out; /* Gölge geçiş efekti */
            border: 1px solid #e2e8f0; /* Çok açık gri kenarlık */
            display: flex; /* İçeriği dikey olarak düzenlemek için */
            flex-direction: column;
        }

        .survey-card:hover {
            box-shadow: 0 5px 10px rgba(0, 0, 0, 0.1); /* Hover'da daha belirgin gölge */
        }

        /* Anket başlığı stili */
        .survey-card h2 {
            font-size: 1.25rem; /* text-xl */
            font-weight: 600; /* font-semibold */
            margin-bottom: 0.75rem; /* Biraz daha boşluk */
            color: #1e293b; /* Koyu gri */
        }

        /* Anket açıklaması stili */
        .survey-card p {
            color: #475569; /* Orta gri */
            margin-bottom: 1.5rem; /* Daha fazla boşluk */
            line-height: 1.5; /* Satır yüksekliği */
            flex-grow: 1; /* Açıklama alanının büyümesini sağla */
        }

        /* Kartın altındaki buton alanı */
         .survey-card .card-actions {
             margin-top: auto; /* Butonu kartın en altına iter */
         }


        /* Buton stilleri */
        .btn {
            padding: 0.6rem 1.25rem;
            border-radius: 0.375rem;
            color: white;
            font-weight: 500;
            transition: background-color 0.2s ease-in-out;
            display: inline-block;
            text-align: center;
            text-decoration: none;
        }

        .btn-primary { background-color: #0ea5e9; }
        .btn-primary:hover { background-color: #0284c7; }
        .btn-secondary { background-color: #64748b; }
        .btn-secondary:hover { background-color: #475569; }
        .btn-success { background-color: #22c55e; }
        .btn-success:hover { background-color: #16a34a; }
        .btn-danger { background-color: #ef4444; }
        .btn-danger:hover { background-color: #dc2626; }

        /* İkon buton stili */
        .icon-btn {
            padding: 0.6rem; border-radius: 0.375rem; color: white;
            transition: background-color 0.2s ease-in-out; display: inline-flex;
            align-items: center; justify-content: center; width: 2.75rem; height: 2.75rem;
            text-decoration: none;
        }
        .icon-btn svg { width: 1.5rem; height: 1.5rem; fill: currentColor; }
        .btn-primary.icon-btn { background-color: #0ea5e9; }
        .btn-primary.icon-btn:hover { background-color: #0284c7; }
        .btn-success.icon-btn { background-color: #22c55e; }
        .btn-success.icon-btn:hover { background-color: #16a34a; }

        /* Footer stilleri */
        footer {
            background-color: #e2e8f0; /* Açık Gri Arkaplan */
            color: #475569; /* Orta Gri Metin */
            padding: 2rem 1rem; /* İç boşluk */
            margin-top: 3rem; /* Main'den sonra boşluk */
            text-align: center;
            font-size: 0.875rem; /* Daha küçük yazı */
        }
        footer a {
            color: #334155; /* Koyu Gri Link */
            text-decoration: underline;
            margin: 0 0.5rem; /* Linkler arası boşluk */
            transition: color 0.2s ease-in-out;
        }
        footer a:hover {
            color: #0ea5e9; /* Hover'da Mavi */
        }
        .footer-links span { /* Ayraç için */
             margin: 0 0.25rem;
        }


        /* Diğer Tailwind sınıfları (Gerekirse kullanılır) */
        .mx-auto { margin-left: auto; margin-right: auto; }
        .container { width: 100%; max-width: 1100px; /* Konteyner genişliği biraz daha artırıldı */ }
        .p-4 { padding: 1rem; }
        .mb-6 { margin-bottom: 1.5rem; }
        .text-2xl { font-size: 1.5rem; }
        .font-bold { font-weight: 700; }
        .mt-4 { margin-top: 1rem; }
        .mt-8 { margin-top: 2rem; } /* mt-8 eklendi */
        .mr-2 { margin-right: 0.5rem; }
        .mr-4 { margin-right: 1rem; } /* mr-4 eklendi */
        .ml-2 { margin-left: 0.5rem; }
        .text-gray-600 { color: #4b5563; } /* Tailwind text-gray-600 */
        .text-gray-700 { color: #374151; } /* Tailwind text-gray-700 */
        .text-gray-800 { color: #1f2937; } /* Tailwind text-gray-800 */
        .min-h-screen { min-height: 100vh; } /* Tailwind min-h-screen */


    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <nav>
        <div class="logo-area">
            <a href="index.php"><img src="/assets/Psikometrik.png" alt="Psikometrik.Net Logo" ></a>
            </div>

        <div>
            <?php if (isset($_SESSION['user_id'])): ?>
                <span class="mr-4 text-gray-700">👤 <?php echo htmlspecialchars($_SESSION['username']); ?></span>
                <?php // Admin paneli linki sadece adminler için gösterilebilir (role kontrolü ile)
                 if (isset($_SESSION['role']) && ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'super-admin')): ?>
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

    <main class="container mx-auto p-4 mt-8">
        <h1 class="text-2xl font-bold mb-6 text-gray-800">Tüm Anketler ve Testler</h1>

        <?php if (count($surveys) > 0): ?>
            <div class="survey-grid">
                <?php foreach ($surveys as $survey): ?>
                    <div class="survey-card">
                        <h2 class="text-xl font-semibold mb-2"><?php echo htmlspecialchars($survey['title']); ?></h2>
                        <p class="text-gray-600 mb-4">
                            <?php echo nl2br(htmlspecialchars($survey['description'] ?? 'Açıklama bulunmuyor.')); ?>
                        </p>
                        <div class="card-actions">
                            <?php
                            // Her anket/test için doğru take-{test/survey}-{id}.php dosyasını hedefle
                            $linkTarget = "take-test-{$survey['id']}.php"; // Burdon vb. için 'test' kullanabiliriz
                            if (!file_exists(__DIR__ . '/' . $linkTarget)) {
                                // Eğer take-test-{id}.php yoksa, take-survey-{id}.php'yi dene
                                $linkTarget = "take-survey-{$survey['id']}.php";
                                if (!file_exists(__DIR__ . '/' . $linkTarget)) {
                                     // O da yoksa genel bir hedef belirle veya linki gösterme
                                     $linkTarget = "take-survey.php?id={$survey['id']}"; // Genel anket sayfası (varsa)
                                     // Veya linki tamamen gizle: $linkTarget = null;
                                }
                            }

                            // Kullanıcının giriş yapıp yapmadığına ve admin_id gerekip gerekmediğine göre link oluştur
                            $finalLink = '#'; // Varsayılan
                            if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
                                 // Giriş yapmış kullanıcılar (admin/süper admin) için admin_id ekle
                                if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'super-admin') {
                                     $finalLink = $linkTarget . "?admin_id=" . $_SESSION['user_id'];
                                } else {
                                     // Standart kullanıcılar için (admin_id olmadan, eğer varsa)
                                     // Eğer standart kullanıcıların teste katılmasına izin verilmiyorsa,
                                     // bu linki gösterme veya farklı bir mesaj göster.
                                     // Şimdilik admin_id olmadan link verelim:
                                     $finalLink = $linkTarget;
                                }
                            } else {
                                // Giriş yapmamış kullanıcılar (sadece admin_id ile çalışan testler için link gösterilmez)
                                // Belki login sayfasına yönlendirilebilir.
                                // Şimdilik linki göstermiyoruz ya da '#' bırakıyoruz.
                                $finalLink = 'login.php'; // Veya '#'
                            }

                            // Link oluşturulabildiyse göster
                            if ($linkTarget !== null && $finalLink !== '#'):
                            ?>
                                <a href="<?= htmlspecialchars($finalLink) ?>" class="btn btn-primary">Katıl</a>
                            <?php elseif ($finalLink === 'login.php'): ?>
                                 <a href="login.php" class="btn btn-secondary">Katılmak İçin Giriş Yap</a>
                             <?php else: ?>
                                 <span class="text-sm text-gray-500">Katılım linki bulunamadı.</span>
                             <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="text-gray-600">Henüz hiç anket veya test oluşturulmadı.</p>
        <?php endif; ?>
    </main>

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