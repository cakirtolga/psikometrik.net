<?php
// --- Güçlendirilmiş Hata Raporlama Ayarları ---
// Bu ayarlar, hata logu oluşmuyorsa hataları doğrudan sayfada görmenizi sağlar.
// Canlı ortamda display_errors KAPALI olmalıdır!
ini_set('display_errors', 1); // Hataları ekranda göster
ini_set('display_startup_errors', 1); // Başlangıç hatalarını ekranda göster
error_reporting(E_ALL); // Tüm PHP hatalarını raporla
// ini_set('log_errors', 1); // Hataları log dosyasına yaz (error_log() için gerekli)
// ini_set('error_log', '/path/to/your/php_error.log'); // Hata log dosyasının yolu (gerekirse belirtin)
// Yukarıdaki log_errors ve error_log satırları, eğer error_log() çıktılarını göremiyorsanız
// sunucu yapılandırmanıza bağlı olarak yardımcı olabilir.

// Start the session (Oturum başlatılır)
session_start();

// Set content type and charset explicitly for the response (Yanıtın içerik türü ve karakter seti ayarlanır)
header('Content-Type: text/html; charset=utf-8');

// Include database configuration using __DIR__ (Veritabanı yapılandırma dosyası dahil edilir)
// Ensure your config.php establishes a UTF-8 connection
require_once __DIR__ . '/src/config.php';

// Define the survey ID for this specific file (Bu dosya için anket ID'si belirlenir)
$surveyId = 6; // Anket ID'si 6 olarak ayarlandı

// --- Admin ID'sini URL'den Al ve Doğrula (4. Anket Şablonu Mantığı) ---
// Bu anketin bir admin'e atanabilmesi için admin_id URL'de gelmelidir.
$adminId = null;
// URL'deki admin_id parametresi kontrol edilir, pozitif tam sayı olduğu doğrulanır
if (isset($_GET['admin_id']) && filter_var($_GET['admin_id'], FILTER_VALIDATE_INT) !== false && $_GET['admin_id'] > 0) {
    $potentialAdminId = (int)$_GET['admin_id'];

    // Veritabanında bu ID'ye sahip bir admin var mı kontrol et
    try {
        $adminStmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
        $adminStmt->execute([$potentialAdminId]);
        $admin = $adminStmt->fetch(PDO::FETCH_ASSOC);

        if ($admin) {
            $adminId = $admin['id']; // Admin bulundu, ID'yi kullanabiliriz
             error_log("Admin ID validated from URL for Survey 6: " . $adminId);
        } else {
            $error = 'Geçersiz veya bulunamayan yönetici ID\'si.';
            error_log($error . " - Attempted ID for Survey 6: " . $potentialAdminId);
             // adminId null kalır
        }
    } catch (PDOException $e) {
        $error = 'Yönetici bilgisi alınırken veritabanı hatası: ' . $e->getMessage();
        error_log("Admin ID validation PDO Exception for Survey 6: " . $e->getMessage());
         // adminId null kalır
    }
} else {
    $error = 'Yönetici ID\'si eksik veya geçersiz.';
    error_log($error . " - Received admin_id for Survey 6: " . ($_GET['admin_id'] ?? 'Not set'));
     // adminId null kalır
}

// Eğer admin ID'si geçerli değilse, anket formunu gösterme ve hata mesajı ile durma
if (is_null($adminId)) {
    die('Erişim reddedildi: ' . ($error ?? 'Yönetici bilgisi doğrulanamadı.'));
}


// Fetch survey information for Survey ID 6 (Anket bilgileri çekilir)
$stmt = $pdo->prepare("SELECT * FROM surveys WHERE id = ?");
$stmt->execute([$surveyId]);
$survey = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$survey) {
    // If survey not found, set an error and stop execution
    $error = 'Anket bulunamadı.';
    error_log($error); // Hata loglanır
    die($error); // Script durdurulur ve hata gösterilir
}

// Fetch questions for Survey ID 6 ordered by sort_order (Anketin soruları çekilir)
$stmt = $pdo->prepare("SELECT * FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC");
$stmt->execute([$surveyId]);
$questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalQuestions = count($questions); // Toplam soru sayısı
$questionsPerPage = 5; // Sayfa başına soru sayısı (şablon ile tutarlı)
$totalPages = ceil($totalQuestions / $questionsPerPage); // Toplam sayfa sayısı hesaplanır

// --- POST İsteği İşleme (4. Anket Şablonu Mantığı) ---
// Form gönderildiğinde tüm veriler (Ad, Sınıf, Tüm Cevaplar) tek seferde işlenir.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Bu log, POST bloğuna girilip girilmediğini kontrol etmek için kritik öneme sahiptir.
    error_log("--- POST request received for Survey 6 ---");

    // Sanitize and get participant details and answers (Katılımcı bilgileri ve cevaplar alınır)
    // Use $_POST directly as all data is submitted at the end
    $name = trim($_POST['student_name'] ?? ''); // student_name olarak alınır (4. Anket HTML'i ile uyumlu)
    $class = trim($_POST['student_class'] ?? ''); // student_class olarak alınır (4. Anket HTML'i ile uyumlu)
    $answers = $_POST['answers'] ?? []; // Cevaplar alınır (null coalesce ile güvenlik)

    // Log received POST data for debugging (Alınan POST verileri loglanır)
    error_log("POST Data Received for Survey 6: " . print_r($_POST, true));
    error_log("Name: " . $name);
    error_log("Class: " . $class);
    error_log("Answers: " . print_r($answers, true));

    // --- Server-side Validation (4. Anket Şablonu Mantığı) ---
    // Check if name and class are empty (Ad ve sınıf boş mu kontrolü)
    if (empty($name) || empty($class)) {
        $error = "Lütfen Ad Soyad ve Sınıf bilgilerinizi girin.";
        error_log("Validation failed for Survey 6: Name or Class is empty.");
    }
    // Check if the number of received answers matches the total number of questions (Tüm sorular yanıtlanmış mı kontrolü)
    // This ensures all questions across all pages have been answered
    else if (count($answers) !== $totalQuestions) {
         $error = "Lütfen tüm soruları yanıtlayın. Yanıtlanan soru sayısı: " . count($answers) . ", Toplam soru sayısı: " . $totalQuestions;
         error_log("Validation failed for Survey 6: Incorrect number of answers received. Expected: " . $totalQuestions . ", Received: " . count($answers));
         // Log missing question IDs if possible (Eksik soru ID'leri loglanır)
         $answeredQuestionIds = array_keys($answers);
         $allQuestionIds = array_column($questions, 'id');
         $missingQuestionIds = array_diff($allQuestionIds, $answeredQuestionIds);
         if (!empty($missingQuestionIds)) {
             error_log("Missing question IDs for Survey 6: " . implode(', ', $missingQuestionIds));
         }

    }
    // If validation passes, proceed with database insertion (Doğrulama başarılıysa veritabanına kayıt yapılır)
    else {
        // Ensure adminId is available before attempting insertion (Kayıt öncesi admin ID'nin geçerli olduğundan emin olunur)
        if (is_null($adminId)) {
             $error = "Kayıt işlemi için yönetici bilgisi eksik veya geçersiz.";
             error_log("Insertion blocked for Survey 6: Admin ID is null.");
        } else {
            try {
                // Start a transaction for atomic database operations (Veritabanı işlemleri için transaction başlatılır)
                $pdo->beginTransaction();
                error_log("Transaction started for Survey 6.");

                // --- Insert Participant --- (Katılımcı kaydı yapılır)
                error_log("Attempting to insert participant for Survey 6: Name=" . $name . ", Class=" . $class . ", SurveyId=" . $surveyId . ", AdminId=" . $adminId);
                // Use the validated $adminId here (Doğrulanan $adminId kullanılır)
                $stmt = $pdo->prepare("INSERT INTO survey_participants (name, class, survey_id, admin_id) VALUES (?, ?, ?, ?)");
                $participantInsertSuccess = $stmt->execute([$name, $class, $surveyId, $adminId]); // $adminId kullanılır

                $participantId = $pdo->lastInsertId(); // Eklenen katılımcının ID'si alınır

                if ($participantInsertSuccess) {
                     error_log("Participant inserted successfully for Survey 6 with ID: " . $participantId);
                } else {
                     error_log("Participant insert failed for Survey 6. PDO ErrorInfo: " . print_r($stmt->errorInfo(), true));
                     // Optionally throw an exception here to trigger the catch block
                     // throw new Exception("Participant insert failed");
                }


                // --- Insert Answers --- (Cevaplar kaydedilir)
                error_log("Attempting to insert answers for Survey 6, participant ID: " . $participantId);
                $stmt = $pdo->prepare("INSERT INTO survey_answers (participant_id, question_id, answer_text) VALUES (?, ?, ?)");
                $allAnswersInserted = true;
                foreach ($answers as $qid => $answer) {
                     // Ensure qid is numeric and answer is a string before inserting (Soru ID'si ve cevap doğrulanır)
                    if (is_numeric($qid) && is_string($answer)) {
                        $answerInsertSuccess = $stmt->execute([$participantId, $qid, $answer]);
                        if ($answerInsertSuccess) {
                             error_log("Inserted answer for Survey 6, QID " . $qid . ": " . $answer);
                        } else {
                             error_log("Answer insert failed for Survey 6, QID " . $qid . ". PDO ErrorInfo: " . print_r($stmt->errorInfo(), true));
                             $allAnswersInserted = false; // En az bir cevabın başarısız olduğu işaretlenir
                             // Optionally continue or break based on desired behavior on answer insert failure
                        }
                    } else {
                        error_log("Skipping invalid answer data for Survey 6: QID=" . $qid . ", Answer=" . $answer);
                        $allAnswersInserted = false; // Geçersiz veri bulunduğu işaretlenir
                    }
                }

                // --- Commit or Rollback --- (Transaction commit veya rollback yapılır)
                // Only commit if participant was inserted and all answers were attempted (or successfully inserted based on logic)
                if ($participantInsertSuccess && $allAnswersInserted) { // Katılımcı eklendiyse VE tüm cevaplar başarılıysa commit yapılır
                     $pdo->commit();
                     error_log("Transaction committed for Survey 6. Redirecting to tamamlandi.php");

                     // Check if tamamlandi.php exists before redirecting (tamamlandi.php var mı kontrolü)
                     if (file_exists('tamamlandi.php')) {
                          header('Location: tamamlandi.php'); // Başarı sayfasına yönlendirilir
                          exit();
                     } else {
                          $error = "Anket başarıyla tamamlandı, ancak 'tamamlandi.php' sayfası bulunamadı.";
                          error_log($error);
                     }

                } else {
                     $pdo->rollBack(); // Transaction geri alınır
                     $error = "Kayıt işlemi tamamlanamadı. Lütfen logları kontrol edin."; // Genel hata mesajı
                     error_log("Transaction rolled back for Survey 6 due to insertion failure.");
                }


            } catch (PDOException $e) {
                 // Rollback transaction on database error (Veritabanı hatasında rollback)
                $pdo->rollBack();
                $error = "Veritabanı hatası oluştu: " . $e->getMessage(); // PDO hata mesajı gösterilir (debugging için)
                error_log("PDO Exception for Survey 6: " . $e->getMessage());
                // In production, you might want a more generic error message:
                // $error = "Anket kaydedilirken bir hata oluştu.";
            } catch (Exception $e) {
                 // Catch any other unexpected exceptions (Diğer beklenmeyen hatalar yakalanır)
                 $pdo->rollBack(); // Rollback denenir
                 $error = "Beklenmeyen bir hata oluştu: " . $e->getMessage(); // Hata mesajı gösterilir
                 error_log("General Exception for Survey 6: " . $e->getMessage()); // Hata loglanır
            }
        }
    }
    // If $error is set, the script will continue to render the HTML with the error message. (Eğer $error ayarlandıysa, script hata mesajı ile HTML'i render etmeye devam eder)
}

// Group questions for pagination (using array_chunk) (Sorular sayfalama için gruplandırılır)
$groups = array_chunk($questions, $questionsPerPage);
$totalGroups = count($groups); // Toplam grup/sayfa sayısı
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($survey['title']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* General body styling */
        body {
            font-family: sans-serif;
            line-height: 1.6;
            background-color: #f0fdf4; /* Light green background */
            color: #2c3e50; /* Dark text color as requested */
            margin: 0;
            padding: 20px;
            text-align: center; /* Center body content (the container) */
        }

        /* Container styling - Removed white background */
        .container {
            max-width: 800px; /* Adjusted max-width slightly for this survey's original layout */
            margin: 40px auto; /* Centers the container horizontally */
            /* background: #ffffff; /* White background for the content area - REMOVED */
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); /* Subtle shadow */
            display: block; /* Ensure it's a block element */
            text-align: left; /* Align text inside the container to the left */
             /* Optional: Add a very subtle background if removing white is too stark */
             /* background-color: rgba(255, 255, 255, 0.8); */
        }

        /* Page/Question group styling */
        .question-group {
            display: none; /* Hide pages by default */
        }
        .question-group.active {
            display: block; /* Show the active page */
        }

        /* Style for the answer buttons (from Survey 6 theme) */
        .question-button {
            background: #f0fdf4; /* Very light green */
            border: 2px solid #bbf7d0; /* Light green border */
            color: #15803d; /* Dark green text - Adjusted to match screenshot */
            padding: 10px 18px; /* Slightly reduced padding */
            border-radius: 8px;
            transition: all 0.2s ease-in-out; /* Smooth transition */
            /* Removed flex: 1 and min-width */
            /* flex-basis: auto; Allow flex items to shrink/grow based on content */
            text-align: center; /* Center text */
            cursor: pointer; /* Pointer cursor */
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); /* Subtle shadow */
            /* Ensure buttons are treated as flex items */
            display: inline-block; /* Or use flex item properties from parent */
        }

        /* Active state for selected answer button (More aesthetic green) */
        .question-button.active {
            background: #22c55e; /* Green */
            border-color: #16a34a; /* Darker green border */
            color: white; /* White text */
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06); /* More prominent shadow */
            transform: translateY(-2px); /* Slight lift effect */
        }

        /* Hover state for answer buttons */
        .question-button:hover:not(.active) {
            background-color: #dcfce7; /* Slightly darker light green on hover */
            border-color: #a7f3d0; /* Slightly darker green border on hover */
        }


        /* Navigation button styling (from Survey 6 theme) */
        .nav-btn {
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.2s ease-in-out; /* Smooth transition */
            cursor: pointer;
            border: none; /* Remove default border */
        }

        /* Style for the next button */
        .nav-btn.next {
            background: #15803d; /* Dark green */
            color: white;
        }

        .nav-btn.next:hover {
             background: #0b532c; /* Even darker green on hover */
        }

        /* Style for the submit button */
        .nav-btn.submit {
            background: #2563eb; /* Blue */
            color: white;
        }

         .nav-btn.submit:hover {
             background: #1d4ed8; /* Darker blue on hover */
        }

        /* Style for the previous button (from Survey 6 theme) - HIDDEN */
        .nav-btn.prev {
            /* display: none; */ /* Handled by JS hidden class */
            background: #e5e7eb; /* Light gray background */
            color: #374151; /* Dark gray text */
        }
        .nav-btn.prev:hover {
             background: #d1d5db; /* Slightly darker gray on hover */
        }

        /* Utility class to hide elements */
        .hidden { display: none; }

        /* Question block styling */
        .question {
            margin-bottom: 30px;
        }

        /* Options container (flex for side-by-side, wrap for responsiveness) */
        .options {
             display: flex;
             flex-wrap: wrap; /* Allow wrapping on smaller screens */
             gap: 10px; /* Space between option buttons */
             margin-top: 10px;
        }
         .options .question-button {
             margin: 0; /* Remove individual button margin when using gap on flex container */
         }


        /* Info section inputs (from Survey 4 code, adapted) */
        .info label {
            display: block; /* Make labels block elements */
            margin-bottom: 5px;
            font-weight: 600;
        }
        .info input {
            display: block; /* Make inputs block elements to stack */
            margin: 0 0 15px 0; /* Adjusted margin */
            padding: 8px;
            width: 100%; /* Full width */
            box-sizing: border-box;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 1em;
            color: #2c3e50; /* Ensure input text color matches body */
        }


        /* Error message styling (using Tailwind classes) */
        .error-message {
             color: #b91c1c; /* Tailwind red-700 */
             background-color: #fee2e2; /* Tailwind red-100 */
             padding: 1rem;
             border-radius: 0.5rem;
             margin-bottom: 1.5rem;
             border: 1px solid #fca5a5; /* Light red border */
             font-weight: bold; /* Make text bold */
        }


        /* Tailwind-like utilities used in HTML */
        .mx-auto { margin-left: auto; margin-right: auto; }
        .mt-10 { margin-top: 2.5rem; }
        .p-8 { padding: 2rem; }
        .rounded-xl { border-radius: 0.75rem; }
        .shadow-sm { box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); }
        .text-2xl { font-size: 1.5rem; }
        .font-bold { font-weight: 700; }
        .text-center { text-align: center; }
        .mb-8 { margin-bottom: 2rem; }
        .pb-4 { padding-bottom: 1rem; }
        .border-b-2 { border-bottom-width: 2px; }
        .border-[#dcfce7] { border-color: #dcfce7; }
        /* mb-6 defined above */
        .block { display: block; }
        .font-semibold { font-weight: 600; }
        .mb-2 { margin-bottom: 0.5rem; }
        .w-full { width: 100%; }
        .p-3 { padding: 0.75rem; }
        .border { border-width: 1px; }
        /* rounded-lg defined above */
        .focus\:outline-none:focus { outline: 0; }
        .focus\:ring-2:focus { --tw-ring-offset-shadow: var(--tw-ring-inset) 0 0 0 var(--tw-ring-offset-width) var(--tw-ring-offset-color); --tw-ring-shadow: var(--tw-ring-inset) 0 0 0 calc(2px + var(--tw-ring-offset-width)) var(--tw-ring-color); box-shadow: var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow, 0 0 #0000); }
        .focus\:ring-[#bbf7d0]:focus { --tw-ring-color: #bbf7d0; }
        .text-lg { font-size: 1.125rem; }
        .mb-4 { margin-bottom: 1rem; }
        .flex { display: flex; }
        .gap-3 { gap: 0.75rem; }
        .flex-wrap { flex-wrap: wrap; }
        .justify-end { justify-content: flex-end; }
        .gap-4 { gap: 1rem; }
        .mt-8 { margin-top: 2rem; }
        .min-h-screen { min-height: 100vh; }


    </style>
</head>
<body class="bg-[#f0fdf4] min-h-screen p-4">
    <div class="max-w-2xl mx-auto rounded-xl shadow-sm p-8 mt-10 container">
        <h2 class="text-center text-2xl font-bold mb-6 pb-4 border-b-2 border-[#dcfce7]">
            <?php echo htmlspecialchars($survey['title']); ?>
        </h2>

        <?php if (!empty($error)): ?>
            <div class="error-message"><?= $error ?></div>
        <?php endif; ?>

        <form method="POST" id="surveyForm">

            <div id="personalInfo" class="info">
                <div class="mb-6">
                    <label for="studentName" class="block font-semibold mb-2">Ad Soyad:</label>
                    <input type="text" name="student_name" id="studentName" required
                           class="w-full p-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-[#bbf7d0]">
                </div>
                <div class="mb-8">
                    <label for="studentClass" class="block font-semibold mb-2">Sınıf:</label>
                    <input type="text" name="student_class" id="studentClass" required
                           class="w-full p-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-[#bbf7d0]">
                </div>
            </div>

            <div id="questionGroups">
                <?php
                $pageIndex = 0;
                // array_chunk questions into groups of 5, preserving keys (question IDs)
                foreach (array_chunk($questions, $questionsPerPage, true) as $groupIndex => $pageQuestions):
                ?>
                    <div class="question-group <?php echo $pageIndex === 0 ? 'active' : ''; ?>" data-group="<?php echo $pageIndex; ?>">
                        <?php
                        $questionIndexInGroup = 0; // Counter for question index within the group
                        foreach ($pageQuestions as $q):
                        ?>
                            <div class="mb-8 question">
                                <p class="text-lg font-semibold mb-4">
                                    <strong><?php echo ($groupIndex * $questionsPerPage) + $questionIndexInGroup + 1; ?>.</strong> <?= htmlspecialchars($q['question']) ?>
                                </p>
                                <div class="flex gap-3 mb-6 options">
                                    <?php
                                    // Use the specific options for Survey 6: 'Annem', 'Babam', 'İkisi de'
                                    $options = ['Annem', 'Babam', 'İkisi de'];
                                    foreach ($options as $option):
                                    ?>
                                        <button type="button"
                                                class="question-button"
                                                onclick="selectAnswer(<?= $q['id'] ?>, this)"
                                                data-value="<?= htmlspecialchars($option) ?>">
                                            <?= htmlspecialchars($option) ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                                <input type="hidden" name="answers[<?= $q['id'] ?>]" id="answer_<?= $q['id'] ?>" required>
                            </div>
                        <?php $questionIndexInGroup++; endforeach; // Increment the counter ?>
                    </div>
                <?php $pageIndex++; endforeach; ?>
            </div>

            <div class="flex justify-end gap-4 mt-8 navigation">
                 <button type="button" id="nextBtn" class="nav-btn next">İleri →</button>
                <button type="submit" id="submitBtn" class="nav-btn submit hidden">Gönder</button>
            </div>
        </form>
    </div>

    <script>
        const groups = document.querySelectorAll('.question-group');
        const personalInfo = document.getElementById('personalInfo');
        const nextBtn = document.getElementById('nextBtn');
        const submitBtn = document.getElementById('submitBtn');
        // prevBtn is not used in this version
        const nameInput = document.getElementById('studentName');
        const classInput = document.getElementById('studentClass');

        let current = 0; // Current page index (0-based)
        const totalGroups = groups.length; // Total number of pages

        console.log('Total question groups:', totalGroups); // Log total groups

        // Function to show a specific page (question group)
        function showPage(index) {
            console.log('Attempting to show group:', index, 'Current group:', current, 'Total groups:', totalGroups); // Log attempt

            // Bounds checking for index
            if (index < 0 || index >= totalGroups) {
                console.error('Invalid page index:', index);
                return false; // Prevent page change
            }

            const isLastPage = index === totalGroups - 1;
            console.log('Is last page (for index ' + index + '):', isLastPage); // Log if it's the last page

            // --- Validation before moving forward ---
            if (index > current) { // Only validate when moving forward
                // Validate name and class on the first page
                if (current === 0) {
                    if (nameInput.value.trim() === '' || classInput.value.trim() === '') {
                        alert('Lütfen ad ve sınıf giriniz.');
                        return false; // Prevent page change
                    }
                }

                 // Check if all questions on the current page are answered
                // Use the correct selector for questions within the current group
                const currentPageQuestions = groups[current].querySelectorAll('.question');
                let allAnswered = true;
                currentPageQuestions.forEach(questionDiv => {
                    const hiddenInput = questionDiv.querySelector('input[type="hidden"]');
                    // Check if the hidden input exists and its value is empty
                    if (hiddenInput && hiddenInput.value.trim() === '') {
                        allAnswered = false;
                    }
                });

                if (!allAnswered) {
                    alert('Lütfen bu sayfadaki tüm soruları yanıtlayın.');
                    return false; // Prevent page change
                }
            }
            // --- End Validation ---


            // If validation passes or moving backward (not applicable in this version, but good practice), proceed with page change
            // Hide/show personal info based on the page index
            personalInfo.style.display = index === 0 ? 'block' : 'none';

            // Hide/show question groups
            groups.forEach((group, i) => {
                // Use classList.toggle for managing active class
                group.classList.toggle('active', i === index);
                 // Ensure display is block for active and none for others
                group.style.display = i === index ? 'block' : 'none';
            });

            // Update button visibility and disabled state
            // prevBtn is always hidden in this version
            nextBtn.classList.toggle('hidden', isLastPage);
            submitBtn.classList.toggle('hidden', !isLastPage);

            // Make name and class inputs read-only after the first page
            nameInput.readOnly = index > 0;
            classInput.readOnly = index > 0;

            // Update current page index
            current = index;
            console.log('Page changed to:', current); // Log successful page change

             // Restore selected state for buttons on the current page
             // This is important if the user navigates back (though 'Geri' is hidden) or if the page reloads with saved answers
             if (groups[current]) { // Check if the group exists
                 groups[current].querySelectorAll('.question-button').forEach(btn => {
                     // Extract question ID from the onclick attribute string
                     const qidMatch = btn.onclick.toString().match(/selectAnswer\((\d+)/);
                     if (qidMatch && qidMatch[1]) {
                         const questionId = qidMatch[1];
                         const hiddenInput = document.getElementById(`answer_${questionId}`);
                         // Check if the hidden input exists and its value matches the button's data-value
                         if (hiddenInput && hiddenInput.value === btn.dataset.value) {
                             btn.classList.add('active'); // Add active class if this button's value is selected
                         } else {
                             btn.classList.remove('active'); // Remove active class otherwise
                         }
                     } else {
                         console.error('Could not extract question ID from onclick attribute for button:', btn);
                     }
                 });
             }

             return true; // Page change was successful
        }

        // Function to handle answer selection
        function selectAnswer(questionId, button) {
            const buttons = button.parentElement.querySelectorAll('.question-button');
            // Remove 'active' class from all buttons in the same group
            buttons.forEach(btn => btn.classList.remove('active'));
            // Add 'active' class to the clicked button
            button.classList.add('active');
            // Set the value of the corresponding hidden input
            document.getElementById(`answer_${questionId}`).value = button.dataset.value;
        }

        // --- Initial Load ---
        // Show the first group on initial load
        showPage(0);

        // --- Event Listeners ---
        // Event listener for the 'İleri' button
        nextBtn.addEventListener('click', () => {
            // showPage now handles validation and page change internally
            showPage(current + 1);
        });

        // Event listener for the form submission (when 'Gönder' is clicked on the last page)
        document.getElementById('surveyForm').addEventListener('submit', function(event) {
            // This validation runs when the submit button is clicked on the last page

            // Check if name and class are filled (should be filled on the first page, but double-check)
             if (nameInput.value.trim() === '' || classInput.value.trim() === '') {
                 alert('Lütfen ad ve sınıf giriniz.');
                 event.preventDefault(); // Prevent submission
                 showPage(0); // Go back to the first page (optional, but helpful)
                 return;
             }

            // Check if all questions in the current (last) group are answered
            // Use the correct selector for questions within the current group
            const currentGroupQuestions = groups[current].querySelectorAll('.question');
            let allAnsweredInGroup = true;
             currentGroupQuestions.forEach(questionDiv => {
                 const hiddenInput = questionDiv.querySelector('input[type="hidden"]');
                 if (hiddenInput && hiddenInput.value.trim() === '') {
                     allAnsweredInGroup = false;
                 }
             });


            if (!allAnsweredInGroup) { // Check the boolean flag
                alert('Lütfen bu sayfadaki tüm soruları yanıtlayın.');
                event.preventDefault(); // Prevent form submission
            }
            // If all validations pass, the form will be submitted normally.
        });

    </script>
</body>
</html>
