<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/testanket/src/config.php';

if (!isset($_GET['id'])) {
    die('Geçersiz istek.');
}

$survey_id = intval($_GET['id']);

// Anket çek
$stmt = $pdo->prepare("SELECT * FROM surveys WHERE id = ?");
$stmt->execute([$survey_id]);
$survey = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$survey) {
    die('Anket bulunamadı.');
}

// Sorular ve cevaplar çek
$stmt = $pdo->prepare("
    SELECT q.id as question_id, q.question_text, a.answer_text
    FROM survey_questions q
    LEFT JOIN survey_answers a ON q.id = a.question_id
    WHERE q.survey_id = ?
    ORDER BY q.id
");
$stmt->execute([$survey_id]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Verileri gruplandır
$groupedResults = [];
foreach ($results as $row) {
    $groupedResults[$row['question_id']]['question'] = $row['question_text'];
    $groupedResults[$row['question_id']]['answers'][] = $row['answer_text'];
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <title>Anket Sonuçları</title>
  <link href="assets/css/style.css" rel="stylesheet">
  <!-- Chart.js CDN -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-100 min-h-screen">
  <div class="container mx-auto p-4">
    <h1 class="text-2xl font-bold mb-6 text-center"><?php echo htmlspecialchars($survey['title']); ?> - Sonuçlar</h1>

    <?php foreach ($groupedResults as $questionId => $data): ?>
      <div class="bg-white p-6 rounded shadow-md mb-8">
        <h2 class="text-xl font-semibold mb-4"><?php echo htmlspecialchars($data['question']); ?></h2>

        <?php
        // Cevapları say
        $counts = array_count_values(array_filter($data['answers']));
        $labels = array_keys($counts);
        $values = array_values($counts);
        ?>

        <canvas id="chart_<?php echo $questionId; ?>" height="150"></canvas>

        <script>
          const ctx<?php echo $questionId; ?> = document.getElementById('chart_<?php echo $questionId; ?>').getContext('2d');
          new Chart(ctx<?php echo $questionId; ?>, {
            type: 'bar',
            data: {
              labels: <?php echo json_encode($labels); ?>,
              datasets: [{
                label: 'Cevaplar',
                data: <?php echo json_encode($values); ?>,
                backgroundColor: 'rgba(59, 130, 246, 0.7)', // mavi
                borderColor: 'rgba(59, 130, 246, 1)',
                borderWidth: 1
              }]
            },
            options: {
              scales: {
                y: {
                  beginAtZero: true
                }
              }
            }
          });
        </script>
      </div>
    <?php endforeach; ?>

    <div class="text-center mt-8">
      <a href="index.php" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">Anasayfaya Dön</a>
    </div>
  </div>
</body>
</html>
