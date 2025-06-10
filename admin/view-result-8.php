<?php
session_start();
require_once '/home/dahisinc/public_html/testanket/src/config.php';

// Giriş kontrolü
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super-admin'])) {
    header('Location: ../login.php');
    exit();
}

// Katılımcı ID al
$participantId = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$participantId) {
    die('Geçersiz Katılımcı.');
}

// Katılımcı Bilgileri
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

// Cevapları çek
$answersStmt = $pdo->prepare("
    SELECT sq.sort_order, sq.question, sa.answer_text
    FROM survey_answers sa
    JOIN survey_questions sq ON sa.question_id = sq.id
    WHERE sa.participant_id = ?
    ORDER BY sq.sort_order ASC
");
$answersStmt->execute([$participantId]);
$answers = $answersStmt->fetchAll(PDO::FETCH_ASSOC);

// Puanı hesapla
$puan = 0;
foreach ($answers as $answer) {
    $cevap = strtolower(trim($answer['answer_text']));
    if ($cevap == "tam benim gibi") {
        $puan += 2;
    } elseif ($cevap == "biraz benim gibi") {
        $puan += 1;
    } elseif ($cevap == "kararsızım") {
        $puan += 0;
    } elseif ($cevap == "hayır") {
        $puan -= 1;
    } elseif ($cevap == "asla") {
        $puan -= 2;
    }
}

// Yüzde Hesapla
$max_puan = 130;
$yuzde = ($puan / $max_puan) * 100;
$yuzde = round($yuzde);

// Açıklama ve Görsel Bilgiler
if ($yuzde >= 25) {
    $baslik = "Olumlu Yönde Benlik Tasarımı";
    $aciklama = "Kendinize güveniniz olumlu bir seviyede ve hayata bakış açınız son derece iyi.";
    $card_class = "text-white bg-success";
    $icon = "<span class='fa fa-thumbs-o-up'></span>";
} elseif ($yuzde >= -25 && $yuzde <= 25) {
    $baslik = "Orta Düzeyde Benlik Tasarımı";
    $aciklama = "Benlik tasarım yüzdeniz ortalama bir seviyede.";
    $card_class = "text-white bg-info";
    $icon = "<span class='fa fa-hand-o-right'></span>";
} else {
    $baslik = "Olumsuz Yönde Benlik Tasarımı";
    $aciklama = "Benlik tasarım yüzdeniz düşük. Kendinize güven konusunda problemleriniz bulunmakta.";
    $card_class = "bg-light";
    $icon = "<span class='fa fa-thumbs-o-down'></span>";
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Sonuç Görüntüle - <?php echo htmlspecialchars($participant['name']); ?></title>
    <link href="../assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.css">
    <script src="https://cdn.amcharts.com/lib/4/core.js"></script>
    <script src="https://cdn.amcharts.com/lib/4/charts.js"></script>
    <script src="https://cdn.amcharts.com/lib/4/themes/animated.js"></script>
    <style>
        .card { margin-bottom: 20px; padding: 20px; border-radius: 8px; }
        #chartdiv { width: 220px; height: 220px; margin: 20px auto; }
    </style>
</head>
<body class="bg-gray-100 p-6">

    <div class="max-w-4xl mx-auto">

        <div class="bg-white p-6 rounded shadow mb-6">
            <h1 class="text-2xl font-bold mb-2"><?php echo htmlspecialchars($participant['name']); ?> - <?php echo htmlspecialchars($participant['survey_title']); ?></h1>
            <p class="text-gray-600">Sınıf: <?php echo htmlspecialchars($participant['class']); ?></p>
        </div>

        <!-- SONUÇ -->
        <div class="card <?php echo $card_class; ?>">
            <h2 class="text-xl font-bold mb-2"><?php echo $icon . " " . $baslik; ?> (%<?php echo $yuzde; ?>)</h2>
            <p><?php echo $aciklama; ?></p>
        </div>

        <!-- Grafik -->
        <div id="chartdiv"></div>

        <!-- Cevaplar -->
        <div class="bg-white p-6 rounded shadow">
            <h2 class="text-2xl font-bold mb-4">Cevaplar</h2>
            <ul class="list-disc ml-6 space-y-2">
                <?php foreach ($answers as $answer): ?>
                    <li><strong><?php echo $answer['sort_order'] . ". " . htmlspecialchars($answer['question']); ?></strong> <br>
                    <span class="text-blue-700"><?php echo htmlspecialchars($answer['answer_text']); ?></span></li>
                <?php endforeach; ?>
            </ul>
        </div>

    </div>

<script>
am4core.ready(function() {
    am4core.useTheme(am4themes_animated);

    var chart = am4core.create("chartdiv", am4charts.PieChart);

    chart.data = [{
        "category": "Benlik Tasarımı",
        "value": <?php echo max(0, $puan); ?>
    }, {
        "category": "Kalan",
        "value": <?php echo max(0, 130 - $puan); ?>
    }];

    var pieSeries = chart.series.push(new am4charts.PieSeries());
    pieSeries.dataFields.value = "value";
    pieSeries.dataFields.category = "category";

    chart.innerRadius = am4core.percent(40);
    chart.radius = am4core.percent(50);
    chart.startAngle = 160;
    chart.endAngle = 380;

    pieSeries.colors.list = [
        am4core.color("#4CAF50"),
        am4core.color("#E0E0E0")
    ];

    pieSeries.hiddenState.properties.opacity = 1;
    pieSeries.hiddenState.properties.endAngle = -90;
    pieSeries.hiddenState.properties.startAngle = -90;

});
</script>

</body>
</html>
