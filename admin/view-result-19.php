<?php
// view-result-19.php (UCLA Yalnızlık Ölçeği Sonuçları v1 - DB'den Sort Order Okuma)

session_start(); // Session GEREKLİ
ini_set('display_errors', 1); error_reporting(E_ALL);

// --- Veritabanı Bağlantısı ---
require '../src/config.php';
if (!isset($pdo) || !$pdo instanceof PDO) {
    error_log("CRITICAL: PDO connection object (\$pdo) not created or found in src/config.php");
    header('Content-Type: text/html; charset=utf-8');
    die('<div style="border: 1px solid red; padding: 10px; margin: 10px; background-color: #ffebeb; color: red; font-family: sans-serif;"><b>Kritik Hata:</b> Veritabanı bağlantısı kurulamadı (src/config.php).</div>');
}
// --------------------------------

// --- Ayarlar ---
$surveyId = 19;
$testTitleDefault = "UCLA Yalnızlık Ölçeği";
$pageTitle = $testTitleDefault . " Sonuçları";

// --- Değişkenler ---
$participantId = null; $participantData = null; $survey_title = $pageTitle;
$totalScore = null; // Hesaplanan Toplam Yalnızlık Puanı
$interpretation = "Hesaplanamadı";
$error = null; $dataSource = null;
$processedAnswersForTable = []; // Detaylı cevap tablosu için

// --- Logo URL ---
$institutionWebURL = null; $psikometrikWebURL = '/assets/Psikometrik.png'; // Logo yolunu kontrol edin

// --- Sabit Veriler (Anket 19 - UCLA-LS) ---
// Seçenekler ve Puanları (1-4 Likert)
$optionsMap = [
    1 => "HİÇ Yaşamadım",
    2 => "NADİREN Yaşarım",
    3 => "BAZAN Yaşarım",
    4 => "SIK SIK Yaşarım"
];
// Metinden puanı bulmak için ters harita
$textToScoreMap = array_flip($optionsMap);

// Ters Puanlanan Maddelerin Sıra Numaraları (sort_order)
$reverseScoredItems = [1, 4, 5, 6, 8, 10, 15, 16, 20];

// Yalnızlık Skoru Yorumlama Fonksiyonu (20-80 arası)
function interpretLonelinessScore($totalScore) {
     if ($totalScore === null || !is_numeric($totalScore)) return "Hesaplanamadı";
     if ($totalScore >= 20 && $totalScore <= 40) return "Yalnızlık düzeyiniz düşük görünüyor.";
     elseif ($totalScore > 40 && $totalScore <= 60) return "Yalnızlık düzeyiniz orta düzeyde görünüyor.";
     elseif ($totalScore > 60 && $totalScore <= 80) return "Yalnızlık düzeyiniz yüksek görünüyor. Bu konuda destek almanız faydalı olabilir.";
     else return "Geçersiz Puan Aralığı ({$totalScore}).";
}
// -------------------------------------------


// --- VERİ KAYNAĞINI BELİRLE VE VERİYİ AL ---
if (isset($_GET['id'])) {
    // --- SENARYO 1: ID VAR -> VERITABANINDAN ÇEK ---
    $dataSource = 'db';
    $participantId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$participantId) { $error = "Geçersiz katılımcı ID'si."; }
    else {
        try {
            // 1. Katılımcı ve Anket Bilgileri
            $stmt_participant = $pdo->prepare(" SELECT sp.*, s.title as survey_title, u.institution_logo_path FROM survey_participants sp LEFT JOIN surveys s ON sp.survey_id = s.id LEFT JOIN users u ON sp.admin_id = u.id WHERE sp.id = ? AND sp.survey_id = ? ");
            $stmt_participant->execute([$participantId, $surveyId]);
            $participantData = $stmt_participant->fetch(PDO::FETCH_ASSOC);

            if (!$participantData) {
                 // Katılımcı bulunamazsa hata set et ve logla
                 $error = "Belirtilen ID ({$participantId}) için Anket {$surveyId} sonucu bulunamadı.";
                 error_log("Participant not found for view-result-19 (ID: {$participantId}, Survey: {$surveyId})");
            } else {
                $survey_title = !empty($participantData['survey_title']) ? htmlspecialchars($participantData['survey_title']) . " Sonuçları" : $testTitleDefault . " Sonuçları";
                // Logo URL Ayarla...
                if (!empty($participantData['admin_id']) && !empty($participantData['institution_logo_path'])) {
                   $rawInstitutionPathFromDB = $participantData['institution_logo_path'];
                   $cleanRelativePath = ltrim(str_replace('..', '', $rawInstitutionPathFromDB), '/');
                   $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/..');
                   $fullServerPath = rtrim($docRoot, '/') . '/' . $cleanRelativePath;
                   if (file_exists($fullServerPath)) { $institutionWebURL = '/' . $cleanRelativePath; }
                   else { error_log("Kurum logosu dosyası bulunamadı (view-result-19): " . $fullServerPath); }
                }

                // 2. Cevapları Çek (question_id artık sort_order, answer_text puan metni)
                // question_id sütununu sort_order olarak, answer_text sütununu cevap metni olarak alıyoruz
                $stmt_answers = $pdo->prepare("SELECT question_id AS sort_order, answer_text FROM survey_answers WHERE participant_id = ? AND survey_id = ? ORDER BY sort_order ASC");
                $stmt_answers->execute([$participantId, $surveyId]);
                $fetched_answers = $stmt_answers->fetchAll(PDO::FETCH_ASSOC);

                // Toplam beklenen soru sayısı (20)
                $stmt_total_questions = $pdo->prepare("SELECT COUNT(*) FROM survey_questions WHERE survey_id = ?");
                $stmt_total_questions->execute([$surveyId]);
                $totalExpectedQuestions = (int)$stmt_total_questions->fetchColumn();


                if (empty($fetched_answers) || count($fetched_answers) < $totalExpectedQuestions) {
                     // Cevap bulunamazsa veya eksikse hata set et ve logla
                     $error = "Katılımcı cevapları veritabanında bulunamadı veya eksik (ID: {$participantId}, Anket: {$surveyId}).";
                     error_log("Answers not found or incomplete for participant {$participantId} in survey {$surveyId}. Expected {$totalExpectedQuestions}, found " . count($fetched_answers));
                     // Hata durumunda skorları ve yorumları boşalt
                     $totalScore = null;
                     $interpretation = "Hesaplanamadı";
                } else {
                    // 3. Toplam Skoru Hesapla
                    $totalScore = 0;
                    $processedAnswerCount = 0; // İşlenen geçerli cevap sayısı

                    foreach($fetched_answers as $ans) {
                        $sortOrder = (int)$ans['sort_order']; // question_id sütunundaki değer (sort_order)
                        $answerText = trim($ans['answer_text'] ?? '');

                        // Cevap metninden sayısal puanı bul (1-4)
                        $originalScore = $textToScoreMap[$answerText] ?? null;

                        // Geçerli bir sort_order ve orijinal puan varsa işleme devam et
                        if ($originalScore !== null && ($sortOrder > 0 && $sortOrder <= $totalExpectedQuestions)) {

                            // Skoru hesapla (ters puanlama dikkate alınarak)
                            if (in_array($sortOrder, $reverseScoredItems)) {
                                // Ters puanlama: 1->4, 2->3, 3->2, 4->1 (5 - orijinal puan)
                                $calculatedScore = 5 - $originalScore;
                            } else {
                                // Normal puanlama
                                $calculatedScore = $originalScore;
                            }
                            $totalScore += $calculatedScore;
                            $processedAnswerCount++;

                            // Detaylı tablo için veriyi hazırla
                            $processedAnswersForTable[] = [
                                'madde' => $sortOrder,
                                'question_text' => 'Soru metni DB\'den çekilmedi', // Soru metni burada yok
                                'original_answer' => $answerText,
                                'original_score' => $originalScore,
                                'calculated_score' => $calculatedScore
                            ];

                        } else {
                             // Beklenmeyen sort_order veya geçersiz cevap metni/puanı gelirse logla
                             error_log("Invalid sort_order ({$sortOrder}) or answer_text ('{$answerText}') found in survey_answers for participant {$participantId}, survey {$surveyId}. Parsed original score: " . ($originalScore ?? 'null'));
                             // Bu durumda bu cevabı skorlamaya dahil etme
                        }
                    }

                    // Tüm beklenen cevaplar işlendi mi kontrol et
                    if ($processedAnswerCount < $totalExpectedQuestions) {
                         error_log("view-result-19 DB: Processed fewer valid answers ({$processedAnswerCount}) than expected ({$totalExpectedQuestions}) for participant {$participantId}.");
                         // Bu durumda skoru geçersiz kılabiliriz
                         $totalScore = null;
                         $interpretation = "Hesaplanamadı (Eksik veya hatalı cevaplar işlendi)";
                    } else {
                         // 4. Yorumu Hesapla (Toplam skor geçerliyse)
                         $interpretation = interpretLonelinessScore($totalScore);
                    }

                } // End if (empty($fetched_answers)) else
            } // End if (!$participantData) else

        } catch (Exception $e) {
             // Veritabanı veya diğer hatalar için genel hata yönetimi
             $error = "Sonuçlar yüklenirken bir hata oluştu: " . $e->getMessage();
             error_log("DB Error view-result-19 (ID: {$participantId}): ".$e->getMessage());
             $participantData = null; // Hata durumunda katılımcı verisini temizle
             $totalScore = null; // Hata durumunda skoru temizle
             $interpretation = "Hesaplanamadı"; // Hata durumunda yorumu temizle
             $processedAnswersForTable = []; // Hata durumunda tablo verisini temizle
        }
    }

} else {
    // --- SENARYO 2: ID YOK -> SESSION'DAN ÇEK ---
    $dataSource = 'session';
    // take-survey-19.php Session'a 'total_score', 'interpretation', 'answers' ([sort_order => original_score]) kaydediyor.
    if (isset($_SESSION['survey_result_data'], $_SESSION['survey_result_data']['survey_id']) && $_SESSION['survey_result_data']['survey_id'] == $surveyId) {
        $sessionData = $_SESSION['survey_result_data'];
        // Gerekli anahtarların varlığını kontrol et
        if (isset($sessionData['total_score'], $sessionData['interpretation'], $sessionData['participant_name'], $sessionData['answers']) && is_array($sessionData['answers'])) {
            $totalScore = $sessionData['total_score'];
            $interpretation = $sessionData['interpretation'];
            $participantData = [
                 'name' => $sessionData['participant_name'],
                 'class' => $sessionData['participant_class'] ?? null,
                 'created_at' => date('Y-m-d H:i:s', $sessionData['timestamp'] ?? time()),
                 'admin_id' => null // Session'dan gelen sonuçta admin_id olmaz
            ];
            $survey_title = $testTitleDefault . " Sonucunuz"; $error = null;

            // Detaylı tablo için veriyi hazırla (Session'daki answers [sort_order => original_score] formatında)
            $sessionAnswers = $sessionData['answers'];
            $totalExpectedQuestions = count($reverseScoredItems) + (20 - count($reverseScoredItems)); // Toplam 20 soru
            $processedAnswerCount = 0;

            // Soruları DB'den çekerek metinlerini alalım (sort_order'a göre)
            $questionSortOrderToTextMap = [];
             try {
                 $stmtQText = $pdo->prepare("SELECT sort_order, question AS question_text FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC");
                 $stmtQText->execute([$surveyId]);
                 $questionSortOrderToTextMap = $stmtQText->fetchAll(PDO::FETCH_KEY_PAIR); // [sort_order => question_text]
             } catch(Exception $e) { error_log("Session result question text fetch error: " . $e->getMessage()); /* Hata set edilebilir */ }


            foreach ($sessionAnswers as $sortOrder => $originalScore) {
                 $sortOrder_int = (int)$sortOrder;
                 $originalScore_int = (int)$originalScore;

                 // Geçerli sort_order ve orijinal puan (1-4) kontrolü
                 if (($sortOrder_int > 0 && $sortOrder_int <= $totalExpectedQuestions) && ($originalScore_int >= 1 && $originalScore_int <= 4)) {

                     // Hesaplanan skoru bul
                     if (in_array($sortOrder_int, $reverseScoredItems)) {
                         $calculatedScore = 5 - $originalScore_int;
                     } else {
                         $calculatedScore = $originalScore_int;
                     }

                     // Orijinal cevap metnini bul
                     $originalAnswerText = $optionsMap[$originalScore_int] ?? 'Geçersiz Puan';
                     // Soru metnini bul
                     $questionText = $questionSortOrderToTextMap[$sortOrder_int] ?? 'Soru metni yüklenemedi';


                     $processedAnswersForTable[] = [
                         'madde' => $sortOrder_int,
                         'question_text' => $questionText,
                         'original_answer' => $originalAnswerText,
                         'original_score' => $originalScore_int,
                         'calculated_score' => $calculatedScore
                     ];
                     $processedAnswerCount++;

                 } else {
                      // Beklenmeyen sort_order veya geçersiz orijinal puan gelirse logla
                      error_log("Invalid sort_order ({$sortOrder}) or original_score ({$originalScore}) in session data for survey {$surveyId}");
                 }
            }

            // Session'daki cevap sayısı beklenenle uyuşuyor mu kontrol et (opsiyonel)
             if ($processedAnswerCount < $totalExpectedQuestions) {
                 error_log("view-result-19 Session: Processed fewer valid answers ({$processedAnswerCount}) than expected ({$totalExpectedQuestions}) from session data.");
                 // Bu durumda skorun doğruluğu sorgulanabilir, ama Session'daki total_score'u kullanıyoruz.
                 // Yine de bir uyarı gösterilebilir veya tablo boşaltılabilir.
                 // $processedAnswersForTable = []; // Tabloyu boşalt
             }


            unset($_SESSION['survey_result_data']); // Başarılı okuma sonrası temizle
        } else {
            // Session verisi eksikse hata set et ve logla
            error_log("Incomplete session data for survey 19: " . print_r($sessionData, true));
            $error = "Oturumdan alınan sonuç verisi eksik veya geçersiz (Kod: SESSION_INCOMPLETE).";
            $participantData = null; // Eksikse katılımcı verisi de yok
            $totalScore = null; // Eksikse skor yok
            $interpretation = "Hesaplanamadı"; // Eksikse yorum yok
            $processedAnswersForTable = []; // Eksikse tablo verisi yok
            unset($_SESSION['survey_result_data']); // Eksikse de temizle
        }
    } else {
        // Session verisi tamamen yoksa hata set et
        $error = "Görüntülenecek sonuç bulunamadı veya oturum süresi dolmuş olabilir (Kod: SESSION_MISSING).";
        $participantData = null;
        $totalScore = null;
        $interpretation = "Hesaplanamadı";
        $processedAnswersForTable = [];
    }
}
// --- VERİ KAYNAĞI SONU ---

// Psikometrik logo kontrolü...
if ($psikometrikWebURL) {
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/..');
    $fullPsikoServerPath = rtrim($docRoot, '/') . '/' . ltrim(str_replace('..', '', $psikometrikWebURL), '/');
    if (!file_exists($fullPsikoServerPath)) { $psikometrikWebURL = null; error_log("Psikometrik logo dosyası bulunamadı (view-result-19): " . $fullPsikoServerPath); }
}

// Header gönder...
if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> <?= ($participantData && isset($participantData['name'])) ? '- ' . htmlspecialchars($participantData['name']) : '' ?></title>
    <style>
        /* --- Stil Bloğu (Yeşil Tema - view-result-16/18 ile Uyumlu) --- */
        body { font-family: sans-serif; line-height: 1.6; background-color: #f0fdf4; color: #2c3e50; margin: 0; padding: 0; }
        .page-header { background-color: #ffffff; padding: 10px 25px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e0e0e0; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .page-header img { max-height: 50px; width: auto; }
        .container { max-width: 900px; margin: 20px auto; background: white; padding: 20px 30px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        h1 { text-align: center; color: #1f2937; margin-top: 0; margin-bottom: 1.5rem; font-size: 1.75rem; border-bottom: 2px solid #dcfce7; padding-bottom: 0.75rem; }
        h2 { font-size: 1.4rem; color: #15803d; /* Yeşil */ margin-top: 2rem; margin-bottom: 1rem; border-bottom: 1px solid #eee; padding-bottom: 0.4rem; }

        .participant-info, .result-summary { margin-bottom: 1.5rem; padding: 15px; background-color: #f9fafb; border: 1px solid #f3f4f6; border-radius: 8px; }
        .participant-info p { margin: 0.4rem 0; font-size: 1rem; }
        .participant-info strong { font-weight: 600; color: #374151; min-width: 120px; display: inline-block; }
        /* Sonuç Özeti Kutusu - Vurgulu Yeşil */
        .result-summary { text-align: center; background-color: #e8f5e9; border-color: #c8e6c9; padding: 25px; }
        .result-summary h2 { margin-top: 0; }
        .final-score { font-size: 2.4em; font-weight: bold; color: #15803d; display: block; margin: 10px 0; }
        .score-interpretation { font-size: 1.15em; color: #374151; line-height: 1.7; }

        /* Detaylı Cevap Tablosu Stilleri */
        .answers-table { width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: 0.9rem; }
        .answers-table th, .answers-table td { border: 1px solid #ddd; padding: 8px 10px; text-align: left; vertical-align: top; }
        .answers-table th { background-color: #dcfce7; font-weight: 600; color: #1f2937; }
        .answers-table td:nth-child(1) { width: 8%; text-align: center; font-weight: bold; vertical-align: middle;} /* Madde No */
        .answers-table td:nth-child(2) { width: 40%; line-height: 1.4; } /* Soru Metni */
        .answers-table td:nth-child(3) { width: 22%; vertical-align: middle;} /* Verilen Cevap */
        .answers-table td:nth-child(4) { width: 15%; text-align: center; vertical-align: middle;} /* Orijinal Puan */
        .answers-table td:nth-child(5) { width: 15%; text-align: center; font-weight: bold; vertical-align: middle;} /* Hesaplanan Puan */
        .answers-table tr:nth-child(even) { background-color: #f8f9fa; }


        .error-box { color: #b91c1c; background-color: #fee2e2; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; border: 1px solid #fca5a5; font-weight: 500; text-align: left; font-size: 0.9em;}
        .error-box b { font-weight: bold; }

        .action-buttons { display: flex; justify-content: center; gap: 1rem; margin-top: 2.5rem; padding-top: 1.5rem; border-top: 1px solid #e0e0e0; }
        .action-button { display: inline-flex; align-items: center; padding: 10px 20px; font-size: 1rem; font-weight: 600; color: white; background-color: #15803d; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; transition: background-color 0.2s ease, box-shadow 0.2s ease; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .action-button:hover { background-color: #0b532c; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .no-print { }
        @media print { /* ... */ }
    </style>
</head>
<body>

<div class="page-header">
    <div>
        <?php if($dataSource == 'db' && !empty($institutionWebURL)): ?>
            <img src="<?= htmlspecialchars($institutionWebURL) ?>" alt="Kurum Logosu">
        <?php else: ?><span>&nbsp;</span><?php endif; ?>
    </div>
    <div>
        <?php if (!empty($psikometrikWebURL)): ?>
            <img src="<?= htmlspecialchars($psikometrikWebURL) ?>" alt="Psikometrik.Net Logosu">
        <?php else: ?><span>Psikometrik.Net</span><?php endif; ?>
    </div>
</div>

<div class="container">

    <?php // Hata veya Veri Yoksa Gösterim ?>
    <?php if ($error): ?>
         <h1>Sonuçlar Yüklenemedi</h1>
         <div class="error-box"><b>Hata:</b> <?= htmlspecialchars($error) ?></div>
    <?php elseif (!$participantData): ?>
         <h1>Sonuçlar Yüklenemedi</h1>
         <div class="error-box">Görüntülenecek katılımcı verisi bulunamadı.</div>
    <?php elseif ($totalScore === null): // Katılımcı var ama skor hesaplanamadıysa ?>
        <h1><?= htmlspecialchars($survey_title) ?></h1>
        <div class="participant-info">
             <h2>Katılımcı Bilgileri</h2>
             <p><strong>Ad Soyad:</strong> <?= htmlspecialchars($participantData['name']) ?></p>
             <p><strong>Sınıf/Bölüm:</strong> <?= htmlspecialchars($participantData['class'] ?? 'Belirtilmemiş') ?></p>
             <p><strong>Test Tarihi:</strong> <?= date('d.m.Y H:i', strtotime($participantData['created_at'])) ?></p>
        </div>
        <div class="error-box">Sonuç hesaplanırken bir sorun oluştu veya tüm cevaplar bulunamadı.</div>
        <div class="action-buttons no-print">
            <?php if ($dataSource == 'db'): ?>
                 <a href="dashboard.php" class="action-button panel-button">Panele Dön</a>
            <?php else: ?>
                 <a href="index.php" class="action-button panel-button">Diğer Anketler</a>
            <?php endif; ?>
             <button onclick="window.print();" class="action-button print-button">Sayfayı Yazdır</button>
         </div>
    <?php else: // Veri var, sonuçları göster ?>

        <h1><?= htmlspecialchars($survey_title) ?></h1>

        <div class="participant-info">
             <h2>Katılımcı Bilgileri</h2>
             <p><strong>Ad Soyad:</strong> <?= htmlspecialchars($participantData['name']) ?></p>
             <p><strong>Sınıf/Bölüm:</strong> <?= htmlspecialchars($participantData['class'] ?? 'Belirtilmemiş') ?></p>
             <p><strong>Test Tarihi:</strong> <?= date('d.m.Y H:i', strtotime($participantData['created_at'])) ?></p>
        </div>

        <div class="result-summary">
             <h2>Yalnızlık Puanınız</h2>
             <p><strong>Toplam Puan:</strong> <span class="final-score"><?= htmlspecialchars($totalScore) ?></span></p>
             <p class="score-interpretation"><strong>Yorum:</strong><br><?= htmlspecialchars($interpretation) ?></p>
             <p style="font-size: 0.85em; margin-top: 15px; color: #475569;">(Min Puan: 20, Max Puan: 80)</p>
        </div>

        <h2>Detaylı Cevaplar ve Puanlar</h2>
         <?php if (!empty($processedAnswersForTable)): ?>
             <table class="answers-table">
                 <thead>
                     <tr>
                         <th>Madde No</th>
                         <th>Soru</th>
                         <th>Verilen Cevap</th>
                         <th>Orijinal Puan (1-4)</th>
                         <th>Hesaplanan Puan</th>
                     </tr>
                 </thead>
                 <tbody>
                     <?php foreach ($processedAnswersForTable as $item): ?>
                     <tr>
                         <td><?= htmlspecialchars($item['madde']) ?></td>
                         <td><?= htmlspecialchars($item['question_text']) ?></td>
                         <td><?= htmlspecialchars($item['original_answer']) ?></td>
                         <td><?= htmlspecialchars($item['original_score']) ?></td>
                         <td><?= htmlspecialchars($item['calculated_score']) ?></td>
                     </tr>
                     <?php endforeach; ?>
                 </tbody>
             </table>
             <p style="font-size: 0.85em; margin-top: 15px; text-align: center; color: #475569;">* Ters puanlanan maddelerde hesaplanan puan farklılık gösterebilir.</p>
         <?php else: ?>
             <div class="error-box">Detaylı cevaplar görüntülenemiyor.</div>
         <?php endif; ?>


         <div class="action-buttons no-print">
            <?php if ($dataSource == 'db'): ?>
                 <a href="dashboard.php" class="action-button panel-button">Panele Dön</a>
            <?php else: ?>
                 <a href="../index.php" class="action-button panel-button">Diğer Anketler</a> <?php // Ana sayfaya yönlendirme ?>
            <?php endif; ?>
             <button onclick="window.print();" class="action-button print-button">Sayfayı Yazdır</button>
         </div>

    <?php endif; ?>

</div> <?php // container sonu ?>

<script> /* Özel JS gerekmiyor */ </script>

</body>
</html>
