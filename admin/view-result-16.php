<?php
// view-result-16.php (Ergenler için Oyun Bağımlılığı Ölçeği Sonuçları v1)

session_start(); // Session'dan veri okumak için GEREKLİ
ini_set('display_errors', 1);
error_reporting(E_ALL);

// --- Veritabanı Bağlantısı ---
require '../src/config.php';
if (!isset($pdo) || !$pdo instanceof PDO) {
    error_log("CRITICAL: PDO connection object (\$pdo) not created or found in src/config.php");
    header('Content-Type: text/html; charset=utf-8');
    die('<div style="border: 1px solid red; padding: 10px; margin: 10px; background-color: #ffebeb; color: red; font-family: sans-serif;"><b>Kritik Hata:</b> Veritabanı bağlantısı kurulamadı (src/config.php).</div>');
}
// --------------------------------

// --- Ayarlar ---
$surveyId = 16; // Anket ID'si
$testTitleDefault = "Ergenler için Oyun Bağımlılığı Ölçeği";
$pageTitle = $testTitleDefault . " Sonuçları";

// --- Değişkenler ---
$participantId = null;
$participantData = null;
$survey_title = $pageTitle;
$questions = [];
$participantAnswers = []; // [question_id => answer_text/score] - Bu array'in anahtarı artık sort_order olacak
$processedAnswersForTable = [];
$totalScore = null;
$scoreInterpretation = "Hesaplanamadı";
$error = null;
$dataSource = null; // 'db' veya 'session'

// --- Logo URL Tanımlamaları ---
$institutionWebURL = null;
$psikometrikWebURL = '/assets/Psikometrik.png';
// --- Bitiş Logo URL Tanımlamaları ---

// --- Cevap Seçenekleri ve Puan Haritaları (1-5 Skala) ---
$optionsMap = [
    1 => "Asla", 2 => "Nadiren", 3 => "Bazen",
    4 => "Sıklıkla", 5 => "Çok Sık"
];
$scoreMap = array_flip($optionsMap); // Metni puana çevirmek için

// --- Puan Yorumlama Fonksiyonu (Anket 16 için güncellendi) ---
function interpretGameAddictionScore($score) {
    if ($score === null || !is_numeric($score)) return "Puan hesaplanamadığı için yorum yapılamadı.";
    // PDF'e göre 50 ve üstü riskli grup, min puan 21 (21*1), max 105 (21*5)
    if ($score >= 50 && $score <= 105) {
        return "Riskli grup (Oyun kullanımıyla ilgili sorun yaşama olasılığınız bulunmaktadır. Bir uzmana danışmanız önerilir).";
    } elseif ($score >= 21 && $score < 50) {
        return "Risk grubunda değil (Mevcut oyun kullanım alışkanlıklarınız belirgin bir risk taşımamaktadır).";
    } else {
        // 21'den düşük veya 105'ten yüksekse geçersizdir
        return "Geçersiz Puan Aralığı ($score). Lütfen cevaplarınızı kontrol edin.";
    }
}


// --- VERİ KAYNAĞINI BELİRLE VE VERİYİ AL ---
if (isset($_GET['id'])) {
    // --- SENARYO 1: ID VAR -> VERİTABANINDAN ÇEK ---
    $dataSource = 'db';
    $participantId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$participantId) {
        $error = "Geçersiz katılımcı ID'si.";
    } else {
        try {
            // 1. Katılımcı, Anket Başlığı ve Logo Bilgilerini Çek
            $stmt_participant = $pdo->prepare("
                SELECT sp.id as participantId, sp.name, sp.class, sp.created_at as participant_created_at, sp.admin_id,
                       s.title as survey_title,
                       u.institution_logo_path
                FROM survey_participants sp
                LEFT JOIN surveys s ON sp.survey_id = s.id
                LEFT JOIN users u ON sp.admin_id = u.id
                WHERE sp.id = ? AND sp.survey_id = ?
            ");
            $stmt_participant->execute([$participantId, $surveyId]);
            $participantData = $stmt_participant->fetch(PDO::FETCH_ASSOC);

            if (!$participantData) { throw new Exception("Katılımcı bulunamadı (ID: {$participantId}, Anket: {$surveyId})."); }
            $survey_title = !empty($participantData['survey_title']) ? htmlspecialchars($participantData['survey_title']) . " Sonuçları" : $testTitleDefault . " Sonuçları";

            // 2. Logo URL'lerini Ayarla
             if (!empty($participantData['admin_id']) && !empty($participantData['institution_logo_path'])) {
                /* ... logo path işleme ... */
                $rawInstitutionPathFromDB = $participantData['institution_logo_path'];
                $cleanRelativePath = ltrim(str_replace('..', '', $rawInstitutionPathFromDB), '/');
                $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/..');
                $fullServerPath = rtrim($docRoot, '/') . '/' . $cleanRelativePath;
                if (file_exists($fullServerPath)) { $institutionWebURL = '/' . $cleanRelativePath; }
                else { error_log("Kurum logosu dosyası bulunamadı (view-result-16): " . $fullServerPath); }
            }
             if ($psikometrikWebURL) { /* ... Psikometrik logo kontrolü ... */ }

            // 3. Anketin Tüm Sorularını Çek (ID, Question Text, Sort Order)
            $stmt_questions = $pdo->prepare("SELECT id, question AS question_text, sort_order FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC, id ASC");
            $stmt_questions->execute([$surveyId]);
            $questions = $stmt_questions->fetchAll(PDO::FETCH_ASSOC);
            if (empty($questions)) { throw new Exception("Anket soruları veritabanında bulunamadı."); }

            // 4. Katılımcının Cevaplarını Çek (question_id (şimdi sort_order içeriyor) ve answer_text)
            // NOT: survey_answers.question_id artık sort_order değerini tutuyor.
            $stmt_answers = $pdo->prepare("SELECT question_id, answer_text FROM survey_answers WHERE participant_id = ? AND survey_id = ?");
            $stmt_answers->execute([$participantId, $surveyId]);
            $fetched_answers = $stmt_answers->fetchAll(PDO::FETCH_ASSOC);
            // Cevapları question_id (ARTIK SORT ORDER) => answer_text haritasına dök
            foreach($fetched_answers as $ans) {
                // Anahtar olarak sort_order değerini kullanıyoruz
                if (isset($ans['question_id']) && isset($ans['answer_text'])) {
                     $participantAnswers[(int)$ans['question_id']] = trim($ans['answer_text']);
                }
            }
            // Cevapların boş olup olmadığını kontrol et
            if (empty($participantAnswers)) { throw new Exception("Katılımcı cevapları veritabanında bulunamadı."); }


            // 5. Skoru Hesapla ve Tabloyu Hazırla
            $totalScore = 0; $answeredQuestionsCount = 0; $missingAnswers = false;
            // Sorular üzerinde dönerken, her sorunun sort_order'ını kullanarak cevapları ara
            foreach($questions as $q) {
                $question_id = (int)$q['id']; // Orijinal DB ID
                $sort_order = (int)$q['sort_order']; // Sıra numarası
                $question_text = $q['question_text'];

                // *** Burası Değişti: Cevabı sort_order kullanarak participantAnswers'tan al ***
                $answerText = $participantAnswers[$sort_order] ?? null;

                $itemScore = null;
                // Metni puana çevir (1-5)
                if ($answerText !== null && isset($scoreMap[$answerText])) {
                    $itemScore = $scoreMap[$answerText];
                    $totalScore += $itemScore;
                    $answeredQuestionsCount++;
                } else {
                    // Cevap bulunamadı veya metin scoreMap'te yok
                    $missingAnswers = true;
                    $answerText = ($answerText === null) ? 'Cevap Yok' : ('Hatalı Cevap Metni: ' . htmlspecialchars($answerText));
                    // Eksik veya hatalı cevaplar için puan eklemiyoruz, tabloya "-" yazdıracağız
                }
                // Tablo için veriyi hazırla
                $processedAnswersForTable[] = [
                    'madde' => $sort_order,
                    'question_text' => $question_text,
                    'answer_text' => $answerText,
                    'score' => $itemScore // Eğer puan hesaplanamadıysa null kalır
                ];
            }

            // Tüm sorular cevaplanmadıysa toplam skoru geçersiz kıl
            if ($answeredQuestionsCount < count($questions)) {
                 $totalScore = null;
                 error_log("view-result-16 DB: Missing answers for participant {$participantId}. Expected " . count($questions) . ", found " . $answeredQuestionsCount);
            }


        } catch (Exception $e) {
            $error = "Sonuçlar yüklenirken hata oluştu: " . $e->getMessage();
            error_log("DB Error view-result-16 (ID: {$participantId}): ".$e->getMessage());
            // Hata durumunda tablo verisini temizle
            $processedAnswersForTable = [];
            $participantData = null; // Katılımcı verisi de hatalı olabilir
            $totalScore = null; // Skor geçersiz
        }
    }

} else {
    // --- SENARYO 2: ID YOK -> SESSION'DAN ÇEK ---
    $dataSource = 'session';
    // Session'dan gelen verinin yapısı take-survey-16.php'deki Session kaydı ile uyumlu olmalı.
    // take-survey-16.php'de Session'a kaydedilirken 'answers' array'i [qid (DB ID) => score (1-5)] formatındaydı.
    // view-result-16.php'nin Session kısmının bu formatı işlemesi gerekiyor.
    // Bu kısım, take-survey-16.php'nin Session kaydı formatı değişmediği için aynı kalabilir.

    if (isset($_SESSION['survey_result_data']) && isset($_SESSION['survey_result_data']['survey_id']) && $_SESSION['survey_result_data']['survey_id'] == $surveyId) {
        $sessionData = $_SESSION['survey_result_data'];

        // Session'dan verileri al
        $totalScore = $sessionData['score'];
        // Yorumu tekrar hesaplamak daha güvenli olabilir
        // $scoreInterpretation = $sessionData['interpretation'];
        $sessionAnswers = $sessionData['answers']; // [qid (DB ID) => score (1-5)]
        $participantData = [
             'name' => $sessionData['participant_name'] ?? 'Katılımcı',
             'class' => $sessionData['participant_class'] ?? null,
             'participant_created_at' => date('Y-m-d H:i:s', $sessionData['timestamp'] ?? time())
        ];
        $survey_title = $testTitleDefault . " Sonucunuz";

        // Tabloyu hazırlamak için soruları DB'den çek
        try {
            // Soruları orijinal ID, metin ve sort_order ile çek
            $stmt_questions = $pdo->prepare("SELECT id, question AS question_text, sort_order FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC, id ASC");
            $stmt_questions->execute([$surveyId]);
            $questions = $stmt_questions->fetchAll(PDO::FETCH_ASSOC);
            if (empty($questions)) { throw new Exception("Anket soruları veritabanında bulunamadı."); }

            // Session cevaplarını (qid (DB ID) => score) kullanarak tabloyu doldur
            $answeredQuestionsCount = 0;
            foreach($questions as $q) {
                 $question_id = (int)$q['id']; // Orijinal DB ID
                 $sort_order = (int)$q['sort_order']; // Sıra numarası
                 $question_text = $q['question_text'];

                 // Session'dan cevabı orijinal DB ID kullanarak al
                 $itemScore = $sessionAnswers[$question_id] ?? null; // Session'dan skoru (1-5) al

                 $answerText = ($itemScore !== null && isset($optionsMap[$itemScore])) ? $optionsMap[$itemScore] : 'Cevap Yok/Hatalı'; // Skordan metni bul

                 // Tablo için veriyi hazırla
                 $processedAnswersForTable[] = [
                     'madde' => $sort_order, // Tabloda sort_order gösteriliyor
                     'question_text' => $question_text,
                     'answer_text' => $answerText,
                     'score' => $itemScore // Puan (1-5)
                 ];

                 // Skor geçerliyse sayacı artır
                 if ($itemScore !== null && isset($optionsMap[$itemScore])) {
                    $answeredQuestionsCount++;
                 }
             }
             // Session'dan gelen skorun tüm soruları içerip içermediğini kontrol et
             // Eğer session'daki cevap sayısı toplam soru sayısından az ise skoru geçersiz kıl
             if ($answeredQuestionsCount < count($questions)) {
                 $totalScore = null; // Eksikse skoru geçersiz kıl
                 error_log("view-result-16 Session: Missing answers in session data. Expected " . count($questions) . ", found " . $answeredQuestionsCount);
             }


        } catch (Exception $e) {
             $error = "Sonuçlar görüntülenirken sorular yüklenemedi: " . $e->getMessage();
             error_log("Session Result Question Fetch Error (S{$surveyId}): " . $e->getMessage());
             $processedAnswersForTable = []; $participantData = null; $totalScore = null;
        }

        // Session verisini TEMİZLE
        unset($_SESSION['survey_result_data']);

    } else {
        $error = "Görüntülenecek sonuç bulunamadı veya oturum süresi dolmuş olabilir. Lütfen anketi tekrar doldurun.";
    }
}
// --- VERİ KAYNAĞI SONU ---


// Yorumlama (Skor geçerliyse hesapla)
if (!$error && $totalScore !== null) {
    $scoreInterpretation = interpretGameAddictionScore($totalScore);
} elseif (!$error) {
    $scoreInterpretation = "Puan hesaplanamadığı için yorum yapılamadı (Eksik veya geçersiz cevaplar olabilir).";
}

// Psikometrik logo kontrolü
// Bu kısım mevcut kodunuzla aynı kalabilir.
if ($psikometrikWebURL) {
    // Dosyanın varlığını kontrol et
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/..');
    $fullServerPath = rtrim($docRoot, '/') . '/' . ltrim($psikometrikWebURL, '/');
     if (!file_exists($fullServerPath)) {
         error_log("Psikometrik logosu dosyası bulunamadı (view-result-16): " . $fullServerPath);
         $psikometrikWebURL = null; // Dosya yoksa URL'yi null yap
     }
}


// Header gönder
if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> <?= ($participantData && isset($participantData['name'])) ? '- ' . htmlspecialchars($participantData['name']) : '' ?></title>
    <style>
        /* Stil Bloğu (Yeşil Tema - Öncekiyle aynı) */
        body { font-family: sans-serif; line-height: 1.6; background-color: #f0fdf4; color: #2c3e50; margin: 0; padding: 0; }
        .page-header { background-color: #ffffff; padding: 10px 25px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e0e0e0; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .page-header img { max-height: 50px; width: auto; }
        .container { max-width: 900px; margin: 20px auto; background: white; padding: 20px 30px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        h1 { text-align: center; color: #1f2937; margin-top: 0; margin-bottom: 1.5rem; font-size: 1.75rem; border-bottom: 2px solid #dcfce7; padding-bottom: 0.75rem; }
        h2 { font-size: 1.4rem; color: #15803d; margin-top: 2rem; margin-bottom: 1rem; border-bottom: 1px solid #eee; padding-bottom: 0.4rem; }
        h3 { font-size: 1.15rem; color: #15803d; margin-top: 1.5rem; margin-bottom: 0.8rem; }

        .participant-info, .result-summary { margin-bottom: 1.5rem; padding: 15px; background-color: #f9fafb; border: 1px solid #f3f4f6; border-radius: 8px; }
        .participant-info p, .result-summary p { margin: 0.4rem 0; font-size: 1rem; }
        .participant-info strong { font-weight: 600; color: #374151; min-width: 120px; display: inline-block; }
        .result-summary { text-align: center; background-color: #e8f5e9; border-color: #c8e6c9; }
        .total-score { font-size: 2.2em; font-weight: bold; color: #15803d; display: block; margin: 10px 0; }
        .score-interpretation { font-size: 1.1em; font-style: italic; color: #374151; }

        .answers-table { width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: 0.9rem; }
        .answers-table th, .answers-table td { border: 1px solid #ddd; padding: 8px 10px; text-align: left; vertical-align: top; }
        .answers-table th { background-color: #dcfce7; font-weight: 600; color: #1f2937; }
        .answers-table td:nth-child(1) { width: 8%; text-align: center; font-weight: bold; vertical-align: middle;}
        .answers-table td:nth-child(2) { width: 57%; line-height: 1.4; }
        .answers-table td:nth-child(3) { width: 25%; vertical-align: middle;}
        .answers-table td:nth-child(4) { width: 10%; text-align: center; vertical-align: middle;}
        .answers-table tr:nth-child(even) { background-color: #f8f9fa; }

        .error-box { color: #b91c1c; background-color: #fee2e2; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; border: 1px solid #fca5a5; font-weight: 500; text-align: left; font-size: 0.9em;}
        .error-box b { font-weight: bold; }

        .action-buttons { display: flex; justify-content: center; gap: 1rem; margin-top: 2.5rem; padding-top: 1.5rem; border-top: 1px solid #e0e0e0; }
        .action-button { display: inline-flex; align-items: center; padding: 10px 20px; font-size: 1rem; font-weight: 600; color: white; background-color: #15803d; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; transition: background-color 0.2s ease, box-shadow 0.2s ease; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .action-button:hover { background-color: #0b532c; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .no-print { }

        @media print { /* ... (Önceki yazdırma stilleri aynı) ... */ }
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

    <?php if ($error): ?>
         <h1>Sonuçlar Yüklenemedi</h1>
         <div class="error-box"><b>Hata:</b> <?= htmlspecialchars($error) ?></div>
    <?php elseif (!$participantData): ?>
         <h1>Sonuçlar Yüklenemedi</h1>
         <div class="error-box">Görüntülenecek katılımcı veya sonuç verisi bulunamadı.</div>
    <?php else: ?>

        <h1><?= htmlspecialchars($survey_title) ?></h1>

        <div class="participant-info">
             <h2>Katılımcı Bilgileri</h2>
             <p><strong>Ad Soyad:</strong> <?= htmlspecialchars($participantData['name']) ?></p>
             <p><strong>Sınıf/Bölüm:</strong> <?= htmlspecialchars($participantData['class'] ?? 'Belirtilmemiş') ?></p>
             <p><strong>Test Tarihi:</strong> <?= date('d.m.Y H:i', strtotime($participantData['participant_created_at'])) ?></p>
        </div>

        <div class="result-summary">
             <h2>Sonuç Özeti</h2>
             <?php if ($totalScore !== null): ?>
                 <p><strong>Toplam Puanınız:</strong> <span class="total-score"><?= htmlspecialchars($totalScore) ?></span></p>
                 <p><strong>Değerlendirme:</strong></p>
                 <p class="score-interpretation"><?= htmlspecialchars($scoreInterpretation) ?></p>
             <?php else: ?>
                  <p class="score-interpretation">Puan hesaplanamadı (Tüm sorular cevaplanmamış veya geçersiz cevaplar olabilir).</p>
             <?php endif; ?>
        </div>

         <h2>Detaylı Cevaplar ve Puanlar</h2>
         <?php if (!empty($processedAnswersForTable)): ?>
             <table class="answers-table">
                 <thead>
                     <tr>
                         <th>Madde No</th>
                         <th>Soru</th>
                         <th>Verilen Cevap</th>
                         <th>Puan</th>
                     </tr>
                 </thead>
                 <tbody>
                     <?php foreach ($processedAnswersForTable as $item): ?>
                     <tr>
                         <td><?= htmlspecialchars($item['madde']) // Sort Order ?></td>
                         <td><?= htmlspecialchars($item['question_text']) ?></td>
                         <td><?= htmlspecialchars($item['answer_text']) ?></td>
                         <td><?= ($item['score'] !== null) ? htmlspecialchars($item['score']) : '-' // Puan (1-5) ?></td>
                     </tr>
                     <?php endforeach; ?>
                 </tbody>
             </table>
         <?php else: ?>
             <div class="error-box">Detaylı cevaplar görüntülenemiyor.</div>
         <?php endif; ?>


         <div class="action-buttons no-print">
            <?php // Panele Dön butonu sadece DB'den geliyorsa göster
                  if ($dataSource == 'db'): ?>
                 <a href="dashboard.php" class="action-button panel-button">Panele Dön</a>
            <?php else: // Session'dan geliyorsa başka bir anket doldurma linki ?>
                  <a href="../index.php" class="action-button panel-button">Ana Sayfa</a> <?php // Örnek ?>
            <?php endif; ?>
             <button onclick="window.print();" class="action-button print-button">Sayfayı Yazdır</button>
         </div>

    <?php endif; ?>

</div> <?php // container sonu ?>

<script>
    // Özel JS gerekmiyor
</script>

</body>
</html>
