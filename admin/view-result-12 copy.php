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
$surveyId = 12;
$testTitle = "Çoklu Zeka Testi";

$participantId = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : 0;
if ($participantId <= 0) { die('Geçersiz katılımcı ID\'si.'); }

$participant = null; $error = null; $participantAnswers = [];
$intelligenceScores = []; $dominantIntelligences = []; $interpretations = [];
$chartLabels = []; $chartData = []; $institutionLogoPath = null;
$maxPossibleScore = 32;

try {
    // Katılımcı bilgilerini VE İLİŞKİLİ ADMİNİN LOGO YOLUNU çek
    $stmt = $pdo->prepare("
        SELECT sp.*, u.institution_logo_path
        FROM survey_participants sp
        LEFT JOIN users u ON sp.admin_id = u.id
        WHERE sp.id = ? AND sp.survey_id = ?
    ");
    $stmt->execute([$participantId, $surveyId]);
    $participant = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$participant) throw new Exception('Test sonucu bulunamadı.');

    $institutionLogoPath = $participant['institution_logo_path']; // Logo yolunu al

    // Yetkilendirme
    $isSuperAdmin = false; // Varsayım
    if ($participant['admin_id'] != $loggedInUserId && !$isSuperAdmin) {
        throw new Exception('Bu sonucu görüntüleme yetkiniz yok.');
    }

    // Cevapları çek
    $stmtAnswers = $pdo->prepare("SELECT q.sort_order, a.answer_text FROM survey_answers a JOIN survey_questions q ON a.question_id = q.id WHERE a.participant_id = ? AND q.survey_id = ? ORDER BY q.sort_order ASC");
    $stmtAnswers->execute([$participantId, $surveyId]);
    $participantAnswers = $stmtAnswers->fetchAll(PDO::FETCH_KEY_PAIR);
    if (count($participantAnswers) !== 56 && count($participantAnswers) > 0) { error_log("Warning: Participant $participantId survey $surveyId answer count mismatch."); }
    if (count($participantAnswers) === 0) throw new Exception("Katılımcı için cevap bulunamadı.");

    // --- Skorlama ve Yorumlama ---
    $intelligenceQuestionMap = [ 'Sözel-Dilsel Zeka' => [6, 8, 9, 23, 31, 33, 52, 60], 'Mantıksal-Matematiksel Zeka' => [5, 10, 11, 20, 29, 40, 49, 54], 'Müziksel Zeka' => [2, 4, 13, 18, 25, 30, 39, 51], 'Bedensel-Kinestetik Zeka' => [3, 7, 15, 22, 34, 38, 47, 53], 'Görsel-Uzamsal Zeka' => [21, 24, 26, 37, 44, 45, 48, 57], 'Sosyal Zeka' => [19, 27, 36, 43, 46, 58, 61, 68], 'İçsel Zeka' => [1, 12, 16, 28, 41, 50, 55, 56] ];
    $scoreMap = [ 'Çoğunlukla Katılmıyorum' => 1, 'Biraz Katılmıyorum' => 2, 'Biraz Katılıyorum' => 3, 'Çoğunlukla Katılıyorum' => 4 ];
    foreach ($intelligenceQuestionMap as $name => $orders) { $score = 0; foreach ($orders as $order) { $ans = $participantAnswers[$order] ?? null; if (isset($ans) && isset($scoreMap[$ans])) $score += $scoreMap[$ans]; } $intelligenceScores[$name] = $score; }
    if (!empty($intelligenceScores)) { $maxScore = max($intelligenceScores); $dominantKeys = array_keys($intelligenceScores, $maxScore); $interpretationTexts = [ /* ... Yorum metinleri önceki yanıttaki gibi ... */ 'Sözel-Dilsel Zeka' => 'Kelime ve dil becerisi...', 'Mantıksal-Matematiksel Zeka' => 'Mantık ve matematiksel düşünme...', 'Müziksel Zeka' => 'Müzik ve ses algısı...', 'Bedensel-Kinestetik Zeka' => 'Beden hareketleri ve fiziksel beceri...', 'Görsel-Uzamsal Zeka' => 'Görsel ve mekansal algı...', 'Sosyal Zeka' => 'İnsan ilişkileri ve empati...', 'İçsel Zeka' => 'Öz farkındalık ve iç gözlem...' ]; foreach ($dominantKeys as $key) { if (isset($interpretationTexts[$key])) $dominantIntelligences[$key] = $interpretationTexts[$key]; } }
    $chartLabels = array_keys($intelligenceScores); $chartData = array_values($intelligenceScores);
    $chartLabelsJSON = json_encode($chartLabels); $chartDataJSON = json_encode($chartData);
    // --- Bitiş: Skorlama ve Yorumlama ---

} catch (Exception $e) {
    $error = "Veri alınırken veya işlenirken hata: " . $e->getMessage();
    error_log("Result Processing/Fetch Error (Survey $surveyId, Participant $participantId): " . $e->getMessage());
    $participant = null;
}
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
        /* Stil Bloğu */
        body { font-family: sans-serif; line-height: 1.6; background-color: #f0fdf4; color: #333; margin: 0; padding: 20px; }
        .container { max-width: 900px; margin: 30px auto; padding: 25px 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); background-color: #ffffff; }
        h1 { color: #166534; text-align: center; margin-bottom: 10px; font-size: 1.75rem; border-bottom: 2px solid #dcfce7; padding-bottom: 10px;}
        h2 { color: #15803d; text-align: center; margin-bottom: 25px; font-size: 1.25rem; font-weight: 600;}
        h3 { font-size: 1.2rem; font-weight: 600; color: #1e3a8a; margin-top: 1.75rem; margin-bottom: 1rem; border-bottom: 1px solid #e5e7eb; padding-bottom: 8px;}
        .info-section, .result-section { margin-bottom: 20px; padding: 20px; background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; }
        .info-section p { margin-bottom: 8px; color: #374151; }
        .info-section strong { color: #111827; font-weight: 600; margin-right: 5px;}
        .result-section ul { list-style: none; padding: 0; margin: 0;}
        .result-section li { padding: 10px 0; border-bottom: 1px dashed #d1d5db; }
        .result-section li:last-child { border-bottom: none; }
        .result-section strong { color: #111827; font-weight: 600; }
        .interpretation-section { margin-top: 25px; padding: 15px; background-color: #eefbf3; border-left: 4px solid #16a34a; border-radius: 4px; }
        .interpretation-section h4 { font-size: 1.1rem; font-weight: 600; color: #14532d; margin-bottom: 0.75rem; }
        .interpretation-section p { color: #166534; margin-bottom: 0.5rem; }
        .error-message { color: #b91c1c; background-color: #fee2e2; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; border: 1px solid #fca5a5; font-weight: bold; text-align: center; }
        .timestamp { font-size: 0.85rem; color: #6b7280; text-align: right; margin-top: 15px;}
        .nav-btn { padding: 10px 20px; border-radius: 6px; font-weight: bold; cursor: pointer; border: none; transition: background-color 0.2s ease; display: inline-flex; align-items: center;}
        .nav-btn svg { width: 1.25em; height: 1.25em; margin-right: 0.5em;}
        .chart-container { width: 100%; max-width: 700px; margin: 30px auto; padding: 15px; border: 1px solid #e5e7eb; border-radius: 8px; background-color: #f9fafb;}
        .note { font-size: 0.8rem; color: #555; margin-top: 1rem; }
        .footer-controls { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 1.5rem;}

        /* --- Yazdırma için Logo Stilleri --- */
        .print-logo {
            display: none; /* Varsayılan olarak gizli */
        }

        @media print {
            body { background-color: #fff; padding: 0; margin: 10mm;}
            nav, footer, .nav-btn, .note { display: none; } /* Gereksiz elemanları gizle */
            .container { max-width: 100%; margin: 0; padding: 0; border: none; box-shadow: none; }
            h1, h2, h3 { text-align: left; margin-bottom: 10px; }
            h1 { font-size: 16pt; padding-bottom: 5px; border-bottom: 1px solid #ccc;}
            h2 { font-size: 12pt; margin-bottom: 15px; }
            h3 { font-size: 11pt; margin-top: 1rem; margin-bottom: 0.5rem; border-bottom: none; }
            .info-section, .result-section, .chart-container, .interpretation-section { border: 1px solid #eee; margin-bottom: 15px; padding: 10px; background-color: #fff !important; }
            .chart-container { max-width: 95%; page-break-inside: avoid; margin: 15px auto;}
            .result-section li { padding: 5px 0; }
            .interpretation-section { background-color: #f8f8f8 !important; border-left: 3px solid #ccc; }
            .timestamp { text-align: left; margin-top: 10px; font-size: 8pt; }
            .footer-controls { justify-content: flex-start; margin-top: 20px; page-break-inside: avoid; } /* Logo ve butonları ayarla */

            .print-logo {
                display: block !important; /* Yazdırmada GÖSTER */
            }
            .institution-logo {
                 max-height: 60px; /* Yazdırmada boyut */
                 width: auto;
                 display: block;
                 margin-bottom: 15px; /* Başlıktan önce boşluk */
                 margin-left: auto; /* Ortalama */
                 margin-right: auto; /* Ortalama */
            }
            .psikometrik-logo {
                max-height: 30px; /* Yazdırmada boyut */
                width: auto;
                display: block;
                margin-top: 10px; /* Butonlardan sonra boşluk */
            }
        }
        /* --- Bitiş: Yazdırma Stilleri --- */

    </style>
</head>
<body class="bg-[#f0fdf4] min-h-screen p-4">
    <div class="container">

         <?php if ($participant && !empty($institutionLogoPath) && file_exists('../' . $institutionLogoPath)): ?>
            <img src="../<?= htmlspecialchars($institutionLogoPath) ?>?t=<?= time() ?>" alt="Kurum Logosu" class="print-logo institution-logo">
         <?php endif; ?>
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

            <h3>Zeka Alanı Puanları Grafiği</h3>
            <div class="chart-container">
                 <?php if (!empty($intelligenceScores)): ?>
                     <canvas id="intelligenceChart"></canvas>
                     <p class="note text-center">*Grafik, hesaplanan zeka alanlarının puanlarını göstermektedir. (Maksimum puan: <?= $maxPossibleScore ?>)</p>
                 <?php else: ?>
                     <p class="text-center text-gray-500">Grafik oluşturmak için yeterli veri bulunamadı.</p>
                 <?php endif; ?>
            </div>

            <div class="result-section">
                <h3 class="!border-b-0 !mb-4">Zeka Alanı Puanları</h3>
                 <ul>
                     <?php foreach ($intelligenceScores as $name => $score): ?>
                        <li>
                            <strong><?= htmlspecialchars($name) ?>:</strong>
                            <span class="font-bold text-lg text-blue-600 ml-2"><?= $score ?></span> / <?= $maxPossibleScore ?>
                        </li>
                     <?php endforeach; ?>
                 </ul>
            </div>

             <?php if (!empty($dominantIntelligences)): ?>
                 <div class="interpretation-section">
                     <h4>Baskın Zeka Alan(lar)ı ve Açıklaması:</h4>
                     <?php foreach($dominantIntelligences as $name => $description): ?>
                        <div class="mb-3">
                            <p class="font-semibold text-green-800"><?= htmlspecialchars($name) ?></p>
                            <p class="text-sm"><?= htmlspecialchars($description) ?></p>
                        </div>
                     <?php endforeach; ?>
                 </div>
             <?php endif; ?>

        <?php else: ?>
            <p class="text-center text-gray-600">Sonuç bilgisi bulunamadı.</p>
        <?php endif; ?>

         <div class="footer-controls">
              <img src="https://psikometrik.net/assets/Psikometrik.png" alt="Psikometrik.Net Logo" class="print-logo psikometrik-logo">
               <div class="text-right space-x-4 nav-btn-container">
                  <button onclick="window.print();" class="nav-btn bg-gray-500 hover:bg-gray-600 text-white">
                      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 4v3H4a2 2 0 00-2 2v6a2 2 0 002 2h12a2 2 0 002-2V9a2 2 0 00-2-2h-1V4a2 2 0 00-2-2H7a2 2 0 00-2 2zm2 0h6v3H7V4zm6 6H7v1a1 1 0 100 2h6a1 1 0 100-2v-1zm-3 4a1 1 0 100 2h.01a1 1 0 100-2H10z" clip-rule="evenodd" /></svg>
                      Yazdır
                  </button>
                  <a href="dashboard.php" class="nav-btn bg-blue-500 hover:bg-blue-600 text-white">
                       <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm.707-10.293a1 1 0 00-1.414-1.414l-3 3a1 1 0 000 1.414l3 3a1 1 0 001.414-1.414L9.414 11H13a1 1 0 100-2H9.414l1.293-1.293z" clip-rule="evenodd" /></svg>
                       Panele Dön
                 </a>
              </div>
        </div>


    </div> <?php // Grafik için JavaScript kodu ?>
    <?php if (!$error && $participant && !empty($intelligenceScores)): ?>
    <script>
        const ctx = document.getElementById('intelligenceChart').getContext('2d');
        const maxPossibleScore = <?= $maxPossibleScore ?>;

        new Chart(ctx, {
            type: 'bar',
            data: { /* ... Önceki grafik verisi ... */
                labels: <?= $chartLabelsJSON ?? '[]' ?>,
                datasets: [{
                    label: 'Puan', data: <?= $chartDataJSON ?? '[]' ?>,
                    backgroundColor: ['rgba(239, 68, 68, 0.7)', 'rgba(59, 130, 246, 0.7)', 'rgba(245, 158, 11, 0.7)', 'rgba(34, 197, 94, 0.7)', 'rgba(219, 39, 119, 0.7)', 'rgba(139, 92, 246, 0.7)', 'rgba(249, 115, 22, 0.7)' ],
                    borderColor: [ 'rgba(239, 68, 68, 1)', 'rgba(59, 130, 246, 1)', 'rgba(245, 158, 11, 1)', 'rgba(34, 197, 94, 1)', 'rgba(219, 39, 119, 1)', 'rgba(139, 92, 246, 1)', 'rgba(249, 115, 22, 1)' ],
                    borderWidth: 1
                }]
            },
            options: { /* ... önceki grafik seçenekleri ... */
                 responsive: true, maintainAspectRatio: true,
                 scales: { y: { beginAtZero: true, max: maxPossibleScore, title: { display: true, text: 'Puan (Maksimum ' + maxPossibleScore + ')' } }, x: { title: { display: false } } },
                 plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(context) { let label = context.dataset.label || ''; if (label) { label += ': '; } if (context.parsed.y !== null) { label += context.parsed.y + ' / ' + maxPossibleScore; } return label; } } } }
            }
        });
    </script>
    <?php endif; ?>

</body>
</html>