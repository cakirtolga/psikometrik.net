<?php
session_start();
require_once '/home/dahisinc/public_html/testanket/src/config.php';

// Giriş kontrolü
if (!isset($_SESSION['user_id'])) {
  header('Location: login.php');
  exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $title = trim($_POST['title']);
  $description = trim($_POST['description']);
  $questions = $_POST['questions']; // Dizi şeklinde geliyor
  
  if (!$title || empty($questions)) {
    $error = "Başlık ve en az bir soru gereklidir.";
  } else {
    // Anketi kaydet
    $pdo->beginTransaction();
    try {
      $stmt = $pdo->prepare("INSERT INTO surveys (user_id, title, description) VALUES (?, ?, ?)");
      $stmt->execute([$_SESSION['user_id'], $title, $description]);
      $survey_id = $pdo->lastInsertId();

      foreach ($questions as $q) {
        if (!empty(trim($q))) {
          $stmt = $pdo->prepare("INSERT INTO survey_questions (survey_id, question_text) VALUES (?, ?)");
          $stmt->execute([$survey_id, trim($q)]);
        }
      }

      $pdo->commit();
      $_SESSION['success'] = "Anket başarıyla oluşturuldu!";
      header('Location: index.php');
      exit();
    } catch (Exception $e) {
      $pdo->rollBack();
      $error = "Anket oluşturulamadı: " . $e->getMessage();
    }
  }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <title>Anket Oluştur</title>
  <link href="assets/css/style.css" rel="stylesheet">
  <script>
    function addQuestionField() {
      const container = document.getElementById('questions');
      const input = document.createElement('input');
      input.type = 'text';
      input.name = 'questions[]';
      input.placeholder = 'Soru yazın...';
      input.className = 'w-full p-2 mb-2 border rounded';
      container.appendChild(input);
    }
  </script>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
  <div class="bg-white p-8 rounded shadow-md w-full max-w-2xl">
    <h1 class="text-2xl font-bold mb-6 text-center">Yeni Anket Oluştur</h1>

    <?php if (isset($error)): ?>
      <div class="bg-red-100 text-red-700 p-2 mb-4 rounded"><?php echo $error; ?></div>
    <?php endif; ?>

    <form method="POST">
      <input type="text" name="title" placeholder="Anket Başlığı" class="w-full p-2 mb-4 border rounded" required>
      <textarea name="description" placeholder="Anket Açıklaması (opsiyonel)" class="w-full p-2 mb-4 border rounded"></textarea>

      <div id="questions" class="mb-4">
        <input type="text" name="questions[]" placeholder="Soru yazın..." class="w-full p-2 mb-2 border rounded" required>
      </div>

      <button type="button" onclick="addQuestionField()" class="bg-green-500 text-white p-2 rounded hover:bg-green-600 mb-4">+ Soru Ekle</button>
      <button type="submit" class="w-full bg-blue-500 text-white p-2 rounded hover:bg-blue-600">Anketi Oluştur</button>
    </form>
  </div>
</body>
</html>
