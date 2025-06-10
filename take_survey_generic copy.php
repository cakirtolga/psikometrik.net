<?php
session_start();
require_once __DIR__ . '/src/config.php';

// Hata raporlamayı geliştirme aşamasında etkinleştirin
ini_set('display_errors', 1);
error_reporting(E_ALL);

$survey_id_get = filter_input(INPUT_GET, 'survey_id', FILTER_VALIDATE_INT);
$admin_id_get = filter_input(INPUT_GET, 'admin_id', FILTER_VALIDATE_INT); 

$page_title = "Anket"; 
$survey_description_text = "";
$questions = [];
$form_error_message = null; 
$form_data = $_POST; // Hata durumunda formu tekrar doldurmak için

if (!$survey_id_get) {
    $form_error_message = "Geçerli bir anket ID'si belirtilmedi.";
} else {
    $survey_id = $survey_id_get; // survey_id'yi GET'ten alıyoruz
}

// Form gönderildi mi kontrol et
if ($_SERVER["REQUEST_METHOD"] == "POST" && $survey_id && !$form_error_message) { // survey_id geçerliyse devam et
    $posted_survey_id = filter_input(INPUT_POST, 'survey_id', FILTER_VALIDATE_INT);
    $admin_id_form = filter_input(INPUT_POST, 'admin_id', FILTER_VALIDATE_INT); 
    
    $participant_name = trim(filter_input(INPUT_POST, 'participant_name', FILTER_SANITIZE_STRING));
    $participant_class = trim(filter_input(INPUT_POST, 'participant_class', FILTER_SANITIZE_STRING));
    $participant_school_number = trim(filter_input(INPUT_POST, 'participant_school_number', FILTER_SANITIZE_STRING));
    $participant_email = trim(filter_input(INPUT_POST, 'participant_email', FILTER_SANITIZE_EMAIL));
    
    $answers = $_POST['answers'] ?? []; 

    // Temel doğrulamalar
    if (!$posted_survey_id || $posted_survey_id != $survey_id) {
        $form_error_message = "Form gönderiminde geçersiz anket ID'si.";
    } elseif (empty($participant_name)) {
        $form_error_message = "Ad Soyad boş bırakılamaz.";
    } elseif (empty($participant_class)) {
        $form_error_message = "Sınıf/Grup boş bırakılamaz.";
    } elseif (empty($answers) || !is_array($answers)) {
        $form_error_message = "Cevaplar alınamadı veya geçersiz formatta.";
    } else {
        $current_admin_id_for_db = $admin_id_form ?: $admin_id_get; // Önce formdan, yoksa GET'ten al

        if ($current_admin_id_for_db) { 
            try {
                $pdo->beginTransaction();

                $participant_extra_info = [];
                if (!empty($participant_school_number)) {
                    $participant_extra_info['school_number'] = $participant_school_number;
                }
                if (!empty($participant_email) && filter_var($participant_email, FILTER_VALIDATE_EMAIL)) {
                    $participant_extra_info['email'] = $participant_email;
                }
                $description_json = !empty($participant_extra_info) ? json_encode($participant_extra_info) : null;

                $stmt_participant = $pdo->prepare(
                    "INSERT INTO survey_participants (name, class, survey_id, admin_id, created_at, description) 
                     VALUES (:name, :class, :survey_id, :admin_id, NOW(), :description)"
                );
                $stmt_participant->bindParam(':name', $participant_name, PDO::PARAM_STR); 
                $stmt_participant->bindParam(':class', $participant_class, PDO::PARAM_STR); 
                $stmt_participant->bindParam(':survey_id', $survey_id, PDO::PARAM_INT);
                $stmt_participant->bindParam(':admin_id', $current_admin_id_for_db, PDO::PARAM_INT);
                $stmt_participant->bindParam(':description', $description_json, PDO::PARAM_STR);
                
                if (!$stmt_participant->execute()) {
                    throw new PDOException("Katılımcı kaydı başarısız: " . implode(";", $stmt_participant->errorInfo()));
                }
                $participant_id = $pdo->lastInsertId();

                if (!$participant_id) {
                    throw new Exception("Katılımcı ID'si alınamadı.");
                }

                $stmt_answer = $pdo->prepare(
                    "INSERT INTO survey_answers (survey_id, question_id, answer_text, admin_id, created_at, participant_id)
                     VALUES (:survey_id, :question_id, :answer_text, :admin_id, NOW(), :participant_id)"
                );

                // Veritabanından soruları çekerek cevapların doğru sayıda olup olmadığını kontrol et
                $stmt_q_count_check = $pdo->prepare("SELECT COUNT(*) FROM survey_questions WHERE survey_id = :survey_id");
                $stmt_q_count_check->bindParam(':survey_id', $survey_id, PDO::PARAM_INT);
                $stmt_q_count_check->execute();
                $expected_question_count = $stmt_q_count_check->fetchColumn();

                if (count($answers) < $expected_question_count) {
                    throw new Exception("Lütfen tüm soruları cevaplayınız.");
                }

                foreach ($answers as $question_identifier => $answer_text) {
                    if (!is_numeric($question_identifier)) {
                        throw new Exception("Geçersiz soru tanımlayıcı formatı: " . htmlspecialchars($question_identifier));
                    }
                    $question_id_to_save = (int)$question_identifier; // Bu, sorunun sort_order'ı olmalı

                    $stmt_answer->bindParam(':survey_id', $survey_id, PDO::PARAM_INT);
                    $stmt_answer->bindParam(':question_id', $question_id_to_save, PDO::PARAM_INT);
                    $stmt_answer->bindParam(':answer_text', $answer_text, PDO::PARAM_STR);
                    $stmt_answer->bindParam(':admin_id', $current_admin_id_for_db, PDO::PARAM_INT);
                    $stmt_answer->bindParam(':participant_id', $participant_id, PDO::PARAM_INT);
                    
                    if (!$stmt_answer->execute()) {
                        throw new PDOException("Cevap kaydı başarısız (Soru Tanımlayıcı: {$question_id_to_save}): " . implode(";", $stmt_answer->errorInfo()));
                    }
                }

                $pdo->commit();
                $_SESSION['survey_message'] = "Anketiniz başarıyla gönderildi. Katılımınız için teşekkür ederiz!";
                $_SESSION['survey_message_type'] = "success";
                header("Location: tamamlandi.php"); 
                exit();

            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $form_error_message = "Anket gönderilirken bir veritabanı hatası oluştu. Lütfen daha sonra tekrar deneyin.";
                error_log("take_survey_generic.php PDOException (Survey ID: {$survey_id}): " . $e->getMessage());
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $form_error_message = "Anket gönderilirken bir hata oluştu: " . $e->getMessage();
                error_log("take_survey_generic.php Exception (Survey ID: {$survey_id}): " . $e->getMessage());
            }
        } else {
            $form_error_message = "Anketi başlatan yönetici ID'si bulunamadı. Lütfen geçerli bir link kullanın veya sistem yöneticisi ile iletişime geçin.";
            error_log("take_survey_generic.php: Admin ID formda veya GET parametresinde bulunamadı. Survey ID: {$survey_id}");
        }
    }
}


// Sayfa ilk kez yükleniyorsa veya POST sonrası hata varsa anket ve soruları çek
if ($survey_id && ($_SERVER["REQUEST_METHOD"] !== "POST" || $form_error_message)) {
    try {
        $stmt_survey = $pdo->prepare("SELECT title, description FROM surveys WHERE id = :survey_id");
        $stmt_survey->bindParam(':survey_id', $survey_id, PDO::PARAM_INT);
        $stmt_survey->execute();
        $survey_data = $stmt_survey->fetch(PDO::FETCH_ASSOC);

        if ($survey_data) {
            $page_title = htmlspecialchars($survey_data['title']);
            $survey_description_text = htmlspecialchars($survey_data['description'] ?? '');
        } else {
            if (!$form_error_message) $form_error_message = "Belirtilen ID ile anket bulunamadı.";
        }

        if (!$form_error_message) { 
            $stmt_questions = $pdo->prepare("
                SELECT id, question_number, question_text, question_type, options, sort_order 
                FROM survey_questions 
                WHERE survey_id = :survey_id 
                ORDER BY sort_order ASC, question_number ASC, id ASC 
            ");
            $stmt_questions->bindParam(':survey_id', $survey_id, PDO::PARAM_INT);
            $stmt_questions->execute();
            $questions = $stmt_questions->fetchAll(PDO::FETCH_ASSOC);

            if (empty($questions) && !$form_error_message) {
                $form_error_message = "Bu anket için soru bulunamadı."; 
            }
        }
    } catch (PDOException $e) {
        if (!$form_error_message) {
            $form_error_message = "Veritabanı hatası: Anket bilgileri yüklenemedi. Lütfen daha sonra tekrar deneyin.";
        }
        error_log("take_survey_generic.php (GET) PDOException (Survey ID: {$survey_id}): " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: #f0fdf4; 
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
            text-align: center; font-size: 1.5rem; font-weight: 600; margin-bottom: 0.5rem; 
            color: #1f2937; 
        }
        .survey-description {
            text-align: center; font-size: 0.95em; color: #4b5563; 
            margin-bottom: 1.5rem; padding-bottom: 1rem; 
            border-bottom: 2px solid #dcfce7; 
        }
        .section-title { 
            display: block; margin-bottom: 1rem; font-weight: 600; color: #059669; 
            font-size: 1.125rem; padding-bottom: 0.5rem; border-bottom: 1px solid #a7f3d0; 
        }
        .info-block { margin-bottom: 1.5rem; padding-bottom: 1.5rem; border-bottom: 1px solid #f3f4f6; }
        .question-block { 
            margin-bottom: 15px; padding: 15px; 
            border: 1px solid #e5e7eb; border-radius: 6px; 
            background-color: #ffffff; 
        }
        .question-block:last-child { border-bottom: 1px solid #e5e7eb; }
        .question-text { 
            display: block; font-weight: 500; margin-bottom: 10px; 
            font-size: 1em; color: #111827; 
        }
        .options-group { display: flex; flex-direction: column; padding-top: 5px; }
        .option-label { 
            cursor: pointer; padding: 10px 12px; border: 1px solid #ced4da; 
            border-radius: 5px; background-color: #fff; 
            transition: background-color 0.2s, border-color 0.2s, color 0.2s; 
            text-align: left; font-size: 0.9em; line-height: 1.5; 
            display: flex; align-items: center; user-select: none; width: 100%; 
            margin-bottom: 0.5rem;
        }
        .option-label:last-child { margin-bottom: 0; }
        .option-label input[type="radio"], .option-label input[type="checkbox"] { 
            margin-right: 0.75rem; accent-color: #10b981; 
            width: 1.125rem; height: 1.125rem;
        }
        .option-label:hover { background-color: #dcfce7; border-color: #a7f3d0; }
        .selected-option { 
            background-color: #a7f3d0 !important; 
            border-color: #10b981 !important;
            font-weight: 500 !important;
        }
        .selected-option .option-text-prefix { color: #047857; font-weight: 700; }
        .selected-option .option-text-value { color: #065f46; }
        .form-input, .form-textarea { 
            padding: 8px 10px; width: 100%; box-sizing: border-box; border: 1px solid #ccc; 
            border-radius: 6px; font-size: 1em; color: #2c3e50; 
            background-color: white; margin-top: 0.25rem; 
        }
        .form-input { height: 40px; }
        .form-input:focus, .form-textarea:focus {
            border-color: #15803d; box-shadow: 0 0 0 2px rgba(34, 197, 94, 0.3); outline: none; 
        }
        .submit-button { 
            padding: 12px 30px; border-radius: 8px; font-weight: 600; 
            transition: all 0.2s ease-in-out; cursor: pointer; border: none; 
            color: white; display: block; width: 100%; margin-top: 2rem;
            background: #15803d; 
        }
        .submit-button:hover { background: #0b532c; }
        .error-alert { @apply bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6; }
        .footer { @apply text-center p-4 text-sm text-gray-500 mt-8; }
        .instructions { background-color: #e8f5e9; padding: 15px; border-radius: 5px; margin-bottom: 25px; font-size: 0.95em; border: 1px solid #c8e6c9; }
        .instructions p { margin: 0.5rem 0; }
    </style>
</head>
<body class="py-8 px-4">
    <div class="survey-container rounded-lg shadow-xl">
        <div class="logo-container">
            <img src="assets/Psikometrik.png" alt="Psikometrik Logosu" class="logo-img">
            <h1 class="main-title"><?php echo $page_title; ?></h1>
            <?php if (!empty($survey_description_text)): ?>
                <p class="survey-description"><?php echo nl2br($survey_description_text); ?></p>
            <?php endif; ?>
        </div>

        <?php if ($form_error_message): ?>
            <div class="error-alert" role="alert">
                <p class="font-bold">Hata</p>
                <p><?php echo htmlspecialchars($form_error_message); ?></p>
            </div>
        <?php endif; ?>

        <?php if (empty($questions) && !$form_error_message): ?>
             <div class="error-alert" role="alert">
                <p class="font-bold">Hata</p>
                <p>Anket soruları yüklenemedi veya anket mevcut değil.</p>
            </div>
        <?php elseif (!empty($questions)): ?>
            <form action="take_survey_generic.php?survey_id=<?php echo $survey_id; ?><?php echo $admin_id_get ? '&admin_id='.$admin_id_get : ''; ?>" method="POST" id="surveyForm">
                <input type="hidden" name="survey_id" value="<?php echo $survey_id; ?>">
                <input type="hidden" name="admin_id" value="<?php echo htmlspecialchars($admin_id_get ?? ''); ?>">
                
                <div class="info-block">
                    <h3 class="section-title">Katılımcı Bilgileri</h3> 
                    <div class="mb-4">
                        <label for="participant_name" class="block text-sm font-medium text-gray-700">Adınız Soyadınız: <span class="text-red-500">*</span></label>
                        <input type="text" name="participant_name" id="participant_name" class="form-input" required value="<?php echo htmlspecialchars($form_data['participant_name'] ?? ''); ?>">
                    </div>
                    <div class="mb-4">
                        <label for="participant_class" class="block text-sm font-medium text-gray-700">Sınıfınız/Grubunuz: <span class="text-red-500">*</span></label>
                        <input type="text" name="participant_class" id="participant_class" class="form-input" required value="<?php echo htmlspecialchars($form_data['participant_class'] ?? ''); ?>">
                    </div>
                    <div class="mb-4">
                        <label for="participant_school_number" class="block text-sm font-medium text-gray-700">Okul Numaranız (isteğe bağlı):</label>
                        <input type="text" name="participant_school_number" id="participant_school_number" class="form-input" value="<?php echo htmlspecialchars($form_data['participant_school_number'] ?? ''); ?>">
                    </div>
                    <div>
                        <label for="participant_email" class="block text-sm font-medium text-gray-700">E-posta Adresiniz (isteğe bağlı):</label>
                        <input type="email" name="participant_email" id="participant_email" class="form-input" value="<?php echo htmlspecialchars($form_data['participant_email'] ?? ''); ?>">
                    </div>
                </div>

                <h3 class="section-title">Anket Soruları</h3>  
                <?php foreach ($questions as $index => $question): ?>
                    <?php 
                        $options_array = null;
                        if (!empty($question['options'])) {
                            $options_array = json_decode($question['options'], true);
                            if (json_last_error() !== JSON_ERROR_NONE) {
                                echo "<div class='question-block'><p class='text-red-500'>Soru ID: " . htmlspecialchars($question['id']) . " için seçenekler yüklenemedi (JSON Hatalı).</p></div>";
                                $options_array = null; 
                            }
                        }
                        $question_identifier = $question['sort_order'] ?? $question['question_number'] ?? $question['id'];
                        $current_answer = $form_data['answers'][$question_identifier] ?? null;
                    ?>
                    <div class="question-block" id="question-block-<?php echo htmlspecialchars($question_identifier); ?>">
                        <span class="question-text">
                            <?php echo htmlspecialchars($question['question_text']); ?>
                            <span class="text-red-500">*</span>
                        </span>
                        <div class="options-group mt-2 space-y-2">
                            <?php if ($question['question_type'] === 'multiple_choice_radio' || $question['question_type'] === 'likert_5_point' || $question['question_type'] === 'evet_hayir'): ?>
                                <?php if (is_array($options_array) && !empty($options_array)): ?>
                                    <?php foreach ($options_array as $key_or_value => $option_text_display): ?>
                                        <?php
                                            $option_value = is_int($key_or_value) ? $option_text_display : $option_text_display; 
                                            $option_prefix = ($question['question_type'] === 'multiple_choice_radio' && !is_int($key_or_value) && preg_match('/^[A-Z]$/i', (string)$key_or_value) ) ? htmlspecialchars($key_or_value) . ") " : "";
                                        ?>
                                        <label class="option-label <?php echo ($current_answer === $option_value) ? 'selected-option' : ''; ?>">
                                            <input type="radio" name="answers[<?php echo htmlspecialchars($question_identifier); ?>]" value="<?php echo htmlspecialchars($option_value); ?>" required <?php echo ($current_answer === $option_value) ? 'checked' : ''; ?>>
                                            <?php if ($option_prefix): ?>
                                                <span class="option-text-prefix"><?php echo $option_prefix; ?></span>
                                            <?php endif; ?>
                                            <span class="option-text-value"><?php echo htmlspecialchars($option_text_display); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-sm text-red-500">Bu soru için seçenek bulunamadı.</p>
                                <?php endif; ?>
                            <?php elseif ($question['question_type'] === 'text_short'): ?>
                                <input type="text" name="answers[<?php echo htmlspecialchars($question_identifier); ?>]" class="form-input" value="<?php echo htmlspecialchars($current_answer ?? ''); ?>" required>
                            <?php elseif ($question['question_type'] === 'text_long'): ?>
                                <textarea name="answers[<?php echo htmlspecialchars($question_identifier); ?>]" rows="3" class="form-textarea" required><?php echo htmlspecialchars($current_answer ?? ''); ?></textarea>
                            <?php else: ?>
                                <p class="text-sm text-red-500">Bilinmeyen soru tipi: <?php echo htmlspecialchars($question['question_type']); ?></p>
                            <?php endif; ?>
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
    <footer class="footer">
        &copy; <?php echo date("Y"); ?> Psikometrik.Net Anket Platformu
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const questionBlocks = document.querySelectorAll('.question-block');
            questionBlocks.forEach(block => {
                const radioButtons = block.querySelectorAll('input[type="radio"], input[type="checkbox"]'); 
                
                radioButtons.forEach(radio => {
                    radio.addEventListener('change', function() {
                        const allLabelsInBlock = block.querySelectorAll('.option-label');
                        allLabelsInBlock.forEach(label => label.classList.remove('selected-option'));
                        
                        if (this.checked) {
                            this.closest('.option-label').classList.add('selected-option');
                        }
                    });

                    if (radio.checked) {
                        radio.closest('.option-label').classList.add('selected-option');
                    }
                });
            });
        });
    </script>
</body>
</html>
