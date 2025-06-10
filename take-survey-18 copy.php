<?php
// take-survey-18.php (Mesleki Olgunluk Ölçeği v4 - Alanlar ve Seçenek Metni Düzeltmesi)

// --- Hata Raporlama ---
ini_set('display_errors', 1); error_reporting(E_ALL);
// ---------------------------

session_start(); // Session GEREKLİ

// --- Veritabanı Bağlantısı ---
require_once __DIR__ . '/src/config.php';
if (!isset($pdo) || !$pdo instanceof PDO) { /* ... Bağlantı Hatası ... */ die('DB Error'); }
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
// Skorlama için X ve Y grubu maddeleri (SIRALI 1-40 NUMARALANDIRMA VARSAYILDI!)
$xItems = [5, 6, 7, 8, 10, 11, 18, 20, 23, 28, 29, 30, 31, 32, 34, 35, 37, 39, 40];
$yItems = [1, 2, 3, 4, 9, 12, 13, 14, 15, 16, 17, 19, 21, 22, 24, 25, 26, 27, 33, 36, 38];
// Skor Yorumlama Fonksiyonu
function interpretMaturityScore($tScore) {
     if ($tScore === null || !is_numeric($tScore)) return "Hesaplanamadı";
     if ($tScore < 143) return "Mesleki olgunluk düzeyiniz şu an için beklenenin altında görünüyor...";
     elseif ($tScore <= 155) return "Mesleki olgunluk düzeyiniz normal aralıkta...";
     else return "Mesleki olgunluk düzeyiniz beklenenin üzerinde...";
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

    // 2. Doğrulama
    if ($surveyId_post !== $surveyId) { $post_error = "Geçersiz anket bilgisi."; }
    elseif (empty($participantName)) { $post_error = "Ad Soyad alanı boş bırakılamaz."; }
    elseif (empty($answers) || !is_array($answers)) { $post_error = "Cevaplar alınamadı."; }
    else {
        // Tüm soruların cevaplanıp cevaplanmadığını ve geçerli (1-5) olduğunu kontrol et
        $expectedQuestionCount = 40; $dbQuestionIds = [];
        try { $stmt_q_ids = $pdo->prepare("SELECT id FROM survey_questions WHERE survey_id = ?"); $stmt_q_ids->execute([$surveyId]); $dbQuestionIds = $stmt_q_ids->fetchAll(PDO::FETCH_COLUMN); $expectedQuestionCount = count($dbQuestionIds);} catch (PDOException $e) { error_log("Soru ID alınamadı (S{$surveyId}): ".$e->getMessage()); }

        $answeredCount = 0; $invalidDataFound = false;
        if (!empty($dbQuestionIds)) {
            if (count($answers) < $expectedQuestionCount) {
                 $post_error = "Lütfen tüm soruları cevaplayın.";
            } else {
                foreach ($answers as $qDbId => $aScore) {
                    $qIdCheck = filter_var($qDbId, FILTER_VALIDATE_INT);
                    $aScoreCheck = filter_var($aScore, FILTER_VALIDATE_INT);
                    $isValidScore = ($aScoreCheck !== false && $aScoreCheck >= 1 && $aScoreCheck <= 5);
                    $isValidQuestionId = ($qIdCheck !== false && in_array($qIdCheck, $dbQuestionIds));
                    if ($isValidQuestionId && $isValidScore) { $answeredCount++; }
                    else { $invalidDataFound = true; break; }
                }
                if ($invalidDataFound) { $post_error = "Geçersiz cevap veya soru ID'si."; }
            }
        } else { $post_error = "Anket soruları bulunamadığı için doğrulama yapılamadı."; }
    }

    // ---- KOŞULLU KAYIT ve YÖNLENDİRME ----
    if ($post_error === null) {
        if ($adminId !== null) {
            // **** SENARYO 1: ADMIN VAR -> Veritabanına KAYDET, tamamlandi.php'ye YÖNLENDİR ****
            $participant_id = null;
            try {
                $pdo->beginTransaction();
                // Katılımcı ekle...
                 $stmtParticipant = $pdo->prepare("INSERT INTO survey_participants (name, class, survey_id, admin_id, created_at) VALUES (:name, :class, :survey_id, :admin_id, NOW())");
                 $stmtParticipant->bindParam(':name', $participantName, PDO::PARAM_STR);
                 $stmtParticipant->bindParam(':class', $participantClass, PDO::PARAM_STR);
                 $stmtParticipant->bindParam(':survey_id', $surveyId, PDO::PARAM_INT);
                 $stmtParticipant->bindParam(':admin_id', $adminId, PDO::PARAM_INT);
                 if (!$stmtParticipant->execute()) { throw new PDOException("Katılımcı kaydı başarısız: " . implode(";", $stmtParticipant->errorInfo())); }
                 $participant_id = $pdo->lastInsertId();
                 if (!$participant_id) { throw new Exception("Katılımcı ID alınamadı."); }

                // TÜM Cevapları Ekle (Gerçek Soru ID + SAYISAL PUAN (1-5))
                // !!! answer_score sütununun var olduğunu varsayar !!!
                $stmtAnswer = $pdo->prepare("INSERT INTO survey_answers (participant_id, survey_id, question_id, answer_score, created_at) VALUES (:pid, :sid, :qid, :answer_score, NOW())");
                foreach ($answers as $actual_db_id => $answer_score) {
                    $qId = (int)$actual_db_id;
                    $aScore = (int)$answer_score;
                    $stmtAnswer->bindParam(':pid', $participant_id, PDO::PARAM_INT);
                    $stmtAnswer->bindParam(':sid', $surveyId, PDO::PARAM_INT);
                    $stmtAnswer->bindParam(':qid', $qId, PDO::PARAM_INT);
                    $stmtAnswer->bindParam(':answer_score', $aScore, PDO::PARAM_INT);
                    if (!$stmtAnswer->execute()) { throw new PDOException("Cevap kaydı başarısız (QID: {$qId}): " . implode(";", $stmtAnswer->errorInfo())); }
                }
                $pdo->commit();
                session_write_close();
                header('Location: tamamlandi.php?pid=' . $participant_id);
                exit();
            } catch (Exception $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } error_log(/*...*/); $post_error = "<b>Kayıt Sırasında Bir Hata Oluştu!</b>"; }

        } else {
             // **** SENARYO 2: ADMIN YOK -> KAYDETME, SONUCU HESAPLA, SESSION'A AT, view-result-18.php'ye YÖNLENDİR ****
             $questionIdToSortOrderMap = [];
             try { $stmtQMap = $pdo->prepare("SELECT id, sort_order FROM survey_questions WHERE survey_id = ?"); $stmtQMap->execute([$surveyId]); $questionIdToSortOrderMap = $stmtQMap->fetchAll(PDO::FETCH_KEY_PAIR); if (empty($questionIdToSortOrderMap)) throw new Exception("Soru haritası alınamadı."); }
             catch (Exception $e) { $post_error = "Sonuç hesaplanırken soru haritası yüklenemedi."; error_log(/*...*/); }

             if ($post_error === null) {
                 $xScore = 0; $yScore = 0;
                 foreach ($answers as $qDbId => $aScore) {
                     $questionId = (int)$qDbId; $score = (int)$aScore;
                     $sortOrder = $questionIdToSortOrderMap[$questionId] ?? null;
                     if ($sortOrder !== null) { if (in_array($sortOrder, $xItems)) { $xScore += $score; } elseif (in_array($sortOrder, $yItems)) { $yScore += $score; } }
                 }
                 $zScore = 126 - $yScore; $tScore = $xScore + $zScore;
                 $interpretation = interpretMaturityScore($tScore);
                 $_SESSION['survey_result_data'] = [ 'survey_id' => $surveyId, 't_score' => $tScore, 'interpretation' => $interpretation, 'answers' => $answers, 'participant_name' => $participantName, 'participant_class' => $participantClass, 'timestamp' => time() ];
                 session_write_close();
                 header('Location: view-result-18.php'); exit(); // Yönlendirme düzeltildi
             }
        }
    }
}
// --- POST İŞLEME SONU ---


// --- Formu Göstermek İçin Veri Çek ---
if ($error === null) { try { /* ... Veri Çekme ... */ } catch (Exception $e) { /* ... Hata ... */ } }
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

        .nav-button { /* ... */ } .info { /* ... */ } .error-box { /* ... */ } .admin-info-box, .public-info-box { /* ... */ }
        .required-star { /* ... */ } .validation-error { /* ... */ } .validation-error-text { /* ... */ }
        .mt-6 { margin-top: 1.5rem; } a { color: #15803d; } a:hover { color: #0b532c; } .text-center { /* ... */ }
        .info label { /* ... */ } .info input { /* ... */ } .info input:focus { /* ... */ } .info-grid { /* ... */ }
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
            <?php if ($adminId !== null): ?> <div class="admin-info-box">...</div> <?php else: ?> <div class="public-info-box">...</div> <?php endif; ?>

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
                      <p>Ölçekte meslek seçimiyle ilgili tutum ve davranışları ölçen bazı ifadeler verilmiştir. Sizden istenen, ifadeleri dikkatle okuyup bu ifadelerin size ne kadar uygun olduğunu, sizin durumunuzu ne ölçüde yansıttığını belirtmenizdir.</p>
                      <p>Lütfen her ifade için size en uygun seçeneği işaretleyin.</p>
                      <p>(A= Bana Hiç Uygun Değil, B= Bana Pek Uygun Değil, C= Bana Biraz Uygun, D= Bana Uygun, E= Bana Çok Uygun)</p>
                 </div>


                <?php foreach ($questions as $question):
                    $question_id = $question['id'];
                    $sort_order = $question['sort_order'];
                    $submitted_answer = $form_data['answers'][$question_id] ?? null; // 1-5 arası değer olmalı
                 ?>
                    <div class="question-block" id="q_block_<?= $question_id ?>">
                        <span class="question-text <?= ($post_error && $submitted_answer === null) ? 'validation-error' : '' ?>" id="q_text_<?= $question_id ?>">
                           <?= htmlspecialchars($sort_order) ?>. <?= htmlspecialchars($question['question_text'] ?? 'Soru metni yüklenemedi') ?> <span class="required-star">*</span>
                        </span>
                        <div class="options-group-styled" role="radiogroup" aria-labelledby="q_text_<?= $question_id ?>">
                            <?php foreach ($optionsMap as $value => $label): // $optionsMap 1-5 anahtarlı, TAM METİN değerli
                                $input_id = "q{$question_id}_opt{$value}";
                                $is_checked = ($submitted_answer !== null && (string)$submitted_answer === (string)$value);
                            ?>
                                <input type="radio"
                                       class="hidden-radio"
                                       id="<?= $input_id ?>"
                                       name="answers[<?= $question_id ?>]"
                                       value="<?= $value // Sayısal Puan (1-5) ?>"
                                       required <?= $is_checked ? 'checked' : '' ?>>
                                <label for="<?= $input_id ?>" class="option-label-button">
                                    <?= htmlspecialchars($label) // TAM METNİ GÖSTER ?>
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
    <script> /* ... (JS Kodu Aynı) ... */ </script>
     <?php endif; ?>

</body>
</html>