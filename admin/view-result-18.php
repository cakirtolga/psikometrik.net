<?php
// view-result-18.php (Mesleki Olgunluk Ölçeği Sonuçları v2 - DB'den Sort Order ve Metin Okuma)

session_start(); // Session GEREKLİ
ini_set('display_errors', 1); error_reporting(E_ALL);

// --- Veritabanı Bağlantısı ---
require_once '../src/config.php';
if (!isset($pdo) || !$pdo instanceof PDO) {
    error_log("CRITICAL: PDO connection object (\$pdo) not created or found in src/config.php");
    header('Content-Type: text/html; charset=utf-8');
    die('<div style="border: 1px solid red; padding: 10px; margin: 10px; background-color: #ffebeb; color: red; font-family: sans-serif;"><b>Kritik Hata:</b> Veritabanı bağlantısı kurulamadı (src/config.php).</div>');
}
// --------------------------------

// --- Konfigürasyon ---
$surveyId = 18;
$testTitleDefault = "Mesleki Olgunluk Ölçeği";
$pageTitle = $testTitleDefault . " Sonuçları";

// --- Değişkenler ---
$participantId = null; $participantData = null; $survey_title = $pageTitle;
$tScore = null; // Hesaplanan T (Ham) Puan
$xScore = null; $yScore = null; $zScore = null; // Alt puanlar (opsiyonel)
$interpretation = "Hesaplanamadı";
$error = null; $dataSource = null;
// --- Logo URL ---
$institutionWebURL = null; $psikometrikWebURL = '/assets/Psikometrik.png';
// --- Sabit Veriler ---
// Seçenekler ve Puanları (Tam Metin)
$optionsMap = [
    1 => "Bana Hiç Uygun Değil",
    2 => "Bana Pek Uygun Değil",
    3 => "Bana Biraz Uygun",
    4 => "Bana Uygun",
    5 => "Bana Çok Uygun"
];
// Metinden puanı bulmak için ters harita
$textToScoreMap = array_flip($optionsMap);

// Skorlama için X ve Y grubu maddeleri (SIRALI 1-40 NUMARALANDIRMA VARSAYILDI!)
// DİKKAT: Bu listelerin doğruluğunu orijinal ölçekten teyit edin!
$xItems = [5, 6, 7, 8, 10, 11, 18, 20, 23, 28, 29, 30, 31, 32, 34, 35, 37, 39, 40]; // 19 madde
$yItems = [1, 2, 3, 4, 9, 12, 13, 14, 15, 16, 17, 19, 21, 22, 24, 25, 26, 27, 33, 36, 38]; // 21 madde

// Skor Yorumlama Fonksiyonu (T Ham Puanına Göre)
function interpretMaturityScore($tScore) {
    if ($tScore === null || !is_numeric($tScore)) return "Hesaplanamadı";
    if ($tScore < 143) return "Mesleki olgunluk düzeyiniz şu an için beklenenin altında görünüyor. Meslekleri ve kendinizi tanımaya yönelik daha fazla araştırma yapmanız faydalı olabilir.";
    elseif ($tScore <= 155) return "Mesleki olgunluk düzeyiniz normal aralıkta. Ancak daha isabetli kararlar için ilgi ve yeteneklerinizi araştırmaya devam etmeniz önerilir.";
    else return "Mesleki olgunluk düzeyiniz beklenenin üzerinde. İlgi, yetenek ve değerlerinize uygun meslekleri belirleme konusunda iyi bir noktadasınız."; // 155 üzeri
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
                 error_log("Participant not found for view-result-18 (ID: {$participantId}, Survey: {$surveyId})");
            } else {
                $survey_title = !empty($participantData['survey_title']) ? htmlspecialchars($participantData['survey_title']) . " Sonuçları" : $testTitleDefault . " Sonuçları";
                // Logo URL Ayarla...
                if (!empty($participantData['admin_id']) && !empty($participantData['institution_logo_path'])) {
                   $rawInstitutionPathFromDB = $participantData['institution_logo_path'];
                   $cleanRelativePath = ltrim(str_replace('..', '', $rawInstitutionPathFromDB), '/');
                   $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/..');
                   $fullServerPath = rtrim($docRoot, '/') . '/' . $cleanRelativePath;
                   if (file_exists($fullServerPath)) { $institutionWebURL = '/' . $cleanRelativePath; }
                   else { error_log("Kurum logosu dosyası bulunamadı (view-result-18): " . $fullServerPath); }
                }

                // 2. Cevapları Çek (question_id artık sort_order, answer_text puan metni)
                // question_id sütununu sort_order olarak, answer_text sütununu cevap metni olarak alıyoruz
                $stmt_answers = $pdo->prepare("SELECT question_id AS sort_order, answer_text FROM survey_answers WHERE participant_id = ? AND survey_id = ?");
                $stmt_answers->execute([$participantId, $surveyId]);
                $fetched_answers = $stmt_answers->fetchAll(PDO::FETCH_ASSOC);

                // Toplam beklenen soru sayısı (40)
                $totalExpectedQuestions = count($xItems) + count($yItems);

                if (empty($fetched_answers) || count($fetched_answers) < $totalExpectedQuestions) {
                     // Cevap bulunamazsa veya eksikse hata set et ve logla
                     $error = "Katılımcı cevapları veritabanında bulunamadı veya eksik (ID: {$participantId}, Anket: {$surveyId}).";
                     error_log("Answers not found or incomplete for participant {$participantId} in survey {$surveyId}. Expected {$totalExpectedQuestions}, found " . count($fetched_answers));
                     // Hata durumunda skorları ve yorumları boşalt
                     $tScore = null;
                     $interpretation = "Hesaplanamadı";
                } else {
                    // 3. X, Y, Z, T Skorlarını Hesapla
                    $xScore = 0; $yScore = 0;
                    $processedAnswerCount = 0; // İşlenen geçerli cevap sayısı

                    foreach($fetched_answers as $ans) {
                        $sortOrder = (int)$ans['sort_order']; // question_id sütunundaki değer (sort_order)
                        $answerText = trim($ans['answer_text'] ?? '');

                        // Cevap metninden sayısal puanı bul
                        $answerScore = $textToScoreMap[$answerText] ?? null;

                        // Geçerli bir sort_order ve sayısal puan varsa işleme devam et
                        if ($answerScore !== null && ($sortOrder > 0 && $sortOrder <= $totalExpectedQuestions)) {
                             if (in_array($sortOrder, $xItems)) {
                                 $xScore += $answerScore;
                                 $processedAnswerCount++;
                             } elseif (in_array($sortOrder, $yItems)) {
                                 $yScore += $answerScore;
                                 $processedAnswerCount++;
                             } else {
                                 // questionGroupMap'te olmayan bir sort_order gelirse logla
                                 error_log("Sort_order {$sortOrder} from survey_answers not found in xItems or yItems for survey {$surveyId}, participant {$participantId}");
                                 // Bu durumda bu cevabı skorlamaya dahil etme
                             }
                        } else {
                             // Beklenmeyen sort_order veya geçersiz cevap metni/puanı gelirse logla
                             error_log("Invalid sort_order ({$sortOrder}) or answer_text ('{$answerText}') found in survey_answers for participant {$participantId}, survey {$surveyId}. Parsed score: " . ($answerScore ?? 'null'));
                             // Bu durumda bu cevabı skorlamaya dahil etme
                        }
                    }

                    // Tüm beklenen cevaplar işlendi mi kontrol et (opsiyonel ama iyi pratik)
                    // take-survey-18.php'de tüm soruların cevaplandığı zaten doğrulanıyor.
                    // processedAnswerCount'un totalExpectedQuestions'a eşit olması beklenir.
                    if ($processedAnswerCount < $totalExpectedQuestions) {
                         error_log("view-result-18 DB: Processed fewer answers ({$processedAnswerCount}) than expected ({$totalExpectedQuestions}) for participant {$participantId}.");
                         // Bu durumda skoru geçersiz kılabiliriz
                         $tScore = null;
                         $interpretation = "Hesaplanamadı (Eksik veya hatalı cevaplar işlendi)";
                    } else {
                         // Skor hesaplaması başarılıysa Z ve T skorlarını hesapla
                         $zScore = 126 - $yScore;
                         $tScore = $xScore + $zScore; // Ham puan

                         // 4. Yorumu Hesapla
                         $interpretation = interpretMaturityScore($tScore);
                    }

                } // End if (empty($fetched_answers)) else
            } // End if (!$participantData) else

        } catch (Exception $e) {
             // Veritabanı veya diğer hatalar için genel hata yönetimi
             $error = "Sonuçlar yüklenirken bir hata oluştu: " . $e->getMessage();
             error_log("DB Error view-result-18 (ID: {$participantId}): ".$e->getMessage());
             $participantData = null; // Hata durumunda katılımcı verisini temizle
             $tScore = null; // Hata durumunda skoru temizle
             $interpretation = "Hesaplanamadı"; // Hata durumunda yorumu temizle
        }
    }

} else {
    // --- SENARYO 2: ID YOK -> SESSION'DAN ÇEK ---
    $dataSource = 'session';
    // take-survey-18.php Session'a 't_score' ve 'interpretation' kaydediyor.
    // Bu kısım mevcut kodunuzla uyumludur.
    if (isset($_SESSION['survey_result_data'], $_SESSION['survey_result_data']['survey_id']) && $_SESSION['survey_result_data']['survey_id'] == $surveyId) {
        $sessionData = $_SESSION['survey_result_data'];
        // Gerekli anahtarların varlığını kontrol et
        if (isset($sessionData['t_score'], $sessionData['interpretation'], $sessionData['participant_name'])) {
            $tScore = $sessionData['t_score'];
            $interpretation = $sessionData['interpretation'];
            $participantData = [
                 'name' => $sessionData['participant_name'],
                 'class' => $sessionData['participant_class'] ?? null,
                 'created_at' => date('Y-m-d H:i:s', $sessionData['timestamp'] ?? time()),
                 'admin_id' => null // Session'dan gelen sonuçta admin_id olmaz
            ];
            $survey_title = $testTitleDefault . " Sonucunuz"; $error = null;

            // Session'daki answer array'i [qid (DB ID) => score (1-5)] formatında.
            // Eğer Session'dan X, Y, Z puanlarını göstermek isterseniz,
            // bu answer array'ini kullanarak burada yeniden hesaplamanız gerekir.
            // Şu anki Session logic'i sadece t_score ve interpretation'ı alıyor, bu yeterli olabilir.
            // Eğer alt puanları Session'dan göstermek isterseniz, aşağıdaki yorum satırlarını açın ve test edin.
            // try {
            //     $questionIdToSortOrderMap = $pdo->prepare("SELECT id, sort_order FROM survey_questions WHERE survey_id = ?")->execute([$surveyId])->fetchAll(PDO::FETCH_KEY_PAIR);
            //     $xScore = 0; $yScore = 0;
            //     foreach ($sessionData['answers'] as $qDbId => $aScore) {
            //         $sortOrder = $questionIdToSortOrderMap[$qDbId] ?? null;
            //         if ($sortOrder !== null) {
            //             if (in_array($sortOrder, $xItems)) { $xScore += (int)$aScore; }
            //             elseif (in_array($sortOrder, $yItems)) { $yScore += (int)$aScore; }
            //         }
            //     }
            //     $zScore = 126 - $yScore;
            // } catch(Exception $e) { error_log("Session alt puan hesaplama hatası: " . $e->getMessage()); }

            unset($_SESSION['survey_result_data']); // Başarılı okuma sonrası temizle
        } else {
            // Session verisi eksikse hata set et ve logla
            error_log("Incomplete session data for survey 18: " . print_r($sessionData, true));
            $error = "Oturumdan alınan sonuç verisi eksik veya geçersiz (Kod: SESSION_INCOMPLETE).";
            $participantData = null; // Eksikse katılımcı verisi de yok
            $tScore = null; // Eksikse skor yok
            $interpretation = "Hesaplanamadı"; // Eksikse yorum yok
            unset($_SESSION['survey_result_data']); // Eksikse de temizle
        }
    } else {
        // Session verisi tamamen yoksa hata set et
        $error = "Görüntülenecek sonuç bulunamadı veya oturum süresi dolmuş olabilir (Kod: SESSION_MISSING).";
        $participantData = null;
        $tScore = null;
        $interpretation = "Hesaplanamadı";
    }
}
// --- VERİ KAYNAĞI SONU ---

// Psikometrik logo kontrolü...
if ($psikometrikWebURL) {
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/..');
    $fullPsikoServerPath = rtrim($docRoot, '/') . '/' . ltrim(str_replace('..', '', $psikometrikWebURL), '/');
    if (!file_exists($fullPsikoServerPath)) { $psikometrikWebURL = null; error_log("Psikometrik logo dosyası bulunamadı (view-result-18): " . $fullPsikoServerPath); }
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
        /* --- Stil Bloğu (Yeşil Tema - view-result-16 ile Uyumlu) --- */
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
    <?php elseif ($tScore === null): // Katılımcı var ama skor hesaplanamadıysa ?>
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
                 <a href="..index.php" class="action-button panel-button">Ana Sayfa</a>
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
             <h2>Mesleki Olgunluk Sonucunuz</h2>
             <p><strong>Ham Puanınız (T):</strong> <span class="final-score"><?= htmlspecialchars($tScore) ?></span></p>
             <p class="score-interpretation"><strong>Yorum:</strong><br><?= htmlspecialchars($interpretation) ?></p>
             <?php
                // Opsiyonel: X, Y, Z Puanlarını Gösterme (Sadece DB'den geliyorsa ve hesaplanabildiyse)
                // take-survey-18.php'de alt puanlar Session'a kaydedilmiyor, sadece T skoru.
                // Bu nedenle alt puanları sadece DB'den çekerken gösterebiliriz.
                if($dataSource == 'db' && $xScore !== null && $yScore !== null && $zScore !== null) {
                    echo "<p style='font-size:0.8em; margin-top:15px; color:#555;'> (X Puanı: " . htmlspecialchars($xScore) . ", Y Puanı: " . htmlspecialchars($yScore) . ", Z Puanı: " . htmlspecialchars($zScore) . ")</p>";
                }
             ?>
        </div>

         <?php /* Detaylı cevap tablosu bu anket için gösterilmiyor */ ?>

         <div class="action-buttons no-print">
            <?php if ($dataSource == 'db'): ?>
                 <a href="dashboard.php" class="action-button panel-button">Panele Dön</a>
            <?php else: ?>
                 <a href="../index.php" class="action-button panel-button">Ana Sayfa</a>
            <?php endif; ?>
             <button onclick="window.print();" class="action-button print-button">Sayfayı Yazdır</button>
         </div>

    <?php endif; ?>

</div> <?php // container sonu ?>

<script> /* Özel JS gerekmiyor */ </script>

</body>
</html>
