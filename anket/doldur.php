<?php
session_start();
require_once '/home/dahisinc/public_html/testanket/src/config.php';

// Anket ID kontrolü
if (!isset($_GET['anket_id'])) {
    die('Geçersiz anket bağlantısı.');
}

$surveyId = intval($_GET['anket_id']);

// Anket bilgisi çek
$stmt = $pdo->prepare("SELECT * FROM surveys WHERE id = ?");
$stmt->execute([$surveyId]);
$survey = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$survey) {
    die('Anket bulunamadı.');
}

// Anket soruları çek
$questionsStmt = $pdo->prepare("SELECT * FROM survey_questions WHERE survey_id = ?");
$questionsStmt->execute([$surveyId]);
$questions = $questionsStmt->fetchAll(PDO::FETCH_ASSOC);

// Form gönderimi
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);

    if (!$name || !$email) {
        $error = "Lütfen adınızı ve e-posta adresinizi girin.";
    } else {
        // Katılımcı kaydı
        $participantStmt = $pdo->prepare("INSERT INTO survey_participants (name, email, survey_id) VALUES (?, ?, ?)");
        $participantStmt->execute([$name, $email, $surveyId]);
        $participantId = $pdo->lastInsertId();

        // Soruların cevaplarını kaydet
        foreach ($_POST['answers'] as $questionId => $answer) {
            $answerStmt = $pdo->prepare("INSERT INTO survey_answers (participant_id, question_id, answer) VALUES (?, ?, ?)");
            $answerStmt->execute([$participantId, $questionId, $answer]);
        }

        // Başarı mesajı
        header('Location: tamamlandi.php');
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <title>Anket: <?php echo htmlspecialchars($survey['title']); ?></title>
  <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
  <div class="bg-white p-8 rounded shadow-md w-full max-w-2xl">
    <h1 class="text-2xl font-bold mb-6 text-center"><?php echo htmlspecialchars($survey['title']); ?></h1>

    <?php if (isset($error)): ?>
      <div class="bg-red-100 text-red-700 p-2 mb-4 rounded"><?php echo $error; ?></div>
    <?php endif; ?>

    <form method="POST">
      <div class="mb-4">
        <input type="text" name="name" placeholder="Ad Soyad" required class="w-full p-2 border rounded">
      </div>
      <div class="mb-4">
        <input type="email" name="email" placeholder="E-posta" required class="w-full p-2 border rounded">
      </div>

      <?php foreach ($questions as $q): ?>
        <div class="mb-4">
          <label class="block mb-2 font-semibold"><?php echo htmlspecialchars($q['question']); ?></label>

          <?php if ($q['answer_type'] === 'evet_hayir'): ?>
            <label><input type="radio" name="answers[<?php echo $q['id']; ?>]" value="Evet" required> Evet</label>
            <label><input type="radio" name="answers[<?php echo $q['id']; ?>]" value="Hayır" required> Hayır</label>
          <?php elseif ($q['answer_type'] === 'puanlama_1_5'): ?>
            <select name="answers[<?php echo $q['id']; ?>]" required>
              <option value="">Seçiniz</option>
              <?php for ($i=1; $i<=5; $i++): ?>
                <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
              <?php endfor; ?>
            </select>
          <?php elseif ($q['answer_type'] === 'coktan_secmeli'): ?>
            <input type="text" name="answers[<?php echo $q['id']; ?>]" placeholder="Seçiminizi yazınız" required class="w-full p-2 border rounded">
          <?php endif; ?>
        </div>
      <?php endforeach; ?>

      <button type="submit" class="w-full bg-green-500 text-white p-2 rounded hover:bg-green-600">Gönder</button>
    </form>
  </div>
</body>
</html>
