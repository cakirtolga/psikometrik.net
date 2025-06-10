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

<div class="max-w-4xl mx-auto bg-white p-8 rounded shadow-md">
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
      <p>Henüz cevap yok.</p>
    <?php endif; ?>
  </div>

  <?php
  // 🔥 Şimdi: Puan Hesapla ve Yorumla (Bu sadece survey_id = 5 için)
  if ($participant['survey_id'] == 5) {
      $puan = 0;

      foreach ($answers as $answer) {
          $cevap = $answer['answer_text'];
          if ($cevap == 'Evet') {
              $puan += 3;
          } elseif ($cevap == 'Kısmen') {
              $puan += 2;
          } elseif ($cevap == 'Hayır') {
              $puan += 1;
          }
      }

      // Yorum
      if ($puan >= 75) {
          $baslik = "✅ Yüksek düzeyde aile desteği";
          $aciklama = "Test sonucunuz son derece olumlu. Çok yüksek seviyede aile desteğine sahip olduğunuz görülmektedir.";
          $renk = "bg-green-100";
      } elseif ($puan >= 41 && $puan <= 74) {
          $baslik = "ℹ️ Normal düzeyde aile desteği";
          $aciklama = "Test sonucunuz normal seviyede aile desteğine sahip olduğunuzu göstermektedir.";
          $renk = "bg-blue-100";
      } elseif ($puan >= 26 && $puan <= 40) {
          $baslik = "⚠️ Düşük seviyede aile desteği";
          $aciklama = "Test sonucunuza bakıldığında, aile destek düzeyinizin düşük olduğu görülmektedir.";
          $renk = "bg-yellow-100";
      } else {
          $baslik = "🚨 Çok düşük seviyede aile desteği";
          $aciklama = "Test sonucunuz aile destek düzeyinizin son derece düşük olduğunu göstermektedir.";
          $renk = "bg-red-100";
      }

      echo "<div class='mt-10 p-6 rounded $renk'>";
      echo "<h2 class='text-xl font-bold mb-4'>$baslik</h2>";
      echo "<p><strong>Toplam Puan:</strong> $puan</p>";
      echo "<p class='mt-2'>$aciklama</p>";
      echo "</div>";
  }
  ?>

  <div class="mt-8">
    <a href="dashboard.php" class="inline-block mt-4 bg-blue-500 text-white px-6 py-2 rounded hover:bg-blue-600">Geri Dön</a>
  </div>
</div>

</body>
</html>
