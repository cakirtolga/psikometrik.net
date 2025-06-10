<?php
session_start(); // Oturumu başlat
ini_set('display_errors', 1); // Hata gösterimi (geliştirme için)
error_reporting(E_ALL);
header('Content-Type: text/html; charset=utf-8'); // Karakter seti

require_once '/home/dahisinc/public_html/testanket/src/config.php'; // Veritabanı bağlantısı ve yapılandırma

// --- Oturum ve Yetki Kontrolü ---
if (!isset($_SESSION['user_id'])) {
    die('Bu sayfayı görüntülemek için giriş yapmalısınız.');
}
$loggedInUserId = $_SESSION['user_id'];
// --- Bitiş: Oturum ve Yetki Kontrolü ---

// --- Test ve Katılımcı Bilgisi Al ---
$surveyId = 11;
$testTitle = "Çalışma Davranışı Değerlendirme Ölçeği";

$participantId = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : 0;
if ($participantId <= 0) {
    die('Geçersiz katılımcı ID\'si.');
}

$participant = null;
$error = null;
$participantAnswers = []; // Başlangıçta boş dizi

try {
    // Katılımcı bilgilerini çek
    $stmt = $pdo->prepare("SELECT * FROM survey_participants WHERE id = ? AND survey_id = ?");
    $stmt->execute([$participantId, $surveyId]);
    $participant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$participant) {
        throw new Exception('Test sonucu bulunamadı.');
    }

    // Yetkilendirme kontrolü
    $isSuperAdmin = false; // Basit yetkilendirme
    if ($participant['admin_id'] != $loggedInUserId && !$isSuperAdmin) {
        throw new Exception('Bu sonucu görüntüleme yetkiniz yok.');
    }

    // Katılımcının cevaplarını çek
    $stmtAnswers = $pdo->prepare("
        SELECT q.sort_order, a.answer_text
        FROM survey_answers a
        JOIN survey_questions q ON a.question_id = q.id
        WHERE a.participant_id = ? AND q.survey_id = ?
        ORDER BY q.sort_order ASC
    ");
    $stmtAnswers->execute([$participantId, $surveyId]);
    $participantAnswers = $stmtAnswers->fetchAll(PDO::FETCH_KEY_PAIR);

    // Cevap sayısını kontrol et (opsiyonel ama faydalı)
    if (count($participantAnswers) !== 73) {
         error_log("Warning: Participant $participantId for survey $surveyId has " . count($participantAnswers) . " answers, expected 73.");
         // Belki bir uyarı gösterilebilir $error değişkeni ile
         // $error = "Uyarı: Katılımcının tüm cevapları sistemde bulunmuyor olabilir.";
    }


} catch (Exception $e) {
    $error = "Veri alınırken hata oluştu: " . $e->getMessage();
    error_log("Result Fetch Error (Survey $surveyId, Participant $participantId): " . $e->getMessage());
}
// --- Bitiş: Test ve Katılımcı Bilgisi Al ---


// --- Skorlama ve Yorumlama Mantığı ---
$subscaleScores = [];
$subscaleInterpretations = [];
$chartLabels = [];
$chartData = [];
$subscaleItemCounts = []; // Alt ölçeklerin madde sayılarını tutalım

if (!$error && $participant && !empty($participantAnswers)) {
    // Alt Ölçek Tanımlamaları ve Cevap Anahtarı
    $subscales = [
        'A' => ['title' => 'Çalışmaya Başlama/Sürdürme', 'items' => [13, 30, 40, 49, 15, 32, 43, 55, 17, 37, 44, 67, 18, 39, 48, 70]],
        'B' => ['title' => 'Bilinçli Çalışma/Kullanma', 'items' => [12, 19, 47, 14, 38, 50, 16, 42, 51]],
        'C' => ['title' => 'Not Tutma/Dinleme', 'items' => [8, 22, 61, 72, 10, 24, 62, 20, 31, 71]],
        'D' => ['title' => 'Okuma Alışkanlığı/Tekniği', 'items' => [4, 11, 34, 56, 28, 45, 5, 60, 7, 29, 46, 73]],
        'E' => ['title' => 'Ödev Hazırlama', 'items' => [25, 3, 52, 63, 23, 26, 53]],
        'F' => ['title' => 'Okula Karşı Tutum', 'items' => [35, 27, 57, 68, 33, 36, 64, 69]],
        'G' => ['title' => 'Sınavlara Hazırlanma/Girme', 'items' => [1, 54, 9, 65, 2, 21, 58, 66, 6, 41, 59]]
    ];
    $answerKey = [ 1=>'Y', 2=>'Y', 3=>'Y', 4=>'Y', 5=>'Y', 6=>'D', 7=>'D', 8=>'D', 9=>'Y', 10=>'Y', 11=>'D', 12=>'Y', 13=>'Y', 14=>'D', 15=>'Y', 16=>'Y', 17=>'Y', 18=>'Y', 19=>'D', 20=>'D', 21=>'Y', 22=>'Y', 23=>'D', 24=>'D', 25=>'D', 26=>'Y', 27=>'Y', 28=>'Y', 29=>'Y', 30=>'Y', 31=>'D', 32=>'Y', 33=>'D', 34=>'D', 35=>'Y', 36=>'Y', 37=>'Y', 38=>'D', 39=>'D', 40=>'Y', 41=>'D', 42=>'Y', 43=>'Y', 44=>'Y', 45=>'D', 46=>'D', 47=>'D', 48=>'Y', 49=>'Y', 50=>'D', 51=>'D', 52=>'Y', 53=>'Y', 54=>'Y', 55=>'Y', 56=>'D', 57=>'D', 58=>'Y', 59=>'Y', 60=>'D', 61=>'D', 62=>'Y', 63=>'D', 64=>'D', 65=>'D', 66=>'Y', 67=>'Y', 68=>'Y', 69=>'Y', 70=>'Y', 71=>'Y', 72=>'Y', 73=>'Y' ];

    // Skor Hesaplama
    foreach ($subscales as $key => $subscale) {
        $score = 0;
        $itemCount = count($subscale['items']); // Madde sayısını al
        $subscaleItemCounts[$key] = $itemCount; // Grafik veya bilgi için sakla
        foreach ($subscale['items'] as $sortOrder) {
            $participantAns = $participantAnswers[$sortOrder] ?? '';
            $keyAns = $answerKey[$sortOrder] ?? 'ANAHTAR_YOK';
            if (($participantAns === 'Doğru' && $keyAns !== 'D') || ($participantAns === 'Yanlış' && $keyAns !== 'Y')) {
                $score++;
            }
        }
        $subscaleScores[$key] = $score;
        // Grafik için veri hazırla
        $chartLabels[] = $subscale['title']; // Tam başlığı etiket olarak kullan
        $chartData[] = $score;
    }

    // Yorumlama
    // A
    $scoreA = $subscaleScores['A']; if ($scoreA >= 10) $subscaleInterpretations['A'] = "Ciddi problemleriniz var."; elseif ($scoreA >= 5) $subscaleInterpretations['A'] = "Bazı güçlükleriniz var."; else $subscaleInterpretations['A'] = "Önemli bir güçlüğünüz yok.";
    // B
    $scoreB = $subscaleScores['B']; if ($scoreB >= 5) $subscaleInterpretations['B'] = "Önemli eksikleriniz var."; elseif ($scoreB >= 3) $subscaleInterpretations['B'] = "Bazı eksikleriniz var."; else $subscaleInterpretations['B'] = "Bilinçli çalışıyorsunuz.";
    // C
    $scoreC = $subscaleScores['C']; if ($scoreC >= 6) $subscaleInterpretations['C'] = "Not tutma/dinleme etkisi yeterince bilinmiyor."; elseif ($scoreC >= 3) $subscaleInterpretations['C'] = "Not tutma/dinleme konusunda bazı hatalar var."; else $subscaleInterpretations['C'] = "Not tutma/dinleme konusunda başarılısınız.";
    // D
    $scoreD = $subscaleScores['D']; if ($scoreD >= 8) $subscaleInterpretations['D'] = "Okuma becerilerini geliştirmeye özel önem vermelisiniz."; elseif ($scoreD >= 4) $subscaleInterpretations['D'] = "Okuma hızını/seçiciliği artırmak faydalı olabilir."; else $subscaleInterpretations['D'] = "Okuma alışkanlıklarınız olumlu.";
    // E
    $scoreE = $subscaleScores['E']; if ($scoreE >= 5) $subscaleInterpretations['E'] = "Ödevlerin öneminin farkında değilsiniz."; elseif ($scoreE >= 3) $subscaleInterpretations['E'] = "Ödev hazırlamada zaman zaman güçlük çekiyorsunuz."; else $subscaleInterpretations['E'] = "Ödevlerin önemini kavramışsınız.";
    // F
    $scoreF = $subscaleScores['F']; if ($scoreF >= 5) $subscaleInterpretations['F'] = "Okula karşı tutumunuz başarıyı güçleştiriyor."; elseif ($scoreF >= 3) $subscaleInterpretations['F'] = "Okula karşı olumsuz duygu/düşünceleriniz var."; else $subscaleInterpretations['F'] = "Okula karşı olumlu bir tavrınız var.";
    // G
    $scoreG = $subscaleScores['G']; if ($scoreG >= 8) $subscaleInterpretations['G'] = "Sınav teknik ve taktiklerini yeterince bilmiyorsunuz."; elseif ($scoreG >= 4) $subscaleInterpretations['G'] = "Sınavlar konusunda bazı eksikleriniz var."; else $subscaleInterpretations['G'] = "Sınav teknik ve taktiklerini iyi biliyorsunuz.";

    // JSON formatında grafik verisi
    $chartLabelsJSON = json_encode($chartLabels);
    $chartDataJSON = json_encode($chartData);
}
// --- Bitiş: Skorlama ve Yorumlama ---

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($testTitle) ?> Sonucu</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Stil Bloğu (Önceki view-result stilinden alındı) */
        body { font-family: sans-serif; line-height: 1.6; background-color: #f0fdf4; color: #333; margin: 0; padding: 20px; }
        .container { max-width: 900px; margin: 30px auto; padding: 25px 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); background-color: #ffffff; }
        h1 { color: #166534; text-align: center; margin-bottom: 10px; font-size: 1.75rem; border-bottom: 2px solid #dcfce7; padding-bottom: 10px;}
        h2 { color: #15803d; text-align: center; margin-bottom: 25px; font-size: 1.25rem; font-weight: 600;}
        h3 { font-size: 1.2rem; /* Biraz büyütüldü */ font-weight: 600; color: #1e3a8a; margin-top: 1.75rem; /* Üst boşluk artırıldı */ margin-bottom: 1rem; /* Alt boşluk artırıldı */ border-bottom: 1px solid #e5e7eb; padding-bottom: 8px;}
        .info-section, .result-section { margin-bottom: 25px; padding: 20px; background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; }
        .info-section p { margin-bottom: 8px; color: #374151; }
        .info-section strong { color: #111827; font-weight: 600; margin-right: 5px;}
        .result-section .subscale-block { margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px dashed #d1d5db; }
        .result-section .subscale-block:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0;}
        .result-section strong { color: #111827; font-weight: 600;}
        .subscale-title { font-size: 1.1rem; font-weight: 600; color: #1e40af; margin-bottom: 0.5rem;}
        .subscale-score { font-weight: bold; color: #1d4ed8; }
        .interpretation { font-style: italic; color: #4b5563; margin-top: 5px; display: block; }
        .error-message { color: #b91c1c; background-color: #fee2e2; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; border: 1px solid #fca5a5; font-weight: bold; text-align: center; }
        .timestamp { font-size: 0.85rem; color: #6b7280; text-align: right; margin-top: 15px;}
        .nav-btn { padding: 10px 20px; border-radius: 6px; font-weight: bold; cursor: pointer; border: none; transition: background-color 0.2s ease; display: inline-flex; align-items: center;}
        .nav-btn svg { width: 1.25em; height: 1.25em; margin-right: 0.5em;}
        .chart-container { width: 100%; max-width: 600px; /* Grafik genişliği artırıldı */ margin: 30px auto; padding: 15px; border: 1px solid #e5e7eb; border-radius: 8px; background-color: #f9fafb;}
    </style>
</head>
<body class="bg-[#f0fdf4] min-h-screen p-4">
    <div class="container">
        <h1><?= htmlspecialchars($testTitle) ?> Sonucu</h1>

        <?php if (!empty($error)): // Hata varsa göster ?>
            <div class="error-message">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php elseif ($participant): // Hata yoksa ve katılımcı bulunduysa ?>
            <h2><?= htmlspecialchars($participant['name']) ?></h2>

            <div class="info-section">
                <p><strong>Sınıf:</strong> <?= htmlspecialchars($participant['class']) ?></p>
                 <p class="timestamp"><strong>Test Tarihi:</strong>
                    <?= isset($participant['created_at']) ? htmlspecialchars(date('d.m.Y H:i', strtotime($participant['created_at']))) : 'Bilinmiyor' ?>
                 </p>
            </div>

            <h3>Puan Dağılımı Grafiği</h3>
            <div class="chart-container">
                 <canvas id="studyBehaviorChart"></canvas>
            </div>

            <div class="result-section">
                <h3 class="text-center !border-b-0 !mb-5">Alt Ölçek Puanları ve Yorumları</h3>
                <?php foreach ($subscales as $key => $subscale): ?>
                    <div class="subscale-block">
                        <p class="subscale-title"><?= htmlspecialchars($subscale['title']) ?></p>
                        <p>
                            <strong>Alınan Puan:</strong>
                            <span class="subscale-score"><?= $subscaleScores[$key] ?? 'N/A' ?></span>
                            <span class="text-gray-500 text-sm"> (<?= $subscaleItemCounts[$key] ?? '?' ?> maddeden)</span>
                            <span class="text-gray-500 text-sm"> - *Düşük puan daha iyi durumu gösterir.*</span>
                        </p>
                        <p>
                            <strong>Yorum:</strong>
                            <span class="interpretation"><?= htmlspecialchars($subscaleInterpretations[$key] ?? 'Yorum yok.') ?></span>
                        </p>
                    </div>
                <?php endforeach; ?>
            </div>

             <?php /* // Cevapları göstermek için eski kod (opsiyonel)
            <h3>Verilen Yanıtlar</h3>
            <table> ... </table>
            */ ?>

        <?php else: ?>
            <p class="text-center text-gray-600">Sonuç bilgisi bulunamadı.</p>
        <?php endif; ?>

         <div class="text-center mt-8 space-x-4">
             <button onclick="window.print();" class="nav-btn bg-gray-500 hover:bg-gray-600 text-white">
                 <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 4v3H4a2 2 0 00-2 2v6a2 2 0 002 2h12a2 2 0 002-2V9a2 2 0 00-2-2h-1V4a2 2 0 00-2-2H7a2 2 0 00-2 2zm2 0h6v3H7V4zm6 6H7v1a1 1 0 100 2h6a1 1 0 100-2v-1zm-3 4a1 1 0 100 2h.01a1 1 0 100-2H10z" clip-rule="evenodd" /></svg>
                 Yazdır
             </button>
             <a href="dashboard.php" class="nav-btn bg-blue-500 hover:bg-blue-600 text-white">
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm.707-10.293a1 1 0 00-1.414-1.414l-3 3a1 1 0 000 1.414l3 3a1 1 0 001.414-1.414L9.414 11H13a1 1 0 100-2H9.414l1.293-1.293z" clip-rule="evenodd" /></svg>
                 Panele Dön
            </a>
         </div>

    </div> <?php // Grafik için JavaScript kodu (sadece veri varsa çalışır) ?>
    <?php if (!$error && $participant && !empty($participantAnswers)): ?>
    <script>
        const ctx = document.getElementById('studyBehaviorChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar', // Çubuk grafik
            data: {
                labels: <?= $chartLabelsJSON ?? '[]' ?>, // PHP'den gelen etiketler
                datasets: [{
                    label: 'Alınan Puan (Düşük Puan Daha İyi)', // Grafik açıklaması
                    data: <?= $chartDataJSON ?? '[]' ?>, // PHP'den gelen puanlar
                    backgroundColor: [ // Farklı renkler kullanılabilir
                        'rgba(255, 99, 132, 0.6)', // A
                        'rgba(54, 162, 235, 0.6)', // B
                        'rgba(255, 206, 86, 0.6)', // C
                        'rgba(75, 192, 192, 0.6)', // D
                        'rgba(153, 102, 255, 0.6)',// E
                        'rgba(255, 159, 64, 0.6)', // F
                        'rgba(199, 199, 199, 0.6)' // G
                    ],
                    borderColor: [ // Kenarlık renkleri
                         'rgba(255, 99, 132, 1)',
                         'rgba(54, 162, 235, 1)',
                         'rgba(255, 206, 86, 1)',
                         'rgba(75, 192, 192, 1)',
                         'rgba(153, 102, 255, 1)',
                         'rgba(255, 159, 64, 1)',
                         'rgba(199, 199, 199, 1)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true, // Oranı koru
                indexAxis: 'y', // Yatay çubuk grafik için 'y' yapabilirsiniz
                scales: {
                    x: { // X ekseni (Puanlar)
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Puan (Daha Düşük = Daha İyi)'
                        }
                        // Max değeri dinamik olarak ayarlanabilir veya sabit bırakılabilir
                         // max: 16 // En yüksek madde sayısına göre
                    },
                     y: { // Y ekseni (Alt Ölçekler)
                         title: {
                             display: true,
                             text: 'Çalışma Davranışı Alanları'
                         }
                     }
                },
                plugins: {
                    legend: {
                        display: false // Tek veri seti olduğu için legend gereksiz
                    },
                    tooltip: {
                         callbacks: {
                             label: function(context) {
                                 let label = context.dataset.label || '';
                                 if (label) {
                                     label += ': ';
                                 }
                                 if (context.parsed.x !== null) {
                                     label += context.parsed.x;
                                     // İlgili alt ölçeğin toplam madde sayısını ekleyebiliriz
                                     // Bunun için PHP'den subscaleItemCounts'ı JS'e aktarmak gerekir.
                                     // label += ' / MAX_SORU';
                                 }
                                 return label;
                             }
                         }
                    }
                }
            }
        });
    </script>
    <?php endif; ?>

</body>
</html>