<?php
// --- Hata Raporlama (Geliştirme için Açık) ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// ---------------------------

session_start(); // Oturumları başlat (Gerekli olmasa da kalabilir)
header('Content-Type: text/html; charset=utf-8'); // Karakter setini ayarla

// --- Veritabanı Bağlantısı ---
require_once __DIR__ . '/src/config.php'; // PDO $pdo nesnesini oluşturur

// --- Bağlantı Kontrolü ---
if (!isset($pdo) || !$pdo instanceof PDO) {
    error_log("CRITICAL: PDO connection object (\$pdo) not created or found in src/config.php");
    die('<div style="border: 1px solid red; padding: 10px; margin: 10px; background-color: #ffebeb; color: red; font-family: sans-serif;"><b>Kritik Hata:</b> Veritabanı bağlantısı kurulamadı (src/config.php).</div>');
}
// --------------------------------

// --- Konfigürasyon ---
$surveyId = 14; // Holland Envanteri ID'si
$testTitleDefault = "Holland Mesleki Tercih Envanteri"; // Varsayılan Başlık
$questionsPerPage = 10; // Sayfa başına soru sayısı
// ---------------------

// Değişkenleri başlat
$adminId = null; $error = null; $survey = null; $questions = []; $groups = [];

// --- Yönetici ID Doğrulama (Düzeltilmiş Tam Blok) ---
if (isset($_GET['admin_id'])) {
    $potentialAdminId = filter_var($_GET['admin_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($potentialAdminId === false) {
        $error = 'URL\'de geçersiz yönetici ID formatı.';
    } else {
        try {
            if (!isset($pdo) || !$pdo instanceof PDO) { throw new Exception("Veritabanı bağlantısı bulunamadı."); }

            $adminStmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
            $adminStmt->execute([$potentialAdminId]);
            $admin = $adminStmt->fetch(PDO::FETCH_ASSOC);

            if ($admin) { $adminId = $admin['id']; }
            else { $error = "Belirtilen yönetici ID ({$potentialAdminId}) sistemdeki `users` tablosunda bulunamadı."; }

        } catch (PDOException $e) {
            error_log("Admin ID Check PDO Error (Survey {$surveyId}): " . $e->getMessage());
            $error = 'Yönetici bilgileri kontrol edilirken bir veritabanı hatası oluştu.';
        } catch (Exception $e) {
             error_log("Admin ID Check General Error (Survey {$surveyId}): " . $e->getMessage());
             $error = 'Yönetici doğrulaması sırasında bir hata oluştu: ' . $e->getMessage();
        }
    }
} else {
    $error = 'Yönetici ID\'si URL\'de eksik veya belirtilmemiş (?admin_id=...).';
}

if (!$adminId && !$error) { $error = "Yönetici ID doğrulanamadı (Bilinmeyen Neden)."; }

// Eğer adminId alınamadıysa veya bir hata oluştuysa işlemi durdur ve HATA GÖSTER
if (!$adminId) {
     $log_message = "Access Denied/Error on take-survey-{$surveyId}.php. ";
     $log_message .= $error ?: "Reason: Unknown (adminId is null after checks).";
     error_log($log_message);
     die('<div style="border: 1px solid red; padding: 15px; margin: 20px; background-color: #fee2e2; color: #b91c1c; font-family: sans-serif;">' .
         '<b>Erişim Hatası!</b><br>' . ($error ?: 'Geçerli bir yönetici kimliği sağlanamadı.') .
         '<br><br>Lütfen URL\'deki `admin_id` parametresini kontrol edin veya sistem yöneticinizle iletişime geçin.' .
         '</div>');
}
// --- Yönetici ID Doğrulama Sonu ---


// --- Anket ve Soruları Çek (sort_order dahil) ---
// (Bu blok önceki yanıttaki gibi, $questions dizisini id, question_text, sort_order ile doldurur)
if ($adminId && !$error) { // Sadece admin doğrulanmışsa ve hata yoksa devam et
    try {
        // Anket başlığını al
        $stmtSurvey = $pdo->prepare("SELECT title FROM surveys WHERE id = ?");
        $stmtSurvey->execute([$surveyId]);
        $survey = $stmtSurvey->fetch(PDO::FETCH_ASSOC);
        // Başlık alınamazsa $survey null olacak, $pageTitle varsayılanı kullanacak

        // Soruları çek (id, question_text, sort_order)
        $stmtQuestions = $pdo->prepare("SELECT id, question_text, sort_order FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC, id ASC");
        $stmtQuestions->execute([$surveyId]);
        $questions = $stmtQuestions->fetchAll(PDO::FETCH_ASSOC);

        if (empty($questions)) {
            $error = "Bu anket ({$surveyId}) için `survey_questions` tablosunda hiç soru bulunamadı.";
        } else {
             // sort_order'ın geçerliliğini kontrol et
             $valid_sort_order = true;
             foreach($questions as $q) {
                 if(!isset($q['sort_order']) || !filter_var($q['sort_order'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
                    error_log("Survey $surveyId question ID {$q['id']} has invalid sort_order: " . var_export($q['sort_order'], true));
                    $error = "Soruların sıralama bilgisinde hata var (ID: {$q['id']}). Yöneticiye bildirin.";
                    $valid_sort_order = false;
                    break;
                 }
             }
             // Geçerli sort_order varsa grupla
             if ($valid_sort_order) {
                 $groups = array_chunk($questions, $questionsPerPage);
             } else {
                  $questions = []; // Hatalı sort_order varsa soruları boşalt ki form gösterilmesin
             }
        }
    } catch (PDOException $e) {
        error_log("Data Fetch PDO Error (Survey {$surveyId}): " . $e->getMessage());
        $error = "Anket verileri yüklenirken bir veritabanı hatası oluştu.";
        $questions = []; // Hata varsa soruları boşalt
    } catch (Exception $e) {
        error_log("Data Fetch General Error (Survey {$surveyId}): " . $e->getMessage());
        $error = "Anket verileri yüklenirken genel bir hata oluştu: " . $e->getMessage();
        $questions = []; // Hata varsa soruları boşalt
    }
}
// --- Veri Çekme Sonu ---


// --- POST İşleme (Mantıksal Soru Numarasını Kaydeden Versiyon) ---
if ($adminId && !$error && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($questions)) {
    $name = isset($_POST['student_name']) ? trim($_POST['student_name']) : '';
    $class = isset($_POST['student_class']) ? trim($_POST['student_class']) : '';
    $answers = isset($_POST['answers']) && is_array($_POST['answers']) ? $_POST['answers'] : [];
    $sort_orders_posted = isset($_POST['sort_orders']) && is_array($_POST['sort_orders']) ? $_POST['sort_orders'] : [];

    // Doğrulama
    $validationErrors = [];
    if (empty($name)) $validationErrors[] = "Ad Soyad alanı boş bırakılamaz.";
    if (empty($class)) $validationErrors[] = "Sınıf alanı boş bırakılamaz.";
    $expectedTotalQuestions = count($questions); $totalQuestionsAnswered = count($answers);
    if ($totalQuestionsAnswered < $expectedTotalQuestions) { $missingCount = $expectedTotalQuestions - $totalQuestionsAnswered; $validationErrors[] = "Lütfen tüm soruları cevapladığınızdan emin olun. {$missingCount} soru eksik."; }
    $validOptions = ['Hoşlanırım', 'Farketmez', 'Hoşlanmam'];
    foreach ($answers as $actual_db_id => $answer) {
        if (!filter_var($actual_db_id, FILTER_VALIDATE_INT)) { $validationErrors[] = "Geçersiz soru formatı algılandı."; continue; }
        if (!in_array($answer, $validOptions)) { $validationErrors[] = "Geçersiz cevap değeri gönderildi."; }
        if (!isset($sort_orders_posted[$actual_db_id]) || !filter_var($sort_orders_posted[$actual_db_id], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) { $validationErrors[] = "Soru sırası bilgisi eksik veya geçersiz (ID: $actual_db_id)."; }
    }

    // Sonuç
    if (!empty($validationErrors)) {
        $error = "Lütfen aşağıdaki hataları düzeltip tekrar deneyin:<br> - " . implode("<br> - ", $validationErrors);
    } else {
        // Veritabanı İşlemi
        try {
            $pdo->beginTransaction();
            // Katılımcı ekle
            $stmtParticipant = $pdo->prepare("INSERT INTO survey_participants (name, class, survey_id, admin_id, created_at) VALUES (:name, :class, :survey_id, :admin_id, NOW())");
            $stmtParticipant->execute([':name' => $name, ':class' => $class, ':survey_id' => $surveyId, ':admin_id' => $adminId]);
            $participantId = (int)$pdo->lastInsertId();
            if (!$participantId) { throw new PDOException("Katılımcı ID'si alınamadı."); }

            // Cevapları Ekle (question_id'ye sort_order kaydedilecek)
            $stmtAnswer = $pdo->prepare("INSERT INTO survey_answers (participant_id, survey_id, question_id, answer_text, created_at) VALUES (:pid, :sid, :qid, :answer, NOW())");
            $skipped_answers = 0;
            foreach ($answers as $actual_db_id => $answer) {
                $logical_question_number = (int)($sort_orders_posted[$actual_db_id] ?? 0);
                 if ($logical_question_number <= 0) {
                      error_log("Critical Error: Invalid logical question number (0) for DB ID {$actual_db_id} during save for participant {$participantId}, survey {$surveyId}. Skipping answer.");
                      $skipped_answers++;
                      continue; // Bu cevabı kaydetme
                 }
                 $stmtAnswer->execute([ ':pid' => $participantId, ':sid' => $surveyId, ':qid' => $logical_question_number, ':answer' => $answer, ]);
            }

            // Eğer cevap atlandıysa bir uyarı verilebilir (opsiyonel)
             if ($skipped_answers > 0) {
                error_log("Warning: {$skipped_answers} answers were skipped during save for participant {$participantId} due to invalid sort_order mapping.");
                // Belki kullanıcıya da bir mesaj gösterilebilir, ama genellikle loglamak yeterli.
             }


            $pdo->commit();
            header('Location: tamamlandi.php?status=success&pid=' . $participantId); exit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Holland Kayıt PDOException: Code[{$e->getCode()}] " . $e->getMessage());
            $error = "<b>Kayıt Sırasında Bir Veritabanı Hatası Oluştu!</b><br>Teknik Mesaj: " . htmlspecialchars($e->getMessage());
        } catch (Exception $e) {
             if ($pdo->inTransaction()) $pdo->rollBack();
             error_log("Holland Kayıt Genel Hata: " . $e->getMessage());
             $error = "Kayıt sırasında beklenmedik bir sistem hatası oluştu: " . htmlspecialchars($e->getMessage());
        }
    }
}
// --- POST İşleme Sonu ---

$pageTitle = isset($survey['title']) ? htmlspecialchars($survey['title']) : $testTitleDefault;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <?php /* Stil Bloğu (take-survey-12 stilini kullanır) */ ?>
    <style>
        /* Stil Bloğu (take-survey-12.php'den alındı ve uyarlandı) */
        body { font-family: sans-serif; line-height: 1.6; background-color: #f0fdf4; color: #2c3e50; margin: 0; padding: 20px; }
        .container { max-width: 800px; margin: 40px auto; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); text-align: left; background-color: #ffffff; }
        h2 { text-align: center; font-size: 1.5rem; font-weight: 600; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 2px solid #dcfce7; color: #1f2937; }
        h3 { font-size: 1.25rem; font-weight: 500; margin-bottom: 1rem; color: #374151; }
        .question-group { display: none; } .question-group.active { display: block; }
        .question-button { background: #f0fdf4; border: 2px solid #bbf7d0; color: #15803d; padding: 10px 15px; border-radius: 8px; transition: all 0.2s ease-in-out; text-align: center; cursor: pointer; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); user-select: none; font-size: 0.9rem; margin: 0.25rem; display: inline-block; }
        .question-button.active { background: #22c55e; border-color: #16a34a; color: white; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06); transform: translateY(-2px); }
        .question-button:hover:not(.active) { background-color: #dcfce7; border-color: #a7f3d0; }
        .nav-button { padding: 12px 30px; border-radius: 8px; font-weight: 600; transition: all 0.2s ease-in-out; cursor: pointer; border: none; color: white; }
        .nav-button.next { background: #15803d; } .nav-button.next:hover { background: #0b532c; }
        .nav-button.submit { background: #2563eb; } .nav-button.submit:hover { background: #1d4ed8; }
        .nav-button:disabled { background-color: #9ca3af; cursor: not-allowed; opacity: 0.7;}
        .info { margin-bottom: 1.5rem; } .info label { display: block; margin-bottom: 5px; font-weight: 600; color: #374151; }
        .info input { padding: 8px 10px; width: 100%; box-sizing: border-box; border: 1px solid #ccc; border-radius: 6px; font-size: 1em; color: #2c3e50; height: 40px; background-color: white;}
        .info input:focus { border-color: #15803d; box-shadow: 0 0 0 2px rgba(34, 197, 94, 0.3); outline: none; }
        .error-box { color: #b91c1c; background-color: #fee2e2; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; border: 1px solid #fca5a5; font-weight: 500; text-align: left; }
        .error-box b { font-weight: bold; }
        fieldset { border: none; padding: 0; margin: 0; margin-bottom: 1.75rem; padding-bottom: 1rem; border-bottom: 1px solid #f3f4f6; }
        fieldset:last-of-type { border-bottom: none; margin-bottom: 1rem; }
        legend { font-size: 1rem; line-height: 1.6; font-weight: 500; color: #1f2937; margin-bottom: 0.75rem; }
        .required-star { color: #dc2626; font-weight: bold; margin-left: 2px; }
        .hidden { display: none; } .flex { display: flex; } .flex-wrap { flex-wrap: wrap; } .justify-end { justify-content: flex-end; } .items-center { align-items: center; }
        .gap-3 > * { margin: 0.25rem; } .mt-6 { margin-top: 1.5rem; } .text-center { text-align: center; } .underline { text-decoration: underline; }
        a { color: #2563eb; } a:hover { color: #1d4ed8; }
        .locked-info { background-color: #f3f4f6; padding: 0.75rem; border-radius: 6px; margin-bottom: 1.25rem; border: 1px solid #e5e7eb; font-size: 0.9rem; color: #374151; }
        .locked-info strong { font-weight: 600; color: #1f2937; } .ml-2 { margin-left: 0.5rem; }
        .validation-error { border-color: #ef4444 !important; }
        .validation-error-text { color: #dc2626; font-size: 0.75rem; line-height: 1rem; margin-top: 0.25rem; min-height: 1.2em; }
        legend.validation-error-text { color: #dc2626 !important; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <h2><?= $pageTitle ?></h2>

        <?php if ($error): ?>
            <div class="error-box" role="alert"> <?= $error ?> </div>
        <?php endif; ?>

        <?php // Formu sadece hata yoksa ve sorular varsa göster ?>
        <?php if (!$error && !empty($questions)): ?>
            <form method="POST" id="surveyForm" action="take-survey-14.php?admin_id=<?= htmlspecialchars($adminId) ?>" novalidate>

                <?php // Kişisel Bilgiler Bölümü (Sayfa 0) ?>
                <div class="question-group active" data-page="0">
                    <h3>Katılımcı Bilgileri</h3>
                    <div class="info">
                        <div style="margin-bottom: 1rem;">
                            <label for="student_name">Ad Soyadınız:</label>
                            <input type="text" id="student_name" name="student_name" required value="<?= isset($_POST['student_name']) ? htmlspecialchars($_POST['student_name']) : '' ?>" aria-describedby="nameError">
                            <p id="nameError" class="validation-error-text" aria-live="polite"></p>
                        </div>
                        <div style="margin-bottom: 1rem;">
                            <label for="student_class">Sınıfınız / Bölümünüz:</label>
                            <input type="text" id="student_class" name="student_class" required value="<?= isset($_POST['student_class']) ? htmlspecialchars($_POST['student_class']) : '' ?>" aria-describedby="classError">
                            <p id="classError" class="validation-error-text" aria-live="polite"></p>
                        </div>
                    </div>
                    <div class="flex justify-end mt-6">
                         <button type="button" onclick="showNextPage()" class="nav-button next"> Sorulara Başla → </button>
                     </div>
                </div>

                <?php // Soru Grupları (Sayfa 1+)
                foreach ($groups as $pageIndex => $pageQuestions): ?>
                    <?php $actualPageIndex = $pageIndex + 1; ?>
                    <div class="question-group" data-page="<?= $actualPageIndex ?>">
                        <div class="locked-info">
                             <strong>Katılımcı:</strong> <span id="lockedName_<?= $actualPageIndex ?>" class="ml-2"></span> |
                            <strong class="ml-2">Sınıf/Bölüm:</strong> <span id="lockedClass_<?= $actualPageIndex ?>" class="ml-2"></span>
                        </div>
                        <h3>Sorular (Sayfa <?= $actualPageIndex ?> / <?= count($groups) ?>)</h3>

                        <?php foreach ($pageQuestions as $q): ?>
                            <fieldset id="q_fieldset_<?= $q['id'] ?>">
                                <legend id="q_legend_<?= $q['id'] ?>">
                                   <?= htmlspecialchars($q['sort_order'] ?? '?') ?>. <?= htmlspecialchars($q['question_text'] ?? 'Soru metni yüklenemedi') ?>
                                   <span class="required-star" aria-hidden="true">*</span>
                                </legend>
                                <div class="flex flex-wrap gap-3" role="radiogroup" aria-labelledby="q_legend_<?= $q['id'] ?>">
                                    <?php $options = ['Hoşlanırım', 'Farketmez', 'Hoşlanmam']; ?>
                                    <?php foreach ($options as $option): ?>
                                        <button type="button" role="radio" aria-checked="false"
                                                class="question-button"
                                                onclick="selectAnswer(<?= $q['id'] ?>, this)" <?php // JS hala gerçek ID ile çalışıyor ?>
                                                data-value="<?= htmlspecialchars($option) ?>"
                                                data-qid="<?= $q['id'] ?>">
                                            <?= htmlspecialchars($option) ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                                <?php // Cevap inputu hala gerçek ID ile anahtarlanıyor ?>
                                <input type="hidden" name="answers[<?= $q['id'] ?>]" id="answer_<?= $q['id'] ?>" value="<?= isset($_POST['answers'][$q['id']]) ? htmlspecialchars($_POST['answers'][$q['id']]) : '' ?>">
                                <?php // Her soru için sort_order'ı da gizli olarak gönderelim ?>
                                <input type="hidden" name="sort_orders[<?= $q['id'] ?>]" value="<?= htmlspecialchars($q['sort_order'] ?? '0') ?>">
                                <p id="qError_<?= $q['id'] ?>" class="validation-error-text" aria-live="polite"></p>
                            </fieldset>
                        <?php endforeach; ?>

                        <?php // Navigasyon Butonları ?>
                        <div class="flex justify-end items-center mt-6">
                             <?php if ($actualPageIndex < count($groups)): ?>
                                <button type="button" onclick="showNextPage()" class="nav-button next"> Sonraki Sayfa → </button>
                             <?php else: ?>
                                <button type="submit" class="nav-button submit"> Anketi Tamamla ve Gönder </button>
                             <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </form>

        <?php elseif ($error): // Soru yüklenirken veya admin ID'de hata olduysa ?>
             <div class="text-center mt-6"> <a href="javascript:location.reload();" class="underline">Sayfayı Yenilemeyi Deneyin</a> </div>
        <?php else: // Hata yok ama $questions dizisi boşsa (Normalde olmamalı ama ekstra kontrol) ?>
            <div class="error-box" style="background-color: #ebf8ff; border-color: #90cdf4; color: #2c5282;" role="alert"> <strong>Bilgi:</strong> Anket yüklenemedi veya gösterilecek soru bulunamadı. </div>
        <?php endif; ?>
    </div>

    <?php // JavaScript (Tam Hali - Değişiklik Yok)
    if (!$error && !empty($questions)): ?>
    <script>
        let currentPageIndex = 0; // Aktif olan 'question-group'un index'i (0'dan başlar)
        const questionGroups = document.querySelectorAll('.question-group');
        const totalPages = questionGroups.length; // Toplam sayfa sayısı (kişisel bilgi + soru sayfaları)
        const nameInput = document.getElementById('student_name');
        const classInput = document.getElementById('student_class');
        const form = document.getElementById('surveyForm');

        // Hata mesajı paragrafları (ID'leri HTML ile eşleşmeli)
        const nameErrorP = document.getElementById('nameError');
        const classErrorP = document.getElementById('classError');

        // Sayfa yüklendiğinde hata durumunda önceden seçilmiş cevapları işaretle
        document.addEventListener('DOMContentLoaded', function() {
            restoreSelections();
            // Başlangıçta doğru sayfanın aktif olduğundan emin ol
             if (questionGroups.length > 0) {
                 showPage(currentPageIndex);
             } else {
                 // Eğer hiç soru grubu yoksa (PHP hata verdi veya soru yoksa), JS'i çalıştırma
                 console.warn("No question groups found to initialize.");
             }
        });

        // Hata durumunda veya sayfa yenilendiğinde seçili butonları tekrar işaretler
        function restoreSelections() {
             questionGroups.forEach(group => {
                const hiddenInputs = group.querySelectorAll('input[type="hidden"][name^="answers["]');
                hiddenInputs.forEach(input => {
                    if (input.value) {
                        // ID'yi input name'den al: answers[123] -> 123
                        const qidMatch = input.name.match(/\[(\d+)\]/);
                         if (qidMatch && qidMatch[1]) {
                            const qid = qidMatch[1];
                            // İlgili butonları data-qid ile bul
                            const buttons = group.querySelectorAll(`button[data-qid="${qid}"]`);
                            buttons.forEach(button => {
                                if (button.dataset.value === input.value) {
                                    button.classList.add('active');
                                    button.setAttribute('aria-checked', 'true');
                                } else {
                                    button.classList.remove('active');
                                    button.setAttribute('aria-checked', 'false');
                                }
                            });
                        }
                    }
                });
            });
        }

        // Bir cevap butonu tıklandığında çalışır
        function selectAnswer(questionId, buttonElement) {
            const parentFieldset = buttonElement.closest('fieldset'); // Fieldset'i bulmak önemli
            if (!parentFieldset) { console.error("Fieldset not found for button:", buttonElement); return; }

            const buttonsInGroup = parentFieldset.querySelectorAll(`button[data-qid="${questionId}"]`);
            const hiddenInput = document.getElementById(`answer_${questionId}`); // Gizli input ID ile bulunur
            const errorP = document.getElementById(`qError_${questionId}`); // Hata mesajı <p> ID ile bulunur
            const legend = document.getElementById(`q_legend_${questionId}`); // Soru başlığı (legend) ID ile bulunur

            // Önce aynı soruya ait diğer butonlardaki 'active' sınıfını kaldır
            buttonsInGroup.forEach(btn => {
                btn.classList.remove('active');
                btn.setAttribute('aria-checked', 'false');
            });

            // Tıklanan butona 'active' sınıfını ekle
            buttonElement.classList.add('active');
            buttonElement.setAttribute('aria-checked', 'true');

            // İlgili gizli input'un değerini ayarla
            if(hiddenInput) {
                 hiddenInput.value = buttonElement.dataset.value;
                 // Hata stillerini temizle
                 if (errorP) errorP.textContent = '';
                 if (legend) legend.classList.remove('validation-error-text'); // Kırmızı rengi kaldır
                 // Gerekirse fieldset'ten de hata sınıfı kaldırılabilir:
                 // parentFieldset.classList.remove('validation-error');
            } else {
                 // Bu durum olmamalı ama olursa logla
                 console.error("Hidden input not found for question ID:", questionId);
            }
        }

        // Belirtilen sayfadaki validasyon hata stillerini temizler
        function clearValidationErrors(pageIndex) {
            const group = questionGroups[pageIndex];
            if (!group) return;

            // Kişisel bilgi sayfası (index 0)
            if (pageIndex === 0) {
                if(nameInput) nameInput.classList.remove('validation-error');
                if(classInput) classInput.classList.remove('validation-error');
                if (nameErrorP) nameErrorP.textContent = '';
                if (classErrorP) classErrorP.textContent = '';
            }

            // Soru sayfaları (index > 0 veya tüm sayfalar)
            const legends = group.querySelectorAll('legend'); // Tüm legendları bul
            legends.forEach(legend => {
                 legend.classList.remove('validation-error-text'); // Kırmızı rengi kaldır
            });
            const errorPs = group.querySelectorAll('.validation-error-text'); // Tüm hata p'lerini bul
             errorPs.forEach(p => {
                // İsim ve sınıf hataları hariç soru hatalarını temizle
                if(p.id !== 'nameError' && p.id !== 'classError') {
                   p.textContent = '';
                }
            });
            // Gerekirse input hata stilleri de temizlenir:
            // const inputs = group.querySelectorAll('input.validation-error');
            // inputs.forEach(input => input.classList.remove('validation-error'));
        }

        // Belirtilen sayfadaki girdileri doğrular
        function validatePage(pageIndex) {
            clearValidationErrors(pageIndex); // Önceki hataları temizle
            let isValid = true;
            const group = questionGroups[pageIndex];
            if (!group) {
                console.error("Validation failed: Page group not found for index", pageIndex);
                return false; // Sayfa grubu yoksa geçersiz
            }

            // Kişisel bilgi sayfası kontrolü (index 0)
            if (pageIndex === 0) {
                 if (!nameInput || !nameInput.value.trim()) {
                    if(nameInput) nameInput.classList.add('validation-error');
                    if (nameErrorP) nameErrorP.textContent = 'Lütfen adınızı ve soyadınızı girin.';
                    isValid = false;
                 }
                 if (!classInput || !classInput.value.trim()) {
                    if(classInput) classInput.classList.add('validation-error');
                     if (classErrorP) classErrorP.textContent = 'Lütfen sınıfınızı veya bölümünüzü girin.';
                    isValid = false;
                 }
            }
            // Soru sayfası kontrolü (index > 0)
            else {
                 const questionsOnPage = group.querySelectorAll('input[type="hidden"][name^="answers["]');
                 if (questionsOnPage.length === 0) {
                     // Bu sayfada soru inputu yoksa (beklenmedik durum), geçerli say
                     console.warn("No question inputs found on page index", pageIndex);
                 }
                 questionsOnPage.forEach(input => {
                     if (!input.value) { // Eğer gizli input boşsa (cevap seçilmemişse)
                         isValid = false;
                         const qidMatch = input.name.match(/\[(\d+)\]/);
                         if (qidMatch && qidMatch[1]) {
                             const qid = qidMatch[1];
                             const errorP = document.getElementById(`qError_${qid}`);
                             const legend = document.getElementById(`q_legend_${qid}`);
                             if (legend) legend.classList.add('validation-error-text'); // Soruyu kırmızı yap
                             if (errorP) errorP.textContent = 'Lütfen bu soru için bir seçim yapın.';
                         }
                     }
                 });
            }

            // Geçersizse ilk hatalı alana odaklanmaya çalış
            if (!isValid) {
                // Hatalı input veya legend'ı bul
                const firstError = group.querySelector('.validation-error, legend.validation-error-text');
                 if (firstError) {
                    // Elementin görünürlüğünü kontrol et ve odaklan
                    const rect = firstError.getBoundingClientRect();
                    if (rect.top < 0 || rect.bottom > window.innerHeight || rect.left < 0 || rect.right > window.innerWidth) {
                        // Eğer ekran dışında ise, görünür alana kaydır
                         firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                     // Odaklanma işlemi
                     try {
                        if (firstError.tagName === 'INPUT') {
                            firstError.focus();
                        } else if (firstError.tagName === 'LEGEND') {
                            // Legend'a doğrudan odaklanmak yerine fieldset içindeki ilk butona odaklan
                            const fieldset = firstError.closest('fieldset');
                            const firstButton = fieldset?.querySelector('button.question-button');
                            if (firstButton) firstButton.focus();
                        } else {
                             firstError.focus(); // Diğer durumlar için doğrudan odaklan
                        }
                     } catch (e) {
                         console.warn("Focusing error:", e); // Odaklanma hatasını yakala
                     }
                }
            }
            return isValid; // Doğrulama sonucunu döndür
        }

        // Belirtilen index'teki sayfayı gösterir
        function showPage(pageIndexToShow) {
            // Geçerli index aralığında mı kontrol et
            if (pageIndexToShow < 0 || pageIndexToShow >= totalPages) {
                console.error("Invalid page index requested:", pageIndexToShow);
                return;
            }

            // Önce tüm sayfaları gizle
            questionGroups.forEach(group => {
                group.classList.remove('active');
            });

            // İstenen sayfayı göster
            const targetGroup = questionGroups[pageIndexToShow];
            if (targetGroup) {
                targetGroup.classList.add('active');
                currentPageIndex = pageIndexToShow; // Şu anki sayfa index'ini güncelle

                 // Eğer gösterilen sayfa soru sayfasıysa (index > 0),
                 // kilitli bilgileri (isim/sınıf) güncelle
                 if(currentPageIndex > 0) {
                      const actualPageIndex = parseInt(targetGroup.dataset.page || '0', 10); // dataset.page 1'den başlar
                      const lockedNameSpan = document.getElementById(`lockedName_${actualPageIndex}`);
                      const lockedClassSpan = document.getElementById(`lockedClass_${actualPageIndex}`);
                      // Değerleri inputlardan al (her zaman güncel)
                      const currentName = nameInput ? (nameInput.value.trim() || '-') : '-';
                      const currentClass = classInput ? (classInput.value.trim() || '-') : '-';
                      if (lockedNameSpan) lockedNameSpan.textContent = currentName;
                      if (lockedClassSpan) lockedClassSpan.textContent = currentClass;
                  }

                 // Sayfa değiştiğinde sayfanın başına odaklan veya kaydır
                  const firstFocusable = targetGroup.querySelector('h3, input, button'); // Sayfadaki ilk odaklanılabilir öğe
                 if (firstFocusable) {
                     try {
                         // Önce scrollIntoView ile görünür yap, sonra odaklan
                          firstFocusable.scrollIntoView({ behavior: 'auto', block: 'nearest' }); // 'smooth' yerine 'auto' daha hızlı olabilir
                          // Kısa bir gecikme sonrası odaklanma daha iyi çalışabilir
                           setTimeout(() => { try { firstFocusable.focus(); } catch(ef) {} }, 50); // 50ms gecikme
                          // Veya doğrudan odaklanmayı dene
                          // firstFocusable.focus({ preventScroll: true }); // Kaydırmayı engelle, zaten scrollIntoView yaptık
                     } catch(e) { console.warn("Focusing error on page change:", e); }
                 } else {
                     // Odaklanacak öğe yoksa sayfanın başına git
                      window.scrollTo({ top: 0, behavior: 'smooth' });
                 }

            } else {
                 console.error("Target page group not found for index:", pageIndexToShow);
            }
        }

        // Sonraki sayfayı gösterir
        function showNextPage() {
            // Önce mevcut sayfayı doğrula
            if (!validatePage(currentPageIndex)) {
                return; // Geçerli değilse ilerleme
            }
            // Eğer son sayfada değilsek, bir sonraki sayfayı göster
            if (currentPageIndex < totalPages - 1) {
                showPage(currentPageIndex + 1);
            }
            // Son sayfadaysa bu buton zaten görünmez, submit butonu görünür.
        }

        // Geri butonu kaldırıldığı için showPrevPage fonksiyonu yok.

         // Form gönderilmeden önce son bir genel kontrol (özellikle son sayfa için)
         if (form) {
             form.addEventListener('submit', function(event) {
                 // Son sayfadaki validasyonu tekrar yap (garanti olsun)
                 if (!validatePage(currentPageIndex)) {
                    console.warn("Form submit blocked by validation on the current page.");
                    event.preventDefault(); // Formun gönderilmesini engelle
                    alert("Lütfen formu göndermeden önce bu sayfadaki tüm zorunlu alanları doldurun.");
                 }
                  // Eğer validasyon başarılıysa, butonun tekrar tıklanmasını engellemek için submit butonunu disable yapabiliriz.
                 else {
                     const submitButton = form.querySelector('button[type="submit"]');
                     if(submitButton) {
                         submitButton.disabled = true;
                         submitButton.textContent = 'Gönderiliyor...'; // Kullanıcıya geri bildirim
                     }
                 }
             });
         } else {
             console.error("Form element not found!");
         }
    </script>
    <?php endif; ?>

</body>
</html>