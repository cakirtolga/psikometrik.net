<?php
session_start();

// config.php dosyasını dahil et (veritabanı bağlantısı için)
require_once 'src/config.php'; // Lütfen bu yolun doğru olduğundan emin olun.

// Hata raporlamayı geliştirme aşamasında etkinleştirin
ini_set('display_errors', 1);
error_reporting(E_ALL);

$survey_id = 56;
$default_page_title = "Rotter İç-Dış Denetim Odağı Ölçeği"; 

// admin_id'yi URL'den (GET) al (sayfa ilk yüklendiğinde)
$admin_id = null;
if (isset($_GET['admin_id'])) {
    $admin_id_from_get = filter_input(INPUT_GET, 'admin_id', FILTER_VALIDATE_INT);
    if ($admin_id_from_get !== false && $admin_id_from_get !== null && $admin_id_from_get > 0) {
        $admin_id = $admin_id_from_get;
    }
}

// POST isteği durumunda, formdan gelen admin_id_hidden'ı kontrol et
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['admin_id_hidden'])) {
    $admin_id_from_post = filter_input(INPUT_POST, 'admin_id_hidden', FILTER_VALIDATE_INT);
    if ($admin_id_from_post !== false && $admin_id_from_post !== null && $admin_id_from_post > 0) {
        $admin_id = $admin_id_from_post; 
    } elseif ($admin_id_from_post === 0 || $admin_id_from_post === false) { 
        if (!isset($_GET['admin_id'])) { 
            $admin_id = null;
        }
    }
}

$guest_session_key = 'guest_participant_id_survey_' . $survey_id;
if (!isset($_SESSION[$guest_session_key])) {
    $_SESSION[$guest_session_key] = uniqid('guest_rotter_', true);
}
$guest_participant_id = $_SESSION[$guest_session_key]; 

$errors = [];
$posted_answers = []; 
$posted_participant_name = '';
$posted_participant_class = '';
$unanswered_questions_numbers = [];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $posted_survey_id = filter_input(INPUT_POST, 'survey_id', FILTER_VALIDATE_INT);

    if ($admin_id !== null && $admin_id > 0) {
        $posted_participant_name = trim(filter_input(INPUT_POST, 'participant_name', FILTER_SANITIZE_STRING));
        $posted_participant_class = trim(filter_input(INPUT_POST, 'participant_class', FILTER_SANITIZE_STRING));

        if (empty($posted_participant_name)) {
            $errors[] = "Adı Soyadı boş bırakılamaz.";
        }
        if (empty($posted_participant_class)) {
            $errors[] = "Sınıfı/Grubu bilgisi boş bırakılamaz.";
        }
    }

    if (!$posted_survey_id || $posted_survey_id != $survey_id) {
        $errors[] = "Geçersiz anket ID'si.";
    } 
    
    if (empty($errors)) { 
        $stmt_q_info = $pdo->prepare("SELECT question_number FROM survey_questions WHERE survey_id = :survey_id ORDER BY sort_order ASC");
        $stmt_q_info->bindParam(':survey_id', $survey_id, PDO::PARAM_INT);
        
        if (!$stmt_q_info->execute()) {
            $errors[] = "Soru bilgileri alınırken bir veritabanı hatası oluştu.";
            error_log("PDO Error (get question_numbers Rotter): " . implode(";", $stmt_q_info->errorInfo()));
        } else {
            $question_numbers_from_db = $stmt_q_info->fetchAll(PDO::FETCH_COLUMN);
            $total_questions_in_db = count($question_numbers_from_db);

            if ($total_questions_in_db > 0) {
                $all_questions_answered = true;
                $current_answers_for_processing = []; 

                foreach ($question_numbers_from_db as $q_num) {
                    $answer_key = 'q_' . $q_num;
                    $posted_answers[$q_num] = $_POST[$answer_key] ?? null;
                    if (!isset($_POST[$answer_key]) || ($_POST[$answer_key] !== 'a' && $_POST[$answer_key] !== 'b')) {
                        $all_questions_answered = false;
                        $errors[] = $q_num . ". soruyu yanıtlamadınız (Lütfen a veya b seçeneğini işaretleyin).";
                        $unanswered_questions_numbers[] = $q_num; 
                    } else {
                        $current_answers_for_processing[$q_num] = $_POST[$answer_key];
                    }
                }
                
                if (!$all_questions_answered && empty($errors)) { // Eğer sadece soru yanıtlanmama hatası varsa
                     $errors[] = "Lütfen tüm soruları yanıtlayınız.";
                }


                if ($all_questions_answered && empty($errors)) { 
                    if ($admin_id !== null && $admin_id > 0) {
                        try {
                            $pdo->beginTransaction();
                            $stmt_participant = $pdo->prepare(
                                "INSERT INTO survey_participants (name, class, survey_id, admin_id, created_at) 
                                 VALUES (:name, :class, :survey_id, :admin_id, NOW())"
                            );
                            $stmt_participant->bindParam(':name', $posted_participant_name, PDO::PARAM_STR);
                            $stmt_participant->bindParam(':class', $posted_participant_class, PDO::PARAM_STR);
                            $stmt_participant->bindParam(':survey_id', $survey_id, PDO::PARAM_INT);
                            $stmt_participant->bindParam(':admin_id', $admin_id, PDO::PARAM_INT);
                            if (!$stmt_participant->execute()) {
                                throw new PDOException("Katılımcı bilgileri kaydedilemedi: " . implode(";", $stmt_participant->errorInfo()));
                            }
                            $db_participant_id = $pdo->lastInsertId(); 

                            $stmt_insert_answer = $pdo->prepare(
                                "INSERT INTO survey_answers (survey_id, question_id, answer_text, admin_id, participant_id, created_at) 
                                 VALUES (:survey_id, :question_id, :answer_text, :admin_id, :participant_id, NOW())"
                            );
                            foreach ($current_answers_for_processing as $question_number => $answer_text) {
                                $q_id_to_save = (int)$question_number;
                                $stmt_insert_answer->bindParam(':survey_id', $survey_id, PDO::PARAM_INT);
                                $stmt_insert_answer->bindParam(':question_id', $q_id_to_save, PDO::PARAM_INT);
                                $stmt_insert_answer->bindParam(':answer_text', $answer_text, PDO::PARAM_STR); // 'a' veya 'b' olarak kaydedilecek
                                $stmt_insert_answer->bindParam(':admin_id', $admin_id, PDO::PARAM_INT); 
                                $stmt_insert_answer->bindParam(':participant_id', $db_participant_id, PDO::PARAM_INT); 
                                if (!$stmt_insert_answer->execute()) {
                                    throw new PDOException("Cevap kaydedilirken hata oluştu (Soru: {$question_number}): " . implode(";", $stmt_insert_answer->errorInfo()));
                                }
                            }
                            $pdo->commit();
                            unset($_SESSION[$guest_session_key]); 

                            $_SESSION['survey_message'] = "Anketiniz başarıyla gönderildi. Katılımınız için teşekkür ederiz.";
                            $_SESSION['survey_message_type'] = "success"; 
                            
                            $redirect_url = "tamamlandi.php?survey_id=" . $survey_id;
                            $redirect_url .= "&admin_id=" . urlencode($admin_id);
                            $redirect_url .= "&participant_db_id=" . urlencode($db_participant_id); 
                            
                            header("Location: " . $redirect_url);
                            exit();
                        } catch (PDOException $e) {
                            if ($pdo->inTransaction()) $pdo->rollBack();
                            $errors[] = "Veritabanı hatası: Anket cevapları kaydedilemedi. Lütfen daha sonra tekrar deneyin.";
                            error_log("take-survey-{$survey_id}.php PDOException (admin_id: {$admin_id}): " . $e->getMessage());
                        } catch (Exception $e) { 
                            if ($pdo->inTransaction()) $pdo->rollBack();
                            $errors[] = "Bir hata oluştu: " . $e->getMessage();
                            error_log("take-survey-{$survey_id}.php Exception (admin_id: {$admin_id}): " . $e->getMessage());
                        }
                    } else {
                        $preview_unique_id = $guest_participant_id; 
                        $_SESSION['temp_survey_answers_' . $survey_id . '_' . $preview_unique_id] = $current_answers_for_processing;
                        $_SESSION['survey_message'] = "Sonuçlarınız aşağıdadır. Bu bir önizlemedir ve cevaplarınız kaydedilmemiştir."; // Misafir için farklı mesaj
                        $_SESSION['survey_message_type'] = "info"; // Bilgi mesajı
                        
                        $redirect_url = "admin/view-result-56.php?id=" . urlencode($preview_unique_id); 
                        $redirect_url .= "&status=preview_no_save"; 
                        
                        header("Location: " . $redirect_url);
                        exit();
                    }
                } 
            } else {
                $errors[] = "Anket için soru bulunamadı.";
            }
        }
    }
}

$survey_info_title = $default_page_title; 
$survey_description_for_seo = "Rotter İç-Dış Denetim Odağı Ölçeği, bireylerin kontrol beklentilerini ölçer. Online olarak doldurun ve denetim odağınızı öğrenin.";
$page_keywords_for_seo = "rotter denetim odağı, iç dış kontrol, locus of control, psikolojik test, online anket, psikometrik.net";
$page_author_for_seo = "Psikometrik.net";
$current_page_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
$og_image_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]/assets/Psikometrik_og_rotter.png"; // Özel OG imajı


try {
    $stmt_survey_details = $pdo->prepare("SELECT title, description FROM surveys WHERE id = :survey_id");
    $stmt_survey_details->bindParam(':survey_id', $survey_id, PDO::PARAM_INT);
    if ($stmt_survey_details->execute()) {
        $survey_data = $stmt_survey_details->fetch(PDO::FETCH_ASSOC);
        if ($survey_data) {
            if (!empty($survey_data['title'])) {
                $survey_info_title = htmlspecialchars($survey_data['title']);
            }
            if (!empty($survey_data['description'])) {
                $survey_description_for_seo = htmlspecialchars(mb_substr(strip_tags($survey_data['description']), 0, 160)); 
            }
        }
    } 

    $stmt_questions = $pdo->prepare("SELECT question_number, question_text, sort_order, options FROM survey_questions WHERE survey_id = :survey_id ORDER BY sort_order ASC");
    $stmt_questions->bindParam(':survey_id', $survey_id, PDO::PARAM_INT);
    
    $questions_from_db = [];
    if ($stmt_questions->execute()) {
        $questions_from_db = $stmt_questions->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $errors[] = "Sorular veritabanından çekilirken bir hata oluştu.";
        error_log("PDO Error (get questions Rotter): " . implode(";", $stmt_questions->errorInfo()));
    }

    if (empty($questions_from_db) && empty($errors) && $_SERVER["REQUEST_METHOD"] !== "POST") { 
        $errors[] = "Bu anket için soru bulunamadı veya anket mevcut değil.";
    }

} catch (PDOException $e) {
    $errors[] = "Veritabanı bağlantı veya sorgu hatası: Sorular yüklenemedi.";
    error_log("take-survey-{$survey_id}.php (Initial Load) PDOException: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $survey_info_title; ?> - Psikometrik.net</title>
    
    <meta name="description" content="<?php echo htmlspecialchars(strip_tags($survey_description_for_seo)); ?>">
    <meta name="keywords" content="<?php echo htmlspecialchars($page_keywords_for_seo); ?>">
    <meta name="author" content="<?php echo htmlspecialchars($page_author_for_seo); ?>">
    <link rel="canonical" href="<?php echo htmlspecialchars($current_page_url); ?>" />

    <meta property="og:title" content="<?php echo $survey_info_title; ?> - Psikometrik.net" />
    <meta property="og:description" content="<?php echo htmlspecialchars(strip_tags($survey_description_for_seo)); ?>" />
    <meta property="og:type" content="website" />
    <meta property="og:url" content="<?php echo htmlspecialchars($current_page_url); ?>" />
    <meta property="og:image" content="<?php echo htmlspecialchars($og_image_url); ?>" />
    <meta property="og:image:alt" content="<?php echo $survey_info_title; ?> için görsel" />
    <meta property="og:site_name" content="Psikometrik.net" />
    <meta property_og:locale" content="tr_TR" />

    <meta name="twitter:card" content="summary_large_image"> 
    <meta name="twitter:title" content="<?php echo $survey_info_title; ?> - Psikometrik.net">
    <meta name="twitter:description" content="<?php echo htmlspecialchars(strip_tags($survey_description_for_seo)); ?>">
    <meta name="twitter:image" content="<?php echo htmlspecialchars($og_image_url); ?>">

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: #f0fdf4; /* Yeşil tema arka plan */
            color: #1f2937; 
            margin: 0;
            padding: 20px; 
        }
        .survey-container {
            max-width: 800px; 
            margin: 40px auto; 
            background-color: #ffffff;
            padding: 30px; 
            border-radius: 8px; 
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); 
            text-align: left; 
        }
        .logo-container { text-align: center; margin-bottom: 1.5rem; }
        .logo-img { margin-left: auto; margin-right: auto; height: 5rem; margin-bottom: 1rem; }
        .main-title { 
            text-align: center; font-size: 1.5rem; font-weight: 600; margin-bottom: 1.5rem; 
            padding-bottom: 1rem; border-bottom: 2px solid #dcfce7; /* Açık yeşil sınır */
            color: #065f46; /* Koyu yeşil başlık */
        }
        .section-title { 
            display: block; margin-bottom: 1rem; font-weight: 600; color: #059669; /* Ana yeşil */
            font-size: 1.125rem; padding-bottom: 0.5rem; border-bottom: 1px solid #a7f3d0; /* Yeşil tonu sınır */
        }
        .participant-info-section { margin-bottom: 1.5rem; padding-bottom: 1.5rem; border-bottom: 1px solid #f3f4f6; }
        .form-label { display: block; margin-bottom: 0.5rem; font-weight: 500; color: #374151; }
        .form-input { 
            padding: 8px 10px; width: 100%; box-sizing: border-box; border: 1px solid #ccc; 
            border-radius: 6px; font-size: 1em; color: #2c3e50; height: 40px; 
            background-color: white; margin-top: 0.25rem; 
        }
        .form-input:focus {
            border-color: #15803d; box-shadow: 0 0 0 2px rgba(34, 197, 94, 0.3); outline: none; 
        }
        .instructions-box { 
            background-color: #ecfdf5; /* Çok açık yeşil */
            padding: 15px; border-radius: 5px; margin-bottom: 25px; 
            font-size: 0.95em; border: 1px solid #a7f3d0; /* Yeşil tonu sınır */
            color: #065f46; /* Koyu yeşil metin */
        }
        .instructions-box p { margin: 0.5rem 0; }

        .question-block { 
            margin-bottom: 1.5rem; /* Artırıldı */
            padding: 1.5rem; /* Artırıldı */
            border: 1px solid #d1fae5; /* Yeşil tonu sınır */
            border-radius: 0.5rem; 
            background-color: #f9fafb; /* Hafif gri arka plan */
        }
        .question-block.unanswered-highlight { border-left: 4px solid #ef4444; box-shadow: 0 0 10px rgba(239, 68, 68, 0.3); }
        .question-text { display: block; font-weight: 600; /* Kalınlaştırıldı */ margin-bottom: 1rem; /* Artırıldı */ font-size: 1.05em; /* Biraz büyütüldü */ color: #1f2937; }
        
        .options-group { display: flex; flex-direction: column; padding-top: 5px; gap: 0.5rem; /* Seçenekler arasına boşluk */ }
        .option-label { 
            cursor: pointer; padding: 12px 15px; /* Dolgu artırıldı */ border: 2px solid #d1d5db; /* Daha belirgin sınır */
            border-radius: 6px; /* Köşeler yuvarlatıldı */ background-color: #fff; 
            transition: background-color 0.2s, border-color 0.2s, color 0.2s; 
            text-align: left; font-size: 0.9em; /* Biraz büyütüldü */ line-height: 1.5; /* Satır yüksekliği ayarlandı */
            display: flex; align-items: center; user-select: none; width: 100%; 
        }
        .option-label input[type="radio"] { margin-right: 10px; /* Radio buton ile metin arasına boşluk */ width: 1.2em; height: 1.2em; accent-color: #10b981; /* Yeşil radio buton */ }
        .option-label:hover { background-color: #dcfce7; border-color: #6ee7b7; } /* Yeşil hover */
        .option-label.selected-option { 
            background-color: #10b981 !important; /* Koyu yeşil seçili */
            color: white !important; 
            border-color: #059669 !important; /* Daha koyu yeşil sınır */
            font-weight: bold !important; 
        }
        .option-label.selected-option:hover { background-color: #059669 !important; }
        .option-prefix { font-weight: bold; margin-right: 0.5rem; color: #065f46; } /* Seçenek harfi için (a, b) */

        .submit-button { 
            padding: 12px 30px; border-radius: 8px; font-weight: 600; 
            transition: all 0.2s ease-in-out; cursor: pointer; border: none; 
            color: white; display: block; width: 100%; margin-top: 2rem;
            background: #15803d; /* Koyu yeşil buton */
        }
        .submit-button:hover { background: #065f46; /* Daha da koyu yeşil hover */ }
        .error-alert { @apply bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6; }
        .error-alert ul { list-style-type: disc; margin-left: 1.5rem; }
        .footer-text { @apply text-center p-4 text-sm text-gray-500 mt-8; }
    </style>
</head>
<body class="py-8 px-4">
    <div class="survey-container rounded-lg shadow-xl">
        <div class="logo-container">
            <img src="assets/Psikometrik.png" alt="Psikometrik Logosu" class="logo-img">
            <h2 class="main-title"><?php echo $survey_info_title; ?></h2> 
            <p class="text-sm text-gray-500 -mt-4 mb-6">Anket No: <?php echo $survey_id; ?></p>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="error-alert" role="alert">
                <p class="font-bold">Hata</p>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (empty($questions_from_db) && empty($errors)): ?>
             <div class="error-alert" role="alert">
                <p class="font-bold">Hata</p>
                <p>Anket soruları yüklenemedi veya anket mevcut değil.</p>
            </div>
        <?php elseif (!empty($questions_from_db)): ?>
            <form action="take-survey-<?php echo $survey_id; ?>.php<?php echo ($admin_id !== null && $admin_id > 0 && isset($_GET['admin_id'])) ? '?admin_id=' . htmlspecialchars($admin_id) : ''; ?>" method="POST" id="surveyForm">
                <input type="hidden" name="survey_id" value="<?php echo $survey_id; ?>">
                <?php if ($admin_id !== null && $admin_id > 0): ?>
                    <input type="hidden" name="admin_id_hidden" value="<?php echo htmlspecialchars($admin_id); ?>">
                <?php endif; ?>
                
                <?php if ($admin_id !== null && $admin_id > 0): ?>
                <div class="participant-info-section">
                    <h3 class="section-title">Katılımcı Bilgileri</h3> 
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="participant_name" class="form-label">Adı Soyadı:</label>
                            <input type="text" name="participant_name" id="participant_name" class="form-input" required value="<?php echo htmlspecialchars($posted_participant_name); ?>">
                        </div>
                        <div>
                            <label for="participant_class" class="form-label">Sınıfı/Grubu:</label>
                            <input type="text" name="participant_class" id="participant_class" class="form-input" required value="<?php echo htmlspecialchars($posted_participant_class); ?>">
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="instructions-box">
                     <p>Bu anket, toplumumuzdaki bazı önemli olayların farklı insanları etkileme biçimini bulmayı amaçlamaktadır. Her maddede “a” ya da “b“ harfiyle gösterilen iki seçenek bulunmaktadır. Lütfen, her seçenek çiftinde sizin kendi görüşünüze göre gerçeği yansıttığına en çok inandığınız cümleyi (yalnız bir cümleyi) seçiniz.</p>
                     <p>Seçiminizi yaparken, seçmeniz gerektiğini düşündüğünüz ya da doğru olmasını arzu ettiğiniz cümleyi değil, gerçekten daha doğru olduğuna inandığınız cümleyi seçiniz. Bu anket bazı durumlara ilişkin, kişisel inançlarla ilgilidir, bunun için “doğru” ya da “yanlış” cevap diye bir durum söz konusu değildir.</p>
                </div>

                <h3 class="section-title">Anket Soruları</h3>  
                <?php foreach ($questions_from_db as $index => $question): ?>
                    <?php 
                        $options_json = $question['options'];
                        $options_array = json_decode($options_json, true);
                        $current_answer = $posted_answers[$question['question_number']] ?? null;
                        
                        if (json_last_error() !== JSON_ERROR_NONE || !is_array($options_array) || !isset($options_array['a']) || !isset($options_array['b'])) {
                            echo "<div class='question-block ". (in_array($question['question_number'], $unanswered_questions_numbers) ? 'unanswered-highlight' : '') ."'><p class='text-red-500'>Soru (" . htmlspecialchars($question['question_text']) . ") için seçenekler yüklenemedi.</p></div>";
                            continue; 
                        }
                    ?>
                    <div class="question-block <?php echo (in_array($question['question_number'], $unanswered_questions_numbers)) ? 'unanswered-highlight' : ''; ?>" id="question-block-<?php echo htmlspecialchars($question['question_number']); ?>">
                        <span class="question-text"><?php echo htmlspecialchars($question['question_text']); // Soru metninde zaten numara var, tekrar eklemeye gerek yok ?></span>
                        <div class="options-group mt-2">
                            <label class="option-label <?php echo ($current_answer === 'a') ? 'selected-option' : ''; ?>">
                                <input type="radio" name="q_<?php echo htmlspecialchars($question['question_number']); ?>" value="a" required <?php echo ($current_answer === 'a') ? 'checked' : ''; ?>>
                                <span class="option-prefix">a)</span> 
                                <span><?php echo htmlspecialchars($options_array['a']); ?></span>
                            </label>
                            <label class="option-label <?php echo ($current_answer === 'b') ? 'selected-option' : ''; ?>">
                                <input type="radio" name="q_<?php echo htmlspecialchars($question['question_number']); ?>" value="b" <?php echo ($current_answer === 'b') ? 'checked' : ''; ?>>
                                <span class="option-prefix">b)</span> 
                                <span><?php echo htmlspecialchars($options_array['b']); ?></span>
                            </label>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <div class="mt-8 text-center">
                    <button type="submit" class="submit-button">
                        Anketi Tamamla ve Gönder
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
    <footer class="footer-text">
        &copy; <?php echo date("Y"); ?> Psikometrik.Net Anket Platformu
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('surveyForm');
            if (form) {
                const questionBlocks = document.querySelectorAll('.question-block');
                questionBlocks.forEach(block => {
                    const radioButtons = block.querySelectorAll('input[type="radio"]');
                    radioButtons.forEach(radio => {
                        radio.addEventListener('change', function() {
                            // Aynı soru bloğundaki diğer seçeneklerden 'selected-option' classını kaldır
                            const allLabelsInBlock = block.querySelectorAll('.option-label');
                            allLabelsInBlock.forEach(label => label.classList.remove('selected-option'));
                            
                            // Seçili olanın label'ına 'selected-option' classını ekle
                            if (this.checked) {
                                this.closest('.option-label').classList.add('selected-option');
                            }
                        });
                         // Sayfa yüklendiğinde zaten seçili olan varsa onu da işaretle (hata durumunda)
                        if (radio.checked) {
                             radio.closest('.option-label').classList.add('selected-option');
                        }
                    });
                });

                <?php if (!empty($unanswered_questions_numbers)): ?>
                const firstUnansweredId = 'question-block-<?php echo $unanswered_questions_numbers[0]; ?>';
                const firstUnansweredElement = document.getElementById(firstUnansweredId);
                if (firstUnansweredElement) {
                    firstUnansweredElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                <?php endif; ?>
            }
        });
    </script>
</body>
</html>
