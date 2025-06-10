<?php
session_start();
require_once '/home/dahisinc/public_html/testanket/src/config.php';

// Giriş kontrolü
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super-admin'])) {
    header('Location: ../login.php');
    exit();
}

// Katılımcı ID kontrol
$participantId = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$participantId) {
    die('Geçersiz katılımcı.');
}

// Katılımcı bilgilerini çek
$participantStmt = $pdo->prepare("
    SELECT sp.*, s.title AS survey_title
    FROM survey_participants sp
    JOIN surveys s ON sp.survey_id = s.id
    WHERE sp.id = ?
");
$participantStmt->execute([$participantId]);
$participant = $participantStmt->fetch(PDO::FETCH_ASSOC);

// Cevapları ve sort_order bilgisini çek
$answersStmt = $pdo->prepare("
    SELECT sa.question_id, sa.answer_text, sq.question, sq.sort_order
    FROM survey_answers sa
    JOIN survey_questions sq ON sa.question_id = sq.id
    WHERE sa.participant_id = ?
    ORDER BY sq.sort_order ASC
");
$answersStmt->execute([$participantId]);
$answers = $answersStmt->fetchAll(PDO::FETCH_ASSOC);

// Demokratik, Otoriter, İlgisiz soru numaraları
$democratic = [1,3,6,7,12,13,17,21,22,23,26,28,32,33,36,37,42,45,48,49,50,51,54,55,60,61,62,68,69,77,82,85,88,94,97,105,109,112,117,119];
$authoritarian = [2,5,8,11,14,15,18,24,25,29,34,35,40,43,44,52,57,63,64,66,67,73,74,75,76,79,81,84,87,91,92,93,98,99,101,102,110,113,115,118];
$neglectful = [4,9,10,16,19,20,27,30,31,38,39,41,46,47,53,56,58,59,65,70,71,72,78,80,83,86,89,90,95,96,100,103,104,106,107,108,111,114,116,120];

// Skor hesaplama
$puanDemocratic = 0;
$puanAuthoritarian = 0;
$puanNeglectful = 0;

foreach ($answers as $answer) {
    if (empty($answer['answer_text'])) continue;
    $qNo = (int)$answer['sort_order'];

    $score = ($answer['answer_text'] === 'Her ikisi') ? 3 : 2;

    if (in_array($qNo, $democratic)) { $puanDemocratic += $score; }
    elseif (in_array($qNo, $authoritarian)) { $puanAuthoritarian += $score; }
    elseif (in_array($qNo, $neglectful)) { $puanNeglectful += $score; }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <title>Sonuçlar | Ana Baba Tutumu</title>
  <link href="../assets/css/style.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script> <!-- Chart.js dahil -->
</head>
<body class="bg-gray-100 p-8">

<div class="max-w-5xl mx-auto bg-white p-8 rounded shadow-md">

  <h1 class="text-2xl font-bold mb-6 text-center"><?php echo htmlspecialchars($participant['name']); ?> - <?php echo htmlspecialchars($participant['survey_title']); ?></h1>

  <!-- Grafik -->
  <div class="mb-12 text-center">
    <h2 class="text-xl font-semibold mb-4">📊 Ana Baba Tutumu - Puan Dağılımı</h2>
    <div class="flex justify-center">
      <canvas id="pieChart" style="width:300px; height:300px; max-width:300px;"></canvas>
    </div>
  </div>

  <!-- Skorlar -->
  <div class="mb-8">
    <div class="bg-green-100 p-4 rounded mb-2">
      <strong>Demokratik Tutum:</strong> <?php echo $puanDemocratic; ?>/120
    </div>
    <div class="bg-blue-100 p-4 rounded mb-2">
      <strong>Otoriter Tutum:</strong> <?php echo $puanAuthoritarian; ?>/120
    </div>
    <div class="bg-yellow-100 p-4 rounded mb-2">
      <strong>İlgisiz Tutum:</strong> <?php echo $puanNeglectful; ?>/120
    </div>
  </div>

  <!-- Öğrenci Yanıtları -->
  <div class="mb-8">
    <h2 class="text-xl font-semibold mb-4">Öğrenci Yanıtları:</h2>

    <?php if (count($answers) > 0): ?>
      <ul class="space-y-4">
        <?php foreach ($answers as $answer): ?>
          <li class="p-4 bg-gray-50 rounded border">
            <strong><?php echo htmlspecialchars($answer['question']); ?></strong><br>
            Yanıt: <?php echo htmlspecialchars($answer['answer_text'] ?: "Boş bırakıldı"); ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p>Henüz cevap bulunamadı.</p>
    <?php endif; ?>
  </div>

  <div class="mt-8 text-center">
    <a href="dashboard.php" class="inline-block bg-blue-500 text-white px-6 py-2 rounded hover:bg-blue-600">🔙 Geri Dön</a>
  </div>

</div>

<!-- Chart Script -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const demokratik = <?php echo (int)$puanDemocratic; ?>;
    const otoriter = <?php echo (int)$puanAuthoritarian; ?>;
    const ilgisiz = <?php echo (int)$puanNeglectful; ?>;
    
    const total = demokratik + otoriter + ilgisiz;

    const ctx = document.getElementById('pieChart').getContext('2d');
    new Chart(ctx, {
        type: 'pie',
        data: {
            labels: ['Demokratik', 'Otoriter', 'İlgisiz'],
            datasets: [{
                data: [demokratik, otoriter, ilgisiz],
                backgroundColor: [
                    'rgba(54, 162, 235, 0.7)',
                    'rgba(255, 99, 132, 0.7)',
                    'rgba(255, 206, 86, 0.7)'
                ],
                borderColor: [
                    'rgba(54, 162, 235, 1)',
                    'rgba(255, 99, 132, 1)',
                    'rgba(255, 206, 86, 1)'
                ],
                borderWidth: 2
            }]
        },
        options: {
            responsive: false,
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.label || '';
                            let value = context.parsed || 0;
                            let percentage = total ? (value / total * 100).toFixed(1) : 0;
                            return `${label}: ${percentage}%`;
                        }
                    }
                },
                legend: {
                    position: 'bottom'
                }
            }
        }
    });
});
</script>


</body>
</html>
