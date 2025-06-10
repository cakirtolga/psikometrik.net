<?php
session_start();
require_once '/home/dahisinc/public_html/testanket/src/config.php';

if (!isset($_SESSION['user_id'])) {
    die('Erişim reddedildi.');
}

$participantId = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($participantId === 0) {
    die('Geçersiz katılımcı ID.');
}

$stmt = $pdo->prepare("SELECT * FROM survey_participants WHERE id = ? AND survey_id = 9");
$stmt->execute([$participantId]);
$participant = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$participant) {
    die('Katılımcı bulunamadı.');
}

$stmt = $pdo->prepare("
    SELECT q.id, q.question, q.sort_order, a.answer_text
    FROM survey_answers a
    JOIN survey_questions q ON a.question_id = q.id
    WHERE a.participant_id = ?
    ORDER BY q.sort_order ASC
");
$stmt->execute([$participantId]);
$answers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$factors = [
    'Dışadönüklük' => [1, 6, 11, 16, 21, 26, 31, 36, 41, 46],
    'Uyumluluk' => [2, 7, 12, 17, 22, 27, 32, 37, 42, 47],
    'Sorumluluk' => [3, 8, 13, 18, 23, 28, 33, 38, 43, 48],
    'Duygusal Denge' => [4, 9, 14, 19, 24, 29, 34, 39, 44, 49],
    'Deneyime Açıklık' => [5, 10, 15, 20, 25, 30, 35, 40, 45, 50]
];
$reverseQuestions = [6,8,10,12,14,16,18,20,22,24,26,28,30,32,34,36,38,40,42,44,46,48];

$scoreMap = [
    'Tamamen Yanlış' => 1,
    'Katılmıyorum' => 2,
    'Kısmen Katılıyorum' => 3,
    'Katılıyorum' => 4,
    'Tamamen Katılıyorum' => 5
];

$factorScores = [];
$factorComments = [];
foreach ($factors as $factor => $questionIds) {
    $score = 0;
    foreach ($answers as $ans) {
        if (in_array($ans['sort_order'], $questionIds)) {
            $s = $scoreMap[$ans['answer_text']] ?? 0;
            if (in_array($ans['sort_order'], $reverseQuestions)) {
                $s = 6 - $s; // ters kodlama
            }
            $score += $s;
        }
    }
    $factorScores[$factor] = $score;

    if ($score >= 40) {
        $factorComments[$factor] = "Çok yüksek düzeyde";
    } elseif ($score >= 30) {
        $factorComments[$factor] = "Yüksek düzeyde";
    } elseif ($score >= 20) {
        $factorComments[$factor] = "Orta düzeyde";
    } else {
        $factorComments[$factor] = "Düşük düzeyde";
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Beş Faktör Kişilik Sonuçları</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: sans-serif; background-color: #f0fdf4; padding: 20px; color: #2c3e50; }
        .container { max-width: 900px; margin: auto; background: #fff; padding: 20px; border-radius: 8px; }
        h1, h2 { text-align: center; }
        table { width: 100%; margin-top: 15px; border-collapse: collapse; }
        table, th, td { border: 1px solid #bbb; }
        th, td { padding: 8px; text-align: left; }
        .chart-container { width: 100%; max-width: 500px; margin: 30px auto; }
        .factor-summary { margin-top: 20px; }
        .factor-summary p { margin: 5px 0; }
    </style>
</head>
<body>
<div class="container">
    <h1>Beş Faktör Kişilik Envanteri Sonuçları</h1>
    <h2><?= htmlspecialchars($participant['name']) ?> (<?= htmlspecialchars($participant['class']) ?>)</h2>

    <div class="factor-summary">
        <h3>Faktör Puanları ve Yorumları</h3>
        <?php foreach ($factorScores as $factor => $score): ?>
            <p><strong><?= $factor ?>:</strong> <?= $score ?>/50 → <em><?= $factorComments[$factor] ?></em></p>
        <?php endforeach; ?>
    </div>

    <div class="chart-container">
        <canvas id="factorChart"></canvas>
    </div>

    <h3>Verilen Yanıtlar</h3>
    <table>
        <thead>
        <tr>
            <th>#</th>
            <th>Soru</th>
            <th>Yanıt</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($answers as $ans): ?>
            <tr>
                <td><?= $ans['sort_order'] ?></td>
                <td><?= htmlspecialchars($ans['question']) ?></td>
                <td><?= htmlspecialchars($ans['answer_text']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
const ctx = document.getElementById('factorChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_keys($factorScores)) ?>,
        datasets: [{
            label: 'Puan',
            data: <?= json_encode(array_values($factorScores)) ?>,
            backgroundColor: ['#22c55e', '#3b82f6', '#facc15', '#f87171', '#8b5cf6']
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: { beginAtZero: true, max: 50 }
        },
        plugins: { legend: { display: false } }
    }
});
</script>
</body>
</html>
