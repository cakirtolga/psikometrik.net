<?php
session_start();
// config.php dosyasının yolu, bu dosyanın admin klasöründe olduğu varsayılarak ayarlandı.
// Eğer view-result-4.php ana dizindeyse, require_once 'src/config.php'; olmalı.
// Eğer admin klasöründeyse, require_once __DIR__ . '/../src/config.php'; olmalı.
// Orijinal kodda tam yol kullanılmış, ona sadık kalıyorum.
require_once '/home/dahisinc/public_html/testanket/src/config.php';

// Hata Raporlama (Geliştirme için)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Giriş kontrolü
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super-admin'])) {
    header('Location: ../login.php'); // Ana dizindeki login.php'ye yönlendir
    exit();
}

// Katılımcı bilgisi
$participantId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$page_error = null; // Hata mesajları için

if (!$participantId) {
    // Orijinal kodda die() kullanılmış, hata mesajını değişkene atayarak HTML içinde göstermek daha kullanıcı dostu olabilir.
    $page_error = 'Geçersiz katılımcı ID\'si.';
    // die('Geçersiz katılımcı.'); // Orijinal davranış
}

// PHP Değişkenlerini Başlatma (HTML'de kullanılacaklar)
$survey_title_text = "Katılımcı Anketi Sonuçları"; // Varsayılan
$participant_name_for_title = "Katılımcı";
$participant_class_display = "Belirtilmemiş";
$participant_survey_title = "Anket"; // Varsayılan
$participant_object = null; 
$answers_list = []; 
$institutionWebURL = null; // Kurum logosu için

if (!$page_error) { // Sadece $participantId geçerliyse devam et
    try {
        // Katılımcı ve anket bilgisi
        // Orijinal sorgunuz sp.* alıyor, bu da survey_id'yi içerir.
        // Kurum logosu için sp.admin_id'yi de alıp users tablosundan logo yolunu çekebiliriz.
        $participantStmt = $pdo->prepare("
            SELECT sp.name, sp.class, sp.created_at, sp.survey_id, sp.admin_id, s.title AS survey_title
            FROM survey_participants sp
            JOIN surveys s ON sp.survey_id = s.id
            WHERE sp.id = ?
        ");
        $participantStmt->execute([$participantId]);
        $participant_object = $participantStmt->fetch(PDO::FETCH_ASSOC);

        if (!$participant_object) {
            $page_error = 'Katılımcı bulunamadı.';
            // die('Katılımcı bulunamadı.'); // Orijinal davranış
        } else {
            $survey_title_text = htmlspecialchars($participant_object['survey_title']);
            $participant_name_for_title = htmlspecialchars($participant_object['name']);
            $participant_class_display = htmlspecialchars($participant_object['class'] ?? 'Belirtilmemiş');
            $participant_survey_title = $survey_title_text; 

            // Kurum logosunu çek (eğer admin_id varsa)
            if (!empty($participant_object['admin_id'])) {
                $logoStmt = $pdo->prepare("SELECT institution_logo_path FROM users WHERE id = ?");
                $logoStmt->execute([$participant_object['admin_id']]);
                $logoData = $logoStmt->fetch(PDO::FETCH_ASSOC);
                if ($logoData && !empty($logoData['institution_logo_path'])) {
                    $rawInstitutionPathFromDB = $logoData['institution_logo_path'];
                    $cleanRelativePath = ltrim(str_replace(['../', '..'], '', $rawInstitutionPathFromDB), '/');
                    if (preg_match('/^(http:\/\/|https:\/\/|\/\/)/i', $cleanRelativePath)) {
                        $institutionWebURL = $cleanRelativePath;
                    } else {
                        $potentialWebPath = '/' . $cleanRelativePath;
                        $potentialServerPath = $_SERVER['DOCUMENT_ROOT'] . $potentialWebPath;
                        if (file_exists($potentialServerPath)) {
                            $institutionWebURL = $potentialWebPath;
                        } else {
                            $alternativePath = '../' . $cleanRelativePath; // Eğer admin klasöründeysek
                            if (file_exists(__DIR__ . '/' . $alternativePath)) {
                               $institutionWebURL = $alternativePath;
                            } else {
                               error_log("Kurum logosu dosyası bulunamadı (view-result-4.php): " . $potentialServerPath . " veya " . __DIR__ . '/' . $alternativePath);
                            }
                        }
                    }
                }
            }

            // Cevapları çek
            // Orijinal sorgu sq.question kullanıyor, bunu sq.question_text olarak varsayıyorum.
            // Soruları sıralamak için survey_questions tablosunda sort_order sütunu olmalı.
            // Eğer yoksa, sq.id'ye göre sıralanabilir veya sırasız listelenebilir.
            // Orijinal sorgu sıralama belirtmiyor, ben sq.id'ye göre ekliyorum.
            $answersStmt = $pdo->prepare("
                SELECT sa.question_id, sa.answer_text, sq.question_text AS question_title, sq.sort_order 
                FROM survey_answers sa
                JOIN survey_questions sq ON sa.question_id = sq.id 
                WHERE sa.participant_id = ? AND sa.survey_id = ?
                ORDER BY sq.sort_order ASC, sq.id ASC
            ");
            $answersStmt->execute([$participantId, $participant_object['survey_id']]);
            $answers_list = $answersStmt->fetchAll(PDO::FETCH_ASSOC);
        }

    } catch (PDOException $e) {
        error_log("Veritabanı hatası (view-result-4.php): " . $e->getMessage());
        $page_error = "Sonuçlar yüklenirken bir veritabanı sorunu oluştu. Lütfen sistem yöneticisine başvurun.";
    }
}

$psikometrikLogoPath = '../assets/Psikometrik.png'; // Bu dosyanın admin klasörüne göre yolu
$psikometrikLogoExists = file_exists(__DIR__ . '/' . $psikometrikLogoPath);

// Sayfa başlıklarını ayarla
$page_main_title = $survey_title_text . " - " . $participant_name_for_title . " Sonuçları";
$header_title = $survey_title_text . " Sonuçları";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo $page_main_title; ?> - Psikometrik.Net Yönetim</title>
  <meta name="robots" content="noindex, nofollow">
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <style>
    body { 
        font-family: 'Inter', sans-serif; 
        background-color: #f0fdf4; /* Yeşil tema ana arka plan */
        color: #1f2937; 
        margin:0; 
        padding:0; 
    }
    .page-header { 
        background-color: #ffffff; 
        padding: 10px 25px; 
        display: flex; 
        justify-content: space-between; 
        align-items: center; 
        border-bottom: 1px solid #a7f3d0; /* Yeşil tonu sınır */
        box-shadow: 0 2px 4px rgba(0,0,0,0.05); 
    }
    .page-header .logo-left img, .page-header .logo-right img { 
        max-height: 50px; 
        width: auto; 
    }
    .page-header .logo-left, .page-header .logo-right { 
        flex: 1; 
        display: flex; 
        align-items: center; 
    }
    .page-header .logo-left { justify-content: flex-start; }
    .page-header .logo-right { justify-content: flex-end; }
    .page-header .page-title-main { 
        flex: 2; 
        text-align: center; 
        font-size: 1.6rem; 
        color: #065f46; /* Koyu Yeşil Başlık */
        margin: 0; 
        font-weight: 600;
    }
    
    .result-container { 
        max-width: 900px; 
        margin: 20px auto; 
        background-color: #ffffff; 
        padding: 2.5rem; 
        border-radius: 0.75rem; 
        box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04); 
    }
    .content-header { 
        display: flex; 
        justify-content: space-between; 
        align-items: center; 
        margin-bottom: 1.5rem; 
        padding-bottom: 1rem;
        border-bottom: 2px solid #dcfce7; /* Açık yeşil sınır */
    }
    .main-content-title-h1 { 
        color: #065f46; /* Koyu Yeşil Başlık */ 
        font-size: 1.75rem; 
        font-weight:700; 
        flex-grow: 1; 
    }
    .participant-info-card { 
        margin-bottom: 2rem; 
        padding: 1.5rem; 
        background-color: #f0fdf4; /* Yeşil tema açık arka plan */ 
        border: 1px solid #a7f3d0; /* Yeşil tonu sınır */
        border-radius: 8px; 
    }
    .participant-info-card p { 
        margin: 0.5rem 0; 
        font-size: 1rem; 
        color: #1f2937;
    }
    .participant-info-card strong { 
        font-weight: 600; 
        color: #059669; /* Ana yeşil */
        min-width: 150px; /* Etiket genişliği */
        display: inline-block; 
    }
    .section-title-custom { 
        font-size: 1.5rem; 
        font-weight: 600; 
        color: #065f46; /* Koyu Yeşil */ 
        margin-top: 2rem; 
        margin-bottom: 1.25rem; 
        padding-bottom: 0.75rem; 
        border-bottom: 2px solid #6ee7b7; /* Daha belirgin yeşil sınır */
    }
    .answers-list li {
        background-color: #f9fafb; /* Hafif gri */
        border: 1px solid #dcfce7; /* Açık yeşil sınır */
        padding: 1rem;
        border-radius: 0.375rem; /* 6px */
        margin-bottom: 0.75rem;
    }
    .answers-list .question-header { 
        display: block;
        color: #059669; /* Ana yeşil */
        margin-bottom: 0.25rem;
        font-weight: 500;
    }
    .answer-text-display { 
        color: #374151; 
    }

    .alert-danger { background-color: #fef2f2; border-left: 4px solid #fca5a5; color: #b91c1c; padding: 1rem; border-radius: 0.375rem; margin-bottom: 1.5rem; }
    .footer-text { color: #4ade80; text-align: center; margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #dcfce7;}
    .action-button { 
        display: inline-flex; align-items: center; padding: 10px 20px; 
        font-size: 1rem; font-weight: 600; color: white; 
        background-color: #10b981; /* Yeşil buton */ 
        border: none; border-radius: 6px; cursor: pointer; 
        text-decoration: none; transition: background-color 0.2s ease; 
    }
    .action-button:hover { background-color: #059669; }
    .action-button.panel-btn { background-color: #065f46; }
    .action-button.panel-btn:hover { background-color: #044e3a; }
    .action-button.print-btn { background-color: #0ea5e9; } 
    .action-button.print-btn:hover { background-color: #0284c7; }

    @media print {
        body { background-color: #fff !important; color: #000 !important; font-size: 10pt !important; }
        .page-header { display: flex !important; border-bottom: 1px solid #000 !important; box-shadow: none !important; padding: 5mm 0 !important; margin-bottom:5mm !important;}
        .page-header .logo-left img, .page-header .logo-right img { max-height: 35px !important; }
        .page-header .page-title-main { font-size: 12pt !important; color: #000 !important; }
        .result-container { margin: 0 !important; padding: 1cm !important; box-shadow: none !important; border: none !important; }
        .content-header { display: none !important; }
        .main-content-title-h1.print-only { display: block !important; text-align: center !important; font-size: 14pt !important; margin-top:0 !important; }
        .participant-info-card { background-color: #f0f0f0 !important; border: 1px solid #ccc !important; }
        .section-title-custom { font-size: 11pt !important; color: #000 !important; border-bottom-color: #ccc !important; }
        .answers-list li { background-color: #f9f9f9 !important; border-color: #ddd !important; }
        .answers-list .question-header { color: #000 !important; }
        .answer-text-display { color: #333 !important; }
        .no-print { display: none !important; }
    }
  </style>
</head>
<body class="bg-gray-100">

    <div class="page-header">
         <div class="logo-left">
            <?php if($institutionWebURL): // Kurum logosu (PHP'de çekiliyorsa) ?>
                <img src="<?php echo htmlspecialchars($institutionWebURL); ?>" alt="Kurum Logosu">
            <?php else: ?><span>&nbsp;</span><?php endif; ?>
        </div>
        <div class="page-title-main">
            <?php echo $header_title; ?>
        </div>
        <div class="logo-right">
            <?php if ($psikometrikLogoExists): ?>
                <img src="<?php echo htmlspecialchars($psikometrikLogoPath); ?>" alt="Psikometrik.Net Logosu">
            <?php else: ?>
                <span>Psikometrik.Net</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="result-container">
        <div class="content-header no-print">
            <h1 class="main-content-title-h1"><?php echo $page_main_title; ?></h1>
            <a href="dashboard.php" class="action-button panel-btn">
                <i class="fas fa-arrow-left mr-2"></i>Kontrol Paneline Dön
            </a>
        </div>
        <h1 class="main-content-title-h1 print-only" style="display:none;"><?php echo $page_main_title; ?></h1>

        <?php if ($page_error): ?>
            <div class="alert-danger" role="alert">
                <p class="font-bold">Hata!</p>
                <p><?php echo htmlspecialchars($page_error); ?></p>
            </div>
        <?php elseif (!$participant_object): // $participant_object kontrolü $page_error'dan sonra olmalı ?>
            <div class="alert-danger" role="alert">
                <p>Katılımcı bulunamadı.</p>
            </div>
        <?php else: ?>
            <div class="participant-info-card">
                <h2 class="section-title-custom !mt-0 !mb-3 !border-b-0">Katılımcı Bilgileri</h2>
                <p><strong>Ad Soyad:</strong> <?php echo htmlspecialchars($participant_object['name']); ?></p>
                <p><strong>Sınıf/Grup:</strong> <?php echo $participant_class_display; ?></p>
                <p><strong>Anket Tarihi:</strong> <?php echo date('d.m.Y H:i', strtotime($participant_object['created_at'])); ?></p>
                <p><strong>Anket Başlığı:</strong> <?php echo htmlspecialchars($participant_object['survey_title']); ?></p>
                <p><strong>Katılımcı ID:</strong> <?php echo htmlspecialchars($participantId); ?></p>
            </div>

            <div class="mb-8">
                <h2 class="section-title-custom">Verilen Cevaplar</h2>
                <?php if (count($answers_list) > 0): ?>
                <ul class="answers-list space-y-3">
                    <?php foreach ($answers_list as $index => $answer): ?>
                    <li>
                        <strong class="question-header">Soru <?php echo htmlspecialchars($answer['sort_order'] ?? ($index + 1)); ?>: <?php echo htmlspecialchars($answer['question_title'] ?? 'Soru metni bulunamadı'); ?></strong>
                        <span class="answer-text-display">Cevap: <?php echo htmlspecialchars($answer['answer_text']); ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                <p class="text-gray-600">Bu katılımcı için henüz cevap bulunamadı.</p>
                <?php endif; ?>
            </div>

            <div class="button-container mt-8 pt-6 border-t border-gray-300 no-print">
                 <a href="dashboard.php" class="action-button panel-btn">
                    <i class="fas fa-tachometer-alt mr-2"></i>Kontrol Paneline Dön
                 </a>
                 <button onclick="window.print();" class="action-button print-btn">
                    <i class="fas fa-print mr-2"></i>Çıktı Al
                 </button>
            </div>
        <?php endif; ?>
    </div>
    
    <footer class="footer-text no-print">
        &copy; <?php echo date("Y"); ?> Psikometrik.Net
    </footer>

</body>
</html>
