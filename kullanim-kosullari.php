<?php
// Hata raporlamayı aç (Geliştirme için)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start(); // Oturumu başlat

// config.php dosyasını doğru yoldan dahil et
// Bu dosyanın, kullanildigi-kosullari.php dosyasına göre doğru konumda olduğundan emin olun.
// Genellikle ana dizindeki src klasöründe bulunur.
require_once __DIR__ . '/src/config.php'; // __DIR__ kullanıldı

?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kullanım Koşulları - Psikometrik.Net</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* index.php'den alınan genel stiller */
        body {
            font-family: sans-serif; line-height: 1.6; background-color: #f8fafc;
            color: #334155; display: flex; flex-direction: column; min-height: 100vh;
        }
        main { flex-grow: 1; }
        nav {
            background-color: #ffffff; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; /* align-items: center yapıldı */
        }
        .logo-area { display: flex; align-items: center; gap: 0.75rem; }
        /* Logo resmi için boyut ayarı */
        .logo-area img { height: 3rem; /* Yükseklik ayarlandı (6rem çok büyük olabilir) */ width: auto; }
        .logo-area a.site-name { font-size: 1.5rem; font-weight: bold; color: #0e7490; text-decoration: none; } /* Site adı için ayrı class */
        .nav-links a, .nav-links span { margin-left: 1rem; } /* Sağdaki linkler/span için */
        .btn { padding: 0.6rem 1.25rem; border-radius: 0.375rem; color: white; font-weight: 500; transition: background-color 0.2s ease-in-out; display: inline-block; text-align: center; text-decoration: none; }
        .btn-primary { background-color: #0ea5e9; } .btn-primary:hover { background-color: #0284c7; }
        .btn-success { background-color: #22c55e; } .btn-success:hover { background-color: #16a34a; }
        .btn-danger { background-color: #ef4444; } .btn-danger:hover { background-color: #dc2626; }
        .icon-btn { padding: 0.6rem; border-radius: 0.375rem; color: white; transition: background-color 0.2s ease-in-out; display: inline-flex; align-items: center; justify-content: center; width: 2.75rem; height: 2.75rem; text-decoration: none; }
        .icon-btn svg { width: 1.5rem; height: 1.5rem; fill: currentColor; }
        .btn-primary.icon-btn { background-color: #0ea5e9; } .btn-primary.icon-btn:hover { background-color: #0284c7; }
        .btn-success.icon-btn { background-color: #22c55e; } .btn-success.icon-btn:hover { background-color: #16a34a; }
        footer { background-color: #e2e8f0; color: #475569; padding: 2rem 1rem; margin-top: 3rem; text-align: center; font-size: 0.875rem; }
        footer a { color: #334155; text-decoration: underline; margin: 0 0.5rem; transition: color 0.2s ease-in-out; }
        footer a:hover { color: #0ea5e9; }
        .footer-links span { margin: 0 0.25rem; }
        .container { width: 100%; max-width: 900px; }
        .mx-auto { margin-left: auto; margin-right: auto; }
        .p-4 { padding: 1rem; } .p-6 { padding: 1.5rem; } .md\:p-8 { padding: 2rem; }
        .mb-6 { margin-bottom: 1.5rem; } .mt-8 { margin-top: 2rem; } .mt-12 { margin-top: 3rem; }
        .text-2xl { font-size: 1.5rem; } .font-bold { font-weight: 700; }
        .mr-2 { margin-right: 0.5rem; } .mr-4 { margin-right: 1rem; }
        .text-gray-700 { color: #374151; }
        .min-h-screen { min-height: 100vh; }
        .bg-white { background-color: #ffffff; }
        .rounded { border-radius: 0.25rem; } .rounded-lg { border-radius: 0.5rem; }
        .shadow-md { box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06); }
        /* İçerik Stilleri */
        .content-area h1 { font-size: 1.75rem; font-weight: bold; margin-bottom: 1rem; text-align: center; color: #1e293b; }
        .content-area h2 { font-size: 1.25rem; font-weight: 600; margin-top: 1.5rem; margin-bottom: 0.75rem; color: #1e3a8a; border-bottom: 1px solid #e2e8f0; padding-bottom: 0.25rem;}
        .content-area p { margin-bottom: 1rem; }
        .content-area ul { list-style-type: disc; margin-left: 1.5rem; margin-bottom: 1rem; }
        .content-area li { margin-bottom: 0.5rem; }
        .content-area strong { font-weight: 600; color: #111827; }
        .content-area a { color: #2563eb; text-decoration: underline; }
        .content-area a:hover { color: #1d4ed8; }
        .last-updated { text-align: center; font-size: 0.875rem; color: #64748b; margin-bottom: 2rem; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <nav>
        <div class="logo-area">
             <a href="index.php"><img src="https://psikometrik.net/assets/Psikometrik.png" alt="Psikometrik.Net Logo"></a>
            </div>
        <div class="nav-links">
            <?php if (isset($_SESSION['user_id'])): ?>
                <span class="text-gray-700">👤 <?php echo htmlspecialchars($_SESSION['username']); ?></span>
                <?php if (isset($_SESSION['role']) && ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'super-admin')): ?>
                     <a href="admin/dashboard.php" class="btn btn-primary">Admin Paneli</a>
                <?php endif; ?>
                <a href="logout.php" class="btn btn-danger">Çıkış</a>
            <?php else: ?>
                <a href="login.php" class="icon-btn btn-primary" title="Giriş Yap">
                     <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.38 0 2.5 1.12 2.5 2.5S13.38 10 12 10 9.5 8.88 9.5 7.5 10.62 5 12 5zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"/></svg>
                </a>
                <a href="register.php" class="icon-btn btn-success" title="Kayıt Ol">
                     <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm5 11h-4v4h-2v-4H7v-2h4V7h2v4h4v2z"/></svg>
                </a>
            <?php endif; ?>
        </div>
    </nav>

    <main class="container mx-auto p-4 mt-8">
        <div class="bg-white p-6 md:p-8 rounded-lg shadow-md content-area">

            <h1>PSİKOMETRİK.NET KULLANIM KOŞULLARI</h1>
            <p class="last-updated"><em>Son Güncelleme: 13/07/2023</em></p>

            <p>Bu Kullanım Koşulları ("Koşullar"), psikometrik.net web sitesi ("Platform") üzerinden sunulan hizmetlerin kullanımına ilişkin hak ve yükümlülüklerinizi düzenler. Platformu kullanmadan önce bu metni dikkatlice okuyunuz.</p>

            <h2>1. Hizmetin Kapsamı</h2>
            <p>Psikometrik.net, kullanıcıların psikometrik testlere erişim sağlayabileceği, bu testleri tamamlayabileceği ve kişisel raporlar alabileceği bir online platformdur. Hizmetler yalnızca <strong>kişisel gelişim ve bilgilendirme</strong> amaçlıdır; tıbbi teşhis, danışmanlık veya profesyonel tavsiye yerine geçmez.</p>

            <h2>2. Üyelik ve Hesap Yönetimi</h2>
            <ul>
                <li><strong>Kayıt:</strong> Hizmetlerden tam olarak yararlanmak için geçerli bir e-posta adresi ile üyelik oluşturmanız gerekir.</li>
                <li><strong>Doğruluk:</strong> Üyelik bilgilerinizin (ad-soyad, e-posta) güncel ve doğru olmasından siz sorumlusunuz.</li>
                <li><strong>Güvenlik:</strong> Hesabınızı yetkisiz kullanıma karşı korumak sizin yükümlülüğünüzdür. Şüpheli bir etkinlik tespit ederseniz derhal <a href="mailto:destek@psikometrik.net">destek@psikometrik.net</a> adresine bildiriniz.</li>
                <li><strong>Yaş Sınırı:</strong> Platformu kullanmak için <strong>18 yaşını doldurmuş</strong> olmalısınız. 18 yaş altı kullanıcılar, yasal veli/vasi onayı olmadan kayıt yapamaz.</li>
            </ul>

            <h2>3. Kullanıcı Yükümlülükleri</h2>
            <p>Platformu kullanırken aşağıdakilere uymayı kabul edersiniz:</p>
            <ul>
                <li>Sistemi kötüye kullanma, zarar verme veya teknik altyapıyı bozmaya yönelik girişimlerde bulunmamak,</li>
                <li>Başka kullanıcıların verilerine izinsiz erişmemek,</li>
                <li>Platform üzerinden yasa dışı, hakaret içeren veya spam niteliğinde içerik paylaşmamak,</li>
                <li>Test sonuçlarını başkalarına zarar vermek amacıyla kullanmamak.</li>
            </ul>

            <h2>4. Fikri Mülkiyet Hakları</h2>
            <ul>
                <li><strong>Platform İçeriği:</strong> Tüm testler, raporlar, grafikler ve metinler psikometrik.net’e aittir. İzinsiz kopyalanması, dağıtılması veya ticari amaçla kullanılması yasaktır.</li>
                <li><strong>Kullanıcı Verileri:</strong> Test sonuçlarınız ve raporlarınız üzerinde yalnızca sizin erişim ve kullanım hakkınız bulunur.</li>
            </ul>

            <h2>5. Hizmetin Sınırlamaları ve Sorumluluk Reddi</h2>
            <ul>
                <li>Psikometrik.net, test sonuçlarınızın doğruluğu veya eksiksizliği konusunda <strong>garanti vermez</strong>. Sonuçlar yalnızca genel bilgilendirme amaçlıdır.</li>
                <li>Platform, teknik arızalar, sunucu kesintileri veya üçüncü taraf hataları nedeniyle oluşan direkt/dolaylı zararlardan sorumlu değildir.</li>
                <li>Kullanıcıların test sonuçlarına dayanarak aldığı kararların sorumluluğu tamamen kendilerine aittir.</li>
            </ul>

            <h2>6. Değişiklikler ve Hizmet Sonlandırma</h2>
            <ul>
                <li><strong>Koşullarda Değişiklik:</strong> Psikometrik.net, dilediği zaman bu Koşulları güncelleyebilir. Değişiklikler, sitede yayınlandığı tarihte geçerli olur.</li>
                <li><strong>Hesap Sonlandırma:</strong> Platform, kuralları ihlal eden hesapları önceden uyarma gereği olmadan askıya alabilir veya silebilir.</li>
                <li><strong>Hizmet Durdurma:</strong> Psikometrik.net, teknik veya operasyonel nedenlerle hizmetleri geçici/sürekli olarak durdurabilir.</li>
            </ul>

            <h2>7. Uyuşmazlık Çözümü</h2>
            <ul>
                <li>Bu Koşullar, Türkiye Cumhuriyeti mevzuatına tabidir.</li>
                <li>Platform ile kullanıcılar arasında doğacak ihtilafların çözümünde <strong>İstanbul Mahkemeleri ve İcra Daireleri</strong> yetkilidir.</li>
            </ul>

            <h2>8. İletişim</h2>
            <p>Sorularınız veya talepleriniz için:</p>
            <ul>
                <li><strong>E-posta:</strong> <a href="mailto:destek@psikometrik.net">destek@psikometrik.net</a></li>
            </ul>

            <p class="mt-6">Platformu kullanarak, bu Kullanım Koşullarını okuduğunuzu, anladığınızı ve kabul ettiğinizi beyan edersiniz.</p>

             <div class="text-center mt-8">
                 <a href="index.php" class="btn btn-secondary inline-flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 20 20" fill="currentColor">
                      <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm.707-10.293a1 1 0 00-1.414-1.414l-3 3a1 1 0 000 1.414l3 3a1 1 0 001.414-1.414L9.414 11H13a1 1 0 100-2H9.414l1.293-1.293z" clip-rule="evenodd" />
                    </svg>
                    Ana Sayfa
                </a>
            </div>
             </div>
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
             <br class="sm:hidden">
             <span class="hidden sm:inline">|</span>
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