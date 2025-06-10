<?php
session_start();

// Hata Raporlama (Geliştirme için)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Veritabanı bağlantısı
require_once '../src/config.php'; 

if (!isset($pdo) || !$pdo instanceof PDO) {
    error_log("Veritabanı bağlantısı (\$pdo) 'config.php' dosyasında kurulamadı veya geçerli değil. admin/view-result-35.php");
    die("Sistemde bir hata oluştu. Lütfen daha sonra tekrar deneyin veya site yöneticisi ile iletişime geçin.");
}

// Admin girişi kontrolü - dashboard.php ile tutarlı
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super-admin'])) {
    header("Location: ../login.php"); 
    exit;
}

$survey_id_to_view = 35; 
$participant_id_to_view = null;
$page_error = null;
$institutionWebURL = null; 
$psikometrikWebURL = '../assets/Psikometrik.png'; 

if (isset($_GET['id']) && filter_var($_GET['id'], FILTER_VALIDATE_INT)) {
    $participant_id_to_view = (int)$_GET['id'];
} else {
    $page_error = "Geçerli bir katılımcı ID'si belirtilmedi.";
}

$survey_title_text = "Riba 2 - Lise Veli Sonuçları"; 
$page_main_title = $survey_title_text; 
$header_title = $survey_title_text;

try {
    $stmt_survey_title = $pdo->prepare("SELECT title FROM surveys WHERE id = :survey_id");
    $stmt_survey_title->bindParam(':survey_id', $survey_id_to_view, PDO::PARAM_INT);
    $stmt_survey_title->execute();
    $survey_title_data = $stmt_survey_title->fetch(PDO::FETCH_ASSOC);
    if ($survey_title_data && !empty($survey_title_data['title'])) {
        $survey_title_text = htmlspecialchars($survey_title_data['title']);
        $page_main_title = $survey_title_text . " Sonuçları";
        $header_title = $survey_title_text . " Sonuçları";
    }
} catch (PDOException $e) {
    error_log("Anket başlığı çekilirken hata (view-result-35.php): " . $e->getMessage());
}

$participant_data_display = null; 
$participant_admin_id = null;

if (!$page_error && $participant_id_to_view) {
    try {
        $stmt_results = $pdo->prepare("
            SELECT 
                p.id as participant_id,
                p.name as parent_name,         
                p.class as child_class,        
                p.description as participant_description, 
                p.created_at as participation_date,
                p.admin_id as participant_admin_id,
                sq.question_text, 
                sq.sort_order as question_sort_order, 
                sa.answer_text 
            FROM survey_participants p
            LEFT JOIN survey_answers sa ON p.id = sa.participant_id AND sa.survey_id = :survey_id_answers
            LEFT JOIN survey_questions sq ON sa.question_id = sq.sort_order AND sq.survey_id = :survey_id_questions
            WHERE p.id = :participant_id AND p.survey_id = :survey_id_participant
            ORDER BY sq.sort_order ASC
        ");
        $stmt_results->bindParam(':survey_id_answers', $survey_id_to_view, PDO::PARAM_INT);
        $stmt_results->bindParam(':survey_id_questions', $survey_id_to_view, PDO::PARAM_INT);
        $stmt_results->bindParam(':participant_id', $participant_id_to_view, PDO::PARAM_INT);
        $stmt_results->bindParam(':survey_id_participant', $survey_id_to_view, PDO::PARAM_INT);
        $stmt_results->execute();
        $results_for_participant = $stmt_results->fetchAll(PDO::FETCH_ASSOC);

        if ($results_for_participant) {
            $first_row = $results_for_participant[0]; 
            $participant_admin_id = $first_row['participant_admin_id'];
            
            $desc_json = json_decode($first_row['participant_description'], true);
            $child_name_from_desc = isset($desc_json['child_name']) ? htmlspecialchars($desc_json['child_name']) : 'Belirtilmemiş';
            $child_school_no_from_desc = isset($desc_json['child_school_number']) ? htmlspecialchars($desc_json['child_school_number']) : 'Belirtilmemiş';

            $participant_data_display = [
                'parent_name' => htmlspecialchars($first_row['parent_name']),
                'child_name' => $child_name_from_desc,
                'child_class' => htmlspecialchars($first_row['child_class']),
                'child_school_number' => $child_school_no_from_desc,
                'participation_date' => date('d.m.Y H:i', strtotime($first_row['participation_date'])),
                'answers' => []
            ];

            foreach ($results_for_participant as $row) {
                if ($row['question_sort_order'] !== null) { 
                     $participant_data_display['answers'][] = [
                        'question_text' => htmlspecialchars($row['question_text']), 
                        'answer_text' => htmlspecialchars($row['answer_text']), 
                        'question_sort_order' => $row['question_sort_order']
                    ];
                }
            }
            
            if ($participant_data_display['parent_name'] !== 'Belirtilmemiş' && $participant_data_display['parent_name'] !== '') {
                 $page_main_title = $survey_title_text . " - " . $participant_data_display['parent_name'] . " (Veli) Sonuçları";
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
                       error_log("Kurum logosu dosyası bulunamadı (view-result-35.php): " . $fullServerPath . " (Admin ID: " . $participant_admin_id . ")");
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
        error_log("Sonuç çekme hatası (view-result-35.php): " . $e->getMessage() . " - SurveyID: {$survey_id_to_view}, ParticipantID: {$participant_id_to_view}");
    }
}

$psikometrikLogoExists = false;
if ($psikometrikWebURL) {
    $fullPsikoServerPath = realpath(__DIR__ . '/' . $psikometrikWebURL);
    if ($fullPsikoServerPath && file_exists($fullPsikoServerPath)) {
        $psikometrikLogoExists = true;
    } else {
        error_log("Psikometrik logo dosyası bulunamadı (view-result-35.php): " . $psikometrikWebURL . " (Çözümlenen: " . $fullPsikoServerPath . ")");
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
        body { font-family: 'Inter', sans-serif; background-color: #f0fdf4; color: #2c3e50; margin:0; padding:0; }
        .page-header { background-color: #ffffff; padding: 10px 25px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e0e0e0; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .page-header .logo-left img, .page-header .logo-right img { max-height: 50px; width: auto; }
        .page-header .logo-left, .page-header .logo-right { flex: 1; display: flex; align-items: center; }
        .page-header .logo-left { justify-content: flex-start; }
        .page-header .logo-right { justify-content: flex-end; }
        .page-header .page-title-main { flex: 2; text-align: center; font-size: 1.8rem; color: #1f2937; margin: 0; font-weight: 600;}
        
        .container-main { max-width: 900px; margin: 20px auto; background: white; padding: 20px 30px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .main-content-title-h1 { text-align: center; color: #1f2937; margin-top: 0; margin-bottom: 1.5rem; font-size: 1.75rem; border-bottom: 2px solid #dcfce7; padding-bottom: 0.75rem; }
        h2.section-title { font-size: 1.4rem; color: #15803d; margin-top: 2rem; margin-bottom: 1rem; border-bottom: 1px solid #eee; padding-bottom: 0.4rem; }

        .participant-info-card { margin-bottom: 1.5rem; padding: 15px; background-color: #f9fafb; border: 1px solid #f3f4f6; border-radius: 8px; }
        .participant-info-card p { margin: 0.4rem 0; font-size: 1rem; }
        .participant-info-card strong { font-weight: 600; color: #374151; min-width: 180px; display: inline-block; }

        .answers-table { width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: 0.9rem; }
        .answers-table th, .answers-table td { border: 1px solid #ddd; padding: 8px 10px; text-align: left; vertical-align: top; }
        .answers-table th { background-color: #dcfce7; font-weight: 600; color: #1f2937; }
        .answers-table td.question-sort-order { width: 10%; text-align: center; font-weight: bold; vertical-align: middle;} 
        .answers-table td.question-text-cell { width: 50%; line-height: 1.4; } 
        .answers-table td.answer-text-cell { width: 40%; text-align: left; vertical-align: middle; font-weight: normal;} 
        .answers-table tr:nth-child(even) { background-color: #f8f9fa; }

        .error-box { color: #b91c1c; background-color: #fee2e2; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; border: 1px solid #fca5a5; font-weight: 500; text-align: left; font-size: 0.9em;}
        .info-box { background-color: #fefce8; border-left: 4px solid #facc15; padding: 1rem; color: #ca8a04; border-radius: 0.25rem; }
        
        .button-container { display: flex; justify-content: center; gap: 1rem; margin-top: 2.5rem; padding-top: 1.5rem; border-top: 1px solid #e0e0e0; }
        .action-button { display: inline-flex; align-items: center; padding: 10px 20px; font-size: 1rem; font-weight: 600; color: white; background-color: #15803d; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; transition: background-color 0.2s ease, box-shadow 0.2s ease; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .action-button:hover { background-color: #0b532c; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .action-button.panel-button { background-color: #6c757d; } 
        .action-button.panel-button:hover { background-color: #5a6268; }

         @media print {
            @page {
                margin: 20mm !important; 
                size: A4; 
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
                 font-size: 10pt !important; 
             }
             .page-header { 
                 display: flex !important; 
                 padding: 0 !important; 
                 border-bottom: 1px solid #000 !important;
                 box-shadow: none !important;
                 margin-top: 0 !important; 
                 margin-bottom: 0.1rem !important; 
                 width: 100% !important; 
                 position: static !important; 
                 page-break-after: avoid !important; 
             }
             .page-header .logo-left img,
             .page-header .logo-right img {
                max-height: 45px !important; /* Logo boyutu artırıldı */
                width: auto !important;
             }
             .page-header .logo-left { justify-content: flex-start !important; }
             .page-header .logo-right { justify-content: flex-end !important; }
             .page-header .page-title-main {
                 font-size: 11pt !important; 
                 color: #000 !important;
                 font-weight: bold !important;
                 padding: 2px 0 !important; 
             }
             .container-main {
                 box-shadow: none !important;
                 border: none !important;
                 margin: 0 !important; 
                 padding: 0 !important; 
                 max-width: 100% !important;
                 width: 100% !important;
             }
             .container-main > .flex.justify-between.items-center.no-print { 
                 display: none !important; 
             }
             h1.main-content-title-h1.print-only { 
                 display: block !important; 
                 text-align: center !important;
                 font-size: 11pt !important; 
                 font-weight: bold !important;
                 margin-top: 0.1rem !important; 
                 margin-bottom: 0.1rem !important; 
                 padding-bottom: 0.1rem !important; 
                 border-bottom: 1px solid #ccc !important;
                 color: #000 !important;
                 page-break-after: avoid !important; 
             }
             h2.section-title { 
                 font-size: 10pt !important; color: #000 !important; 
                 border-bottom: 1px dashed #ccc !important; padding-bottom: 2px !important; 
                 margin-top: 0.1rem !important; margin-bottom: 0.1rem !important; 
             }
             .participant-info-card {
                 box-shadow: none !important;
                 border: 1px solid #ccc !important;
                 padding: 0.2rem !important; 
                 margin-top: 0.1rem !important; 
                 margin-bottom: 0.2rem !important; 
                 page-break-inside: avoid !important;
             }
             .participant-info-card p { margin: 1px 0 !important; font-size: 8pt !important; color: #000 !important; margin-top: 0.05rem !important;}
             .answers-table { margin-top: 5px !important; font-size: 8pt !important; page-break-inside: avoid !important;} 
             .answers-table th, .answers-table td { border: 1px solid #000 !important; padding: 2px 4px !important; } 
             .answers-table th { background-color: #eee !important; color: #000 !important; }
             .answers-table td.question-sort-order, .answers-table td.answer-text-cell { font-weight: normal !important; }
             .answers-table tr:nth-child(even) { background-color: #fff !important; }
             .no-print, .button-container, footer { 
                 display: none !important; 
             }
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
        <div class="flex justify-between items-center mb-6 no-print">
            <h1 class="text-2xl md:text-3xl font-bold text-gray-800"><?php echo $page_main_title; ?></h1>
            <a href="dashboard.php" class="text-blue-600 hover:text-blue-800 underline">
                <i class="fas fa-arrow-left mr-2"></i>Kontrol Paneline Dön
            </a>
        </div>
        <h1 class="main-content-title-h1 print-only" style="display:none;"><?php echo $page_main_title; ?></h1>


        <?php if ($page_error): ?>
            <div class="error-box" role="alert">
                <p class="font-bold">Hata!</p>
                <p><?php echo htmlspecialchars($page_error); ?></p>
            </div>
        <?php elseif (!$participant_data_display): ?>
            <div class="info-box" role="alert">
                <p class="font-bold">Bilgi</p>
                <p>Bu katılımcı için sonuç bulunamadı veya bir sorun oluştu.</p>
            </div>
        <?php else: ?>
            <div class="participant-info-card">
                <h2 class="section-title !mt-0 !mb-2 !border-b-0">Katılımcı Bilgileri</h2>
                <p><strong>Veli Adı Soyadı:</strong> <?php echo $participant_data_display['parent_name']; ?></p>
                <p><strong>Öğrenci Adı Soyadı:</strong> <?php echo $participant_data_display['child_name']; ?></p>
                <p><strong>Öğrencinin Sınıfı:</strong> <?php echo $participant_data_display['child_class']; ?></p>
                <p><strong>Öğrencinin Okul Numarası:</strong> <?php echo $participant_data_display['child_school_number']; ?></p>
                <p><strong>Yanıt Tarihi:</strong> <?php echo $participant_data_display['participation_date']; ?></p>
                <p><strong>Katılımcı ID:</strong> <?php echo htmlspecialchars($participant_id_to_view); ?></p>
            </div>
            
            <div class="answers-section mt-6">
                <h2 class="section-title !mt-0 !mb-2 !border-b-0">Verilen Cevaplar</h2>
                <?php if (empty($participant_data_display['answers'])): ?>
                    <p class="text-gray-500">Bu katılımcı için cevap bulunamadı.</p>
                <?php else: ?>
                    <table class="answers-table">
                        <thead>
                            <tr>
                                <th class="question-sort-order">Soru No</th>
                                <th class="question-text-cell">Soru Metni</th>
                                <th class="answer-text-cell">Seçilen Cevap</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($participant_data_display['answers'] as $answer_data): ?>
                                <tr>
                                    <td class="question-sort-order"><?php echo htmlspecialchars($answer_data['question_sort_order']); ?></td>
                                    <td class="question-text-cell"><?php echo $answer_data['question_text']; ?></td>
                                    <td class="answer-text-cell"><?php echo $answer_data['answer_text']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            
            <div class="button-container no-print">
                 <a href="dashboard.php" class="action-button panel-button">Kontrol Paneline Dön</a>
                 <button onclick="window.print();" class="action-button">Çıktı Al</button>
            </div>

        <?php endif; ?>
    </div>
    <footer class="text-center p-4 text-sm text-gray-500 mt-8 no-print">
        &copy; <?php echo date("Y"); ?> Psikometrik.Net Anket Platformu
    </footer>
</body>
</html>
