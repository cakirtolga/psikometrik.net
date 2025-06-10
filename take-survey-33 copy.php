<?php
// Oturum başlatma (isteğe bağlı, artık tamamlama kontrolü için kullanılmıyor)
session_start();

// Hata Raporlama (Geliştirme için)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Veritabanı bağlantısı ve diğer yapılandırmaları içe aktarma
require_once 'src/config.php';

// $pdo değişkeninin src/config.php tarafından ayarlandığını kontrol et
if (!isset($pdo) || !$pdo instanceof PDO) {
    error_log("Veritabanı bağlantısı (\$pdo) 'config.php' dosyasında kurulamadı veya geçerli değil. take-survey-33.php");
    die("Sistemde bir hata oluştu. Lütfen daha sonra tekrar deneyin veya site yöneticisi ile iletişime geçin.");
}

$survey_id = 33; // Bu anket için sabit ID

// Anket bilgilerini (başlık, açıklama, oluşturan kullanıcı ID) 'surveys' tablosundan çek
$survey_info_stmt = $pdo->prepare("SELECT title, description, user_id AS survey_owner_id FROM surveys WHERE id = :survey_id");
$survey_info_stmt->bindParam(':survey_id', $survey_id, PDO::PARAM_INT);
$survey_info_stmt->execute();
$survey_info = $survey_info_stmt->fetch(PDO::FETCH_ASSOC);

if (!$survey_info) {
    die("Anket bilgileri bulunamadı. Anket ID: " . $survey_id);
}
$survey_title = $survey_info['title'] ?? 'Riba Anketi (Veli Formu - 15 Soru)'; // Varsayılan başlık
$survey_description = $survey_info['description'] ?? 'Okulda öğrencilerimizin hangi rehberlik hizmetlerine ihtiyaç duyduğunu belirlemek amacıyla kullanılır.'; // Varsayılan açıklama
$survey_owner_id = $survey_info['survey_owner_id'];

// Anket sorularını (id, question_text, question_number, sort_order) veritabanından çek
$questions_stmt = $pdo->prepare("SELECT id, question_text, question_number, sort_order FROM survey_questions WHERE survey_id = :survey_id ORDER BY question_number ASC");
$questions_stmt->bindParam(':survey_id', $survey_id, PDO::PARAM_INT);
$questions_stmt->execute();
$db_questions_raw = $questions_stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($db_questions_raw)) {
    die("Anket soruları veritabanında bulunamadı. Anket ID: " . $survey_id);
}

// Soruları ID'lerine göre haritala ve sort_order bilgisini de ekle
$db_questions_map = [];
foreach ($db_questions_raw as $q) {
    $db_questions_map[$q['id']] = $q;
}

// Sorular ve seçenekleri için PHP dizisi (question_number ile eşleşiyor)
$survey_specific_options = [
    1 => ["A" => "Çevremdeki insanların mesleklerini öğrenmek", "B" => "Okul kurallarını öğrenmek"],
    2 => ["A" => "Gelecekte başarılı olmak için kendime uygun hedefler belirlemek", "B" => "Severek yapabileceğim şeyleri öğrenmek"],
    3 => ["A" => "Bilgisayar, cep telefonu, tablet veya televizyonu kullanırken yaşıma uygun içerik seçmek ve kullanım süresini belirlemek", "B" => "Başkaları hatırlatmadan sorumluluklarımı yerine getirmek"],
    4 => ["A" => "Okulda, evde ve arkadaşlık ilişkilerimde doğru kararlar almak", "B" => "Bedenimi korumayı öğrenmek"],
    5 => ["A" => "Sorunlarımı nasıl çözebileceğimi öğrenmek", "B" => "Rehber öğretmen/psikolojik danışmandan hangi konularda yardım alabileceğimi öğrenmek"],
    6 => ["A" => "Yeni arkadaşlar edinmek ve arkadaşlarımla iyi geçinmeyi öğrenmek", "B" => "Oyun oynarken, ödev yaparken arkadaşlarımla yardımlaşmayı öğrenmek"],
    7 => ["A" => "Tehlikeli olabilecek durumlarda dikkatli davranmayı öğrenmek", "B" => "Ders çalıştığım sırada silgiyle oynama, resim çizme gibi dikkatimi dağıtan davranışlardan uzak durmayı öğrenmek"],
    8 => ["A" => "Ders çalışma ortamımı (odamı, masamı) nasıl düzenleyeceğimi bilmek", "B" => "Hangi ortaokullara gidebileceğimi öğrenmek"],
    9 => ["A" => "Okula her gün mutlu bir şekilde gelme isteğimi artırmak", "B" => "Yaşadığım duyguları tanımak"],
    10 => ["A" => "Duygularımı ve isteklerimi saygılı bir şekilde karşımdakine iletme isteğimi artırmak", "B" => "Zorbalıkla karşılaştığımda ne yapmam gerektiğini öğrenmek"],
    11 => ["A" => "Bir çocuk olarak hak ve sorumluluklarımı öğrenmek", "B" => "Kolay öğrendiğim, başkalarından daha iyi yapabildiğim şeyleri öğrenmek"],
    12 => ["A" => "Okuldaki kurslar, kulüpler (ör., spor, satranç, tiyatro) ve yarışmalar gibi etkinlikler hakkında bilgilenmek", "B" => "Nasıl ders çalışmam gerektiğini öğrenmek"],
    13 => ["A" => "Başkalarının ne hissettiklerini ve ne düşündüklerini anlamak", "B" => "Derslerde zorlandığımda bile başarılı olacağıma inanmak"],
    14 => ["A" => "HAYIR! diyebilmeyi öğrenmek", "B" => "Kendi hedeflerim doğrultusunda çalışmayı öğrenmek"],
    15 => ["A" => "Ders çalışmak ve oyun oynamak için zamanı planlamayı öğrenmek", "B" => "Zorlandığım konularda doğru kişilerden yardım istemek (ör., zorbalığa uğradığımda bir yetişkinden yardım istemek; dersi anlamadığımda öğretmenden yardım istemek)"]
];


$form_data = $_POST;
$form_error = null;
$validation_errors = [];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $parent_name = isset($_POST['parent_name']) ? trim(htmlspecialchars($_POST['parent_name'], ENT_QUOTES, 'UTF-8')) : '';
    $child_name_form = isset($_POST['child_name']) ? trim(htmlspecialchars($_POST['child_name'], ENT_QUOTES, 'UTF-8')) : '';
    $child_class = isset($_POST['child_class']) ? trim(htmlspecialchars($_POST['child_class'], ENT_QUOTES, 'UTF-8')) : '';
    $child_school_number = isset($_POST['child_school_number']) ? trim(htmlspecialchars($_POST['child_school_number'], ENT_QUOTES, 'UTF-8')) : '';
    $participant_email_placeholder = "anonim-" . uniqid() . "@site.com";

    $answers = isset($_POST['answers']) && is_array($_POST['answers']) ? $_POST['answers'] : [];
    
    if (empty($parent_name)) {
        $validation_errors['parent_name'] = "Veli Ad Soyad alanı zorunludur.";
    }
    if (empty($child_name_form)) {
        $validation_errors['child_name'] = "Çocuğun Adı Soyadı alanı zorunludur.";
    }
    if (empty($child_class)) {
        $validation_errors['child_class'] = "Çocuğun Sınıfı alanı zorunludur.";
    }
    if (empty($child_school_number)) {
        $validation_errors['child_school_number'] = "Çocuğun Okul Numarası alanı zorunludur.";
    }

    $all_questions_answered_correctly = true;
    if (count($db_questions_raw) != count($answers)) {
        $all_questions_answered_correctly = false;
    }

    foreach ($db_questions_raw as $db_q) {
        $question_actual_id = $db_q['id'];
        if (!isset($answers[$question_actual_id]) || empty($answers[$question_actual_id])) {
            $all_questions_answered_correctly = false;
            $validation_errors['question_' . $question_actual_id] = "Bu soru yanıtlanmalıdır.";
        } else {
            $submitted_option_text = $answers[$question_actual_id];
            $current_question_details = $db_questions_map[$question_actual_id] ?? null;
            if ($current_question_details) {
                $question_number_for_options = $current_question_details['question_number'];
                $valid_options_for_this_question = $survey_specific_options[$question_number_for_options] ?? [];
                if (!in_array($submitted_option_text, array_values($valid_options_for_this_question))) {
                    $all_questions_answered_correctly = false;
                    $validation_errors['question_' . $question_actual_id] = "Geçersiz bir seçenek gönderildi.";
                }
            } else {
                 $all_questions_answered_correctly = false;
                 $validation_errors['question_' . $question_actual_id] = "Soru detayı bulunamadı.";
            }
        }
    }

    if (!$all_questions_answered_correctly && !isset($validation_errors['general_questions'])) {
         $validation_errors['general_questions'] = "Lütfen tüm anket sorularını doğru şekilde yanıtlayın.";
    }

    if (empty($validation_errors)) {
        try {
            $pdo->beginTransaction();

            $participant_description_data = [
                'child_name' => $child_name_form,
                'child_school_number' => $child_school_number
            ];
            $participant_description_json = json_encode($participant_description_data);

            $participant_stmt = $pdo->prepare(
                "INSERT INTO survey_participants (name, email, survey_id, admin_id, class, description, created_at) 
                 VALUES (:name, :email, :survey_id, :admin_id, :class, :description, NOW())"
            );
            $participant_stmt->bindParam(':name', $parent_name, PDO::PARAM_STR);
            $participant_stmt->bindParam(':email', $participant_email_placeholder, PDO::PARAM_STR);
            $participant_stmt->bindParam(':survey_id', $survey_id, PDO::PARAM_INT);
            $participant_stmt->bindParam(':admin_id', $survey_owner_id, PDO::PARAM_INT);
            $participant_stmt->bindParam(':class', $child_class, PDO::PARAM_STR);
            $participant_stmt->bindParam(':description', $participant_description_json, PDO::PARAM_STR);
            $participant_stmt->execute();
            $participant_id = $pdo->lastInsertId();

            if (!$participant_id) {
                throw new PDOException("Katılımcı ID'si oluşturulamadı.");
            }

            foreach ($answers as $question_actual_id => $submitted_option_text) {
                $sort_order_to_save = $db_questions_map[$question_actual_id]['sort_order'] ?? null;

                if ($sort_order_to_save !== null) {
                    $insert_answer_stmt = $pdo->prepare(
                        "INSERT INTO survey_answers (survey_id, question_id, answer_text, user_id, participant_id, created_at) 
                         VALUES (:survey_id, :question_id, :answer_text, :user_id, :participant_id, NOW())"
                    );
                    $insert_answer_stmt->bindParam(':survey_id', $survey_id, PDO::PARAM_INT);
                    $insert_answer_stmt->bindParam(':question_id', $sort_order_to_save, PDO::PARAM_INT);
                    $insert_answer_stmt->bindParam(':answer_text', $submitted_option_text, PDO::PARAM_STR);
                    $insert_answer_stmt->bindValue(':user_id', null, PDO::PARAM_NULL);
                    $insert_answer_stmt->bindParam(':participant_id', $participant_id, PDO::PARAM_INT);
                    $insert_answer_stmt->execute();
                } else {
                    error_log("Hata: survey_questions.id '$question_actual_id' için sort_order bulunamadı.");
                }
            }

            $pdo->commit();
            header("Location: tamamlandi.php?survey_id=" . $survey_id . "&message=success&pid=" . $participant_id);
            exit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Anket cevapları kaydedilirken PDOException (Anket ID: $survey_id): " . $e->getMessage());
            $form_error = "Cevaplarınız kaydedilirken bir veritabanı sorunu oluştu. Lütfen tekrar deneyin.";
        }
    } else {
        $form_error = "Lütfen formdaki işaretli alanları kontrol edin ve tüm soruları yanıtlayın.";
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($survey_title); ?> - Anket Sistemi</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f7fafc; }
        .survey-container { max-width: 800px; margin: 2rem auto; padding: 2rem; background-color: white; border-radius: 0.5rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06); }
        .question-card { background-color: #fff; padding: 1.5rem; margin-bottom: 1.5rem; border-radius: 0.375rem; border: 1px solid #e5e7eb; }
        .question-card.validation-error-card { border-color: #ef4444; }
        .question-main-title { font-size: 1.125rem; font-weight: 600; margin-bottom: 0.5rem; color: #1f2937; }
        .option-container { display: flex; flex-direction: column; gap: 0.75rem; margin-top: 1rem;}
        .option-label { display: block; padding: 0.75rem 1rem; border: 1px solid #d1d5db; border-radius: 0.375rem; cursor: pointer; transition: background-color 0.2s ease-in-out, border-color 0.2s ease-in-out; background-color: #f9fafb; position: relative; }
        .option-label:hover { background-color: #f3f4f6; border-color: #9ca3af; }
        .option-label input[type="radio"] { opacity: 0; position: absolute; width: 1px; height: 1px; }
        .option-label span.option-text { margin-left: 0; display: inline-block; }
        .option-label::before { content: ''; display: inline-block; width: 1rem; height: 1rem; border-radius: 50%; border: 2px solid #d1d5db; margin-right: 0.75rem; vertical-align: middle; transition: border-color 0.2s, background-color 0.2s; }
        label.option-label.selected { background-color: #dbeafe; border-color: #2563eb; }
        label.option-label.selected::before { background-color: #2563eb; border-color: #2563eb; }
        .option-text { font-size: 0.95rem; color: #374151; }
        .submit-btn { background-color: #2563eb; color: white; padding: 0.75rem 1.5rem; border-radius: 0.375rem; font-weight: 500; transition: background-color 0.2s ease-in-out; border: none; cursor: pointer; width: 100%; }
        .submit-btn:hover { background-color: #1d4ed8; }
        .submit-btn:disabled { background-color: #9ca3af; cursor: not-allowed; }
        .back-btn { display: inline-block; margin-bottom: 1rem; padding: 0.5rem 1rem; background-color: #6b7280; color: white; border-radius: 0.375rem; text-decoration: none; font-size: 0.875rem; }
        .back-btn:hover { background-color: #4b5563; }
        .progress-bar-container { width: 100%; background-color: #e5e7eb; border-radius: 0.375rem; margin-bottom: 1.5rem; overflow: hidden; }
        .progress-bar { height: 1.25rem; background-color: #3b82f6; width: 0%; text-align: center; line-height: 1.25rem; color: white; font-size: 0.75rem; border-radius: 0.375rem; transition: width 0.3s ease-in-out; }
        .error-message-global { background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; padding: 1rem; border-radius: 0.375rem; margin-bottom: 1.5rem; font-size:0.9em; }
        .survey-description { background-color: #eff6ff; border-left: 4px solid #3b82f6; padding: 1rem; margin-bottom: 1.5rem; color: #1e40af; border-radius: 0.25rem; }
        .info-section { margin-bottom: 2rem; padding: 1.5rem; background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 0.5rem; }
        .info-section h3 { font-size: 1.25rem; font-semibold: 600; color: #111827; margin-bottom: 1rem; border-bottom: 1px solid #e5e7eb; padding-bottom: 0.5rem;}
        .info-grid { display: grid; grid-template-columns: repeat(1, minmax(0, 1fr)); gap: 1rem; }
        @media (min-width: 768px) { .info-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        .info-grid label { display: block; font-weight: 500; color: #374151; margin-bottom: 0.25rem; font-size: 0.875rem;}
        .info-grid input[type="text"], .info-grid select, .info-grid textarea { width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #d1d5db; border-radius: 0.375rem; box-shadow: sm; font-size: 0.875rem; }
        .info-grid input[type="text"]:focus, .info-grid select:focus, .info-grid textarea:focus { outline: 2px solid transparent; outline-offset: 2px; border-color: #2563eb; ring: 1px solid #2563eb; }
        .info-grid input.validation-error-input, .info-grid select.validation-error-input { border-color: #ef4444; }
        .required-star { color: #ef4444; margin-left: 2px; }
        .validation-error-text { color: #ef4444; font-size: 0.75rem; margin-top: 0.25rem; min-height: 1em; }
    </style>
</head>
<body>
    <div class="survey-container">
        <h1 class="text-3xl font-bold text-center mb-2 text-gray-800"><?php echo htmlspecialchars($survey_title); ?></h1>
        
        <?php if (!empty($survey_description)): ?>
            <div class="survey-description">
                <p class="text-sm"><?php echo nl2br(htmlspecialchars($survey_description)); ?></p>
            </div>
        <?php endif; ?>

        <?php if ($form_error && !empty($validation_errors)): ?>
            <div class="error-message-global" role="alert" id="formErrorAlertGlobal"><?php echo htmlspecialchars($form_error); ?></div>
        <?php endif; ?>


        <div class="progress-bar-container">
            <div class="progress-bar" id="progressBar">0%</div>
        </div>

        <form id="surveyForm" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST" novalidate>
            
            <div class="info-section">
                <h3>Veli ve Öğrenci Bilgileri</h3>
                <div class="info-grid">
                    <div>
                        <label for="parent_name">Veli Ad Soyadınız <span class="required-star">*</span></label>
                        <input type="text" id="parent_name" name="parent_name" required placeholder="Adınız ve soyadınız" value="<?php echo htmlspecialchars($form_data['parent_name'] ?? ''); ?>" class="<?php echo isset($validation_errors['parent_name']) ? 'validation-error-input' : ''; ?>">
                        <p id="parent_name_error" class="validation-error-text"><?php echo htmlspecialchars($validation_errors['parent_name'] ?? ''); ?></p>
                    </div>
                    <div>
                        <label for="child_name">Çocuğunuzun Adı Soyadı <span class="required-star">*</span></label>
                        <input type="text" id="child_name" name="child_name" required placeholder="Çocuğunuzun adı ve soyadı" value="<?php echo htmlspecialchars($form_data['child_name'] ?? ''); ?>" class="<?php echo isset($validation_errors['child_name']) ? 'validation-error-input' : ''; ?>">
                         <p id="child_name_error" class="validation-error-text"><?php echo htmlspecialchars($validation_errors['child_name'] ?? ''); ?></p>
                    </div>
                     <div>
                        <label for="child_class">Çocuğunuzun Sınıfı <span class="required-star">*</span></label>
                        <input type="text" id="child_class" name="child_class" required placeholder="Örn: 4/A" value="<?php echo htmlspecialchars($form_data['child_class'] ?? ''); ?>" class="<?php echo isset($validation_errors['child_class']) ? 'validation-error-input' : ''; ?>">
                        <p id="child_class_error" class="validation-error-text"><?php echo htmlspecialchars($validation_errors['child_class'] ?? ''); ?></p>
                    </div>
                    <div>
                        <label for="child_school_number">Çocuğunuzun Okul Numarası <span class="required-star">*</span></label>
                        <input type="text" id="child_school_number" name="child_school_number" required placeholder="Okul numarası" value="<?php echo htmlspecialchars($form_data['child_school_number'] ?? ''); ?>" class="<?php echo isset($validation_errors['child_school_number']) ? 'validation-error-input' : ''; ?>">
                        <p id="child_school_number_error" class="validation-error-text"><?php echo htmlspecialchars($validation_errors['child_school_number'] ?? ''); ?></p>
                    </div>
                </div>
            </div>

            <h3 class="text-xl font-semibold text-gray-700 mb-4 mt-6">Anket Soruları</h3>
            <?php if (isset($validation_errors['general_questions'])): ?>
                <p class="validation-error-text mb-4 text-center text-red-600 font-semibold"><?php echo htmlspecialchars($validation_errors['general_questions']); ?></p>
            <?php endif; ?>

            <?php foreach ($db_questions_raw as $db_question): ?>
                <?php
                $question_number_from_db = $db_question['question_number'];
                $current_options_set = $survey_specific_options[$question_number_from_db] ?? null;
                $q_id = $db_question['id'];
                $q_error_text = $validation_errors['question_' . $q_id] ?? '';
                ?>
                <?php if ($current_options_set): ?>
                <div class="question-card <?php echo !empty($q_error_text) ? 'validation-error-card' : ''; ?>" id="question-<?php echo $q_id; ?>">
                    <p class="question-main-title"><?php echo htmlspecialchars($db_question['question_text']); ?> <span class="required-star">*</span></p>
                    <div class="option-container">
                        <?php foreach ($current_options_set as $option_key => $option_text): ?>
                            <label class="option-label <?php echo (isset($form_data['answers'][$q_id]) && $form_data['answers'][$q_id] == $option_text) ? 'selected' : ''; ?>">
                                <input type="radio" 
                                       name="answers[<?php echo $q_id; ?>]" 
                                       value="<?php echo htmlspecialchars($option_text); ?>"
                                       required
                                       <?php if (isset($form_data['answers'][$q_id]) && $form_data['answers'][$q_id] == $option_text) { echo ' checked'; } ?> >
                                <span class="option-text"><?php echo htmlspecialchars($option_text); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p id="question_<?php echo $q_id; ?>_error" class="validation-error-text"><?php echo htmlspecialchars($q_error_text); ?></p>
                </div>
                <?php else: ?>
                <div class="question-card error-message-global">
                    <p class="question-main-title"><?php echo htmlspecialchars($db_question['question_text']); ?></p>
                    <p>Bu soru için seçenekler tanımlanmamış (Soru No: <?php echo $question_number_from_db; ?>).</p>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <div class="mt-8">
                <button type="submit" id="submitButton" class="submit-btn">
                    <i class="fas fa-paper-plane mr-2"></i>Anketi Gönder
                </button>
            </div>
        </form>
    </div>

    <script>
        const form = document.getElementById('surveyForm');
        const progressBar = document.getElementById('progressBar');
        const questionCards = form.querySelectorAll('.question-card:not(.error-message-global)');
        const totalQuestions = questionCards.length;
        const submitButton = document.getElementById('submitButton');

        function updateProgressBar() {
            if (totalQuestions === 0) {
                 progressBar.style.width = '0%';
                 progressBar.textContent = '0%';
                 return;
            }
            let answeredCount = 0;
            questionCards.forEach(card => {
                const radios = card.querySelectorAll('input[type="radio"]');
                let isCardAnswered = false;
                radios.forEach(radio => {
                    if (radio.checked) isCardAnswered = true;
                });
                if (isCardAnswered) answeredCount++;
            });
            const progress = totalQuestions > 0 ? (answeredCount / totalQuestions) * 100 : 0;
            progressBar.style.width = progress + '%';
            progressBar.textContent = Math.round(progress) + '%';
        }

        form.addEventListener('change', function(event) {
            if (event.target.type === 'radio') {
                const groupName = event.target.name;
                document.querySelectorAll(`input[type="radio"][name="${groupName}"]`).forEach(radio => {
                    radio.closest('.option-label').classList.remove('selected');
                });
                if (event.target.checked) {
                    event.target.closest('.option-label').classList.add('selected');
                }
            }
            updateProgressBar();
        });
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('input[type="radio"]:checked').forEach(radio => {
                 radio.closest('.option-label').classList.add('selected');
            });
            updateProgressBar();
        });


        form.addEventListener('submit', function(event) {
            let allValid = true;
            let firstErrorElement = null;

            const globalErrorAlertPHP = document.getElementById('formErrorAlertGlobal');
            if(globalErrorAlertPHP) globalErrorAlertPHP.style.display = 'none';

            function validateInput(inputId, errorId, errorMessage) {
                const input = document.getElementById(inputId);
                const errorP = document.getElementById(errorId);
                input.classList.remove('validation-error-input');
                if (errorP) errorP.textContent = '';
                if (!input.value.trim()) {
                    allValid = false;
                    input.classList.add('validation-error-input');
                    if (errorP) errorP.textContent = errorMessage;
                    if (!firstErrorElement) firstErrorElement = input;
                }
            }

            validateInput('parent_name', 'parent_name_error', 'Veli ad soyadı zorunludur.');
            validateInput('child_name', 'child_name_error', 'Çocuğun adı soyadı zorunludur.');
            validateInput('child_class', 'child_class_error', 'Çocuğun sınıfı zorunludur.');
            validateInput('child_school_number', 'child_school_number_error', 'Çocuğun okul numarası zorunludur.');

            questionCards.forEach(card => {
                const questionId = card.id.replace('question-', '');
                const qError = document.getElementById('question_' + questionId + '_error');
                card.classList.remove('validation-error-card'); 
                if(qError) qError.textContent = '';

                const radios = card.querySelectorAll('input[type="radio"]');
                let cardAnswered = false;
                radios.forEach(radio => {
                    if (radio.checked) cardAnswered = true;
                });
                if (!cardAnswered) {
                    allValid = false;
                    card.classList.add('validation-error-card'); 
                    if(qError) qError.textContent = 'Lütfen bu soruyu yanıtlayın.';
                    if (!firstErrorElement && radios.length > 0) firstErrorElement = radios[0].closest('.option-label') || card.querySelector('.question-main-title');
                }
            });

            if (!allValid) {
                event.preventDefault();
                if (globalErrorAlertPHP) {
                     globalErrorAlertPHP.textContent = 'Lütfen formdaki işaretli alanları kontrol edin ve tüm soruları yanıtlayın.';
                     globalErrorAlertPHP.style.display = 'block';
                } else { 
                    alert('Lütfen formdaki işaretli alanları kontrol edin ve tüm soruları yanıtlayın.');
                }

                if (firstErrorElement) {
                    firstErrorElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    if (firstErrorElement.tagName === 'LABEL') {
                        const radioInside = firstErrorElement.querySelector('input[type="radio"]');
                        if (radioInside) radioInside.focus({preventScroll:true}); else firstErrorElement.focus({preventScroll:true});
                    } else {
                         firstErrorElement.focus({preventScroll:true}); 
                    }
                }
            } else {
                 if(submitButton) {
                     submitButton.disabled = true;
                     submitButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Gönderiliyor...';
                 }
            }
        });
    </script>

</body>
</html>
