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

$surveyId = $participant['survey_id'];

// Cevapları çek
$answersStmt = $pdo->prepare("SELECT sa.question_id, sa.answer_text, sq.question FROM survey_answers sa JOIN survey_questions sq ON sa.question_id = sq.id WHERE sa.participant_id = ?");
$answersStmt->execute([$participantId]);
$answers = $answersStmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <title>Katılımcı Cevapları</title>
  <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-gray-100 p-8">

  <div class="bg-white p-8 rounded shadow-md max-w-4xl mx-auto">
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
        <p>Henüz cevap verilmemiş.</p>
      <?php endif; ?>
    </div>

    <?php
    // 🔥 ŞİMDİ: Eğer Anket ID'si 6 ise ➔ Puan ve Yorum Gösterelim
    if ($surveyId == 6) {
        // Maddeleri boyutlarına ayıralım
        $demokratik = [1,3,6,7,12,13,17,21,22,23,26,28,32,33,36,37,42,45,48,49,50,51,54,55,60,61,62,68,69,77,82,85,88,94,97,105,109,112,117,119];
        $otoriter = [2,5,8,11,14,15,18,24,25,29,34,35,40,43,44,52,57,63,64,66,67,73,74,75,76,79,81,84,87,91,92,93,98,99,101,102,110,113,115,118];
        $ilgisiz = [4,9,10,16,19,20,27,30,31,38,39,41,46,47,53,56,58,59,65,70,71,72,78,80,83,86,89,90,95,96,100,103,104,106,107,108,111,114,116,120];

        // Skorlar
        $demokratikPuan = 0;
        $otoriterPuan = 0;
        $ilgisizPuan = 0;

        // Cevapları puanla
        foreach ($answers as $answer) {
            $qId = intval($answer['question_id']);
            $cevap = $answer['answer_text'];

            $puan = 0;
            if ($cevap == 'Annem') $puan = 1;
            elseif ($cevap == 'Babam') $puan = 2;
            elseif ($cevap == 'Her ikisi') $puan = 3;
            else continue; // boş bırakılanlar dahil edilmez

            if (in_array($qId, $demokratik)) {
                $demokratikPuan += $puan;
            } elseif (in_array($qId, $otoriter)) {
                $otoriterPuan += $puan;
            } elseif (in_array($qId, $ilgisiz)) {
                $ilgisizPuan += $puan;
            }
        }

        // En yüksek skora göre yorum
        $yorum = '';

        if ($demokratikPuan >= $otoriterPuan && $demokratikPuan >= $ilgisizPuan) {
            $yorum = "✅ Demokratik Tutum Baskın: Aile çocuğa sevgi, saygı gösteriyor, bağımsızlık tanıyor.";
        } elseif ($otoriterPuan >= $demokratikPuan && $otoriterPuan >= $ilgisizPuan) {
            $yorum = "⚡ Otoriter Tutum Baskın: Aile baskıcı, disiplinli, itaat bekleyen bir yapı sergiliyor.";
        } elseif ($ilgisizPuan >= $demokratikPuan && $ilgisizPuan >= $otoriterPuan) {
            $yorum = "🚨 İlgisiz Tutum Baskın: Aile ilgisiz, duyarsız, ihtiyaçlara kayıtsız bir tavır sergiliyor.";
        }

        echo "<div class='mt-10'>";
        echo "<h2 class='text-xl font-semibold mb-4'>🔎 Test Sonuçları:</h2>";
        echo "<ul class='list-disc pl-6'>";
        echo "<li><strong>Demokratik Puan:</strong> $demokratikPuan</li>";
        echo "<li><strong>Otoriter Puan:</strong> $otoriterPuan</li>";
        echo "<li><strong>İlgisiz Puan:</strong> $ilgisizPuan</li>";
        echo "</ul>";
        echo "<p class='mt-4 p-4 bg-blue-100 border rounded'>$yorum</p>";
        echo "</div>";
    }
    ?>

    <div class="mt-8">
      <a href="dashboard.php" class="inline-block mt-4 bg-blue-500 text-white px-6 py-2 rounded hover:bg-blue-600">Geri Dön</a>
    </div>

  </div>

</body>
</html>
