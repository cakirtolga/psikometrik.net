<?php
// view-result-28.php (Öğrenci Rehberlik İhtiyacı Belirleme Anketi - RİBA Öğretmen Sonuçları)

// --- Hata Raporlama ---
ini_set('display_errors', 1);
error_reporting(E_ALL);
// ---------------------------

session_start(); // Session GEREKLİ

// --- Veritabanı Bağlantısı ---
require '../src/config.php'; // src/config.php dosyasının yolu projenizin yapısına göre değişebilir
if (!isset($pdo) || !$pdo instanceof PDO) {
    error_log("CRITICAL: PDO connection object (\$pdo) not created or found in src/config.php");
    header('Content-Type: text/html; charset=utf-8');
    die('<div style="border: 1px solid red; padding: 10px; margin: 10px; background-color: #ffebeb; color: red; font-family: sans-serif;"><b>Kritik Hata:</b> Veritabanı bağlantısı kurulamadı (src/config.php).</div>');
}
// --------------------------------

// --- Konfigürasyon ---
$surveyId = 28; // Anket ID'si
$testTitleDefault = "Öğrenci Rehberlik İhtiyacı Belirleme Anketi (RİBA) (ÖĞRETMEN)";
$pageTitle = $testTitleDefault . " Sonuçları";

// --- Değişkenler ---
$participantId = null;
$participantData = null;
$survey_title = $pageTitle;
$error = null;
$dataSource = null;
$processedAnswersForTable = []; // Detaylı cevap tablosu için
$questionScores = []; // Her sorunun aldığı puan [sort_order => score]
$evaluationText = "Değerlendirme yapılamadı."; // Sonuç değerlendirme metni


// --- Logo URL ---
$institutionWebURL = null;
$psikometrikWebURL = '/assets/Psikometrik.png'; // Logo yolunu kontrol edin

// --- Sabit Veriler (Anket 28 - RİBA Öğretmen) ---
// Cevap Seçenekleri ve Puanları (PDF'e göre)
$optionsList = ["Hayır Kesinlikle Katılmıyorum", "Hayır Katılmıyorum", "Kararsızım", "Evet Katılıyorum", "Evet Kesinlikle Katılıyorum"];
$textToScoreMap = [
    "Hayır Kesinlikle Katılmıyorum" => 1,
    "Hayır Katılmıyorum" => 2,
    "Kararsızım" => 3,
    "Evet Katılıyorum" => 4,
    "Evet Kesinlikle Katılıyorum" => 5
];

// Gelişim Alanları (Boyutlar) ve Soru Aralıkları (sort_order'a göre)
$dimensions = [
    'Kişisel-sosyal rehberlik' => range(1, 24),
    'Mesleki rehberlik' => range(25, 29),
    'Eğitsel rehberlik' => range(30, 42)
];

// --- Gelişim Alanı Skorlarını Başlangıçta Sıfırla ---
// Grafik ve tablo için boyut isimlerinin her zaman mevcut olmasını sağlar
$dimensionScores = [];
foreach ($dimensions as $dimName => $qNumbers) {
    $dimensionScores[$dimName] = [
        'total' => 0,
        'average' => 0,
        'count' => count($qNumbers) // Boyuttaki toplam soru sayısı
    ];
}
// ----------------------------------------------------


// --- VERİ KAYNAĞINI BELİRLE VE VERİYİ AL ---
if (isset($_GET['id'])) {
    // --- SENARYO 1: ID VAR -> VERITABANINDAN ÇEK ---
    $dataSource = 'db';
    $participantId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$participantId) {
        $error = "Geçersiz katılımcı ID'si.";
        error_log("Invalid participant ID received: " . ($_GET['id'] ?? 'N/A'));
    } else {
        try {
            // 1. Katılımcı ve Anket Bilgileri
            $stmt_participant = $pdo->prepare(" SELECT sp.*, s.title as survey_title, u.institution_logo_path FROM survey_participants sp LEFT JOIN surveys s ON sp.survey_id = s.id LEFT JOIN users u ON sp.admin_id = u.id WHERE sp.id = ? AND sp.survey_id = ? ");
            $stmt_participant->execute([$participantId, $surveyId]);
            $participantData = $stmt_participant->fetch(PDO::FETCH_ASSOC);

            if (!$participantData) {
                $error = "Belirtilen ID ({$participantId}) için Anket {$surveyId} sonucu bulunamadı.";
                error_log("Participant not found for view-result-28 (ID: {$participantId}, Survey: {$surveyId})");
            } else {
                $survey_title = !empty($participantData['survey_title']) ? htmlspecialchars($participantData['survey_title']) . " Sonuçları" : $testTitleDefault . " Sonuçları";
                // Logo URL Ayarla...
                if ($dataSource == 'db' && !empty($participantData['admin_id']) && !empty($participantData['institution_logo_path'])) {
                   $rawInstitutionPathFromDB = $participantData['institution_logo_path'];
                   $cleanRelativePath = ltrim(str_replace('..', '', $rawInstitutionPathFromDB), '/');
                   $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/..');
                   $fullServerPath = rtrim($docRoot, '/') . '/' . $cleanRelativePath;
                   if (file_exists($fullServerPath)) { $institutionWebURL = '/' . $cleanRelativePath; }
                   else { error_log("Kurum logosu dosyası bulunamadı (view-result-28): " . $fullServerPath); }
                }

                // 2. Cevapları Çek (question_id artık sort_order, answer_text cevap metni)
                $stmt_answers = $pdo->prepare("SELECT sa.question_id AS sort_order, sa.answer_text, sq.question AS question_text FROM survey_answers sa JOIN survey_questions sq ON sa.question_id = sq.sort_order AND sa.survey_id = sq.survey_id WHERE sa.participant_id = ? AND sa.survey_id = ? ORDER BY sa.question_id ASC");
                $stmt_answers->execute([$participantId, $surveyId]);
                $fetched_answers = $stmt_answers->fetchAll(PDO::FETCH_ASSOC);

                $processedAnswerCount = 0; // İşlenen geçerli cevap sayısı

                foreach($fetched_answers as $ans) {
                    $sortOrder = (int)$ans['sort_order'];
                    $answerText = trim($ans['answer_text'] ?? '');
                    $numericalScore = $textToScoreMap[$answerText] ?? null;

                     if ($numericalScore !== null) { // Sadece geçerli cevapları al
                        $questionScores[$sortOrder] = $numericalScore; // Puanı kaydet
                        $processedAnswerCount++; // Geçerli cevap sayısını artır

                        // Detaylı tablo için veriyi hazırla
                        $processedAnswersForTable[] = [
                            'madde' => $sortOrder,
                            'question_text' => $ans['question_text'] ?? 'Soru metni yüklenemedi',
                            'verilen_cevap' => $answerText,
                            'puan' => $numericalScore // Puanı tabloya ekle
                        ];
                     } else {
                         error_log("Invalid answer_text '{$answerText}' found in DB for participant {$participantId}, survey {$surveyId}, sort_order {$sortOrder}");
                     }
                }

                // Toplam beklenen soru sayısı (42)
                $stmt_total_questions = $pdo->prepare("SELECT COUNT(*) FROM survey_questions WHERE survey_id = ?");
                $stmt_total_questions->execute([$surveyId]);
                $totalExpectedQuestionsFetched = (int)$stmt_total_questions->fetchColumn();

                // Tüm sorular cevaplanmış mı control et (42 soru bekleniyor)
                if ($processedAnswerCount < $totalExpectedQuestionsFetched) {
                     $error = "Katılımcı cevapları veritabanında eksik (ID: {$participantId}, Anket: {$surveyId}). Tamamlanan: {$processedAnswerCount}/{$totalExpectedQuestionsFetched}.";
                     error_log("Answers incomplete for participant {$participantId} in survey {$surveyId}. Expected {$totalExpectedQuestionsFetched}, found " . $processedAnswerCount);
                     // Eksik cevap durumunda dimensionScores sıfır kalacak (başlangıçta sıfırlandı)
                     // processedAnswersForTable ve questionScores eksik veriyi yansıtacak
                } else {
                    // 3. Gelişim Alanlarına Göre Toplam ve Ortalama Skorları Hesapla
                    // Başlangıçta sıfırlanan dimensionScores üzerine ekleme yapılıyor
                    foreach ($dimensions as $dimName => $qNumbers) {
                        $totalScore = 0;
                        $answeredCountInDimension = 0;
                        foreach ($qNumbers as $qNum) {
                            if (isset($questionScores[$qNum])) {
                                $totalScore += $questionScores[$qNum];
                                $answeredCountInDimension++;
                            }
                        }
                        // Hesaplanan skorları dimensionScores'a yaz
                        $dimensionScores[$dimName]['total'] = $totalScore;
                        $dimensionScores[$dimName]['answered_count'] = $answeredCountInDimension; // Bu boyutta cevaplanan soru sayısı
                        // Ortalama hesaplarken sadece cevaplanan soru sayısını kullan
                        $dimensionScores[$dimName]['average'] = ($answeredCountInDimension > 0) ? $totalScore / $answeredCountInDimension : 0;
                    }

                    // 4. Değerlendirme Metni Oluştur
                    $evaluationText = "Bu anket sonuçları, öğrencilerinizin rehberlik ihtiyacına yönelik öğretmen algısını yansıtmaktadır. ";
                     // Ortalama puanlara göre en yüksek alanı bul (Sadece geçerli skorlar varsa)
                     $highestAverage = 0;
                     $highestDimension = null;
                     $validDimensionScoresExist = false;
                     foreach ($dimensionScores as $dimName => $scores) {
                         if ($scores['answered_count'] > 0) { // Sadece en az bir soru cevaplanmış boyutları dikkate al
                             $validDimensionScoresExist = true;
                             if ($scores['average'] > $highestAverage) {
                                 $highestAverage = $scores['average'];
                                 $highestDimension = $dimName;
                             }
                         }
                     }

                     if ($validDimensionScoresExist) {
                         if ($highestDimension !== null && $highestAverage > 3) { // Ortalama 3'ün üzerindeyse anlamlı bir ihtiyaç algısı var diyebiliriz
                             $evaluationText .= "Sonuçlara göre, öğretmen algısında en çok ihtiyaç duyulan alan '{$highestDimension}' olarak öne çıkmaktadır. ";
                             $evaluationText .= "Bu alana yönelik okul rehberlik programında çalışmaların önceliklendirilmesi faydalı olabilir.";
                         } else {
                              $evaluationText .= "Genel olarak tüm alanlarda orta düzeyde veya düşük bir rehberlik ihtiyacı algısı belirlenmiştir.";
                         }
                     } else {
                         $evaluationText .= "Yeterli anket cevabı bulunamadığı için gelişim alanları değerlendirmesi yapılamamıştır.";
                     }

                } // End if ($processedAnswerCount < $totalExpectedQuestionsFetched) else

            } // End if (!$participantData) else

        } catch (Exception $e) {
            $error = "Sonuçlar veritabanından yüklenirken bir hata oluştu: " . $e->getMessage();
            error_log("DB Error view-result-28 (ID: {$participantId}): " . $e->getMessage());
            $participantData = null; // Hata durumunda katılımcı verisini temizle
            // dimensionScores başlangıçtaki sıfır değerleriyle kalacak
            $questionScores = [];
            $processedAnswersForTable = [];
            $evaluationText = "Veritabanı hatası nedeniyle değerlendirme yapılamadı.";
        }

    }

} else {
    // --- SENARYO 2: ID YOK -> SESSION'DAN ÇEK ---
    $dataSource = 'session';
    $participantData = null;
    $processedAnswersForTable = []; // Başlangıç değerleri
    $evaluationText = "Değerlendirme yapılamadı.";

    if (isset($_SESSION['survey_result_data'], $_SESSION['survey_result_data']['survey_id']) && $_SESSION['survey_result_data']['survey_id'] == $surveyId) {
        $sessionData = $_SESSION['survey_result_data'];
        // Gerekli anahtarların varlığını kontrol et
        if (isset($sessionData['answers'], $sessionData['teacher_branch'], $sessionData['teacher_gender']) && is_array($sessionData['answers'])) { // Öğretmen anketine özel kontrol

            $participantData = [
                'name' => $sessionData['teacher_name'] ?? 'Belirtilmemiş', // Öğretmen adı (opsiyonel)
                'teacher_branch' => $sessionData['teacher_branch'], // Branş
                'teacher_gender' => $sessionData['teacher_gender'], // Cinsiyet
                'created_at' => date('Y-m-d H:i:s', $sessionData['timestamp'] ?? time()),
                'admin_id' => null // Session'dan gelen sonuçta admin_id olmaz
            ];
            $survey_title = $testTitleDefault . " Sonucunuz";
            $error = null;

            $sessionAnswers = $sessionData['answers'];
            $processedAnswerCount = 0; // İşlenen geçerli cevap sayısı
            $questionScores = []; // [sort_order => score (1-5)]

            // Soruları DB'den çekerek metinlerini alalım (tablo için)
            $questionSortOrderToTextMap = [];
             try {
                 $stmtQText = $pdo->prepare("SELECT sort_order, question AS question_text FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC");
                 $stmtQText->execute([$surveyId]);
                 $questionSortOrderToTextMap = $stmtQText->fetchAll(PDO::FETCH_KEY_PAIR); // [sort_order => question_text]
             } catch(Exception $e) { error_log("Session result question text fetch error (S{$surveyId}): " . $e->getMessage()); /* Hata set edilebilir */ }


            // Cevapları puana çevir (1-5) ve skorları kaydet
            foreach ($sessionAnswers as $sortOrder => $answerText) {
                 $sortOrder_int = (int)$sortOrder;
                 $answerText_str = trim($answerText);
                 $numericalScore = $textToScoreMap[$answerText_str] ?? null;

                 if ($numericalScore !== null) {
                     $questionScores[$sortOrder_int] = $numericalScore; // Puanı kaydet
                     $processedAnswerCount++; // Geçerli cevap sayısını artır

                     // Detaylı tablo için veriyi hazırla
                     $questionText = $questionSortOrderToTextMap[$sortOrder_int] ?? 'Soru metni yüklenemedi';
                     $processedAnswersForTable[] = [
                         'madde' => $sortOrder_int,
                         'question_text' => $questionText,
                         'verilen_cevap' => $answerText_str,
                         'puan' => $numericalScore // Puanı tabloya ekle
                     ];
                 } else {
                      error_log("Invalid answer text '{$answerText_str}' found in session data for survey {$surveyId}, sort_order {$sortOrder_int}");
                 }
            }

            // Toplam beklenen soru sayısı (42)
            $stmt_total_questions = $pdo->prepare("SELECT COUNT(*) FROM survey_questions WHERE survey_id = ?");
            $stmt_total_questions->execute([$surveyId]);
            $totalExpectedQuestionsFetched = (int)$stmt_total_questions->fetchColumn();

            if ($processedAnswerCount < $totalExpectedQuestionsFetched) {
                 error_log("view-result-28 Session: Processed fewer valid answers ({$processedAnswerCount}) than expected ({$totalExpectedQuestionsFetched}) from session data.");
                 // Eksik cevap durumunda dimensionScores başlangıçtaki sıfır değerleriyle kalacak
                 // processedAnswersForTable ve questionScores eksik veriyi yansıtacak
            }

            // 3. Gelişim Alanlarına Göre Toplam ve Ortalama Skorları Hesapla
            // Başlangıçta sıfırlanan dimensionScores üzerine ekleme yapılıyor
            foreach ($dimensions as $dimName => $qNumbers) {
                $totalScore = 0;
                $answeredCountInDimension = 0;
                foreach ($qNumbers as $qNum) {
                    if (isset($questionScores[$qNum])) {
                        $totalScore += $questionScores[$qNum];
                        $answeredCountInDimension++;
                    }
                }
                 // Hesaplanan skorları dimensionScores'a yaz
                $dimensionScores[$dimName]['total'] = $totalScore;
                $dimensionScores[$dimName]['answered_count'] = $answeredCountInDimension; // Bu boyutta cevaplanan soru sayısı
                 // Ortalama hesaplarken sadece cevaplanan soru sayısını kullan
                $dimensionScores[$dimName]['average'] = ($answeredCountInDimension > 0) ? $totalScore / $answeredCountInDimension : 0;
            }


             // 4. Değerlendirme Metni Oluştur (Session verisi için)
             $evaluationText = "Bu anket sonuçları, öğrencilerinizin rehberlik ihtiyacına yönelik öğretmen algısını yansıtmaktadır. ";
              // Ortalama puanlara göre en yüksek alanı bul (Sadece geçerli skorlar varsa)
              $highestAverage = 0;
              $highestDimension = null;
              $validDimensionScoresExist = false;
              foreach ($dimensionScores as $dimName => $scores) {
                  if ($scores['answered_count'] > 0) { // Sadece en az bir soru cevaplanmış boyutları dikkate al
                      $validDimensionScoresExist = true;
                      if ($scores['average'] > $highestAverage) {
                          $highestAverage = $scores['average'];
                          $highestDimension = $dimName;
                      }
                  }
              }

              if ($validDimensionScoresExist) {
                  if ($highestDimension !== null && $highestAverage > 3) { // Ortalama 3'ün üzerindeyse anlamlı bir ihtiyaç algısı var diyebiliriz
                      $evaluationText .= "Sonuçlara göre, öğretmen algısında en çok ihtiyaç duyulan alan '{$highestDimension}' olarak öne çıkmaktadır. ";
                      $evaluationText .= "Bu alana yönelik okul rehberlik programında çalışmaların önceliklendirilmesi faydalı olabilir.";
                  } else {
                       $evaluationText .= "Genel olarak tüm alanlarda orta düzeyde veya düşük bir rehberlik ihtiyacı algısı belirlenmiştir.";
                  }
              } else {
                  $evaluationText .= "Yeterli anket cevabı bulunamadığı için gelişim alanları değerlendirmesi yapılamamıştır.";
              }


            unset($_SESSION['survey_result_data']); // Başarılı okuma sonrası temizle
        } else {
            // Session verisi eksikse hata set et ve logla
            error_log("Incomplete session data for survey 28: " . print_r($sessionData, true));
            $error = "Oturumdan alınan sonuç verisi eksik veya geçersiz (Kod: SESSION_INCOMPLETE).";
            $participantData = null; // Eksikse katılımcı verisi de yok
            // dimensionScores başlangıçtaki sıfır değerleriyle kalacak
            $questionScores = [];
            $processedAnswersForTable = [];
            $evaluationText = "Oturum verisi hatası nedeniyle değerlendirme yapılamadı.";
            unset($_SESSION['survey_result_data']); // Eksikse de temizle
        }
    } else {
        // Session verisi tamamen yoksa hata set et
        $error = "Görüntülenecek sonuç bulunamadı veya oturum süresi dolmuş olabilir (Kod: SESSION_MISSING).";
        $participantData = null;
        // dimensionScores başlangıçtaki sıfır değerleriyle kalacak
        $questionScores = [];
        $processedAnswersForTable = [];
        $evaluationText = "Sonuç verisi bulunamadı.";
    }
}
// --- VERİ KAYNAĞI SONU ---

// Psikometrik logo kontrolü...
if ($psikometrikWebURL) {
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/..');
    $fullPsikoServerPath = rtrim($docRoot, '/') . '/' . ltrim(str_replace('..', '', $psikometrikWebURL), '/');
    if (!file_exists($fullPsikoServerPath)) { $psikometrikWebURL = null; error_log("Psikometrik logo dosyası bulunamadı (view-result-28): " . $fullPsikometrikServerPath); }
}

// Header gönder...
if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}
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
        .participant-info strong { font-weight: 600; color: #374151; min-width: 150px; display: inline-block; } /* Genişlik artırıldı */

        /* Grafik Alanı Stilleri */
        .chart-container {
            width: 90%; /* Konteyner genişliği */
            max-width: 700px; /* Maksimum genişlik */
            margin: 20px auto; /* Ortala ve üst/alt boşluk ver */
            padding: 15px;
            background-color: #ffffff; /* Grafik alanı arka planı */
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            height: 350px; /* Grafik konteynerine sabit bir yükseklik */
            display: flex; /* İçeriği ortalamak için flexbox kullan */
            justify-content: center; /* Yatayda ortala */
            align-items: center; /* Dikeyda ortala */
        }
         /* Grafik başlığı */
        .chart-container h3 {
            text-align: center;
            margin-top: 0;
            margin-bottom: 15px;
            color: #1f2937;
            font-size: 1.2rem;
            font-weight: 600;
        }
         /* Canvas elementinin kendisi */
        .chart-container canvas {
            max-width: 100%; /* Konteynerin içine sığmasını sağla */
            max-height: 100%; /* Konteynerin içine sığmasını sağla */
        }


        /* Sonuç Özeti (Gelişim Alanlarına Göre Skorlar) */
        .result-summary { text-align: left; background-color: #e8f5e9; border-color: #c8e6c9; padding: 25px; }
        .result-summary h2 { margin-top: 0; text-align: center; }

        .score-table { width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: 0.95rem; background-color: #fff; }
        .score-table th, .score-table td { border: 1px solid #ddd; padding: 10px 12px; text-align: left; vertical-align: middle; }
        .score-table th { background-color: #dcfce7; font-weight: 600; color: #1f2937; }
        .score-table td:nth-child(1) { font-weight: bold; width: 50%; } /* Gelişim Alanı Adı */
        .score-table td:nth-child(2) { width: 25%; text-align: center;} /* Toplam Puan */
        .score-table td:nth-child(3) { width: 25%; text-align: center; font-weight: bold;} /* Ortalama Puan */
        .score-table tr:nth-child(even) { background-color: #f8f9fa; }

        /* Değerlendirme Metni Stili */
        .evaluation-text {
             margin-top: 2rem;
             padding: 15px;
             background-color: #fff;
             border: 1px solid #e0e0e0;
             border-radius: 8px;
             font-size: 1em;
             color: #475569;
             line-height: 1.5;
        }
         .evaluation-text h3 {
             font-size: 1.1rem;
             color: #1f2937;
             margin-top: 0;
             margin-bottom: 0.8rem;
             border-bottom: 1px dashed #ccc;
             padding-bottom: 0.3rem;
         }


        /* Detaylı Cevap Tablosu Stilleri */
        .answers-table { width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: 0.9rem; }
        .answers-table th, .answers-table td { border: 1px solid #ddd; padding: 8px 10px; text-align: left; vertical-align: top; }
        .answers-table th { background-color: #dcfce7; font-weight: 600; color: #1f2937; }
        .answers-table td:nth-child(1) { width: 8%; text-align: center; font-weight: bold; vertical-align: middle;} /* Madde No */
        .answers-table td:nth-child(2) { width: 52%; line-height: 1.4; } /* Soru Metni */
        .answers-table td:nth-child(3) { width: 25%; text-align: center; vertical-align: middle;} /* Verilen Cevap */
        .answers-table td:nth-child(4) { width: 15%; text-align: center; font-weight: bold; vertical-align: middle;} /* Puan */
        .answers-table tr:nth-child(even) { background-color: #f8f9fa; }


        .error-box { color: #b91c1c; background-color: #fee2e2; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; border: 1px solid #fca5a5; font-weight: 500; text-align: left; font-size: 0.9em;}
        .error-box b { font-weight: bold; }

        .action-buttons { display: flex; justify-content: center; gap: 1rem; margin-top: 2.5rem; padding-top: 1.5rem; border-top: 1px solid #e0e0e0; }
        .action-button { display: inline-flex; align-items: center; padding: 10px 20px; font-size: 1rem; font-weight: 600; color: white; background-color: #15803d; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; transition: background-color 0.2s ease, box-shadow 0.2s ease; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .action-button:hover { background-color: #0b532c; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }

        /* Print Styles */
        @media print {
            body {
                background-color: #fff;
                padding: 0;
                margin: 20mm; /* Kenar boşlukları eklendi */
                font-size: 10pt;
            }

            .page-header {
                padding: 10px 0;
                border-bottom: 1px solid #000;
                box-shadow: none;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .page-header img { max-height: 40px; }
            .page-header span { font-size: 10pt; }

            .container {
                box-shadow: none;
                margin: 0; /* Body'ye margin verildiği için container margin sıfırlandı */
                padding: 0; /* Body'ye padding verildiği için container padding sıfırlandı */
                max-width: 100%;
            }

            h1 {
                font-size: 14pt;
                border-bottom: 1px solid #000;
                padding-bottom: 5px;
                margin-bottom: 15px;
                color: #000;
            }

            h2 {
                font-size: 12pt;
                border-bottom: 1px solid #ccc;
                padding-bottom: 3px;
                margin-top: 15px;
                margin-bottom: 8px;
                color: #000;
            }

            .participant-info {
                margin-bottom: 15px;
                padding: 10px;
                border: 1px solid #ddd;
                background-color: #f9f9f9;
                font-size: 10pt;
            }
            .participant-info p { margin: 2px 0; }
            .participant-info strong { min-width: 100px; } /* Yazdırmada genişlik ayarı */


            .result-summary {
                 margin-bottom: 15px;
                 padding: 10px;
                 border: 1px solid #ddd;
                 background-color: #f9f9f9;
            }
            .result-summary h2 { margin-top: 0; }

            .score-table {
                 margin-top: 10px;
                 font-size: 10pt;
                 box-shadow: none;
            }
            .score-table th, .score-table td { border: 1px solid #000; padding: 5px 8px; }
            .score-table th { background-color: #eee; color: #000; }
            .score-table td { font-weight: normal; } /* Yazdırmada kalınlığı azalt */
            .score-table td:nth-child(1) { font-weight: bold; } /* Alan adı kalın kalsın */

             .evaluation-text {
                 margin-top: 15px;
                 padding: 10px;
                 border: 1px solid #ddd;
                 background-color: #f9f9f9;
                 font-size: 10pt;
                 line-height: 1.4;
             }
             .evaluation-text h3 {
                 font-size: 11pt;
                 color: #000;
                 margin-top: 0;
                 margin-bottom: 5px;
                 border-bottom: 1px dashed #ccc;
                 padding-bottom: 2px;
             }

            .answers-table {
                 margin-top: 15px;
                 font-size: 9pt;
            }
            .answers-table th, .answers-table td { border: 1px solid #000; padding: 5px 8px; }
            .answers-table th { background-color: #eee; color: #000; }
            .answers-table td:nth-child(1) { font-weight: normal; }
            .answers-table td:nth-child(3) { font-weight: normal; }
            .answers-table td:nth-child(4) { font-weight: normal; }

            .chart-container {
                width: 80%;
                height: 250px;
                margin: 15px auto;
                padding: 10px;
                border: 1px solid #ddd;
                box-shadow: none;
                page-break-inside: avoid;
            }
            .chart-container canvas { max-width: 100%; max-height: 100%; }

            .no-print { display: none; }
        }
    </style>
     <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
    <?php elseif (!$participantData && empty($dimensionScores) && empty($processedAnswersForTable)): ?>
         <?php // Eğer katılımcı verisi yoksa VE skorlar/cevaplar boşsa genel hata göster ?>
         <h1>Sonuçlar Yüklenemedi</h1>
         <div class="error-box">Görüntülenecek katılımcı verisi bulunamadı veya sonuçlar işlenemedi.</div>
    <?php else: // Katılımcı verisi var veya en azından boyut isimleri mevcut ?>

        <h1><?= htmlspecialchars($survey_title) ?></h1>

        <?php if ($participantData): // Katılımcı bilgileri varsa göster ?>
        <div class="participant-info">
             <h2>Katılımcı Bilgileri</h2>
             <?php // Katılımcı bilgileri, Session'dan geliyorsa ayrı alanlar olarak gösterilir ?>
             <?php if ($dataSource == 'session' && isset($participantData['teacher_branch'])): ?>
                 <p><strong>Ad Soyad:</strong> <?= htmlspecialchars($participantData['name']) ?></p> <?php // Öğretmen adı (opsiyonel) ?>
                 <p><strong>Branş:</strong> <?= htmlspecialchars($participantData['teacher_branch']) ?></p> <?php // Branş ?>
                 <p><strong>Cinsiyet:</strong> <?= htmlspecialchars($participantData['teacher_gender']) ?></p> <?php // Cinsiyet ?>
             <? else: ?>
                 <?php // DB'den geliyorsa, bilgiler 'name' sütununda birleşik olabilir ?>
                 <p><strong>Öğretmen Bilgileri:</strong> <?= htmlspecialchars($participantData['name']) ?></p>
                 <?php /* Branş ve Cinsiyet DB'de ayrı sütunlarda değilse burada gösterilemez */ ?>
             <? endif; ?>
             <p><strong>Test Tarihi:</strong> <?= date('d.m.Y H:i', strtotime($participantData['created_at'])) ?></p>
        </div>
        <? endif; ?>


        <?php // Grafik Alanı ?>
        <?php // dimensionScores her zaman boyut isimlerini içerdiği için bu blok her zaman çalışır ?>
        <div class="chart-container">
             <h3></h3>
             <canvas id="ribaTeacherChart"></canvas>
        </div>


        <div class="result-summary">
             <h2>Gelişim Alanları Sonuçları</h2>

             <?php if (!empty($dimensionScores)): ?>
                 <table class="score-table">
                     <thead>
                         <tr>
                             <th>Gelişim Alanı</th>
                             <th>Toplam Puan</th>
                             <th>Ortalama Puan (1-5)</th>
                         </tr>
                     </thead>
                     <tbody>
                         <?php foreach($dimensions as $dimName => $qNumbers): // Gelişim alanlarını döngüye al ?>
                            <?php $scores = $dimensionScores[$dimName] ?? ['total' => 0, 'average' => 0, 'answered_count' => 0]; ?>
                         <tr>
                             <td><?= htmlspecialchars($dimName) ?></td> <?php // Gelişim alanı adı ?>
                             <td><?= htmlspecialchars($scores['total']) ?></td>
                             <td><?= htmlspecialchars(sprintf("%.2f", $scores['average'])) ?></td> <?php // Ortalama puanı 2 ondalık basamakla göster ?>
                         </tr>
                         <?php endforeach; ?>
                     </tbody>
                 </table>

                 <?php // Sonuç Değerlendirme Metni ?>
                 <div class="evaluation-text">
                      <h3>Sonuç Değerlendirmesi</h3>
                      <p><?= htmlspecialchars($evaluationText) ?></p>
                 </div>

             <?php else: ?>
                 <div class="error-box">Gelişim alanlarına göre puanlar hesaplanamadı.</div>
             <?php endif; ?>

        </div>

        <h2>Detaylı Cevaplarınız</h2>
         <?php if (!empty($processedAnswersForTable)): ?>
             <table class="answers-table">
                 <thead>
                     <tr>
                         <th>Madde No</th>
                         <th>Soru</th>
                         <th>Verilen Cevap</th>
                         <th>Puan (1-5)</th> <?php // Puan 1-5 olacak ?>
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
             <div class="error-box">Detaylı cevaplar görüntülenemiyor veya eksik.</div>
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

<script>
    // Grafik çizimi için JavaScript
    // dimensionScores PHP'de her zaman boyut isimlerini içerecek şekilde başlatıldığı için
    // script bloğu her zaman çalışır, ancak data boşsa grafik boş çizilir.
    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('ribaTeacherChart');

        // PHP'den gelen gelişim alanı isimlerini ve ortalama skorlarını al
        const dimensionScores = <?= json_encode($dimensionScores) ?>;
        const dimensionNames = Object.keys(dimensionScores);
        // Ortalama puanları alırken, eğer answered_count 0 ise ortalama 0 olacaktır.
        const dimensionAverages = dimensionNames.map(dimName => dimensionScores[dimName].average);

        // Konsol logları ile veriyi kontrol et
        console.log('Dimension Scores from PHP:', dimensionScores);
        console.log('Dimension Names for Chart:', dimensionNames);
        console.log('Dimension Averages for Chart:', dimensionAverages);


        if (ctx && dimensionNames.length > 0) { // ctx kontrolü ve boyut isimlerinin varlığı kontrol edildi
             new Chart(ctx, {
                 type: 'bar', // Çubuk grafik
                 data: {
                     labels: dimensionNames, // Gelişim alanı adları
                     datasets: [{
                         label: 'Ortalama Puan',
                         data: dimensionAverages, // Ortalama puanlar (0 olabilir)
                         backgroundColor: [ // Her çubuk için farklı renk
                             'rgba(75, 192, 192, 0.7)', // Kişisel-sosyal (Turkuaz)
                             'rgba(153, 102, 255, 0.7)', // Mesleki (Mor)
                             'rgba(255, 159, 64, 0.7)'  // Eğitsel (Turuncu)
                         ],
                         borderColor: [
                             'rgba(75, 192, 192, 1)',
                             'rgba(153, 102, 255, 1)',
                             'rgba(255, 159, 64, 1)'
                         ],
                         borderWidth: 1
                     }]
                 },
                 options: {
                     indexAxis: 'y', // Eksenleri değiştirerek yatay çubuk grafik oluştur
                     responsive: true,
                     maintainAspectRatio: false, // Konteyner boyutuna uyum sağlaması için
                     plugins: {
                         legend: {
                             display: false // Legend'ı gizle (Tek veri seti var)
                         },
                         title: {
                             display: false, // Ana başlık (h3 ile zaten var)
                         },
                         tooltip: { // Tooltip ayarları
                             callbacks: {
                                 label: function(context) {
                                     const label = context.label || ''; // Gelişim alanı adı
                                     const average = context.raw; // Ortalama puan
                                     return `${label}: ${average.toFixed(2)}`; // 2 ondalık basamakla göster
                                 }
                             }
                         }
                     },
                     scales: {
                         x: { // X ekseni şimdi ortalama puanlar
                             beginAtZero: true,
                             max: 5, // Maksimum puan 5
                             title: {
                                 display: true,
                                 text: 'Ortalama Puan (1-5)'
                             },
                             ticks: {
                                 stepSize: 1 // X ekseninde 1'er artış
                             }
                         },
                         y: { // Y ekseni şimdi gelişim alanı adları
                             title: {
                                 display: true,
                                 text: 'Gelişim Alanları'
                             },
                             // Yatay grafikte y ekseni etiketleri dikey yazdırmaya gerek duymaz
                             ticks: {
                                autoSkip: false,
                                maxRotation: 0,
                                minRotation: 0
                             }
                         }
                     }
                 }
             });
        } else {
            console.error("Chart canvas element not found or dimension names are missing.");
        }
    });
</script>

</body>
</html>
