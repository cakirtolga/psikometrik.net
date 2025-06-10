<?php
// view-result-31.php (Şiddet Sıklığı Anketi - Veli Formu Sonuçları)

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
$surveyId = 31; // Anket ID'si - Anket 31 için güncellendi
$testTitleDefault = "Şiddet Sıklığı Anketi (Veli Formu)"; // Varsayılan anket başlığı


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
$answerCountsPerDimension = []; // Boyutlara göre cevap sayısını tutacak
$childName = null; // Çocuğun Adı Soyadı
$childClass = null; // Çocuğun Sınıfı
$childSchoolNumber = null; // Çocuğun Okul No


// --- Sabit Veriler (Anket 31) ---
// Cevap Seçenekleri
$optionsList = ["Hiç Olmadı", "Ayda Birkaç Kez Oldu", "Hemen Hemen Her Gün Oldu"];

// Şiddet Sıklığı Boyutları ve Soru Aralıkları (sort_order'a göre)
$dimensions = [
    'Ev Ortamı' => range(1, 12),
    'Okul Ortamı' => range(13, 27),
    'Okul Çevresi' => range(28, 34),
    'Elektronik Ortam' => range(35, 40), // 40 soru var
];

// --- Boyutlara Göre Cevap Sayılarını Başlangıçta Sıfırla ---
foreach ($dimensions as $dimName => $qNumbers) {
    $answerCountsPerDimension[$dimName] = [];
    foreach ($optionsList as $option) {
        $answerCountsPerDimension[$dimName][$option] = 0;
    }
    $answerCountsPerDimension[$dimName]['total_questions'] = count($qNumbers);
}
// ----------------------------------------------------


// --- VERİ KAYNAĞINI BELİRLE VE VERİYİ AL ---
if (isset($_GET['id']) || isset($_GET['pid'])) {
    // --- SENARYO 1: ID VAR -> VERITABANINDAN ÇEK ---
    $dataSource = 'db';
    $participantId = isset($_GET['id']) ? filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) : filter_input(INPUT_GET, 'pid', FILTER_VALIDATE_INT);

    if (!$participantId) {
        $error = "Geçersiz katılımcı ID formatı.";
        error_log("Invalid participant ID format received: " . ($_GET['id'] ?? $_GET['pid'] ?? 'N/A'));
    } else {
        try {
            // 1. Katılımcı ve Anket Bilgileri
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
            $participantData = $stmt_participant->fetch(PDO::FETCH_ASSOC);

            if (!$participantData) {
                $error = "Belirtilen ID ({$participantId}) için Anket {$surveyId} sonucu veritabanında bulunamadı.";
                error_log("Participant not found for view-result-31 (ID: {$participantId}, Survey: {$surveyId})");
            } else {
                $surveyTitle = !empty($participantData['survey_title']) ? htmlspecialchars($participantData['survey_title']) : $testTitleDefault;
                $pageTitle = $surveyTitle . " Sonuçları"; // Sayfa başlığını güncelle

                // Kurum logosu yolunu belirle (DB'den geldiyse ve admin'e bağlıysa)
                if (!empty($participantData['admin_id']) && !empty($participantData['institution_logo_path'])) {
                   $rawInstitutionPathFromDB = $participantData['institution_logo_path'];
                   $cleanRelativePath = ltrim(str_replace('..', '', $rawInstitutionPathFromDB), '/');
                   $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/..');
                   $fullServerPath = rtrim($docRoot, '/') . '/' . $cleanRelativePath;
                   if (file_exists($fullServerPath)) { $institutionWebURL = '/' . $cleanRelativePath; }
                   else { error_log("Kurum logosu dosyası bulunamadı (view-result-31): " . $fullServerPath); }
                }

                // Çocuğun Adı Soyadı, Sınıfı ve Okul No bilgilerini description ve class sütunlarından çek
                $childClass = htmlspecialchars($participantData['class'] ?? 'Belirtilmemiş'); // Sınıf class sütununda

                // Description alanından Ad Soyadı ve Okul No bilgisini çekmeye çalış
                if (!empty($participantData['description'])) {
                    $descriptionLines = explode("\n", $participantData['description']);
                    foreach ($descriptionLines as $line) {
                        if (strpos($line, 'Çocuğun Adı Soyadı:') === 0) {
                            $childName = trim(str_replace('Çocuğun Adı Soyadı:', '', $line));
                        } elseif (strpos($line, 'Çocuğun Okul No:') === 0) {
                             $childSchoolNumber = trim(str_replace('Çocuğun Okul No:', '', $line));
                        }
                    }
                }
                 // Eğer description'dan çekilemediyse veya boşsa varsayılan değer ata
                 if (empty($childName)) $childName = 'Belirtilmemiş';
                 if (empty($childSchoolNumber)) $childSchoolNumber = 'Belirtilmemiş';


                // 2. Cevapları Çek (question_id artık sort_order, answer_text cevap metni)
                $stmt_answers = $pdo->prepare("SELECT sa.question_id AS sort_order, sa.answer_text, sq.question AS question_text FROM survey_answers sa JOIN survey_questions sq ON sa.question_id = sq.sort_order AND sa.survey_id = sq.survey_id WHERE sa.participant_id = ? AND sa.survey_id = ? ORDER BY sa.question_id ASC");
                $stmt_answers->execute([$participantId, $surveyId]);
                $fetched_answers = $stmt_answers->fetchAll(PDO::FETCH_ASSOC);

                $processedAnswerCount = 0; // İşlenen geçerli cevap sayısı

                foreach($fetched_answers as $ans) {
                    $sortOrder = (int)$ans['sort_order'];
                    $answerText = trim($ans['answer_text'] ?? '');

                     if (in_array($answerText, $optionsList)) { // Sadece geçerli cevapları al
                        $processedAnswerCount++; // Geçerli cevap sayısını artır

                        // Detaylı tablo için veriyi hazırla
                        $processedAnswersForTable[] = [
                            'madde' => $sortOrder,
                            'question_text' => $ans['question_text'] ?? 'Soru metni yüklenemedi',
                            'verilen_cevap' => $answerText,
                        ];

                        // Boyutlara göre cevap sayılarını güncelle
                        foreach ($dimensions as $dimName => $qNumbers) {
                            if (in_array($sortOrder, $qNumbers)) {
                                $answerCountsPerDimension[$dimName][$answerText]++;
                                break; // İlgili boyutu bulduk, diğerlerine bakmaya gerek yok
                            }
                        }

                     } else {
                         error_log("Invalid answer_text '{$answerText}' found in DB for participant {$participantId}, survey {$surveyId}, sort_order {$sortOrder}");
                     }
                }

                // Toplam beklenen soru sayısı (40)
                $stmt_total_questions = $pdo->prepare("SELECT COUNT(*) FROM survey_questions WHERE survey_id = ?");
                $stmt_total_questions->execute([$surveyId]);
                $totalExpectedQuestionsFetched = (int)$stmt_total_questions->fetchColumn();

                // Tüm sorular cevaplanmış mı control et (40 soru bekleniyor)
                if ($processedAnswerCount < $totalExpectedQuestionsFetched) {
                     $error = "Katılımcı cevapları veritabanında eksik (ID: {$participantId}, Anket: {$surveyId}). Tamamlanan: {$processedAnswerCount}/{$totalExpectedQuestionsFetched}.";
                     error_log("Answers incomplete for participant {$participantId} in survey {$surveyId}. Expected {$totalExpectedQuestionsFetched}, found " . $processedAnswerCount);
                     // Eksik cevap durumunda answerCountsPerDimension eksik veriyi yansıtacak
                     $evaluationText = "Anket cevapları eksik olduğu için değerlendirme tam yapılamadı.";
                } else {
                    // 3. Değerlendirme Metni Oluştur (Cevap sayılarına göre basit bir değerlendirme)
                    $evaluationText = "Bu anket sonuçları, çocuğunuzun son 6 ayda maruz kaldığı şiddet durumlarına ilişkin veli gözlemini yansıtmaktadır. ";

                    $violenceSituationsFound = false;
                    foreach ($answerCountsPerDimension as $dimName => $counts) {
                        $frequentViolence = ($counts["Ayda Birkaç Kez Oldu"] ?? 0) + ($counts["Hemen Hemen Her Gün Oldu"] ?? 0);
                        if ($frequentViolence > 0) {
                            $violenceSituationsFound = true;
                            $evaluationText .= "<strong>{$dimName}:</strong> {$frequentViolence} durumda şiddet yaşanmıştır. ";
                        }
                    }

                    if (!$violenceSituationsFound) {
                        $evaluationText .= "Veli gözlemine göre çocuğunuzun son 6 ayda anket kapsamında belirtilen şiddet durumlarına maruz kalmadığı görülmektedir.";
                    } else {
                         $evaluationText .= "Detaylı bilgi için aşağıdaki cevap tablosunu inceleyebilirsiniz. Gerektiğinde bireysel görüşme ve rehberlik çalışmaları planlanabilir.";
                    }

                } // End if ($processedAnswerCount < $totalExpectedQuestionsFetched) else

            } // End if (!$participantData) else

        } catch (Exception $e) {
            $error = "Sonuçlar veritabanından yüklenirken bir hata oluştu: " . $e->getMessage();
            error_log("DB Error view-result-31 (ID: {$participantId}): " . $e->getMessage());
            $participantData = null; // Hata durumunda katılımcı verisini temizle
            $answerCountsPerDimension = []; // Hata durumunda temizle
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
    $childName = null; // Session'dan çocuk adı
    $childClass = null; // Session'dan çocuk sınıfı
    $childSchoolNumber = null; // Session'dan çocuk okul no


    if (isset($_SESSION['survey_result_data'], $_SESSION['survey_result_data']['survey_id']) && $_SESSION['survey_result_data']['survey_id'] == $surveyId) {
        $sessionData = $_SESSION['survey_result_data'];
        // Gerekli anahtarların varlığını kontrol et (Veli anketine özel kontrol)
        if (isset($sessionData['answers'], $sessionData['child_name'], $sessionData['child_class'], $sessionData['child_school_number']) && is_array($sessionData['answers'])) {

            // Session verilerini participantData yapısına uygun hale getir (görüntüleme için)
            $participantData = [
                'name' => 'Veli', // Veli anketi
                'class' => $sessionData['child_class'], // Çocuğun sınıfı
                'created_at' => date('Y-m-d H:i:s', $sessionData['timestamp'] ?? time()),
                'admin_id' => null, // Session'dan gelen sonuçta admin_id olmaz
                'description' => "Çocuğun Adı Soyadı: " . ($sessionData['child_name'] ?? 'Belirtilmemiş') . "\n" .
                                 "Çocuğun Okul No: " . ($sessionData['child_school_number'] ?? 'Belirtilmemiş') // Description alanına çocuk bilgilerini kaydet
            ];
            $surveyTitle = $testTitleDefault; // Session için varsayılan başlık
            $pageTitle = $surveyTitle . " Sonuçları"; // Sayfa başlığını güncelle

            // Session'dan çocuk bilgilerini al
            $childName = $sessionData['child_name'] ?? 'Belirtilmemiş';
            $childClass = $sessionData['child_class'] ?? 'Belirtilmemiş';
            $childSchoolNumber = $sessionData['child_school_number'] ?? 'Belirtilmemiş';


            $sessionAnswers = $sessionData['answers'];
            $processedAnswerCount = 0; // İşlenen geçerli cevap sayısı

            // Soruları DB'den çekerek metinlerini alalım (tablo için)
            $questionSortOrderToTextMap = [];
             try {
                 $stmtQText = $pdo->prepare("SELECT sort_order, question AS question_text FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC");
                 $stmtQText->execute([$surveyId]);
                 $questionSortOrderToTextMap = $stmtQText->fetchAll(PDO::FETCH_KEY_PAIR); // [sort_order => question_text]
             } catch(Exception $e) { error_log("Session result question text fetch error (S{$surveyId}): " . $e->getMessage()); /* Hata set edilebilir */ }


            // Cevapları işle ve sayıları kaydet
            foreach ($sessionAnswers as $sortOrder => $answerText) {
                 $sortOrder_int = (int)$sortOrder;
                 $answerText_str = trim($answerText);

                 if (in_array($answerText_str, $optionsList)) { // Sadece geçerli cevapları al
                     $processedAnswerCount++; // Geçerli cevap sayısını artır

                     // Detaylı tablo için veriyi hazırla
                     $questionText = $questionSortOrderToTextMap[$sortOrder_int] ?? 'Soru metni yüklenemedi';
                     $processedAnswersForTable[] = [
                         'madde' => $sortOrder_int,
                         'question_text' => $questionText,
                         'verilen_cevap' => $answerText_str,
                     ];

                     // Boyutlara göre cevap sayılarını güncelle
                     foreach ($dimensions as $dimName => $qNumbers) {
                         if (in_array($sortOrder_int, $qNumbers)) {
                             $answerCountsPerDimension[$dimName][$answerText_str]++;
                             break; // İlgili boyutu bulduk
                         }
                     }
                 } else {
                      error_log("Invalid answer text '{$answerText_str}' found in session data for survey {$surveyId}, sort_order {$sortOrder_int}");
                 }
            }

            // Toplam beklenen soru sayısı (40)
            $stmt_total_questions = $pdo->prepare("SELECT COUNT(*) FROM survey_questions WHERE survey_id = ?");
            $stmt_total_questions->execute([$surveyId]);
            $totalExpectedQuestionsFetched = (int)$stmt_total_questions->fetchColumn();

            if ($processedAnswerCount < $totalExpectedQuestionsFetched) {
                 error_log("view-result-31 Session: Processed fewer valid answers ({$processedAnswerCount}) than expected ({$totalExpectedQuestionsFetched}) from session data.");
                 // Eksik cevap durumunda answerCountsPerDimension eksik veriyi yansıtacak
                 $evaluationText = "Anket cevapları eksik olduğu için değerlendirme tam yapılamadı.";

            } else {
                 // 3. Değerlendirme Metni Oluştur (Session verisi için)
                 $evaluationText = "Bu anket sonuçları, çocuğunuzun son 6 ayda maruz kaldığı şiddet durumlarına ilişkin veli gözlemini yansıtmaktadır. ";

                 $violenceSituationsFound = false;
                 foreach ($answerCountsPerDimension as $dimName => $counts) {
                     $frequentViolence = ($counts["Ayda Birkaç Kez Oldu"] ?? 0) + ($counts["Hemen Hemen Her Gün Oldu"] ?? 0);
                     if ($frequentViolence > 0) {
                         $violenceSituationsFound = true;
                         $evaluationText .= "<strong>{$dimName}:</strong> {$frequentViolence} durumda şiddet yaşanmıştır. ";
                     }
                 }

                 if (!$violenceSituationsFound) {
                     $evaluationText .= "Veli gözlemine göre çocuğunuzun son 6 ayda anket kapsamında belirtilen şiddet durumlarına maruz kalmadığı görülmektedir.";
                 } else {
                      $evaluationText .= "Detaylı bilgi için aşağıdaki cevap tablosunu inceleyebilirsiniz.";
                 }

            }


            unset($_SESSION['survey_result_data']); // Başarılı okuma sonrası temizle
        } else {
            // Session verisi eksikse hata set et ve logla
            error_log("Incomplete session data for survey 31: " . print_r($sessionData, true));
            $error = "Oturumdan alınan sonuç verisi eksik veya geçersiz (Kod: SESSION_INCOMPLETE).";
            $participantData = null; // Eksikse katılımcı verisi de yok
            $answerCountsPerDimension = []; // Eksikse de temizle
            $processedAnswersForTable = [];
            $evaluationText = "Oturum verisi hatası nedeniyle değerlendirme yapılamadı.";
            unset($_SESSION['survey_result_data']); // Eksikse de temizle
        }
    } else {
        // Session verisi tamamen yoksa hata set et
        $error = "Görüntülenecek sonuç bulunamadı veya oturum süresi dolmuş olabilir (Kod: SESSION_MISSING).";
        $participantData = null;
        $answerCountsPerDimension = [];
        $processedAnswersForTable = [];
        $evaluationText = "Sonuç verisi bulunamadı.";
    }
}
// --- VERİ KAYNAĞI SONU ---

// Psikometrik logo kontrolü
if ($psikometrikWebURL) {
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/..');
    $fullPsikoServerPath = rtrim($docRoot, '/') . '/' . ltrim(str_replace('..', '', $psikometrikWebURL), '/');
    if (!file_exists($fullPsikoServerPath)) { $psikometrikWebURL = null; error_log("Psikometrik logo dosyası bulunamadı (view-result-31): " . $fullPsikoServerPath); }
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
    <meta name="description" content="<?= htmlspecialchars($pageTitle) ?> sonuçlarınızı görüntüleyin. Çocuğunuzun şiddet sıklığı anketi sonuçları.">
    <meta name="keywords" content="şiddet sıklığı, veli anketi, çocuk, evde şiddet, okulda şiddet, siber şiddet, anket sonuçları">
    <meta name="robots" content="noindex, nofollow"> <link rel="canonical" href="https://www.yourwebsite.com/admin/view-result-<?= $surveyId ?>.php<?= $participantId ? '?id=' . $participantId : '' ?>"> <link rel="icon" href="/favicon.png" type="image/png">

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

        /* --- Katılımcı Bilgileri Stili --- */
        .participant-info {
             margin-bottom: 1.5rem;
             padding: 15px;
             background-color: #f9fafb;
             border: 1px solid #f3f4f6;
             border-radius: 8px;
        }
        .participant-info h2 {
            margin-top: 0;
            text-align: center;
            font-size: 1.4rem;
            color: #15803d;
            border-bottom: 1px solid #eee;
            padding-bottom: 0.4rem;
            margin-bottom: 1rem;
        }
        .participant-info p {
            margin: 0.4rem 0;
            font-size: 1rem;
        }
        .participant-info strong {
             font-weight: 600;
             color: #374151;
             min-width: 180px; /* Etiket genişliği ayarlandı */
             display: inline-block;
             margin-right: 10px; /* Etiket ve değer arasına boşluk */
        }


        /* --- Boyutlara Göre Özet Stili --- */
        .dimension-summary-section {
            margin-bottom: 30px;
            padding: 20px;
            background-color: #e8f5e9;
            border: 1px solid #c8e6c9;
            border-radius: 8px;
        }
        .dimension-summary-section h3 {
             font-size: 1.4rem;
             color: #15803d;
             margin-top: 0;
             margin-bottom: 1rem;
             border-bottom: 1px solid #eee;
             padding-bottom: 0.4rem;
        }
        .dimension-summary-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
            font-size: 0.95rem;
            background-color: #fff;
        }
        .dimension-summary-table th, .dimension-summary-table td {
            border: 1px solid #ddd;
            padding: 10px 12px;
            text-align: left;
            vertical-align: middle;
        }
        .dimension-summary-table th {
            background-color: #dcfce7;
            font-weight: 600;
            color: #1f2937;
        }
        .dimension-summary-table td:nth-child(1) { font-weight: bold; width: 30%; } /* Boyut Adı */
        .dimension-summary-table td { text-align: center; } /* Cevap sayıları ortala */
        .dimension-summary-table tr:nth-child(even) { background-color: #f8f9fa; }


        /* --- Değerlendirme Metni Stili --- */
        .evaluation-text-section {
            margin-bottom: 30px;
            padding: 20px;
            background-color: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1em;
            color: #475569;
            line-height: 1.5;
        }
         .evaluation-text-section h3 {
             font-size: 1.4rem;
             color: #15803d;
             margin-top: 0;
             margin-bottom: 1rem;
             border-bottom: 1px solid #eee;
             padding-bottom: 0.4rem;
         }
         .evaluation-text-section p strong { color: #dc3545; } /* Şiddet vurgusu için renk */


        /* --- Detaylı Cevap Tablosu Stili --- */
        .results-table-section h3 {
             font-size: 1.4rem;
             color: #15803d;
             margin-top: 2rem;
             margin-bottom: 1rem;
             border-bottom: 1px solid #eee;
             padding-bottom: 0.4rem;
        }
        .results-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
            font-size: 0.9rem;
        }
        .results-table th, .results-table td {
            border: 1px solid #ddd;
            padding: 8px 10px;
            text-align: left;
            vertical-align: top;
        }
        .results-table th {
            background-color: #dcfce7;
            font-weight: 600;
            color: #1f2937;
        }
         .results-table td:nth-child(1) {
             width: 10%; /* Madde No genişliği */
             text-align: center;
             font-weight: bold;
             vertical-align: middle;
         } /* Madde No */
        .results-table td:nth-child(2) { width: 60%; line-height: 1.4; } /* Durum Metni */
        .results-table td:nth-child(3) { width: 30%; text-align: center; vertical-align: middle;} /* Verilen Cevap */
        .results-table tr:nth-child(even) { background-color: #f8f9fa; }


        .error-box { color: #b91c1c; background-color: #fee2e2; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; border: 1px solid #fca5a5; font-weight: 500; text-align: left; font-size: 0.9em;}
        .error-box b { font-weight: bold; }

        .button-container {
             text-align: center;
             margin-top: 2.5rem;
             padding-top: 1.5rem;
             border-top: 1px solid #e0e0e0;
             display: flex;
             justify-content: center;
             gap: 1rem;
        }
        .action-button {
             display: inline-flex;
             align-items: center;
             padding: 10px 20px;
             font-size: 1rem;
             font-weight: 600;
             color: white;
             background-color: #15803d; /* Yeşil */
             border: none;
             border-radius: 6px;
             cursor: pointer;
             text-decoration: none;
             transition: background-color 0.2s ease, box-shadow 0.2s ease;
             box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        .action-button:hover { background-color: #0b532c; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .action-button.print-button { background-color: #15803d; }
        .action-button.print-button:hover { background-color: #0b532c; }
        .action-button.panel-button { background-color: #6c757d; } /* Gri */
        .action-button.panel-button:hover { background-color: #5a6268; }


         /* --- Yazdırma Stilleri --- */
         @media print {
             body {
                 background-color: #fff;
                 padding: 0;
                 margin: 0;
                 font-size: 10pt;
                 margin: 20mm;
             }
             .container {
                 box-shadow: none;
                 border: none;
                 margin: 0;
                 padding: 0;
                 max-width: 100%;
             }
             .page-header {
                 padding: 10px 0;
                 border-bottom: 1px solid #000;
                 box-shadow: none;
                 display: flex;
                 justify-content: space-between;
                 align-items: center;
             }
             .page-header .logo-left img,
             .page-header .logo-right img {
                max-height: 40px;
                width: auto;
             }
             .page-header .logo-left { justify-content: flex-start; }
             .page-header .logo-right { justify-content: flex-end; }
             .page-header .page-title {
                 flex: 2;
                 text-align: center;
                 font-size: 14pt;
                 color: #000;
                 margin: 0;
             }

             /* Butonları yazdırmada gizle */
             .button-container {
                 display: none;
             }

             /* Grafik olmadığı için chart-section gizli kalabilir veya kaldırılabilir */
             .chart-section {
                 display: none;
             }

             /* Özet ve değerlendirme bölümü */
             .dimension-summary-section, .evaluation-text-section {
                 margin-bottom: 15px;
                 padding: 10px;
                 border: 1px solid #eee;
                 background-color: #fff;
             }
              .dimension-summary-section h3, .evaluation-text-section h3 {
                  font-size: 11pt;
                  margin-bottom: 10px;
              }
              .dimension-summary-table, .results-table {
                   font-size: 9pt;
                   margin-top: 10px;
              }
              .dimension-summary-table th, .dimension-summary-table td,
              .results-table th, .results-table td {
                  border: 1px solid #000;
                  padding: 5px 8px;
              }
              .dimension-summary-table th {
                  background-color: #eee;
                  color: #000;
              }
              .dimension-summary-table td {
                  font-weight: normal;
              }
              .dimension-summary-table td:nth-child(1) { font-weight: bold; } /* Boyut adı kalın kalsın */

         }

    </style>
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
                 error_log("Psikometrik logo dosyası bulunamadı (view-result-31): " . $fullPsikoServerPath);
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
    <?php elseif (!$participantData && empty($processedAnswersForTable) && empty($answerCountsPerDimension)): ?>
         <?php // Eğer katılımcı verisi yoksa VE cevaplar/sayılar boşsa genel hata göster ?>
         <h1>Sonuçlar Yüklenemedi</h1>
         <div class="error-box">Görüntülenecek katılımcı verisi bulunamadı veya sonuçlar işlenemedi.</div>
    <?php else: // Katılımcı verisi var veya en azından boyut isimleri mevcut ?>

        <h1><?= htmlspecialchars($surveyTitle) ?> Sonuçları</h1> <?php // Anket başlığı kullanıldı ?>

        <?php // Katılımcı bilgileri varsa göster ?>
        <?php if ($participantData): ?>
        <div class="participant-info">
             <h2>Çocuğun Bilgileri</h2>
             <p><strong>Adı Soyadı:</strong> <?= htmlspecialchars($childName ?? 'Belirtilmemiş') ?></p>
             <p><strong>Sınıfı:</strong> <?= htmlspecialchars($childClass ?? 'Belirtilmemiş') ?></p>
             <p><strong>Okul No:</strong> <?= htmlspecialchars($childSchoolNumber ?? 'Belirtilmemiş') ?></p>
             <p><strong>Anket Tamamlanma Tarihi:</strong> <?= date('d.m.Y H:i', strtotime($participantData['created_at'] ?? (@$_SESSION['survey_result_data']['timestamp'] ? date('Y-m-d H:i:s', $_SESSION['survey_result_data']['timestamp']) : time()))) ?></p>
             <?php if ($dataSource === 'db' && !empty($participantData['admin_id'])): ?>
                  <p><strong>Yönetici ID:</strong> <?= htmlspecialchars($participantData['admin_id']) ?></p>
             <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php // Boyutlara Göre Özet ?>
        <?php if (!empty($answerCountsPerDimension)): ?>
        <div class="dimension-summary-section">
            <h3>Boyutlara Göre Şiddet Sıklığı Özeti</h3>
            <table class="dimension-summary-table">
                <thead>
                    <tr>
                        <th>Boyut</th>
                        <th>Hiç Olmadı</th>
                        <th>Ayda Birkaç Kez Oldu</th>
                        <th>Hemen Hemen Her Gün Oldu</th>
                        <th>Toplam Madde</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dimensions as $dimName => $qNumbers): ?>
                    <?php
                         $counts = $answerCountsPerDimension[$dimName] ?? [];
                         $hicOlmadi = $counts["Hiç Olmadı"] ?? 0;
                         $aydaBirkacKezOldu = $counts["Ayda Birkaç Kez Oldu"] ?? 0;
                         $hemenHemenHerGunOldu = $counts["Hemen Hemen Her Gün Oldu"] ?? 0;
                         $totalQuestionsInDimension = $counts['total_questions'] ?? count($qNumbers);
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($dimName) ?></td>
                        <td><?= htmlspecialchars($hicOlmadi) ?></td>
                        <td><?= htmlspecialchars($aydaBirkacKezOldu) ?></td>
                        <td><?= htmlspecialchars($hemenHemenHerGunOldu) ?></td>
                        <td><?= htmlspecialchars($totalQuestionsInDimension) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php // Değerlendirme Metni ?>
        <div class="evaluation-text-section">
             <h3>Sonuç Değerlendirmesi</h3>
             <?php // htmlspecialchars() kaldırıldı ?>
             <p><?= nl2br($evaluationText) ?></p>
        </div>


        <?php // Detaylı Cevap Tablosu ?>
        <div class="results-table-section">
             <h3>Verilen Cevaplar</h3>
             <?php if (!empty($processedAnswersForTable)): ?>
                 <table class="results-table">
                     <thead>
                         <tr>
                             <th>Madde No</th>
                             <th>Durum</th>
                             <th>Verilen Cevap</th>
                         </tr>
                     </thead>
                     <tbody>
                         <?php foreach ($processedAnswersForTable as $row): ?>
                             <tr>
                                 <td><?= htmlspecialchars($row['madde']) ?></td>
                                 <td><?= htmlspecialchars($row['question_text']) ?></td>
                                 <td><?= htmlspecialchars($row['verilen_cevap']) ?></td>
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

</body>
</html>
