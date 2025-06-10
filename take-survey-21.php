<?php
// take-survey-21.php (Rathus Atılganlık Envanteri v2 - Güncellenmiş Soru Listesi)

// --- Hata Raporlama ---
ini_set('display_errors', 1); error_reporting(E_ALL);
// ---------------------------

session_start(); // Session GEREKLİ

// --- Veritabanı Bağlantısı ---
require_once __DIR__ . '/src/config.php';
if (!isset($pdo) || !$pdo instanceof PDO) {
    error_log("CRITICAL: PDO connection object (\$pdo) not created or found in src/config.php");
    header('Content-Type: text/html; charset=utf-8');
    die('<div style="border: 1px solid red; padding: 10px; margin: 10px; background-color: #ffebeb; color: red; font-family: sans-serif;"><b>Kritik Hata:</b> Veritabanı bağlantısı kurulamadı (src/config.php).</div>');
}
// --------------------------------

// --- Konfigürasyon ---
$surveyId = 21; // Anket ID'si
$testTitleDefault = "Rathus Atılganlık Envanteri";
// ---------------------

// --- Değişken Başlatma ---
$adminId = null; $error = null; $post_error = null;
$adminCheckError = null; $survey = null; $questions = [];
$form_data = ($_SERVER["REQUEST_METHOD"] == "POST") ? $_POST : [];
// -----------------------------

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
// Metinden puanı bulmak için ters harita (Session hesaplaması için kullanılabilir)
$textToScoreMap = array_flip($optionsMap);

// Olumlu ve Olumsuz Maddelerin Sıra Numaraları (sort_order)
// Bu listeler standart Rathus puanlama anahtarına göredir.
// Soru metinleri değişse bile, puanlama bu sort_order listelerine göre yapılır.
$positiveItems = [3, 6, 7, 8, 10, 18, 20, 21, 22, 25, 27, 28, 29]; // 13 madde
$negativeItems = [1, 2, 4, 5, 9, 11, 12, 13, 15, 16, 17, 19, 23, 24, 26, 30]; // 17 madde

// Skorlama Haritaları (Cevap Metnine Göre Puan)
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
     else return "Geçersiz Puan Aralığı ({$totalScore}).";
}
// -------------------------------------------


// --- Yönetici ID Kontrolü ---
if (isset($_GET['admin_id']) && !empty($_GET['admin_id'])) {
    $potentialAdminId = filter_var($_GET['admin_id'], FILTER_VALIDATE_INT);
    if ($potentialAdminId === false) { $adminCheckError = 'URL\'de geçersiz yönetici ID formatı.'; }
    else {
        try {
            $adminStmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
            $adminStmt->execute([$potentialAdminId]);
            $admin = $adminStmt->fetch(PDO::FETCH_ASSOC);
            if ($admin) { $adminId = $admin['id']; }
            else { $adminCheckError = "Belirtilen yönetici ID ({$potentialAdminId}) sistemde bulunamadı."; }
        } catch (Exception $e) { $error = 'Yönetici bilgileri kontrol edilirken bir veritabanı hatası oluştu.'; error_log("Admin ID Check Error (S{$surveyId}): ".$e->getMessage()); }
    }
}
// --- Yönetici ID Kontrolü Sonu ---


// --- POST İŞLEME ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === null) {

    // 1. POST Verisini Al
    $surveyId_post = filter_input(INPUT_POST, 'survey_id', FILTER_VALIDATE_INT);
    $participantName = trim(htmlspecialchars($_POST['participant_name'] ?? '', ENT_QUOTES, 'UTF-8'));
    $participantClass = isset($_POST['participant_class']) ? trim(htmlspecialchars($_POST['participant_class'], ENT_QUOTES, 'UTF-8')) : null;
    $answers = $_POST['answers'] ?? []; // Dizi: [question_db_id => answer_text (e.g., "Çok iyi uyuyor")]

    // 2. Doğrulama için Soru Bilgilerini Çek
    $questionIdToSortOrderMap = []; // ID -> sort_order haritası
    $expectedQuestionCount = 0; // Toplam soru sayısı

    try {
        // POST edilen soru ID'lerinin geçerliliğini ve sort_order'larını çekmek için
        $questionIdsFromPost = array_keys($answers);
        if (!empty($questionIdsFromPost)) {
             // Sadece sayısal ID'leri filtrele
             $safeQuestionIds = array_filter($questionIdsFromPost, 'is_numeric');
             if (!empty($safeQuestionIds)) {
                 $placeholders = implode(',', array_fill(0, count($safeQuestionIds), '?'));
                 // question_text'i de çekelim, Session'a kaydederken detaylı tablo için lazım olabilir
                 $stmt_q_check = $pdo->prepare("SELECT id, sort_order, question AS question_text FROM survey_questions WHERE survey_id = ? AND id IN ({$placeholders})");
                 $executeParams = array_merge([$surveyId], $safeQuestionIds);
                 $stmt_q_check->execute($executeParams);
                 $dbQuestionsInfo = $stmt_q_check->fetchAll(PDO::FETCH_ASSOC);

                 // ID -> sort_order ve ID -> question_text haritalarını oluştur
                 $questionIdToTextMap = [];
                 foreach($dbQuestionsInfo as $q) {
                     $questionIdToSortOrderMap[$q['id']] = $q['sort_order'];
                     $questionIdToTextMap[$q['id']] = $q['question_text'];
                 }
             }
        }

        // Toplam soru sayısını çek
        $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM survey_questions WHERE survey_id = ?");
        $stmt_count->execute([$surveyId]);
        $expectedQuestionCount = (int)$stmt_count->fetchColumn();

    } catch (PDOException $e) {
         error_log("Soru bilgileri alınamadı (S{$surveyId}) POST: " . $e->getMessage());
         $post_error = "Soru bilgileri yüklenirken bir hata oluştu.";
    }


    if ($post_error === null) { // Veritabanı hatası yoksa devam et
        if ($surveyId_post !== $surveyId) { $post_error = "Geçersiz anket bilgisi."; }
        elseif (empty($participantName)) { $post_error = "Ad Soyad alanı boş bırakılamaz."; }
        elseif (empty($answers) || !is_array($answers)) { $post_error = "Cevaplar alınamadı."; }
        elseif (count($answers) < $expectedQuestionCount) {
             $post_error = "Lütfen tüm soruları cevaplayın.";
        } else {
            // Cevapların geçerliliğini (optionsMap'te var mı?) ve gönderilen ID'lerin DB'de varlığını kontrol et
            $answeredCount = 0; $invalidDataFound = false;
            foreach ($answers as $qDbId => $answerValue) {
                $qIdCheck = filter_var($qDbId, FILTER_VALIDATE_INT);
                // Cevap metni optionsMap'in değerlerinden biri mi?
                $isValidAnswerValue = in_array($answerValue, $optionsMap);
                // Gönderilen soru ID'si (qDbId) çekilen questionIdToSortOrderMap içinde var mı?
                $isValidQuestionId = ($qIdCheck !== false && isset($questionIdToSortOrderMap[$qIdCheck]));

                if ($isValidQuestionId && $isValidAnswerValue) { $answeredCount++; }
                else { $invalidDataFound = true; break; }
            }
            if ($invalidDataFound) { $post_error = "Geçersiz cevap veya soru ID'si."; }
            // Count kontrolü yukarıda yapıldı
        }
    }

    // ---- KOŞULLU KAYIT ve YÖNLENDİRME ----
    if ($post_error === null) { // Tüm doğrulamalar başarılıysa devam et
        if ($adminId !== null) {
            // **** SENARYO 1: ADMIN VAR -> Veritabanına KAYDET, tamamlandi.php'ye YÖNLENDİR ****
            $participant_id = null;
            try {
                $pdo->beginTransaction();
                // Katılımcı ekle
                 $stmtParticipant = $pdo->prepare("INSERT INTO survey_participants (name, class, survey_id, admin_id, created_at) VALUES (:name, :class, :survey_id, :admin_id, NOW())");
                 $stmtParticipant->bindParam(':name', $participantName, PDO::PARAM_STR);
                 $stmtParticipant->bindParam(':class', $participantClass, PDO::PARAM_STR);
                 $stmtParticipant->bindParam(':survey_id', $surveyId, PDO::PARAM_INT);
                 $stmtParticipant->bindParam(':admin_id', $adminId, PDO::PARAM_INT);
                 if (!$stmtParticipant->execute()) { throw new PDOException("Katılımcı kaydı başarısız: " . implode(";", $stmtParticipant->errorInfo())); }
                 $participant_id = $pdo->lastInsertId();
                 if (!$participant_id) { throw new Exception("Katılımcı ID alınamadı."); }

                // Cevapları Ekle (question_id sütunına sort_order yazılacak, answer_text'e cevap metni)
                $stmtAnswer = $pdo->prepare(
                    "INSERT INTO survey_answers (participant_id, survey_id, question_id, answer_text, created_at)
                     VALUES (:pid, :sid, :qid_sort_order, :answer_text, NOW())" // qid_sort_order placeholder'ı
                );
                foreach ($answers as $actual_db_id => $answer_text) {
                    $qId_int = (int)$actual_db_id;
                    $answer_text_str = trim($answer_text);

                    // *** Orijinal DB ID'ye karşılık gelen sort_order'ı al ***
                    $sort_order_to_save = $questionIdToSortOrderMap[$qId_int] ?? null;

                    if ($sort_order_to_save !== null) {
                        $stmtAnswer->bindParam(':pid', $participant_id, PDO::PARAM_INT);
                        $stmtAnswer->bindParam(':sid', $surveyId, PDO::PARAM_INT);
                        // *** question_id sütununa sort_order değeri bağlanıyor ***
                        $stmtAnswer->bindParam(':qid_sort_order', $sort_order_to_save, PDO::PARAM_INT);
                        $stmtAnswer->bindParam(':answer_text', $answer_text_str, PDO::PARAM_STR); // Cevap metni

                        if (!$stmtAnswer->execute()) {
                            error_log("Cevap kaydı başarısız (OriginalQID:" . $qId_int . ", SavingSortOrder:" . $sort_order_to_save . "): " . implode(";", $stmtAnswer->errorInfo()));
                            throw new PDOException("Cevap kaydı sırasında veritabanı hatası oluştu.");
                        }
                    } else {
                        // Bu durum, formdan gelen bir soru ID'sinin sort_order haritasında bulunamaması durumunda oluşur.
                        error_log("Sort order not found for QID: {$qId_int} during DB save in survey {$surveyId}");
                        throw new Exception("Cevap verisi işlenirken bir hata oluştu (Soru ID: {$qId_int}).");
                    }
                }
                $pdo->commit();
                session_write_close(); // Session yazmayı bitir
                header('Location: tamamlandi.php?pid=' . $participant_id);
                exit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log("Admin Survey Submit Exception (S{$surveyId}, Admin{$adminId}, PName:{$participantName}): " . $e->getMessage());
                $post_error = "<b>Kayıt Sırasında Bir Hata Oluştu!</b> Lütfen tekrar deneyin veya yöneticiyle iletişime geçin. (Detaylar loglandı)";
            }

        } else {
             // **** SENARYO 2: ADMIN YOK -> KAYDETME, SONUCU HESAPLA, SESSION'A AT, view-result-21.php'ye YÖNLENDİR ****
             // Skor hesaplaması sort_order'a göre yapıldığı için questionIdToSortOrderMap'e ihtiyaç var.
             // Bu harita POST doğrulaması sırasında zaten çekiliyor.

             if ($post_error === null && !empty($questionIdToSortOrderMap)) { // questionIdToSortOrderMap POST doğrulamada çekildi
                 $totalScore = 0;
                 $sessionAnswersData = []; // Session'a kaydedilecek cevaplar [sort_order => answer_text] formatında

                 foreach ($answers as $qDbId => $answerValue) {
                     $questionId = (int)$qDbId; // Orijinal DB ID
                     $answerText = trim($answerValue); // Cevap metni

                     $sortOrder = $questionIdToSortOrderMap[$questionId] ?? null; // sort_order'ı bul

                     if ($sortOrder !== null) {
                         // Session'a orijinal soru ID'si yerine sort_order ve cevap metnini kaydedelim
                         $sessionAnswersData[$sortOrder] = $answerText;

                         // Cevap metninden sayısal puanı bul
                         $originalScore = $textToScoreMap[$answerText] ?? null;

                         if ($originalScore !== null) {
                             // Skoru hesapla (olumlu/olumsuz maddeye göre)
                             if (in_array($sortOrder, $positiveItems)) {
                                 // Olumlu madde puanlaması
                                 $calculatedScore = $positiveScoring[$answerText] ?? 0;
                             } elseif (in_array($sortOrder, $negativeItems)) {
                                 // Olumsuz madde puanlaması
                                 $calculatedScore = $negativeScoring[$answerText] ?? 0;
                             } else {
                                 // Ne olumlu ne olumsuz maddede bulunursa logla
                                 error_log("Sort_order {$sortOrder} not found in positiveItems or negativeItems for survey {$surveyId} during Session score calculation");
                                 $calculatedScore = 0; // Skora dahil etme
                             }
                             $totalScore += $calculatedScore;
                         } else {
                             // Cevap metni optionsMap'te bulunamazsa logla
                             error_log("Answer text '{$answerText}' not found in optionsMap for survey {$surveyId}, sort_order {$sortOrder} during Session score calculation");
                             // Bu cevabı skora dahil etme
                         }

                     } else {
                          error_log("Cannot find sort_order for QID: {$questionId} in survey {$surveyId} during Session score calculation");
                          // Sort order bulunamazsa bu cevabı skora dahil etme
                     }
                 }

                 // Yorumlama
                 $interpretation = interpretAssertivenessScore($totalScore);

                 $_SESSION['survey_result_data'] = [
                     'survey_id' => $surveyId,
                     'total_score' => $totalScore, // Hesaplanan toplam skor
                     'interpretation' => $interpretation,
                     'answers' => $sessionAnswersData, // [sort_order => answer_text] olarak kaydediliyor
                     'participant_name' => $participantName,
                     'participant_class' => $participantClass,
                     'timestamp' => time()
                 ];
                 session_write_close(); // Session yazmayı bitir
                 // Yönlendirme yolu admin dizinini içerecek şekilde güncellendi
                 header('Location: ../admin/view-result-21.php');
                 exit();
             } else if ($post_error === null) {
                 // questionIdToSortOrderMap boşsa ama post_error set edilmediyse beklenmedik durum
                 error_log("Unexpected empty questionIdToSortOrderMap after validation for survey {$surveyId} during Session handling.");
                 $post_error = "İşlem sırasında beklenmedik bir hata oluştu. Lütfen tekrar deneyin.";
             }
        }
    }
}
// --- POST İŞLEME SONU ---


// --- Formu Göstermek İçin Veri Çek ---
// Bu kısım değişmedi, formu göstermek için ID, question_text ve sort_order çekiliyor
if ($error === null) {
     try {
        $stmtSurvey = $pdo->prepare("SELECT title FROM surveys WHERE id = ?"); $stmtSurvey->execute([$surveyId]); $survey = $stmtSurvey->fetch(PDO::FETCH_ASSOC);
        // 'question' sütununu kullan
        $stmtQuestions = $pdo->prepare("SELECT id, question AS question_text, sort_order FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC, id ASC");
        $stmtQuestions->execute([$surveyId]); $questions = $stmtQuestions->fetchAll(PDO::FETCH_ASSOC);
        if (empty($questions)) { if ($post_error === null) { $error = "Bu anket ({$surveyId}) için veritabanında soru bulunamadı..."; } $questions = []; }
        else { /* Sort order kontrolü... */ }
    } catch (Exception $e) { if ($post_error === null) { $error = "Anket verileri yüklenirken hata oluştu."; } error_log("Data Fetch Error: ".$e->getMessage()); $questions = []; }
}
// --- Veri Çekme Sonu ---

// Sayfa başlığı vs.
$pageTitle = isset($survey['title']) ? htmlspecialchars($survey['title']) : $testTitleDefault;
$actionUrl = "take-survey-21.php";
if ($adminId !== null) { $actionUrl .= "?admin_id=" . htmlspecialchars($adminId); }
if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> <?= $adminId ? '- Yönetici (' . htmlspecialchars($adminId) . ')' : '- Ücretsiz Anket' ?></title>
    <style>
        /* --- Stil Bloğu (Yeşil Tema - Tıklanabilir Tam Metin Butonları) --- */
        body { font-family: sans-serif; line-height: 1.6; background-color: #f0fdf4; color: #2c3e50; margin: 0; padding: 20px; }
        .container { max-width: 800px; margin: 40px auto; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); text-align: left; background-color: #ffffff; }
        h2 { text-align: center; font-size: 1.5rem; font-weight: 600; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 2px solid #dcfce7; color: #1f2937; }
        .instructions { background-color: #e8f5e9; padding: 15px; border-radius: 5px; margin-bottom: 25px; font-size: 0.95em; border: 1px solid #c8e6c9; }
        .instructions p { margin: 0.5rem 0; }

        .question-block { margin-bottom: 15px; padding: 10px 0; border-bottom: 1px solid #eee; }
        .question-block:last-child { border-bottom: none; }
        .question-text { display: block; font-weight: 500; margin-bottom: 10px; font-size: 0.95em; color: #1f2937; }
        .question-text.validation-error { color: #dc2626 !important; font-weight: bold; }
        .options-group-styled { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; padding-top: 5px;}
        input[type="radio"].hidden-radio { position: absolute; opacity: 0; width: 0; height: 0; pointer-events: none; }
         .option-label-button { cursor: pointer; padding: 8px 12px; border: 1px solid #ced4da; border-radius: 5px; background-color: #fff; transition: background-color 0.2s, border-color 0.2s, color 0.2s; text-align: center; font-size: 0.85em; line-height: 1.4; display: inline-block; user-select: none; min-width: 100px; } /* Genişlik ayarlandı */
        input[type="radio"].hidden-radio:checked + label.option-label-button { background-color: #15803d; color: white; border-color: #0b532c; font-weight: bold; } /* Renkler yeşile uyarlandı */
        .option-label-button:hover { background-color: #dcfce7; border-color: #a7f3d0;}
        input[type="radio"].hidden-radio:checked + label.option-label-button:hover { background-color: #0b532c; }

        .nav-button { padding: 12px 30px; border-radius: 8px; font-weight: 600; transition: all 0.2s ease-in-out; cursor: pointer; border: none; color: white; display: block; width: 100%; margin-top: 2rem;}
        .nav-button.submit { background: #15803d; } .nav-button.submit:hover { background: #0b532c; }
        .nav-button:disabled { background-color: #9ca3af; cursor: not-allowed; opacity: 0.7;}
        .info { margin-bottom: 1.5rem; padding-bottom: 1.5rem; border-bottom: 1px solid #f3f4f6;}
        .info label { display: block; margin-bottom: 5px; font-weight: 600; color: #374151; }
        .info input { padding: 8px 10px; width: 100%; box-sizing: border-box; border: 1px solid #ccc; border-radius: 6px; font-size: 1em; color: #2c3e50; height: 40px; background-color: white;}
        .info input:focus { border-color: #15803d; box-shadow: 0 0 0 2px rgba(34, 197, 94, 0.3); outline: none; }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; }
        .error-box { color: #b91c1c; background-color: #fee2e2; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; border: 1px solid #fca5a5; font-weight: 500; text-align: left; font-size: 0.9em;}
        .error-box b { font-weight: bold; }
        .admin-info-box { color: #0f5132; background-color: #d1e7dd; border-color: #badbcc; padding: 0.75rem 1rem; margin-bottom: 1.5rem; border: 1px solid transparent; border-radius: 0.375rem; font-size: 0.9em;}
        .public-info-box { background-color: #cfe2ff; border-color: #b6d4fe; color: #084298; padding: 0.75rem 1rem; margin-bottom: 1.5rem; border: 1px solid transparent; border-radius: 0.375rem; font-size: 0.9em;}
        .required-star { color: #dc2626; font-weight: bold; margin-left: 2px; }
        .validation-error { border-color: #ef4444 !important; }
        .validation-error-text { color: #dc2626; font-size: 0.75rem; line-height: 1rem; margin-top: 0.25rem; min-height: 1.2em; }
        legend.validation-error { color: #dc2626 !important; font-weight: bold; }
        .mt-6 { margin-top: 1.5rem; } a { color: #15803d; } a:hover { color: #0b532c; }
        .text-center { text-align: center; }
        .question-block .validation-error-text { margin-left: 0; width: 100%; }
    </style>
</head>
<body>
    <div class="container">
        <h2><?= $pageTitle ?></h2>

        <?php if ($error): ?> <div class="error-box" role="alert"><b>Hata:</b> <?= htmlspecialchars($error) ?></div>
        <?php elseif ($post_error): ?> <div class="error-box" role="alert"><?= htmlspecialchars($post_error) ?></div>
         <?php elseif (isset($adminCheckError) && $adminCheckError): ?> <div class="error-box" role="alert" style="..."><b>Uyarı:</b> <?= htmlspecialchars($adminCheckError) ?>...</div>
        <?php endif; ?>


        <?php // Formu Göster ?>
        <?php if (!$error && !empty($questions)): ?>

            <?php // Bilgilendirme kutusu ?>
            <?php if ($adminId !== null): ?> <div class="admin-info-box">Bu anket <strong>Yönetici ID: <?= htmlspecialchars($adminId) ?></strong> tarafından başlatıldı...</div> <?php else: ?> <div class="public-info-box">Bu herkese açık ücretsiz bir ankettir...</div> <?php endif; ?>

            <form method="POST" id="surveyForm" action="<?= $actionUrl ?>" novalidate>
                <input type="hidden" name="survey_id" value="<?= $surveyId ?>">

                <div class="info">
                     <div class="info-grid">
                         <div>
                             <label for="participant_name">Ad Soyadınız <span class="required-star">*</span></label>
                             <input type="text" id="participant_name" name="participant_name" required placeholder="Adınızı ve soyadınızı girin..." value="<?= htmlspecialchars($form_data['participant_name'] ?? '') ?>" class="<?= ($post_error && empty($form_data['participant_name'])) ? 'validation-error' : '' ?>" aria-describedby="nameError">
                             <p id="nameError" class="validation-error-text" aria-live="polite"></p>
                         </div>
                         <div>
                             <label for="participant_class">Sınıfınız / Bölümünüz:</label>
                             <input type="text" id="participant_class" name="participant_class" placeholder="Örn: 10/A veya Psikoloji Bölümü..." value="<?= htmlspecialchars($form_data['participant_class'] ?? '') ?>" aria-describedby="classError">
                             <p id="classError" class="validation-error-text" aria-live="polite"></p>
                         </div>
                      </div>
                </div>

                 <div class="instructions">
                     <p>Aşağıda size 30 maddelik bir envanter verilmiştir. Her maddeyi dikkatlice okuyup, size uygunluk derecesine göre aşağıdaki seçeneklerden birini işaretleyerek cevaplandırınız.</p>
                     <p>
                         <strong>Çok iyi uyuyor</strong> (6 Puan),
                         <strong>Oldukça uyuyor</strong> (5 Puan),
                         <strong>Biraz uyuyor</strong> (4 Puan),
                         <strong>Pek uymuyor</strong> (3 Puan),
                         <strong>Fazla uymuyor</strong> (2 Puan),
                         <strong>Hiç uymuyor</strong> (1 Puan)
                     </p>
                      <p>Tüm maddeleri işaretlemeniz gerekmektedir.</p>
                 </div>

                <?php foreach ($questions as $question):
                    $question_id = $question['id'];
                    $sort_order = $question['sort_order'];
                    $submitted_answer = $form_data['answers'][$question_id] ?? null;
                 ?>
                    <div class="question-block" id="q_block_<?= $question_id ?>">
                        <span class="question-text <?= ($post_error && $submitted_answer === null) ? 'validation-error' : '' ?>" id="q_text_<?= $question_id ?>">
                           <?= htmlspecialchars($sort_order) ?>. <?= htmlspecialchars($question['question_text'] ?? '...') ?> <span class="required-star">*</span>
                        </span>
                        <div class="options-group-styled" role="radiogroup" aria-labelledby="q_text_<?= $question_id ?>">
                            <?php foreach ($optionsMap as $value => $label): // $value burada puanı temsil ediyor (6'dan 1'e)
                                $input_id = "q{$question_id}_opt{$value}";
                                $is_checked = ($submitted_answer !== null && $submitted_answer === $label); // Cevap metni ile karşılaştır
                            ?>
                                <?php // Input ve Label ayrı ?>
                                <input type="radio"
                                       class="hidden-radio"
                                       id="<?= $input_id ?>"
                                       name="answers[<?= $question_id ?>]"
                                       value="<?= $label // Cevap metnini gönder ?>"
                                       required <?= $is_checked ? 'checked' : '' ?>>
                                <label for="<?= $input_id ?>" class="option-label-button">
                                    <?= htmlspecialchars($label) ?>
                                </label>
                            <?php endforeach; ?>
                         </div>
                          <p id="qError_<?= $question_id ?>" class="validation-error-text" aria-live="polite"></p>
                    </div>
                 <?php endforeach; ?>


                <button type="submit" class="nav-button submit"> Anketi Tamamla ve Gönder </button>

            </form>

        <?php // Diğer hata/bilgi durumları ?>
        <?php elseif (!$error && !$post_error): ?>
             <div class="error-box" style="..."><strong>Bilgi:</strong> Anket yüklenemedi...</div>
        <?php endif; ?>
    </div>

    <?php // İstemci Taraflı Doğrulama ?>
     <?php if (!$error && !empty($questions)): ?>
    <script>
        // Bu JS kodu tüm soruların ve ismin girilmesini kontrol eder.
        // Eksik varsa alert gösterir ve formu GÖNDERMEZ.
         const form = document.getElementById('surveyForm');
         if (form) {
             form.addEventListener('submit', function(event) {
                 let firstErrorElement = null; let isValid = true;
                 // İsim kontrolü
                 const nameInput = document.getElementById('participant_name');
                 const nameErrorP = document.getElementById('nameError');
                 if (nameInput && nameErrorP) { if (!nameInput.value.trim()) { nameInput.classList.add('validation-error'); nameErrorP.textContent = 'Lütfen adınızı ve soyadınızı girin.'; isValid = false; if (!firstErrorElement) firstErrorElement = nameInput; } else { nameInput.classList.remove('validation-error'); nameErrorP.textContent = ''; } }
                 // Tüm soruların cevaplandığını kontrol et
                 const questionsBlocks = form.querySelectorAll('.question-block');
                 questionsBlocks.forEach(qBlock => {
                     const qid = qBlock.id.replace('q_block_', '');
                     const qText = qBlock.querySelector('.question-text');
                     const errorP = document.getElementById(`qError_${qid}`);
                     const radios = qBlock.querySelectorAll('input[type="radio"].hidden-radio');
                     let isAnswered = false;
                     radios.forEach(radio => { if (radio.checked) isAnswered = true; });
                     if (!isAnswered) { if(qText) qText.classList.add('validation-error'); if(errorP) errorP.textContent = 'Lütfen bu soru için bir seçim yapın.'; isValid = false; if (!firstErrorElement && qText) firstErrorElement = qText; }
                     else { if(qText) qText.classList.remove('validation-error'); if(errorP) errorP.textContent = ''; }
                 });
                 // Hata varsa formu gönderme ve odaklanma
                 if (!isValid) {
                     event.preventDefault(); // Formu göndermeyi engelle
                     alert('Lütfen tüm soruları cevaplayın.');
                     if (firstErrorElement) {
                        firstErrorElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                         try {
                             const focusTarget = firstErrorElement.closest('.question-block')?.querySelector('label.option-label-button');
                              if (focusTarget) focusTarget.focus();
                              else if (firstErrorElement.tagName === 'INPUT') firstErrorElement.focus();
                         } catch (e) {}
                     }
                 } else {
                     const submitButton = form.querySelector('button[type="submit"]');
                     if(submitButton) {
                         submitButton.disabled = true;
                         submitButton.textContent = 'Gönderiliyor...';
                     }
                 }
             });
         }
    </script>
     <?php endif; ?>

</body>
</html>