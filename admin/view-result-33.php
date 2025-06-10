<?php
session_start();

// Hata Raporlama (Geliştirme için)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Veritabanı bağlantısı
require_once '../src/config.php'; // Ana dizindeki src klasörüne göre yol

if (!isset($pdo) || !$pdo instanceof PDO) {
    error_log("Veritabanı bağlantısı (\$pdo) 'config.php' dosyasında kurulamadı veya geçerli değil. admin/view-result-33.php");
    die("Sistemde bir hata oluştu. Lütfen daha sonra tekrar deneyin veya site yöneticisi ile iletişime geçin.");
}

// Admin girişi kontrolü - dashboard.php ile tutarlı hale getirildi
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super-admin'])) {
    header("Location: ../login.php"); 
    exit;
}

$survey_id_to_view = 33; 
$participant_id_to_view = null;
$page_error = null;
$institutionWebURL = null; // Kurum logosu için web yolu
$psikometrikWebURL = '../assets/Psikometrik.png'; // Psikometrik logo yolu

if (isset($_GET['id']) && filter_var($_GET['id'], FILTER_VALIDATE_INT)) {
    $participant_id_to_view = (int)$_GET['id'];
} else {
    $page_error = "Geçerli bir katılımcı ID'si belirtilmedi.";
}

$survey_title_text = "Anket Sonuçları (ID: {$survey_id_to_view})"; 
$page_main_title = $survey_title_text; // Sayfanın ana başlığı için
$header_title = $survey_title_text; // Üstteki header için başlık

try {
    $stmt_survey_title = $pdo->prepare("SELECT title FROM surveys WHERE id = :survey_id");
    $stmt_survey_title->bindParam(':survey_id', $survey_id_to_view, PDO::PARAM_INT);
    $stmt_survey_title->execute();
    $survey_title_data = $stmt_survey_title->fetch(PDO::FETCH_ASSOC);
    if ($survey_title_data && !empty($survey_title_data['title'])) {
        $survey_title_text = htmlspecialchars($survey_title_data['title']);
        $page_main_title = $survey_title_text . " Sonuçları";
        $header_title = $survey_title_text . " Sonuçları"; // Header başlığını da güncelle
    }
} catch (PDOException $e) {
    error_log("Anket başlığı çekilirken hata (view-result-33.php): " . $e->getMessage());
}

$grouped_results = [];
$participant_admin_id = null; // Katılımcının admin_id'sini tutmak için

if (!$page_error && $participant_id_to_view) {
    try {
        $stmt_results = $pdo->prepare("
            SELECT 
                sa.id as answer_id,
                sa.participant_id,
                p.name as parent_name, 
                p.class as child_class, 
                p.description as participant_description, 
                p.created_at as participation_date,
                p.admin_id as participant_admin_id, /* Katılımcının admin_id'si eklendi */
                sq.question_text,
                sq.sort_order as question_sort_order, 
                sa.answer_text
            FROM survey_answers sa
            JOIN survey_participants p ON sa.participant_id = p.id
            JOIN survey_questions sq ON sa.question_id = sq.sort_order AND sq.survey_id = sa.survey_id
            WHERE sa.survey_id = :survey_id AND p.id = :participant_id
            ORDER BY sq.sort_order ASC
        ");
        $stmt_results->bindParam(':survey_id', $survey_id_to_view, PDO::PARAM_INT);
        $stmt_results->bindParam(':participant_id', $participant_id_to_view, PDO::PARAM_INT);
        $stmt_results->execute();
        $all_answers_for_participant = $stmt_results->fetchAll(PDO::FETCH_ASSOC);

        if ($all_answers_for_participant) {
            $first_answer = $all_answers_for_participant[0]; 
            $participant_admin_id = $first_answer['participant_admin_id']; // admin_id'yi al
            $child_details_from_json = json_decode($first_answer['participant_description'], true);
            
            $grouped_results[$participant_id_to_view] = [
                'parent_name' => htmlspecialchars($first_answer['parent_name']),
                'child_name' => isset($child_details_from_json['child_name']) ? htmlspecialchars($child_details_from_json['child_name']) : 'Belirtilmemiş',
                'child_class' => htmlspecialchars($first_answer['child_class']),
                'child_school_number' => isset($child_details_from_json['child_school_number']) ? htmlspecialchars($child_details_from_json['child_school_number']) : 'Belirtilmemiş',
                'participation_date' => date('d.m.Y H:i', strtotime($first_answer['participation_date'])),
                'answers' => []
            ];

            foreach ($all_answers_for_participant as $answer) {
                $grouped_results[$participant_id_to_view]['answers'][] = [
                    'question_text' => htmlspecialchars($answer['question_text']),
                    'answer_text' => htmlspecialchars($answer['answer_text']),
                    'question_sort_order' => $answer['question_sort_order']
                ];
            }
            
            if ($grouped_results[$participant_id_to_view]['parent_name'] !== 'Belirtilmemiş' && $grouped_results[$participant_id_to_view]['parent_name'] !== '') {
                 $page_main_title = $survey_title_text . " - " . $grouped_results[$participant_id_to_view]['parent_name'] . " Sonuçları";
                 $header_title = $survey_title_text . " Sonuçları"; 
            } elseif ($grouped_results[$participant_id_to_view]['child_name'] !== 'Belirtilmemiş' && $grouped_results[$participant_id_to_view]['child_name'] !== '') {
                 $page_main_title = $survey_title_text . " - " . $grouped_results[$participant_id_to_view]['child_name'] . " Adlı Öğrencinin Velisi Sonuçları";
                 $header_title = $survey_title_text . " Sonuçları";
            }

            if ($participant_admin_id) {
                $stmt_logo = $pdo->prepare("SELECT institution_logo_path FROM users WHERE id = :admin_id");
                $stmt_logo->bindParam(':admin_id', $participant_admin_id, PDO::PARAM_INT);
                $stmt_logo->execute();
                $logo_data = $stmt_logo->fetch(PDO::FETCH_ASSOC);
                if ($logo_data && !empty($logo_data['institution_logo_path'])) {
                    $rawInstitutionPathFromDB = $logo_data['institution_logo_path'];
                    $cleanRelativePath = ltrim(str_replace('..', '', $rawInstitutionPathFromDB), '/');
                    $fullServerPath = $_SERVER['DOCUMENT_ROOT'] . '/' . $cleanRelativePath; 
                    if (file_exists($fullServerPath)) {
                       $institutionWebURL = '/' . $cleanRelativePath; 
                    } else {
                       error_log("Kurum logosu dosyası bulunamadı (view-result-33.php): " . $fullServerPath . " (Admin ID: " . $participant_admin_id . ")");
                    }
                } else {
                     error_log("Kurum logosu yolu users tablosunda bulunamadı. Admin ID: " . $participant_admin_id);
                }
            }
        } else {
            $page_error = "Belirtilen katılımcı (ID: {$participant_id_to_view}) için anket sonucu bulunamadı veya katılımcı bu anketi tamamlamamış.";
        }
    } catch (PDOException $e) {
        $page_error = "Sonuçlar yüklenirken bir veritabanı hatası oluştu. Lütfen sistem yöneticisine başvurun.";
        error_log("Sonuç çekme hatası (view-result-33.php): " . $e->getMessage() . " - SurveyID: {$survey_id_to_view}, ParticipantID: {$participant_id_to_view}");
    }
}

$psikometrikLogoExists = false;
if ($psikometrikWebURL) {
    $fullPsikoServerPath = realpath(__DIR__ . '/' . $psikometrikWebURL);
    if ($fullPsikoServerPath && file_exists($fullPsikoServerPath)) {
        $psikometrikLogoExists = true;
    } else {
        error_log("Psikometrik logo dosyası bulunamadı (view-result-33.php): " . $psikometrikWebURL . " (Çözümlenen: " . $fullPsikoServerPath . ")");
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_main_title; ?> - Admin Paneli</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f3f4f6; }
        .container-main { max-width: 900px; margin-left: auto; margin-right: auto; } 
        
        .page-header {
            background-color: #ffffff;
            padding: 10px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e0e0e0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            margin-bottom: 1.5rem; 
        }
         .page-header .logo-left img,
         .page-header .logo-right img {
            max-height: 50px; 
            width: auto;
         }
         .page-header .logo-left,
         .page-header .logo-right {
             flex: 1; 
             display: flex;
             align-items: center;
         }
         .page-header .logo-left { justify-content: flex-start; }
         .page-header .logo-right { justify-content: flex-end; }
         .page-header .page-title-main { 
             flex: 2; 
             text-align: center;
             font-size: 1.8rem;
             color: #1f2937; 
             margin: 0;
             font-weight: 600;
         }

        .participant-card { background-color: white; border-radius: 0.5rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06); margin-bottom: 1.5rem; padding: 1.5rem; }
        .participant-info { border-bottom: 1px solid #e5e7eb; padding-bottom: 1rem; margin-bottom: 1rem; }
        .participant-info h2 { font-size: 1.25rem; font-weight: 600; color: #1e40af; }
        .participant-info p { font-size: 0.875rem; color: #4b5563; margin-top: 0.25rem; }
        .answer-list h3 { font-size: 1.125rem; font-weight: 500; color: #1f2937; margin-bottom: 0.75rem; }
        .answer-item { padding: 0.5rem 0; border-bottom: 1px dashed #e5e7eb; }
        .answer-item:last-child { border-bottom: none; }
        .answer-item .question { font-weight: 500; color: #374151; }
        .answer-item .answer { color: #111827; padding-left: 1rem; }
        .back-link { display: inline-block; padding: 0.5rem 1rem; background-color: #4b5563; color: white; border-radius: 0.375rem; text-decoration: none; transition: background-color 0.2s ease; }
        .back-link:hover { background-color: #374151; }
        .error-box { background-color: #fee2e2; border-left: 4px solid #f87171; padding: 1rem; color: #b91c1c; border-radius: 0.25rem; }
        .info-box { background-color: #fefce8; border-left: 4px solid #facc15; padding: 1rem; color: #ca8a04; border-radius: 0.25rem; }
        
        .button-container {
             text-align: center;
             margin-top: 2.5rem; 
             padding-top: 1.5rem; 
             border-top: 1px solid #e0e0e0; 
             display: flex; 
             justify-content: center; 
             gap: 1rem; 
        }
        .action-button {
             display: inline-flex; 
             align-items: center; 
             padding: 10px 20px; 
             font-size: 1rem; 
             font-weight: 600; 
             color: white;
             background-color: #15803d; 
             border: none;
             border-radius: 6px; 
             cursor: pointer;
             text-decoration: none; 
             transition: background-color 0.2s ease, box-shadow 0.2s ease; 
             box-shadow: 0 1px 2px rgba(0,0,0,0.05); 
        }
        .action-button:hover { background-color: #0b532c; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .action-button.panel-button { background-color: #6c757d; } 
        .action-button.panel-button:hover { background-color: #5a6268; }

         @media print {
            @page {
                margin: 20mm;
            }
             html, body {
                 margin: 10mm !important; 
                 padding: 0 !important;
                 height: auto !important;
                 min-height: initial !important;
                 background-color: #fff !important;
                 color: #000 !important;
                 -webkit-print-color-adjust: exact !important; 
                 print-color-adjust: exact !important;
             }
             body {
                 /* @page kullanıldığı için body margin'i 0 olmalı, sayfa marjini @page'den gelir */
                 font-size: 10pt !important;
             }
             .page-header { 
                 display: flex !important; 
                 padding: 10px 0 !important; /* Dikey padding azaltılabilir */
                 border-bottom: 1px solid #000 !important;
                 box-shadow: none !important;
                 margin-top: 0 !important; /* Üstteki boşluğu kaldır */
                 margin-bottom: 1rem !important; /* Alt boşluğu biraz azalt */
                 width: 100% !important; 
                 position: static !important; 
                 page-break-before: auto !important;
                 page-break-after: auto !important;
             }
             .page-header .logo-left img,
             .page-header .logo-right img {
                max-height: 35px !important; /* Yazdırmada logoları biraz küçült */
                width: auto !important;
             }
             .page-header .logo-left { justify-content: flex-start !important; }
             .page-header .logo-right { justify-content: flex-end !important; }
             .page-header .page-title-main {
                 font-size: 13pt !important; /* Başlık fontunu biraz küçült */
                 color: #000 !important;
             }
             .container-main {
                 box-shadow: none !important;
                 border: none !important;
                 margin: 0 !important; 
                 padding: 0 !important;
                 max-width: 100% !important;
                 width: 100% !important;
                 page-break-before: auto !important;
             }
             /* Ana başlığın (h1) üstündeki boşluğu azalt */
             .container-main > .flex.justify-between.items-center {
                 margin-bottom: 1rem !important; /* Başlık altı boşluğu azalt */
             }
             .container-main > .flex.justify-between.items-center > h1 {
                 font-size: 12pt !important; /* Ana içerik başlığını küçült */
             }

             .back-link, .button-container.no-print {
                 display: none !important;
             }
             .participant-card {
                 box-shadow: none !important;
                 border: 1px solid #ccc !important;
                 padding: 0.75rem !important; /* Kart iç boşluğunu azalt */
                 margin-bottom: 0.75rem !important; /* Kartlar arası boşluğu azalt */
                 page-break-inside: avoid !important;
             }
             .participant-info {
                 padding-bottom: 0.5rem !important;
                 margin-bottom: 0.5rem !important;
             }
             .participant-info h2 { font-size: 10pt !important; color: #000 !important;}
             .participant-info p { font-size: 8pt !important; color: #000 !important; margin-top: 0.15rem !important;}
             .answer-list h3 { font-size: 9pt !important; color: #000 !important; margin-bottom: 0.5rem !important;}
             .answer-item { border-bottom: 1px dashed #ccc !important; padding: 0.25rem 0 !important; }
             .answer-item .question { font-size: 8pt !important; color: #000 !important;}
             .answer-item .answer { font-size: 8pt !important; color: #000 !important; padding-left: 0.5rem !important;}
             footer { display: none !important; }
         }
    </style>
</head>
<body class="bg-gray-100">

    <div class="page-header">
        <div class="logo-left">
            <?php if($institutionWebURL): ?>
                <img src="<?php echo htmlspecialchars($institutionWebURL); ?>" alt="Kurum Logosu">
            <?php else: ?><span>&nbsp;</span><?php endif; ?>
        </div>
        <div class="page-title-main">
            <?php echo $header_title; ?>
        </div>
        <div class="logo-right">
            <?php if ($psikometrikLogoExists): ?>
                <img src="<?php echo htmlspecialchars($psikometrikWebURL); ?>" alt="Psikometrik.Net Logosu">
            <?php else: ?>
                <span>Psikometrik.Net</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="container-main mx-auto p-4 md:p-6 lg:p-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl md:text-3xl font-bold text-gray-800"><?php echo $page_main_title; ?></h1>
            <a href="dashboard.php" class="back-link">
                <i class="fas fa-arrow-left mr-2"></i>Kontrol Paneline Dön
            </a>
        </div>

        <?php if ($page_error): ?>
            <div class="error-box" role="alert">
                <p class="font-bold">Hata!</p>
                <p><?php echo htmlspecialchars($page_error); ?></p>
            </div>
        <?php elseif (empty($grouped_results)): ?>
            <div class="info-box" role="alert">
                <p class="font-bold">Bilgi</p>
                <p>Bu katılımcı için sonuç bulunamadı veya bir sorun oluştu.</p>
            </div>
        <?php else: ?>
            <?php foreach ($grouped_results as $p_id => $data): ?>
                <div class="participant-card">
                    <div class="participant-info">
                        <h2>Veli: <?php echo $data['parent_name']; ?></h2>
                        <p><strong>Çocuk Adı Soyadı:</strong> <?php echo $data['child_name']; ?></p>
                        <p><strong>Sınıfı:</strong> <?php echo $data['child_class']; ?></p>
                        <p><strong>Okul Numarası:</strong> <?php echo $data['child_school_number']; ?></p>
                        <p><strong>Yanıt Tarihi:</strong> <?php echo $data['participation_date']; ?></p>
                        <p><strong>Katılımcı ID:</strong> <?php echo $p_id; ?></p>
                    </div>
                    <div class="answer-list">
                        <h3>Verilen Cevaplar:</h3>
                        <?php if (empty($data['answers'])): ?>
                            <p class="text-gray-500">Bu katılımcı için cevap bulunamadı.</p>
                        <?php else: ?>
                            <?php foreach ($data['answers'] as $answer_data): ?>
                                <div class="answer-item">
                                    <p class="question"><?php echo htmlspecialchars($answer_data['question_sort_order']) . ". " . $answer_data['question_text']; ?></p>
                                    <p class="answer">- <?php echo $answer_data['answer_text']; ?></p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <div class="button-container no-print">
                 <a href="dashboard.php" class="action-button panel-button">Kontrol Paneline Dön</a>
                 <button onclick="window.print();" class="action-button">Çıktı Al</button>
            </div>

        <?php endif; ?>
    </div>
    <footer class="text-center p-4 text-sm text-gray-500 mt-8">
        &copy; <?php echo date("Y"); ?> Anket Sistemi - Admin Paneli
    </footer>
</body>
</html>
