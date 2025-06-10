<?php
session_start();
require_once 'src/config.php'; // Veritabanı bağlantısı

// Hata raporlamayı geliştirme aşamasında etkinleştirin
ini_set('display_errors', 1);
error_reporting(E_ALL);

$survey_id = 35; 
$page_title = "Riba 2 - Lise Veli"; 
$questions = [];
$form_error_message = null; 
$form_success_message = null; 

// Form gönderildi mi kontrol et
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $posted_survey_id = filter_input(INPUT_POST, 'survey_id', FILTER_VALIDATE_INT);
    $admin_id_form = filter_input(INPUT_POST, 'admin_id', FILTER_VALIDATE_INT);
    
    $parent_name = trim(filter_input(INPUT_POST, 'parent_name', FILTER_SANITIZE_STRING));
    $child_name = trim(filter_input(INPUT_POST, 'child_name', FILTER_SANITIZE_STRING));
    $child_class = trim(filter_input(INPUT_POST, 'child_class', FILTER_SANITIZE_STRING));
    $child_school_number = trim(filter_input(INPUT_POST, 'child_school_number', FILTER_SANITIZE_STRING));
    
    $answers = $_POST['answers'] ?? []; 

    // Temel doğrulamalar
    if (!$posted_survey_id || $posted_survey_id != $survey_id) {
        $form_error_message = "Geçersiz anket ID'si.";
    } elseif (empty($parent_name)) {
        $form_error_message = "Veli adı soyadı boş bırakılamaz.";
    } elseif (empty($child_name)) {
        $form_error_message = "Öğrenci adı soyadı boş bırakılamaz.";
    } elseif (empty($child_class)) {
        $form_error_message = "Öğrenci sınıfı boş bırakılamaz.";
    } elseif (empty($answers) || !is_array($answers)) {
        $form_error_message = "Cevaplar alınamadı veya geçersiz formatta.";
    } else {
        $current_admin_id_for_db = $admin_id_form; 
        if (empty($current_admin_id_for_db) && isset($_GET['admin_id'])) {
             $current_admin_id_for_db = filter_input(INPUT_GET, 'admin_id', FILTER_VALIDATE_INT);
        }

        if ($current_admin_id_for_db) { 
            try {
                $pdo->beginTransaction();

                $participant_description_data = [
                    'child_name' => $child_name,
                    'child_school_number' => $child_school_number
                ];
                $description_json = json_encode($participant_description_data);

                $stmt_participant = $pdo->prepare(
                    "INSERT INTO survey_participants (name, class, survey_id, admin_id, created_at, description) 
                     VALUES (:name, :class, :survey_id, :admin_id, NOW(), :description)"
                );
                $stmt_participant->bindParam(':name', $parent_name, PDO::PARAM_STR); 
                $stmt_participant->bindParam(':class', $child_class, PDO::PARAM_STR); 
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

                $stmt_q_count_check = $pdo->prepare("SELECT COUNT(*) FROM survey_questions WHERE survey_id = :survey_id");
                $stmt_q_count_check->bindParam(':survey_id', $survey_id, PDO::PARAM_INT);
                $stmt_q_count_check->execute();
                $expected_question_count = $stmt_q_count_check->fetchColumn();

                if (count($answers) < $expected_question_count) {
                    throw new Exception("Lütfen tüm soruları cevaplayınız.");
                }

                foreach ($answers as $question_sort_order => $answer_text) {
                    if (!is_numeric($question_sort_order)) {
                        throw new Exception("Geçersiz soru ID formatı: " . htmlspecialchars($question_sort_order));
                    }
                    $question_id_to_save = (int)$question_sort_order;

                    $stmt_answer->bindParam(':survey_id', $survey_id, PDO::PARAM_INT);
                    $stmt_answer->bindParam(':question_id', $question_id_to_save, PDO::PARAM_INT);
                    $stmt_answer->bindParam(':answer_text', $answer_text, PDO::PARAM_STR);
                    $stmt_answer->bindParam(':admin_id', $current_admin_id_for_db, PDO::PARAM_INT);
                    $stmt_answer->bindParam(':participant_id', $participant_id, PDO::PARAM_INT);
                    
                    if (!$stmt_answer->execute()) {
                        throw new PDOException("Cevap kaydı başarısız (Soru ID: {$question_id_to_save}): " . implode(";", $stmt_answer->errorInfo()));
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
                error_log("take-survey-{$survey_id}.php (Survey ID: {$survey_id}) PDOException: " . $e->getMessage());
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $form_error_message = "Anket gönderilirken bir hata oluştu: " . $e->getMessage();
                error_log("take-survey-{$survey_id}.php (Survey ID: {$survey_id}) Exception: " . $e->getMessage());
            }
        } else {
            $form_error_message = "Anketi başlatan yönetici ID'si bulunamadı. Lütfen geçerli bir link kullanın.";
            error_log("take-survey-{$survey_id}.php: Admin ID formda veya GET parametresinde bulunamadı.");
        }
    }
}

// Sayfa ilk kez yükleniyorsa veya POST sonrası hata varsa soruları çek
if ($_SERVER["REQUEST_METHOD"] !== "POST" || $form_error_message) {
    $admin_id_get = filter_input(INPUT_GET, 'admin_id', FILTER_VALIDATE_INT); 
    if (!$admin_id_get && !$form_error_message && $_SERVER["REQUEST_METHOD"] !== "POST") {
        // $form_error_message = "Ankete erişim için geçerli bir yönetici bağlantısı gereklidir.";
    }

    try {
        $stmt_title = $pdo->prepare("SELECT title FROM surveys WHERE id = :survey_id");
        $stmt_title->bindParam(':survey_id', $survey_id, PDO::PARAM_INT);
        $stmt_title->execute();
        $survey_data = $stmt_title->fetch(PDO::FETCH_ASSOC);
        if ($survey_data && !empty($survey_data['title'])) {
            $page_title = htmlspecialchars($survey_data['title']);
        }

        $stmt_questions = $pdo->prepare("SELECT id, question_number, question_text, question_type, options, sort_order FROM survey_questions WHERE survey_id = :survey_id ORDER BY sort_order ASC");
        $stmt_questions->bindParam(':survey_id', $survey_id, PDO::PARAM_INT);
        $stmt_questions->execute();
        $questions = $stmt_questions->fetchAll(PDO::FETCH_ASSOC);

        if (empty($questions) && !$form_error_message) { 
            $form_error_message = "Bu anket için soru bulunamadı veya anket mevcut değil.";
        }

    } catch (PDOException $e) {
        if (!$form_error_message) { 
            $form_error_message = "Veritabanı hatası: Sorular yüklenemedi. Lütfen daha sonra tekrar deneyin.";
        }
        error_log("take-survey-{$survey_id}.php (GET) PDOException: " . $e->getMessage());
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
            text-align: center; font-size: 1.5rem; font-weight: 600; margin-bottom: 1.5rem; 
            padding-bottom: 1rem; border-bottom: 2px solid #dcfce7; color: #1f2937; 
        }
        .section-title { 
            display: block; margin-bottom: 1rem; font-weight: 600; color: #059669; 
            font-size: 1.125rem; padding-bottom: 0.5rem; border-bottom: 1px solid #a7f3d0; 
        }
        .info-block { margin-bottom: 1.5rem; padding-bottom: 1.5rem; border-bottom: 1px solid #f3f4f6; }
        .question-section-container { 
            margin-bottom: 2rem; padding: 1.5rem; border: 1px solid #d1fae5; 
            border-radius: 0.5rem; background-color: #f9fafb; 
        }
        .question-block { margin-bottom: 15px; padding: 10px 0; border-bottom: 1px solid #eee; }
        .question-block:last-child { border-bottom: none; }
        .question-text { display: block; font-weight: 500; margin-bottom: 10px; font-size: 0.95em; color: #1f2937; }
        .options-group { display: flex; flex-direction: column; padding-top: 5px; }
        .option-label { 
            cursor: pointer; padding: 8px 12px; border: 1px solid #ced4da; 
            border-radius: 5px; background-color: #fff; 
            transition: background-color 0.2s, border-color 0.2s, color 0.2s; 
            text-align: left; font-size: 0.85em; line-height: 1.4; 
            display: flex; align-items: center; user-select: none; width: 100%; 
        }
        .option-label input[type="radio"] { position: absolute; opacity: 0; width: 0; height: 0; pointer-events: none; }
        .option-label:hover { background-color: #dcfce7; border-color: #a7f3d0; }
        .selected-option { background-color: #22c55e !important; color: white !important; border-color: #16a34a !important; font-weight: bold !important; }
        .selected-option:hover { background-color: #16a34a !important; }
        .option-text-prefix { margin-right: 0.35rem; }
        .form-input { 
            padding: 8px 10px; width: 100%; box-sizing: border-box; border: 1px solid #ccc; 
            border-radius: 6px; font-size: 1em; color: #2c3e50; height: 40px; 
            background-color: white; margin-top: 0.25rem; 
        }
        .form-input:focus {
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
            <h2 class="main-title"><?php echo $page_title; ?></h2> 
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
            <form action="take-survey-35.php<?php echo isset($_GET['admin_id']) ? '?admin_id='.htmlspecialchars($_GET['admin_id']) : ''; ?>" method="POST" id="surveyForm">
                <input type="hidden" name="survey_id" value="<?php echo $survey_id; ?>">
                <input type="hidden" name="admin_id" value="<?php echo htmlspecialchars(filter_input(INPUT_GET, 'admin_id', FILTER_VALIDATE_INT) ?? ''); ?>">
                
                <div class="info-block">
                    <h3 class="section-title">Veli ve Öğrenci Bilgileri</h3> 
                    <div class="mb-4">
                        <label for="parent_name" class="block text-sm font-medium text-gray-700">Velinin Adı Soyadı:</label>
                        <input type="text" name="parent_name" id="parent_name" class="form-input" required value="<?php echo htmlspecialchars($_POST['parent_name'] ?? ''); ?>">
                    </div>
                    <div class="mb-4">
                        <label for="child_name" class="block text-sm font-medium text-gray-700">Öğrencinin Adı Soyadı:</label>
                        <input type="text" name="child_name" id="child_name" class="form-input" required value="<?php echo htmlspecialchars($_POST['child_name'] ?? ''); ?>">
                    </div>
                     <div class="mb-4">
                        <label for="child_class" class="block text-sm font-medium text-gray-700">Öğrencinin Sınıfı:</label>
                        <input type="text" name="child_class" id="child_class" class="form-input" required value="<?php echo htmlspecialchars($_POST['child_class'] ?? ''); ?>">
                    </div>
                    <div>
                        <label for="child_school_number" class="block text-sm font-medium text-gray-700">Öğrencinin Okul Numarası (isteğe bağlı):</label>
                        <input type="text" name="child_school_number" id="child_school_number" class="form-input" value="<?php echo htmlspecialchars($_POST['child_school_number'] ?? ''); ?>">
                    </div>
                </div>

                <div class="instructions">
                     <p>Lütfen aşağıdaki her bir madde için çocuğunuzun öncelikli rehberlik ihtiyacını daha iyi tanımladığını düşündüğünüz seçeneği (A veya B) işaretleyiniz.</p>
                </div>

                <h3 class="section-title">Anket Soruları</h3>  
                <?php foreach ($questions as $index => $question): ?>
                    <?php 
                        $options_json = $question['options'];
                        $options_array = json_decode($options_json, true);
                        $current_answer = $_POST['answers'][$question['sort_order']] ?? null;
                        
                        if (json_last_error() !== JSON_ERROR_NONE || !is_array($options_array) || !isset($options_array['A']) || !isset($options_array['B'])) {
                            echo "<div class='question-block'><p class='text-red-500'>Soru (" . htmlspecialchars($question['question_text']) . ") için seçenekler yüklenemedi. Lütfen seçeneklerin JSON formatında ve 'A', 'B' anahtarlarına sahip olduğundan emin olun.</p><pre>Options JSON: " . htmlspecialchars($options_json) . "</pre></div>";
                            continue; 
                        }
                    ?>
                    <div class="question-block" id="question-<?php echo htmlspecialchars($question['sort_order']); ?>">
                        <span class="question-text"><?php echo htmlspecialchars($question['question_text']); ?></span>
                        <div class="options-group mt-2 space-y-2">
                            <label class="option-label <?php echo ($current_answer === $options_array['A']) ? 'selected-option' : ''; ?>">
                                <input type="radio" name="answers[<?php echo htmlspecialchars($question['sort_order']); ?>]" value="<?php echo htmlspecialchars($options_array['A']); ?>" required <?php echo ($current_answer === $options_array['A']) ? 'checked' : ''; ?>>
                                <span class="option-text-prefix">A)</span> 
                                <span class="option-text-value"><?php echo htmlspecialchars($options_array['A']); ?></span>
                            </label>
                            <label class="option-label <?php echo ($current_answer === $options_array['B']) ? 'selected-option' : ''; ?>">
                                <input type="radio" name="answers[<?php echo htmlspecialchars($question['sort_order']); ?>]" value="<?php echo htmlspecialchars($options_array['B']); ?>" <?php echo ($current_answer === $options_array['B']) ? 'checked' : ''; ?>>
                                <span class="option-text-prefix">B)</span> 
                                <span class="option-text-value"><?php echo htmlspecialchars($options_array['B']); ?></span>
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
    <footer class="footer">
        &copy; <?php echo date("Y"); ?> Psikometrik.Net Anket Platformu
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const questionBlocks = document.querySelectorAll('.question-block');
            questionBlocks.forEach(block => {
                const radioButtons = block.querySelectorAll('input[type="radio"]');
                
                radioButtons.forEach(radio => {
                    radio.addEventListener('change', function() {
                        const allLabelsInBlock = block.querySelectorAll('.option-label');
                        allLabelsInBlock.forEach(label => label.classList.remove('selected-option'));
                        
                        if (this.checked) {
                            this.closest('.option-label').classList.add('selected-option');
                        }
                    });
                });
            });
        });
    </script>
</body>
</html>
