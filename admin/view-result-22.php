<?php
// view-result-22.php (SCL-90 Psikolojik Belirti Tarama Testi Sonuçları v2 - DB'den Metin Okuma ve Puanlama)

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
$surveyId = 22; // Anket ID'si
$testTitleDefault = "SCL-90 Psikolojik Belirti Tarama Testi";
$pageTitle = $testTitleDefault . " Sonuçları";

// --- Değişkenler ---
$participantId = null; $participantData = null; $survey_title = $pageTitle;
$subscaleScores = []; // Alt ölçek puanları [scaleKey => score]
$gsi = null; // Genel Semptom İndeksi
$interpretation = "Hesaplanamadı"; // Genel yorum veya dominant stil
$error = null; $dataSource = null;
$processedAnswersForTable = []; // Detaylı cevap tablosu için

// --- Logo URL ---
$institutionWebURL = null; $psikometrikWebURL = '/assets/Psikometrik.png'; // Logo yolunu kontrol edin

// --- Sabit Veriler (Anket 22 - SCL-90) ---
// Seçenekler ve Puanları (0-4 Likert)
$optionsMap = [
    0 => "Hiç",
    1 => "Çok az",
    2 => "Orta Derecede",
    3 => "Oldukça Fazla",
    4 => "İleri Derecede"
];
// Metinden puanı bulmak için ters harita
$textToScoreMap = array_flip($optionsMap);
// Puanlardan metni bulmak için harita
$scoreToTextMap = $optionsMap; // optionsMap zaten bu formatta

// Alt Ölçek Madde Numaraları (sort_order) - PDF'e göre
$subscales = [
    'SOM' => [1, 4, 12, 27, 40, 42, 48, 49, 52, 53, 56, 58], // Somatizasyon (12 madde)
    'O-C' => [3, 9, 10, 28, 38, 45, 46, 51, 55, 65], // Obsesif-Kompulsif (10 madde)
    'INT' => [6, 21, 34, 36, 37, 41, 61, 69, 73], // Kişilerarası Duyarlık (9 madde)
    'DEP' => [5, 14, 15, 20, 22, 26, 29, 30, 31, 32, 54, 71, 79], // Depresyon (13 madde)
    'ANX' => [2, 17, 23, 33, 39, 57, 72, 78, 80, 86], // Anksiyete (10 madde)
    'HOS' => [11, 24, 63, 67, 74, 81], // Öfke ve Düşmanlık (6 madde)
    'PHOB' => [13, 25, 47, 50, 70, 75, 82], // Fobik Anksiyete (7 madde)
    'PAR' => [8, 18, 43, 68, 76, 83], // Paranoid Düşünce (6 madde)
    'PSY' => [7, 16, 35, 62, 77, 84, 85, 87, 88, 90], // Psikotizm (10 madde)
    'EK' => [19, 44, 55, 59, 60, 64, 89, 66] // Ek Skala (8 madde)
];
$totalExpectedQuestions = 90; // Toplam soru sayısı

// Yorumlama Aralıkları
$interpretationRanges = [
    ['min' => 0.00, 'max' => 1.50, 'text' => 'Normal'],
    ['min' => 1.51, 'max' => 2.50, 'text' => 'Araz Düzeyi Yüksek'],
    ['min' => 2.51, 'max' => 4.00, 'text' => 'Araz Düzeyi Çok Yüksek'],
];

// Yorumlama Fonksiyonu (Alt Ölçek veya GSI Puanına Göre)
function interpretScore($score, $ranges) {
     if ($score === null || !is_numeric($score)) return "Hesaplanamadı";
     // Puanı iki ondalık basamağa yuvarla
     $score = round($score, 2);
     foreach ($ranges as $range) {
         // Aralıkları kontrol ederken, üst sınırı dahil etmek için <= kullanılır
         if ($score >= $range['min'] && $score <= $range['max']) {
             return $range['text'];
         }
     }
     return "Geçersiz Puan Aralığı"; // Beklenmeyen bir puan gelirse
}

// Cevap Geçersizlik Kriterleri
$minAnsweredForValidGSI = 90 - 18; // 18 veya daha az soru cevaplanmamışsa (en az 72 cevap)
// Alt ölçek geçersizlik yüzdesi: %40'ı cevaplanmamışsa = %60'ı cevaplanmışsa geçerli
$minAnsweredPercentageForSubscale = 0.60;


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
                 error_log("Participant not found for view-result-22 (ID: {$participantId}, Survey: {$surveyId})");
            } else {
                $survey_title = !empty($participantData['survey_title']) ? htmlspecialchars($participantData['survey_title']) . " Sonuçları" : $testTitleDefault . " Sonuçları";
                // Logo URL Ayarla...
                if (!empty($participantData['admin_id']) && !empty($participantData['institution_logo_path'])) {
                   $rawInstitutionPathFromDB = $participantData['institution_logo_path'];
                   $cleanRelativePath = ltrim(str_replace('..', '', $rawInstitutionPathFromDB), '/');
                   $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/..');
                   $fullServerPath = rtrim($docRoot, '/') . '/' . $cleanRelativePath;
                   if (file_exists($fullServerPath)) { $institutionWebURL = '/' . $cleanRelativePath; }
                   else { error_log("Kurum logosu dosyası bulunamadı (view-result-22): " . $fullServerPath); }
                }

                // 2. Cevapları Çek (question_id artık sort_order, answer_text cevap metni)
                // answer_score sütunu yerine answer_text sütununu çekiyoruz
                $stmt_answers = $pdo->prepare("SELECT question_id AS sort_order, answer_text FROM survey_answers WHERE participant_id = ? AND survey_id = ? ORDER BY sort_order ASC");
                $stmt_answers->execute([$participantId, $surveyId]);
                $fetched_answers = $stmt_answers->fetchAll(PDO::FETCH_ASSOC);

                // Toplam beklenen soru sayısı (90)
                $stmt_total_questions = $pdo->prepare("SELECT COUNT(*) FROM survey_questions WHERE survey_id = ?");
                $stmt_total_questions->execute([$surveyId]);
                $totalExpectedQuestionsFetched = (int)$stmt_total_questions->fetchColumn();

                // Cevapları sort_order'a göre bir haritaya dök ve metinden puana çevir
                $participantAnswersBySortOrder = []; // [sort_order => numerical_score (0-4)]
                foreach($fetched_answers as $ans) {
                    $sortOrder = (int)$ans['sort_order'];
                    $answerText = trim($ans['answer_text'] ?? '');
                    // Metinden sayısal puana çevir
                    $numericalScore = $textToScoreMap[$answerText] ?? null;

                    if ($numericalScore !== null) {
                        $participantAnswersBySortOrder[$sortOrder] = $numericalScore;
                    } else {
                        // Geçersiz metin karşılığı gelirse logla
                        error_log("Invalid answer_text '{$answerText}' found in DB for participant {$participantId}, survey {$surveyId}, sort_order {$sortOrder}");
                        // Bu cevabı dikkate alma
                    }
                }


                if (empty($participantAnswersBySortOrder) || count($participantAnswersBySortOrder) < $totalExpectedQuestionsFetched) {
                     // Cevap bulunamazsa veya eksikse hata set et ve logla
                     $error = "Katılımcı cevapları veritabanında bulunamadı veya eksik (ID: {$participantId}, Anket: {$surveyId}).";
                     error_log("Answers not found or incomplete for participant {$participantId} in survey {$surveyId}. Expected {$totalExpectedQuestionsFetched}, found " . count($participantAnswersBySortOrder));
                     // Hata durumunda skorları ve yorumları boşalt
                     $subscaleScores = []; $gsi = null; $interpretation = "Hesaplanamadı"; $processedAnswersForTable = [];
                } else {
                    // 3. Skorları Hesapla (Alt Ölçek ve GSI)
                    $subscaleScores = []; // Alt ölçek puanları [scaleKey => score]
                    $totalAllItemsScore = 0; // Tüm maddelerin toplam skoru
                    $answeredItemCount = 0; // Cevaplanan madde sayısı

                    // Soru metinlerini çek (tablo için)
                    $questionSortOrderToTextMap = [];
                    try {
                        $stmtQText = $pdo->prepare("SELECT sort_order, question AS question_text FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC");
                        $stmtQText->execute([$surveyId]);
                        $questionSortOrderToTextMap = $stmtQText->fetchAll(PDO::FETCH_KEY_PAIR); // [sort_order => question_text]
                    } catch(Exception $e) { error_log("DB result question text fetch error: " . $e->getMessage()); /* Hata set edilebilir */ }


                    // Her alt ölçek için skorları topla ve cevaplanan madde sayısını bul
                    $subscaleAnsweredCounts = array_fill_keys(array_keys($subscales), 0);
                    $subscaleTotalScores = array_fill_keys(array_keys($subscales), 0);

                    foreach ($participantAnswersBySortOrder as $sortOrder => $numericalScore) { // Artık sayısal puan
                         // Toplam GSI skoru için puanı ekle
                         $totalAllItemsScore += $numericalScore;
                         $answeredItemCount++; // Bu madde cevaplanmış sayılır

                         // Alt ölçek skorları için puanı ilgili alt ölçeğe ekle
                         foreach ($subscales as $scaleKey => $items) {
                             if (in_array($sortOrder, $items)) {
                                 $subscaleTotalScores[$scaleKey] += $numericalScore;
                                 $subscaleAnsweredCounts[$scaleKey]++;
                                 break; // İlgili alt ölçeği bulduktan sonra döngüyü kır
                             }
                         }

                         // Detaylı tablo için veriyi hazırla
                         $questionText = $questionSortOrderToTextMap[$sortOrder] ?? 'Soru metni yüklenemedi';
                         $processedAnswersForTable[] = [
                             'madde' => $sortOrder,
                             'question_text' => $questionText,
                             'verilen_cevap' => $scoreToTextMap[$numericalScore] ?? 'Geçersiz Puan', // Sayısal puanı metne çevir
                             'puan' => $numericalScore // Sayısal puan
                         ];
                    }

                    // GSI ve Alt Ölçek Puanlarını Hesapla (Ortalama) ve Geçersizlik Kontrolü
                    $gsi = null; // Başlangıçta GSI'ı null yap
                    if ($answeredItemCount >= $minAnsweredForValidGSI) {
                         $gsi = ($answeredItemCount > 0) ? round($totalAllItemsScore / $answeredItemCount, 2) : 0;
                    } else {
                         error_log("GSI invalid for participant {$participantId} (DB). Answered: {$answeredItemCount}/{$totalExpectedQuestionsFetched}");
                    }


                    $subscaleScores = []; // Hesaplanan alt ölçek puanları
                    foreach ($subscales as $scaleKey => $items) {
                         $itemCount = count($items);
                         $answeredInSubscaleCount = $subscaleAnsweredCounts[$scaleKey];
                         $subscaleTotalScore = $subscaleTotalScores[$scaleKey];

                         // Alt ölçek puanını hesapla (sadece cevaplanan maddelere göre ortalama)
                         $subscaleRawScore = ($answeredInSubscaleCount > 0) ? $subscaleTotalScore / $answeredInSubscaleCount : 0;

                         // Alt ölçek geçersizlik kontrolü
                         $answeredPercentage = ($itemCount > 0) ? ($answeredInSubscaleCount / $itemCount) : 1;

                         if ($answeredPercentage >= $minAnsweredPercentageForSubscale) {
                             $subscaleScores[$scaleKey] = round($subscaleRawScore, 2); // Geçerliyse puanı yuvarla
                         } else {
                             // Alt ölçek geçersizse, puanı null veya özel bir değer yap
                             $subscaleScores[$scaleKey] = null; // Geçersiz olduğunu belirt
                             error_log("Subscale {$scaleKey} invalid for participant {$participantId} (DB). Answered: {$answeredInSubscaleCount}/{$itemCount}");
                         }
                    }

                    // 4. Yorumu Hesapla (GSI geçerliyse genel yorum, alt ölçekler için ayrı yorumlar tabloda)
                    if ($gsi !== null) {
                         $interpretation = "Genel Semptom İndeksi (GSI): " . htmlspecialchars(interpretScore($gsi, $interpretationRanges));
                    } else {
                         $interpretation = "Genel Semptom İndeksi (GSI) hesaplanamadı (Yetersiz cevap sayısı).";
                    }


                } // End if (empty($participantAnswersBySortOrder)) else
            } // End if (!$participantData) else

        } catch (Exception $e) {
             // Veritabanı veya diğer hatalar için genel hata yönetimi
             $error = "Sonuçlar yüklenirken bir hata oluştu: " . $e->getMessage();
             error_log("DB Error view-result-22 (ID: {$participantId}): ".$e->getMessage());
             $participantData = null; // Hata durumunda katılımcı verisini temizle
             $subscaleScores = []; $gsi = null; $interpretation = "Hesaplanamadı"; $processedAnswersForTable = [];
        }
    }

} else {
    // --- SENARYO 2: ID YOK -> SESSION'DAN ÇEK ---
    $dataSource = 'session';
    // take-survey-22.php Session'a 'gsi', 'subscale_scores', 'answers' ([sort_order => original_score]), katılımcı bilgisini kaydediyor.
    $subscaleScores = []; $gsi = null; $interpretation = "Hesaplanamadı"; $participantData = null; $processedAnswersForTable = []; // Başlangıç değerleri

    if (isset($_SESSION['survey_result_data'], $_SESSION['survey_result_data']['survey_id']) && $_SESSION['survey_result_data']['survey_id'] == $surveyId) {
        $sessionData = $_SESSION['survey_result_data'];
        // Gerekli anahtarların varlığını kontrol et
        if (isset($sessionData['gsi'], $sessionData['subscale_scores'], $sessionData['answers'], $sessionData['participant_name']) && is_array($sessionData['subscale_scores']) && is_array($sessionData['answers'])) {
            $gsi = $sessionData['gsi'];
            $subscaleScores = $sessionData['subscale_scores'];
            // Yorumu Session'dan almak yerine burada yeniden hesaplamak daha güvenli olabilir
            // $interpretation = $sessionData['interpretation']; // take-survey-22 Session'a yorum kaydetmiyor

            $participantData = [
                 'name' => $sessionData['participant_name'],
                 'class' => $sessionData['participant_class'] ?? null,
                 'created_at' => date('Y-m-d H:i:s', $sessionData['timestamp'] ?? time()),
                 'admin_id' => null // Session'dan gelen sonuçta admin_id olmaz
            ];
            $survey_title = $testTitleDefault . " Sonucunuz"; $error = null;

            // Detaylı tablo için veriyi hazırla (Session'daki answers [sort_order => original_score] formatında)
            $sessionAnswers = $sessionData['answers'];
            // Toplam beklenen soru sayısı (90)
            $totalExpectedQuestions = 90; // SCL-90'da 90 soru var

            // Soruları DB'den çekerek metinlerini alalım (sort_order'a göre)
            $questionSortOrderToTextMap = [];
             try {
                 $stmtQText = $pdo->prepare("SELECT sort_order, question AS question_text FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC");
                 $stmtQText->execute([$surveyId]);
                 $questionSortOrderToTextMap = $stmtQText->fetchAll(PDO::FETCH_KEY_PAIR); // [sort_order => question_text]
             } catch(Exception $e) { error_log("Session result question text fetch error: " . $e->getMessage()); /* Hata set edilebilir */ }

            $processedAnswerCount = 0;
            foreach ($sessionAnswers as $sortOrder => $originalScore) {
                 $sortOrder_int = (int)$sortOrder;
                 $originalScore_int = (int)$originalScore;

                 // Geçerli sort_order ve orijinal puan (0-4) kontrolü
                 if (($sortOrder_int > 0 && $sortOrder_int <= $totalExpectedQuestions) && ($originalScore_int >= 0 && $originalScore_int <= 4)) {

                     // Soru metnini bul
                     $questionText = $questionSortOrderToTextMap[$sortOrder_int] ?? 'Soru metni yüklenemedi';

                     $processedAnswersForTable[] = [
                         'madde' => $sortOrder_int,
                         'question_text' => $questionText,
                         'verilen_cevap' => $scoreToTextMap[$originalScore_int] ?? 'Geçersiz Puan', // Puanı metne çevir
                         'puan' => $originalScore_int // Sayısal puan
                     ];
                     $processedAnswerCount++;

                 } else {
                      // Beklenmeyen sort_order veya geçersiz orijinal puan gelirse logla
                      error_log("Invalid sort_order ({$sortOrder}) or original_score ({$originalScore}) in session data for survey {$surveyId}");
                 }
            }

            // Session'daki cevap sayısı beklenenle uyuşuyor mu kontrol et (opsiyonel)
             if ($processedAnswerCount < $totalExpectedQuestions) {
                 error_log("view-result-22 Session: Processed fewer valid answers ({$processedAnswerCount}) than expected ({$totalExpectedQuestions}) from session data.");
                 // Bu durumda tablo boşaltılabilir veya bir uyarı gösterilebilir.
                 // $processedAnswersForTable = []; // Tabloyu boşalt
             }

            // Yorumu Hesapla (Session'dan gelen GSI'yı kullanarak)
            if ($gsi !== null) {
                 $interpretation = "Genel Semptom İndeksi (GSI): " . htmlspecialchars(interpretScore($gsi, $interpretationRanges));
            } else {
                 $interpretation = "Genel Semptom İndeksi (GSI) hesaplanamadı (Session verisi eksik veya hatalı).";
            }

            unset($_SESSION['survey_result_data']); // Başarılı okuma sonrası temizle
        } else {
            // Session verisi eksikse hata set et ve logla
            error_log("Incomplete session data for survey 22: " . print_r($sessionData, true));
            $error = "Oturumdan alınan sonuç verisi eksik veya geçersiz (Kod: SESSION_INCOMPLETE).";
            $participantData = null; // Eksikse katılımcı verisi de yok
            $subscaleScores = []; $gsi = null; $interpretation = "Hesaplanamadı"; $processedAnswersForTable = [];
            unset($_SESSION['survey_result_data']); // Eksikse de temizle
        }
    } else {
        // Session verisi tamamen yoksa hata set et
        $error = "Görüntülenecek sonuç bulunamadı veya oturum süresi dolmuş olabilir (Kod: SESSION_MISSING).";
        $participantData = null;
        $subscaleScores = []; $gsi = null; $interpretation = "Hesaplanamadı"; $processedAnswersForTable = [];
    }
}
// --- VERİ KAYNAĞI SONU ---

// Psikometrik logo kontrolü...
if ($psikometrikWebURL) {
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/..');
    $fullPsikoServerPath = rtrim($docRoot, '/') . '/' . ltrim(str_replace('..', '', $psikometrikWebURL), '/');
    if (!file_exists($fullPsikoServerPath)) { $psikometrikWebURL = null; error_log("Psikometrik logo dosyası bulunamadı (view-result-22): " . $fullPsikoServerPath); }
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

        /* Sonuç Özeti ve Tablolar */
        .result-summary { text-align: left; background-color: #e8f5e9; border-color: #c8e6c9; padding: 25px; }
        .result-summary h2 { margin-top: 0; text-align: center; }

        .score-table { width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: 0.95rem; background-color: #fff; }
        .score-table th, .score-table td { border: 1px solid #ddd; padding: 10px 12px; text-align: left; vertical-align: middle; }
        .score-table th { background-color: #dcfce7; font-weight: 600; color: #1f2937; }
        .score-table td:nth-child(1) { font-weight: bold; width: 40%; } /* Alt Ölçek Adı */
        .score-table td:nth-child(2) { width: 20%; text-align: center; font-weight: bold; font-size: 1.1em;} /* Puan */
        .score-table td:nth-child(3) { width: 40%; font-style: italic; color: #374151;} /* Yorum */
        .score-table tr:nth-child(even) { background-color: #f8f9fa; }

        .gsi-summary { text-align: center; margin-top: 20px; font-size: 1.1em; font-weight: bold; color: #1f2937; }
        .gsi-summary span { color: #0b532c; }


        /* Detaylı Cevap Tablosu Stilleri */
        .answers-table { width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: 0.9rem; }
        .answers-table th, .answers-table td { border: 1px solid #ddd; padding: 8px 10px; text-align: left; vertical-align: top; }
        .answers-table th { background-color: #dcfce7; font-weight: 600; color: #1f2937; }
        .answers-table td:nth-child(1) { width: 8%; text-align: center; font-weight: bold; vertical-align: middle;} /* Madde No */
        .answers-table td:nth-child(2) { width: 50%; line-height: 1.4; } /* Soru Metni */
        .answers-table td:nth-child(3) { width: 22%; text-align: center; vertical-align: middle;} /* Verilen Cevap */
        .answers-table td:nth-child(4) { width: 20%; text-align: center; font-weight: bold; vertical-align: middle;} /* Puan */
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
    <?php else: // Katılımcı verisi var ?>

        <h1><?= htmlspecialchars($survey_title) ?></h1>

        <div class="participant-info">
             <h2>Katılımcı Bilgileri</h2>
             <p><strong>Ad Soyad:</strong> <?= htmlspecialchars($participantData['name']) ?></p>
             <p><strong>Sınıf/Bölüm:</strong> <?= htmlspecialchars($participantData['class'] ?? 'Belirtilmemiş') ?></p>
             <p><strong>Test Tarihi:</strong> <?= date('d.m.Y H:i', strtotime($participantData['created_at'])) ?></p>
        </div>

        <div class="result-summary">
             <h2>SCL-90 Sonuçlarınız</h2>

             <?php if (!empty($subscaleScores)): ?>
                 <table class="score-table">
                     <thead>
                         <tr>
                             <th>Alt Ölçek</th>
                             <th>Puan (Ortalama)</th>
                             <th>Yorum</th>
                         </tr>
                     </thead>
                     <tbody>
                         <?php
                         // Alt ölçek adlarının daha açıklayıcı olması için bir harita
                         $subscaleNames = [
                             'SOM' => 'Somatizasyon',
                             'O-C' => 'Obsesif-Kompulsif',
                             'INT' => 'Kişilerarası Duyarlık',
                             'DEP' => 'Depresyon',
                             'ANX' => 'Anksiyete',
                             'HOS' => 'Öfke ve Düşmanlık',
                             'PHOB' => 'Fobik Anksiyete',
                             'PAR' => 'Paranoid Düşünce',
                             'PSY' => 'Psikotizm',
                             'EK' => 'Ek Skala' // Ek Skala için de yorum gösterilebilir
                         ];
                         ?>
                         <?php foreach($subscaleScores as $scaleKey => $score): ?>
                         <tr>
                             <td><?= htmlspecialchars($subscaleNames[$scaleKey] ?? $scaleKey) ?></td>
                             <td><?= ($score !== null) ? htmlspecialchars(sprintf("%.2f", $score)) : 'Geçersiz' ?></td> <?php // Puanı 2 ondalık basamakla göster ?>
                             <td><?= ($score !== null) ? htmlspecialchars(interpretScore($score, $interpretationRanges)) : 'Yetersiz Cevap' ?></td>
                         </tr>
                         <?php endforeach; ?>
                     </tbody>
                 </table>
             <?php else: ?>
                 <div class="error-box">Alt ölçek puanları hesaplanamadı (Yetersiz veya hatalı cevaplar).</div>
             <?php endif; ?>

             <?php if ($gsi !== null): ?>
                 <p class="gsi-summary">
                     Genel Semptom İndeksi (GSI): <span><?= htmlspecialchars(sprintf("%.2f", $gsi)) ?></span> (Yorum: <?= htmlspecialchars(interpretScore($gsi, $interpretationRanges)) ?>)
                 </p>
             <?php else: ?>
                  <div class="error-box" style="margin-top: 20px;">Genel Semptom İndeksi (GSI) hesaplanamadı (Yetersiz cevap sayısı).</div>
             <?php endif; ?>

             <p style="font-size: 0.85em; margin-top: 25px; text-align: center; color: #475569;">
                 * Bu sonuçlar bir tarama testine aittir ve klinik tanı yerine geçmez. Yüksek puanlar, ilgili alanda detaylı değerlendirme gerektirebilir.
             </p>
        </div>

        <h2>Detaylı Cevaplarınız</h2>
         <?php if (!empty($processedAnswersForTable)): ?>
             <table class="answers-table">
                 <thead>
                     <tr>
                         <th>Madde No</th>
                         <th>Soru</th>
                         <th>Verilen Cevap</th>
                         <th>Puan (0-4)</th>
                     </tr>
                 </thead>
                 <tbody>
                     <?php foreach ($processedAnswersForTable as $item): ?>
                     <tr>
                         <td><?= htmlspecialchars($item['madde']) ?></td>
                         <td><?= htmlspecialchars($item['question_text']) ?></td>
                         <td><?= htmlspecialchars($item['verilen_cevap']) ?></td>
                         <td><?= htmlspecialchars($item['puan']) ?></td>
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
