<?php
// view-result-29.php (Öğrenci Rehberlik İhtiyacı Belirleme Anketi - RİBA Ortaokul Öğrenci Sonuçları)

// --- Hata Raporlama ---
ini_set('display_errors', 1);
error_reporting(E_ALL);
// ---------------------------

session_start(); // Session GEREKLİ

// --- Veritabanı Bağlantısı ---
// src/config.php dosyasının yolu projenizin yapısına göre değişebilir
require '../src/config.php'; // view-result-28.php ve view-result-27.php'deki gibi yol düzenlemesi yapıldı
if (!isset($pdo) || !$pdo instanceof PDO) {
    error_log("CRITICAL: PDO connection object (\$pdo) not created or found in src/config.php");
    header('Content-Type: text/html; charset=utf-8');
    die('<div style="border: 1px solid red; padding: 10px; margin: 10px; background-color: #ffebeb; color: red; font-family: sans-serif;"><b>Kritik Hata:</b> Veritabanı bağlantısı kurulamadı (src/config.php).</div>');
}
// --------------------------------

// --- Konfigürasyon ---
$surveyId = 29; // Anket ID'si - Anket 29 için güncellendi
$testTitleDefault = "Öğrenci Rehberlik İhtiyacı Belirleme Anketi (RİBA) (Ortaokul-Öğrenci Formu)"; // Varsayılan anket başlığı


// Logo yollarını kendi projenize göre güncelleyin
$institutionWebURL = null; // Kurum logosu için web yolu (DB'den çekilecek)
// Psikometrik logo yolu - assets klasöründeki PsikoMetrik.png dosyasına işaret ediyor
// Dosya adının ve büyük/küçük harf duyarlılığının doğru olduğundan emin olun.
$psikometrikWebURL = '../assets/Psikometrik.png';


// --- Değişkenler ---
$participantId = null;
$participantData = null; // Hem DB hem de Session verisini tutacak ana değişken
$surveyTitle = $testTitleDefault; // Anket başlığı başlangıçta varsayılan değer
$pageTitle = $testTitleDefault . " Sonuçları"; // Sayfa başlığı başlangıçta varsayılan değer

$error = null;
$dataSource = null; // Veri kaynağını belirten değişken ('db' veya 'session')
$processedAnswersForTable = []; // Detaylı cevap tablosu için
$questionScores = []; // Her sorunun aldığı puan [sort_order => score]
$evaluationText = "Değerlendirme yapılamadı."; // Sonuç değerlendirme metni
$otherNeedsContent = null; // Öğrencinin belirttiği diğer ihtiyaçlar

// --- Puanlama Anahtarı (Anket 29) ---
// Hayır: 1, Kararsızım: 2, Evet: 3 (PDF'e göre)
$scoringKey = [
    "Hayır" => 1,
    "Kararsızım" => 2,
    "Evet" => 3,
];

// --- Gelişim Alanları Soru Aralıkları ---
// PDF'teki sayfa 3'e göre güncellendi
$developmentAreas = [
    'Kişisel-Sosyal Rehberlik' => range(1, 20),
    'Mesleki Rehberlik' => range(21, 25),
    'Eğitsel Rehberlik' => range(26, 38),
];

// --- Gelişim Alanı Skorlarını Başlangıçta Sıfırla ---
// Grafik ve tablo için boyut isimlerinin her zaman mevcut olmasını sağlar
$dimensionScores = [];
foreach ($developmentAreas as $dimName => $qNumbers) {
    $dimensionScores[$dimName] = [
        'total' => 0,
        'average' => 0,
        'count' => count($qNumbers) // Boyuttaki toplam soru sayısı
    ];
}
// ----------------------------------------------------


// --- VERİ KAYNAĞINI BELİRLE VE VERİYİ AL ---
// view-result-27.php'deki gibi önce 'id' sonra 'pid' kontrolü yapıldı
// Eğer URL'de id veya pid varsa veritabanından çekmeyi dene
if (isset($_GET['id']) || isset($_GET['pid'])) {
    $dataSource = 'db';
    // URL'deki id veya pid parametresini al
    $participantId = isset($_GET['id']) ? filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) : filter_input(INPUT_GET, 'pid', FILTER_VALIDATE_INT);

    if (!$participantId) {
        $error = "Geçersiz katılımcı ID formatı.";
        error_log("Invalid participant ID format received: " . ($_GET['id'] ?? $_GET['pid'] ?? 'N/A'));
    } else {
        try {
            // 1. Katılımcı ve Anket Bilgileri
            // view-result-27.php'deki JOIN yapısı kullanıldı
            $stmt_participant = $pdo->prepare("
                SELECT
                    sp.*,
                    s.title as survey_title,
                    u.institution_logo_path
                FROM
                    survey_participants sp
                LEFT JOIN surveys s ON sp.survey_id = s.id
                LEFT JOIN users u ON sp.admin_id = u.id -- admin_id'ye göre users tablosuna join yap
                WHERE sp.id = ? AND sp.survey_id = ?
            ");
            $stmt_participant->execute([$participantId, $surveyId]);
            $participantData = $stmt_participant->fetch(PDO::FETCH_ASSOC); // participantData burada doldurulur

            if (!$participantData) {
                $error = "Belirtilen ID ({$participantId}) için Anket {$surveyId} sonucu veritabanında bulunamadı.";
                error_log("Participant not found in DB for view-result-29 (ID: {$participantId}, Survey: {$surveyId})");
            } else {
                $surveyTitle = !empty($participantData['survey_title']) ? htmlspecialchars($participantData['survey_title']) : $testTitleDefault;
                $pageTitle = $surveyTitle . " Sonuçları"; // Sayfa başlığını güncelle

                // Kurum logosu yolunu belirle (DB'den geldiyse ve admin'e bağlıysa)
                if (!empty($participantData['admin_id']) && !empty($participantData['institution_logo_path'])) {
                   $rawInstitutionPathFromDB = $participantData['institution_logo_path'];
                   // Yolu temizle ve sunucu kök dizinine göre ayarla
                   $cleanRelativePath = ltrim(str_replace('..', '', $rawInstitutionPathFromDB), '/');
                   $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/..');
                   $fullServerPath = rtrim($docRoot, '/') . '/' . $cleanRelativePath;
                   if (file_exists($fullServerPath)) {
                       $institutionWebURL = '/' . $cleanRelativePath; // Web'den erişilebilir yol
                   } else {
                       error_log("Kurum logosu dosyası bulunamadı (view-result-29): " . $fullServerPath);
                   }
                }

                // Öğrencinin belirttiği diğer ihtiyaçlar (description sütunından)
                $otherNeedsContent = htmlspecialchars($participantData['description'] ?? '');


                // 2. Cevapları Çek (question_id artık sort_order, answer_text cevap metni)
                // view-result-27.php'deki JOIN koşulu kullanıldı (sa.question_id = sq.sort_order)
                $stmt_answers = $pdo->prepare("SELECT sa.question_id AS sort_order, sa.answer_text, sq.question AS question_text FROM survey_answers sa JOIN survey_questions sq ON sa.question_id = sq.sort_order AND sa.survey_id = sq.survey_id WHERE sa.participant_id = ? AND sa.survey_id = ? ORDER BY sa.question_id ASC");
                $stmt_answers->execute([$participantId, $surveyId]);
                $fetched_answers = $stmt_answers->fetchAll(PDO::FETCH_ASSOC);

                $processedAnswerCount = 0; // İşlenen geçerli cevap sayısı

                foreach($fetched_answers as $ans) {
                    $sortOrder = (int)$ans['sort_order'];
                    $answerText = trim($ans['answer_text'] ?? '');
                    $numericalScore = $scoringKey[$answerText] ?? null; // Puanlama anahtarını kullan

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

                // Toplam beklenen soru sayısını çek (38)
                $stmt_total_questions = $pdo->prepare("SELECT COUNT(*) FROM survey_questions WHERE survey_id = ?");
                $stmt_total_questions->execute([$surveyId]);
                $totalExpectedQuestionsFetched = (int)$stmt_total_questions->fetchColumn();

                // Tüm sorular cevaplanmış mı control et (38 soru bekleniyor)
                if ($processedAnswerCount < $totalExpectedQuestionsFetched) {
                     $error = "Katılımcı cevapları veritabanında eksik (ID: {$participantId}, Anket: {$surveyId}). Tamamlanan: {$processedAnswerCount}/{$totalExpectedQuestionsFetched}.";
                     error_log("Answers incomplete for participant {$participantId} in survey {$surveyId}. Expected {$totalExpectedQuestionsFetched}, found " . $processedAnswerCount);
                     // Eksik cevap durumunda dimensionScores sıfır kalacak (başlangıçta sıfırlandı)
                     // processedAnswersForTable ve questionScores eksik veriyi yansıtacak
                } else {
                    // 3. Gelişim Alanlarına Göre Toplam ve Ortalama Skorları Hesapla
                    // Başlangıçta sıfırlanan dimensionScores üzerine ekleme yapılıyor
                    foreach ($developmentAreas as $dimName => $qNumbers) {
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
                    $evaluationText = "Bu anket sonuçları, öğrencinin rehberlik ihtiyacına yönelik algısını yansıtmaktadır. ";
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
                         // Puanlama 1-3 arası
                         if ($highestDimension !== null && $highestAverage > 2) { // Ortalama 2'nin üzerindeyse anlamlı bir ihtiyaç algısı var diyebiliriz (1-3 arası puanlama)
                             $evaluationText .= "Sonuçlara göre, öğrenci algısında en çok ihtiyaç duyulan alan '{$highestDimension}' olarak öne çıkmaktadır. ";
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
            error_log("DB Error view-result-29 (ID: {$participantId}): " . $e->getMessage());
            $participantData = null; // Hata durumunda katılımcı verisini temizle
            // dimensionScores başlangıçtaki sıfır değerleriyle kalacak
            $questionScores = [];
            $processedAnswersForTable = [];
            $evaluationText = "Veritabanı hatası nedeniyle değerlendirme yapılamadı.";
        }

    }

}
// Eğer URL'de id veya pid yoksa session'dan çekmeyi dene
if ($dataSource === null) {
    $dataSource = 'session'; // Varsayılan kaynak session olarak ayarlandı
    $participantData = null; // Session verisi buraya yüklenecek
    $processedAnswersForTable = []; // Başlangıç değerleri
    $evaluationText = "Değerlendirme yapılamadı.";
    $otherNeedsContent = null; // Session'dan diğer ihtiyaçlar

    if (isset($_SESSION['survey_result_data'], $_SESSION['survey_result_data']['survey_id']) && $_SESSION['survey_result_data']['survey_id'] == $surveyId) {
        $sessionData = $_SESSION['survey_result_data'];
        // Gerekli anahtarların varlığını kontrol et (Öğrenci anketine özel kontrol)
        if (isset($sessionData['answers'], $sessionData['student_class'], $sessionData['student_gender']) && is_array($sessionData['answers'])) {

            // Session verilerini participantData yapısına uygun hale getir
            $participantData = [
                'name' => $sessionData['student_name'] ?? 'Belirtilmemiş', // Öğrenci adı
                'class' => $sessionData['student_class'], // Sınıf
                'gender' => $sessionData['student_gender'], // Cinsiyet
                'created_at' => date('Y-m-d H:i:s', $sessionData['timestamp'] ?? time()),
                'admin_id' => null, // Session'dan gelen sonuçta admin_id olmaz
                'description' => $sessionData['other_needs'] ?? '' // Diğer ihtiyaçlar
            ];
            $surveyTitle = $testTitleDefault; // Session için varsayılan başlık
            $pageTitle = $surveyTitle . " Sonuçları"; // Sayfa başlığını güncelle

            // Öğrencinin belirttiği diğer ihtiyaçlar (session'dan)
            $otherNeedsContent = htmlspecialchars($participantData['description']); // participantData'dan al

            $sessionAnswers = $sessionData['answers'];
            $processedAnswerCount = 0; // İşlenen geçerli cevap sayısı
            $questionScores = []; // [sort_order => score (1-3)]

            // Soruları DB'den çekerek metinlerini alalım (tablo için)
            $questionSortOrderToTextMap = [];
             try {
                 $stmtQText = $pdo->prepare("SELECT sort_order, question AS question_text FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC");
                 $stmtQText->execute([$surveyId]);
                 $questionSortOrderToTextMap = $stmtQText->fetchAll(PDO::FETCH_KEY_PAIR); // [sort_order => question_text]
             } catch(Exception $e) { error_log("Session result question text fetch error (S{$surveyId}): " . $e->getMessage()); /* Hata set edilebilir */ }


            // Cevapları puana çevir (1-3) ve skorları kaydet
            foreach ($sessionAnswers as $sortOrder => $answerText) {
                 $sortOrder_int = (int)$sortOrder;
                 $answerText_str = trim($answerText);
                 $numericalScore = $scoringKey[$answerText_str] ?? null; // Puanlama anahtarını kullan

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

            // Toplam beklenen soru sayısını çek (38)
            $stmt_total_questions = $pdo->prepare("SELECT COUNT(*) FROM survey_questions WHERE survey_id = ?");
            $stmt_total_questions->execute([$surveyId]);
            $totalExpectedQuestionsFetched = (int)$stmt_total_questions->fetchColumn();

            if ($processedAnswerCount < $totalExpectedQuestionsFetched) {
                 error_log("view-result-29 Session: Processed fewer valid answers ({$processedAnswerCount}) than expected ({$totalExpectedQuestionsFetched}) from session data.");
                 // Eksik cevap durumunda dimensionScores başlangıçtaki sıfır değerleriyle kalacak
                 // processedAnswersForTable ve questionScores eksik veriyi yansıtacak
            }

            // 3. Gelişim Alanlarına Göre Toplam ve Ortalama Skorları Hesapla
            // Başlangıçta sıfırlanan dimensionScores üzerine ekleme yapılıyor
            foreach ($developmentAreas as $dimName => $qNumbers) {
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
             $evaluationText = "Bu anket sonuçları, öğrencinin rehberlik ihtiyacına yönelik algısını yansıtmaktadır. ";
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
                   // Puanlama 1-3 arası
                  if ($highestDimension !== null && $highestAverage > 2) { // Ortalama 2'nin üzerindeyse anlamlı bir ihtiyaç algısı var diyebiliriz (1-3 arası puanlama)
                      $evaluationText .= "Sonuçlara göre, öğrenci algısında en çok ihtiyaç duyulan alan '{$highestDimension}' olarak öne çıkmaktadır. ";
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
            error_log("Incomplete session data for survey 29: " . print_r($sessionData, true));
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


// Psikometrik logo kontrolü (view-result-27.php'deki gibi)
if ($psikometrikWebURL) {
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/..');
    $fullPsikoServerPath = rtrim($docRoot, '/') . '/' . ltrim(str_replace('..', '', $psikometrikWebURL), '/');
    if (!file_exists($fullPsikoServerPath)) { $psikometrikWebURL = null; error_log("Psikometrik logo dosyası bulunamadı (view-result-29): " . $fullPsikoServerPath); }
}


if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link rel="icon" href="/favicon.png" type="image/png">
    <style>
        /* --- Genel Stil --- */
        body {
            font-family: 'Arial', sans-serif;
            line-height: 1.6;
            background-color: #f0fdf4; /* view-result-27.php teması */
            color: #2c3e50; /* view-result-27.php teması */
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 900px;
            margin: 20px auto; /* view-result-27.php teması */
            background: white; /* view-result-27.php teması */
            padding: 20px 30px; /* view-result-27.php teması */
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1); /* view-result-27.php teması */
            border: 1px solid #e0e0e0;
        }
         /* Sayfa başlığı ve logoları içeren üst kısım */
        .page-header {
            background-color: #ffffff;
            padding: 10px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e0e0e0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
         .page-header .logo-left img,
         .page-header .logo-right img {
            max-height: 50px; /* Logo boyutu */
            width: auto;
         }
         .page-header .logo-left,
         .page-header .logo-right {
             flex: 1; /* Alanı doldur */
             display: flex;
             align-items: center;
         }
         .page-header .logo-left {
             justify-content: flex-start; /* Sol logoyu sola hizala */
         }
         .page-header .logo-right {
             justify-content: flex-end; /* Sağ logoyu sağa hizala */
         }
         .page-header .page-title {
             flex: 2; /* Başlık için daha fazla alan */
             text-align: center;
             font-size: 1.8rem;
             color: #1f2937; /* view-result-27.php teması */
             margin: 0;
         }


        .info-box {
            background-color: #e9f7ef; /* view-result-27.php teması */
            color: #0f5132; /* view-result-27.php teması */
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 25px;
            border: 1px solid #c3e6cb; /* view-result-27.php teması */
            font-size: 0.95em;
        }
        .error-box {
            color: #b91c1c;
            background-color: #fee2e2;
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
            border: 1px solid #fca5a5;
            font-weight: 500;
            text-align: left;
            font-size: 0.9em;
        }

        /* --- Özet ve Grafik Alanları Stili (Üst Kısım) --- */
         .summary-area-top {
             margin-bottom: 30px;
             padding-bottom: 20px;
             border-bottom: 2px solid #dcfce7; /* view-result-27.php teması */
         }

        /* --- Gelişim Alanları Özeti Stili --- */
        .summary-section {
            margin-bottom: 30px; /* Tablodan önce boşluk */
        }
        .summary-section h3 {
            font-size: 1.4rem; /* view-result-27.php teması */
            color: #15803d; /* view-result-27.php teması */
            margin-top: 2rem; /* view-result-27.php teması */
            margin-bottom: 1rem; /* view-result-28.php teması */
            border-bottom: 1px solid #eee; /* view-result-28.php teması */
            padding-bottom: 0.4rem; /* view-result-28.php teması */
        }
        .summary-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .summary-list li {
            background-color: #f9fafb; /* view-result-27.php teması */
            padding: 10px 15px;
            margin-bottom: 8px;
            border-radius: 4px;
            border: 1px solid #f3f4f6; /* view-result-27.php teması */
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .summary-list li strong {
            color: #374151; /* view-result-27.php teması */
        }
        .summary-list li span {
            font-weight: bold;
            color: #15803d; /* view-result-27.php teması */
        }

        /* --- Grafik Alanı --- */
        .chart-section {
            margin-bottom: 30px; /* Değerlendirme notundan önce boşluk */
            text-align: center; /* Grafik ortalanabilir */
        }
        .chart-section h3 {
             font-size: 1.4rem; /* view-result-27.php teması */
             color: #15803d; /* view-result-27.php teması */
             margin-top: 2rem; /* view-result-27.php teması */
             margin-bottom: 1rem; /* view-result-28.php teması */
             border-bottom: 1px solid #eee; /* view-result-28.php teması */
             padding-bottom: 0.4rem; /* view-result-28.php teması */
         }
        .chart-container {
            width: 100%; /* Konteyner genişliği %100 yapıldı */
            max-width: 900px; /* Maksimum genişlik artırıldı */
            margin: 20px auto; /* Ortala */
            padding: 15px;
            background-color: #ffffff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            height: 550px; /* Konteyner yüksekliği artırıldı */
            display: flex;
            justify-content: center;
            align-items: center;
            position: relative;
        }
         /* Grafik canvas'ı için sabit boyutlar */
         .chart-container canvas {
             width: 850px !important; /* Sabit genişlik artırıldı */
             height: 500px !important; /* Sabit yükseklik artırıldı */
             max-width: 100%; /* Konteynerin içine sığmasını sağla */
             max-height: 100%; /* Konteynerin içine sığmasını sağla */
         }


        /* --- Değerlendirme Notu Stili --- */
        .evaluation-note-section {
            margin-bottom: 30px; /* Cevap tablosundan önce boşluk */
            padding: 20px;
            background-color: #fff; /* view-result-27.php teması */
            border: 1px solid #e0e0e0; /* view-result-27.php teması */
            border-radius: 8px; /* view-result-27.php teması */
            font-size: 1em;
            color: #475569; /* view-result-27.php teması */
            line-height: 1.5;
        }
         .evaluation-note-section h3 {
             font-size: 1.4rem; /* view-result-27.php teması */
             color: #15803d; /* view-result-27.php teması */
             margin-top: 0;
             margin-bottom: 1rem; /* view-result-28.php teması */
             border-bottom: 1px solid #eee; /* view-result-28.php teması */
             padding-bottom: 0.4rem; /* view-result-28.php teması */
         }
         .evaluation-note-section ul {
             list-style: disc;
             padding-left: 20px;
         }
         .evaluation-note-section li {
             margin-bottom: 8px;
         }

        /* --- Diğer İhtiyaçlar Alanı Stili --- */
        .other-needs-section {
            margin-top: 30px; /* Tablodan sonra boşluk */
            padding: 20px;
            background-color: #f9fafb; /* view-result-27.php teması */
            border: 1px solid #f3f4f6; /* view-result-27.php teması */
            border-radius: 8px; /* view-result-27.php teması */
        }
        .other-needs-section h3 {
             font-size: 1.4rem; /* view-result-27.php teması */
             color: #15803d; /* view-result-27.php teması */
             margin-top: 0;
             margin-bottom: 1rem; /* view-result-28.php teması */
             border-bottom: 1px solid #eee; /* view-result-28.php teması */
             padding-bottom: 0.4rem; /* view-result-28.php teması */
        }
        .other-needs-section p {
            margin: 0;
            color: #475569; /* view-result-28.php teması */
            line-height: 1.5;
        }


        /* --- Sonuç Tablosu Stili --- */
        .results-table-section h3 {
             font-size: 1.4rem; /* view-result-27.php teması */
             color: #15803d; /* view-result-27.php teması */
             margin-top: 2rem; /* view-result-28.php teması */
             margin-bottom: 1rem; /* view-result-28.php teması */
             border-bottom: 1px solid #eee; /* view-result-28.php teması */
             padding-bottom: 0.4rem; /* view-result-28.php teması */
        }
        .results-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem; /* view-result-28.php teması */
            font-size: 0.9rem; /* view-result-28.php teması */
        }
        .results-table th, .results-table td {
            border: 1px solid #ddd; /* view-result-28.php teması */
            padding: 8px 10px; /* view-result-28.php teması - Satır yüksekliği ayarı */
            text-align: left;
            vertical-align: top; /* view-result-28.php teması */
        }
        .results-table th {
            background-color: #dcfce7; /* view-result-28.php teması */
            font-weight: 600; /* view-result-28.php teması */
            color: #1f2937; /* view-result-28.php teması */
        }
         .results-table td:nth-child(1) {
             width: 8%; /* view-result-28.php teması */
             text-align: center;
             font-weight: bold; /* view-result-28.php teması */
             vertical-align: middle; /* view-result-28.php teması */
         } /* Madde No */
        .results-table td:nth-child(2) { width: 52%; line-height: 1.4; } /* Soru Metni */
        .results-table td:nth-child(3) { width: 25%; text-align: center; vertical-align: middle;} /* Verilen Cevap */
        .results-table td:nth-child(4) { width: 15%; text-align: center; font-weight: bold; vertical-align: middle;} /* Puan */
        .results-table tr:nth-child(even) { background-color: #f8f9fa; } /* view-result-28.php teması */


        .error-box { color: #b91c1c; background-color: #fee2e2; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; border: 1px solid #fca5a5; font-weight: 500; text-align: left; font-size: 0.9em;}
        .error-box b { font-weight: bold; }

        .button-container {
             text-align: center;
             margin-top: 2.5rem; /* view-result-28.php teması */
             padding-top: 1.5rem; /* view-result-28.php teması */
             border-top: 1px solid #e0e0e0; /* view-result-28.php teması */
             display: flex; /* view-result-28.php teması */
             justify-content: center; /* view-result-28.php teması */
             gap: 1rem; /* view-result-28.php teması */
        }
        .action-button {
             display: inline-flex; /* view-result-28.php teması */
             align-items: center; /* view-result-28.php teması */
             padding: 10px 20px; /* view-result-28.php teması */
             font-size: 1rem; /* view-result-28.php teması */
             font-weight: 600; /* view-result-28.php teması */
             color: white;
             background-color: #15803d; /* view-result-28.php teması */
             border: none;
             border-radius: 6px; /* view-result-28.php teması */
             cursor: pointer;
             text-decoration: none; /* Linkler için alt çizgiyi kaldır */
             transition: background-color 0.2s ease, box-shadow 0.2s ease; /* view-result-28.php teması */
             box-shadow: 0 1px 2px rgba(0,0,0,0.05); /* view-result-28.php teması */
        }
        .action-button:hover { background-color: #0b532c; box-shadow: 0 4px 8px rgba(0,0,0,0.1); } /* view-result-28.php teması */
        /* view-result-28.php'deki buton renkleri */
        .action-button.print-button { background-color: #15803d; }
        .action-button.print-button:hover { background-color: #0b532c; }
        .action-button.panel-button { background-color: #6c757d; } /* Panele Dön/Diğer Anketler butonu */
        .action-button.panel-button:hover { background-color: #5a6268; }


         /* --- Yazdırma Stilleri --- */
         @media print {
             body {
                 background-color: #fff;
                 padding: 0;
                 margin: 0;
                 font-size: 10pt; /* Yazdırma için font boyutu */
                 margin: 20mm; /* view-result-28.php teması - Kenar boşlukları eklendi */
             }
             .container {
                 box-shadow: none;
                 border: none;
                 margin: 0; /* Body'ye margin verildiği için container margin sıfırlandı */
                 padding: 0; /* Body'ye padding verildiği için container padding sıfırlandı */
                 max-width: 100%;
             }
             .page-header {
                 padding: 10px 0; /* view-result-28.php teması */
                 border-bottom: 1px solid #000; /* view-result-28.php teması */
                 box-shadow: none;
                 display: flex;
                 justify-content: space-between;
                 align-items: center;
             }
             .page-header .logo-left img,
             .page-header .logo-right img {
                max-height: 40px; /* Yazdırmada logo boyutu */
                width: auto;
             }
             .page-header .logo-left { justify-content: flex-start; }
             .page-header .logo-right { justify-content: flex-end; }
             .page-header .page-title {
                 flex: 2;
                 text-align: center;
                 font-size: 14pt; /* view-result-28.php teması */
                 color: #000; /* view-result-28.php teması */
                 margin: 0;
             }


             /* Butonları yazdırmada gizle */
             .button-container {
                 display: none;
             }

             /* Grafik konteynerini yazdırmada görünür yap */
             .chart-section {
                 display: block; /* Gizlemeyi kaldır */
                 margin-bottom: 15px; /* Yazdırmada alt boşluk */
                 page-break-inside: avoid; /* Grafik sayfa bölünmesini engelle */
             }
             .chart-container {
                 width: 80%; /* Yazdırmada daha az yer kaplaması için */
                 height: 250px; /* Yazdırmada daha az yer kaplaması için */
                 margin: 15px auto;
                 padding: 10px;
                 border: 1px solid #ddd;
                 box-shadow: none;
                 /* page-break-inside: avoid; parent elemente taşındı */
             }
             /* Yazdırmada canvas'ın sabit boyutu */
             .chart-container canvas {
                 width: 600px !important; /* Sabit genişlik */
                 height: 250px !important; /* Sabit yükseklik, yazdırma için biraz daha küçük */
                 max-width: 100%;
                 max-height: 100%;
             }


             /* Özet ve değerlendirme notu bölümü */
             .summary-area-top {
                 border-bottom: 1px solid #ccc; /* view-result-28.php teması */
                 margin-bottom: 15px;
                 padding-bottom: 15px;
             }
             .summary-section, .evaluation-note-section {
                 margin-bottom: 15px;
                 padding: 10px;
                 border: 1px solid #eee;
                 background-color: #fff;
             }
              .summary-section h3, .evaluation-note-section h3 {
                  font-size: 11pt; /* view-result-28.php teması */
                  margin-bottom: 10px;
              }
              .summary-list li {
                  padding: 5px 10px; /* view-result-28.php teması */
                  margin-bottom: 4px;
                  border: none;
                  background-color: #fff;
              }
              .evaluation-note-section ul {
                  padding-left: 15px;
              }
              .evaluation-note-section li {
                  margin-bottom: 4px;
              }

             /* Diğer İhtiyaçlar Alanı Stili (Yazdırmada görünür) */
             .other-needs-section {
                 margin-top: 15px;
                 padding: 10px;
                 border: 1px solid #eee;
                 background-color: #fff;
                 font-size: 10pt;
             }
              .other-needs-section h3 {
                  font-size: 11pt;
                  margin-top: 0;
                  margin-bottom: 10px;
                  border-bottom: 1px dashed #ccc;
                  padding-bottom: 2px;
              }


             /* Tablo stilleri */
             .results-table-section h3 {
                 font-size: 11pt; /* view-result-28.php teması */
                 margin-bottom: 10px;
             }
             .results-table th, .results-table td {
                 padding: 5px 8px; /* view-result-28.php teması - Yazdırmada satır yüksekliği ayarı */
                 border: 1px solid #000; /* view-result-28.php teması */
             }
             .results-table th {
                 background-color: #eee; /* view-result-28.php teması */
                 color: #000; /* view-result-28.php teması */
             }
             .results-table tbody tr:nth-child(even) {
                 background-color: #fff; /* Yazdırmada zebra çizgiyi kaldır */
             }
         }

    </style>
     <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

<div class="page-header">
    <div class="logo-left">
        <?php if($dataSource == 'db' && !empty($institutionWebURL)): ?>
            <img src="<?= htmlspecialchars($institutionWebURL) ?>" alt="Kurum Logosu">
        <?php else: ?><span>&nbsp;</span><?php endif; ?>
    </div>
    <div class="page-title">
        <?= htmlspecialchars($surveyTitle) ?> Sonuçları
    </div>
    <div class="logo-right">
        <?php
        // Psikometrik logo dosyasının varlığını kontrol et
        $psikometrikLogoExists = false;
        if ($psikometrikWebURL) {
             $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/..');
             $fullPsikoServerPath = rtrim($docRoot, '/') . '/' . ltrim(str_replace('..', '', $psikometrikWebURL), '/');
             if (file_exists($fullPsikoServerPath)) {
                 $psikometrikLogoExists = true;
             } else {
                 error_log("Psikometrik logo dosyası bulunamadı (view-result-29): " . $fullPsikoServerPath);
             }
        }
        ?>
        <?php if ($psikometrikLogoExists): ?>
            <img src="<?= htmlspecialchars($psikometrikWebURL) ?>" alt="Psikometrik.Net Logosu">
        <?php else: ?>
            <span>Psikometrik.Net</span>
        <?php endif; ?>
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

        <h1><?= htmlspecialchars($surveyTitle) ?> Sonuçları</h1> <?php // Anket başlığı kullanıldı ?>

        <?php // Katılımcı bilgileri varsa göster ?>
        <?php if ($participantData): ?>
        <div class="participant-info">
             <h2>Katılımcı Bilgileri</h2>
             <?php // Katılımcı bilgileri, Session'dan geliyorsa ayrı alanlar olarak gösterilir ?>
             <?php if ($dataSource == 'session'): ?>
                 <p><strong>Ad Soyad:</strong> <?= htmlspecialchars($participantData['name'] ?? 'Belirtilmemiş') ?></p>
                 <p><strong>Sınıf:</strong> <?= htmlspecialchars($participantData['class'] ?? 'Belirtilmemiş') ?></p>
                 <p><strong>Cinsiyet:</strong> <?= htmlspecialchars($participantData['gender'] ?? 'Belirtilmemiş') ?></p>
             <?php elseif ($dataSource == 'db'): ?>
                 <?php // DB'den geliyorsa, bilgiler 'name' sütununda birleşik olabilir ?>
                 <p><strong>Ad Soyad:</strong> <?= htmlspecialchars($participantData['name'] ?? 'Belirtilmemiş') ?></p>
                 <p><strong>Sınıf:</strong> <?= htmlspecialchars($participantData['class'] ?? 'Belirtilmemiş') ?></p>
                 <?php // Cinsiyet bilgisi 'name' alanında birleşik kaydedildiği için burada ayrı gösterilemiyor.
                       // Eğer ayrı sütun olsaydı buradan çekilip gösterilebilirdi. ?>
             <?php endif; ?>
             <?php // Test Tarihi ve Yönetici ID her iki durumda da gösterilebilir ?>
             <p><strong>Test Tarihi:</strong> <?= date('d.m.Y H:i', strtotime($participantData['created_at'] ?? (@$_SESSION['survey_result_data']['timestamp'] ? date('Y-m-d H:i:s', $_SESSION['survey_result_data']['timestamp']) : time()))) ?></p>
             <?php if ($dataSource === 'db' && !empty($participantData['admin_id'])): ?>
                  <p><strong>Yönetici ID:</strong> <?= htmlspecialchars($participantData['admin_id']) ?></p>
             <?php endif; ?>
        </div>
        <?php endif; ?>


        <?php // Grafik Alanı ?>
        <?php // dimensionScores her zaman boyut isimlerini içerecek şekilde başlatıldığı için bu blok her zaman çalışır ?>
        <div class="chart-section">
             <h3>Gelişim Alanları Puan Ortalaması Grafiği</h3>
             <canvas id="developmentAreaChart"></canvas>
        </div>


        <div class="result-summary">
             <h2>Gelişim Alanları Sonuçları</h2>

             <?php if (!empty($dimensionScores)): ?>
                 <table class="score-table">
                     <thead>
                         <tr>
                             <th>Gelişim Alanı</th>
                             <th>Toplam Puan</th>
                             <th>Ortalama Puan (1-3)</th> <?php // Puan 1-3 olacak ?>
                         </tr>
                     </thead>
                     <tbody>
                         <?php foreach($developmentAreas as $dimName => $qNumbers): // Gelişim alanlarını döngüye al ?>
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

        <?php // Öğrencinin Belirttiği Diğer İhtiyaçlar Alanı ?>
        <?php if ($otherNeedsContent): ?>
             <div class="other-needs-section">
                 <h3>Öğrencinin Belirttiği Diğer İhtiyaçlar</h3>
                 <p><?= nl2br($otherNeedsContent) ?></p> <?php // Satır sonlarını <br> ile değiştir ?>
             </div>
        <?php endif; ?>


        <div class="results-table-section">
             <h3>Cevaplarınız ve Puanlar</h3>
             <?php if (!empty($processedAnswersForTable)): ?>
                 <table class="results-table">
                     <thead>
                         <tr>
                             <th>Soru No</th>
                             <th>Soru</th>
                             <th>Verilen Cevap</th>
                             <th>Puan (1-3)</th> <?php // Puan 1-3 olacak ?>
                         </tr>
                     </thead>
                     <tbody>
                         <?php foreach ($processedAnswersForTable as $row): // Döngü değişkeni $row olarak düzeltildi ?>
                             <tr>
                                 <td><?= htmlspecialchars($row['madde']) ?></td> <?php // Anahtar 'madde' olarak düzeltildi ?>
                                 <td><?= htmlspecialchars($row['question_text']) ?></td>
                                 <td><?= htmlspecialchars($row['verilen_cevap']) ?></td> <?php // Anahtar 'verilen_cevap' olarak düzeltildi ?>
                                 <td><?= htmlspecialchars($row['puan']) ?></td> <?php // Anahtar 'puan' olarak düzeltildi ?>
                             </tr>
                         <?php endforeach; ?>
                     </tbody>
                 </table>
             <?php else: ?>
                 <div class="error-box">Detaylı cevaplar görüntülenemiyor veya eksik.</div>
             <?php endif; ?>
        </div>


         <div class="button-container no-print">
            <?php // Eğer DB'den geldiyse ve admin_id varsa panele dön, yoksa ana sayfaya dön ?>
            <?php if ($dataSource == 'db' && $participantData && !empty($participantData['admin_id'])): ?>
                 <a href="dashboard.php" class="action-button panel-button">Panele Dön</a>
            <?php else: ?>
                 <a href="../index.php" class="action-button panel-button">Ana Sayfaya Dön</a> <?php // Ana sayfaya yönlendirme ?>
            <?php endif; ?>
             <button onclick="window.print();" class="action-button print-button">Çıktı Al</button>
         </div>

    <?php endif; ?>

</div> <?php // container sonu ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Grafik çizimi için JavaScript
    // dimensionScores PHP'de her zaman boyut isimlerini içerecek şekilde başlatıldığı için
    // script bloğu her zaman çalışır, ancak data boşsa grafik boş çizilir.
    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('developmentAreaChart');

        // PHP'den gelen gelişim alanı isimlerini ve ortalama skorlarını al
        const dimensionScores = <?= json_encode($dimensionScores ?? []) ?>; // Hata durumunda boş dizi gönder
        const areaLabels = Object.keys(dimensionScores);
        // Ortalama puanları alırken, eğer answered_count 0 ise ortalama 0 olacaktır.
        const areaData = areaLabels.map(dimName => dimensionScores[dimName].average);


        // Konsol logları ile veriyi kontrol et
        console.log('Development Area Averages from PHP:', dimensionScores);
        console.log('Area Names for Chart:', areaLabels);
        console.log('Area Averages for Chart:', areaData);


        if (ctx && areaLabels.length > 0) { // Canvas elementi ve veri varsa grafiği çiz
             new Chart(ctx, {
                 type: 'bar', // Çubuk grafik
                 data: {
                     labels: areaLabels, // Gelişim alanı adları
                     datasets: [{
                         label: 'Ortalama Puan',
                         data: areaData, // Ortalama puanlar (0 olabilir)
                         backgroundColor: [ // Her çubuk için farklı renk
                             'rgba(54, 162, 235, 0.7)', // Mavi (Kişisel-Sosyal)
                             'rgba(255, 159, 64, 0.7)', // Turuncu (Mesleki)
                             'rgba(75, 192, 192, 0.7)'  // Yeşil (Eğitsel)
                         ],
                         borderColor: [
                             'rgba(54, 162, 235, 1)',
                             'rgba(255, 159, 64, 1)',
                             'rgba(75, 192, 192, 1)'
                         ],
                         borderWidth: 1
                     }]
                 },
                 options: {
                     indexAxis: 'y', // Eksenleri değiştirerek yatay çubuk grafik oluşturuldu
                     responsive: false, // Grafik boyutunu sabitlemek için responsive kapatıldı
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
                         },
                         // datalabels eklentisi Chart.js'e ayrıca eklenmelidir
                         // datalabels: {
                         //    anchor: 'end',
                         //    align: 'end',
                         //    formatter: function(value, context) {
                         //        return value.toFixed(2); // Çubuk üzerine ortalama puanı yazdır
                         //    }
                         // }
                     },
                     scales: {
                         x: { // X ekseni şimdi ortalama puanlar
                             beginAtZero: true,
                             max: 3, // Maksimum puan 3 (Hayır:1, Kararsızım:2, Evet:3)
                             title: {
                                 display: true,
                                 text: 'Ortalama Puan (1-3)'
                             },
                             ticks: {
                                 stepSize: 0.5 // X ekseninde 0.5'er artış (isteğe bağlı)
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
            console.error("Chart canvas element not found or development area averages are missing.");
        }
    });
</script>

</body>
</html>
