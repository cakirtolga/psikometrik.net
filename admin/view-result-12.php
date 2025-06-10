<?php
session_start(); // Oturumu başlat
ini_set('display_errors', 1); // Hata gösterimi (geliştirme için)
error_reporting(E_ALL);
header('Content-Type: text/html; charset=utf-8'); // Karakter seti

// Config dosyasının doğru yolda olduğundan emin olun (admin/ içinden çağrılıyor)
require '../src/config.php';

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

// Değişkenleri başlat
$participant = null; $error = null; $participantAnswers = [];
$intelligenceScores = []; $dominantIntelligences = []; $interpretations = [];
$chartLabelsJSON = '[]'; $chartDataJSON = '[]'; // Başlangıçta boş JSON string'i
$institutionLogoPath = null; $institutionName = null;
$maxPossibleScore = 32;

try {
    // Katılımcı bilgilerini VE İLİŞKİLİ ADMİNİN LOGO YOLUNU/ADINI çek
    $stmt = $pdo->prepare("
        SELECT sp.*, u.institution_logo_path, u.institution_name
        FROM survey_participants sp
        LEFT JOIN users u ON sp.admin_id = u.id
        WHERE sp.id = ? AND sp.survey_id = ?
    ");
    $stmt->execute([$participantId, $surveyId]);
    $participant = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$participant) throw new Exception('Test sonucu bulunamadı.');

    $institutionLogoPath = $participant['institution_logo_path'];
    $institutionName = $participant['institution_name'];

    // Yetkilendirme
    $isSuperAdmin = (isset($_SESSION['role']) && $_SESSION['role'] === 'super-admin');
    if ($participant['admin_id'] != $loggedInUserId && !$isSuperAdmin) {
        throw new Exception('Bu sonucu görüntüleme yetkiniz yok.');
    }

    // Cevapları çek
    $stmtAnswers = $pdo->prepare("SELECT q.sort_order, a.answer_text FROM survey_answers a JOIN survey_questions q ON a.question_id = q.id WHERE a.participant_id = ? AND q.survey_id = ? ORDER BY q.sort_order ASC");
    $stmtAnswers->execute([$participantId, $surveyId]);
    $participantAnswers = $stmtAnswers->fetchAll(PDO::FETCH_KEY_PAIR);
    if (count($participantAnswers) !== 56 && count($participantAnswers) > 0) { error_log("Warning: Participant $participantId survey $surveyId answer count mismatch. Found: ".count($participantAnswers)); }
    if (count($participantAnswers) === 0) throw new Exception("Katılımcı için cevap bulunamadı.");

    // --- Skorlama ve Yorumlama ---
    $intelligenceQuestionMap = [ 'Sözel-Dilsel Zeka' => [6, 8, 9, 23, 31, 33, 52, 60], 'Mantıksal-Matematiksel Zeka' => [5, 10, 11, 20, 29, 40, 49, 54], 'Müziksel Zeka' => [2, 4, 13, 18, 25, 30, 39, 51], 'Bedensel-Kinestetik Zeka' => [3, 7, 15, 22, 34, 38, 47, 53], 'Görsel-Uzamsal Zeka' => [21, 24, 26, 37, 44, 45, 48, 57], 'Sosyal Zeka' => [19, 27, 36, 43, 46, 58, 61, 68], 'İçsel Zeka' => [1, 12, 16, 28, 41, 50, 55, 56] ];
    $scoreMap = [ 'Çoğunlukla Katılmıyorum' => 1, 'Biraz Katılmıyorum' => 2, 'Biraz Katılıyorum' => 3, 'Çoğunlukla Katılıyorum' => 4 ];
    foreach ($intelligenceQuestionMap as $name => $orders) { $score = 0; foreach ($orders as $order) { $ans = $participantAnswers[$order] ?? null; if (isset($ans) && isset($scoreMap[$ans])) $score += $scoreMap[$ans]; } $intelligenceScores[$name] = $score; }
    if (!empty($intelligenceScores)) { $maxScore = max($intelligenceScores); $dominantKeys = array_keys($intelligenceScores, $maxScore); $interpretationTexts = [ 'Sözel-Dilsel Zeka' => 'Kelime ve dil becerisi...', 'Mantıksal-Matematiksel Zeka' => 'Mantık ve matematiksel düşünme...', 'Müziksel Zeka' => 'Müzik ve ses algısı...', 'Bedensel-Kinestetik Zeka' => 'Beden hareketleri...', 'Görsel-Uzamsal Zeka' => 'Görsel ve mekansal algı...', 'Sosyal Zeka' => 'İnsan ilişkileri ve empati...', 'İçsel Zeka' => 'Öz farkındalık...' ]; foreach ($dominantKeys as $key) { if (isset($interpretationTexts[$key])) $dominantIntelligences[$key] = $interpretationTexts[$key]; } }

    // Grafik için JSON verisi (Hata kontrolü ile)
    if (!empty($intelligenceScores)) {
        $chartLabels = array_keys($intelligenceScores); $chartData = array_values($intelligenceScores);
        try {
            $chartLabelsJSON = json_encode($chartLabels, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $chartDataJSON = json_encode($chartData, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) { $error = ($error ? $error." " : "")."Grafik verisi oluşturulamadı."; error_log("JSON Encode Error (Survey $surveyId): " . $e->getMessage()); $chartLabelsJSON = '[]'; $chartDataJSON = '[]'; }
    }
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
    <title><?= $testTitle ? htmlspecialchars($testTitle) : 'Test Sonucu' ?> Sonucu</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js" defer></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Stil Bloğu (Yazdırma için logo konumları güncellendi) */
        body { font-family: sans-serif; line-height: 1.5; background-color: #f0fdf4; color: #333; margin: 0; padding: 20px; }
        .container { max-width: 900px; margin: 20px auto; padding: 25px 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); background-color: #ffffff; }
        h1 { color: #166534; text-align: center; margin-bottom: 10px; font-size: 1.6rem; border-bottom: 1px solid #dcfce7; padding-bottom: 8px;}
        h2 { color: #15803d; text-align: center; margin-bottom: 20px; font-size: 1.15rem; font-weight: 600;}
        h3 { font-size: 1.1rem; font-weight: 600; color: #1e3a8a; margin-top: 1.5rem; margin-bottom: 0.8rem; border-bottom: 1px solid #e5e7eb; padding-bottom: 6px;}
        .info-section, .result-section { margin-bottom: 15px; padding: 15px; background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; }
        .info-section p, .result-section p { margin-bottom: 6px; color: #374151; font-size: 0.9rem;}
        .info-section strong, .result-section strong { color: #111827; font-weight: 600; margin-right: 4px;}
        .result-section ul { list-style: none; padding: 0; margin: 0;}
        .result-section li { padding: 8px 0; border-bottom: 1px dashed #d1d5db; font-size: 0.9rem;}
        .result-section li:last-child { border-bottom: none; }
        .interpretation-section { margin-top: 20px; padding: 15px; background-color: #eefbf3; border-left: 3px solid #16a34a; border-radius: 4px; }
        .interpretation-section h4 { font-size: 1rem; font-weight: 600; color: #14532d; margin-bottom: 0.5rem; }
        .interpretation-section p { color: #166534; margin-bottom: 0.5rem; font-size: 0.9rem; }
        .error-message { color: #b91c1c; background-color: #fee2e2; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; border: 1px solid #fca5a5; font-weight: bold; text-align: center; }
        .timestamp { font-size: 0.8rem; color: #6b7280; text-align: right; margin-top: 10px;}
        .nav-btn { padding: 8px 16px; border-radius: 5px; font-weight: 600; cursor: pointer; border: none; transition: background-color 0.2s ease; display: inline-flex; align-items: center; font-size: 0.9rem; text-decoration: none; color: white; }
        .nav-btn svg { width: 1.1em; height: 1.1em; margin-right: 0.4em;}
        .chart-container { width: 100%; max-width: 650px; margin: 20px auto; padding: 10px; border: 1px solid #e5e7eb; border-radius: 8px; background-color: #f9fafb;}
        .note { font-size: 0.75rem; color: #555; margin-top: 0.5rem; }
        .print-only { display: none; } /* Logoları normalde gizle */
        .nav-btn-container { display: flex; justify-content: flex-end; align-items: center; }

        @media print {
            body { background-color: #fff !important; color: #000 !important; padding: 0; margin: 0; font-size: 9pt; line-height: 1.2; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            nav, footer, .nav-btn-container, .note, h3 { display: none !important; }
             /* Container ayarları yazdırma için */
             .container { max-width: 100%; width: 100%; margin: 0; padding: 10mm; /* Kenar boşlukları */ border: none !important; box-shadow: none !important; background-color: #fff !important; }
             /* Yazdırma başlığı (logolar için) */
             .print-header { display: flex !important; justify-content: space-between !important; align-items: flex-start !important; margin-bottom: 5mm; width: 100%; height: 45px; /* Yüksekliği ayarla */ overflow: hidden; }
             h1 { font-size: 14pt; margin-top: 0; margin-bottom: 5mm; text-align: center !important; border: none !important; padding: 0; color: #000 !important;}
             h2 { font-size: 11pt; margin-bottom: 8mm; text-align: center !important; color: #000 !important;}
             .info-section, .result-section, .chart-container, .interpretation-section { border: 1px solid #ccc !important; margin-bottom: 5mm; padding: 4mm; background-color: #fff !important; page-break-inside: avoid; }
             .info-section p, .result-section p, .result-section li { font-size: 9pt; margin-bottom: 1.5mm; padding: 2px 0; color: #000 !important; border-color: #eee !important; }
             .result-section strong { color: #000 !important; }
             .interpretation-section { background-color: #f0f0f0 !important; border-left: 3px solid #aaa !important; }
             .interpretation-section h4 { font-size: 10pt; margin-bottom: 2mm; display: block !important; color: #000 !important; }
             .interpretation-section p { font-size: 9pt; color: #000 !important;}
             .timestamp { font-size: 7pt; text-align: left; margin-top: 5mm; color: #333 !important;}
             .chart-container { max-width: 100%; border: none !important; padding: 0; margin: 8mm auto; }
             canvas { max-width: 100%; max-height: 150px; }
             a { text-decoration: none !important; color: #000 !important; } /* Link altı çizgilerini kaldır */

             .print-only { display: block !important; } /* Yazdırmada GÖSTER */
            .institution-logo { /* Sol Üst */
        max-height: 50px !important; /* 45px'ten 50px'e çıkarıldı */
        width: auto;
        display: block;
        position: fixed; /* Konum sabitlendi */
        top: 10mm;
        left: 10mm;
    }

    .psikometrik-logo-print { /* Sağ Üst */
        max-height: 40px !important; /* 35px'ten 40px'e çıkarıldı */
        width: auto;
        display: block;
        position: fixed; /* Konum sabitlendi */
        top: 10mm;
        right: 10mm;
    }

    /* Container'ın üst boşluğunu logolara göre ayarla */
    .container {
        margin-top: 30px !important; /* Logoların yüksekliğine göre boşluk */
    }
}
    </style>
</head>
<body class="bg-[#f0fdf4] min-h-screen p-4">
    <div class="container">

         <div class="print-only print-header">
             <?php
             $logo_url = '';
             if ($participant && !empty($institutionLogoPath)) {
                 // Güvenlik ve dosya varlığı kontrolü
                 $potential_path = realpath(__DIR__ . '/../' . $institutionLogoPath);
                 if ($potential_path && strpos($potential_path, realpath(__DIR__ . '/../uploads/logos')) === 0 && file_exists($potential_path)) {
                     $logo_url = '../' . htmlspecialchars($institutionLogoPath) . '?t=' . time();
                 } else { error_log("Institution logo file not found or invalid path: " . $institutionLogoPath); }
             }
             ?>
             <?php if (!empty($logo_url)): ?>
                 <img src="<?= $logo_url ?>" alt="<?= htmlspecialchars($institutionName ?? 'Kurum Logosu') ?>" class="institution-logo">
             <?php else: ?>
                 <div>&nbsp;</div> <?php endif; ?>
             <img src="https://psikometrik.net/assets/Psikometrik.png" alt="Psikometrik.Net Logo" class="psikometrik-logo-print">
         </div>
         <h1><?= htmlspecialchars($testTitle) ?> Sonucu</h1>

        <?php if (!empty($error)): ?>
            <div class="error-message"> <?= htmlspecialchars($error) ?> </div>
        <?php elseif ($participant): ?>
            <h2><?= htmlspecialchars($participant['name']) ?></h2>

            <div class="info-section">
                <p><strong>Sınıf:</strong> <?= htmlspecialchars($participant['class']) ?></p>
                 <p class="timestamp"><strong>Test Tarihi:</strong>
                    <?= isset($participant['created_at']) ? htmlspecialchars(date('d.m.Y H:i', strtotime($participant['created_at']))) : 'Bilinmiyor' ?>
                 </p>
            </div>

            <h3>Zeka Alanı Puanları Grafiği</h3>
            <div class="chart-container">
                 <?php if (!empty($intelligenceScores) && $chartLabelsJSON !== '[]' && $chartDataJSON !== '[]'): ?>
                     <canvas id="intelligenceChart"></canvas>
                     <p class="note text-center">*Grafik, hesaplanan zeka alanlarının puanlarını göstermektedir. (Maksimum puan: <?= $maxPossibleScore ?>)</p>
                 <?php else: ?>
                     <p class="text-center text-gray-500 chart-error-msg">Grafik oluşturulamadı (veri eksik veya hatalı).</p>
                 <?php endif; ?>
            </div>

            <div class="result-section">
                <h3 class="!border-b-0 !mb-4">Zeka Alanı Puanları</h3>
                 <ul>
                     <?php if (!empty($intelligenceScores)): ?>
                         <?php foreach ($intelligenceScores as $name => $score): ?>
                            <li>
                                <strong><?= htmlspecialchars($name) ?>:</strong>
                                <span class="font-bold text-lg text-blue-600 ml-2"><?= $score ?></span> / <?= $maxPossibleScore ?>
                            </li>
                         <?php endforeach; ?>
                      <?php else: ?>
                         <li>Puanlar hesaplanamadı.</li>
                      <?php endif; ?>
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
              <div class="nav-btn-container">
                  <button onclick="window.print();" class="nav-btn bg-gray-500 hover:bg-gray-600 text-white mr-3">
                      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 4v3H4a2 2 0 00-2 2v6a2 2 0 002 2h12a2 2 0 002-2V9a2 2 0 00-2-2h-1V4a2 2 0 00-2-2H7a2 2 0 00-2 2zm2 0h6v3H7V4zm6 6H7v1a1 1 0 100 2h6a1 1 0 100-2v-1zm-3 4a1 1 0 100 2h.01a1 1 0 100-2H10z" clip-rule="evenodd" /></svg>
                      Yazdır
                  </button>
                  <a href="dashboard.php" class="nav-btn bg-blue-500 hover:bg-blue-600 text-white">
                       <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm.707-10.293a1 1 0 00-1.414-1.414l-3 3a1 1 0 000 1.414l3 3a1 1 0 001.414-1.414L9.414 11H13a1 1 0 100-2H9.414l1.293-1.293z" clip-rule="evenodd" /></svg>
                       Panele Dön
                 </a>
              </div>
        </div>


    </div> <?php // Grafik için JavaScript kodu (En son haliyle) ?>
    <?php if (!$error && $participant && !empty($intelligenceScores)): ?>
    <script>
        document.addEventListener('DOMContentLoaded', (event) => {
            const canvasElement = document.getElementById('intelligenceChart');
            let chartLabelsData = [];
            let chartDataValues = [];

            // Parse JSON data safely
            try {
                const labelsJsonString = <?= $chartLabelsJSON ?? '[]' ?>;
                const dataJsonString = <?= $chartDataJSON ?? '[]' ?>;
                // JSON string'i değilse parse etme
                chartLabelsData = typeof labelsJsonString === 'string' ? JSON.parse(labelsJsonString) : labelsJsonString;
                chartDataValues = typeof dataJsonString === 'string' ? JSON.parse(dataJsonString) : dataJsonString;
                 // Tekrar dizi kontrolü
                 if (!Array.isArray(chartLabelsData)) chartLabelsData = [];
                 if (!Array.isArray(chartDataValues)) chartDataValues = [];

            } catch (e) {
                console.error("[DEBUG] Error parsing chart JSON data passed from PHP:", e);
                chartLabelsData = []; chartDataValues = []; // Hata durumunda boşalt
            }

            const maxPossibleScore = <?= $maxPossibleScore ?>;

            console.log("[DEBUG] Canvas Element:", canvasElement);
            console.log("[DEBUG] Chart Labels Parsed:", chartLabelsData);
            console.log("[DEBUG] Chart Data Parsed:", chartDataValues);

            const isDataValid = canvasElement && Array.isArray(chartLabelsData) && Array.isArray(chartDataValues) &&
                                chartLabelsData.length > 0 && chartDataValues.length > 0 &&
                                chartLabelsData.length === chartDataValues.length;

            const chartContainer = document.querySelector('.chart-container');

            if (isDataValid) {
                console.log("[DEBUG] Data is valid. Attempting to create chart...");
                try {
                    const ctx = canvasElement.getContext('2d');
                    if (!ctx) throw new Error("Canvas context could not be retrieved."); // Context kontrolü
                    new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: chartLabelsData,
                            datasets: [{
                                label: 'Puan', data: chartDataValues,
                                backgroundColor: [ 'rgba(239, 68, 68, 0.7)', 'rgba(59, 130, 246, 0.7)', 'rgba(245, 158, 11, 0.7)', 'rgba(34, 197, 94, 0.7)', 'rgba(219, 39, 119, 0.7)', 'rgba(139, 92, 246, 0.7)', 'rgba(249, 115, 22, 0.7)' ],
                                borderColor: [ 'rgba(239, 68, 68, 1)', 'rgba(59, 130, 246, 1)', 'rgba(245, 158, 11, 1)', 'rgba(34, 197, 94, 1)', 'rgba(219, 39, 119, 1)', 'rgba(139, 92, 246, 1)', 'rgba(249, 115, 22, 1)' ],
                                borderWidth: 1
                            }]
                        },
                        options: {
                             responsive: true, maintainAspectRatio: true,
                             scales: { y: { beginAtZero: true, max: maxPossibleScore, title: { display: true, text: 'Puan (Maks ' + maxPossibleScore + ')' } }, x: { title: { display: false } } },
                             plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(context) { let label = context.dataset.label || ''; if (label) { label += ': '; } if (context.parsed.y !== null) { label += context.parsed.y + ' / ' + maxPossibleScore; } return label; } } } }
                        }
                    });
                     console.log("[DEBUG] Chart created successfully.");
                } catch (chartError) {
                     console.error("[DEBUG] Chart.js Error:", chartError);
                     if(chartContainer) chartContainer.innerHTML = '<p class="text-red-600 text-center font-semibold">Grafik oluşturulamadı (JS Hatası). Konsolu kontrol edin.</p>';
                }
            } else {
                console.warn("[DEBUG] Chart prerequisites not met or data is empty/invalid. Canvas:", !!canvasElement, "Labels:", chartLabelsData, "Data:", chartDataValues);
                 if(chartContainer) {
                    const canvas = chartContainer.querySelector('canvas');
                    if(canvas) canvas.style.display = 'none';
                    let msgEl = chartContainer.querySelector('.chart-error-msg');
                    if(!msgEl) { msgEl = document.createElement('p'); msgEl.className = 'text-center text-gray-500 chart-error-msg'; chartContainer.appendChild(msgEl); }
                    msgEl.textContent = 'Grafik oluşturulamadı (Veri eksik veya hatalı).';
                 }
            }
        });
    </script>
    <?php endif; ?>

</body>
</html>