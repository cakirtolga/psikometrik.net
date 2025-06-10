<?php
session_start();
require_once '/home/dahisinc/public_html/testanket/src/config.php';

// Giriş kontrolü
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super-admin'])) {
    header('Location: ../login.php');
    exit();
}

// URL'den participant_id al
$participantId = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$participantId) {
    die('Geçersiz katılımcı ID.');
}

// Katılımcı bilgilerini al
$stmt = $pdo->prepare("
    SELECT sp.*, s.title AS survey_title
    FROM survey_participants sp
    JOIN surveys s ON sp.survey_id = s.id
    WHERE sp.id = ?
");
$stmt->execute([$participantId]);
$participant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$participant) {
    die('Katılımcı bulunamadı.');
}

// Katılımcının cevaplarını çek
$answersStmt = $pdo->prepare("
    SELECT sq.sort_order, sq.question, sa.answer_text
    FROM survey_answers sa
    JOIN survey_questions sq ON sa.question_id = sq.id
    WHERE sa.participant_id = ?
    ORDER BY sq.sort_order ASC
");
$answersStmt->execute([$participantId]);
$answers = $answersStmt->fetchAll(PDO::FETCH_ASSOC);

// Anket ID kontrolü (Beck testi: survey_id = 7)
if ($participant['survey_id'] != 7) {
    die('Bu sonuç Beck Anksiyete Ölçeği için değildir.');
}

// Puan hesaplama
$totalScore = 0;
foreach ($answers as $answer) {
    $value = (int) $answer['answer_text']; // 0 = Hiç, 1 = Hafif, 2 = Orta, 3 = Ciddi
    $totalScore += $value;
}

// Sonuç açıklaması
$aciklama = "";
if ($totalScore >= 8 && $totalScore <= 15) {
    $baslik = "Hafif Anksiyete Belirtileri";
    $aciklama = "Test sonucunuz hafif düzeyde anksiyete belirtilerine işaret etmektedir.";
} elseif ($totalScore >= 16 && $totalScore <= 25) {
    $baslik = "Orta Düzeyde Anksiyete Belirtileri";
    $aciklama = "Test sonucunuz orta düzeyde anksiyete belirtilerine işaret etmektedir.";
} elseif ($totalScore >= 26 && $totalScore <= 63) {
    $baslik = "Şiddetli Anksiyete Belirtileri";
    $aciklama = "Test sonucunuz şiddetli düzeyde anksiyete belirtilerine işaret etmektedir. Bir uzmana başvurmanız önerilir.";
} else {
    $baslik = "Belirti Yok veya Çok Hafif";
    $aciklama = "Anksiyete belirtileri bulunmamaktadır veya çok hafiftir.";
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <title>Beck Anksiyete Testi Sonuçları</title>
  <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-gray-100 p-8">

  <div class="max-w-4xl mx-auto bg-white p-8 rounded shadow-md">
    <h1 class="text-2xl font-bold mb-6 text-center"><?php echo htmlspecialchars($participant['survey_title']); ?> Sonuçları</h1>

    <div class="mb-8">
      <p><strong>Ad Soyad:</strong> <?php echo htmlspecialchars($participant['name']); ?></p>
      <p><strong>Sınıf:</strong> <?php echo htmlspecialchars($participant['class']); ?></p>
      <p><strong>Toplam Puan:</strong> <?php echo $totalScore; ?></p>
      <p><strong>Değerlendirme:</strong> <?php echo $baslik; ?></p>
      <div class="mt-4 p-4 bg-blue-100 text-blue-800 rounded">
        <?php echo $aciklama; ?>
      </div>
    </div>

    <h2 class="text-xl font-semibold mb-4">📝 Yanıtlar</h2>

    <div class="overflow-x-auto">
      <table class="min-w-full bg-white border">
        <thead>
          <tr>
            <th class="py-2 px-4 border">#</th>
            <th class="py-2 px-4 border">Soru</th>
            <th class="py-2 px-4 border">Verilen Yanıt</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($answers as $answer): ?>
            <tr>
              <td class="py-2 px-4 border"><?php echo htmlspecialchars($answer['sort_order']); ?></td>
              <td class="py-2 px-4 border"><?php echo htmlspecialchars($answer['question']); ?></td>
              <td class="py-2 px-4 border">
                <?php
                switch ($answer['answer_text']) {
                    case '0': echo 'Hiç'; break;
                    case '1': echo 'Hafif'; break;
                    case '2': echo 'Orta'; break;
                    case '3': echo 'Ciddi'; break;
                    default: echo '-'; break;
                }
                ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="mt-8 text-center">
      <a href="dashboard.php" class="bg-blue-500 text-white px-6 py-2 rounded hover:bg-blue-600">Geri Dön</a>
    </div>
  </div>

</body>
</html>
