<?php
// view-result-13.php (Beck Depresyon Ölçeği Sonuçları - Grafik Kaldırıldı v10)

session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/html; charset=utf-8');

require '../src/config.php'; // !! YOLU KONTROL EDİN !!

// --- Oturum Kontrolü ---
/* if (!isset($_SESSION['user_id'])) { die('Giriş yapmalısınız.'); } */
// --- Bitiş Oturum Kontrolü ---

$surveyId = 13;
$testTitle = "Beck Depresyon Ölçeği";

$participantId = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : 0;
if ($participantId === false || $participantId <= 0) {
    die('Geçersiz katılımcı ID\'si.');
}

// --- Değişkenler ---
$participant = null; $error = null; $processedAnswers = [];
$totalScore = 0; $scoreInterpretation = "Hesaplanamadı";
$institutionWebPath = null; $psikometrikWebPath = null; $institutionName = null;
$basePath = realpath(__DIR__ . '/..');

// --- Logo URL Tanımlamaları ---
// !!! WEB URL'lerini KONTROL EDİN/GÜNCELLEYİN !!!
$psikometrikWebURL = '/assets/Psikometrik.png'; // Psikometrik.net logosunun web URL'si
$institutionWebURL = null;
// --- Bitiş Logo URL Tanımlamaları ---


try {
    // Katılımcı ve Kurum Bilgilerini Çek
    $stmt = $pdo->prepare("
        SELECT sp.name, sp.class, sp.admin_id, sp.created_at, u.institution_logo_path, u.institution_name
        FROM survey_participants sp LEFT JOIN users u ON sp.admin_id = u.id
        WHERE sp.id = ? AND sp.survey_id = ? ");
    $stmt->execute([$participantId, $surveyId]);
    $participant = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$participant) { throw new Exception("ID ($participantId) için $testTitle sonucu bulunamadı."); }

    // Kurum Logosu Web Yolunu Ayarla
    $rawInstitutionPathFromDB = $participant['institution_logo_path'];
    if (!empty($rawInstitutionPathFromDB)) {
        $cleanRelativePath = ltrim(str_replace('..', '', $rawInstitutionPathFromDB), '/');
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? $basePath;
        $fullServerPath = $docRoot . '/' . $cleanRelativePath;
        if (file_exists($fullServerPath)) { $institutionWebURL = '/' . $cleanRelativePath; }
        else { error_log("Kurum logosu dosyası bulunamadı: " . $fullServerPath); }
    }
    $institutionName = $participant['institution_name'];

    // Psikometrik Logo Dosya Varlığını Kontrol Et
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? $basePath;
    $fullPsikoServerPath = $docRoot . '/' . ltrim(str_replace('..', '', $psikometrikWebURL), '/');
    if (!file_exists($fullPsikoServerPath)) {
        error_log("Psikometrik logo dosyası bulunamadı: " . $fullPsikoServerPath);
        $psikometrikWebURL = null;
    }


    // --- Beck Cevap/Puanlama ---
    $stmt_questions = $pdo->prepare("SELECT id, question, sort_order FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC");
    $stmt_questions->execute([$surveyId]);
    $questions = $stmt_questions->fetchAll(PDO::FETCH_ASSOC);
    // ... (questions kontrolü) ...

    $stmt_answers = $pdo->prepare("SELECT question_id, answer_text FROM survey_answers WHERE participant_id = ?");
    $stmt_answers->execute([$participantId]);
    $raw_answers = $stmt_answers->fetchAll(PDO::FETCH_ASSOC);
    // ... (raw_answers kontrolü) ...
    $answersByQid = [];
    foreach($raw_answers as $ans) { $answersByQid[$ans['question_id']] = $ans['answer_text']; }

    $beck_options = [ /* ... Beck seçenekleri tam listesi ... */
        1 => ["Kendimi üzüntülü ve sıkıntılı hissetmiyorum.", "Kendimi üzüntülü ve sıkıntılı hissediyorum.", "Hep üzüntülü ve sıkıntılıyım. Bundan kurtulamıyorum.", "O kadar üzüntülü ve sıkıntılıyım ki artık dayanamıyorum."],
        2 => ["Gelecek hakkında mutsuz ve karamsar değilim.", "Gelecek hakkında karamsarım.", "Gelecekten beklediğim hiçbir şey yok.", "Geleceğim hakkında umutsuzum ve sanki hiçbir şey düzelmeyecekmiş gibi geliyor."],
        3 => ["Kendimi başarısız bir insan olarak görmüyorum.", "Çevremdeki birçok kişiden daha çok başarısızlıklarım olmuş gibi hissediyorum.", "Geçmişe baktığımda başarısızlıklarla dolu olduğunu görüyorum.", "Kendimi tümüyle başarısız biri olarak görüyorum."],
        4 => ["Birçok şeyden eskisi kadar zevk alıyorum.", "Eskiden olduğu gibi her şeyden hoşlanmıyorum.", "Artık hiçbir şey bana tam anlamıyla zevk vermiyor.", "Her şeyden sıkılıyorum."],
        5 => ["Kendimi herhangi bir şekilde suçlu hissetmiyorum.", "Kendimi zaman zaman suçlu hissediyorum.", "Çoğu zaman kendimi suçlu hissediyorum.", "Kendimi her zaman suçlu hissediyorum."],
        6 => ["Bana cezalandırılmışım gibi gelmiyor.", "Cezalandırılabileceğimi hissediyorum.", "Cezalandırılmayı bekliyorum.", "Cezalandırıldığımı hissediyorum."],
        7 => ["Kendimden memnunum.", "Kendi kendimden pek memnun değilim.", "Kendime çok kızıyorum.", "Kendimden nefret ediyorum."],
        8 => ["Başkalarından daha kötü olduğumu sanmıyorum.", "Zayıf yanlarım veya hatalarım için kendi kendimi eleştiririm.", "Hatalarımdan dolayı ve her zaman kendimi kabahatli bulurum.", "Her aksilik karşısında kendimi hatalı bulurum."],
        9 => ["Kendimi öldürmek gibi düşüncelerim yok.", "Zaman zaman kendimi öldürmeyi düşündüğüm olur. Fakat yapmıyorum.", "Kendimi öldürmek isterdim.", "Fırsatını bulsam kendimi öldürürdüm."],
        10 => ["Her zamankinden fazla içimden ağlamak gelmiyor.", "Zaman zaman içimden ağlamak geliyor.", "Çoğu zaman ağlıyorum.", "Eskiden ağlayabilirdim şimdi istesem de ağlayamıyorum."],
        11 => ["Şimdi her zaman olduğumdan daha sinirli değilim.", "Eskisine kıyasla daha kolay kızıyor ya da sinirleniyorum.", "Şimdi hep sinirliyim.", "Bir zamanlar beni sinirlendiren şeyler şimdi hiç sinirlendirmiyor."],
        12 => ["Başkaları ile görüşmek, konuşmak isteğimi kaybetmedim.", "Başkaları ile eskiden daha az konuşmak, görüşmek istiyorum.", "Başkaları ile konuşma ve görüşme isteğimi kaybetmedim.", "Hiç kimseyle konuşmak görüşmek istemiyorum."],
        13 => ["Eskiden olduğu gibi kolay karar verebiliyorum.", "Eskiden olduğu kadar kolay karar veremiyorum.", "Karar verirken eskisine kıyasla çok güçlük çekiyorum.", "Artık hiç karar veremiyorum."],
        14 => ["Aynada kendime baktığımda değişiklik görmüyorum.", "Daha yaşlanmış ve çirkinleşmişim gibi geliyor.", "Görünüşümün çok değiştiğini ve çirkinleştiğimi hissediyorum.", "Kendimi çok çirkin buluyorum."],
        15 => ["Eskisi kadar iyi çalışabiliyorum.", "Bir şeyler yapabilmek için gayret göstermem gerekiyor.", "Herhangi bir şeyi yapabilmek için kendimi çok zorlamam gerekiyor.", "Hiçbir şey yapamıyorum."],
        16 => ["Her zamanki gibi iyi uyuyabiliyorum.", "Eskiden olduğu gibi iyi uyuyamıyorum.", "Her zamankinden 1-2 saat daha erken uyanıyorum ve tekrar uyuyamıyorum.", "Her zamankinden çok daha erken uyanıyor ve tekrar uyuyamıyorum."],
        17 => ["Her zamankinden daha çabuk yorulmuyorum.", "Her zamankinden daha çabuk yoruluyorum.", "Yaptığım her şey beni yoruyor.", "Kendimi hemen hiçbir şey yapamayacak kadar yorgun hissediyorum."],
        18 => ["İştahım her zamanki gibi.", "İştahım her zamanki kadar iyi değil.", "İştahım çok azaldı.", "Artık hiç iştahım yok."],
        19 => ["Son zamanlarda kilo vermedim.", "İki kilodan fazla kilo verdim.", "Dört kilodan fazla kilo verdim.", "Altı kilodan fazla kilo vermeye çalışıyorum."],
        20 => ["Sağlığım beni fazla endişelendirmiyor.", "Ağrı, sancı, mide bozukluğu veya kabızlık gibi rahatsızlıklar beni endişelendirmiyor.", "Sağlığım beni endişelendirdiği için başka şeyleri düşünmek zorlaşıyor.", "Sağlığım hakkında o kadar endişeliyim ki başka hiçbir şey düşünemiyorum."],
        21 => ["Son zamanlarda cinsel konulara olan ilgimde bir değişme fark etmedim.", "Cinsel konularla eskisinden daha az ilgiliyim.", "Cinsel konularla şimdi çok daha az ilgiliyim.", "Cinsel konulara olan ilgimi tamamen kaybettim."]
    ];
    $totalScore = 0;
    $processedAnswers = [];

    if (!empty($raw_answers)) {
        // ... (Puanlama mantığı) ...
        foreach ($questions as $index => $q) {
            $qid = $q['id']; $maddeNumber = $index + 1; $answerText = $answersByQid[$qid] ?? "Cevap Yok";
            $itemScore = null; $optionsForThisItem = $beck_options[$maddeNumber] ?? null;
            if ($optionsForThisItem && $answerText !== "Cevap Yok") {
                $scoreIndex = array_search($answerText, $optionsForThisItem);
                if ($scoreIndex !== false) { $itemScore = (int)$scoreIndex; $totalScore += $itemScore; }
                else { $itemScore = "?"; error_log("Beck score error: Text mismatch P:$participantId, Q:$qid"); }
            } else { $itemScore = "-"; }
            $processedAnswers[] = [ 'madde' => $maddeNumber, 'answer_text' => $answerText, 'score' => $itemScore ];
        }
    } else {
        // ... (Boş cevap listesi) ...
        foreach ($questions as $index => $q) { $processedAnswers[] = [ 'madde' => $index + 1, 'answer_text' => 'Cevap Yok', 'score' => '-' ]; }
        $totalScore = null;
    }

    function interpretBeckScore($score) { /* ... Yorumlama fonksiyonu ... */
        if ($score === null) return "Cevaplar bulunamadığı için yorum yapılamadı.";
        if ($score <= 13) return "Minimal düzeyde depresyon belirtisi.";
        if ($score <= 19) return "Hafif düzeyde depresyon belirtisi.";
        if ($score <= 28) return "Orta düzeyde depresyon belirtisi.";
        if ($score <= 63) return "Şiddetli düzeyde depresyon belirtisi.";
        return "Geçersiz Puan Aralığı ($score)";
    }
    $scoreInterpretation = interpretBeckScore($totalScore);

} catch (PDOException $e) { die("Veritabanı hatası: " . $e->getMessage());
} catch (Exception $e) { die("Hata: " . $e->getMessage()); }
// --- Bitiş Veri Çekme ---

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($testTitle) ?> Sonucu - <?= htmlspecialchars($participant['name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* --- Ekran Stilleri --- */
        body { background-color: #f0fdf4; font-family: sans-serif; padding: 20px; color: #2d3748; }
        .container.screen-only { max-width: 800px; margin: 30px auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        h1.screen-title { color: #166534; text-align: center; margin-bottom: 1.5rem; font-size: 1.8rem; border-bottom: 2px solid #dcfce7; padding-bottom: 0.75rem; }
        h2.screen-section-title { font-size: 1.5rem; margin-top: 2rem; color: #166534; text-align: center; margin-bottom: 1rem;}
        .screen-participant-info p, .screen-score-info p { margin-bottom: 0.5rem; font-size: 1.1em; }
        .screen-participant-info strong, .screen-score-info strong { color: #1e3a8a; min-width: 150px; display: inline-block; font-weight: 600;}
        .screen-score-info { text-align: center; }
        .screen-table { width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: 0.95em; }
        .screen-table th, .screen-table td { border: 1px solid #d1d5db; padding: 10px; text-align: left; vertical-align: top; }
        .screen-table th { background-color: #dcfce7; color: #14532d; font-weight: 600; }
        .screen-table td:nth-child(1), .screen-table td:nth-child(3) { text-align: center; width: 10%; font-weight: 500;}
        .screen-table td:nth-child(2) { width: 80%; line-height: 1.5; }
        .screen-table tr:nth-child(even) { background-color: #f9fafb; }
        .screen-score-highlight { font-size: 1.6em; font-weight: bold; color: #166534; margin-top: 0.5rem; }
        .screen-interpretation { font-size: 1.1em; font-style: italic; color: #555; margin-top: 0.5rem; }
        .screen-button-footer { display: flex; justify-content: flex-end; gap: 1rem; margin-top: 2.5rem; padding-top: 1.5rem; border-top: 1px solid #e2e8f0;}
        .print-button, .panel-button { padding: 8px 16px; border: none; border-radius: 6px; font-size: 0.9rem; cursor: pointer; transition: background-color 0.2s, box-shadow 0.2s; font-weight: 500; text-decoration: none; display: inline-flex; align-items: center;}
        .print-button svg, .panel-button svg { width: 1.1em; height: 1.1em; margin-right: 0.4em;}
        .print-button { background-color: #6b7280; color: white; } .print-button:hover { background-color: #4b5563; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .panel-button { background-color: #3b82f6; color: white; } .panel-button:hover { background-color: #2563eb; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .error-message { color: #b91c1c; background-color: #fee2e2; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; border: 1px solid #fca5a5; font-weight: bold; text-align: center; }

        /* Grafik Alanı Stili (Ekran) - KALDIRILDI */
        /* .chart-container { ... } */
        /* .chart-container canvas { ... } */

        /* Yazdırma yapısını ekranda GİZLE */
        .print-structure { display: none; }

        /* --- YAZDIRMA STİLLERİ --- */
        @media print {
             .screen-only { display: none !important; }
             .print-structure { display: block !important; }
             body { background: #fff !important; color: #000 !important; font-family: Arial, Helvetica, sans-serif; font-size: 9pt; margin: 0; padding: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

             /* Sabit Başlık ve Logolar */
             .print-header-fixed { position: fixed; top: 10mm; left: 10mm; right: 10mm; height: 60px; display: flex !important; justify-content: space-between !important; align-items: center !important; z-index: 10; }
             .logo-container-fixed { height: 100%; display: flex; align-items: center; flex: 0 0 180px; }
             .logo-container-fixed.left { justify-content: flex-start; } .logo-container-fixed.right { justify-content: flex-end; }
             .logo-container-fixed img { max-width: 100%; max-height: 100%; width: auto; height: auto; display: block; }
             .print-title-center-fixed { text-align: center; flex-grow: 1; padding: 0 10px; }
             h1.print-title-fixed { font-size: 14pt; font-weight: bold; margin: 0 0 2mm 0; text-align: center !important; border: none !important; padding: 0; color: #000 !important;}
             h2.print-subtitle-fixed { font-size: 11pt; font-weight: bold; margin: 0; text-align: center !important; color: #000 !important;}

             /* Ana İçerik Alanı */
             .print-main-content { margin: 10mm; padding-top: calc(10mm + 60px + 5mm); }

            /* Grafik Alanı Stili (Yazdırma) - KALDIRILDI */
             /* .print-chart-container { ... } */
             /* .print-chart-container canvas { ... } */

             /* Diğer yazdırma stilleri */
             .print-info-box { border: 1px solid #ccc !important; padding: 3mm 4mm; margin-top: 0; margin-bottom: 5mm; page-break-inside: avoid; }
             .print-info-box p { margin: 1.5mm 0; font-size: 9pt; } .print-info-box strong { display: inline-block; min-width: 70px; font-weight: bold; }
             .print-score-box { border: 1px solid #ccc !important; padding: 4mm; margin-top: 5mm; margin-bottom: 5mm; background-color: #f0f0f0 !important; page-break-inside: avoid; }
             .print-score-box h3 { font-size: 11pt; font-weight: bold; margin: 0 0 2mm 0; color: #000 !important; text-align: left; border: none; padding: 0; }
             .print-score-box p { margin: 1.5mm 0; font-size: 9pt; } .print-score-box .score { font-weight: bold; font-size: 10pt; } .print-score-box .interpretation { font-style: italic; }
             .print-answers-area { page-break-inside: avoid; }
             .print-answers-area h3 { font-size: 11pt; font-weight: bold; margin: 5mm 0 2mm 0; color: #000 !important; text-align: left; border: none; padding: 0; }
             table.print-table { width: 100%; border-collapse: collapse; margin-top: 2mm; font-size: 8pt; page-break-inside: auto; }
             .print-table th, .print-table td { border: 1px solid #bbb !important; padding: 1.5mm 2mm; text-align: left; vertical-align: top; }
             .print-table th { background-color: #e9e9e9 !important; color: #000 !important; font-weight: bold; }
             .print-table td:nth-child(1), .print-table td:nth-child(3) { text-align: center; width: 8%; }
             .print-table td:nth-child(2) { width: 84%; line-height: 1.3; }
             tr { page-break-inside: avoid !important; }
             a { text-decoration: none !important; color: #000 !important; }
        }
        /* --- Bitiş: Yazdırma Stilleri --- */
    </style>
</head>
<body class="bg-[#f0fdf4]">

<div class="container screen-only">

    <h1 class="screen-title"><?= htmlspecialchars($testTitle) ?> Sonucu</h1>

    <div class="screen-participant-info mb-6">
        <h2 class="screen-section-title">Katılımcı Bilgileri</h2>
        <p><strong>Ad Soyad:</strong> <?= htmlspecialchars($participant['name']) ?></p>
        <p><strong>Sınıf:</strong> <?= htmlspecialchars($participant['class']) ?></p>
        <p><strong>Gönderim Zamanı:</strong> <?= htmlspecialchars(date('d.m.Y H:i:s', strtotime($participant['created_at']))) ?></p>
    </div>

    <div class="screen-score-info my-8">
        <h2 class="screen-section-title">Toplam Puan ve Yorum</h2>
        <p class="screen-score-highlight">Toplam Puan: <?= ($totalScore !== null) ? htmlspecialchars($totalScore) : 'Hesaplanamadı' ?></p>
        <p class="screen-interpretation"><?= htmlspecialchars($scoreInterpretation) ?></p>
    </div>

    <h2 class="screen-section-title">Verilen Cevaplar ve Puanlar</h2>
    <table class="screen-table">
        <thead><tr><th>Madde No</th><th>Seçilen İfade</th><th>Puan</th></tr></thead>
        <tbody>
            <?php if (empty($raw_answers)): ?>
                <tr><td colspan="3" class="text-center py-4">Katılımcı için cevap bulunamadı.</td></tr>
            <?php else: ?>
                <?php foreach ($processedAnswers as $ans): ?>
                <tr><td><?= htmlspecialchars($ans['madde']) ?></td><td><?= htmlspecialchars($ans['answer_text']) ?></td><td><?= htmlspecialchars($ans['score']) ?></td></tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="screen-button-footer">
         <a href="dashboard.php" class="panel-button"> <svg class="w-4 h-4 mr-2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm.707-10.293a1 1 0 00-1.414-1.414l-3 3a1 1 0 000 1.414l3 3a1 1 0 001.414-1.414L9.414 11H13a1 1 0 100-2H9.414l1.293-1.293z" clip-rule="evenodd" /></svg>
             Panele Dön
         </a>
         <button id="printBtn" class="print-button">
             <svg class="w-4 h-4 mr-2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 4v3H4a2 2 0 00-2 2v6a2 2 0 002 2h12a2 2 0 002-2V9a2 2 0 00-2-2h-1V4a2 2 0 00-2-2H7a2 2 0 00-2 2zm2 0h6v3H7V4zm6 6H7v1a1 1 0 100 2h6a1 1 0 100-2v-1zm-3 4a1 1 0 100 2h.01a1 1 0 100-2H10z" clip-rule="evenodd" /></svg>
             Yazdır
         </button>
    </div>

</div> <div class="print-structure">
     <div class="print-header-fixed">
          <div class="logo-container-fixed left">
               <?php if (!empty($institutionWebURL)): ?><img src="<?= htmlspecialchars($institutionWebURL) ?>" alt="<?= htmlspecialchars($institutionName ?? 'Kurum Logosu') ?>"><?php else: ?><div>&nbsp;</div><?php endif; ?>
          </div>
          <div class="print-title-center-fixed">
              <h1 class="print-title-fixed"><?= htmlspecialchars($testTitle) ?> Sonucu</h1>
              <h2 class="print-subtitle-fixed"><?= htmlspecialchars($participant['name']) ?></h2>
          </div>
          <div class="logo-container-fixed right">
               <?php if (!empty($psikometrikWebURL)): ?><img src="<?= htmlspecialchars($psikometrikWebURL) ?>" alt="Psikometrik.Net Logo"><?php else: ?><span>Psikometrik.Net</span><?php endif; ?>
          </div>
     </div>

     <div class="print-main-content">
         <div class="print-info-box">
             <p><strong>Sınıf:</strong> <?= htmlspecialchars($participant['class']) ?></p>
             <p><strong>Test Tarihi:</strong> <?= htmlspecialchars(date('d.m.Y H:i', strtotime($participant['created_at']))) ?></p>
         </div>

         <div class="print-score-box">
              <h3>Toplam Puan ve Yorum</h3>
              <p><span class="score">Toplam Puan: <?= ($totalScore !== null) ? htmlspecialchars($totalScore) : 'Hesaplanamadı' ?></span></p>
              <p><span class="interpretation"><?= htmlspecialchars($scoreInterpretation) ?></span></p>
         </div>

         <div class="print-answers-area">
              <h3>Verilen Cevaplar ve Puanlar</h3>
              <table class="print-table">
                  <thead><tr><th>Madde No</th><th>Seçilen İfade</th><th>Puan</th></tr></thead>
                  <tbody>
                       <?php if (empty($raw_answers)): ?>
                           <tr><td colspan="3" style="text-align: center; padding: 10px;">Katılımcı için cevap bulunamadı.</td></tr>
                       <?php else: ?>
                           <?php foreach ($processedAnswers as $ans): ?>
                           <tr><td><?= htmlspecialchars($ans['madde']) ?></td><td><?= htmlspecialchars($ans['answer_text']) ?></td><td><?= htmlspecialchars($ans['score']) ?></td></tr>
                           <?php endforeach; ?>
                       <?php endif; ?>
                  </tbody>
              </table>
         </div>
     </div> </div>
<script>
// Yazdırma Butonu İşlevi
document.addEventListener('DOMContentLoaded', function() {
    const printButton = document.getElementById('printBtn');
    if (printButton) {
        printButton.addEventListener('click', function() {
            window.print();
        });
    }

    // --- CHART.JS KODU KALDIRILDI ---

});
</script>

</body>
</html>