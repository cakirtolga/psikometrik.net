<?php
// Hata raporlamayı geliştirme aşamasında etkinleştirin (session_start() öncesine alınabilir)
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/src/config.php';

header('Content-Type: text/html; charset=utf-8');

$survey_id_get = filter_input(INPUT_GET, 'survey_id', FILTER_VALIDATE_INT);
$admin_id_get = filter_input(INPUT_GET, 'admin_id', FILTER_VALIDATE_INT); 

$page_title = "Anket"; 
$survey_description_text = "";
$questions = [];
$form_error_message = null; 
$error = ''; 
$form_data = $_POST; 

$survey_id = null; 

if (!$survey_id_get) {
    $form_error_message = "Geçerli bir anket ID'si belirtilmedi.";
} else {
    $survey_id = $survey_id_get; 
}

if (is_null($admin_id_get) || $admin_id_get === false || $admin_id_get <= 0) {
    if(empty($form_error_message)) { 
        $form_error_message = 'Erişim için geçerli bir yönetici ID\'si (`admin_id`) URL\'de belirtilmelidir.';
    }
    error_log("take_survey_generic.php: Admin ID eksik veya geçersiz. Alınan: " . print_r($_GET['admin_id'] ?? 'Not set', true));
} else {
    try {
        $adminStmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
        $adminStmt->execute([$admin_id_get]);
        $adminExists = $adminStmt->fetch(PDO::FETCH_ASSOC);
        if (!$adminExists) {
            if(empty($form_error_message)) $form_error_message = 'Belirtilen yönetici ID\'si sistemde bulunamadı.';
            error_log("take_survey_generic.php: Geçersiz admin ID veritabanında bulunamadı: " . $admin_id_get);
            $admin_id_get = null; 
        }
    } catch (PDOException $e) {
        if(empty($form_error_message)) $form_error_message = 'Yönetici bilgisi doğrulanırken bir hata oluştu.';
        error_log("take_survey_generic.php Admin ID DB Check PDOException: " . $e->getMessage());
        $admin_id_get = null;
    }
}


if ($_SERVER["REQUEST_METHOD"] == "POST" && $survey_id && empty($form_error_message) ) {
    $posted_survey_id = filter_input(INPUT_POST, 'survey_id', FILTER_VALIDATE_INT);
    $admin_id_form = filter_input(INPUT_POST, 'admin_id', FILTER_VALIDATE_INT); 
    
    $participant_name = trim(filter_input(INPUT_POST, 'participant_name', FILTER_SANITIZE_STRING));
    $participant_class = trim(filter_input(INPUT_POST, 'participant_class', FILTER_SANITIZE_STRING));
    $participant_school_number = trim(filter_input(INPUT_POST, 'participant_school_number', FILTER_SANITIZE_STRING));
    
    $answers = $_POST['answers'] ?? []; 

    if (!$posted_survey_id || $posted_survey_id != $survey_id) {
        $form_error_message = "Form gönderiminde geçersiz anket ID'si.";
    } elseif (empty($participant_name)) {
        $form_error_message = "Ad Soyad boş bırakılamaz.";
    } elseif (empty($participant_class)) { 
        $form_error_message = "Sınıf/Grup seçimi zorunludur.";
    } elseif (empty($answers) || !is_array($answers)) {
        $form_error_message = "Cevaplar alınamadı veya geçersiz formatta.";
    } else {
        $current_admin_id_for_db = $admin_id_form ?: $admin_id_get; 

        if ($current_admin_id_for_db) { 
            try {
                $pdo->beginTransaction();

                $participant_extra_info = [];
                if (!empty($participant_school_number)) {
                    $participant_extra_info['school_number'] = $participant_school_number;
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

                $current_total_questions = 0; 
                if ($survey_id) {
                     $stmt_q_for_count = $pdo->prepare("SELECT id FROM survey_questions WHERE survey_id = :survey_id");
                     $stmt_q_for_count->bindParam(':survey_id', $survey_id, PDO::PARAM_INT);
                     $stmt_q_for_count->execute();
                     $current_total_questions = $stmt_q_for_count->rowCount();
                }

                if (count($answers) < $current_total_questions) {
                    throw new Exception("Lütfen tüm soruları cevaplayınız. Yanıtlanan: " . count($answers) . ", Beklenen: " . $current_total_questions);
                }

                // DÜZELTME: $question_identifier artık survey_questions.question_number olacak.
                foreach ($answers as $question_number_from_form => $answer_text) {
                    // $question_number_from_form, HTML formundaki input name'inden (answers[QUESTION_NUMBER]) gelen değerdir.
                    $question_id_to_save = (int)$question_number_from_form; 

                    $stmt_answer->bindParam(':survey_id', $survey_id, PDO::PARAM_INT);
                    $stmt_answer->bindParam(':question_id', $question_id_to_save, PDO::PARAM_INT); // Buraya question_number kaydedilecek
                    $stmt_answer->bindParam(':answer_text', $answer_text, PDO::PARAM_STR);
                    $stmt_answer->bindParam(':admin_id', $current_admin_id_for_db, PDO::PARAM_INT);
                    $stmt_answer->bindParam(':participant_id', $participant_id, PDO::PARAM_INT);
                    
                    if (!$stmt_answer->execute()) {
                        throw new PDOException("Cevap kaydı başarısız (Soru No: {$question_id_to_save}): " . implode(";", $stmt_answer->errorInfo()));
                    }
                }

                $pdo->commit();
                $_SESSION['survey_message'] = "Anketiniz başarıyla gönderildi. Katılımınız için teşekkür ederiz!";
                $_SESSION['survey_message_type'] = "success";
                header("Location: tamamlandi.php?survey_id=" . $survey_id . "&admin_id=" . $current_admin_id_for_db . "&participant_id=" . $participant_id); 
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

$totalQuestions = 0; 
if ($survey_id && empty($error) && ($_SERVER["REQUEST_METHOD"] !== "POST" || !empty($form_error_message)) ) {
    try {
        $stmt_survey = $pdo->prepare("SELECT title, description FROM surveys WHERE id = :survey_id");
        $stmt_survey->bindParam(':survey_id', $survey_id, PDO::PARAM_INT);
        $stmt_survey->execute();
        $survey_data = $stmt_survey->fetch(PDO::FETCH_ASSOC);

        if ($survey_data) {
            $page_title = htmlspecialchars($survey_data['title']);
            $survey_description_text = htmlspecialchars($survey_data['description'] ?? '');
        } else {
            if (empty($form_error_message)) $form_error_message = "Belirtilen ID ile anket bulunamadı.";
        }

        if (empty($form_error_message)) { 
            $stmt_questions = $pdo->prepare("
                SELECT id, question_number, question_text, question_type, options, sort_order 
                FROM survey_questions 
                WHERE survey_id = :survey_id 
                ORDER BY sort_order ASC, question_number ASC, id ASC 
            ");
            $stmt_questions->bindParam(':survey_id', $survey_id, PDO::PARAM_INT);
            $stmt_questions->execute();
            $questions = $stmt_questions->fetchAll(PDO::FETCH_ASSOC);
            $totalQuestions = count($questions); 

            if (empty($questions) && empty($form_error_message)) {
                $form_error_message = "Bu anket için soru bulunamadı."; 
            }
        }
    } catch (PDOException $e) {
        if (empty($form_error_message)) {
            $form_error_message = "Veritabanı hatası: Anket bilgileri yüklenemedi. Lütfen daha sonra tekrar deneyin.";
        }
        error_log("take_survey_generic.php (GET) PDOException (Survey ID: {$survey_id}): " . $e->getMessage());
    }
}

$psikometrikLogoPath = 'assets/Psikometrik.png'; 
$psikometrikLogoExists = file_exists(__DIR__ . '/' . $psikometrikLogoPath);

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Psikometrik.Net</title>
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
            margin: 20px auto; 
            background-color: #ffffff;
            padding: 30px; 
            border-radius: 8px; 
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); 
            text-align: left; 
        }
        .logo-container { text-align: center; margin-bottom: 1.5rem; }
        .logo-img { margin-left: auto; margin-right: auto; max-height: 60px; width:auto; margin-bottom: 1rem; }
        .main-title { 
            text-align: center; font-size: 1.75rem; font-weight: 600; margin-bottom: 0.5rem; 
            color: #065f46; /* Koyu Yeşil */
        }
        .total-questions-info {
            text-align: center;
            font-size: 0.9rem;
            color: #059669; /* Ana yeşil */
            margin-bottom: 1rem;
            padding: 0.25rem 0.5rem;
            background-color: #ecfdf5;
            border: 1px solid #a7f3d0;
            display: inline-block;
            border-radius: 0.25rem;
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
        .info-block label { display: block; margin-bottom: 0.5rem; font-weight: 500; color: #374151; }
        .info-block input[type="text"], .info-block select {
            display: block; margin-bottom: 1rem; padding: 0.75rem; width: 100%;
            box-sizing: border-box; border: 1px solid #d1d5db; border-radius: 6px;
            font-size: 1em; color: #1f2937; background-color: #f9fafb;
        }
        .info-block input[type="text"]:focus, .info-block select:focus {
            border-color: #10b981; box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.3); outline: none;
        }
        .class-selection-container { display: flex; gap: 1rem; margin-bottom: 1rem; }
        .class-selection-container > div { flex: 1; }

        .question-block { 
            margin-bottom: 1.5rem; padding: 1.5rem; 
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
        
        .submit-button-container { text-align: right; margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid #dcfce7; }
        .submit-button { 
            padding: 12px 30px; border-radius: 8px; font-weight: 600; 
            transition: all 0.2s ease-in-out; cursor: pointer; border: none; 
            color: white; display: inline-block; 
            background: #15803d; 
        }
        .submit-button:hover { background: #0b532c; }
        .error-alert { @apply bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6; }
        .footer { @apply text-center p-4 text-sm text-gray-500 mt-8; color: #047857;}
    </style>
</head>
<body class="py-8 px-4">
    <div class="survey-container rounded-lg shadow-xl">
        <div class="logo-container">
            <?php if ($psikometrikLogoExists): ?>
                <img src="<?php echo htmlspecialchars($psikometrikLogoPath); ?>" alt="Psikometrik.Net Logosu" class="logo-img">
            <?php endif; ?>
        </div>
        <div class="text-center"> 
            <h1 class="main-title"><?php echo $page_title; ?></h1>
            <?php if ($totalQuestions > 0 && empty($form_error_message) && empty($error) ): ?>
                <span class="total-questions-info">Toplam Soru: <?php echo $totalQuestions; ?></span>
            <?php endif; ?>
        </div>
        <?php if (!empty($survey_description_text) && empty($form_error_message) && empty($error)): ?>
            <p class="survey-description"><?php echo nl2br($survey_description_text); ?></p>
        <?php endif; ?>

        <?php if ($form_error_message || !empty($error)): ?>
            <div class="error-alert" role="alert">
                <p class="font-bold">Hata</p>
                <p><?php echo htmlspecialchars($form_error_message ?: $error); ?></p>
            </div>
        <?php endif; ?>

        <?php if (empty($error) && empty($form_error_message) && !empty($questions)): ?>
            <form action="take_survey_generic.php?survey_id=<?php echo $survey_id; ?><?php echo $admin_id_get ? '&admin_id='.$admin_id_get : ''; ?>" method="POST" id="surveyForm">
                <input type="hidden" name="survey_id" value="<?php echo $survey_id; ?>">
                <?php if ($admin_id_get): ?>
                    <input type="hidden" name="admin_id" value="<?php echo htmlspecialchars($admin_id_get); ?>">
                <?php endif; ?>
                
                <div class="info-block">
                    <h3 class="section-title">Katılımcı Bilgileri</h3> 
                    <div class="mb-4">
                        <label for="participant_name" class="block text-sm font-medium text-gray-700">Adınız Soyadınız: <span class="text-red-500">*</span></label>
                        <input type="text" name="participant_name" id="participant_name" class="form-input" required value="<?php echo htmlspecialchars($form_data['participant_name'] ?? ''); ?>">
                    </div>
                    
                    <label for="student_class_number_select">Sınıfınız/Grubunuz: <span class="text-red-500">*</span></label> 
                    <div class="class-selection-container">
                        <div>
                            <select id="student_class_number_select" class="form-input"> <option value="">Seviye</option>
                                <?php for ($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo (isset($form_data['student_class_number_select']) && $form_data['student_class_number_select'] == $i) ? 'selected' : ''; ?>><?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div>
                            <select id="student_class_branch_select" class="form-input"> <option value="">Şube</option>
                                <?php foreach (range('A', 'Z') as $letter): ?>
                                    <option value="<?php echo $letter; ?>" <?php echo (isset($form_data['student_class_branch_select']) && $form_data['student_class_branch_select'] == $letter) ? 'selected' : ''; ?>><?php echo $letter; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <input type="hidden" name="participant_class" id="participant_class_hidden_input" value="<?php echo htmlspecialchars($form_data['participant_class'] ?? ''); ?>">


                    <div class="mb-4">
                        <label for="participant_school_number" class="block text-sm font-medium text-gray-700">Okul Numaranız (isteğe bağlı):</label>
                        <input type="text" name="participant_school_number" id="participant_school_number" class="form-input" value="<?php echo htmlspecialchars($form_data['participant_school_number'] ?? ''); ?>">
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
                        // DÜZELTME: $question_identifier olarak $question['question_number'] kullanılacak
                        $question_identifier_for_answers_array = $question['question_number'] ?? $question['id']; // Eğer question_number yoksa id'yi kullan
                        $current_answer = $form_data['answers'][$question_identifier_for_answers_array] ?? null;
                    ?>
                    <div class="question-block" id="question-block-<?php echo htmlspecialchars($question['id']); // Blok ID'si için DB ID'si kullanılabilir ?>">
                        <p class="question-text">
                            <?php echo ($index + 1) . ". "; // Soru numarasını göster ?>
                            <?php echo htmlspecialchars($question['question_text']); ?>
                            <span class="text-red-500">*</span>
                        </p>
                        <div class="options-group mt-2 space-y-2">
                            <?php if ($question['question_type'] === 'multiple_choice_radio' || $question['question_type'] === 'likert_5_point' || $question['question_type'] === 'evet_hayir' || $question['question_type'] === 'forced_choice'): ?>
                                <?php if (is_array($options_array) && !empty($options_array)): ?>
                                    <?php foreach ($options_array as $key_or_value => $option_text_display): ?>
                                        <?php
                                            $option_value_attr = ($question['question_type'] === 'forced_choice') ? $key_or_value : (is_int($key_or_value) ? $option_text_display : $key_or_value);
                                            $option_prefix = '';
                                            if ($question['question_type'] === 'forced_choice' && preg_match('/^[a-zA-Z]$/', (string)$key_or_value) ) {
                                                 $option_prefix = strtoupper($key_or_value) . ") ";
                                            } elseif ($question['question_type'] === 'multiple_choice_radio' && !is_int($key_or_value) && preg_match('/^[A-Z]$/i', (string)$key_or_value) ) {
                                                $option_prefix = strtoupper($key_or_value) . ") ";
                                            }
                                        ?>
                                        <label class="option-label <?php echo ($current_answer === $option_value_attr) ? 'selected-option' : ''; ?>">
                                            <input type="radio" name="answers[<?php echo htmlspecialchars($question_identifier_for_answers_array); ?>]" value="<?php echo htmlspecialchars($option_value_attr); ?>" required <?php echo ($current_answer === $option_value_attr) ? 'checked' : ''; ?>>
                                            <?php if ($option_prefix): ?>
                                                <span class="option-text-prefix font-semibold"><?php echo $option_prefix; ?></span>
                                            <?php endif; ?>
                                            <span class="option-text-value"><?php echo htmlspecialchars($option_text_display); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-sm text-red-500">Bu soru için seçenek bulunamadı.</p>
                                <?php endif; ?>
                            <?php elseif ($question['question_type'] === 'text_short'): ?>
                                <input type="text" name="answers[<?php echo htmlspecialchars($question_identifier_for_answers_array); ?>]" class="form-input" value="<?php echo htmlspecialchars($current_answer ?? ''); ?>" required>
                            <?php elseif ($question['question_type'] === 'text_long'): ?>
                                <textarea name="answers[<?php echo htmlspecialchars($question_identifier_for_answers_array); ?>]" rows="3" class="form-input" required><?php echo htmlspecialchars($current_answer ?? ''); ?></textarea>
                            <?php else: ?>
                                <p class="text-sm text-red-500">Bilinmeyen soru tipi: <?php echo htmlspecialchars($question['question_type']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <div class="submit-button-container">
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
            const classNumberSelect = document.getElementById('student_class_number_select');
            const classBranchSelect = document.getElementById('student_class_branch_select');
            const classHiddenInput = document.getElementById('participant_class_hidden_input'); 

            function updateHiddenClassInput() {
                if (classNumberSelect && classBranchSelect && classHiddenInput) {
                    const numberVal = classNumberSelect.value;
                    const branchVal = classBranchSelect.value;
                    if (numberVal && branchVal) { 
                        classHiddenInput.value = numberVal + branchVal;
                    } else { 
                        classHiddenInput.value = ''; 
                    }
                }
            }

            if (classNumberSelect) classNumberSelect.addEventListener('change', updateHiddenClassInput);
            if (classBranchSelect) classBranchSelect.addEventListener('change', updateHiddenClassInput);
            updateHiddenClassInput(); 


            const questionBlocks = document.querySelectorAll('.question-block');
            questionBlocks.forEach(block => {
                const radioButtons = block.querySelectorAll('input[type="radio"], input[type="checkbox"]'); 
                
                radioButtons.forEach(radio => {
                    if (radio.checked) {
                        const label = radio.closest('.option-label');
                        if (label) label.classList.add('selected-option');
                    }

                    radio.addEventListener('change', function() {
                        const groupName = this.name;
                        document.querySelectorAll(`input[name="${groupName}"]`).forEach(rb => {
                            const lbl = rb.closest('.option-label');
                            if (lbl) lbl.classList.remove('selected-option');
                        });
                        
                        if (this.checked) {
                            this.closest('.option-label').classList.add('selected-option');
                        }
                    });
                });
            });

            const surveyForm = document.getElementById('surveyForm');
            if (surveyForm) {
                surveyForm.addEventListener('submit', function(event) {
                    updateHiddenClassInput(); 
                    
                    const nameInput = document.getElementById('participant_name');
                    if (nameInput && nameInput.value.trim() === '') { 
                         alert('Lütfen Ad Soyad giriniz.');
                         event.preventDefault();
                         nameInput.focus();
                         return;
                     }
                     if (classHiddenInput && classHiddenInput.value.trim() === '') { 
                         alert('Lütfen Sınıf ve Şube seçiniz.');
                         event.preventDefault();
                         if(classNumberSelect && classNumberSelect.value === '') classNumberSelect.focus();
                         else if(classBranchSelect && classBranchSelect.value === '') classBranchSelect.focus();
                         return;
                     }
                    
                    let allQuestionsAnswered = true;
                    document.querySelectorAll('.question-block').forEach(qBlock => {
                        const questionId = qBlock.id.replace('question-block-', ''); // Bu ID, survey_questions.id'ye karşılık geliyor
                        // Cevap input'unun name attribute'ü answers[QUESTION_NUMBER] şeklinde olmalı.
                        // PHP tarafı $answers[$question_number_from_form] şeklinde alıyor.
                        // Dolayısıyla, JS'te de bu QUESTION_NUMBER'a göre kontrol yapılmalı.
                        // Eğer soru blok ID'si question_db_id ise, buradan question_number'a ulaşmak için
                        // PHP'den JS'e bir eşleme (map) aktarılabilir veya input'lara data attribute eklenebilir.
                        // Şimdilik, her soru bloğundaki inputların genel bir şekilde kontrol edildiğini varsayalım.
                        // Bu generic yapı için en sağlıklısı, PHP'de soruları çekerken
                        // `answers[<?php echo htmlspecialchars($question['question_number']); ?>]` name'ini kullanmaktır.

                        // Mevcut JS, cevap inputlarını name'lerine göre değil, genel olarak blok içindeki varlıklarına göre kontrol ediyor.
                        // Bu, `answers[DB_ID]` veya `answers[QUESTION_NUMBER]` olmasına göre değişmez.
                        // Önemli olan, `required` attribute'ünün olması ve değerinin boş olmaması.
                        const inputs = qBlock.querySelectorAll(`input[name^="answers["], textarea[name^="answers["]`);
                        let answeredThisQuestion = false;
                        if (inputs.length > 0) {
                            inputs.forEach(input => {
                                if ((input.type === 'radio' || input.type === 'checkbox') && input.required) {
                                    // Radio/checkbox gruplarında en az birinin seçili olması gerekir.
                                    // Bu kontrol, grubun name'ine göre yapılmalı.
                                    const groupName = input.name;
                                    if (document.querySelector(`input[name="${groupName}"]:checked`)) {
                                        answeredThisQuestion = true;
                                    }
                                } else if (input.required) { // text, textarea
                                    if (input.value.trim() !== '') answeredThisQuestion = true;
                                } else if (!input.required) { // required olmayanlar için
                                    answeredThisQuestion = true; // Yanıtlanmış say
                                }
                            });
                             if (inputs[0] && (inputs[0].type === 'radio' || inputs[0].type === 'checkbox') && inputs[0].required && !answeredThisQuestion) {
                                // Eğer radio/checkbox grubu zorunluysa ve hiçbiri seçilmemişse
                                allQuestionsAnswered = false;
                            } else if (inputs[0] && inputs[0].required && !answeredThisQuestion){
                                allQuestionsAnswered = false;
                            }

                        } else {
                            // Input yoksa, bu soruyu atlanmış sayabiliriz veya bir hata olarak işaretleyebiliriz.
                            // Şimdilik, input yoksa yanıtlanmış gibi varsayıyoruz, çünkü formda gösterilmiyor.
                        }
                    });

                    if(!allQuestionsAnswered){
                        alert('Lütfen tüm soruları yanıtlayınız.');
                        event.preventDefault();
                    }
                });
            }
        });
    </script>
</body>
</html>
