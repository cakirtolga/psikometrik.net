<?php
// view-result-21.php (Rathus Atılganlık Envanteri Sonuçları v2 - Güncel Soru Listesi ve Puanlama)

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
$surveyId = 21; // Anket ID'si
$testTitleDefault = "Rathus Atılganlık Envanteri";
$pageTitle = $testTitleDefault . " Sonuçları";

// --- Değişkenler ---
$participantId = null; $participantData = null; $survey_title = $pageTitle;
$totalScore = null; // Hesaplanan Toplam Atılganlık Puanı
$interpretation = "Hesaplanamadı";
$error = null; $dataSource = null;
$processedAnswersForTable = []; // Detaylı cevap tablosu için

// --- Logo URL ---
$institutionWebURL = null; $psikometrikWebURL = '/assets/Psikometrik.png'; // Logo yolunu kontrol edin

// --- Sabit Veriler (Anket 21 - Rathus Atılganlık Envanteri) ---
// Seçenekler ve Metinleri (6-noktalı Likert)
$optionsMap = [
    6 => "Çok iyi uyuyor",
    5 => "Oldukça uyuyor",
    4 => "Biraz uyuyor",
    3 => "Pek uymuyor",
    2 => "Fazla uymuyor",
    1 => "Hiç uymuyor"
];
// Metinden orijinal puanı bulmak için ters harita (take-survey'den gelen değeri işlemek için)
$textToOriginalScoreMap = array_flip($optionsMap);


// Olumlu ve Olumsuz Maddelerin Sıra Numaraları (sort_order)
// Bu listeler standart Rathus puanlama anahtarına göredir (1-30).
$positiveItems = [3, 6, 7, 8, 10, 18, 20, 21, 22, 25, 27, 28, 29]; // 13 madde
$negativeItems = [1, 2, 4, 5, 9, 11, 12, 13, 14, 15, 16, 17, 19, 23, 24, 26, 30]; // 17 madde

// Skorlama Haritaları (Cevap Metnine Göre Hesaplanan Puan)
// Olumlu maddeler için
$positiveScoring = [
    "Çok iyi uyuyor" => 6,
    "Oldukça uyuyor" => 5,
    "Biraz uyuyor" => 4,
    "Pek uymuyor" => 3,
    "Fazla uymuyor" => 2,
    "Hiç uymuyor" => 1
];
// Olumsuz maddeler için (ters puanlama)
$negativeScoring = [
    "Çok iyi uyuyor" => 1,
    "Oldukça uyuyor" => 2,
    "Biraz uyuyor" => 3,
    "Pek uymuyor" => 4,
    "Fazla uymuyor" => 5,
    "Hiç uymuyor" => 6
];


// Atılganlık Skoru Yorumlama Fonksiyonu (30-180 arası)
function interpretAssertivenessScore($totalScore) {
     if ($totalScore === null || !is_numeric($totalScore)) return "Hesaplanamadı";
     if ($totalScore >= 30 && $totalScore <= 80) return "Çekingenlik düzeyiniz yüksek görünüyor. Kişilerarası ilişkilerde kendinizi ifade etme konusunda zorluklar yaşıyor olabilirsiniz.";
     elseif ($totalScore > 80 && $totalScore <= 130) return "Atılganlık düzeyiniz orta seviyede görünüyor. Çoğu durumda kendinizi rahatça ifade edebilirsiniz, ancak bazı durumlarda çekingenlik yaşayabilirsiniz.";
     elseif ($totalScore > 130 && $totalScore <= 180) return "Atılganlık düzeyiniz yüksek görünüyor. Kişilerarası ilişkilerde genellikle kendinizi rahat ve etkili bir şekilde ifade edebilirsiniz.";
     else return "Geçersiz Puan Aralığı ({$totalScore}). Lütfen cevaplarınızı kontrol edin.";
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
                 error_log("Participant not found for view-result-21 (ID: {$participantId}, Survey: {$surveyId})");
            } else {
                $survey_title = !empty($participantData['survey_title']) ? htmlspecialchars($participantData['survey_title']) . " Sonuçları" : $testTitleDefault . " Sonuçları";
                // Logo URL Ayarla...
                if (!empty($participantData['admin_id']) && !empty($participantData['institution_logo_path'])) {
                   $rawInstitutionPathFromDB = $participantData['institution_logo_path'];
                   $cleanRelativePath = ltrim(str_replace('..', '', $rawInstitutionPathFromDB), '/');
                   $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/..');
                   $fullServerPath = rtrim($docRoot, '/') . '/' . $cleanRelativePath;
                   if (file_exists($fullServerPath)) { $institutionWebURL = '/' . $cleanRelativePath; }
                   else { error_log("Kurum logosu dosyası bulunamadı (view-result-21): " . $fullServerPath); }
                }

                // 2. Cevapları Çek (question_id artık sort_order, answer_text cevap metni)
                $stmt_answers = $pdo->prepare("SELECT question_id AS sort_order, answer_text FROM survey_answers WHERE participant_id = ? AND survey_id = ? ORDER BY sort_order ASC");
                $stmt_answers->execute([$participantId, $surveyId]);
                $fetched_answers = $stmt_answers->fetchAll(PDO::FETCH_ASSOC);

                // Toplam beklenen soru sayısı (30)
                $totalExpectedQuestions = count($positiveItems) + count($negativeItems);


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

                    // Soru metinlerini çek (tablo için)
                    $questionSortOrderToTextMap = [];
                    try {
                        $stmtQText = $pdo->prepare("SELECT sort_order, question AS question_text FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC");
                        $stmtQText->execute([$surveyId]);
                        $questionSortOrderToTextMap = $stmtQText->fetchAll(PDO::FETCH_KEY_PAIR); // [sort_order => question_text]
                    } catch(Exception $e) { error_log("DB result question text fetch error: " . $e->getMessage()); /* Hata set edilebilir */ }


                    foreach($fetched_answers as $ans) {
                        $sortOrder = (int)$ans['sort_order']; // question_id sütunundaki değer (sort_order)
                        $answerText = trim($ans['answer_text'] ?? ''); // Cevap metni

                        // Cevap metni optionsMap'te var mı kontrol et
                        if (in_array($answerText, $optionsMap)) {

                            // Skoru hesapla (olumlu/olumsuz maddeye göre)
                            if (in_array($sortOrder, $positiveItems)) {
                                // Olumlu madde puanlaması
                                $calculatedScore = $positiveScoring[$answerText] ?? 0;
                            } elseif (in_array($sortOrder, $negativeItems)) {
                                // Olumsuz madde puanlaması
                                $calculatedScore = $negativeScoring[$answerText] ?? 0;
                            } else {
                                // Ne olumlu ne olumsuz maddede bulunursa logla
                                error_log("Sort_order {$sortOrder} not found in positiveItems or negativeItems for survey {$surveyId}, participant {$participantId}");
                                $calculatedScore = 0; // Skora dahil etme
                            }

                            $totalScore += $calculatedScore;
                            $processedAnswerCount++;

                            // Detaylı tablo için veriyi hazırla
                            $questionText = $questionSortOrderToTextMap[$sortOrder] ?? 'Soru metni yüklenemedi';
                            $processedAnswersForTable[] = [
                                'madde' => $sortOrder,
                                'question_text' => $questionText,
                                'verilen_cevap' => $answerText,
                                'hesaplanan_puan' => $calculatedScore
                            ];

                        } else {
                             // Beklenmeyen cevap metni gelirse logla
                             error_log("Invalid answer_text ('{$answerText}') found in survey_answers for participant {$participantId}, survey {$surveyId}, sort_order {$sortOrder}.");
                             // Bu durumda bu cevabı skora dahil etme veya tabloya ekleme
                        }
                    }

                    // Tüm beklenen cevaplar işlendi mi kontrol et
                    if ($processedAnswerCount < $totalExpectedQuestions) {
                         error_log("view-result-21 DB: Processed fewer valid answers ({$processedAnswerCount}) than expected ({$totalExpectedQuestions}) for participant {$participantId}.");
                         // Bu durumda skorun doğruluğu sorgulanabilir.
                         // $totalScore = null; // Skoru geçersiz kıl
                         // $interpretation = "Hesaplanamadı (Eksik veya hatalı cevaplar işlendi)";
                    }

                    // 4. Yorumu Hesapla (Toplam skor geçerliyse)
                    if ($totalScore !== null) {
                         $interpretation = interpretAssertivenessScore($totalScore);
                    } else {
                         $interpretation = "Hesaplanamadı (Cevaplar işlenemedi).";
                    }


                } // End if (empty($fetched_answers)) else
            } // End if (!$participantData) else

        } catch (Exception $e) {
             // Veritabanı veya diğer hatalar için genel hata yönetimi
             $error = "Sonuçlar yüklenirken bir hata oluştu: " . $e->getMessage();
             error_log("DB Error view-result-21 (ID: {$participantId}): ".$e->getMessage());
             $participantData = null; // Hata durumunda katılımcı verisini temizle
             $totalScore = null; // Hata durumunda skoru temizle
             $interpretation = "Hesaplanamadı"; // Hata durumunda yorumu temizle
             $processedAnswersForTable = []; // Hata durumunda tablo verisini temizle
        }
    }

} else {
    // --- SENARYO 2: ID YOK -> SESSION'DAN ÇEK ---
    $dataSource = 'session';
    // take-survey-21.php Session'a 'total_score', 'interpretation', 'answers' ([sort_order => answer_text]) kaydediyor.
    $totalScore = null; $interpretation = "Hesaplanamadı"; $participantData = null; $processedAnswersForTable = []; // Başlangıç değerleri

    if (isset($_SESSION['survey_result_data'], $_SESSION['survey_result_data']['survey_id']) && $_SESSION['survey_result_data']['survey_id'] == $surveyId) {
        $sessionData = $_SESSION['survey_result_data'];
        // Gerekli anahtarların varlığını kontrol et
        if (isset($sessionData['total_score'], $sessionData['interpretation'], $sessionData['participant_name'], $sessionData['answers']) && is_array($sessionData['answers'])) {
            $totalScore = $sessionData['total_score'];
            $interpretation = $sessionData['interpretation']; // Yorum Session'dan alınıyor
            $participantData = [
                 'name' => $sessionData['participant_name'],
                 'class' => $sessionData['participant_class'] ?? null,
                 'created_at' => date('Y-m-d H:i:s', $sessionData['timestamp'] ?? time()),
                 'admin_id' => null // Session'dan gelen sonuçta admin_id olmaz
            ];
            $survey_title = $testTitleDefault . " Sonucunuz"; $error = null;

            // Detaylı tablo için veriyi hazırla (Session'daki answers [sort_order => answer_text] formatında)
            $sessionAnswers = $sessionData['answers'];
            // Toplam beklenen soru sayısı (30)
            $totalExpectedQuestions = count($positiveItems) + count($negativeItems);

            // Soruları DB'den çekerek metinlerini alalım (sort_order'a göre)
            $questionSortOrderToTextMap = [];
             try {
                 $stmtQText = $pdo->prepare("SELECT sort_order, question AS question_text FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC");
                 $stmtQText->execute([$surveyId]);
                 $questionSortOrderToTextMap = $stmtQText->fetchAll(PDO::FETCH_KEY_PAIR); // [sort_order => question_text]
             } catch(Exception $e) { error_log("Session result question text fetch error: " . $e->getMessage()); /* Hata set edilebilir */ }

            $processedAnswerCount = 0;
            foreach ($sessionAnswers as $sortOrder => $answerText) {
                 $sortOrder_int = (int)$sortOrder;
                 $answerText_str = trim($answerText);

                 // Geçerli sort_order ve cevap metni kontrolü
                 if (($sortOrder_int > 0 && $sortOrder_int <= $totalExpectedQuestions) && in_array($answerText_str, $optionsMap)) {

                     // Skoru hesapla (olumlu/olumsuz maddeye göre) - Session'da zaten hesaplandı ama tablo için tekrar yapıyoruz
                     if (in_array($sortOrder_int, $positiveItems)) {
                         $calculatedScore = $positiveScoring[$answerText_str] ?? 0;
                     } elseif (in_array($sortOrder_int, $negativeItems)) {
                         $calculatedScore = $negativeScoring[$answerText_str] ?? 0;
                     } else {
                         $calculatedScore = 0; // Bilinmeyen madde
                     }


                     // Soru metnini bul
                     $questionText = $questionSortOrderToTextMap[$sortOrder_int] ?? 'Soru metni yüklenemedi';

                     $processedAnswersForTable[] = [
                         'madde' => $sortOrder_int,
                         'question_text' => $questionText,
                         'verilen_cevap' => $answerText_str,
                         'hesaplanan_puan' => $calculatedScore
                     ];
                     $processedAnswerCount++;

                 } else {
                      // Beklenmeyen sort_order veya geçersiz cevap metni gelirse logla
                      error_log("Invalid sort_order ({$sortOrder}) or answer_text ('{$answerText}') in session data for survey {$surveyId}");
                 }
            }

            // Session'daki cevap sayısı beklenenle uyuşuyor mu kontrol et (opsiyonel)
             if ($processedAnswerCount < $totalExpectedQuestions) {
                 error_log("view-result-21 Session: Processed fewer valid answers ({$processedAnswerCount}) than expected ({$totalExpectedQuestions}) from session data.");
                 // Bu durumda tablo boşaltılabilir veya bir uyarı gösterilebilir.
                 // $processedAnswersForTable = []; // Tabloyu boşalt
             }


            unset($_SESSION['survey_result_data']); // Başarılı okuma sonrası temizle
        } else {
            // Session verisi eksikse hata set et ve logla
            error_log("Incomplete session data for survey 21: " . print_r($sessionData, true));
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
    if (!file_exists($fullPsikoServerPath)) { $psikometrikWebURL = null; error_log("Psikometrik logo dosyası bulunamadı (view-result-21): " . $fullPsikoServerPath); }
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
        /* --- Stil Bloğu (Yeşil Tema - genel uyumluluk) --- */
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
        .result-summary h2 { margin-top: 0; text-align: center; }
        .final-score { font-size: 2.4em; font-weight: bold; color: #15803d; display: block; margin: 10px 0; }
        .score-interpretation { font-size: 1.15em; color: #374151; line-height: 1.7; text-align: center; margin-top: 20px;}


        /* Detaylı Cevap Tablosu Stilleri */
        .answers-table { width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: 0.9rem; }
        .answers-table th, .answers-table td { border: 1px solid #ddd; padding: 8px 10px; text-align: left; vertical-align: top; }
        .answers-table th { background-color: #dcfce7; font-weight: 600; color: #1f2937; }
        .answers-table td:nth-child(1) { width: 8%; text-align: center; font-weight: bold; vertical-align: middle;} /* Madde No */
        .answers-table td:nth-child(2) { width: 45%; line-height: 1.4; } /* Soru Metni */
        .answers-table td:nth-child(3) { width: 15%; text-align: center; vertical-align: middle;} /* Verilen Cevap */
        .answers-table td:nth-child(4) { width: 15%; text-align: center; font-weight: bold; vertical-align: middle;} /* Hesaplanan Puan */
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
                 <a href="../index.php" class="action-button panel-button">Diğer Anketler</a> <?php // Ana sayfaya yönlendirme ?>
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
             <h2>Atılganlık Puanınız</h2>
             <p><strong>Toplam Puan:</strong> <span class="final-score"><?= htmlspecialchars($totalScore) ?></span></p>
             <p class="score-interpretation"><strong>Yorum:</strong><br><?= htmlspecialchars($interpretation) ?></p>
             <p style="font-size: 0.85em; margin-top: 15px; color: #475569;">(Min Puan: 30, Max Puan: 180)</p>
        </div>

        <h2>Detaylı Cevaplarınız</h2>
         <?php if (!empty($processedAnswersForTable)): ?>
             <table class="answers-table">
                 <thead>
                     <tr>
                         <th>Madde No</th>
                         <th>Soru</th>
                         <th>Verilen Cevap</th>
                         <th>Hesaplanan Puan</th>
                     </tr>
                 </thead>
                 <tbody>
                     <?php foreach ($processedAnswersForTable as $item): ?>
                     <tr>
                         <td><?= htmlspecialchars($item['madde']) ?></td>
                         <td><?= htmlspecialchars($item['question_text']) ?></td>
                         <td><?= htmlspecialchars($item['verilen_cevap']) ?></td>
                         <td><?= htmlspecialchars($item['hesaplanan_puan']) ?></td>
                     </tr>
                     <?php endforeach; ?>
                 </tbody>
             </table>
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
