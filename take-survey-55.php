<?php
session_start();

// config.php dosyasını dahil et (veritabanı bağlantısı için)
require_once 'src/config.php'; // Lütfen bu yolun doğru olduğundan emin olun.

// Hata raporlamayı geliştirme aşamasında etkinleştirin
ini_set('display_errors', 1);
error_reporting(E_ALL);

$survey_id = 55;
$default_page_title = "Offer Benlik İmajı Ölçeği"; 

$admin_id = null;
if (isset($_GET['admin_id'])) {
    $admin_id_from_get = filter_input(INPUT_GET, 'admin_id', FILTER_VALIDATE_INT);
    if ($admin_id_from_get !== false && $admin_id_from_get !== null && $admin_id_from_get > 0) {
        $admin_id = $admin_id_from_get;
    }
}

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
    $_SESSION[$guest_session_key] = uniqid('guest_p_', true);
}
$guest_participant_id = $_SESSION[$guest_session_key]; 

$errors = [];
$unanswered_questions_numbers = []; // Yanıtlanmamış soruların numaralarını tutacak dizi
$posted_answers = []; 
$posted_participant_name = '';
$posted_participant_class = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $posted_survey_id = filter_input(INPUT_POST, 'survey_id', FILTER_VALIDATE_INT);

    if ($admin_id !== null && $admin_id > 0) {
        $posted_participant_name = trim(filter_input(INPUT_POST, 'participant_name', FILTER_SANITIZE_STRING));
        $posted_participant_class = trim(filter_input(INPUT_POST, 'participant_class', FILTER_SANITIZE_STRING));

        if (empty($posted_participant_name)) {
            $errors[] = "Katılımcı Adı Soyadı boş bırakılamaz.";
        }
        if (empty($posted_participant_class)) {
            $errors[] = "Sınıf/Grup bilgisi boş bırakılamaz.";
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
            error_log("PDO Error (get question_numbers): " . implode(";", $stmt_q_info->errorInfo()));
        } else {
            $question_numbers_from_db = $stmt_q_info->fetchAll(PDO::FETCH_COLUMN);
            $total_questions_in_db = count($question_numbers_from_db);

            if ($total_questions_in_db > 0) {
                $all_questions_answered = true;
                $current_answers_for_processing = []; 

                foreach ($question_numbers_from_db as $q_num) {
                    $posted_answers[$q_num] = $_POST['q_' . $q_num] ?? null;
                    if (!isset($_POST['q_' . $q_num]) || !is_numeric($_POST['q_' . $q_num]) || $_POST['q_' . $q_num] < 1 || $_POST['q_' . $q_num] > 6) {
                        $all_questions_answered = false;
                        $errors[] = $q_num . ". soruyu geçerli bir şekilde yanıtlamadınız (1-6 arası).";
                        $unanswered_questions_numbers[] = $q_num; // Yanıtlanmamış soru numarasını ekle
                    } else {
                        $current_answers_for_processing[$q_num] = $_POST['q_' . $q_num];
                    }
                }

                if (!$all_questions_answered && !in_array("Lütfen tüm soruları yanıtlayınız.", $errors)) {
                    // Eğer spesifik soru hataları varsa, genel bir mesaj da eklenebilir veya sadece spesifik olanlar kalabilir.
                    // Şimdilik, eğer $errors zaten doluysa bu genel mesajı eklemeyelim, karışıklık olmasın.
                    // Eğer $errors boşsa ama $all_questions_answered false ise (bu durum normalde olmaz) o zaman ekleyebiliriz.
                    if(empty($errors)){ 
                        $errors[] = "Lütfen işaretlenmemiş soruları yanıtlayınız.";
                    }
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
                                $stmt_insert_answer->bindParam(':answer_text', $answer_text, PDO::PARAM_STR);
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
                        $_SESSION['survey_message'] = "Cevaplarınız kaydedilmedi (yönetici ID'si bulunamadı). Sonuçlar sadece önizleme amaçlıdır.";
                        $_SESSION['survey_message_type'] = "warning"; 
                        $redirect_url = "admin/view-result-55.php?id=" . urlencode($preview_unique_id);
                        $redirect_url .= "&status=preview_no_save"; 
                        header("Location: " . $redirect_url);
                        exit();
                    }
                } 
                // Hata varsa (örn: $all_questions_answered false ise veya $errors doluysa)
                // sayfa yeniden yüklenecek ve $errors gösterilecek.
                // $unanswered_questions_numbers dizisi JS'e aktarılacak.
            } else {
                $errors[] = "Anket için soru bulunamadı.";
            }
        }
    }
}

// Sayfa başlığı ve SEO için açıklama
$survey_info_title = $default_page_title; 
$survey_description_for_seo = "Offer Benlik İmajı Ölçeği, bireylerin kendilerine yönelik algılarını değerlendirmek için kullanılan bir psikometrik araçtır. Bu ölçeği online olarak doldurun.";
$page_keywords_for_seo = "offer benlik imajı ölçeği, benlik algısı testi, psikolojik test, online anket, psikometrik test, kişilik envanteri, psikometrik.net";
$page_author_for_seo = "Psikometrik.net";
$current_page_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
$og_image_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]/assets/Psikometrik_og.png"; 


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

    $stmt_questions = $pdo->prepare("SELECT question_number, question_text, sort_order FROM survey_questions WHERE survey_id = :survey_id ORDER BY sort_order ASC");
    $stmt_questions->bindParam(':survey_id', $survey_id, PDO::PARAM_INT);
    
    $questions_from_db = [];
    if ($stmt_questions->execute()) {
        $questions_from_db = $stmt_questions->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $errors[] = "Sorular veritabanından çekilirken bir hata oluştu.";
        error_log("PDO Error (get questions): " . implode(";", $stmt_questions->errorInfo()));
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
        body { font-family: 'Inter', sans-serif; background-color: #f0f4f8; color: #1a202c; }
        .survey-container { max-width: 800px; margin: 2rem auto; background-color: #ffffff; padding: 2rem; border-radius: 0.5rem; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05); }
        .logo-container { text-align: center; margin-bottom: 1.5rem; }
        .logo-img { margin-left: auto; margin-right: auto; height: 4rem; margin-bottom: 0.5rem; }
        .main-title { text-align: center; font-size: 1.875rem; font-weight: 700; margin-bottom: 0.5rem; color: #2c5282; }
        .survey-subtitle { text-align: center; font-size: 0.875rem; color: #4a5568; margin-bottom: 2rem; }
        .participant-info-section { margin-bottom: 2rem; padding-bottom: 1.5rem; border-bottom: 1px solid #e2e8f0; }
        .form-label { display: block; margin-bottom: 0.5rem; font-weight: 500; color: #374151; }
        .form-input { display: block; width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #d1d5db; border-radius: 0.375rem; box-shadow: sm; font-size: 0.875rem; }
        .form-input:focus { outline: 2px solid transparent; outline-offset: 2px; border-color: #2563eb; box-shadow: 0 0 0 2px rgba(59,130,246,0.4); }
        .instructions-box { background-color: #ebf8ff; border-left: 4px solid #3182ce; color: #2c5282; padding: 1rem; margin-bottom: 2rem; border-radius: 0.25rem; font-size: 0.9rem; }
        .question-block { background-color: #f7fafc; border: 1px solid #e2e8f0; border-radius: 0.5rem; padding: 1.5rem; margin-bottom: 1.5rem; }
        .question-block.unanswered-highlight { border-left: 4px solid #ef4444; /* Kırmızı sol kenarlık */ box-shadow: 0 0 10px rgba(239, 68, 68, 0.3); }
        .question-text { font-weight: 600; color: #2d3748; margin-bottom: 1rem; font-size: 1.1rem; }
        .options-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(70px, 1fr)); gap: 0.75rem; }
        .option-label { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 0.75rem 0.5rem; border: 2px solid #e2e8f0; border-radius: 0.375rem; cursor: pointer; transition: all 0.2s ease-in-out; background-color: #fff; text-align: center; min-height: 70px; }
        .option-label:hover { border-color: #3182ce; background-color: #ebf8ff; }
        .option-label input[type="radio"] { position: absolute; opacity: 0; width: 0; height: 0; }
        .option-label.selected, .option-label input[type="radio"]:checked + .option-content { border-color: #3182ce; background-color: #ebf8ff; box-shadow: 0 0 0 2px rgba(49,130,206,0.4); }
        .option-label input[type="radio"]:checked + .option-content .option-value { font-weight: 700; color: #2c5282; }
        .option-label input[type="radio"]:checked + .option-content .option-description { color: #2c5282; }
        .option-value { font-size: 1.125rem; font-weight: 600; color: #2d3748; line-height: 1; }
        .option-description { font-size: 0.75rem; color: #718096; margin-top: 0.25rem; line-height: 1.2; }
        .submit-button { background-color: #3182ce; font-weight: 600; padding: 0.75rem 2rem; }
        .submit-button:hover { background-color: #2b6cb0; }
        .error-alert { background-color: #fff5f5; border-left: 4px solid #e53e3e; color: #c53030; padding: 1rem; margin-bottom: 1.5rem; border-radius: 0.25rem; }
        .error-alert ul { list-style-type: disc; margin-left: 1.5rem; }
        .footer-text { color: #718096; }
    </style>
</head>
<body class="py-8 px-4">
    <div class="survey-container">
        <header class="logo-container">
            <img src="assets/Psikometrik.png" alt="Psikometrik.net Logo" class="logo-img" onerror="this.style.display='none'; this.onerror=null;">
            <h1 class="main-title"><?php echo $survey_info_title; ?></h1>
            <p class="survey-subtitle">Anket No: <?php echo $survey_id; ?></p>
        </header>

        <?php if (!empty($errors)): ?>
            <div class="error-alert" role="alert">
                <p class="font-bold mb-2">Lütfen aşağıdaki hataları düzeltin:</p>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (empty($questions_from_db) && empty($errors)): ?>
            <div class="bg-yellow-50 border-l-4 border-yellow-400 text-yellow-700 p-4 mb-6" role="alert">
                <p class="font-bold">Bilgi</p>
                <p>Bu anket için henüz soru eklenmemiş veya sorular yüklenemedi.</p>
            </div>
        <?php elseif (!empty($questions_from_db)): ?>
             <form action="take-survey-<?php echo $survey_id; ?>.php<?php echo (isset($_GET['admin_id']) && filter_input(INPUT_GET, 'admin_id', FILTER_VALIDATE_INT) > 0) ? '?admin_id=' . htmlspecialchars(filter_input(INPUT_GET, 'admin_id', FILTER_VALIDATE_INT)) : ''; ?>" method="POST" id="surveyForm" class="space-y-6">
                <input type="hidden" name="survey_id" value="<?php echo $survey_id; ?>">
                <?php 
                if ($admin_id !== null && $admin_id > 0): 
                ?>
                    <input type="hidden" name="admin_id_hidden" value="<?php echo htmlspecialchars($admin_id); ?>">
                <?php endif; ?>

                <?php if ($admin_id !== null && $admin_id > 0): ?>
                <div class="participant-info-section">
                    <h3 class="text-lg font-semibold text-gray-700 mb-4">Katılımcı Bilgileri</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="participant_name" class="form-label">Katılımcı Adı Soyadı:</label>
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
                    Lütfen aşağıdaki ifadeleri dikkatlice okuyun ve size ne kadar uygun olduğunu <strong>1 (Bana hiç uygun değil)</strong> ile <strong>6 (Bana çok uygun)</strong> arasında bir değer seçerek işaretleyin.
                </div>

                <?php foreach ($questions_from_db as $question): ?>
                    <div class="question-block <?php echo (in_array($question['question_number'], $unanswered_questions_numbers)) ? 'unanswered-highlight' : ''; ?>" id="question-block-<?php echo htmlspecialchars($question['question_number']); ?>">
                        <p class="question-text">
                            <?php echo htmlspecialchars($question['sort_order']) . ". " . htmlspecialchars($question['question_text']); ?>
                        </p>
                        <div class="options-grid mt-3">
                            <?php for ($i = 1; $i <= 6; $i++): ?>
                                <?php
                                    $option_value = $i;
                                    $checked = (isset($posted_answers[$question['question_number']]) && $posted_answers[$question['question_number']] == $option_value) ? 'checked' : '';
                                    $label_text = "";
                                    if ($i == 1) $label_text = "Bana hiç uygun değil";
                                    if ($i == 6) $label_text = "Bana çok uygun";
                                ?>
                                <label class="option-label <?php echo !empty($checked) ? 'selected' : ''; ?>">
                                    <input type="radio" 
                                           name="q_<?php echo htmlspecialchars($question['question_number']); ?>" 
                                           value="<?php echo $option_value; ?>" 
                                           required 
                                           <?php echo $checked; ?>>
                                    <span class="option-content">
                                        <span class="option-value"><?php echo $option_value; ?></span>
                                        <?php if(!empty($label_text)): ?>
                                            <span class="option-description"><?php echo $label_text; ?></span>
                                        <?php endif; ?>
                                    </span>
                                </label>
                            <?php endfor; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <div class="mt-8 text-center">
                    <button type="submit" class="submit-button w-full sm:w-auto inline-flex justify-center items-center px-8 py-3 border border-transparent text-base font-medium rounded-md shadow-sm text-white focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition duration-150 ease-in-out">
                        Anketi Tamamla ve Gönder
                    </button>
                </div>
            </form>
        <?php endif; ?>

        <footer class="text-center mt-10 py-6 border-t border-gray-300">
            <p class="text-sm footer-text">&copy; <?php echo date("Y"); ?> Psikometrik.net - Tüm hakları saklıdır.</p>
        </footer>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('surveyForm');
            if (form) {
                const questionBlocks = form.querySelectorAll('.question-block');
                questionBlocks.forEach(block => {
                    const radioButtons = block.querySelectorAll('input[type="radio"]');
                    radioButtons.forEach(radio => {
                        radio.addEventListener('change', function() {
                            const allLabelsInBlock = block.querySelectorAll('.option-label');
                            allLabelsInBlock.forEach(label => label.classList.remove('selected'));
                            
                            if (this.checked) {
                                this.closest('.option-label').classList.add('selected');
                            }
                        });
                        if (radio.checked) {
                             radio.closest('.option-label').classList.add('selected');
                        }
                    });
                });

                <?php if (!empty($unanswered_questions_numbers)): ?>
                // Eğer yanıtlanmamış sorular varsa, ilkine odaklan
                const firstUnansweredId = 'question-block-<?php echo $unanswered_questions_numbers[0]; ?>';
                const firstUnansweredElement = document.getElementById(firstUnansweredId);
                if (firstUnansweredElement) {
                    firstUnansweredElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    // Yanıtlanmamış soru bloğunu vurgula (CSS ile yapıldı: unanswered-highlight)
                }
                <?php endif; ?>
            }
        });
    </script>
</body>
</html>
