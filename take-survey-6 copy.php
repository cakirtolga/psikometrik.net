<?php
// Enable error reporting for development (should be disabled in production)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Start the session
session_start();

// Include database configuration
require_once __DIR__ . '/src/config.php';

// Define the survey ID
$surveyId = 6;

// Get and validate the user email from the URL
$userEmail = isset($_GET['user_email']) ? trim($_GET['user_email']) : '';
if (!$userEmail) {
    die('Geçersiz bağlantı.'); // Invalid link if email is missing
}

// Find the admin user based on the email
$adminStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$adminStmt->execute([$userEmail]);
$admin = $adminStmt->fetch(PDO::FETCH_ASSOC);
if (!$admin) {
    die('Admin bulunamadı.'); // Admin not found
}
$adminId = $admin['id'];

// Fetch the survey details
$stmt = $pdo->prepare("SELECT * FROM surveys WHERE id = ?");
$stmt->execute([$surveyId]);
$survey = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$survey) {
    die('Anket bulunamadı.'); // Survey not found
}

// Fetch survey questions ordered by sort_order
$questionStmt = $pdo->prepare("SELECT * FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC");
$questionStmt->execute([$surveyId]);
$questions = $questionStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and get participant details and answers
    $name = trim($_POST['name']);
    $class = trim($_POST['class']);
    $answers = $_POST['answers'] ?? []; // Use null coalesce operator for safety

    // Validate required fields
    if (!$name || !$class || empty($answers)) {
        $error = "Lütfen tüm alanları doldurun ve tüm soruları yanıtlayın.";
    } else {
        try {
            // Start a transaction
            $pdo->beginTransaction();

            // Insert participant information
            $insertParticipant = $pdo->prepare("INSERT INTO survey_participants (name, class, survey_id, admin_id) VALUES (?, ?, ?, ?)");
            $insertParticipant->execute([$name, $class, $surveyId, $adminId]);
            $participantId = $pdo->lastInsertId();

            // Insert answers
            $insertAnswer = $pdo->prepare("INSERT INTO survey_answers (participant_id, question_id, answer_text) VALUES (?, ?, ?)");
            foreach ($answers as $questionId => $answer) {
                // Ensure questionId is numeric and answer is a string
                if (is_numeric($questionId) && is_string($answer)) {
                     $insertAnswer->execute([$participantId, $questionId, $answer]);
                }
            }

            // Commit the transaction
            $pdo->commit();

            // Redirect to completion page
            header("Location: tamamlandi.php");
            exit;

        } catch (PDOException $e) {
            // Rollback transaction on error
            $pdo->rollBack();
            $error = "Bir hata oluştu: " . $e->getMessage(); // Display error message (for debugging)
            // In production, you might want a more generic error message
            // $error = "Anket kaydedilirken bir hata oluştu.";
        }
    }
}

// Calculate total questions and group them into chunks of 5
$total = count($questions);
$groups = array_chunk($questions, 5); // Groups of 5 questions per page
$totalGroups = count($groups); // Total number of groups/pages
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($survey['title']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Tailwind CSS overrides and custom styles */
        .question-group { display: none; }

        /* Style for the answer buttons */
        .question-button {
            background: #f0fdf4; /* Very light green */
            border: 2px solid #bbf7d0; /* Light green border */
            color: #15803d; /* Dark green text */
            padding: 12px 20px;
            border-radius: 8px;
            transition: all 0.2s ease-in-out; /* Smooth transition */
            flex: 1; /* Allow buttons to grow */
            min-width: 120px; /* Minimum width */
            text-align: center; /* Center text */
            cursor: pointer; /* Pointer cursor */
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); /* Subtle shadow */
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
            background: #dcfce7; /* Slightly darker light green on hover */
            border-color: #a7f3d0; /* Slightly darker green border on hover */
        }


        /* Style for navigation buttons */
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

        /* Utility class to hide elements */
        .hidden { display: none; }

    </style>
</head>
<body class="bg-[#f0fdf4] min-h-screen p-4">
    <div class="max-w-2xl mx-auto bg-white rounded-xl shadow-sm p-8 mt-10">
        <h1 class="text-2xl font-bold text-center text-[#15803d] mb-8 pb-4 border-b-2 border-[#dcfce7]">
            Algılanan Aile Desteği Ölçeği (PSS-Fa)
        </h1>

        <?php if (isset($error)): ?>
            <div class="bg-red-100 text-red-700 p-4 rounded-lg mb-6"><?= $error ?></div>
        <?php endif; ?>

        <form method="POST" id="surveyForm">
            <div id="personalInfo">
                <div class="mb-6">
                    <label for="nameInput" class="block text-gray-700 font-semibold mb-2">Ad Soyad</label>
                    <input type="text" name="name" id="nameInput" required
                           class="w-full p-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-[#bbf7d0]">
                </div>
                <div class="mb-8">
                    <label for="classInput" class="block text-gray-700 font-semibold mb-2">Sınıf</label>
                    <input type="text" name="class" id="classInput" required
                           class="w-full p-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-[#bbf7d0]">
                </div>
            </div>

            <div id="questionGroups">
                <?php foreach ($groups as $groupIndex => $group): ?>
                    <div class="question-group" data-group="<?= $groupIndex ?>">
                        <?php foreach ($group as $q): ?>
                            <div class="mb-8">
                                <p class="text-lg font-semibold mb-4 text-gray-700">
                                    <?= $q['sort_order'] ?>. <?= htmlspecialchars($q['question']) ?>
                                </p>
                                <div class="flex gap-3 mb-6">
                                    <?php foreach (['Evet', 'Kısmen', 'Hayır'] as $option): ?>
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
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="flex justify-end gap-4 mt-8">
                <button type="button" id="nextBtn" class="nav-btn next">Sonraki Sorular</button>
                <button type="submit" id="submitBtn" class="nav-btn submit hidden">Gönder</button>
            </div>
        </form>
    </div>

    <script>
        const groups = document.querySelectorAll('.question-group');
        const personalInfo = document.getElementById('personalInfo');
        const nextBtn = document.getElementById('nextBtn');
        const submitBtn = document.getElementById('submitBtn');
        const nameInput = document.getElementById('nameInput');
        const classInput = document.getElementById('classInput');

        let current = 0;
        const totalGroups = groups.length;

        function showGroup(index) {
            // Validate name and class before moving from the first page
            if (index > 0 && (nameInput.value.trim() === '' || classInput.value.trim() === '')) {
                 alert('Lütfen adınızı ve sınıfınızı girin.');
                 return; // Stay on the current page
            }

            // Hide/show personal info based on the page index
            personalInfo.style.display = index === 0 ? 'block' : 'none';

            // Hide/show question groups
            groups.forEach((group, i) => {
                group.style.display = i === index ? 'block' : 'none';
            });

            // Update button visibility
            const isLastPage = index === totalGroups - 1;
            nextBtn.classList.toggle('hidden', isLastPage);
            submitBtn.classList.toggle('hidden', !isLastPage);

            // Make name and class inputs read-only after the first page
            if (index > 0) {
                 nameInput.readOnly = true;
                 classInput.readOnly = true;
            } else {
                 nameInput.readOnly = false;
                 classInput.readOnly = false;
            }

            // Update current page index
            current = index;
        }

        function selectAnswer(questionId, button) {
            const buttons = button.parentElement.querySelectorAll('.question-button');
            buttons.forEach(btn => btn.classList.remove('active'));
            button.classList.add('active');
            document.getElementById(`answer_${questionId}`).value = button.dataset.value;
        }

        // Show the first group on initial load
        showGroup(0);

        // Event listener for the 'Sonraki Sorular' button
        nextBtn.addEventListener('click', () => {
            // Optional: Add validation here to ensure questions on the current page are answered
            // before moving to the next page if needed.
            // For simplicity, this example only validates name/class on the first page.

            if (current < totalGroups - 1) {
                showGroup(current + 1);
            }
        });

        // Optional: Add form validation before submitting (on the last page)
        document.getElementById('surveyForm').addEventListener('submit', function(event) {
            // Check if name and class are filled (should be filled on the first page, but double-check)
            if (nameInput.value.trim() === '' || classInput.value.trim() === '') {
                alert('Lütfen adınızı ve sınıfınızı girin.');
                event.preventDefault(); // Prevent form submission
                showGroup(0); // Go back to the first page
                return;
            }

            // Check if all questions in the current (last) group are answered
            const currentGroupQuestions = groups[current].querySelectorAll('.mb-8'); // Select question blocks
            let allAnsweredInGroup = true;
            const answeredQuestionsInGroup = new Set();

            currentGroupQuestions.forEach(questionBlock => {
                 const hiddenInput = questionBlock.querySelector('input[type="hidden"][name^="answers"]');
                 if (hiddenInput && hiddenInput.value.trim() !== '') {
                     answeredQuestionsInGroup.add(hiddenInput.name); // Use input name to identify answered questions
                 }
            });

            // Check if the number of answered questions matches the number of questions in the last group
             const questionsInLastGroupCount = groups[current].querySelectorAll('input[type="hidden"][name^="answers"]').length;
             if (answeredQuestionsInGroup.size !== questionsInLastGroupCount) {
                 alert('Lütfen bu sayfadaki tüm soruları yanıtlayın.');
                 event.preventDefault(); // Prevent form submission
             }
        });

    </script>
</body>
</html>
