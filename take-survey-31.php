<?php
// take-survey-31.php (Şiddet Sıklığı Anketi - Veli Formu)

// --- Hata Raporlama ---
ini_set('display_errors', 1);
error_reporting(E_ALL);
// ---------------------------

session_start(); // Session GEREKLİ

// --- Veritabanı Bağlantısı ---
// src/config.php dosyasının yolu projenizin yapısına göre değişebilir
// $_SERVER['DOCUMENT_ROOT'] kullanarak kök dizininden dahil etme deneniyor.
$configPath = $_SERVER['DOCUMENT_ROOT'] . '/src/config.php';
if (file_exists($configPath)) {
    require $configPath;
} else {
    error_log("CRITICAL: PDO connection object (\$pdo) not created or found. Config file not found at " . $configPath);
    header('Content-Type: text/html; charset=utf-8');
    die('<div style="border: 1px solid red; padding: 10px; margin: 10px; background-color: #ffebeb; color: red; font-family: sans-serif;"><b>Kritik Hata:</b> Veritabanı bağlantısı kurulamadı. Yapılandırma dosyası bulunamadı.</div>');
}

if (!isset($pdo) || !$pdo instanceof PDO) {
     error_log("CRITICAL: PDO connection object (\$pdo) not created or found in src/config.php after require.");
     header('Content-Type: text/html; charset=utf-8');
     die('<div style="border: 1px solid red; padding: 10px; margin: 10px; background-color: #ffebeb; color: red; font-family: sans-serif;"><b>Kritik Hata:</b> Veritabanı bağlantısı kurulamadı (PDO nesnesi eksik).</div>');
}
// --------------------------------

// --- Konfigürasyon ---
$surveyId = 31; // Anket ID'si - Anket 31 için güncellendi
$testTitleDefault = "Şiddet Sıklığı Anketi (Veli Formu)"; // Yeni anket başlığı
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

// --- Sabit Veriler (Anket 31 - Şiddet Sıklığı Anketi Veli) ---
// Cevap Seçenekleri - Bunlar doğrudan kaydedilecek metinlerdir.
// Anket 31 PDF'ine göre güncellendi: "Hiç Olmadı", "Ayda Birkaç Kez Oldu", "Hemen Hemen Her Gün Oldu"
$optionsList = ["Hiç Olmadı", "Ayda Birkaç Kez Oldu", "Hemen Hemen Her Gün Oldu"];
// Bu anket için PDF'te sayısal puanlama anahtarı belirtilmemiştir.
// Sonuç sayfasında sadece seçilen seçenekler listelenecektir.


// --- Yönetici ID Kontrolü ---
// URL'de admin_id varsa, bunun geçerli bir admin olup olmadığını kontrol et.
// Bu anket veli formu olsa da, yöneticinin veliler adına doldurması senaryosu olabilir.
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
                $adminCheckError = "Bel belirtilen yönetici ID ({$potentialAdminId}) sistemde bulunamadı veya yetkili değil.";
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
    // Veli anketine özel alanlar (Çocuğun Adı Soyadı, Sınıfı, Okul No eklendi)
    $childName = trim(htmlspecialchars($_POST['child_name'] ?? '', ENT_QUOTES, 'UTF-8')); // Çocuğun Adı Soyadı (Zorunlu yapıldı)
    $childClass = trim(htmlspecialchars($_POST['child_class'] ?? '', ENT_QUOTES, 'UTF-8')); // Çocuğun Sınıfı (Zorunlu)
    $childSchoolNumber = trim(htmlspecialchars($_POST['child_school_number'] ?? '', ENT_QUOTES, 'UTF-8')); // Çocuğun Okul No (Zorunlu)
    // Diğer ihtiyaçlar alanı bu ankette yok, description sütununa çocuk bilgilerini kaydedeceğiz.

    // Cevaplar [question_db_id => answer_text (e.g., "Hiç Olmadı")] şeklinde geliyor
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
        } elseif (empty($childName)) { // Çocuğun Adı Soyadı kontrolü (Zorunlu yapıldı)
             $post_error = "Çocuğun Adı Soyadı alanı boş bırakılamaz.";
        } elseif (empty($childClass)) { // Çocuğun Sınıfı kontrolü (Zorunlu yapıldı)
            $post_error = "Çocuğun Sınıfı alanı boş bırakılamaz.";
        } elseif (empty($childSchoolNumber)) { // Çocuğun Okul No kontrolü (Zorunlu yapıldı)
             $post_error = "Çocuğun Okul No alanı boş bırakılamaz.";
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
                // Katılımcı ekle (Veli anketine özel alanlar dahil)
                // survey_participants tablosunda 'name' veli adı soyadı (bu ankette yok), 'class' çocuğun sınıfı
                // 'description' sütununa çocuğun adı soyadı ve okul no kaydedeceğiz.
                $childInfoForClass = !empty($childClass) ? $childClass : "Belirtilmemiş"; // Çocuğun Sınıfı (class sütununa)

                // Çocuğun Adı Soyadı ve Okul No description sütununda birleştirerek kaydet
                $descriptionContent = "Çocuğun Adı Soyadı: " . (!empty($childName) ? $childName : "Belirtilmemiş") . "\n";
                $descriptionContent .= "Çocuğun Okul No: " . (!empty($childSchoolNumber) ? $childSchoolNumber : "Belirtilmemiş");


                // INSERT ifadesine 'description' sütununu ekle
                $stmtParticipant = $pdo->prepare("INSERT INTO survey_participants (name, class, survey_id, admin_id, description, created_at) VALUES (:name, :class, :survey_id, :admin_id, :description, NOW())");
                $stmtParticipant->bindValue(':name', 'Veli', PDO::PARAM_STR); // Veli anketi olduğu için sabit 'Veli' yazılabilir veya boş bırakılabilir
                $stmtParticipant->bindParam(':class', $childInfoForClass, PDO::PARAM_STR); // Çocuğun Sınıfı (class sütununa)
                $stmtParticipant->bindParam(':survey_id', $surveyId, PDO::PARAM_INT);
                $stmtParticipant->bindParam(':admin_id', $adminId, PDO::PARAM_INT);
                // *** description değişkenini description sütununa bağla ***
                $stmtParticipant->bindParam(':description', $descriptionContent, PDO::PARAM_STR);


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
                foreach ($answers as $actual_db_id => $answer_text) { // $answer_text artık seçenek metinlerinden biri
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
                // Log mesajı veli anketine göre güncellendi
                error_log("Admin Survey Submit Exception (S{$surveyId}, Admin{$adminId}, ChildName:{$childName}, ChildClass:{$childClass}, ChildSchoolNumber:{$childSchoolNumber}): " . $e->getMessage());
                $post_error = "<b>Kayıt Sırasında Bir Hata Oluştu!</b> Lütfen tekrar deneyin veya yöneticiyle iletişime geçin. (Detaylar loglandı)";
            }

        } else {
            // **** SENARYO 2: ADMIN YOK -> KAYDETME, SONUÇ VERİSİNİ SESSION'A AT, view-result-31.php'ye YÖNLENDİR ****
            // Skor hesaplaması bu anket için geçerli değil, sadece cevapları ve katılımcı bilgisini sakla
            if ($post_error === null && !empty($questionIdToSortOrderMap)) { // questionIdToSortOrderMap POST doğrulamada çekildi
                $sessionAnswersData = [];

                foreach ($answers as $qDbId => $answerValue) { // $answerValue artık seçenek metinlerinden biri
                    $questionId = (int)$qDbId; // Orijinal DB ID
                    $answerText = trim($answerValue); // Cevap metni

                    $sortOrder = $questionIdToSortOrderMap[$questionId] ?? null; // sort_order'ı bul

                    if ($sortOrder !== null) {
                        // Session'a orijinal soru ID'si yerine sort_order ve cevap metnini kaydedelim
                        // view-result-31.php sort_order üzerinden çalışıyor gibi görünüyor.
                        $sessionAnswersData[$sortOrder] = $answerText;
                    } else {
                        error_log("Cannot find sort_order for QID: {$qId_int} in survey {$surveyId} during Session handling");
                    }
                }

                // Session'a katılımcı bilgisini (varsa) ve cevapları kaydediyoruz.
                // Veli anketine özel bilgiler session'a eklendi.
                $_SESSION['survey_result_data'] = [
                    'survey_id' => $surveyId,
                    'answers' => $sessionAnswersData, // [sort_order => answer_text] olarak kaydediliyor
                    'child_name' => $childName, // Çocuğun Adı Soyadı
                    'child_class' => $childClass, // Çocuğun Sınıfı
                    'child_school_number' => $childSchoolNumber, // Çocuğun Okul No
                    'timestamp' => time()
                ];
                session_write_close(); // Session yazmayı bitir
                // Yönlendirme yolu admin dizinini içerecek şekilde güncellendi (varsayılan olarak admin paneli altında sonuç gösterimi)
                // view-result-31.php'ye yönlendir
                header('Location: ../admin/view-result-31.php');
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
    <meta name="description" content="<?= htmlspecialchars($pageTitle) ?> doldurma sayfası. Çocuğunuzun şiddet sıklığını belirleme anketi.">
    <meta name="keywords" content="şiddet sıklığı, veli anketi, çocuk, evde şiddet, okulda şiddet, siber şiddet, anket doldur">
    <meta name="robots" content="index, follow"> <link rel="canonical" href="https://www.yourwebsite.com/anket/take-survey-<?= $surveyId ?>.php"> <link rel="icon" href="/favicon.png" type="image/png">

    <style>
        /* --- Stil Bloğu (Genel) --- */
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
                <div class="admin-info-box">Bu anket <strong>Yönetici ID: <?= htmlspecialchars($adminId) ?></strong> tarafından başlatıldı.</div>
            <?php else: ?>
                <div class="public-info-box">Bu herkese açık ücretsiz bir ankettir. Sonuçlarınız size özel olarak görüntülenecektir.</div>
            <?php endif; ?>

            <div class="instructions">
                <p>Sevgili anne babalar,</p>
                <p>Bu bölümde bazı durumlar verilmiştir. Durumların karşısında yer alan "Hiç Olmadı". "Ayda Birkaç Kez Oldu", "Hemen Hemen Her Gün Oldu" ifadelerinden çocuğunuzun durumuna en uygun seçeneği işaretleyiniz. İşaretleme yaparken son 6 ayı göz önünde bulundurunuz.</p>
                 <p>Tüm maddeleri işaretlemeniz gerekmektedir.</p>
            </div>

            <form method="POST" id="surveyForm" action="<?= $actionUrl ?>" novalidate>
                <input type="hidden" name="survey_id" value="<?= $surveyId ?>">

                <?php // Çocuğun bilgileri (Adı Soyadı, Sınıfı, Okul No) ?>
                <div class="info">
                     <div class="info-grid">
                         <div>
                             <label for="child_name">Çocuğunuzun Adı Soyadı <span class="required-star">*</span></label> <?php // Zorunlu yapıldı ?>
                             <input type="text" id="child_name" name="child_name" required placeholder="Çocuğunuzun adını ve soyadını girin..." value="<?= htmlspecialchars($form_data['child_name'] ?? '') ?>" class="<?= ($post_error && empty($form_data['child_name'])) ? 'validation-error' : '' ?>" aria-describedby="childNameError">
                             <p id="childNameError" class="validation-error-text" aria-live="polite"></p>
                         </div>
                         <div>
                             <label for="child_class">Çocuğunuzun Sınıfı <span class="required-star">*</span></label>
                             <input type="text" id="child_class" name="child_class" required placeholder="Çocuğunuzun sınıfını girin..." value="<?= htmlspecialchars($form_data['child_class'] ?? '') ?>" class="<?= ($post_error && empty($form_data['child_class'])) ? 'validation-error' : '' ?>" aria-describedby="childClassError">
                             <p id="childClassError" class="validation-error-text" aria-live="polite"></p>
                         </div>
                         <div>
                             <label for="child_school_number">Çocuğunuzun Okul No <span class="required-star">*</span></label>
                             <input type="text" id="child_school_number" name="child_school_number" required placeholder="Çocuğunuzun okul numarasını girin..." value="<?= htmlspecialchars($form_data['child_school_number'] ?? '') ?>" class="<?= ($post_error && empty($form_data['child_school_number'])) ? 'validation-error' : '' ?>" aria-describedby="childSchoolNumberError">
                             <p id="childSchoolNumberError" class="validation-error-text" aria-live="polite"></p>
                         </div>
                      </div>
                </div>


                <?php foreach ($questions as $question):
                    $question_id = $question['id']; // Veritabanındaki gerçek soru ID'si
                    $sort_order = $question['sort_order']; // Sorunun sıra numarası (1-40)
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

                 // Çocuğun Adı Soyadı kontrolü (Zorunlu yapıldı)
                 const childNameInput = document.getElementById('child_name');
                 const childNameErrorP = document.getElementById('childNameError');
                 if (childNameInput && childNameErrorP) {
                     if (!childNameInput.value.trim()) {
                         childNameInput.classList.add('validation-error');
                         childNameErrorP.textContent = 'Lütfen çocuğunuzun adını ve soyadını girin.';
                         isValid = false;
                         if (!firstErrorElement) firstErrorElement = childNameInput;
                     } else {
                         childNameInput.classList.remove('validation-error');
                         childNameErrorP.textContent = '';
                     }
                 }


                 // Çocuğun Sınıfı kontrolü (Zorunlu)
                 const childClassInput = document.getElementById('child_class');
                 const childClassErrorP = document.getElementById('childClassError');
                 if (childClassInput && childClassErrorP) {
                     if (!childClassInput.value.trim()) {
                         childClassInput.classList.add('validation-error');
                         childClassErrorP.textContent = 'Lütfen çocuğunuzun sınıfını girin.';
                         isValid = false;
                         if (!firstErrorElement) firstErrorElement = childClassInput;
                     } else {
                         childClassInput.classList.remove('validation-error');
                         childClassErrorP.textContent = '';
                     }
                 }

                 // Çocuğun Okul No kontrolü (Zorunlu)
                 const childSchoolNumberInput = document.getElementById('child_school_number');
                 const childSchoolNumberErrorP = document.getElementById('childSchoolNumberError');
                 if (childSchoolNumberInput && childSchoolNumberErrorP) {
                     if (!childSchoolNumberInput.value.trim()) {
                         childSchoolNumberInput.classList.add('validation-error');
                         childSchoolNumberErrorP.textContent = 'Lütfen çocuğunuzun okul numarasını girin.';
                         isValid = false;
                         if (!firstErrorElement) firstErrorElement = childSchoolNumberInput;
                     } else {
                         childSchoolNumberInput.classList.remove('validation-error');
                         childSchoolNumberErrorP.textContent = '';
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
