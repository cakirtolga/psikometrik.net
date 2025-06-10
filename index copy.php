<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);


session_start();
require_once '/home/dahisinc/public_html/testanket/src/config.php';

// Anketleri çek
$stmt = $pdo->query("SELECT * FROM surveys ORDER BY created_at DESC");
$surveys = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <title>Anket Platformu</title>
  <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-gray-100 min-h-screen">
  <nav class="bg-white shadow p-4 flex justify-between">
    <a href="index.php" class="text-lg font-bold">TestAnket</a>

    <div>
      <?php if (isset($_SESSION['user_id'])): ?>
        <span class="mr-4">👤 <?php echo htmlspecialchars($_SESSION['username']); ?></span>
        <a href="create-survey.php" class="bg-green-500 text-white px-4 py-2 rounded hover:bg-green-600 mr-2">Yeni Anket</a>
        <a href="logout.php" class="bg-red-500 text-white px-4 py-2 rounded hover:bg-red-600">Çıkış</a>
      <?php else: ?>
        <a href="login.php" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600 mr-2">Giriş</a>
        <a href="register.php" class="bg-green-500 text-white px-4 py-2 rounded hover:bg-green-600">Kayıt Ol</a>
      <?php endif; ?>
    </div>
  </nav>

  <main class="container mx-auto p-4">
    <h1 class="text-2xl font-bold mb-6">Tüm Anketler</h1>

    <?php if (count($surveys) > 0): ?>
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($surveys as $survey): ?>
          <div class="bg-white p-6 rounded shadow hover:shadow-lg transition">
            <h2 class="text-xl font-semibold mb-2"><?php echo htmlspecialchars($survey['title']); ?></h2>
            <p class="text-gray-600 mb-4"><?php echo nl2br(htmlspecialchars($survey['description'])); ?></p>
            <a href="take-survey.php?id=<?php echo $survey['id']; ?>" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">Katıl</a>
            <a href="results.php?id=<?php echo $survey['id']; ?>" class="ml-2 bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600">Sonuçlar</a>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p>Henüz hiç anket oluşturulmadı.</p>
    <?php endif; ?>
  </main>
</body>
</html>
