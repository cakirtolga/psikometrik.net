<?php
// take-survey-17.php (Mesleki Eğilim Belirleme Testi v7 - Sort Order Kayıt)

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
$surveyId = 17;
$testTitleDefault = "Mesleki Eğilim Belirleme Testi";
// ---------------------

// --- Değişken Başlatma ---
$adminId = null; $error = null; $post_error = null;
$adminCheckError = null; $survey = null; $questions = [];
$form_data = ($_SERVER["REQUEST_METHOD"] == "POST") ? $_POST : [];
// -----------------------------

// --- Soru No (sort_order) -> Meslek Grubu Eşleşmesi ---
$questionGroupMap = [
    1=>'A', 2=>'B', 3=>'C', 4=>'D', 5=>'E', 6=>'F', 7=>'G', 8=>'H', 9=>'I', 10=>'İ',
    11=>'A', 12=>'B', 13=>'C', 14=>'D', 15=>'E', 16=>'F', 17=>'G', 18=>'H', 19=>'I', 20=>'İ',
    21=>'A', 22=>'B', 23=>'C', 24=>'D', 25=>'E', 26=>'F', 27=>'G', 28=>'H', 29=>'I', 30=>'İ',
    31=>'A', 32=>'B', 33=>'C', 34=>'D', 35=>'E', 36=>'F', 37=>'G', 38=>'H', 39=>'I', 40=>'İ',
    41=>'A', 42=>'B', 43=>'C', 44=>'D', 45=>'E', 46=>'F', 47=>'G', 48=>'H', 49=>'I', 50=>'İ',
    51=>'A', 52=>'B', 53=>'C', 54=>'D', 55=>'E', 56=>'F', 57=>'G', 58=>'H', 59=>'I', 60=>'İ',
    61=>'A', 62=>'B', 63=>'C', 64=>'D', 65=>'E', 66=>'F', 67=>'G', 68=>'H', 69=>'I', 70=>'İ',
    71=>'A', 72=>'B', 73=>'C', 74=>'D', 75=>'E', 76=>'F', 77=>'G', 78=>'H', 79=>'I', 80=>'İ',
    81=>'A', 82=>'B', 83=>'C', 84=>'D', 85=>'E', 86=>'F', 87=>'G', 88=>'H', 89=>'I', 90=>'İ',
    91=>'A', 92=>'B', 93=>'C', 94=>'D', 95=>'E', 96=>'F', 97=>'G', 98=>'H', 99=>'I', 100=>'İ',
    101=>'A', 102=>'B', 103=>'C', 104=>'D', 105=>'E', 106=>'F', 107=>'G', 108=>'H', 109=>'I', 110=>'İ',
    111=>'A', 112=>'B', 113=>'C', 114=>'D', 115=>'E', 116=>'F', 117=>'G', 118=>'H', 119=>'I', 120=>'İ',
    121=>'A', 122=>'B', 123=>'C', 124=>'D', 125=>'E', 126=>'F', 127=>'G', 128=>'H', 129=>'I', 130=>'İ',
    131=>'A', 132=>'B', 133=>'C', 134=>'D', 135=>'E', 136=>'F', 137=>'G', 138=>'H', 139=>'I', 140=>'İ',
    141=>'A', 142=>'B', 143=>'C', 144=>'D', 145=>'E', 146=>'F', 147=>'G', 148=>'H', 149=>'I', 150=>'İ',
    151=>'A', 152=>'B', 153=>'C', 154=>'D', 155=>'E', 156=>'F', 157=>'G', 158=>'H', 159=>'I', 160=>'İ'
];
$groupNames = [
    'A'=>'Ziraat / Doğa Bilimleri', 'B'=>'Teknik / Mekanik', 'C'=>'İkna / Yönetim / Sosyal Yardım',
    'D'=>'Sanat / Estetik', 'E'=>'Edebiyat / Tarih / Öğretim', 'F'=>'Sosyal Bilimler / Araştırma',
    'G'=>'Yabancı Dil / Turizm / Uluslararası İlişkiler', 'H'=>'Sağlık / Fen Bilimleri (Biyoloji Temelli)',
    'I'=>'Ekonomi / Ticaret / Finans', 'İ'=>'Bilim / Mühendislik (Matematik/Fizik Temelli)'
];
$allGroups = array_keys($groupNames);

// Skor Yorumlama Fonksiyonu
function interpretVocationalScore($score) {
     if ($score === null || !is_numeric($score)) return "Hesaplanamadı";
     if ($score >= 0 && $score < 40) return "Bu alana ilginiz yok veya çok az.";
     elseif ($score < 80) return "İlginiz var ancak bu alanı seçmeniz için yeterli olmayabilir.";
     elseif ($score < 100) return "İlginiz var ama seçmeden önce bir kere daha düşünün.";
     elseif ($score < 130) return "Normal düzeyde ilginiz var, bu alandaki meslekleri seçebilirsiniz.";
     else return "Bu alandaki meslekler sizin için çok uygun görünüyor.";
}
// -------------------------------------------

// --- Cevap Seçenekleri (Evet/Hayır) ---
$yesNoOptions = ['Evet', 'Hayır'];
// --------------------------------------


// --- Yönetici ID Kontrolü ---
$adminCheckError = null;
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
    $answers = $_POST['answers'] ?? []; // Dizi: [question_db_id => 'Evet'/'Hayır']

    // 2. Doğrulama
    $dbQuestions = []; // Soru ID'lerini ve sort_order'larını tutacak
    $questionIdToSortOrderMap = []; // ID -> sort_order haritası

    try {
        // POST edilen soru ID'lerinin geçerliliğini ve sort_order'larını çekmek için
        $questionIdsFromPost = array_keys($answers);
        if (!empty($questionIdsFromPost)) {
             $placeholders = implode(',', array_fill(0, count($questionIdsFromPost), '?'));
             $stmt_q_check = $pdo->prepare("SELECT id, sort_order FROM survey_questions WHERE survey_id = ? AND id IN ({$placeholders})");
             $executeParams = array_merge([$surveyId], $questionIdsFromPost);
             $stmt_q_check->execute($executeParams);
             $dbQuestions = $stmt_q_check->fetchAll(PDO::FETCH_ASSOC); // Hem id hem sort_order çekildi

             // ID -> sort_order haritasını oluştur
             foreach($dbQuestions as $q) {
                 $questionIdToSortOrderMap[$q['id']] = $q['sort_order'];
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
            foreach ($answers as $qDbId => $answerValue) {
                $qIdCheck = filter_var($qDbId, FILTER_VALIDATE_INT);
                $isValidAnswerValue = in_array($answerValue, $yesNoOptions);
                // Gönderilen soru ID'si (qDbId) çekilen dbQuestions içinde var mı?
                $isValidQuestionId = ($qIdCheck !== false && isset($questionIdToSortOrderMap[$qIdCheck]));

                if ($isValidQuestionId && $isValidAnswerValue) { $answeredCount++; }
                else { $invalidDataFound = true; break; }
            }
            if ($invalidDataFound) { $post_error = "Geçersiz veya eksik cevap verisi."; }
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
                $stmtParticipant = $pdo->prepare(
                    "INSERT INTO survey_participants (name, class, survey_id, admin_id, created_at)
                     VALUES (:name, :class, :survey_id, :admin_id, NOW())"
                );
                $stmtParticipant->bindParam(':name', $participantName, PDO::PARAM_STR);
                $stmtParticipant->bindParam(':class', $participantClass, PDO::PARAM_STR);
                $stmtParticipant->bindParam(':survey_id', $surveyId, PDO::PARAM_INT);
                $stmtParticipant->bindParam(':admin_id', $adminId, PDO::PARAM_INT);
                if (!$stmtParticipant->execute()) { throw new PDOException("Katılımcı kaydı başarısız: " . implode(";", $stmtParticipant->errorInfo())); }
                $participant_id = $pdo->lastInsertId();
                if (!$participant_id) { throw new Exception("Katılımcı ID alınamadı."); }

                // Cevapları Ekle (question_id sütununa sort_order yazılacak)
                $stmtAnswer = $pdo->prepare(
                    "INSERT INTO survey_answers (participant_id, survey_id, question_id, answer_text, created_at)
                     VALUES (:pid, :sid, :qid_sort_order, :answer_text, NOW())" // qid_sort_order placeholder'ı
                );
                foreach ($answers as $actual_db_id => $answer_text) {
                    $qId_int = (int)$actual_db_id;

                    // *** Burası Değişti: Orijinal DB ID'ye karşılık gelen sort_order'ı al ***
                    $sort_order_to_save = $questionIdToSortOrderMap[$qId_int] ?? null;

                    if ($sort_order_to_save !== null) {
                        $stmtAnswer->bindParam(':pid', $participant_id, PDO::PARAM_INT);
                        $stmtAnswer->bindParam(':sid', $surveyId, PDO::PARAM_INT);
                        // *** Burası Önemli: question_id sütununa sort_order değeri bağlanıyor ***
                        $stmtAnswer->bindParam(':qid_sort_order', $sort_order_to_save, PDO::PARAM_INT);
                        $stmtAnswer->bindParam(':answer_text', $answer_text, PDO::PARAM_STR); // 'Evet' veya 'Hayır'
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
                header('Location: tamamlandi.php?pid=' . $participant_id);
                exit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log("Admin Survey Submit Exception (S{$surveyId}, Admin{$adminId}, PName:{$participantName}): " . $e->getMessage());
                $post_error = "<b>Kayıt Sırasında Bir Hata Oluştu!</b> Lütfen tekrar deneyin veya yöneticiyle iletişime geçin. (Detaylar loglandı)";
            }

        } else {
             // **** SENARYO 2: ADMIN YOK -> KAYDETME, SONUCU HESAPLA, SESSION'A AT, view-result-17.php'ye YÖNLENDİR ****
             // Session'a kaydedilecek skorlar ve yorumlar zaten sort_order bazlı hesaplanıyor.
             // Sadece answers array'inin formatını kontrol edelim.
             // view-result-17.php Session kısmı, answers array'ini [qid (DB ID) => score (1-5)] formatında bekliyordu.
             // Bu anket 'Evet'/'Hayır' cevapları alıyor ve Session'a [qid (DB ID) => 'Evet'/'Hayır'] formatında kaydediyor.
             // view-result-17.php'nin Session kısmını bu yeni formata göre güncellememiz gerekecek.
             // Şimdilik Session'a kaydedilen answers formatı [qid (DB ID) => 'Evet'/'Hayır'] olarak kalabilir.
             // view-result-17.php güncellenirken bu dikkate alınacak.

             if ($post_error === null && !empty($questionIdToSortOrderMap)) { // questionIdToSortOrderMap POST doğrulamada çekildi
                 $groupScores = array_fill_keys($allGroups, 0);
                 $sessionAnswersData = $answers; // [qid (DB ID) => 'Evet'/'Hayır']

                 foreach ($answers as $qDbId => $answerValue) {
                     if ($answerValue === 'Evet') {
                        $questionId = (int)$qDbId;
                        // Bu sorunun sort_order'ını bul
                        $sortOrder = $questionIdToSortOrderMap[$questionId] ?? null;
                        if ($sortOrder && isset($questionGroupMap[$sortOrder])) {
                            $group = $questionGroupMap[$sortOrder];
                            // Grup skoru hesaplama mantığı değişmedi
                            if (isset($groupScores[$group])) { $groupScores[$group] += 10; }
                        } else { error_log("Cannot find sort_order or group for QID: {$questionId} in survey {$surveyId} during Session score calculation"); }
                     }
                 }

                 $groupInterpretations = [];
                 foreach ($groupScores as $group => $score) {
                      $groupInterpretations[$group] = interpretVocationalScore($score);
                 }

                 $_SESSION['survey_result_data'] = [
                    'survey_id' => $surveyId,
                    'group_scores' => $groupScores,
                    'group_interpretations' => $groupInterpretations,
                    'answers' => $sessionAnswersData, // [qid (DB ID) => 'Evet'/'Hayır'] olarak kaydediliyor
                    'participant_name' => $participantName,
                    'participant_class' => $participantClass,
                    'timestamp' => time()
                 ];

                 header('Location: ../admin/view-result-17.php');
                 exit();
             } // End if ($post_error === null) - after question map fetch
        } // End if ($adminId !== null) else block
    } // End if ($post_error === null) - main validation
}
// --- POST İŞLEME SONU ---


// --- Formu Göstermek İçin Veri Çek ---
// Bu kısım değişmedi, formu göstermek için ID, question_text ve sort_order çekiliyor
if ($error === null) {
     try {
        $stmtSurvey = $pdo->prepare("SELECT title FROM surveys WHERE id = ?"); $stmtSurvey->execute([$surveyId]); $survey = $stmtSurvey->fetch(PDO::FETCH_ASSOC);
        $stmtQuestions = $pdo->prepare("SELECT id, question AS question_text, sort_order FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC, id ASC");
        $stmtQuestions->execute([$surveyId]); $questions = $stmtQuestions->fetchAll(PDO::FETCH_ASSOC);
        if (empty($questions)) { if ($post_error === null) { $error = "Bu anket ({$surveyId}) için veritabanında soru bulunamadı..."; } $questions = []; }
        else { /* Sort order kontrolü... */ }
    } catch (Exception $e) { if ($post_error === null) { $error = "Anket verileri yüklenirken hata oluştu."; } error_log("Data Fetch Error: ".$e->getMessage()); $questions = []; }
}
// --- Veri Çekme Sonu ---

// Sayfa başlığı vs.
$pageTitle = isset($survey['title']) ? htmlspecialchars($survey['title']) : $testTitleDefault;
$actionUrl = "take-survey-17.php";
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
        /* --- Stil Bloğu (Yeşil Tema - Tıklanabilir Evet/Hayır) --- */
        body { font-family: sans-serif; line-height: 1.6; background-color: #f0fdf4; color: #2c3e50; margin: 0; padding: 20px; }
        .container { max-width: 800px; margin: 40px auto; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); text-align: left; background-color: #ffffff; }
        h2 { text-align: center; font-size: 1.5rem; font-weight: 600; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 2px solid #dcfce7; color: #1f2937; }
        .instructions { background-color: #e8f5e9; padding: 15px; border-radius: 5px; margin-bottom: 25px; font-size: 0.95em; border: 1px solid #c8e6c9; }
        .instructions p { margin: 0.5rem 0; }

        .question-block { margin-bottom: 15px; padding: 10px 0; border-bottom: 1px solid #eee; }
        .question-block:last-child { border-bottom: none; }
        .question-text { display: block; font-weight: 500; margin-bottom: 10px; font-size: 0.95em; color: #1f2937; }
        .question-text.validation-error { color: #dc2626 !important; font-weight: bold; }
        .options-group-styled { display: flex; flex-wrap: wrap; gap: 10px; justify-content: flex-start; padding-top: 5px;}
        input[type="radio"].hidden-radio { position: absolute; opacity: 0; width: 0; height: 0; pointer-events: none; }
         .option-label-button { cursor: pointer; padding: 8px 15px; border: 1px solid #ced4da; border-radius: 5px; background-color: #fff; transition: background-color 0.2s, border-color 0.2s, color 0.2s; text-align: center; font-size: 0.9em; line-height: 1.5; display: inline-block; user-select: none; min-width: 60px; }
        input[type="radio"].hidden-radio:checked + label.option-label-button { background-color: #22c55e; color: white; border-color: #16a34a; font-weight: bold; }
        .option-label-button:hover { background-color: #dcfce7; border-color: #a7f3d0;}
        input[type="radio"].hidden-radio:checked + label.option-label-button:hover { background-color: #16a34a; }

        .nav-button { padding: 12px 30px; border-radius: 8px; font-weight: 600; transition: all 0.2s ease-in-out; cursor: pointer; border: none; color: white; display: block; width: 100%; margin-top: 2rem;}
        .nav-button.submit { background: #15803d; } .nav-button.submit:hover { background: #0b532c; }
        .nav-button:disabled { background-color: #9ca3af; cursor: not-allowed; opacity: 0.7;}
        .required-star { /* ... */ } .validation-error { /* ... */ } .validation-error-text { /* ... */ }
        .mt-6 { margin-top: 1.5rem; } a { color: #15803d; } a:hover { color: #0b532c; } .text-center { /* ... */ }
        .info label { display: block; margin-bottom: 5px; font-weight: 600; color: #374151; }
        .info input { padding: 8px 10px; width: 100%; box-sizing: border-box; border: 1px solid #ccc; border-radius: 6px; font-size: 1em; color: #2c3e50; height: 40px; background-color: white;}
        .info input:focus { border-color: #15803d; box-shadow: 0 0 0 2px rgba(34, 197, 94, 0.3); outline: none; }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; }
         .question-block .validation-error-text { margin-left: 0; width: 100%; /* Hata mesajı tam genişlik */ }
    </style>
</head>
<body>
    <div class="container">
        <h2><?= $pageTitle ?></h2>

        <?php if ($error): ?> <div class="error-box" role="alert"><b>Hata:</b> <?= htmlspecialchars($error) ?></div>
        <?php elseif ($post_error): ?> <div class="error-box" role="alert"><?= htmlspecialchars($post_error) ?></div>
         <?php elseif (isset($adminCheckError) && $adminCheckError): ?> <div class="error-box" role="alert" style="background-color: #fff3cd; border-color: #ffe69c; color: #664d03;"><b>Uyarı:</b> <?= htmlspecialchars($adminCheckError) ?> Yönetici kimliği olmadan devam ediyorsunuz...</div>
        <?php endif; ?>


        <?php // Formu Göster ?>
        <?php if (!$error && !empty($questions)): ?>

            <?php // Bilgilendirme kutusu ?>
            <?php if ($adminId !== null): ?> <?php else: ?> <div class="public-info-box">Bu herkese açık ücretsiz bir ankettir...</div> <?php endif; ?>

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
                     <p>Bu bir ilgi envanteridir...</p>
                     <p>Lütfen aşağıdaki ifadeleri dikkatlice okuyun ve her bir ifadenin size ne kadar uygun olduğunu düşünerek <strong>Evet</strong> veya <strong>Hayır</strong> seçeneklerinden size uygun olanın üzerine tıklayarak işaretleyin.</p>
                     <p>Tüm maddeleri işaretlemeniz gerekmektedir.</p>
                 </div>


                <?php foreach ($questions as $question):
                    $question_id = $question['id'];
                    $sort_order = $question['sort_order'];
                    $submitted_answer = $form_data['answers'][$question_id] ?? null;
                 ?>
                    <div class="question-block" id="q_block_<?= $question_id ?>">
                        <span class="question-text <?= ($post_error && $submitted_answer === null) ? 'validation-error' : '' ?>" id="q_text_<?= $question_id ?>">
                           <?= htmlspecialchars($sort_order) ?>. <?= htmlspecialchars($question['question_text'] ?? 'Soru metni yüklenemedi') ?> <span class="required-star">*</span>
                        </span>
                        <div class="options-group-styled" role="radiogroup" aria-labelledby="q_text_<?= $question_id ?>">
                            <?php foreach ($yesNoOptions as $optionText):
                                $input_id = "q{$question_id}_opt{$optionText}";
                                $is_checked = ($submitted_answer !== null && $submitted_answer === $optionText);
                            ?>
                                <?php // Input ve Label ayrı ?>
                                <input type="radio"
                                       class="hidden-radio"
                                       id="<?= $input_id ?>"
                                       name="answers[<?= $question_id ?>]"
                                       value="<?= $optionText ?>"
                                       required <?= $is_checked ? 'checked' : '' ?>>
                                <label for="<?= $input_id ?>" class="option-label-button">
                                    <?= $optionText ?>
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
             <div class="error-box" style="background-color: #ebf8ff; border-color: #90cdf4; color: #2c5282;" role="alert"><strong>Bilgi:</strong> Anket yüklenemedi veya gösterilecek soru bulunamadı. Veritabanında Anket ID 17 için soruların eklendiğinden emin olun.</div>
        <?php endif; ?>
    </div>

    <?php // İstemci Taraflı Doğrulama ?>
     <?php if (!$error && !empty($questions)): ?>
    <script>
        // Önceki versiyondaki JS doğrulama kodu (İsim + Tüm Sorular Cevaplandı mı?)
         const form = document.getElementById('surveyForm');
         if (form) {
             form.addEventListener('submit', function(event) {
                 let firstErrorElement = null; let isValid = true;
                 // İsim kontrolü...
                 const nameInput = document.getElementById('participant_name');
                 const nameErrorP = document.getElementById('nameError');
                 if (nameInput && nameErrorP) { if (!nameInput.value.trim()) { nameInput.classList.add('validation-error'); nameErrorP.textContent = 'Lütfen adınızı ve soyadınızı girin.'; isValid = false; if (!firstErrorElement) firstErrorElement = nameInput; } else { nameInput.classList.remove('validation-error'); nameErrorP.textContent = ''; } }
                 // Tüm soruların cevaplandığını kontrol et...
                 const questionsBlocks = form.querySelectorAll('.question-block');
                 questionsBlocks.forEach(qBlock => {
                     const qid = qBlock.id.replace('q_block_', '');
                     const qText = qBlock.querySelector('.question-text');
                     const errorP = document.getElementById(`qError_${qid}`);
                     const radios = qBlock.querySelectorAll('input[type="radio"].hidden-radio');
                     let isAnswered = false;
                     radios.forEach(radio => { if (radio.checked) isAnswered = true; });
                     if (!isAnswered) { if(qText) qText.classList.add('validation-error'); if(errorP) errorP.textContent = 'Lütfen Evet veya Hayır seçimi yapın.'; isValid = false; if (!firstErrorElement && qText) firstErrorElement = qText; }
                     else { if(qText) qText.classList.remove('validation-error'); if(errorP) errorP.textContent = ''; }
                 });
                 // Hata varsa formu gönderme ve odaklanma...
                 if (!isValid) { event.preventDefault(); /* ... alert ve focus ... */ }
                 else { /* ... Butonu disable et ... */ }
             });
         }
    </script>
     <?php endif; ?>

</body>
</html>
