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


// Başlangıçta tüm aktif anketleri çek
$initial_surveys = [];
$db_error_message = null;

// Kategorileri tanımla (Bu listeyi ihtiyaçlarınıza göre düzenleyebilirsiniz)
$categories = [
    'psikolojik' => 'Psikolojik Değerlendirme',
    'aile' => 'Aile & Ebeveynlik',
    'egitim' => 'Eğitim & Okul',
    'mesleki' => 'Mesleki Rehberlik',
    'bagimlilik' => 'Bağımlılık',
    'sosyal' => 'Sosyal Konular',
    'dikkat_zeka' => 'Dikkat & Zeka'
];


try {
    // Aktif anketleri çek (money ve type dahil - type hala kartta gösterim için kullanılabilir)
    $stmt_surveys = $pdo->query("SELECT id, title, description, created_at, money, type FROM surveys WHERE status = 'active' ORDER BY created_at DESC");
    $initial_surveys = $stmt_surveys->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // Veritabanı hatası durumunda
    $initial_surveys = [];
    error_log("Index PDO Exception: " . $e->getMessage());
    $db_error_message = "Anketler veya filtreler yüklenirken bir sorun oluştu.";
}

?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="keywords" content="psikometrik testler, online anket, anket platformu, psikolojik değerlendirme, eğitim anketleri, RİBA, kariyer envanteri, mesleki rehberlik, kişilik testi, depresyon ölçeği, anksiyete ölçeği, öğrenme stilleri, veli anketi, öğrenci anketi, Psikometrik.Net, bilimsel ölçüm, net sonuçlar, Benlik Tasarımı Envanteri, Aile Envanteri, Algılanan Aile Desteği Ölçeği (PSS-Fa), Ana Baba Tutumu Envanteri, Beck Anksiyete Ölçeği, Beş Faktör Kişilik Envanteri, Burdon Dikkat Testi, Çalışma Davranışı Değerlendirme Ölçeği, Çoklu Zeka Ölçeği, Beck Depresyon Ölçeği (Beck-D), Holland Mesleki Tercih Envanteri, İnternet Bağımlılığı Ölçeği, Ergenler için Oyun Bağımlılığı Ölçeği, Mesleki Eğilim Belirleme Testi, Mesleki Olgunluk Ölçeği, UCLA Yalnızlık Ölçeği, Öğrenme Stilleri Belirleme Testi, Rathus Atılganlık Envanteri, SCL-90 Psikolojik Belirti Tarama Testi, Sınav Kaygısı Ölçeği, Akademik Benlik Kavramı Ölçeği, Şiddet Algısı Anketi (ÖĞRENCİ), Şiddet Algısı Anketi (VELİ), Ortaokul Öğrenci Rehberlik İhtiyacı Belirleme Anketi (RİBA) (Veli), Ortaokul Öğrenci Rehberlik İhtiyacı Belirleme Anketi (RİBA) (ÖĞRETMEN), Öğrenci Rehberlik İhtiyacı Belirleme Anketi (RİBA) (Ortaokul-Öğrenci Formu), Öğrenci Rehberlik İhtiyacı Belirleme Anketi (RİBA) (Okul Öncesi-Öğretmen Formu), Şiddet Sıklığı Anketi (Veli Formu), Riba 2 - İlkokul Veli, Riba 2 - İlkokul Öğrenci, Riba 2 - İlkokul Öğretmen, Riba 2 - Lise Veli, Riba 2 - Lise Öğrenci, Riba 2 - Lise Öğretmen, Riba 2 - Okul Öncesi Veli, Riba 2 - Okul Öncesi Öğretmen, Riba 2 - Ortaokul Veli, Riba 2 - Ortaokul Öğrenci, Riba 2 - Ortaokul Öğretmen">
    <meta name="description" content="Psikometrik.Net: Ölçüm bilimsel, sonuçlar net! Güvenilir online psikolojik testler, eğitim anketleri (RİBA) ve kariyer envanterleri platformu. Keşfedin.">
    <title>Anket Platformu - Anketler ve Testler</title>
    <link rel="icon" href="/favicon.png" type="image/png">

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" integrity="sha512-9usAa10IRO0HhonpyAIVpjrylPvoDwiPUiKdWk5t3PyolY1cOd4DSE0Ga+ri4AuTroPR5aQvXU9xC6qOPnzFeg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        /* Genel body stili */
        body {
            font-family: 'Inter', sans-serif; /* Daha modern bir font */
            line-height: 1.6; background-color: #f8fafc; /* Açık gri arka plan */
            color: #334155; display: flex; flex-direction: column; min-height: 100vh;
        }
        main { flex-grow: 1; }

        /* Navigasyon çubuğu stilleri */
        nav {
            background-color: #ffffff; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center;
            border-bottom: 1px solid #e5e7eb;
        }
        .logo-area { display: flex; align-items: center; gap: 0.75rem; }
        .logo-area img { height: 5rem; /* Biraz daha zarif */ vertical-align: middle; } /* Logo */

        /* Sağ Navigasyon */
        .nav-actions { display: flex; align-items: center; gap: 0.75rem; /* Butonlar arası boşluk */}

        /* Kayan Yazı Stili */
        .marquee-container {
            background-color:#FFA500; /* Yeşil bir arka plan */
            color: white;
            padding: 0.75rem 0; /* Dikey padding */
            text-align: center;
            font-weight: 500;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow: hidden; /* Marquee'nin taşmasını engelle */
            white-space: nowrap; /* Metnin tek satırda kalmasını sağla */
        }
        .marquee-container p { /* Marquee içindeki p etiketi için */
            display: inline-block; /* Animasyon için gerekli */
            padding-left: 100%; /* Animasyon başlangıç pozisyonu */
            animation: marquee-animation 50s linear infinite; /* Animasyon tanımı */
        }

        @keyframes marquee-animation {
            0%   { transform: translateX(0); }
            100% { transform: translateX(-200%); } /* Metnin tamamen kaymasını sağlar, %100 metnin kendisi + %100 boşluk */
        }


        /* Filtreleme Alanı */
        .filter-section {
            background-color: #ffffff;
            padding: 1.5rem;
            border-radius: 0.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
            border: 1px solid #e2e8f0;
        }
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            align-items: end; /* Elemanları alta hizala */
        }
        .filter-group label {
            display: block;
            font-weight: 500;
            margin-bottom: 0.5rem;
            color: #475569;
            font-size: 0.875rem;
        }
        .filter-group input[type="text"],
        .filter-group select {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border: 1px solid #cbd5e1;
            border-radius: 0.375rem;
            background-color: #ffffff;
            transition: border-color 0.2s ease;
            font-size: 0.9rem;
        }
        .filter-group input[type="text"]:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #3b82f6; /* Mavi focus rengi */
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.3);
        }
        .filter-radios label {
            display: inline-flex; /* Yan yana gelmesi için */
            align-items: center;
            margin-right: 1rem;
            font-size: 0.9rem;
            cursor: pointer;
        }
        .filter-radios input[type="radio"] {
            margin-right: 0.4rem;
            accent-color: #10b981; /* Yeşil radio butonu */
        }


        /* Anket kartları grid */
        .survey-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.5rem; }

        /* Anket kartı */
        .survey-card {
            background-color: #ffffff; padding: 1.5rem; border-radius: 0.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08); transition: box-shadow 0.3s ease-in-out, transform 0.2s ease;
            border: 1px solid #e2e8f0; display: flex; flex-direction: column;
        }
        .survey-card:hover {
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            transform: translateY(-3px); /* Hafif yukarı kalkma efekti */
         }
        .survey-card h2 { font-size: 1.2rem; font-weight: 600; margin-bottom: 0.75rem; color: #1e293b; border-bottom: none; padding-bottom: 0; text-align: left;}
        .survey-card p { color: #475569; margin-bottom: 1rem; line-height: 1.5; flex-grow: 1; font-size: 0.9rem; } /* Font boyutu biraz küçültüldü */
        .survey-card .card-footer {
             margin-top: auto;
             padding-top: 1rem;
             border-top: 1px solid #f1f5f9;
             display: flex;
             justify-content: space-between; /* Buton ve türü ayırmak için */
             align-items: center;
        }
        .survey-card .survey-type-badge { /* Bu hala teknik türü gösterebilir veya kaldırılabilir */
            font-size: 0.75rem;
            font-weight: 500;
            color: #64748b; /* Gri renk */
            background-color: #f1f5f9; /* Açık gri arka plan */
            padding: 0.2rem 0.6rem;
            border-radius: 0.25rem;
            text-transform: capitalize; /* Baş harfi büyük */
        }

        /* Genel Buton Stilleri - Ortak Stil */
        .btn {
            padding: 0.6rem 1.25rem; border-radius: 0.375rem; color: white;
            font-weight: 500; transition: background-color 0.2s ease-in-out, box-shadow 0.2s ease;
            display: inline-flex; align-items: center; gap: 0.4rem; /* İkon ve metin arası boşluk */
            text-align: center; text-decoration: none; border: none; cursor: pointer;
            font-size: 0.9rem; /* Buton font boyutu */
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        .btn:hover { box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .btn svg, .btn i { width: 1.1em; height: 1.1em; } /* Buton ikon boyutu */

        /* Navigasyon butonları için özel renkler */
        .btn-primary { background-color: #0ea5e9; } .btn-primary:hover { background-color: #0284c7; }
        .btn-secondary { background-color: #64748b; } .btn-secondary:hover { background-color: #475569; }
        .btn-danger { background-color: #ef4444; } .btn-danger:hover { background-color: #dc2626; }
        .btn-success { background-color: #22c55e; } .btn-success:hover { background-color: #16a34a; }


        /* Kart içindeki butonlar ve Navigasyon Lisans butonu için renkler */
        .btn-apply { background-color: #10b981; } .btn-apply:hover { background-color: #059669; }
        .btn-license { background-color: #3b82f6; } .btn-license:hover { background-color: #2563eb; }


        /* İkon Buton (Giriş/Kayıt) */
        .icon-btn { padding: 0.6rem; border-radius: 0.375rem; color: white; transition: background-color 0.2s ease-in-out; display: inline-flex; align-items: center; justify-content: center; width: 2.75rem; height: 2.75rem; text-decoration: none; }
        .icon-btn svg, .icon-btn i { width: 1.5rem; height: 1.5rem; fill: currentColor; }
        .btn-primary.icon-btn { background-color: #0ea5e9; } .btn-primary.icon-btn:hover { background-color: #0284c7; }
        .btn-success.icon-btn { background-color: #22c55e; } .btn-success.icon-btn:hover { background-color: #16a34a; }

        /* Footer */
        footer { background-color: #e2e8f0; color: #475569; padding: 2rem 1rem; margin-top: 3rem; text-align: center; font-size: 0.875rem; }
        footer a { color: #334155; text-decoration: underline; margin: 0 0.5rem; transition: color 0.2s ease-in-out; }
        footer a:hover { color: #0ea5e9; }
        .footer-links span { margin: 0 0.25rem; }

        /* Yükleniyor Göstergesi */
        #loading-indicator {
            display: none; /* Başlangıçta gizli */
            text-align: center;
            padding: 2rem;
            font-size: 1.1rem;
            color: #64748b;
        }
         #loading-indicator i { margin-right: 0.5rem; }

        /* Sonuç Yok Mesajı */
        #no-results-message {
            display: none; /* Başlangıçta gizli */
            text-align: center;
            padding: 2rem;
            font-size: 1.1rem;
            color: #64748b;
            background-color: #f1f5f9;
            border: 1px dashed #cbd5e1;
            border-radius: 0.5rem;
        }

        /* Yardımcı sınıflar */
        .container { width: 100%; max-width: 1100px; padding-left: 1rem; padding-right: 1rem;} /* Padding eklendi */
        .mx-auto { margin-left: auto; margin-right: auto; }
        .p-4 { padding: 1rem; }
        .mb-6 { margin-bottom: 1.5rem; }
        .text-2xl { font-size: 1.5rem; }
        .font-bold { font-weight: 700; }
        .mt-8 { margin-top: 2rem; }
        .mr-4 { margin-right: 1rem; }
        .mr-2 { margin-right: 0.5rem; }
        .mr-1 { margin-right: 0.25rem; } /* İkonlar için */
        .text-gray-700 { color: #374151; }
        .text-gray-800 { color: #1f2937; }
        .min-h-screen { min-height: 100vh; }

        /* Font Awesome için */
        @import url('https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css');

    </style>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script async src="https://www.googletagmanager.com/gtag/js?id=AW-1023693484">
</script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  gtag('config', 'AW-1023693484');
</script>
</head>
<body >
    <nav>
        <div class="logo-area">
            <a href="index.php"><img src="/assets/Psikometrik.png" alt="Psikometrik.Net Logo" ></a>
            </div>

        <div class="nav-actions"> <?php // Sağ taraf için sarmalayıcı ?>
            <a href="licensing.php" class="btn btn-license mr-4">
                <i class="fas fa-award"></i> Lisans
            </a>

            <?php if (isset($_SESSION['user_id'])): ?>
                <span class="mr-4 text-gray-700 font-medium hidden sm:inline"> <?php // Küçük ekranlarda gizle ?>
                    <i class="fas fa-user mr-1"></i> <?php echo htmlspecialchars($_SESSION['username']); ?>
                </span>
                <?php if (isset($_SESSION['role']) && ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'super-admin')): ?>
                    <a href="admin/dashboard.php" class="btn btn-secondary mr-2">
                       <i class="fas fa-tachometer-alt"></i> <span class="hidden sm:inline">Yönetim Paneli</span> <?php // Küçük ekranlarda metni gizle ?>
                    </a>
                <?php endif; ?>
                 <a href="logout.php" class="btn btn-danger" title="Çıkış">
                     <i class="fas fa-sign-out-alt"></i> <span class="hidden sm:inline">Çıkış</span> <?php // Küçük ekranlarda metni gizle ?>
                 </a>
            <?php else: ?>
                 <a href="login.php" class="icon-btn btn-primary mr-2" title="Giriş Yap">
                     <i class="fas fa-sign-in-alt"></i>
                 </a>
                 <a href="register.php" class="icon-btn btn-success" title="Kayıt Ol">
                     <i class="fas fa-user-plus"></i>
                 </a>
            <?php endif; ?>
        </div>
    </nav>

    <div class="marquee-container">
        <p>Sadece 10 adetle sınırlı ilk10 koduyla tüm lisanslarda %50 indirim.</p>
    </div>
    <main class="container mx-auto p-4 mt-8">

        <section class="filter-section">
            <form id="filter-form" class="filter-grid">
                <div class="filter-group">
                    <label for="search-term"><i class="fas fa-search mr-1"></i> Ara</label>
                    <input type="text" id="search-term" name="search" placeholder="Anket adı veya açıklaması...">
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-dollar-sign mr-1"></i> Ücret Durumu</label>
                    <div class="filter-radios">
                        <label>
                            <input type="radio" name="money" value="" checked> Tümü
                        </label>
                        <label>
                            <input type="radio" name="money" value="free"> Ücretsiz
                        </label>
                        <label>
                            <input type="radio" name="money" value="paid"> Ücretli
                        </label>
                    </div>
                </div>
                <div class="filter-group">
                    <label for="survey-category"><i class="fas fa-tags mr-1"></i> Kategori</label>
                    <select id="survey-category" name="category"> <?php // name="category" olarak değiştirildi ?>
                        <option value="">Tüm Kategoriler</option>
                        <?php foreach ($categories as $key => $value): ?>
                            <option value="<?php echo htmlspecialchars($key); ?>">
                                <?php echo htmlspecialchars($value); // Kategoriyi göster ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                 </form>
        </section>

        <h1 class="text-2xl font-bold mb-6 text-gray-800">Tüm Anketler ve Testler</h1>

         <?php if (isset($db_error_message)): ?>
             <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-6" role="alert">
                 <strong class="font-bold">Hata:</strong>
                 <span class="block sm:inline"><?= htmlspecialchars($db_error_message) ?></span>
             </div>
         <?php endif; ?>

        <div id="loading-indicator">
            <i class="fas fa-spinner fa-spin"></i> Anketler yükleniyor...
        </div>

        <div id="no-results-message">
             <i class="fas fa-info-circle mr-2"></i> Filtre kriterlerinize uygun anket bulunamadı.
         </div>

        <div id="survey-list" class="survey-grid">
            </div>

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

<script>
    // DOM elementlerini seç
    const filterForm = document.getElementById('filter-form');
    const surveyListContainer = document.getElementById('survey-list');
    const loadingIndicator = document.getElementById('loading-indicator');
    const noResultsMessage = document.getElementById('no-results-message');

    // Başlangıçta tüm anketleri yükle
    let initialSurveys = <?php echo json_encode($initial_surveys); ?>;
    displaySurveys(initialSurveys);

    // Filtre formu elemanlarına olay dinleyicileri ekle (anlık filtreleme için)
    filterForm.addEventListener('input', handleFilterChange); // Arama için input daha iyi
    filterForm.addEventListener('change', handleFilterChange); // Radio ve select için change

    let debounceTimer; // Arama için debounce zamanlayıcısı

    function handleFilterChange(event) { // event parametresi eklendi
        // Arama kutusu için debounce uygula
        if (event && event.target && event.target.id === 'search-term') {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                fetchFilteredSurveys();
            }, 300); // 300ms bekleme süresi
        } else {
            // Diğer filtreler için anında güncelle
            fetchFilteredSurveys();
        }
    }

    async function fetchFilteredSurveys() {
        loadingIndicator.style.display = 'block'; // Yükleniyor göstergesini göster
        surveyListContainer.innerHTML = ''; // Mevcut listeyi temizle
        noResultsMessage.style.display = 'none'; // Sonuç yok mesajını gizle

        // Form verilerini al
        const formData = new FormData(filterForm);
        const params = new URLSearchParams(formData).toString();

        try {
            // filter_surveys.php'ye isteği gönder (category parametresi ile)
            const response = await fetch(`filter_surveys.php?${params}`);
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            const filteredSurveys = await response.json();

            // Hata kontrolü (filter_surveys.php'den gelen)
            if (filteredSurveys.error) {
                 console.error('Filtreleme API Hatası:', filteredSurveys.error);
                 surveyListContainer.innerHTML = `<p class="text-red-600 text-center col-span-full">Anketler yüklenirken bir sunucu hatası oluştu: ${escapeHtml(filteredSurveys.error)}</p>`;
            } else {
                displaySurveys(filteredSurveys);
            }


        } catch (error) {
            console.error('Filtreleme hatası:', error);
            surveyListContainer.innerHTML = '<p class="text-red-600 text-center col-span-full">Anketler yüklenirken bir hata oluştu. Lütfen tekrar deneyin.</p>'; // Hata mesajı göster
        } finally {
            loadingIndicator.style.display = 'none'; // Yükleniyor göstergesini gizle
        }
    }

    function displaySurveys(surveys) {
        surveyListContainer.innerHTML = ''; // Önce temizle

        if (!Array.isArray(surveys)) {
             console.error("Beklenen anket verisi bir dizi değil:", surveys);
             noResultsMessage.style.display = 'block';
             noResultsMessage.innerHTML = '<i class="fas fa-exclamation-triangle mr-2"></i> Geçersiz veri alındı.';
             return;
        }


        if (surveys.length === 0) {
            noResultsMessage.style.display = 'block'; // Sonuç yoksa mesajı göster
            noResultsMessage.innerHTML = '<i class="fas fa-info-circle mr-2"></i> Filtre kriterlerinize uygun anket bulunamadı.'; // Mesajı sıfırla
            return;
        } else {
            noResultsMessage.style.display = 'none'; // Sonuç varsa mesajı gizle
        }

        surveys.forEach(survey => {
            const card = document.createElement('div');
            card.className = 'survey-card';

            // Açıklama (varsa kısalt)
            let description = survey.description || 'Açıklama bulunmuyor.';
            if (description.length > 150) { // Örneğin 150 karakterden uzunsa
                description = description.substring(0, 150) + '...';
            }

            // Anket türü (teknik - isteğe bağlı gösterim)
            let surveyTypeDisplay = survey.type ? survey.type.replace(/_/g, ' ') : '';
             if (surveyTypeDisplay) {
                 surveyTypeDisplay = surveyTypeDisplay.charAt(0).toUpperCase() + surveyTypeDisplay.slice(1); // İlk harf büyük
             }
             const typeBadgeHtml = surveyTypeDisplay ? `<span class="survey-type-badge">${escapeHtml(surveyTypeDisplay)}</span>` : '';


            // Buton HTML'i
            let buttonHtml = '';
            if (survey.money === 'free') {
                const surveyLink = `take-survey-${survey.id}.php`;
                buttonHtml = `<a href="${surveyLink}" class="btn btn-apply"><i class="fas fa-play"></i> Uygula</a>`;
            } else if (survey.money === 'paid') {
                buttonHtml = `<a href="https://psikometrik.net/licensing.php" class="btn btn-license"><i class="fas fa-award"></i> Lisans</a>`;
            }

            card.innerHTML = `
                <h2>${escapeHtml(survey.title)}</h2>
                <p>${escapeHtml(description)}</p>
                <div class="card-footer">
                    <div>${buttonHtml}</div>
                    ${typeBadgeHtml} <?php // Teknik tür rozeti (isteğe bağlı) ?>
                </div>
            `;
            surveyListContainer.appendChild(card);
        });
    }

    // Güvenlik için HTML'den kaçış fonksiyonu
    function escapeHtml(unsafe) {
        if (unsafe === null || unsafe === undefined) return '';
        return unsafe
             .toString()
             .replace(/&/g, "&amp;")
             .replace(/</g, "&lt;")
             .replace(/>/g, "&gt;")
             .replace(/"/g, "&quot;")
             .replace(/'/g, "&#039;");
    }

    // Sayfa ilk yüklendiğinde filtrelemeyi tetikle (isteğe bağlı, zaten PHP ile yüklüyoruz)
    // fetchFilteredSurveys();

</script>

</body>
</html>
