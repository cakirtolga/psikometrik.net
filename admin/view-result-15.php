<?php
// view-result-15.php (İnternet Bağımlılığı Ölçeği Sonuçları v4 - Koşullu Veri Kaynağı)

session_start(); // Session'dan veri okumak için GEREKLİ
ini_set('display_errors', 1); // Geliştirme sırasında hataları göster
error_reporting(E_ALL);

// --- Veritabanı Bağlantısı ---
require '../src/config.php';// PDO $pdo nesnesini oluşturur
if (!isset($pdo) || !$pdo instanceof PDO) {
    error_log("CRITICAL: PDO connection object (\$pdo) not created or found in src/config.php");
    header('Content-Type: text/html; charset=utf-8');
    die('<div style="border: 1px solid red; padding: 10px; margin: 10px; background-color: #ffebeb; color: red; font-family: sans-serif;"><b>Kritik Hata:</b> Veritabanı bağlantısı kurulamadı (src/config.php).</div>');
}
// --------------------------------

// --- Ayarlar ---
$surveyId = 15; // Sabit
$testTitleDefault = "İnternet Bağımlılığı Ölçeği";
$pageTitle = $testTitleDefault . " Sonuçları";

// --- Değişkenler ---
$participantId = null;       // DB'den gelirse ID, Session'dan gelirse null
$participantData = null;     // Katılımcı bilgileri (DB veya Session)
$survey_title = $pageTitle;
$questions = [];             // Anketin tüm soruları (DB'den)
$participantAnswers = [];    // question_id (GERÇEK ID) => answer_text/answer_score
$processedAnswersForTable = [];
$totalScore = null;
$scoreInterpretation = "Hesaplanamadı";
$error = null;
$dataSource = null;          // 'db' veya 'session'

// --- Logo URL Tanımlamaları ---
$institutionWebURL = null; // Kurum logosu admin senaryosunda yüklenecek
$psikometrikWebURL = '/assets/Psikometrik.png'; // Projenizdeki doğru yolu belirtin
// --- Bitiş Logo URL Tanımlamaları ---

// --- Cevap Metnini Puana Çevirme Haritası ---
$scoreMap = [
    "Hiçbir zaman" => 0, "Nadiren" => 1, "Bazen" => 2,
    "Sıklıkla" => 3, "Çoğu zaman" => 4, "Her zaman" => 5
];
// Puandan metne çevirme (Session durumunda lazım)
$optionsMap = array_flip($scoreMap);

// --- Puan Yorumlama Fonksiyonu ---
function interpretInternetAddictionScore($score) {
    if ($score === null || !is_numeric($score)) return "Puan hesaplanamadığı için yorum yapılamadı.";
    if ($score >= 0 && $score <= 49) return "Ortalama internet kullanıcısı (Yaşamında internet kullanımına bağlı herhangi bir sorun yaşamıyor).";
    elseif ($score >= 50 && $score <= 79) return "Riskli internet kullanımı (Günlük hayatında internetle ilgili bir takım sorunlar yaşıyor).";
    elseif ($score >= 80 && $score <= 100) return "İnternet bağımlısı (İşlevsellikte belirgin bozulma göstergesi).";
    else return "Geçersiz Puan Aralığı ($score)";
}


// --- VERİ KAYNAĞINI BELİRLE VE VERİYİ AL ---
if (isset($_GET['id'])) {
    // --- SENARYO 1: ID VAR -> VERİTABANINDAN ÇEK (Admin veya Kayıtlı Kullanıcı Görüntülemesi) ---
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
                       u.institution_logo_path -- Admin varsa logosunu alalım
                FROM survey_participants sp
                LEFT JOIN surveys s ON sp.survey_id = s.id
                LEFT JOIN users u ON sp.admin_id = u.id -- Hata vermemesi için LEFT JOIN
                WHERE sp.id = ? AND sp.survey_id = ?
            ");
            $stmt_participant->execute([$participantId, $surveyId]);
            $participantData = $stmt_participant->fetch(PDO::FETCH_ASSOC);

            if (!$participantData) { throw new Exception("Katılımcı bulunamadı (ID: {$participantId}, Anket: {$surveyId})."); }
            $survey_title = !empty($participantData['survey_title']) ? htmlspecialchars($participantData['survey_title']) . " Sonuçları" : $testTitleDefault . " Sonuçları";

            // 2. Logo URL'lerini Ayarla (Sadece admin varsa kurum logosu)
            if (!empty($participantData['admin_id']) && !empty($participantData['institution_logo_path'])) {
                $rawInstitutionPathFromDB = $participantData['institution_logo_path'];
                $cleanRelativePath = ltrim(str_replace('..', '', $rawInstitutionPathFromDB), '/');
                $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/..');
                $fullServerPath = rtrim($docRoot, '/') . '/' . $cleanRelativePath;
                if (file_exists($fullServerPath)) { $institutionWebURL = '/' . $cleanRelativePath; }
                else { error_log("Kurum logosu dosyası bulunamadı (view-result-15): " . $fullServerPath); }
            }
            // Psikometrik logo kontrolü (her zaman yapılır)
             if ($psikometrikWebURL) {
                 $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/..');
                 $fullPsikoServerPath = rtrim($docRoot, '/') . '/' . ltrim(str_replace('..', '', $psikometrikWebURL), '/');
                 if (!file_exists($fullPsikoServerPath)) { $psikometrikWebURL = null; error_log("Psikometrik logo dosyası bulunamadı (view-result-15): " . $fullPsikoServerPath); }
             }


            // 3. Anketin Tüm Sorularını Çek
            $stmt_questions = $pdo->prepare("SELECT id, question AS question_text, sort_order FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC, id ASC");
            $stmt_questions->execute([$surveyId]);
            $questions = $stmt_questions->fetchAll(PDO::FETCH_ASSOC);
            if (empty($questions)) { throw new Exception("Anket soruları veritabanında bulunamadı."); }

            // 4. Katılımcının Cevaplarını Çek (Gerçek question_id ve answer_text)
            $stmt_answers = $pdo->prepare("SELECT question_id, answer_text FROM survey_answers WHERE participant_id = ? AND survey_id = ?");
            $stmt_answers->execute([$participantId, $surveyId]);
            $fetched_answers = $stmt_answers->fetchAll(PDO::FETCH_ASSOC);
            if (empty($fetched_answers)) { throw new Exception("Katılımcı cevapları bulunamadı."); }

            // Cevapları question_id (GERÇEK ID) => answer_text haritasına dök
            foreach($fetched_answers as $ans) {
                if (isset($ans['question_id']) && isset($ans['answer_text'])) {
                     $participantAnswers[(int)$ans['question_id']] = trim($ans['answer_text']);
                }
            }

            // 5. Skoru Hesapla ve Tabloyu Hazırla
            $totalScore = 0; $answeredQuestionsCount = 0; $missingAnswers = false;
            foreach($questions as $q) {
                $question_id = (int)$q['id']; $sort_order = (int)$q['sort_order']; $question_text = $q['question_text'];
                $answerText = $participantAnswers[$question_id] ?? null; // Gerçek ID ile cevap metnini bul
                $itemScore = null;
                if ($answerText !== null && isset($scoreMap[$answerText])) {
                    $itemScore = $scoreMap[$answerText]; $totalScore += $itemScore; $answeredQuestionsCount++;
                } else {
                    $missingAnswers = true; $answerText = ($answerText === null) ? 'Cevap Yok' : ('DB Kayıt Hatası: ' . $answerText);
                }
                $processedAnswersForTable[] = ['madde' => $sort_order, 'question_text' => $question_text, 'answer_text' => $answerText, 'score' => $itemScore];
            }
            // Eksik cevap varsa toplam skoru null yap
            if ($missingAnswers || $answeredQuestionsCount < count($questions)) {
                 error_log("Warning: Not all questions seem answered/valid for P:{$participantId}, S:{$surveyId}. Answered: {$answeredQuestionsCount}/".count($questions));
                 $totalScore = null;
             }

            // 6. Puanı Yorumla (Eğer geçerliyse)
            $scoreInterpretation = interpretInternetAddictionScore($totalScore);


        } catch (Exception $e) {
            $error = "Sonuçlar yüklenirken hata oluştu: " . $e->getMessage();
            error_log("DB Error view-result-15 (ID: {$participantId}): ".$e->getMessage());
        }
    }

} else {
    // --- SENARYO 2: ID YOK -> SESSION'DAN ÇEK (Ücretsiz Anket Sonucu) ---
    $dataSource = 'session';
    if (isset($_SESSION['survey_result_data']) && isset($_SESSION['survey_result_data']['survey_id']) && $_SESSION['survey_result_data']['survey_id'] == $surveyId) {
        $sessionData = $_SESSION['survey_result_data'];

        // Session'dan verileri al
        $totalScore = $sessionData['score'];
        $scoreInterpretation = $sessionData['interpretation'];
        $sessionAnswers = $sessionData['answers']; // [qid => score] formatında
        $participantData = [
             'name' => $sessionData['participant_name'] ?? 'Katılımcı',
             'class' => $sessionData['participant_class'] ?? null,
             'participant_created_at' => date('Y-m-d H:i:s', $sessionData['timestamp'] ?? time()), // Yaklaşık zaman
             'admin_id' => null // Session'dan gelende admin_id olmaz
        ];
        $survey_title = $testTitleDefault . " Sonucunuz"; // Başlığı ayarla

        // Tabloyu hazırlamak için soruları DB'den çekmemiz GEREKİR
        try {
            $stmt_questions = $pdo->prepare("SELECT id, question AS question_text, sort_order FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC, id ASC");
            $stmt_questions->execute([$surveyId]);
            $questions = $stmt_questions->fetchAll(PDO::FETCH_ASSOC);
            if (empty($questions)) { throw new Exception("Anket soruları veritabanında bulunamadı (Session senaryosu)."); }

            // Session cevaplarını kullanarak tabloyu doldur
            foreach($questions as $q) {
                 $question_id = (int)$q['id']; $sort_order = (int)$q['sort_order']; $question_text = $q['question_text'];
                 $itemScore = $sessionAnswers[$question_id] ?? null; // Session'dan skoru al
                 $answerText = ($itemScore !== null && isset($optionsMap[$itemScore])) ? $optionsMap[$itemScore] : 'Cevap Yok/Hatalı'; // Skordan metni bul

                 $processedAnswersForTable[] = [
                     'madde' => $sort_order,
                     'question_text' => $question_text,
                     'answer_text' => $answerText,
                     'score' => $itemScore
                 ];
             }

        } catch (Exception $e) {
             $error = "Sonuçlar görüntülenirken sorular yüklenemedi: " . $e->getMessage();
             error_log("Session Result Question Fetch Error: " . $e->getMessage());
             $processedAnswersForTable = []; // Tabloyu boşalt
             $participantData = null; // Hata varsa katılımcı bilgisini de sıfırla
        }

        // Session verisini TEMİZLE
        unset($_SESSION['survey_result_data']);

    } else {
        // Session'da veri yoksa veya yanlış anket ID'si ise
        $error = "Görüntülenecek sonuç bulunamadı veya oturum süresi dolmuş olabilir. Lütfen anketi tekrar doldurun.";
    }
}
// --- VERİ KAYNAĞI SONU ---


// Psikometrik logo kontrolü
if ($psikometrikWebURL) {
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/..');
    $fullPsikoServerPath = rtrim($docRoot, '/') . '/' . ltrim(str_replace('..', '', $psikometrikWebURL), '/');
    if (!file_exists($fullPsikoServerPath)) { $psikometrikWebURL = null; error_log("Psikometrik logo dosyası bulunamadı (view-result-15): " . $fullPsikoServerPath); }
}


// Header gönder
if (!headers_sent()) {
     header('Content-Type: text/html; charset=utf-8');
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> <?= ($participantData) ? '- ' . htmlspecialchars($participantData['name']) : '' ?></title>
    <style>
        /* Stil Bloğu (öncekiyle aynı, yeşil tema) */
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

        @media print {
            body { background: #fff !important; color: #000 !important; font-size: 9pt; }
            .page-header { border-bottom: 1px solid #ccc; box-shadow: none; padding: 5mm 10mm; }
            .page-header img { max-height: 35px; }
            .container { box-shadow: none; border: none; margin: 0; max-width: 100%; padding: 10mm; }
            h1 { font-size: 16pt; color: #000 !important; } h2 { font-size: 13pt; margin-top: 1.5rem; border: none; padding-bottom: 0; color: #000 !important; }
            .participant-info, .result-summary { background-color: #fff; border: 1px solid #eee; page-break-inside: avoid;}
            .result-summary { background-color: #f0f0f0 !important; print-color-adjust: exact; border-color: #ccc !important; }
            .answers-table { margin-top: 5mm; font-size: 8pt; page-break-inside: auto; }
            .answers-table th, .answers-table td { border: 1px solid #bbb !important; padding: 1.5mm 2mm; vertical-align: top; }
            .answers-table th { background-color: #e9e9e9 !important; color: #000 !important; font-weight: bold; print-color-adjust: exact; }
            tr { page-break-inside: avoid !important; }
            .action-buttons, .no-print { display: none !important; }
        }
    </style>
</head>
<body>

<div class="page-header">
    <div>
        <?php if($dataSource == 'db' && !empty($institutionWebURL)): // Sadece DB'den geliyorsa ve logo varsa ?>
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
                         <td><?= ($item['score'] !== null) ? htmlspecialchars($item['score']) : '-' ?></td>
                     </tr>
                     <?php endforeach; ?>
                 </tbody>
             </table>
         <?php else: ?>
             <div class="error-box">Detaylı cevaplar görüntülenemiyor.</div>
         <?php endif; ?>


         <div class="action-buttons no-print">
            <?php // Panele Dön butonu sadece DB'den veri çekildiyse gösterilsin
                  if ($dataSource == 'db'): ?>
                 <a href="dashboard.php" class="action-button panel-button">Panele Dön</a>
            <?php else: ?>
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