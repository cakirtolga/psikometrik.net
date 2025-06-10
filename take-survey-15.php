<?php
// take-survey-15.php (İnternet Bağımlılığı Ölçeği v11 - Koşullu Kayıt/Yönlendirme)

// --- Hata Raporlama ---
ini_set('display_errors', 1);
error_reporting(E_ALL);
// ---------------------------

session_start(); // Session kullanımı için GEREKLİ

// --- Veritabanı Bağlantısı ---
require_once __DIR__ . '/src/config.php';
if (!isset($pdo) || !$pdo instanceof PDO) {
    error_log("CRITICAL: PDO connection object (\$pdo) not created or found in src/config.php");
    header('Content-Type: text/html; charset=utf-8');
    die('<div style="border: 1px solid red; padding: 10px; margin: 10px; background-color: #ffebeb; color: red; font-family: sans-serif;"><b>Kritik Hata:</b> Veritabanı bağlantısı kurulamadı (src/config.php).</div>');
}
// --------------------------------

// --- Konfigürasyon ---
$surveyId = 15;
$testTitleDefault = "İnternet Bağımlılığı Ölçeği";
// ---------------------

// --- Değişken Başlatma ---
$adminId = null;
$error = null;
$post_error = null;
$survey = null;
$questions = [];
$form_data = ($_SERVER["REQUEST_METHOD"] == "POST") ? $_POST : [];
// --- Bitiş Değişken Başlatma ---

// --- Cevap Seçenekleri ve Puan/Yorumlama ---
$optionsMap = [
    0 => "Hiçbir zaman", 1 => "Nadiren", 2 => "Bazen",
    3 => "Sıklıkla", 4 => "Çoğu zaman", 5 => "Her zaman"
];
$scoreMap = array_flip($optionsMap); // Metni puana çevirmek için (view-result içinde kullanılacak)

function interpretInternetAddictionScore($score) { // Yorumlama fonksiyonu burada da lazım olabilir
    if ($score === null || !is_numeric($score)) return "Puan hesaplanamadı.";
    if ($score >= 0 && $score <= 49) return "Ortalama internet kullanıcısı.";
    elseif ($score >= 50 && $score <= 79) return "Riskli internet kullanımı.";
    elseif ($score >= 80 && $score <= 100) return "İnternet bağımlısı.";
    else return "Geçersiz Puan.";
}
// -------------------------------------------


// --- Yönetici ID Kontrolü ---
$adminCheckError = null;
if (isset($_GET['admin_id']) && !empty($_GET['admin_id'])) {
    $potentialAdminId = filter_var($_GET['admin_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($potentialAdminId === false) {
        $adminCheckError = 'URL\'de geçersiz yönetici ID formatı.';
    } else {
        try {
            $adminStmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
            $adminStmt->execute([$potentialAdminId]);
            $admin = $adminStmt->fetch(PDO::FETCH_ASSOC);
            if ($admin) { $adminId = $admin['id']; }
            else { $adminCheckError = "Belirtilen yönetici ID ({$potentialAdminId}) sistemde bulunamadı."; }
        } catch (PDOException $e) {
            error_log("Admin ID Check PDO Error (S{$surveyId}): " . $e->getMessage());
            $adminCheckError = 'Yönetici bilgileri kontrol edilirken bir veritabanı hatası oluştu.';
            $error = $adminCheckError; // Ciddi hata
        }
    }
} // adminId yoksa null kalır
// --- Yönetici ID Kontrolü Sonu ---


// --- POST İŞLEME ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === null) {

    // 1. POST Verisini Al ve Doğrula (Admin olsun olmasın aynı)
    $surveyId_post = filter_input(INPUT_POST, 'survey_id', FILTER_VALIDATE_INT);
    $participantName = trim(htmlspecialchars($_POST['participant_name'] ?? '', ENT_QUOTES, 'UTF-8'));
    $participantClass = isset($_POST['participant_class']) ? trim(htmlspecialchars($_POST['participant_class'], ENT_QUOTES, 'UTF-8')) : null;
    $answers = $_POST['answers'] ?? []; // Dizi: [question_db_id => answer_score (0-5)]

    // Temel Doğrulamalar...
    if ($surveyId_post !== $surveyId) { $post_error = "Geçersiz anket bilgisi."; }
    elseif (empty($participantName)) { $post_error = "Ad Soyad alanı boş bırakılamaz."; }
    elseif (empty($answers) || !is_array($answers)) { $post_error = "Cevaplar alınamadı."; }
    else {
        // Cevapların geçerliliğini ve sayısını kontrol et
        $expectedQuestionCount = 0;
        try {
            $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM survey_questions WHERE survey_id = ?");
            $stmt_count->execute([$surveyId]);
            $expectedQuestionCount = (int)$stmt_count->fetchColumn();
        } catch (PDOException $e) { error_log("Soru sayısı alınamadı (S{$surveyId}): " . $e->getMessage()); }

        $answeredCount = 0;
        $invalidDataFound = false;
        $currentTotalScore = 0; // Skoru burada hesapla (admin yoksa lazım olacak)

        if ($expectedQuestionCount > 0) {
            if (count($answers) < $expectedQuestionCount) {
                 $post_error = "Lütfen tüm soruları cevaplayın.";
            } else {
                foreach ($answers as $qDbId => $aScore) {
                    $qIdCheck = filter_var($qDbId, FILTER_VALIDATE_INT);
                    $aScoreCheck = filter_var($aScore, FILTER_VALIDATE_INT);
                    $isValidScore = ($aScoreCheck !== false && isset($optionsMap[$aScoreCheck]));
                    if ($qIdCheck !== false && $isValidScore) {
                        $answeredCount++;
                        $currentTotalScore += $aScoreCheck; // Skoru topla
                    } else { $invalidDataFound = true; break; }
                }
                if ($invalidDataFound) { $post_error = "Geçersiz cevap verisi."; }
                // else if ($answeredCount < $expectedQuestionCount) { $post_error = "Tüm sorular cevaplanmalı."; } // Bu kontrol yukarı alındı
            }
        } else { error_log("Warning: Soru sayısı kontrol edilemedi (S{$surveyId})."); }
    }

    // ---- KOŞULLU KAYIT ve YÖNLENDİRME ----
    if ($post_error === null) {
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
                $stmtParticipant->bindParam(':admin_id', $adminId, PDO::PARAM_INT); // Admin ID burada KULLANILIYOR
                if (!$stmtParticipant->execute()) { throw new PDOException("Katılımcı kaydı başarısız: " . implode(";", $stmtParticipant->errorInfo())); }
                $participant_id = $pdo->lastInsertId();
                if (!$participant_id) { throw new Exception("Katılımcı ID alınamadı."); }

                // Cevapları Ekle (question_id'ye GERÇEK SORU ID, answer_text'e METİN)
                $stmtAnswer = $pdo->prepare(
                    "INSERT INTO survey_answers (participant_id, survey_id, question_id, answer_text, created_at)
                     VALUES (:pid, :sid, :qid, :answer_text, NOW())"
                );
                foreach ($answers as $actual_db_id => $answer_score) {
                    $qId = (int)$actual_db_id;
                    $answer_score_int = (int)$answer_score;
                    if (isset($optionsMap[$answer_score_int])) {
                        $answer_text_to_save = $optionsMap[$answer_score_int];
                        $stmtAnswer->bindParam(':pid', $participant_id, PDO::PARAM_INT);
                        $stmtAnswer->bindParam(':sid', $surveyId, PDO::PARAM_INT);
                        $stmtAnswer->bindParam(':qid', $qId, PDO::PARAM_INT); // Gerçek Soru ID
                        $stmtAnswer->bindParam(':answer_text', $answer_text_to_save, PDO::PARAM_STR); // Metin
                        if (!$stmtAnswer->execute()) { throw new PDOException("Cevap kaydı başarısız (QID: {$qId}): " . implode(";", $stmtAnswer->errorInfo())); }
                    }
                }
                $pdo->commit();
                header('Location: tamamlandi.php?pid=' . $participant_id); // Admin varsa TAMAMLANDI'ya
                exit();

            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log("Admin Survey Submit Exception (S{$surveyId}, Admin{$adminId}, PName:{$participantName}): " . $e->getMessage());
                $post_error = "<b>Kayıt Sırasında Bir Veritabanı Hatası Oluştu!</b><br>Lütfen tekrar deneyin veya sistem yöneticisi ile iletişime geçin.";
            }

        } else {
             // **** SENARYO 2: ADMIN YOK -> VERİTABANINA KAYDETME, SONUCU SESSION'A AT, view-result-15.php'ye YÖNLENDİR ****

             // Puanı ve yorumu hesapla
             $scoreInterpretation = interpretInternetAddictionScore($currentTotalScore);

             // Sonuçları session'a kaydet
             $_SESSION['survey_result_data'] = [ // Anahtar adını değiştirdim
                'survey_id' => $surveyId,
                'score' => $currentTotalScore,
                'interpretation' => $scoreInterpretation,
                'answers' => $answers, // Gönderilen cevapları sakla [qid => score]
                'participant_name' => $participantName, // İsim ve sınıfı da sakla
                'participant_class' => $participantClass,
                'timestamp' => time() // Sonucun ne zaman üretildiğini bilmek için
             ];

             // Sonuç sayfasına yönlendir (ID olmadan)
             header('Location: ../admin/view-result-15.php');
             exit();
        }
    } // End if ($post_error === null)
    // Hata varsa ($post_error doluysa), script aşağıdan devam edip formu tekrar gösterecek.
}
// --- POST İŞLEME SONU ---


// --- Formu Göstermek İçin Veri Çek ---
// Sadece ciddi GET hatası yoksa veri çek
if ($error === null) {
     try {
        $stmtSurvey = $pdo->prepare("SELECT title FROM surveys WHERE id = ?");
        $stmtSurvey->execute([$surveyId]);
        $survey = $stmtSurvey->fetch(PDO::FETCH_ASSOC);

        $stmtQuestions = $pdo->prepare("SELECT id, question AS question_text, sort_order FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC, id ASC");
        $stmtQuestions->execute([$surveyId]);
        $questions = $stmtQuestions->fetchAll(PDO::FETCH_ASSOC);

        if (empty($questions)) { $error = "Bu anket ({$surveyId}) için soru bulunamadı."; }
        else { /* Sort order kontrolü... */ }
    } catch (Exception $e) {
        $error = "Anket verileri yüklenirken hata oluştu."; error_log("Data Fetch Error: ".$e->getMessage()); $questions = [];
    }
}
// --- Veri Çekme Sonu ---

// Sayfa başlığını ayarla
$pageTitle = isset($survey['title']) ? htmlspecialchars($survey['title']) : $testTitleDefault;

// Form action URL'sini dinamik oluştur
$actionUrl = "take-survey-15.php";
if ($adminId !== null) { $actionUrl .= "?admin_id=" . htmlspecialchars($adminId); }

// Header gönder
if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> <?= $adminId ? '- Yönetici (' . htmlspecialchars($adminId) . ')' : '- Ücretsiz Anket' ?></title>
    <style>
        /* Stil Bloğu (öncekiyle aynı, yeşil tema) */
        body { font-family: sans-serif; line-height: 1.6; background-color: #f0fdf4; color: #2c3e50; margin: 0; padding: 20px; }
        .container { max-width: 800px; margin: 40px auto; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); text-align: left; background-color: #ffffff; }
        h2 { text-align: center; font-size: 1.5rem; font-weight: 600; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 2px solid #dcfce7; color: #1f2937; }
        .instructions { background-color: #e8f5e9; padding: 15px; border-radius: 5px; margin-bottom: 25px; font-size: 0.95em; border: 1px solid #c8e6c9; }
        .instructions p { margin: 0.5rem 0; }

        .options-group-styled { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; padding-top: 5px;}
         .option-label-styled { cursor: pointer; padding: 8px 10px; border: 1px solid #ced4da; border-radius: 5px; background-color: #fff; transition: background-color 0.2s, border-color 0.2s, color 0.2s; text-align: center; flex-grow: 1; min-width: 90px; font-size: 0.85em; line-height: 1.4; display: inline-block; vertical-align: middle; user-select: none; }
        .option-label-styled input[type="radio"] { display: none; }
        input[type="radio"]:checked + span { background-color: #22c55e; border-color: #16a34a; color: white; font-weight: bold; display: inline-block; padding: 8px 10px; border-radius: 5px; border: 1px solid transparent; }
        .option-label-styled:hover span { background-color: #dcfce7; border-color: #a7f3d0; }
        input[type="radio"]:checked + span:hover { background-color: #16a34a; }

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
        .admin-info-box { color: #0f5132; background-color: #d1e7dd; border-color: #badbcc; padding: 0.75rem 1rem; margin-bottom: 1.5rem; border: 1px solid transparent; border-radius: 0.375rem; font-size: 0.9em; }
        .public-info-box { background-color: #cfe2ff; border-color: #b6d4fe; color: #084298; padding: 0.75rem 1rem; margin-bottom: 1.5rem; border: 1px solid transparent; border-radius: 0.375rem; font-size: 0.9em;}


        fieldset { border: none; padding: 0; margin: 0; margin-bottom: 1.75rem; padding-bottom: 1rem; border-bottom: 1px solid #f3f4f6; }
        fieldset:last-of-type { border-bottom: none; margin-bottom: 1rem; }
        legend { font-size: 1rem; line-height: 1.6; font-weight: 500; color: #1f2937; margin-bottom: 0.75rem; padding-top: 0.5rem; }
        .required-star { color: #dc2626; font-weight: bold; margin-left: 2px; }

        .validation-error { border-color: #ef4444 !important; }
        .validation-error-text { color: #dc2626; font-size: 0.75rem; line-height: 1rem; margin-top: 0.25rem; min-height: 1.2em; }
        legend.validation-error { color: #dc2626 !important; font-weight: bold; }

        .mt-6 { margin-top: 1.5rem; }
        a { color: #15803d; } a:hover { color: #0b532c; }
        .text-center { text-align: center; }
    </style>
</head>
<body>
    <div class="container">
        <h2><?= $pageTitle ?></h2>

        <?php if ($error): // GET hatası (Ciddi) ?>
            <div class="error-box" role="alert"><b>Hata:</b> <?= $error ?></div>
        <?php elseif ($post_error): // POST hatası ?>
            <div class="error-box" role="alert"><?= $post_error ?></div>
         <?php elseif ($adminCheckError): // Admin ID geçersizdi ?>
             <div class="error-box" role="alert" style="background-color: #fff3cd; border-color: #ffe69c; color: #664d03;">
                 <b>Uyarı:</b> <?= $adminCheckError ?> Yönetici kimliği olmadan devam ediyorsunuz. Sonuçlarınız size gösterilecektir.
             </div>
        <?php endif; ?>


        <?php // Formu Göster (Ciddi GET hatası yoksa ve sorular varsa) ?>
        <?php if (!$error && !empty($questions)): ?>

            <?php // Bilgilendirme kutusu (Admin veya Public) ?>
            <?php if ($adminId !== null): ?>
                <div class="admin-info-box">
                    Bu anket <strong>Yönetici ID: <?= htmlspecialchars($adminId) ?></strong> tarafından başlatıldı. Sonuçlarınız kaydedilecek ve size gösterilmeyecektir.
                </div>
            <?php else: ?>
                 <div class="public-info-box">
                    Bu herkese açık ücretsiz bir ankettir. Sonuçlarınız oturum sonunda size gösterilecektir.
                </div>
            <?php endif; ?>

            <form method="POST" id="surveyForm" action="<?= $actionUrl ?>" novalidate>
                <input type="hidden" name="survey_id" value="<?= $surveyId ?>">

                <div class="info">
                    <div class="info-grid">
                        <div>
                            <label for="participant_name">Ad Soyadınız <span class="required-star">*</span></label>
                            <input type="text" id="participant_name" name="participant_name" required value="<?= htmlspecialchars($form_data['participant_name'] ?? '') ?>" class="<?= ($post_error && empty($form_data['participant_name'])) ? 'validation-error' : '' ?>" aria-describedby="nameError">
                            <p id="nameError" class="validation-error-text" aria-live="polite"></p>
                        </div>
                        <div>
                            <label for="participant_class">Sınıfınız / Bölümünüz:</label>
                            <input type="text" id="participant_class" name="participant_class" value="<?= htmlspecialchars($form_data['participant_class'] ?? '') ?>" aria-describedby="classError">
                            <p id="classError" class="validation-error-text" aria-live="polite"></p>
                        </div>
                     </div>
                </div>

                 <div class="instructions">
                     <p>Aşağıdaki soruları katılım düzeyiniz hangi gruba giriyorsa ona göre işaretleyiniz. Her birisi için <strong>"Hiçbir zaman (0)"..."Her zaman (5)"</strong> seçeneklerden birini işaretleyiniz.</p>
                     <p>Vereceğiniz samimi cevaplar önemlidir.</p>
                 </div>


                <?php foreach ($questions as $question):
                    $question_id = $question['id']; // Gerçek Veritabanı ID'si
                    $sort_order = $question['sort_order']; // Sıra numarası
                    $submitted_answer = $form_data['answers'][$question_id] ?? null;
                 ?>
                    <fieldset id="q_fieldset_<?= $question_id ?>">
                        <legend id="q_legend_<?= $question_id ?>" class="<?= ($post_error && $submitted_answer === null) ? 'validation-error' : '' ?>">
                           <?= htmlspecialchars($sort_order) ?>. <?= htmlspecialchars($question['question_text'] ?? 'Soru metni yüklenemedi') ?>
                           <span class="required-star" aria-hidden="true">*</span>
                        </legend>
                        <div class="options-group-styled" role="radiogroup" aria-labelledby="q_legend_<?= $question_id ?>">
                            <?php foreach ($optionsMap as $value => $label):
                                $input_id = "q{$question_id}_opt{$value}";
                                $is_checked = ($submitted_answer !== null && (string)$submitted_answer === (string)$value);
                            ?>
                               <label for="<?= $input_id ?>" class="option-label-styled">
                                   <input type="radio" id="<?= $input_id ?>" name="answers[<?= $question_id // GERÇEK ID ?>]" value="<?= $value // PUAN DEĞERİ ?>" required <?= $is_checked ? 'checked' : '' ?>>
                                   <span><?= htmlspecialchars($label) ?></span>
                               </label>
                            <?php endforeach; ?>
                        </div>
                         <?php // Gizli sort_order alanına gerek YOK, çünkü doğru qid kaydediliyor ?>
                        <p id="qError_<?= $question_id ?>" class="validation-error-text" aria-live="polite"></p>
                    </fieldset>
                <?php endforeach; ?>


                <button type="submit" class="nav-button submit"> Anketi Tamamla ve Gönder </button>

            </form>

        <?php // Formun gösterilemediği diğer durumlar için mesaj ?>
        <?php elseif (!$error && !$post_error): ?>
             <div class="error-box" style="background-color: #ebf8ff; border-color: #90cdf4; color: #2c5282;" role="alert">
                  <strong>Bilgi:</strong> Anket yüklenemedi veya gösterilecek soru bulunamadı. Lütfen <a href="javascript:location.reload();">sayfayı yenileyin</a> veya yönetici ile iletişime geçin.
             </div>
        <?php endif; ?>
    </div>

    <?php // Basit İstemci Taraflı Doğrulama ?>
     <?php if (!$error && !empty($questions)): ?>
    <script>
        // Öncekiyle aynı basit JS doğrulaması...
         const form = document.getElementById('surveyForm');
         if (form) { /* ... (JS Kodu Aynı) ... */ }
    </script>
     <?php endif; ?>

</body>
</html>