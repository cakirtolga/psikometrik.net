<?php
// view-result-14.php (Holland Sonuçları - Dinamik Logo v1 + Panel Butonu)

session_start();
ini_set('display_errors', 1); // Hataları göster (geliştirme)
error_reporting(E_ALL);
header('Content-Type: text/html; charset=utf-8');

require '../src/config.php'; // Veritabanı bağlantısı
if (!isset($pdo) || !$pdo instanceof PDO) { die('Veritabanı bağlantı hatası.'); }

// --- Ayarlar ---
$surveyId = 14;
$testTitleDefault = "Holland Mesleki Tercih Envanteri";
$pageTitle = $testTitleDefault . " Sonuçları";

// --- Logo URL Tanımlamaları ---
$institutionWebURL = null;
$psikometrikWebURL = '/assets/Psikometrik.png';
$institutionName = null;
// --- Bitiş Logo URL Tanımlamaları ---

// --- Katılımcı ID ---
$participantId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$error = null;
if (!$participantId) { $error = "Geçersiz veya eksik katılımcı ID'si (URL'de ?id=... bekleniyor)."; }

// --- Değişkenler ---
$participant = null; $participant_answers_raw = []; $survey_title = $pageTitle;
$scores = []; $positive_scores = []; $negative_scores = []; $holland_code = ''; $top_codes = [];
$chart_labels = []; $chart_scores_data = []; $chart_positive_data = []; $chart_negative_data = [];
$sample_professions = []; $interpretations = []; $category_names = [];

// --- Veri Çekme ve İşleme ---
if (!$error) {
    try {
        // Katılımcı, Anket Başlığı ve Kurum Bilgilerini Çek
        $stmt = $pdo->prepare("
            SELECT sp.*, s.title as survey_title, u.institution_logo_path, u.institution_name
            FROM survey_participants sp
            LEFT JOIN surveys s ON sp.survey_id = s.id
            LEFT JOIN users u ON sp.admin_id = u.id
            WHERE sp.id = ? AND sp.survey_id = ?
        ");
        $stmt->execute([$participantId, $surveyId]);
        $participant = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$participant) { throw new Exception("Katılımcı bulunamadı (ID: {$participantId}, Anket: {$surveyId})."); }
        $survey_title = !empty($participant['survey_title']) ? htmlspecialchars($participant['survey_title']) . " Sonuçları" : $testTitleDefault . " Sonuçları";

        // --- Logo URLlerini Ayarlama ---
        $institutionName = $participant['institution_name'] ?? null;
        $rawInstitutionPathFromDB = $participant['institution_logo_path'] ?? null;
        if (!empty($rawInstitutionPathFromDB)) {
            $cleanRelativePath = ltrim(str_replace('..', '', $rawInstitutionPathFromDB), '/');
            $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/..');
            $fullServerPath = rtrim($docRoot, '/') . '/' . $cleanRelativePath;
            if (file_exists($fullServerPath)) {
                $institutionWebURL = '/' . $cleanRelativePath;
            } else {
                error_log("Kurum logosu dosyası bulunamadı (Holland Raporu): " . $fullServerPath);
            }
        }
        if ($psikometrikWebURL) {
            $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/..');
            $fullPsikoServerPath = rtrim($docRoot, '/') . '/' . ltrim(str_replace('..', '', $psikometrikWebURL), '/');
            if (!file_exists($fullPsikoServerPath)) {
                error_log("Psikometrik logo dosyası bulunamadı (Holland Raporu): " . $fullPsikoServerPath);
                $psikometrikWebURL = null;
            }
        }
        // --- Bitiş Logo URL Ayarlama ---

        // --- Holland Puanlama ve Diğer İşlemler ---
        // ... (Cevapları Çekme, Puanlama, Grafik Verisi Hazırlama) ...
        // Cevapları Çek
        $stmt_answers = $pdo->prepare("SELECT question_id, answer_text FROM survey_answers WHERE participant_id = ? AND survey_id = ?");
        $stmt_answers->execute([$participantId, $surveyId]);
        $fetched_answers_assoc = $stmt_answers->fetchAll(PDO::FETCH_ASSOC);
        if (empty($fetched_answers_assoc)) { throw new Exception("Katılımcı cevapları bulunamadı."); }
        $participant_answers_raw = []; foreach ($fetched_answers_assoc as $row) { $participant_answers_raw[(int)$row['question_id']] = $row['answer_text']; }

        // Holland Puanlama
        $riasec_codes = ['A', 'Y', 'S', 'G', 'D', 'X'];
        $category_names = [ 'A' => 'Araştırmacı', 'Y' => 'Sanatsal (Yaratıcı)', 'S' => 'Sosyal', 'G' => 'Girişimci', 'D' => 'Geleneksel (Düzenli)', 'X' => 'Gerçekçi' ];
        $category_questions = [ 'A' => [1, 3, 4, 8, 11, 17, 22, 34, 39, 41, 47, 48, 68, 71, 80], 'Y' => [6, 10, 14, 27, 31, 32, 42, 44, 45, 50, 72, 74, 77, 78, 81], 'S' => [2, 21, 29, 37, 49, 55, 58, 60, 64, 65, 66, 73, 87, 88, 90], 'G' => [7, 15, 16, 18, 20, 28, 33, 35, 51, 56, 63, 67, 75, 83, 85], 'D' => [5, 12, 23, 24, 30, 36, 43, 46, 57, 62, 69, 76, 79, 86, 89], 'X' => [9, 13, 19, 25, 26, 38, 40, 52, 53, 54, 59, 61, 70, 82, 84], ];
        $question_to_category = []; foreach ($category_questions as $code => $qids) { foreach ($qids as $qid) { $question_to_category[(int)$qid] = $code; } }
        $scores = array_fill_keys($riasec_codes, 0); $positive_scores = array_fill_keys($riasec_codes, 0); $negative_scores = array_fill_keys($riasec_codes, 0);
        foreach ($participant_answers_raw as $qid_int => $answer_text) { if (isset($question_to_category[$qid_int])) { $category_code = $question_to_category[$qid_int]; $trimmed_answer = trim($answer_text); if ($trimmed_answer === 'Hoşlanırım') { $scores[$category_code]++; $positive_scores[$category_code]++; } elseif ($trimmed_answer === 'Hoşlanmam') { $scores[$category_code]--; $negative_scores[$category_code]++; } } else { error_log("Holland score warning: QID '{$qid_int}' not found in category map for P:{$participantId}."); } }
        $sorted_scores = $scores; arsort($sorted_scores); $top_codes = array_slice(array_keys($sorted_scores), 0, 3); $holland_code = implode('', $top_codes);

        // Grafik Verileri
        $chart_labels = []; $chart_scores_data = []; $chart_positive_data = []; $chart_negative_data = [];
        $ordered_codes_for_chart = ['X', 'A', 'Y', 'S', 'G', 'D'];
        foreach($ordered_codes_for_chart as $code) {
             if (isset($category_names[$code])) {
                $chart_labels[] = $category_names[$code] . " ($code)";
                $chart_scores_data[] = $scores[$code] ?? 0;
                $chart_positive_data[] = $positive_scores[$code] ?? 0;
                $chart_negative_data[] = $negative_scores[$code] ?? 0;
             }
        }
        // Meslekler & Yorumlar
         $sample_professions = [ /* ... öncekiyle aynı ... */
            'X' => ['Mühendis (Makine, Elektrik)', 'Pilot', 'Teknisyen (Bilgisayar, Elektronik)', 'İtfaiyeci', 'Çiftçi', 'Marangoz'],
            'A' => ['Bilim İnsanı (Fizikçi, Biyolog)', 'Doktor (Araştırma Odaklı)', 'Yazılım Geliştirici (Algoritma)', 'Matematikçi', 'Arkeolog', 'Ekonomist'],
            'Y' => ['Grafik Tasarımcı', 'Müzisyen', 'Yazar', 'Aktör', 'Dansçı', 'Fotoğrafçı', 'Mimar (Tasarım Odaklı)'],
            'S' => ['Öğretmen', 'Psikolog / Danışman', 'Sosyal Hizmet Uzmanı', 'Hemşire', 'Fizyoterapist', 'Halkla İlişkiler Uzmanı'],
            'G' => ['Girişimci / İşletme Sahibi', 'Satış Yöneticisi', 'Pazarlama Uzmanı', 'Avukat', 'Politikacı', 'Emlakçı'],
            'D' => ['Muhasebeci', 'Kütüphaneci', 'Sekreter / Yönetici Asistanı', 'Banka Memuru', 'Veri Giriş Operatörü', 'Arşivci']
         ];
         $interpretations = [ /* ... öncekiyle aynı ... */
            'X' => 'Pratik, mekanik ve fiziksel aktivitelere ilgi duyar. Aletlerle, makinelerle veya hayvanlarla çalışmaktan hoşlanır. Genellikle somut sonuçlar görmeyi tercih eder.',
            'A' => 'Araştırmayı, gözlemlemeyi, analiz etmeyi ve problem çözmeyi sever. Soyut düşünme ve bilimsel konulara yatkındır. Bağımsız çalışmaktan hoşlanabilir.',
            'Y' => 'Yaratıcı, sanatsal ifade ve estetiğe önem verir. Özgün fikirler üretmekten, tasarlamaktan, yazmaktan veya performans sergilemekten hoşlanır. Kurallardan ve yapıdan ziyade esnekliği tercih edebilir.',
            'S' => 'İnsanlarla çalışmaktan, onlara yardım etmekten, öğretmekten veya onları bilgilendirmekten hoşlanır. İşbirliğine yatkındır ve sosyal becerileri güçlüdür.',
            'G' => 'İkna etme, yönetme ve liderlik etme eğilimindedir. Başkalarını etkilemekten, projeler başlatmaktan ve hedeflere ulaşmaktan motive olur. Rekabetçi olabilir.',
            'D' => 'Düzenli, planlı ve detay odaklı çalışmayı sever. Verilerle çalışmaktan, kayıt tutmaktan ve belirli kurallara uymaktan hoşlanır. Pratik ve organize olmayı tercih eder.'
        ];
        // ... (Puanlama sonu) ...

    } catch (PDOException $e) { $error = "Veritabanı hatası: " . $e->getMessage(); error_log("DB Error view-result-14: ".$e->getMessage()); }
      catch (Exception $e) { $error = "Hata: " . $e->getMessage(); error_log("General Error view-result-14: ".$e->getMessage()); }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - <?= $participant ? htmlspecialchars($participant['name']) : 'Bulunamadı' ?></title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <style>
        /* --- Genel Stiller --- */
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol"; line-height: 1.6; background-color: #f4f7f6; color: #333; margin: 0; padding: 0; }
        .page-header { background-color: #ffffff; padding: 10px 25px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e0e0e0; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .page-header img { max-height: 50px; width: auto; }
        .container { max-width: 900px; margin: 20px auto; background: white; padding: 20px 30px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        h1 { text-align: center; color: #2c5f2d; margin-top: 0; margin-bottom: 1.5rem; font-size: 1.75rem; border-bottom: 1px solid #e0e0e0; padding-bottom: 0.75rem; }
        h2 { font-size: 1.4rem; color: #367e38; margin-top: 2rem; margin-bottom: 1rem; border-bottom: 1px solid #eee; padding-bottom: 0.4rem; }
        h3 { font-size: 1.15rem; color: #367e38; margin-top: 1.5rem; margin-bottom: 0.8rem; }
        .participant-info, .result-summary, .interpretation-section, .professions-section { margin-bottom: 1.5rem; padding: 15px; background-color: #f9f9f9; border: 1px solid #eee; border-radius: 5px; }
        .participant-info p, .result-summary p { margin: 0.4rem 0; font-size: 1rem; }
        .participant-info strong { font-weight: 600; color: #333; min-width: 120px; display: inline-block; }
        .result-summary { text-align: center; }
        .holland-code { font-size: 2.2em; font-weight: bold; color: #2c5f2d; display: block; margin: 10px 0; letter-spacing: 2px; }
        .score-table { width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: 0.9rem; }
        .score-table th, .score-table td { border: 1px solid #ddd; padding: 8px 10px; text-align: left; vertical-align: middle; }
        .score-table th { background-color: #e8f5e9; font-weight: 600; }
        .score-table td:nth-child(1) { font-weight: bold; text-align: center;}
        .score-table td:nth-child(3), .score-table td:nth-child(4), .score-table td:nth-child(5) { text-align: center; }
        .chart-area { margin-top: 2rem; }
        .chart-container { margin-bottom: 25px; padding: 15px; border: 1px solid #eee; border-radius: 5px; background-color: #fdfdfd; }
        .chart-wrapper { max-width: 550px; margin-left: auto; margin-right: auto; position: relative; height: 280px; }
        ul.professions { margin-top: 0.5em; padding-left: 20px; list-style: disc; }
        ul.professions li { margin-bottom: 4px; }
        .interpretation-area ul { padding-left: 20px; }
        .interpretation-area li { margin-bottom: 0.75rem; }
        .error-box { color: #a94442; background-color: #f2dede; border: 1px solid #ebccd1; padding: 15px; border-radius: 4px; margin: 20px 0; }

        /* --- Buton Alanı Stilleri --- */
        .action-buttons {
            display: flex; /* Butonları yan yana getir */
            justify-content: center; /* Butonları ortala */
            gap: 1rem; /* Butonlar arası boşluk */
            margin-top: 2.5rem; /* Üstten boşluk */
            padding-top: 1.5rem; /* Üstten iç boşluk */
            border-top: 1px solid #e0e0e0; /* Üstüne ayırıcı çizgi */
        }

        /* --- Ortak Buton Stilleri --- */
        .action-button {
            display: inline-flex;
            align-items: center;
            padding: 10px 20px;
            font-size: 1rem; /* Biraz daha belirgin font boyutu */
            font-weight: 600; /* Biraz daha kalın yazı */
            color: white;
            background-color: #367e38; /* Tema rengi (orta yeşil) */
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none; /* Link alt çizgisini kaldır */
            transition: background-color 0.2s ease, box-shadow 0.2s ease;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05); /* Hafif gölge */
        }

        .action-button:hover {
            background-color: #2c5f2d; /* Hover (koyu yeşil) */
            box-shadow: 0 4px 8px rgba(0,0,0,0.1); /* Hover'da belirgin gölge */
        }

        .no-print { /* Yazdırmada gizlenecek elemanlar */ }

        /* --- Yazdırma Stilleri --- */
        @media print {
            body { background-color: #fff !important; color: #000 !important; font-size: 10pt; }
            .page-header { border-bottom: 1px solid #ccc; box-shadow: none; padding: 5mm 10mm; }
            .page-header img { max-height: 35px; }
            .container { box-shadow: none; border: none; margin: 0; max-width: 100%; padding: 10mm; }
            h1 { font-size: 16pt; }
            h2 { font-size: 13pt; margin-top: 1.5rem; border: none; padding-bottom: 0; }
            h3 { font-size: 11pt; }
            .participant-info, .result-summary, .interpretation-section, .professions-section { background-color: #fff; border: 1px solid #eee; }
            .score-table th, .score-table td { border: 1px solid #ccc; font-size: 9pt; padding: 4px 6px; }
            .score-table th { background-color: #f0f0f0 !important; print-color-adjust: exact; }
            .chart-area { margin-top: 1.5rem; page-break-inside: avoid; }
            .chart-container { border: 1px solid #ccc; margin-bottom: 15px; padding: 10px; background-color: #fff; }
            .chart-wrapper { max-width: 100%; height: auto !important; }
            #positiveChart, #negativeChart, #radarChart { max-width: 100%; }
            ul.professions, .interpretation-area ul { padding-left: 15px; }
            .action-buttons, .no-print { display: none !important; } /* Butonları yazdırmada gizle */
        }
    </style>
</head>
<body>

<div class="page-header">
    <div>
        <?php if(!empty($institutionWebURL)): ?>
            <img src="<?= htmlspecialchars($institutionWebURL) ?>" alt="<?= htmlspecialchars($institutionName ?? 'Kurum Logosu') ?>">
        <?php else: ?><span>&nbsp;</span><?php endif; ?>
    </div>
    <div>
        <?php if (!empty($psikometrikWebURL)): ?>
            <img src="<?= htmlspecialchars($psikometrikWebURL) ?>" alt="Psikometrik.Net Logosu">
        <?php else: ?><span>Psikometrik.Net</span><?php endif; ?>
    </div>
</div>

    <div class="container">

        <?php if ($error): ?>
             <h1><?= $pageTitle ?></h1>
             <div class="error-box"><?= htmlspecialchars($error) ?></div>
        <?php elseif (!$participant): ?>
             <h1><?= $pageTitle ?></h1>
             <div class="error-box">Katılımcı bilgileri yüklenemedi veya bulunamadı.</div>
        <?php else: // Normal İçerik Başlangıcı ?>

            <h1><?= htmlspecialchars($survey_title) ?></h1>

            <div class="participant-info">
                 <h2>Katılımcı Bilgileri</h2>
                 <p><strong>Ad Soyad:</strong> <?= htmlspecialchars($participant['name']) ?></p>
                 <p><strong>Sınıf/Bölüm:</strong> <?= htmlspecialchars($participant['class'] ?? 'Belirtilmemiş') ?></p>
                 <p><strong>Test Tarihi:</strong> <?= isset($participant['created_at']) ? date('d.m.Y H:i', strtotime($participant['created_at'])) : 'Bilinmiyor' ?></p>
                 <?php if ($institutionName): ?>
                 <p><strong>Kurum:</strong> <?= htmlspecialchars($institutionName) ?></p>
                 <?php endif; ?>
            </div>

            <div class="result-summary">
                 <h2>Sonuç Özeti</h2>
                 <p><strong>Holland Kodunuz:</strong> <span class="holland-code"><?= htmlspecialchars($holland_code) ?></span></p>
                 <p>Bu kod, en yüksek puanı aldığınız ilk üç ilgi alanını temsil eder.</p>
            </div>

             <h2>Detaylı Puanlar</h2>
             <table class="score-table">
                 <thead><tr><th>Kod</th><th>Kategori</th><th>Toplam Puan</th><th>"Hoşlanırım"</th><th>"Hoşlanmam"</th></tr></thead>
                 <tbody>
                     <?php if (empty($participant_answers_raw)): ?>
                         <tr><td colspan="5" style="text-align: center; padding: 1rem;">Cevaplar yüklenemedi veya bulunamadı.</td></tr>
                     <?php else:
                         foreach($ordered_codes_for_chart as $code):
                            if (isset($category_names[$code])): ?>
                            <tr><td><strong><?= $code ?></strong></td><td><?= htmlspecialchars($category_names[$code]) ?></td><td><?= $scores[$code] ?? 0 ?></td><td><?= $positive_scores[$code] ?? 0 ?></td><td><?= $negative_scores[$code] ?? 0 ?></td></tr>
                     <?php   endif;
                         endforeach;
                     endif; ?>
                 </tbody>
             </table>

             <div class="chart-area">
                 <h2>Grafiksel Sonuçlar</h2>
                 <div class="chart-container"><h3 style="text-align:center;">İlgi Alanları ("Hoşlanırım")</h3><div class="chart-wrapper"><canvas id="positiveChart"></canvas></div></div>
                 <div class="chart-container"><h3 style="text-align:center;">İlgi Duyulmayan Alanlar ("Hoşlanmam")</h3><div class="chart-wrapper"><canvas id="negativeChart"></canvas></div></div>
                 <div class="chart-container"><h3 style="text-align:center;">Genel Profil (Radar)</h3><div class="chart-wrapper"><canvas id="radarChart"></canvas></div></div>
             </div>

             <div class="interpretation-area">
                  <h2>Yorumlama ve Meslek Önerileri</h2>
                  <?php if (!empty($top_codes) && count($top_codes) > 0): ?>
                      <div class="interpretation-section">
                          <h3>Baskın İlgi Alanlarınız (İlk Üç):</h3>
                          <ul>
                          <?php
                          $displayed_codes = 0;
                          foreach($top_codes as $code):
                              if (isset($category_names[$code]) && isset($interpretations[$code])):
                                $displayed_codes++;
                          ?>
                              <li><strong><?= htmlspecialchars($category_names[$code]) ?> (<?= $code ?>):</strong> <?= htmlspecialchars($interpretations[$code]) ?></li>
                          <?php
                              endif;
                           endforeach;
                           if ($displayed_codes == 0) echo "<li>Yorumlanacak baskın alan bulunamadı.</li>";
                          ?>
                          </ul>
                      </div>

                      <div class="professions-section">
                           <h3>Holland Kodunuza Uygun Örnek Meslekler:</h3>
                           <p>Aşağıda, en yüksek puan aldığınız <strong>ilk alan(lar)a</strong> göre bazı meslek örnekleri listelenmiştir...</p>
                           <?php
                           $first_code = $top_codes[0] ?? null;
                           if ($first_code && isset($sample_professions[$first_code])): ?>
                               <h4><?= htmlspecialchars($category_names[$first_code]) ?> (<?= $first_code ?>) Odaklı Meslekler:</h4>
                               <ul class="professions">
                                   <?php foreach($sample_professions[$first_code] as $profession): ?>
                                       <li><?= htmlspecialchars($profession) ?></li>
                                   <?php endforeach; ?>
                               </ul>
                           <?php elseif ($first_code): ?>
                                <p><?= htmlspecialchars($category_names[$first_code]) ?> (<?= $first_code ?>) alanı için örnek meslekler tanımlanmamış.</p>
                           <?php endif; ?>
                           <p><strong>Not:</strong> En iyi kariyer seçimi genellikle birden fazla ilgi alanınızın bir kombinasyonunu içerir...</p>
                      </div>
                  <?php else: ?>
                      <p>Holland kodunuz veya yorumlanacak baskın alanlar belirlenemedi.</p>
                  <?php endif; ?>
             </div>

             <div class="action-buttons no-print">
                 <a href="dashboard.php" class="action-button panel-button">Panele Dön</a>
                 <button onclick="window.print();" class="action-button print-button">Sayfayı Yazdır</button>
             </div>

        <?php endif; // Normal İçerik Sonu ?>

    </div> <?php // container sonu ?>

    <script>
        // --- Chart.js Kodu (DOM Hazır Olunca Çalışacak Şekilde) ---
        // ... (Önceki kodla aynı) ...
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (!$error && $participant && !empty($participant_answers_raw) && !empty($chart_labels)): ?>
                try {
                    const ctxPositive = document.getElementById('positiveChart');
                    const ctxNegative = document.getElementById('negativeChart');
                    const ctxRadar = document.getElementById('radarChart');

                    if(ctxPositive && ctxNegative && ctxRadar) {
                        const labels = <?= json_encode($chart_labels) ?>;
                        const scoresData = <?= json_encode($chart_scores_data) ?>;
                        const positiveData = <?= json_encode($chart_positive_data) ?>;
                        const negativeData = <?= json_encode($chart_negative_data) ?>;
                        const displayNegativeData = negativeData.map(Math.abs);

                        const commonBarOptions = (title) => ({ /* ... */
                            indexAxis: 'y', responsive: true, maintainAspectRatio: false, animation: { duration: 500 }, scales: { x: { beginAtZero: true, ticks: { stepSize: 1 } }, y: { ticks: { font: { size: 10 } } } }, plugins: { title: { display: false }, legend: { display: false }, tooltip: { callbacks: { label: function(context) { let label = context.dataset.label || ''; if (label) { label += ': '; } if (context.parsed.x !== null) { label += context.parsed.x; } return label; } } } }
                        });
                         const radarOptions = { /* ... */
                            responsive: true, maintainAspectRatio: false, animation: { duration: 500 }, elements: { line: { borderWidth: 3 } }, scales: { r: { ticks: { stepSize: 2, backdropColor: 'rgba(255, 255, 255, 0.7)', font: { size: 9 } }, pointLabels: { font: { size: 11 } }, suggestedMin: -15, suggestedMax: 15 } }, plugins: { title: { display: false }, legend: { display: false }, tooltip: { callbacks: { label: function(context) { return context.dataset.label + ': ' + context.raw; } } } }
                        };

                        // Grafikleri çizdir
                        new Chart(ctxPositive, { type: 'bar', data: { labels: labels, datasets: [{ label: '"Hoşlanırım" Sayısı', data: positiveData, backgroundColor: 'rgba(74, 222, 128, 0.7)', borderColor: 'rgba(22, 163, 74, 1)', borderWidth: 1 }] }, options: commonBarOptions('"Hoşlanırım" Sayısı') });
                        new Chart(ctxNegative, { type: 'bar', data: { labels: labels, datasets: [{ label: '"Hoşlanmam" Sayısı', data: displayNegativeData, backgroundColor: 'rgba(248, 113, 113, 0.7)', borderColor: 'rgba(220, 38, 38, 1)', borderWidth: 1 }] }, options: commonBarOptions('"Hoşlanmam" Sayısı') });
                        new Chart(ctxRadar, { type: 'radar', data: { labels: labels, datasets: [{ label: 'Genel Puanlar', data: scoresData, fill: true, backgroundColor: 'rgba(59, 130, 246, 0.3)', borderColor: 'rgba(37, 99, 235, 1)', pointBackgroundColor: 'rgba(37, 99, 235, 1)', pointBorderColor: '#fff', pointHoverBackgroundColor: '#fff', pointHoverBorderColor: 'rgba(37, 99, 235, 1)' }] }, options: radarOptions });

                    } else { console.warn("Chart canvas elementleri bulunamadı."); }
                } catch (e) { console.error("Chart.js hatası:", e); }
            <?php endif; ?>
        }); // DOMContentLoaded Sonu
    </script>

</body>
</html>