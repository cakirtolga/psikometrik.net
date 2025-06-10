<?php
// take-survey-29.php (Öğrenci Rehberlik İhtiyacı Belirleme Anketi - RİBA Ortaokul Öğrenci)

// --- Hata Raporlama ---
ini_set('display_errors', 1);
error_reporting(E_ALL);
// ---------------------------

session_start(); // Session GEREKLİ

// --- Veritabanı Bağlantısı ---
// src/config.php dosyasının yolu projenizin yapısına göre değişebilir
require __DIR__ . '/src/config.php'; // view-result-28.php'deki gibi yol düzenlemesi yapıldı
if (!isset($pdo) || !$pdo instanceof PDO) {
    error_log("CRITICAL: PDO connection object (\$pdo) not created or found in src/config.php");
    header('Content-Type: text/html; charset=utf-8');
    die('<div style="border: 1px solid red; padding: 10px; margin: 10px; background-color: #ffebeb; color: red; font-family: sans-serif;"><b>Kritik Hata:</b> Veritabanı bağlantısı kurulamadı (src/config.php).</div>');
}
// --------------------------------

// --- Konfigürasyon ---
$surveyId = 29; // Anket ID'si - Anket 29 için güncellendi
$testTitleDefault = "Öğrenci Rehberlik İhtiyacı Belirleme Anketi (RİBA) (Ortaokul-Öğrenci Formu)"; // Yeni anket başlığı
// ---------------------

// --- Değişken Başlatma ---
$adminId = null;
$error = null;
$post_error = null;
$adminCheckError = null;
$survey = null;
$questions = [];
// POST geldiğinde veya sayfa yüklendiğinde form verisini al
$form_data = ($_SERVER["REQUEST_METHOD"] == "POST") ? $_POST : [];
// -----------------------------

// --- Sabit Veriler (Anket 29 - RİBA Ortaokul Öğrenci) ---
// Cevap Seçenekleri - Bunlar doğrudan kaydedilecek metinlerdir.
// Anket 29 PDF'ine göre güncellendi: "Hayır", "Kararsızım", "Evet"
$optionsList = ["Hayır", "Kararsızım", "Evet"];
// Bu anketin puanlama mantığı (Hayır:1, Kararsızım:2, Evet:3) view-result dosyası tarafından işlenecektir.

// --- Yönetici ID Kontrolü ---
// URL'de admin_id varsa, bunun geçerli bir admin olup olmadığını kontrol et.
// Bu anket öğrenci formu olsa da, yöneticinin öğrenciler adına doldurması senaryosu olabilir.
// Orijinal take-survey-28.php'deki mantık korundu.
if (isset($_GET['admin_id']) && !empty($_GET['admin_id'])) {
    $potentialAdminId = filter_var($_GET['admin_id'], FILTER_VALIDATE_INT);
    if ($potentialAdminId === false) {
        $adminCheckError = 'URL\'de geçersiz yönetici ID formatı.';
    } else {
        try {
            // Kullanıcının admin veya super-admin rolünde olduğunu kontrol et
            $adminStmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND (role = 'admin' OR role = 'super-admin')");
            $adminStmt->execute([$potentialAdminId]);
            $admin = $adminStmt->fetch(PDO::FETCH_ASSOC);
            if ($admin) {
                $adminId = $admin['id'];
            } else {
                $adminCheckError = "Belirtilen yönetici ID ({$potentialAdminId}) sistemde bulunamadı veya yetkili değil.";
            }
        } catch (Exception $e) {
            $error = 'Yönetici bilgileri kontrol edilirken bir veritabanı hatası oluştu.';
            error_log("Admin ID Check Error (S{$surveyId}): " . $e->getMessage());
        }
    }
}
// --- Yönetici ID Kontrolü Sonu ---


// --- POST İŞLEME ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === null) {

    // 1. POST Verisini Al
    $surveyId_post = filter_input(INPUT_POST, 'survey_id', FILTER_VALIDATE_INT);
    // Öğrenci anketine özel alanlar (Ad Soyad, Sınıf ve Cinsiyet eklendi)
    $studentName = trim(htmlspecialchars($_POST['student_name'] ?? '', ENT_QUOTES, 'UTF-8')); // Ad Soyad alanı (Zorunlu yapıldı)
    $studentClass = trim(htmlspecialchars($_POST['student_class'] ?? '', ENT_QUOTES, 'UTF-8')); // Sınıf alanı (Zorunlu)
    $studentGender = isset($_POST['student_gender']) ? trim(htmlspecialchars($_POST['student_gender'], ENT_QUOTES, 'UTF-8')) : null; // Cinsiyet alanı (Zorunlu)
    $otherNeeds = trim(htmlspecialchars($_POST['other_needs'] ?? '', ENT_QUOTES, 'UTF-8')); // Diğer ihtiyaçlar alanı

    // Cevaplar [question_db_id => answer_text (e.g., "Hayır")] şeklinde geliyor
    $answers = $_POST['answers'] ?? [];

    // 2. Doğrulama için Soru Bilgilerini Çek
    // Sadece formdan gelen soru ID'lerinin geçerli olduğunu ve sort_order'larını çekmek yeterli
    $questionIdToSortOrderMap = []; // ID -> sort_order haritası
    $totalExpectedQuestionsFetched = 0; // Toplam soru sayısı kontrolü için

    try {
        // POST edilen soru ID'lerinin geçerliliğini ve sort_order'larını çekmek için
        $questionIdsFromPost = array_keys($answers);
        if (!empty($questionIdsFromPost)) {
             // Sadece sayısal ID'leri filtrele
             $safeQuestionIds = array_filter($questionIdsFromPost, 'is_numeric');
             if (!empty($safeQuestionIds)) {
                 $placeholders = implode(',', array_fill(0, count($safeQuestionIds), '?'));
                 // question_text'i de çekelim (tablo gösterimi için gerekebilir ama burada sadece sort_order lazım)
                 $stmt_q_check = $pdo->prepare("SELECT id, question AS question_text, sort_order FROM survey_questions WHERE survey_id = ? AND id IN ({$placeholders})");
                 $executeParams = array_merge([$surveyId], $safeQuestionIds);
                 $stmt_q_check->execute($executeParams);
                 $dbQuestionsInfo = $stmt_q_check->fetchAll(PDO::FETCH_ASSOC);

                 // ID -> sort_order haritasını oluştur
                 foreach($dbQuestionsInfo as $q) {
                     $questionIdToSortOrderMap[$q['id']] = $q['sort_order'];
                 }
             }
        }

        // Toplam soru sayısını çek (doğrulama için)
        $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM survey_questions WHERE survey_id = ?");
        $stmt_count->execute([$surveyId]);
        $totalExpectedQuestionsFetched = (int)$stmt_count->fetchColumn();

    } catch (PDOException $e) {
         error_log("Soru bilgileri alınamadı (S{$surveyId}) POST: " . $e->getMessage());
         $post_error = "Soru bilgileri yüklenirken bir veritabanı hatası oluştu.";
    }


    if ($post_error === null) { // Veritabanı hatası yoksa devam et
        if ($surveyId_post !== $surveyId) {
            $post_error = "Geçersiz anket bilgisi.";
        } elseif (empty($studentName)) { // Ad Soyad kontrolü (Zorunlu yapıldı)
             $post_error = "Ad Soyad alanı boş bırakılamaz.";
        } elseif (empty($studentClass)) { // Sınıf kontrolü (Zorunlu yapıldı)
            $post_error = "Sınıf alanı boş bırakılamaz.";
        } elseif (empty($studentGender)) { // Cinsiyet kontrolü (Zorunlu yapıldı)
             $post_error = "Cinsiyet alanı boş bırakılamaz.";
        } elseif (empty($answers) || !is_array($answers)) {
            $post_error = "Cevaplar alınamadı.";
        } elseif (count($answers) < $totalExpectedQuestionsFetched) { // Çekilen soru sayısıyla karşılaştır
             $post_error = "Lütfen tüm soruları cevaplayın. Eksik soru sayısı: " . ($totalExpectedQuestionsFetched - count($answers));
        } else {
            // Cevapların geçerliliğini (optionsList'in değerlerinden biri mi?) ve gönderilen ID'lerin DB'de varlığını kontrol et
            $answeredCount = 0;
            $invalidDataFound = false;
            foreach ($answers as $qDbId => $answerValue) {
                $qIdCheck = filter_var($qDbId, FILTER_VALIDATE_INT);
                // Cevap metni optionsList'in değerlerinden biri mi?
                $isValidAnswerValue = in_array($answerValue, $optionsList);
                // Gönderilen soru ID'si (qDbId) çekilen questionIdToSortOrderMap içinde var mı?
                $isValidQuestionId = ($qIdCheck !== false && isset($questionIdToSortOrderMap[$qIdCheck]));

                if ($isValidQuestionId && $isValidAnswerValue) {
                    $answeredCount++;
                } else {
                    // Hatalı veya eksik veri tespit edildi
                    $invalidDataFound = true;
                    error_log("Invalid answer value ('{$answerValue}') or question ID ({$qDbId}) for survey {$surveyId} during POST validation.");
                    break; // Hata bulunduğunda döngüyü sonlandır
                }
            }

            // Cevaplanan soru sayısı toplam soru sayısına eşit mi?
            if ($answeredCount < $totalExpectedQuestionsFetched || $invalidDataFound) {
                 $post_error = "Lütfen tüm soruları geçerli seçeneklerle cevaplayın.";
                 error_log("Validation failed for survey {$surveyId} POST. Answered count: {$answeredCount}, Expected: {$totalExpectedQuestionsFetched}, Invalid data found: " . ($invalidDataFound ? 'Yes' : 'No'));
            }
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
                // Katılımcı ekle (Öğrenci anketine özel alanlar dahil)
                // survey_participants tablosunda 'name' öğrenci adı soyadı, 'class' sınıf bilgisi
                // 'description' sütununa diğer ihtiyaçlar alanını kaydedeceğiz.
                $studentInfoForName = !empty($studentName) ? $studentName : "Belirtilmemiş"; // Ad Soyad (name sütununa)

                // INSERT ifadesine 'description' sütununu ekle
                $stmtParticipant = $pdo->prepare("INSERT INTO survey_participants (name, class, survey_id, admin_id, description, created_at) VALUES (:name, :class, :survey_id, :admin_id, :description, NOW())");
                $stmtParticipant->bindParam(':name', $studentInfoForName, PDO::PARAM_STR); // Ad Soyad (name sütununa)
                $stmtParticipant->bindParam(':class', $studentClass, PDO::PARAM_STR); // Sınıf bilgisini class sütununa kaydet
                $stmtParticipant->bindParam(':survey_id', $surveyId, PDO::PARAM_INT);
                $stmtParticipant->bindParam(':admin_id', $adminId, PDO::PARAM_INT);
                // *** diğer_needs değişkenini description sütununa bağla ***
                $stmtParticipant->bindParam(':description', $otherNeeds, PDO::PARAM_STR);


                 if (!$stmtParticipant->execute()) {
                    throw new PDOException("Katılımcı kaydı başarısız: " . implode(";", $stmtParticipant->errorInfo()));
                }
                $participant_id = $pdo->lastInsertId();
                if (!$participant_id) {
                    throw new Exception("Katılımcı ID alınamadı.");
                }

                // Cevapları Ekle (question_id sütununa sort_order, answer_text sütununa cevap metni)
                // take-survey-28.php'deki gibi question_id sütununa sort_order kaydediliyor.
                $stmtAnswer = $pdo->prepare(
                    "INSERT INTO survey_answers (participant_id, survey_id, question_id, answer_text, created_at)
                     VALUES (:pid, :sid, :qid_sort_order, :answer_text, NOW())" // qid_sort_order ve answer_text placeholder'ları
                );
                foreach ($answers as $actual_db_id => $answer_text) { // $answer_text artık "Hayır", "Kararsızım", "Evet"
                    $qId_int = (int)$actual_db_id;
                    $answer_text_str = trim($answer_text);

                    // *** Orijinal DB ID'ye karşılık gelen sort_order'ı al ***
                    $sort_order_to_save = $questionIdToSortOrderMap[$qId_int] ?? null;

                    if ($sort_order_to_save !== null) {
                        $stmtAnswer->bindParam(':pid', $participant_id, PDO::PARAM_INT);
                        $stmtAnswer->bindParam(':sid', $surveyId, PDO::PARAM_INT);
                        // *** question_id sütununa sort_order değeri bağlanıyor ***
                        $stmtAnswer->bindParam(':qid_sort_order', $sort_order_to_save, PDO::PARAM_INT);
                        // *** answer_text sütununa cevap metni bağlanıyor ***
                        $stmtAnswer->bindParam(':answer_text', $answer_text_str, PDO::PARAM_STR);

                        if (!$stmtAnswer->execute()) {
                            error_log("Cevap kaydı başarısız (OriginalQID:" . $qId_int . ", SavingSortOrder:" . $sort_order_to_save . ", AnswerText:" . $answer_text_str . "): " . implode(";", $stmtAnswer->errorInfo()));
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
                // Başarılı kayıt sonrası tamamlandi.php'ye yönlendir
                header('Location: tamamlandi.php?pid=' . $participant_id);
                exit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                // Log mesajı öğrenci anketine göre güncellendi
                error_log("Admin Survey Submit Exception (S{$surveyId}, Admin{$adminId}, StudentName:{$studentName}, Class:{$studentClass}, Gender:{$studentGender}, OtherNeeds:{$otherNeeds}): " . $e->getMessage());
                $post_error = "<b>Kayıt Sırasında Bir Hata Oluştu!</b> Lütfen tekrar deneyin veya yöneticiyle iletişime geçin. (Detaylar loglandı)";
            }

        } else {
            // **** SENARYO 2: ADMIN YOK -> KAYDETME, SONUÇ VERİSİNİ SESSION'A AT, view-result-29.php'ye YÖNLENDİR ****
            // Skor hesaplaması bu anket için geçerli değil, sadece cevapları ve katılımcı bilgisini sakla
            if ($post_error === null && !empty($questionIdToSortOrderMap)) { // questionIdToSortOrderMap POST doğrulamada çekildi
                $sessionAnswersData = [];

                foreach ($answers as $qDbId => $answerValue) { // $answerValue artık "Hayır", "Kararsızım", "Evet"
                    $questionId = (int)$qDbId; // Orijinal DB ID
                    $answerText = trim($answerValue); // Cevap metni

                    $sortOrder = $questionIdToSortOrderMap[$questionId] ?? null; // sort_order'ı bul

                    if ($sortOrder !== null) {
                        // Session'a orijinal soru ID'si yerine sort_order ve cevap metnini kaydedelim
                        // view-result-29.php sort_order üzerinden çalışıyor gibi görünüyor.
                        $sessionAnswersData[$sortOrder] = $answerText;
                    } else {
                        error_log("Cannot find sort_order for QID: {$questionId} in survey {$surveyId} during Session handling");
                    }
                }

                // Session'a katılımcı bilgisini (varsa) ve cevapları kaydediyoruz.
                // Öğrenci anketine özel bilgiler session'a eklendi.
                $_SESSION['survey_result_data'] = [
                    'survey_id' => $surveyId,
                    'answers' => $sessionAnswersData, // [sort_order => answer_text] olarak kaydediliyor
                    'student_name' => $studentName, // Öğrenci adı (İsteğe bağlı)
                    'student_class' => $studentClass, // Öğrenci sınıfı
                    'student_gender' => $studentGender, // Öğrenci cinsiyeti
                    'other_needs' => $otherNeeds, // Diğer ihtiyaçlar
                    'timestamp' => time()
                ];
                session_write_close(); // Session yazmayı bitir
                // Yönlendirme yolu admin dizinini içerecek şekilde güncellendi (varsayılan olarak admin paneli altında sonuç gösterimi)
                // view-result-29.php'ye yönlendir
                header('Location: ../admin/view-result-29.php');
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
        $stmtSurvey = $pdo->prepare("SELECT title FROM surveys WHERE id = ?");
        $stmtSurvey->execute([$surveyId]);
        $survey = $stmtSurvey->fetch(PDO::FETCH_ASSOC);
        // 'question' sütununu kullan
        $stmtQuestions = $pdo->prepare("SELECT id, question AS question_text, sort_order FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC, id ASC");
        $stmtQuestions->execute([$surveyId]);
        $questions = $stmtQuestions->fetchAll(PDO::FETCH_ASSOC);
        if (empty($questions)) {
            if ($post_error === null) {
                $error = "Bu anket ({$surveyId}) için veritabanında soru bulunamadı.";
            }
            $questions = [];
        }
    } catch (Exception $e) {
        if ($post_error === null) {
            $error = "Anket verileri yüklenirken hata oluştu.";
        }
        error_log("Data Fetch Error (S{$surveyId}): " . $e->getMessage());
        $questions = [];
    }
}
// --- Veri Çekme Sonu ---

// Sayfa başlığı vs.
$pageTitle = isset($survey['title']) ? htmlspecialchars($survey['title']) : $testTitleDefault;
$actionUrl = "take-survey-{$surveyId}.php"; // Dinamik olarak surveyId kullan
if ($adminId !== null) {
    $actionUrl .= "?admin_id=" . htmlspecialchars($adminId);
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
    <title><?= $pageTitle ?> <?= $adminId ? '- Yönetici (' . htmlspecialchars($adminId) . ')' : '- Ücretsiz Anket' ?></title>
    <style>
        /* --- Stil Bloğu (Yeşil Tema - Genel) --- */
        body { font-family: sans-serif; line-height: 1.6; background-color: #f0fdf4; color: #2c3e50; margin: 0; padding: 20px; }
        .container { max-width: 800px; margin: 40px auto; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); text-align: left; background-color: #ffffff; }
        h2 { text-align: center; font-size: 1.5rem; font-weight: 600; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 2px solid #dcfce7; color: #1f2937; }
        .instructions { background-color: #e8f5e9; padding: 15px; border-radius: 5px; margin-bottom: 25px; font-size: 0.95em; border: 1px solid #c8e6c9; }
        .instructions p { margin: 0.5rem 0; }

        .question-block { margin-bottom: 15px; padding: 10px 0; border-bottom: 1px solid #eee; }
        .question-block:last-child { border-bottom: none; }
        .question-text { display: block; font-weight: 500; margin-bottom: 10px; font-size: 0.95em; color: #1f2937; }
        .question-text.validation-error { color: #dc2626 !important; font-weight: bold; }
        .options-group-styled { display: flex; flex-wrap: wrap; gap: 8px; justify-content: flex-start; padding-top: 5px;}
        input[type="radio"].hidden-radio { position: absolute; opacity: 0; width: 0; height: 0; pointer-events: none; }
         .option-label-button { cursor: pointer; padding: 8px 12px; border: 1px solid #ced4da; border-radius: 5px; background-color: #fff; transition: background-color 0.2s, border-color 0.2s, color 0.2s; text-align: center; font-size: 0.85em; line-height: 1.4; display: inline-block; user-select: none; min-width: 80px; } /* Genişlik ayarlandı */
        input[type="radio"].hidden-radio:checked + label.option-label-button { background-color: #15803d; color: white; border-color: #0b532c; font-weight: bold; } /* Renkler yeşile uyarlandı */
        .option-label-button:hover { background-color: #dcfce7; border-color: #a7f3d0;}
        input[type="radio"].hidden-radio:checked + label.option-label-button:hover { background-color: #16a34a; }

        .nav-button { padding: 12px 30px; border-radius: 8px; font-weight: 600; transition: all 0.2s ease-in-out; cursor: pointer; border: none; color: white; display: block; width: 100%; margin-top: 2rem;}
        .nav-button.submit { background: #15803d; } .nav-button.submit:hover { background: #0b532c; }
        .nav-button:disabled { background-color: #9ca3af; cursor: not-allowed; opacity: 0.7;}
        .info { margin-bottom: 1.5rem; padding-bottom: 1.5rem; border-bottom: 1px solid #f3f4f6;}
        .info label { display: block; margin-bottom: 5px; font-weight: 600; color: #374151; }
        .info input, .info select, .info textarea { padding: 8px 10px; width: 100%; box-sizing: border-box; border: 1px solid #ccc; border-radius: 6px; font-size: 1em; color: #2c3e50; background-color: white;}
         .info input, .info select { height: 40px; } /* Input ve select için yükseklik ayarı */
        .info input:focus, .info select:focus, .info textarea:focus { border-color: #15803d; box-shadow: 0 0 0 2px rgba(34, 197, 94, 0.3); outline: none; }
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

        <?php if ($error): ?>
            <div class="error-box" role="alert"><b>Hata:</b> <?= htmlspecialchars($error) ?></div>
        <?php elseif ($post_error): ?>
            <div class="error-box" role="alert"><?= htmlspecialchars($post_error) ?></div>
        <?php elseif (isset($adminCheckError) && $adminCheckError): ?>
            <div class="error-box" role="alert" style="..."><b>Uyarı:</b> <?= htmlspecialchars($adminCheckError) ?>...</div>
        <?php endif; ?>


        <?php // Formu Göster ?>
        <?php if (!$error && !empty($questions)): ?>

            <?php // Bilgilendirme kutusu ?>
            <?php if ($adminId !== null): ?>
                <div class="admin-info-box">Bu anket <strong>Yönetici ID: <?= htmlspecialchars($adminId) ?></strong> tarafından başlatıldı. Sonuçlar yönetici paneline kaydedilecektir.</div>
            <?php else: ?>
                <div class="public-info-box">Bu herkese açık ücretsiz bir ankettir. Sonuçlarınız size özel olarak görüntülenecektir.</div>
            <?php endif; ?>

            <div class="instructions">
                <p>Sevgili öğrenciler;</p>
                <p>Bu form rehberlik ihtiyaçlarınızı belirlemek amacıyla hazırlanmıştır. Anketi cevaplarken her maddenin karşısında yer alan, "Hayır", "Kararsızım", "Evet", ifadelerinden size en uygun seçeneğin altındaki parantezin içine (X) işareti koyunuz. Unutmayınız ki! Bu bir sınav değildir ve sonuçta sizlere derslerinizi etkileyecek herhangi bir not veya puan verilmeyecektir. Bu nedenle sizlerden sorulara içtenlikle cevap vermeniz beklenmektedir.</p>
                <p>Tüm maddeleri işaretlemeniz gerekmektedir.</p>
            </div>

            <form method="POST" id="surveyForm" action="<?= $actionUrl ?>" novalidate>
                <input type="hidden" name="survey_id" value="<?= $surveyId ?>">

                <?php // Öğrenci bilgileri (Ad Soyad, Sınıf ve Cinsiyet) ?>
                <div class="info">
                     <div class="info-grid">
                         <div>
                             <label for="student_name">Ad Soyadınız <span class="required-star">*</span></label> <?php // Zorunlu yapıldı ?>
                             <input type="text" id="student_name" name="student_name" required placeholder="Adınızı ve soyadınızı girin..." value="<?= htmlspecialchars($form_data['student_name'] ?? '') ?>" class="<?= ($post_error && empty($form_data['student_name'])) ? 'validation-error' : '' ?>" aria-describedby="studentNameError">
                             <p id="studentNameError" class="validation-error-text" aria-live="polite"></p>
                         </div>
                         <div>
                             <label for="student_class">Sınıfınız <span class="required-star">*</span></label>
                             <input type="text" id="student_class" name="student_class" required placeholder="Sınıfınızı girin..." value="<?= htmlspecialchars($form_data['student_class'] ?? '') ?>" class="<?= ($post_error && empty($form_data['student_class'])) ? 'validation-error' : '' ?>" aria-describedby="studentClassError">
                             <p id="studentClassError" class="validation-error-text" aria-live="polite"></p>
                         </div>
                         <div>
                             <label for="student_gender">Cinsiyetiniz <span class="required-star">*</span></label>
                             <select id="student_gender" name="student_gender" required class="<?= ($post_error && empty($form_data['student_gender'])) ? 'validation-error' : '' ?>" aria-describedby="studentGenderError">
                                 <option value="">Seçiniz</option>
                                 <option value="Kız" <?= (isset($form_data['student_gender']) && $form_data['student_gender'] === 'Kız') ? 'selected' : '' ?>>Kız</option>
                                 <option value="Erkek" <?= (isset($form_data['student_gender']) && $form_data['student_gender'] === 'Erkek') ? 'selected' : '' ?>>Erkek</option>
                             </select>
                              <p id="studentGenderError" class="validation-error-text" aria-live="polite"></p>
                         </div>
                      </div>
                </div>


                <?php foreach ($questions as $question):
                    $question_id = $question['id']; // Veritabanındaki gerçek soru ID'si
                    $sort_order = $question['sort_order']; // Sorunun sıra numarası (1-38)
                    $submitted_answer = $form_data['answers'][$question_id] ?? null; // Kullanıcının daha önce gönderdiği cevap
                 ?>
                    <div class="question-block" id="q_block_<?= $question_id ?>">
                        <span class="question-text <?= ($post_error && $submitted_answer === null) ? 'validation-error' : '' ?>" id="q_text_<?= $question_id ?>">
                           <?= htmlspecialchars($sort_order) ?>. <?= htmlspecialchars($question['question_text'] ?? '...') ?> <span class="required-star">*</span>
                        </span>
                        <div class="options-group-styled" role="radiogroup" aria-labelledby="q_text_<?= $question_id ?>">
                            <?php foreach ($optionsList as $optionLabel): // Seçenekleri listele ?>
                                <?php
                                $input_id = "q{$question_id}_opt" . md5($optionLabel); // Benzersiz ID oluştur
                                $is_checked = ($submitted_answer !== null && $submitted_answer === $optionLabel); // Cevap metni ile karşılaştır
                                ?>
                                <input type="radio"
                                       class="hidden-radio"
                                       id="<?= $input_id ?>"
                                       name="answers[<?= $question_id ?>]"
                                       value="<?= htmlspecialchars($optionLabel) // Cevap metnini gönder ?>"
                                       required <?= $is_checked ? 'checked' : '' ?>>
                                <label for="<?= $input_id ?>" class="option-label-button">
                                    <?= htmlspecialchars($optionLabel) ?>
                                </label>
                            <?php endforeach; ?>
                         </div>
                          <p id="qError_<?= $question_id ?>" class="validation-error-text" aria-live="polite"></p>
                    </div>
                 <?php endforeach; ?>

                <?php // Varsa, belirtmek istediğiniz diğer rehberlik ihtiyaçları alanı ?>
                <div class="info">
                    <label for="other_needs">Varsa, belirtmek istediğiniz diğer rehberlik ihtiyaçları:</label>
                    <textarea id="other_needs" name="other_needs" placeholder="Buraya yazabilirsiniz..."><?= htmlspecialchars($form_data['other_needs'] ?? '') ?></textarea>
                </div>


                <button type="submit" class="nav-button submit"> Anketi Tamamla ve Gönder </button>

            </form>

        <?php // Diğer hata/bilgi durumları ?>
        <?php elseif (!$error && !$post_error): ?>
             <div class="error-box" style="..."><strong>Bilgi:</strong> Anket yüklenemedi veya soru bulunamadı. Lütfen yöneticiyle iletişime geçin.</div>
        <?php endif; ?>
    </div>

    <?php // İstemci Taraflı Doğrulama ?>
     <?php if (!$error && !empty($questions)): ?>
    <script>
        // Bu JS kodu tüm soruların ve gerekli bilgilerin girilmesini kontrol eder.
        // Eksik varsa alert gösterir ve formu GÖNDERMEZ.
         const form = document.getElementById('surveyForm');
         if (form) {
             form.addEventListener('submit', function(event) {
                 let firstErrorElement = null;
                 let isValid = true;

                 // Ad Soyad kontrolü (Zorunlu yapıldı)
                 const nameInput = document.getElementById('student_name');
                 const nameErrorP = document.getElementById('studentNameError');
                 if (nameInput && nameErrorP) {
                     if (!nameInput.value.trim()) {
                         nameInput.classList.add('validation-error');
                         nameErrorP.textContent = 'Lütfen adınızı ve soyadınızı girin.';
                         isValid = false;
                         if (!firstErrorElement) firstErrorElement = nameInput;
                     } else {
                         nameInput.classList.remove('validation-error');
                         nameErrorP.textContent = '';
                     }
                 }


                 // Sınıf kontrolü (Zorunlu)
                 const classInput = document.getElementById('student_class');
                 const classErrorP = document.getElementById('studentClassError');
                 if (classInput && classErrorP) {
                     if (!classInput.value.trim()) {
                         classInput.classList.add('validation-error');
                         classErrorP.textContent = 'Lütfen sınıfınızı girin.';
                         isValid = false;
                         if (!firstErrorElement) firstErrorElement = classInput;
                     } else {
                         classInput.classList.remove('validation-error');
                         classErrorP.textContent = '';
                     }
                 }

                 // Cinsiyet kontrolü (Zorunlu)
                 const genderSelect = document.getElementById('student_gender');
                 const genderErrorP = document.getElementById('studentGenderError');
                 if (genderSelect && genderErrorP) {
                     if (!genderSelect.value) {
                         genderSelect.classList.add('validation-error');
                         genderErrorP.textContent = 'Lütfen cinsiyetinizi seçin.';
                         isValid = false;
                         if (!firstErrorElement) firstErrorElement = genderSelect;
                     } else {
                         genderSelect.classList.remove('validation-error');
                         genderErrorP.textContent = '';
                     }
                 }

                 // Tüm soruların cevaplandığını kontrol et
                 const questionsBlocks = form.querySelectorAll('.question-block');
                 questionsBlocks.forEach(qBlock => {
                     const qid = qBlock.id.replace('q_block_', '');
                     const qText = qBlock.querySelector('.question-text');
                     const errorP = document.getElementById(`qError_${qid}`);
                     const radios = qBlock.querySelectorAll('input[type="radio"].hidden-radio');
                     let isAnswered = false;
                     radios.forEach(radio => {
                         if (radio.checked) isAnswered = true;
                     });

                     if (!isAnswered) {
                         if(qText) qText.classList.add('validation-error');
                         if(errorP) errorP.textContent = 'Lütfen bu soru için bir seçim yapın.';
                         isValid = false;
                         if (!firstErrorElement && qText) firstErrorElement = qText;
                     } else {
                         if(qText) qText.classList.remove('validation-error');
                         if(errorP) errorP.textContent = '';
                     }
                 });

                 // Hata varsa formu gönderme ve odaklanma
                 if (!isValid) {
                     event.preventDefault(); // Formu göndermeyi engelle
                     alert('Lütfen tüm gerekli alanları doldurun.'); // Tüm hatalar için genel uyarı
                     if (firstErrorElement) {
                         firstErrorElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                         // İlk hata elementine odaklanmaya çalış
                         try {
                             const focusTarget = firstErrorElement.closest('.info input, .info select') || firstErrorElement.closest('.question-block')?.querySelector('label.option-label-button') || firstErrorElement;
                              if (focusTarget) focusTarget.focus();
                         } catch (e) {
                             console.error("Odaklanma hatası:", e);
                         }
                     }
                 } else {
                     // Form geçerliyse butonu devre dışı bırak
                     const submitButton = form.querySelector('button[type="submit"]');
                     if(submitButton) {
                         submitButton.disabled = true;
                         submitButton.textContent = 'Gönderiliyor...'; // Kullanıcıya geri bildirim
                     }
                 }
             });
         }
    </script>
     <?php endif; ?>

</body>
</html>
