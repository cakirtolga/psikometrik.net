<?php
session_start();
require_once '../src/config.php'; // Veritabanı bağlantısı için

// Hata raporlamayı etkinleştir (Geliştirme ortamı için uygundur, canlı ortamda kapatılmalıdır)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Giriş kontrolü
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super-admin'])) {
    header('Location: ../login.php');
    exit();
}

$adminId = $_SESSION['user_id']; // Mevcut giriş yapmış admin ID'si

// Psikometrik logo yolu - assets klasöründeki PsikoMetrik.png dosyasına işaret ediyor
$psikometrikWebURL = '../assets/Psikometrik.png';
$institutionWebURL = null; // Kurum logosu için web yolu (DB'den çekilecek)

$surveyId = filter_input(INPUT_GET, 'survey_id', FILTER_VALIDATE_INT);
$selectedParticipantIdsString = filter_input(INPUT_GET, 'participant_ids', FILTER_SANITIZE_STRING);

$reportData = null;
$surveyTitle = 'Rapor'; // Varsayılan başlık
$error = null; // Hata değişkenini tanımla
$allAnswersWithQuestionDetails = []; // Cevapları ve ilgili soru detaylarını tutacak array

if (!$surveyId || empty($selectedParticipantIdsString)) {
    $error = "Rapor görüntülemek için geçerli anket ve katılımcı bilgisi gerekli.";
} else {
    // Katılımcı ID'lerini array'e çevir ve sanitize et
    $selectedParticipantIds = explode(',', $selectedParticipantIdsString);
    $sanitizedParticipantIds = array_filter($selectedParticipantIds, 'filter_var', FILTER_VALIDATE_INT);

    if (empty($sanitizedParticipantIds)) {
        $error = "Geçersiz katılımcı ID'leri.";
    } else {
        $participantPlaceholders = implode(',', array_fill(0, count($sanitizedParticipantIds), '?'));

        // Anket bilgilerini çek
        $surveyStmt = $pdo->prepare("SELECT title FROM surveys WHERE id = ?");
        try {
            $surveyStmt->execute([$surveyId]);
            $survey = $surveyStmt->fetch(PDO::FETCH_ASSOC);

            if ($survey) {
                $surveyTitle = htmlspecialchars($survey['title']) . ' Raporu';
            } else {
                $error = "Belirtilen anket bulunamadı.";
            }
        } catch (PDOException $e) {
             $error = (isset($error) ? $error . " " : "") . "Anket bilgileri çekilirken bir veritabanı hatası oluştu: " . $e->getMessage();
        }

        // Seçilen katılımcılardan birinin admin_id'sini çekmek için survey_participants tablosunu kullan
        $surveyAdminId = null;
        if (!isset($error) && !empty($sanitizedParticipantIds)) {
            $stmt_participant_admin = $pdo->prepare("SELECT admin_id FROM survey_participants WHERE id = ? LIMIT 1");
            try {
                $stmt_participant_admin->execute([$sanitizedParticipantIds[0]]); // İlk katılımcının admin_id'si temel alınır
                $surveyAdminId = $stmt_participant_admin->fetchColumn();
                if (!$surveyAdminId) {
                    error_log("Kurum logosu için survey_participants tablosunda admin_id bulunamadı. Katılımcı ID: " . $sanitizedParticipantIds[0] . ", Anket ID: " . $surveyId);
                }
            } catch (PDOException $e) {
                 error_log("Katılımcı admin_id çekilirken veritabanı hatası (view-report): " . $e->getMessage());
            }
        }

        // Anketin admin_id'si varsa, kurum logosu yolunu users tablosından çek
        if (!isset($error) && !empty($surveyAdminId)) {
            $stmt_institution_logo = $pdo->prepare("SELECT institution_logo_path FROM users WHERE id = ?");
            try {
                $stmt_institution_logo->execute([$surveyAdminId]);
                $institutionLogoPathFromDB = $stmt_institution_logo->fetchColumn();

                if (!empty($institutionLogoPathFromDB)) {
                   $rawInstitutionPathFromDB = $institutionLogoPathFromDB;
                   // Yolu temizle ve kök dizine göre ayarla
                   $cleanRelativePath = ltrim(str_replace('..', '', $rawInstitutionPathFromDB), '/');
                   $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/..'); // Projenin kök dizini
                   $fullServerPath = rtrim($docRoot, '/') . '/' . $cleanRelativePath;

                   if (file_exists($fullServerPath)) {
                       $institutionWebURL = '/' . $cleanRelativePath; // Web'den erişilebilir göreceli yol
                   } else {
                       error_log("Kurum logosu dosyası bulunamadı (view-report). Beklenen yol: " . $fullServerPath . " (DB'den gelen ham yol: " . $rawInstitutionPathFromDB . ")");
                   }
                } else {
                    error_log("Kurum logosu için users tablosunda institution_logo_path boş. Admin ID: " . $surveyAdminId);
                }
            } catch (PDOException $e) {
                 error_log("Kurum logosu çekilirken veritabanı hatası (view-report): " . $e->getMessage());
            }
        }

        if (!isset($error)) {
             $answersStmt = $pdo->prepare("
                 SELECT
                     sa.participant_id,
                     sa.question_id, 
                     sa.answer_text,
                     sq.question_number,
                     sq.question_text,
                     sq.question_type,
                     sq.options
                 FROM
                     survey_answers sa
                 JOIN
                     survey_questions sq ON sa.question_id = sq.question_number AND sa.survey_id = sq.survey_id
                 WHERE
                     sa.survey_id = ? AND sa.participant_id IN ($participantPlaceholders)
                 ORDER BY
                     sq.question_number ASC
             ");

             $params = array_merge([$surveyId], $sanitizedParticipantIds);

             try {
                 $answersStmt->execute($params);
                 $allAnswersWithQuestionDetails = $answersStmt->fetchAll(PDO::FETCH_ASSOC);
             } catch (PDOException $e) {
                 $error = (isset($error) ? $error . " " : "") . "Cevaplar ve soru detayları çekilirken bir veritabanı hatası oluştu: " . $e->getMessage();
                 $allAnswersWithQuestionDetails = [];
             }
        }

        $reportData = [];
        $questionsData = [];

        if (!isset($error) && !empty($allAnswersWithQuestionDetails)) {
             foreach ($allAnswersWithQuestionDetails as $row) {
                 $questionNumber = $row['question_number'];
                 $answerText = $row['answer_text'];

                 if (!isset($questionsData[$questionNumber])) {
                     $questionsData[$questionNumber] = [
                         'question_text' => $row['question_text'],
                         'question_type' => $row['question_type'],
                         'options' => json_decode($row['options'], true),
                     ];
                 }

                 if (!isset($reportData[$questionNumber]['answer_counts'][$answerText])) {
                     $reportData[$questionNumber]['answer_counts'][$answerText] = 0;
                 }
                 $reportData[$questionNumber]['answer_counts'][$answerText]++;
             }
             ksort($reportData);
        } else if (!isset($error)) {
             $error = "Seçilen katılımcılar için bu ankete ait yanıt bulunamadı.";
        }
    }
}

function escapeHTML($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

$psikometrikLogoExists = false;
if ($psikometrikWebURL) {
     $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/..');
     $fullPsikoServerPath = rtrim($docRoot, '/') . '/' . ltrim(str_replace('..', '', $psikometrikWebURL), '/');
     if (file_exists($fullPsikoServerPath)) {
         $psikometrikLogoExists = true;
     } else {
         error_log("Psikometrik logo dosyası bulunamadı (view-report): " . $fullPsikoServerPath);
     }
}

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}

$excelHtmlContent = '<html><head><meta charset="UTF-8"><title>' . escapeHTML($surveyTitle) . '</title><style>table { border-collapse: collapse; width: 100%; margin-bottom: 20px; border: 1px solid #000; } th, td { border: 1px solid #000; padding: 8px; text-align: left; vertical-align: top; } th { background-color: #f2f2f2; font-weight: bold; } h1, h2 { color: #333; } h1 { font-size: 20pt; margin-bottom: 15px; text-align: center;} h2 { font-size: 16pt; margin-top: 20px; margin-bottom: 10px; border-bottom: 1px solid #ccc; padding-bottom: 5px;}</style></head><body><h1>' . escapeHTML($surveyTitle) . '</h1>';

if ($reportData !== null && !empty($reportData)) {
    foreach ($reportData as $questionNumber => $data) {
        $questionDetails = $questionsData[$questionNumber] ?? null;
        if (!$questionDetails) continue;

        $questionText = $questionDetails['question_text'];
        $answerCounts = $data['answer_counts'] ?? [];

        $excelHtmlContent .= '<h2>Soru ' . escapeHTML($questionNumber) . ': ' . escapeHTML($questionText) . '</h2>';

        if (!empty($answerCounts)) {
            $excelHtmlContent .= '<table border="1"><thead><tr><th>Yanıt</th><th>Katılımcı Sayısı</th></tr></thead><tbody>';
            foreach ($answerCounts as $answerText => $count) {
                if ($answerText !== '') {
                    $excelHtmlContent .= '<tr><td>"' . escapeHTML($answerText) . '"</td><td>' . $count . '</td></tr>';
                }
            }
            $excelHtmlContent .= '</tbody></table>';
        } else {
            $excelHtmlContent .= '<p>Bu soru için yanıt verisi bulunamadı.</p>';
        }
        $excelHtmlContent .= '<br>';
    }
} else {
     $excelHtmlContent .= '<p>Seçilen katılımcılar için rapor verisi bulunamadı.</p>';
}

$excelHtmlContent .= '</body></html>';
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escapeHTML($surveyTitle); ?> | Anket Platformu</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Genel Stil */
        body {
            font-family: sans-serif;
            line-height: 1.6;
            background-color: #f8fafc; /* Tailwind slate-50 */
            color: #334155; /* Tailwind slate-700 */
            padding: 1rem;
            font-size: 0.9rem;
        }

        .container {
             width: 100%;
             max-width: 800px; /* Rapor alanı için max genişlik */
             margin-left: auto;
             margin-right: auto;
             padding: 1.5rem;
             background-color: #ffffff;
             border-radius: 0.5rem; /* rounded-lg */
             box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08); /* shadow-md */
             border: 1px solid #e2e8f0; /* Tailwind slate-200 */
        }

        /* Sayfa Başlığı ve Logoları İçeren Üst Kısım */
        .page-header {
            background-color: #ffffff;
            padding: 10px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e0e0e0; /* Hafif gri alt çizgi */
            box-shadow: 0 1px 3px rgba(0,0,0,0.05); /* Hafif gölge */
            margin-bottom: 1.5rem; /* Konteyner ile arasına boşluk */
        }
         .page-header .logo-left,
         .page-header .logo-right {
             flex: 1;
             display: flex;
             align-items: center;
         }
         .page-header .logo-left {
             justify-content: flex-start;
         }
         .page-header .logo-right {
             justify-content: flex-end;
         }
         .page-header .logo-left img,
         .page-header .logo-right img {
            max-height: 50px; /* Logo boyutu */
            width: auto;
         }
         .page-header .page-title {
             flex: 2; /* Başlık için daha fazla alan */
             text-align: center;
             font-size: 1.8rem; /* Başlık boyutu */
             color: #1f2937; /* Tailwind slate-800 */
             margin: 0;
             font-weight: 600; /* font-semibold */
         }

        .report-main-title-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid #e2e8f0; /* Tailwind slate-200 */
            padding-bottom: 1rem;
        }

        .report-main-title-container h1 {
            font-size: 1.5rem; /* text-2xl */
            font-weight: bold; /* font-bold */
            margin: 0;
            color: #1e293b; /* Tailwind slate-800 */
            text-align: left;
            flex-grow: 1;
        }

        .output-buttons {
            display: flex;
            gap: 0.5rem; /* Butonlar arasına boşluk */
            align-items: center;
        }

        .output-button {
            background-color: #22c55e; /* Tailwind green-500 */
            color: white;
            padding: 0.5rem 1rem; /* py-2 px-4 */
            border-radius: 0.25rem; /* rounded */
            text-decoration: none;
            font-size: 0.9rem; /* text-sm */
            transition: background-color 0.2s ease-in-out;
            border: none;
            cursor: pointer;
            font-weight: 500; /* font-medium */
        }

        .output-button:hover {
            background-color: #16a34a; /* Tailwind green-600 */
        }

        .print-button {
             background-color: #0ea5e9; /* Tailwind sky-500 */
        }
         .print-button:hover {
             background-color: #0284c7; /* Tailwind sky-600 */
         }

        .question-section {
            background-color: #f1f5f9; /* Tailwind slate-100 */
            padding: 1.25rem; /* p-5 */
            border-radius: 0.375rem; /* rounded-md */
            margin-bottom: 1.5rem; /* mb-6 */
            border: 1px solid #e2e8f0; /* Tailwind slate-200 */
        }

        .question-section h2 {
             font-size: 1.125rem; /* text-lg */
             font-weight: 600; /* font-semibold */
             margin-top: 0;
             margin-bottom: 1rem; /* mb-4 */
             color: #1e293b; /* Tailwind slate-800 */
             border-bottom: 1px solid #cbd5e1; /* Tailwind slate-300 */
             padding-bottom: 0.75rem; /* pb-3 */
        }

        .answer-counts-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem; /* mt-4 */
            border: 1px solid #cbd5e1; /* Tailwind slate-300 */
        }

        .answer-counts-table th,
        .answer-counts-table td {
            text-align: left;
            padding: 0.4rem 0.6rem; /* Daha sıkı padding */
            border: 1px solid #e2e8f0; /* Tailwind slate-200 */
            font-size: 0.85rem; /* Daha küçük tablo metni */
        }

        .answer-counts-table th {
            background-color: #e2e8f0; /* Tailwind slate-200 */
            font-weight: 600; /* font-semibold */
            color: #475569; /* Tailwind slate-600 */
        }

        .answer-counts-table tbody tr:nth-child(even) {
            background-color: #f8fafc; /* Tailwind slate-50 - Zebra deseni */
        }

        .answer-counts-table tbody tr:hover {
            background-color: #e2e8f0; /* Tailwind slate-200 - Hover efekti */
        }

        .answer-counts-table td:last-child { /* Katılımcı sayısı sütunu */
            text-align: right;
            font-weight: 600; /* font-semibold */
            color: #0e7490; /* Tailwind cyan-700 */
        }

        .error-message {
            background-color: #fee2e2; /* Tailwind red-100 */
            color: #dc2626; /* Tailwind red-600 */
            padding: 1rem; /* p-4 */
            border-radius: 0.5rem; /* rounded-lg */
            margin-bottom: 1.5rem; /* mb-6 */
            border: 1px solid #fca5a5; /* Tailwind red-300 */
        }

        .back-link {
            display: inline-block;
            margin-top: 2rem; /* mt-8 */
            color: #0ea5e9; /* Tailwind sky-500 */
            text-decoration: underline;
        }
        .back-link:hover {
            color: #0284c7; /* Tailwind sky-600 */
        }

        /* Yazdırma için stiller */
        @media print {
            body {
                padding: 0;
                margin: 20mm; /* Sayfa kenar boşlukları */
                font-size: 10pt;
                background-color: #fff !important; /* Arka planı beyaz yap ve önemli kıl */
                color: #000 !important; /* Metin rengini siyah yap */
                -webkit-print-color-adjust: exact; /* Chrome/Safari için renkleri zorla */
                print-color-adjust: exact; /* Standart */
            }
            .container {
                 box-shadow: none !important;
                 border: none !important;
                 margin: 0 !important;
                 padding: 0 !important;
                 max-width: 100% !important;
                 background-color: #fff !important;
            }
            .output-buttons,
            .back-link {
                display: none !important;
            }
             .page-header {
                 display: flex !important;
                 padding: 10px 0 !important;
                 border-bottom: 1px solid #000 !important;
                 box-shadow: none !important;
                 justify-content: space-between !important;
                 align-items: center !important;
                 margin-bottom: 1.5rem !important;
                 background-color: #fff !important;
             }
             .page-header .logo-left img,
             .page-header .logo-right img {
                max-height: 40px !important;
                width: auto !important;
             }
             .page-header .logo-left { justify-content: flex-start !important; }
             .page-header .logo-right { justify-content: flex-end !important; }
             .page-header .page-title {
                 flex: 2 !important;
                 text-align: center !important;
                 font-size: 14pt !important;
                 color: #000 !important;
                 margin: 0 !important;
                 font-weight: bold !important;
             }
             .report-main-title-container {
                 border-bottom: 1px solid #000 !important;
                 padding-bottom: 0.5rem !important;
                 margin-bottom: 1rem !important;
                 background-color: #fff !important;
             }
             .report-main-title-container h1 {
                 font-size: 12pt !important;
                 color: #000 !important;
             }
             .question-section {
                 background-color: #fff !important;
                 border: 1px solid #ccc !important;
                 padding: 1rem !important;
                 margin-bottom: 1rem !important;
                 page-break-inside: avoid !important; /* Soru bölümünün sayfa ortasında bölünmesini engelle */
             }
             .question-section h2 {
                 font-size: 11pt !important;
                 border-bottom: 1px solid #ccc !important;
                 padding-bottom: 0.5rem !important;
                 margin-bottom: 0.5rem !important;
                 color: #000 !important;
             }
             .answer-counts-table {
                 border: 1px solid #000 !important;
                 font-size: 9pt !important;
                 width: 100% !important;
                 margin-top: 0.5rem !important;
             }
             .answer-counts-table th,
             .answer-counts-table td {
                 border: 1px solid #000 !important;
                 padding: 4px 6px !important;
                 color: #000 !important;
             }
             .answer-counts-table th {
                 background-color: #eee !important;
             }
             .answer-counts-table tbody tr:nth-child(even) {
                 background-color: #fff !important; /* Yazdırmada zebra deseni olmasın */
             }
             .error-message {
                border: 1px solid #000 !important;
                color: #000 !important;
                background-color: #fff !important;
             }
        }
    </style>
</head>
<body>

    <div class="page-header">
        <div class="logo-left">
            <?php if(!empty($institutionWebURL)): ?>
                <img src="<?= htmlspecialchars($institutionWebURL) ?>" alt="Kurum Logosu">
            <?php else: ?><span>&nbsp;</span><?php endif; ?>
        </div>
        <div class="page-title">
            <?= htmlspecialchars($surveyTitle) ?>
        </div>
        <div class="logo-right">
            <?php if ($psikometrikLogoExists): ?>
                <img src="<?= htmlspecialchars($psikometrikWebURL) ?>" alt="Psikometrik.Net Logosu">
            <?php else: ?>
                <span>Psikometrik.Net</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="container">
        <div class="report-main-title-container">
             <h1><?php echo htmlspecialchars($surveyTitle); ?></h1>
             <div class="output-buttons no-print">
                 <?php if ($reportData !== null && !empty($reportData)): ?>
                    <button id="print-report" class="output-button print-button">Yazdır</button>
                    <button id="export-excel" class="output-button">Excel İndir</button>
                 <?php endif; ?>
             </div>
        </div>

        <?php if (isset($error)): ?>
            <div class="error-message"><?= htmlspecialchars($error) ?></div>
        <?php elseif ($reportData !== null && !empty($reportData)): ?>
            <?php foreach ($reportData as $questionNumber => $data): ?>
                <?php
                $questionDetails = $questionsData[$questionNumber] ?? null;
                if (!$questionDetails) continue;

                $questionText = $questionDetails['question_text'];
                $answerCounts = $data['answer_counts'] ?? [];
                ?>

                <div class="question-section">
                    <h2>Soru <?php echo escapeHTML($questionNumber); ?>: <?php echo escapeHTML($questionText); ?></h2>

                    <?php if (!empty($answerCounts)): ?>
                         <div class="answer-counts">
                             <table class="answer-counts-table">
                                 <thead>
                                     <tr>
                                         <th>Yanıt</th>
                                         <th>Katılımcı Sayısı</th>
                                     </tr>
                                 </thead>
                                 <tbody>
                                <?php foreach ($answerCounts as $answerText => $count):
                                     if ($answerText !== ''): // Boş yanıtları gösterme
                                     ?>
                                         <tr>
                                             <td>"<?php echo escapeHTML($answerText); ?>"</td>
                                             <td><?php echo $count; ?></td>
                                         </tr>
                                     <?php endif; ?>
                                <?php endforeach; ?>
                                 </tbody>
                             </table>
                         </div>
                    <?php else: ?>
                         <p>Bu soru için yanıt verisi bulunamadı.</p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
             <p>Seçilen katılımcılar için rapor verisi bulunamadı.</p>
        <?php endif; ?>

        <a href="dashboard.php" class="back-link no-print">Dashboard'a Geri Dön</a>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const exportExcelButton = document.getElementById('export-excel');
            const printButton = document.getElementById('print-report');

            const excelHtmlContent = <?php echo json_encode($excelHtmlContent); ?>;
            const surveyId = "<?php echo $surveyId; ?>";


            if (exportExcelButton) {
                exportExcelButton.addEventListener('click', function() {
                    exportReportToXls(excelHtmlContent, surveyId);
                });
            }

             if (printButton) {
                 printButton.addEventListener('click', function() {
                     window.print();
                 });
             }

            function exportReportToXls(htmlContent, id) {
                const blob = new Blob([htmlContent], { type: 'application/vnd.ms-excel;charset=utf-8' });
                const url = URL.createObjectURL(blob);

                const link = document.createElement("a");
                link.setAttribute("href", url);
                link.setAttribute("download", "anket_raporu_" + id + ".xls");

                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);

                URL.revokeObjectURL(url);
            }
        });
    </script>

</body>
</html>
