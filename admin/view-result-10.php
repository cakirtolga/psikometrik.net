<?php
session_start(); // Oturumu başlat
require_once '/home/dahisinc/public_html/testanket/src/config.php'; // Veritabanı bağlantısı ve yapılandırma

// --- Oturum Kontrolü ---
// Kullanıcının giriş yapıp yapmadığını kontrol et
if (!isset($_SESSION['user_id'])) {
    // Giriş yapılmamışsa, giriş sayfasına yönlendir veya hata mesajı göster
    // header('Location: login.php'); // Örnek yönlendirme
    die('Bu sayfayı görüntülemek için giriş yapmalısınız.'); // Veya erişimi reddet
}
$loggedInUserId = $_SESSION['user_id']; // Giriş yapmış kullanıcının ID'si
// --- Bitiş: Oturum Kontrolü ---

// --- Test ve Katılımcı Bilgisi Al ---
$testId = 10; // Görüntülenecek testin ID'si
$testTitle = "Burdon Dikkat Testi"; // Testin adı

// GET parametresinden katılımcı ID'sini al
$participantId = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($participantId === 0) {
    die('Geçersiz katılımcı ID\'si belirtilmemiş.');
}

// Veritabanından katılımcı sonucunu çek
$participant = null;
$error = null;
try {
    // survey_participants tablosundan ilgili kaydı çek
    // survey_id = $testId (10) kontrolü ekleyerek sadece bu testin sonucunu aldığımızdan emin olalım
    $stmt = $pdo->prepare("SELECT * FROM survey_participants WHERE id = ? AND survey_id = ?");
    $stmt->execute([$participantId, $testId]);
    $participant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$participant) {
        $error = 'Belirtilen ID\'ye sahip test sonucu bulunamadı veya bu test (ID: '.$testId.') için değil.';
    } else {
        // --- Yetkilendirme Kontrolü ---
        // Sonucu görüntüleyen kişinin, sonucu kaydeden admin ile aynı olup olmadığını kontrol et
        // VEYA süper admin yetkisi varsa (bu kısım sizin yetkilendirme yapınıza göre değişir)
        // Örnek: $isSuperAdmin = checkSuperAdminStatus($_SESSION['user_id']); // Varsayımsal fonksiyon
        $isSuperAdmin = false; // Şimdilik süper admin olmadığını varsayalım

        if ($participant['admin_id'] != $loggedInUserId && !$isSuperAdmin) {
            $error = 'Bu sonucu görüntüleme yetkiniz yok.';
            $participant = null; // Yetkisiz erişimde veriyi gösterme
        }
        // --- Bitiş: Yetkilendirme Kontrolü ---
    }

} catch (PDOException $e) {
    error_log("Sonuç görüntüleme hatası (Burdon): " . $e->getMessage());
    $error = "Sonuç alınırken bir veritabanı hatası oluştu.";
}
// --- Bitiş: Test ve Katılımcı Bilgisi Al ---


// --- Yorumlama Mantığı ---
// Eğer katılımcı verisi başarıyla alındıysa ve hata yoksa yorumlamayı yap
$interpretation = "Hesaplanamadı";
$scorePercentage = null;
if ($participant && !$error) {
    $scorePercentage = $participant['score']; // Veritabanından skoru al

    if (!is_null($scorePercentage)) { // Skor null değilse yorumla
        if ($scorePercentage >= 85) {
            $interpretation = "Yeterli";
        } elseif ($scorePercentage >= 70) {
            $interpretation = "Desteklenebilir, geliştirilebilir.";
        } elseif ($scorePercentage >= 50) {
            $interpretation = "Desteklenmesi gerekir.";
        } else {
            $interpretation = "Uzman desteği almalı, uzman yönlendirilmeli.";
        }
    } else {
        $interpretation = "Skor bilgisi bulunamadı.";
    }
}
// --- Bitiş: Yorumlama Mantığı ---

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($testTitle) ?> Sonucu</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Stil Bloğu (Temiz ve Okunabilir) */
        body { font-family: sans-serif; line-height: 1.6; background-color: #f0fdf4; color: #333; margin: 0; padding: 20px; }
        .container { max-width: 800px; margin: 30px auto; padding: 25px 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); background-color: #ffffff; }
        h1 { color: #166534; text-align: center; margin-bottom: 10px; font-size: 1.75rem; border-bottom: 2px solid #dcfce7; padding-bottom: 10px;}
        h2 { color: #15803d; text-align: center; margin-bottom: 25px; font-size: 1.25rem; font-weight: 600;}
        .info-section, .result-section { margin-bottom: 25px; padding: 15px; background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; }
        .info-section p, .result-section p { margin-bottom: 8px; color: #374151; }
        .info-section strong, .result-section strong { color: #111827; font-weight: 600; margin-right: 5px;}
        .score { font-size: 1.8rem; font-weight: bold; color: #2563eb; display: block; text-align: center; margin: 10px 0; }
        .interpretation { font-style: italic; font-size: 1.1rem; color: #4b5563; display: block; text-align: center; margin-top: 5px; padding: 10px; background-color: #eefbf3; border-radius: 4px; border: 1px dashed #a7f3d0;}
        .error-message { color: #b91c1c; background-color: #fee2e2; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; border: 1px solid #fca5a5; font-weight: bold; text-align: center; }
        .timestamp { font-size: 0.85rem; color: #6b7280; text-align: right; margin-top: 15px;}
    </style>
</head>
<body class="bg-[#f0fdf4] min-h-screen p-4">
    <div class="container">
        <h1><?= htmlspecialchars($testTitle) ?> Sonucu</h1>

        <?php if (!empty($error)): // Eğer hata varsa göster ?>
            <div class="error-message">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php elseif ($participant): // Hata yoksa ve katılımcı bulunduysa sonuçları göster ?>
            <h2><?= htmlspecialchars($participant['name']) ?></h2>

            <div class="info-section">
                <p><strong>Sınıf:</strong> <?= htmlspecialchars($participant['class']) ?></p>
                 <p class="timestamp"><strong>Test Tarihi:</strong>
                    <?= htmlspecialchars(date('d.m.Y H:i', strtotime($participant['created_at']))) ?>
                </p>
                 <?php /* // İsteğe bağlı: Admin ID'sini göstermek isterseniz
                 <p><strong>İlgili Yönetici ID:</strong> <?= htmlspecialchars($participant['admin_id']) ?></p>
                 */ ?>
            </div>

            <div class="result-section">
                <h3 class="text-center text-lg font-semibold mb-3 text-gray-700">Değerlendirme</h3>
                <p class="text-center"><strong>Dikkat Düzeyi (Skor):</strong></p>
                <span class="score">%<?= htmlspecialchars($scorePercentage) ?></span>
                <p class="text-center"><strong>Yorum:</strong></p>
                <span class="interpretation"><?= htmlspecialchars($interpretation) ?></span>

                 <?php // Skora göre ek öneriler/bilgiler eklenebilir ?>
                 <?php if ($scorePercentage >= 70 && $scorePercentage < 85): ?>
                    <p class="mt-4 text-sm text-center text-gray-600">Dikkat becerilerini geliştirmek için MentalUp gibi uygulamalar önerilebilir.</p>
                 <?php elseif ($scorePercentage < 50): ?>
                    <p class="mt-4 text-sm text-center text-red-600 font-semibold">Uzman desteği alması ve bir uzmana yönlendirilmesi önerilir.</p>
                 <?php endif; ?>

            </div>

        <?php else: // Bu duruma normalde gelinmemeli ama fallback ?>
            <p class="text-center text-gray-600">Sonuç bilgisi bulunamadı.</p>
        <?php endif; ?>

         <div class="text-center mt-6 space-x-4"> <button onclick="window.print();" class="nav-btn submit bg-gray-500 hover:bg-gray-600 text-white py-2 px-4 rounded inline-flex items-center transition duration-150 ease-in-out">
                 <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 20 20" fill="currentColor">
                   <path fill-rule="evenodd" d="M5 4v3H4a2 2 0 00-2 2v6a2 2 0 002 2h12a2 2 0 002-2V9a2 2 0 00-2-2h-1V4a2 2 0 00-2-2H7a2 2 0 00-2 2zm2 0h6v3H7V4zm6 6H7v1a1 1 0 100 2h6a1 1 0 100-2v-1zm-3 4a1 1 0 100 2h.01a1 1 0 100-2H10z" clip-rule="evenodd" />
                 </svg>
                 Yazdır
             </button>
             <a href="dashboard.php" class="nav-btn submit bg-blue-500 hover:bg-blue-600 text-white py-2 px-4 rounded inline-flex items-center transition duration-150 ease-in-out">
                 <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 20 20" fill="currentColor">
                   <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm.707-10.293a1 1 0 00-1.414-1.414l-3 3a1 1 0 000 1.414l3 3a1 1 0 001.414-1.414L9.414 11H13a1 1 0 100-2H9.414l1.293-1.293z" clip-rule="evenodd" />
                 </svg>
                 Panele Dön
            </a>
            </div>

    </div> </body>
</html>