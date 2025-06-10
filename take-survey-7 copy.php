<?php
session_start();
require_once '/home/dahisinc/public_html/testanket/src/config.php';

// URL parametrelerini al
$surveyId = 7; // Beck Anksiyete testi ID'si
$userEmail = isset($_GET['user_email']) ? trim($_GET['user_email']) : '';

if (!$userEmail) {
    die('Geçersiz admin bağlantısı.');
}

// Admin bilgisini çek
$adminStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$adminStmt->execute([$userEmail]);
$admin = $adminStmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    die('Admin bulunamadı.');
}
$adminId = $admin['id'];

// Anket bilgisi
$stmt = $pdo->prepare("SELECT * FROM surveys WHERE id = ?");
$stmt->execute([$surveyId]);
$survey = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$survey) {
    die('Anket bulunamadı.');
}

// Soruları çek
$questionsStmt = $pdo->prepare("SELECT * FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC");
$questionsStmt->execute([$surveyId]);
$questions = $questionsStmt->fetchAll(PDO::FETCH_ASSOC);

// Form gönderildiyse
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $class = trim($_POST['class']);

    if (!$name || !$class) {
        $error = "Lütfen adınızı ve sınıfınızı eksiksiz girin.";
    } else {
        // Katılımcı kaydet
        $participantStmt = $pdo->prepare("INSERT INTO survey_participants (name, class, survey_id, admin_id) VALUES (?, ?, ?, ?)");
        $participantStmt->execute([$name, $class, $surveyId, $adminId]);
        $participantId = $pdo->lastInsertId();

        // Cevapları kaydet
        foreach ($_POST['answers'] as $questionId => $answer) {
            $answerStmt = $pdo->prepare("INSERT INTO survey_answers (participant_id, question_id, answer_text) VALUES (?, ?, ?)");
            $answerStmt->execute([$participantId, $questionId, $answer]);
        }

        header('Location: tamamlandi.php');
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($survey['title']); ?> | Beck Anksiyete Testi</title>
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
<div class="bg-white p-8 rounded shadow-md w-full max-w-3xl">
    <h1 class="text-2xl font-bold mb-6 text-center"><?php echo htmlspecialchars($survey['title']); ?></h1>

    <?php if (isset($error)): ?>
        <div class="bg-red-100 text-red-700 p-4 rounded mb-6"><?php echo $error; ?></div>
    <?php endif; ?>

    <form method="POST" class="space-y-6">
        <div>
            <label class="block mb-2 font-semibold">Ad Soyad</label>
            <input type="text" name="name" class="w-full p-2 border rounded" required>
        </div>

        <div>
            <label class="block mb-2 font-semibold">Sınıf</label>
            <input type="text" name="class" class="w-full p-2 border rounded" required>
        </div>

        <?php foreach ($questions as $question): ?>
            <div class="mb-6">
                <label class="block mb-2 font-semibold">
                    <?php echo htmlspecialchars($question['sort_order']) . ". " . htmlspecialchars($question['question']); ?>
                </label>

                <div class="flex flex-wrap gap-6 mt-2">
                    <label class="inline-flex items-center">
                        <input type="radio" name="answers[<?php echo $question['id']; ?>]" value="0" required class="mr-2"> Hiç
                    </label>
                    <label class="inline-flex items-center">
                        <input type="radio" name="answers[<?php echo $question['id']; ?>]" value="1" required class="mr-2"> Hafif
                    </label>
                    <label class="inline-flex items-center">
                        <input type="radio" name="answers[<?php echo $question['id']; ?>]" value="2" required class="mr-2"> Orta
                    </label>
                    <label class="inline-flex items-center">
                        <input type="radio" name="answers[<?php echo $question['id']; ?>]" value="3" required class="mr-2"> Ciddi
                    </label>
                </div>
            </div>
        <?php endforeach; ?>

        <button type="submit" class="w-full bg-green-500 text-white p-2 rounded hover:bg-green-600">Gönder</button>
    </form>
</div>
</body>
</html>
