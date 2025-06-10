<?php
// take-survey-18.php (Mesleki Olgunluk Ölçeği v7 - Sort Order Kayıt ve Yönlendirme Düzeltildi)

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
$surveyId = 18;
$testTitleDefault = "Mesleki Olgunluk Ölçeği";
// ---------------------

// --- Değişken Başlatma ---
$adminId = null; $error = null; $post_error = null;
$adminCheckError = null; $survey = null; $questions = [];
$form_data = ($_SERVER["REQUEST_METHOD"] == "POST") ? $_POST : [];
// -----------------------------

// --- Sabit Veriler (Anket 18 için) ---
// Seçenekler ve Puanları (Tam Metin)
$optionsMap = [
    1 => "Bana Hiç Uygun Değil",
    2 => "Bana Pek Uygun Değil",
    3 => "Bana Biraz Uygun",
    4 => "Bana Uygun",
    5 => "Bana Çok Uygun"
];
// Metinden puanı bulmak için ters harita (Session hesaplaması için kullanılabilir)
$textToScoreMap = array_flip($optionsMap);


// Skorlama için X ve Y grubu maddeleri (SIRALI 1-40 NUMARALANDIRMA VARSAYILDI!)
// DİKKAT: Bu listelerin doğruluğunu orijinal ölçekten teyit edin!
$xItems = [5, 6, 7, 8, 10, 11, 18, 20, 23, 28, 29, 30, 31, 32, 34, 35, 37, 39, 40]; // 19 madde
$yItems = [1, 2, 3, 4, 9, 12, 13, 14, 15, 16, 17, 19, 21, 22, 24, 25, 26, 27, 33, 36, 38]; // 21 madde

// Skor Yorumlama Fonksiyonu
function interpretMaturityScore($tScore) {
     if ($tScore === null || !is_numeric($tScore)) return "Hesaplanamadı";
     if ($tScore < 143) return "Mesleki olgunluk düzeyiniz şu an için beklenenin altında görünüyor. Meslekleri ve kendinizi tanımaya yönelik daha fazla araştırma yapmanız faydalı olabilir.";
     elseif ($tScore <= 155) return "Mesleki olgunluk düzeyiniz normal aralıkta. Ancak daha isabetli kararlar için ilgi ve yeteneklerinizi araştırmaya devam etmeniz önerilir.";
     else return "Mesleki olgunluk düzeyiniz beklenenin üzerinde. İlgi, yetenek ve değerlerinize uygun meslekleri belirleme konusunda iyi bir noktadasınız."; // 155 üzeri
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
    $answers = $_POST['answers'] ?? []; // Dizi: [question_db_id => score (1-5)]

    // 2. Doğrulama için Soru Bilgilerini Çek
    $dbQuestions = []; // Soru ID'lerini ve sort_order'larını tutacak
    $questionIdToSortOrderMap = []; // ID -> sort_order haritası
    $expectedQuestionCount = 0;

    try {
        // POST edilen soru ID'lerinin geçerliliğini ve sort_order'larını çekmek için
        $questionIdsFromPost = array_keys($answers);
        if (!empty($questionIdsFromPost)) {
             // Sadece sayısal ID'leri filtrele
             $safeQuestionIds = array_filter($questionIdsFromPost, 'is_numeric');
             if (!empty($safeQuestionIds)) {
                 $placeholders = implode(',', array_fill(0, count($safeQuestionIds), '?'));
                 $stmt_q_check = $pdo->prepare("SELECT id, sort_order FROM survey_questions WHERE survey_id = ? AND id IN ({$placeholders})");
                 $executeParams = array_merge([$surveyId], $safeQuestionIds);
                 $stmt_q_check->execute($executeParams);
                 $dbQuestions = $stmt_q_check->fetchAll(PDO::FETCH_ASSOC); // Hem id hem sort_order çekildi

                 // ID -> sort_order haritasını oluştur
                 foreach($dbQuestions as $q) {
                     $questionIdToSortOrderMap[$q['id']] = $q['sort_order'];
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
            // Cevapların geçerliliğini ve gönderilen ID'lerin DB'de varlığını kontrol et
            $answeredCount = 0; $invalidDataFound = false;
            foreach ($answers as $qDbId => $aScore) {
                $qIdCheck = filter_var($qDbId, FILTER_VALIDATE_INT);
                $aScoreCheck = filter_var($aScore, FILTER_VALIDATE_INT);
                // Puan 1-5 arasında mı?
                $isValidScore = ($aScoreCheck !== false && $aScoreCheck >= 1 && $aScoreCheck <= 5);
                // Gönderilen soru ID'si (qDbId) çekilen questionIdToSortOrderMap içinde var mı?
                $isValidQuestionId = ($qIdCheck !== false && isset($questionIdToSortOrderMap[$qIdCheck]));

                if ($isValidQuestionId && $isValidScore) { $answeredCount++; }
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

                // Cevapları Ekle (question_id sütununa sort_order yazılacak, answer_text'e puan metni)
                // answer_score sütununun var olduğunu varsayıyoruz, ancak answer_text'e metin kaydedeceğiz
                $stmtAnswer = $pdo->prepare(
                    "INSERT INTO survey_answers (participant_id, survey_id, question_id, answer_text, created_at)
                     VALUES (:pid, :sid, :qid_sort_order, :answer_text, NOW())" // qid_sort_order placeholder'ı
                );
                foreach ($answers as $actual_db_id => $answer_score) {
                    $qId_int = (int)$actual_db_id;
                    $aScore_int = (int)$answer_score;

                    // *** Burası Değişti: Orijinal DB ID'ye karşılık gelen sort_order'ı al ***
                    $sort_order_to_save = $questionIdToSortOrderMap[$qId_int] ?? null;

                    // Puanın metin karşılığını al
                    $answer_text_to_save = $optionsMap[$aScore_int] ?? 'Geçersiz Puan';

                    if ($sort_order_to_save !== null) {
                        $stmtAnswer->bindParam(':pid', $participant_id, PDO::PARAM_INT);
                        $stmtAnswer->bindParam(':sid', $surveyId, PDO::PARAM_INT);
                        // *** Burası Önemli: question_id sütununa sort_order değeri bağlanıyor ***
                        $stmtAnswer->bindParam(':qid_sort_order', $sort_order_to_save, PDO::PARAM_INT);
                        $stmtAnswer->bindParam(':answer_text', $answer_text_to_save, PDO::PARAM_STR); // Puanın metin karşılığı
                        // answer_score sütununa kaydetmek isterseniz burayı ve sorguyu güncelleyin.
                        // $stmtAnswer->bindParam(':answer_score', $aScore_int, PDO::PARAM_INT);

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
             // **** SENARYO 2: ADMIN YOK -> KAYDETME, SONUCU HESAPLA, SESSION'A AT, view-result-18.php'ye YÖNLENDİR ****
             // Bu senaryoda Session'a kaydedilen answers array'i [qid (DB ID) => score (1-5)] formatında kalacak.
             // view-result-18.php'nin Session kısmı bu formatı işleyebilmelidir.
             // Skor hesaplaması sort_order'a göre yapıldığı için questionIdToSortOrderMap'e ihtiyaç var.
             // Bu harita POST doğrulaması sırasında zaten çekiliyor.

             if ($post_error === null && !empty($questionIdToSortOrderMap)) { // questionIdToSortOrderMap POST doğrulamada çekildi
                 $xScore = 0; $yScore = 0;
                 foreach ($answers as $qDbId => $aScore) {
                     $questionId = (int)$qDbId; // Orijinal DB ID
                     $score = (int)$aScore; // Puan (1-5)
                     $sortOrder = $questionIdToSortOrderMap[$questionId] ?? null; // sort_order'ı bul

                     if ($sortOrder !== null) {
                         if (in_array($sortOrder, $xItems)) {
                             $xScore += $score;
                         } elseif (in_array($sortOrder, $yItems)) {
                             $yScore += $score;
                         }
                     } else {
                          error_log("Cannot find sort_order for QID: {$questionId} in survey {$surveyId} during Session score calculation");
                     }
                 }
                 $zScore = 126 - $yScore; // Z Skoru hesaplama
                 $tScore = $xScore + $zScore; // T Skoru hesaplama
                 $interpretation = interpretMaturityScore($tScore); // Yorumlama

                 $_SESSION['survey_result_data'] = [
                     'survey_id' => $surveyId,
                     't_score' => $tScore,
                     'interpretation' => $interpretation,
                     'answers' => $answers, // [qid (DB ID) => score (1-5)] olarak kaydediliyor
                     'participant_name' => $participantName,
                     'participant_class' => $participantClass,
                     'timestamp' => time()
                 ];
                 session_write_close(); // Session yazmayı bitir
                 // *** BURASI DÜZELTİLDİ: Yönlendirme yolu admin dizinini içerecek şekilde güncellendi ***
                 header('Location: ../admin/view-result-18.php');
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
$actionUrl = "take-survey-18.php";
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
         .option-label-button { cursor: pointer; padding: 8px 12px; border: 1px solid #ced4da; border-radius: 5px; background-color: #fff; transition: background-color 0.2s, border-color 0.2s, color 0.2s; text-align: center; font-size: 0.85em; line-height: 1.4; display: inline-block; user-select: none; min-width: 135px; }
        input[type="radio"].hidden-radio:checked + label.option-label-button { background-color: #22c55e; color: white; border-color: #16a34a; font-weight: bold; }
        .option-label-button:hover { background-color: #dcfce7; border-color: #a7f3d0;}
        input[type="radio"].hidden-radio:checked + label.option-label-button:hover { background-color: #16a34a; }

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
                     <p>Ölçekte meslek seçimiyle ilgili tutum ve davranışları ölçen bazı ifadeler verilmiştir...</p>
                     <p>Lütfen her ifade için size en uygun seçeneği işaretleyin.</p>
                     <p>(A= Bana Hiç Uygun Değil, B= Bana Pek Uygun Değil, C= Bana Biraz Uygun, D= Bana Uygun, E= Bana Çok Uygun)</p>
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
                            <?php foreach ($optionsMap as $value => $label):
                                $input_id = "q{$question_id}_opt{$value}";
                                $is_checked = ($submitted_answer !== null && (string)$submitted_answer === (string)$value);
                            ?>
                                <input type="radio" class="hidden-radio" id="<?= $input_id ?>" name="answers[<?= $question_id ?>]" value="<?= $value ?>" required <?= $is_checked ? 'checked' : '' ?>>
                                <label for="<?= $input_id ?>" class="option-label-button"><?= htmlspecialchars($label) ?></label>
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
