<?php
session_start();
require_once '/home/dahisinc/public_html/testanket/src/config.php';

// Giriş kontrolü
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super-admin'])) {
    header('Location: ../login.php');
    exit();
}

// Katılımcı bilgisi
$participantId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$participantId) {
    die('Geçersiz katılımcı.');
}

// Katılımcı ve anket bilgisi
$participantStmt = $pdo->prepare("
    SELECT sp.*, s.title AS survey_title
    FROM survey_participants sp
    JOIN surveys s ON sp.survey_id = s.id
    WHERE sp.id = ?
");
$participantStmt->execute([$participantId]);
$participant = $participantStmt->fetch(PDO::FETCH_ASSOC);

if (!$participant) {
    die('Katılımcı bulunamadı.');
}

// Cevapları çek
$answersStmt = $pdo->prepare("
    SELECT sa.question_id, sa.answer_text, sq.question
    FROM survey_answers sa
    JOIN survey_questions sq ON sa.question_id = sq.id
    WHERE sa.participant_id = ?
");
$answersStmt->execute([$participantId]);
$answers = $answersStmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <title>Katılımcı Sonuçları</title>
  <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-gray-100 p-8">

<div class="max-w-5xl mx-auto bg-white p-8 rounded shadow-md">
  <h1 class="text-2xl font-bold mb-6 text-center"><?php echo htmlspecialchars($participant['name']); ?> - <?php echo htmlspecialchars($participant['survey_title']); ?></h1>

  <div class="mb-8">
    <h2 class="text-xl font-semibold mb-4">Katılımcı Bilgileri:</h2>
    <p><strong>Ad Soyad:</strong> <?php echo htmlspecialchars($participant['name']); ?></p>
    <p><strong>Sınıf:</strong> <?php echo htmlspecialchars($participant['class']); ?></p>
  </div>

  <div class="mb-8">
    <h2 class="text-xl font-semibold mb-4">Cevaplar:</h2>

    <?php if (count($answers) > 0): ?>
      <ul class="space-y-4">
        <?php foreach ($answers as $answer): ?>
          <li class="p-4 bg-gray-50 rounded border">
            <strong><?php echo htmlspecialchars($answer['question']); ?></strong><br>
            Cevap: <?php echo htmlspecialchars($answer['answer_text']); ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p>Henüz cevap bulunamadı.</p>
    <?php endif; ?>

  </div>

  <div class="mt-8">
    <a href="dashboard.php" class="inline-block mt-4 bg-blue-500 text-white px-6 py-2 rounded hover:bg-blue-600">Geri Dön</a>
  </div>

</div>

</body>
</html>
