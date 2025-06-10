<?php
// view-result-17.php (Mesleki Eğilim Belirleme Testi Sonuçları v8 - DB'den Sort Order Okuma)

session_start(); // Session GEREKLİ
ini_set('display_errors', 1); error_reporting(E_ALL);

// --- Veritabanı Bağlantısı ---
require '../src/config.php';
if (!isset($pdo) || !$pdo instanceof PDO) {
    error_log("CRITICAL: PDO connection object (\$pdo) not created or found in src/config.php");
    header('Content-Type: text/html; charset=utf-8');
    die('<div style="border: 1px solid red; padding: 10px; margin: 10px; background-color: #ffebeb; color: red; font-family: sans-serif;"><b>Kritik Hata:</b> Veritabanı bağlantısı kurulamadı (src/config.php).</div>');
}
// --------------------------------

// --- Ayarlar ---
$surveyId = 17;
$testTitleDefault = "Mesleki Eğilim Belirleme Testi";
$pageTitle = $testTitleDefault . " Sonuçları";

// --- Değişkenler ---
$participantId = null; $participantData = null; $survey_title = $pageTitle;
$groupScores = []; $groupInterpretations = []; $error = null; $dataSource = null;
// --- Logo URL ---
$institutionWebURL = null; $psikometrikWebURL = '/assets/Psikometrik.png'; // Logo yolunu kontrol edin
// --- Sabit Veriler ---
// Bu harita sort_order'a göre meslek grubunu verir, değişmedi
$questionGroupMap = [
    1=>'A', 2=>'B', 3=>'C', 4=>'D', 5=>'E', 6=>'F', 7=>'G', 8=>'H', 9=>'I', 10=>'İ',
    11=>'A', 12=>'B', 13=>'C', 14=>'D', 15=>'E', 16=>'F', 17=>'G', 18=>'H', 19=>'I', 20=>'İ',
    21=>'A', 22=>'B', 23=>'C', 24=>'D', 25=>'E', 26=>'F', 27=>'G', 28=>'H', 29=>'I', 30=>'İ',
    31=>'A', 32=>'B', 33=>'C', 34=>'D', 35=>'E', 36=>'F', 37=>'G', 38=>'H', 39=>'I', 40=>'İ',
    41=>'A', 42=>'B', 43=>'C', 44=>'D', 45=>'E', 46=>'F', 47=>'G', 48=>'H', 49=>'I', 50=>'İ',
    51=>'A', 52=>'B', 53=>'C', 54=>'D', 55=>'E', 56=>'F', 57=>'G', 58=>'H', 59=>'I', 60=>'İ',
    61=>'A', 62=>'B', 63=>'C', 64=>'D', 65=>'E', 66=>'F', 67=>'G', 68=>'H', 69=>'I', 70=>'İ',
    71=>'A', 72=>'B', 73=>'C', 74=>'D', 75=>'E', 76=>'F', 77=>'G', 78=>'H', 79=>'I', 80=>'İ',
    81=>'A', 82=>'B', 83=>'C', 84=>'D', 85=>'E', 86=>'F', 87=>'G', 88=>'H', 89=>'I', 90=>'İ',
    91=>'A', 92=>'B', 93=>'C', 94=>'D', 95=>'E', 96=>'F', 97=>'G', 98=>'H', 99=>'I', 100=>'İ',
    101=>'A', 102=>'B', 103=>'C', 104=>'D', 105=>'E', 106=>'F', 107=>'G', 108=>'H', 109=>'I', 110=>'İ',
    111=>'A', 112=>'B', 113=>'C', 114=>'D', 115=>'E', 116=>'F', 117=>'G', 118=>'H', 119=>'I', 120=>'İ',
    121=>'A', 122=>'B', 123=>'C', 124=>'D', 125=>'E', 126=>'F', 127=>'G', 128=>'H', 129=>'I', 130=>'İ',
    131=>'A', 132=>'B', 133=>'C', 134=>'D', 135=>'E', 136=>'F', 137=>'G', 138=>'H', 139=>'I', 140=>'İ',
    141=>'A', 142=>'B', 143=>'C', 144=>'D', 145=>'E', 146=>'F', 147=>'G', 148=>'H', 149=>'I', 150=>'İ',
    151=>'A', 152=>'B', 153=>'C', 154=>'D', 155=>'E', 156=>'F', 157=>'G', 158=>'H', 159=>'I', 160=>'İ'
];
$groupNames = [
    'A'=>'Ziraat / Doğa Bilimleri', 'B'=>'Teknik / Mekanik', 'C'=>'İkna / Yönetim / Sosyal Yardım',
    'D'=>'Sanat / Estetik', 'E'=>'Edebiyat / Tarih / Öğretim', 'F'=>'Sosyal Bilimler / Araştırma',
    'G'=>'Yabancı Dil / Turizm / Uluslararası İlişkiler', 'H'=>'Sağlık / Fen Bilimleri (Biyoloji Temelli)',
    'I'=>'Ekonomi / Ticaret / Finans', 'İ'=>'Bilim / Mühendislik (Matematik/Fizik Temelli)'
];
$allGroups = array_keys($groupNames);

// Skor Yorumlama Fonksiyonu
function interpretVocationalScore($score) {
    if ($score === null || !is_numeric($score)) return "Hesaplanamadı";
    if ($score >= 0 && $score < 40) return "Bu alana ilginiz yok veya çok az.";
    elseif ($score < 80) return "İlginiz var ancak bu alanı seçmeniz için yeterli olmayabilir.";
    elseif ($score < 100) return "İlginiz var ama seçmeden önce bir kere daha düşünün.";
    elseif ($score < 130) return "Normal düzeyde ilginiz var, bu alandaki meslekleri seçebilirsiniz.";
    else return "Bu alandaki meslekler sizin için çok uygun görünüyor.";
}
// -------------------------------------------


// --- VERİ KAYNAĞINI BELİRLE VE VERİYİ AL ---
if (isset($_GET['id'])) {
    // --- SENARYO 1: ID VAR -> VERİTABANINDAN ÇEK ---
    $dataSource = 'db';
    $participantId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$participantId) { $error = "Geçersiz katılımcı ID'si."; }
    else {
        $groupScores = array_fill_keys($allGroups, 0); // Skorları sıfırla
        $groupInterpretations = array_fill_keys($allGroups, 'Hesaplanamadı'); // Yorumları sıfırla

        try {
            // Katılımcı ve Anket Bilgileri
            $stmt_participant = $pdo->prepare(" SELECT sp.id, sp.name, sp.class, sp.created_at, sp.admin_id, s.title as survey_title, u.institution_logo_path FROM survey_participants sp LEFT JOIN surveys s ON sp.survey_id = s.id LEFT JOIN users u ON sp.admin_id = u.id WHERE sp.id = ? AND sp.survey_id = ? ");
            $stmt_participant->execute([$participantId, $surveyId]);
            $participantData = $stmt_participant->fetch(PDO::FETCH_ASSOC);

            if (!$participantData) {
                 // Katılımcı bulunamazsa hata set et ve logla
                 $error = "Belirtilen ID ({$participantId}) için Anket {$surveyId} sonucu bulunamadı.";
                 error_log("Participant not found for view-result-17 (ID: {$participantId}, Survey: {$surveyId})");
            } else {
                $survey_title = !empty($participantData['survey_title']) ? htmlspecialchars($participantData['survey_title']) . " Sonuçları" : $testTitleDefault . " Sonuçları";
                // Logo URL Ayarla...
                if (!empty($participantData['admin_id']) && !empty($participantData['institution_logo_path'])) {
                   $rawInstitutionPathFromDB = $participantData['institution_logo_path'];
                   $cleanRelativePath = ltrim(str_replace('..', '', $rawInstitutionPathFromDB), '/');
                   $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/..');
                   $fullServerPath = rtrim($docRoot, '/') . '/' . $cleanRelativePath;
                   if (file_exists($fullServerPath)) { $institutionWebURL = '/' . $cleanRelativePath; }
                   else { error_log("Kurum logosu dosyası bulunamadı (view-result-17): " . $fullServerPath); }
                }

                // Cevapları Çek (question_id artık sort_order değerini tutuyor)
                // question_id sütununu sort_order olarak alıyoruz
                $stmt_answers = $pdo->prepare("SELECT question_id AS sort_order, answer_text FROM survey_answers WHERE participant_id = ? AND survey_id = ?");
                $stmt_answers->execute([$participantId, $surveyId]);
                $fetched_answers = $stmt_answers->fetchAll(PDO::FETCH_ASSOC);

                if (empty($fetched_answers)) {
                     // Cevap bulunamazsa hata set et ve logla
                     $error = "Katılımcı cevapları veritabanında bulunamadı (ID: {$participantId}, Anket: {$surveyId}).";
                     error_log("Answers not found for participant {$participantId} in survey {$surveyId}");
                     // Hata durumunda skorları ve yorumları boşalt
                     $groupScores = [];
                     $groupInterpretations = [];
                } else {
                    // Grup Skorlarını Hesapla (fetched_answers'taki question_id artık sort_order)
                    $groupScores = array_fill_keys($allGroups, 0); // Skorları tekrar sıfırla
                    $answeredCount = 0; // Cevaplanan soru sayısı kontrolü için

                    foreach($fetched_answers as $ans) {
                        $sortOrder = (int)$ans['sort_order']; // question_id sütunundaki değer (sort_order)
                        $answerText = trim($ans['answer_text'] ?? '');

                        // Cevap 'Evet' ise ve sort_order haritada varsa skoru artır
                        if ($answerText === 'Evet') {
                            if (isset($questionGroupMap[$sortOrder])) {
                                $group = $questionGroupMap[$sortOrder];
                                if (isset($groupScores[$group])) {
                                    $groupScores[$group] += 10; // Evet ise 10 puan
                                    $answeredCount++; // Sadece 'Evet' cevapları sayıyoruz gibi görünüyor test mantığına göre
                                } else {
                                     // questionGroupMap'te olmayan bir sort_order gelirse logla
                                     error_log("Unexpected sort_order {$sortOrder} found in survey_answers for participant {$participantId}, survey {$surveyId}");
                                }
                            } else {
                                // questionGroupMap'te olmayan bir sort_order gelirse logla
                                error_log("Sort_order {$sortOrder} from survey_answers not found in questionGroupMap for survey {$surveyId}");
                            }
                        } else if ($answerText === 'Hayır') {
                             // 'Hayır' cevapları için de sayacı artırabiliriz, eğer tüm soruların cevaplandığını kontrol etmek istiyorsak.
                             // Ancak mevcut logic sadece 'Evet'lere puan veriyor ve toplam soru sayısını kontrol etmiyor.
                             // take-survey-17.php'de tüm soruların cevaplandığı zaten doğrulanıyor.
                             // Bu nedenle sadece 'Evet'leri saymak skor hesaplaması için yeterli.
                             $answeredCount++; // 'Hayır'ları da cevaplanmış sayıyoruz
                        } else {
                             // Beklenmeyen cevap metni gelirse logla
                             error_log("Unexpected answer_text '{$answerText}' for sort_order {$sortOrder} in survey_answers for participant {$participantId}, survey {$surveyId}");
                             $answeredCount++; // Hatalı formatta da olsa cevap gelmiş sayılabilir, duruma göre ayarlanabilir.
                        }
                    }

                    // Yorumları Hesapla
                    foreach ($groupScores as $group => $score) { $groupInterpretations[$group] = interpretVocationalScore($score); }

                    // Opsiyonel: Tüm soruların cevaplanıp cevaplanmadığını kontrol etmek isterseniz
                    // (take-survey-17.php'de yapılıyor ama burada da kontrol edilebilir)
                    // $totalExpectedQuestions = count($questionGroupMap); // questionGroupMap'teki soru sayısı
                    // if ($answeredCount < $totalExpectedQuestions) {
                    //      $error = "Katılımcının tüm soruları cevaplanmamış görünüyor.";
                    //      $groupScores = []; // Skorları geçersiz kıl
                    //      $groupInterpretations = [];
                    // }

                } // End if (empty($fetched_answers)) else
            } // End if (!$participantData) else

        } catch (Exception $e) {
             // Veritabanı veya diğer hatalar için genel hata yönetimi
             $error = "Sonuçlar yüklenirken bir hata oluştu: " . $e->getMessage();
             error_log("DB Error view-result-17 (ID: {$participantId}): ".$e->getMessage());
             $participantData = null; // Hata durumunda katılımcı verisini temizle
             $groupScores = []; // Hata durumunda skorları temizle
             $groupInterpretations = []; // Hata durumunda yorumları temizle
        }
    }

} else {
    // --- SENARYO 2: ID YOK -> SESSION'DAN ÇEK ---
    $dataSource = 'session';
    $groupScores = []; $groupInterpretations = []; $participantData = null; // Başlangıç değerleri

    // Session'dan gelen verinin yapısı take-survey-17.php'deki Session kaydı ile uyumlu olmalı.
    // take-survey-17.php'de Session'a kaydedilirken 'group_scores' ve 'group_interpretations' zaten hesaplanıp kaydediliyordu.
    // Bu kısım mevcut kodunuzla uyumludur.
    if (isset($_SESSION['survey_result_data'], $_SESSION['survey_result_data']['survey_id'], $_SESSION['survey_result_data']['group_scores'], $_SESSION['survey_result_data']['group_interpretations'], $_SESSION['survey_result_data']['participant_name']) && $_SESSION['survey_result_data']['survey_id'] == $surveyId && is_array($_SESSION['survey_result_data']['group_scores']) && is_array($_SESSION['survey_result_data']['group_interpretations'])) {
        $sessionData = $_SESSION['survey_result_data'];
        $groupScores = $sessionData['group_scores'];
        $groupInterpretations = $sessionData['group_interpretations'];
        $participantData = [
             'name' => $sessionData['participant_name'],
             'class' => $sessionData['participant_class'] ?? null,
             'created_at' => date('Y-m-d H:i:s', $sessionData['timestamp'] ?? time()),
             'admin_id' => null // Session'dan gelen sonuçta admin_id olmaz
        ];
        $survey_title = $testTitleDefault . " Sonucunuz";
        $error = null; // Hata yok
        unset($_SESSION['survey_result_data']); // Başarılı okuma sonrası temizle
    } else {
        // Session verisi yok veya eksikse HATA AYARLA
        if (isset($_SESSION['survey_result_data'])){
            error_log("Incomplete session data for survey 17: " . print_r($_SESSION['survey_result_data'], true));
            $error = "Oturumdan alınan sonuç verisi eksik veya geçersiz (Kod: SESSION_INCOMPLETE).";
            unset($_SESSION['survey_result_data']); // Eksikse de temizle
        } else {
            $error = "Görüntülenecek sonuç bulunamadı veya oturum süresi dolmuş olabilir (Kod: SESSION_MISSING).";
        }
        // $participantData null kalır
    }
}
// --- VERİ KAYNAĞI SONU ---

// Psikometrik logo kontrolü...
if ($psikometrikWebURL) {
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/..');
    $fullPsikoServerPath = rtrim($docRoot, '/') . '/' . ltrim(str_replace('..', '', $psikometrikWebURL), '/');
    if (!file_exists($fullPsikoServerPath)) { $psikometrikWebURL = null; error_log("Psikometrik logo dosyası bulunamadı (view-result-17): " . $fullPsikoServerPath); }
}

// Header gönder...
if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> <?= ($participantData && isset($participantData['name'])) ? '- ' . htmlspecialchars($participantData['name']) : '' ?></title>
    <style>
        /* --- Stil Bloğu (Yeşil Tema - view-result-16 ile Uyumlu) --- */
         body { font-family: sans-serif; line-height: 1.6; background-color: #f0fdf4; color: #2c3e50; margin: 0; padding: 0; }
         .page-header { background-color: #ffffff; padding: 10px 25px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e0e0e0; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
         .page-header img { max-height: 50px; width: auto; }
         .container { max-width: 900px; margin: 20px auto; background: white; padding: 20px 30px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
         h1 { text-align: center; color: #1f2937; margin-top: 0; margin-bottom: 1.5rem; font-size: 1.75rem; border-bottom: 2px solid #dcfce7; padding-bottom: 0.75rem; }
         h2 { font-size: 1.4rem; color: #15803d; margin-top: 2rem; margin-bottom: 1rem; border-bottom: 1px solid #eee; padding-bottom: 0.4rem; }
         .participant-info, .result-summary { margin-bottom: 1.5rem; padding: 15px; background-color: #f9fafb; border: 1px solid #f3f4f6; border-radius: 8px; }
         .participant-info p { margin: 0.4rem 0; font-size: 1rem; }
         .participant-info strong { font-weight: 600; color: #374151; min-width: 120px; display: inline-block; }
         .result-summary { text-align: left; background-color: #e8f5e9; border-color: #c8e6c9; padding: 20px; }
         .result-summary h2 { margin-top: 0; }
         .group-results-table { width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: 0.95rem; background-color: #fff; }
         .group-results-table th, .group-results-table td { border: 1px solid #ddd; padding: 10px 12px; text-align: left; vertical-align: middle; }
         .group-results-table th { background-color: #dcfce7; font-weight: 600; color: #1f2937; }
         .group-results-table td:nth-child(1) { font-weight: bold; width: 5%; text-align: center;}
         .group-results-table td:nth-child(2) { width: 40%;}
         .group-results-table td:nth-child(3) { width: 10%; text-align: center; font-weight: bold; font-size: 1.1em;}
         .group-results-table td:nth-child(4) { width: 45%; font-style: italic; color: #374151;}
         .group-results-table tr:nth-child(even) { background-color: #f8f9fa; }
         .error-box { color: #b91c1c; background-color: #fee2e2; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; border: 1px solid #fca5a5; font-weight: 500; text-align: left; font-size: 0.9em;}
         .error-box b { font-weight: bold; }
         .action-buttons { display: flex; justify-content: center; gap: 1rem; margin-top: 2.5rem; padding-top: 1.5rem; border-top: 1px solid #e0e0e0; }
         .action-button { display: inline-flex; align-items: center; padding: 10px 20px; font-size: 1rem; font-weight: 600; color: white; background-color: #15803d; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; transition: background-color 0.2s ease, box-shadow 0.2s ease; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
         .action-button:hover { background-color: #0b532c; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
         .no-print { }
         @media print { /* ... Yazdırma Stilleri ... */ }
    </style>
</head>
<body>

<div class="page-header">
    <div>
        <?php if($dataSource == 'db' && !empty($institutionWebURL)): ?>
            <img src="<?= htmlspecialchars($institutionWebURL) ?>" alt="Kurum Logosu">
        <?php else: ?><span>&nbsp;</span><?php endif; ?>
    </div>
    <div>
        <?php if (!empty($psikometrikWebURL)): ?>
            <img src="<?= htmlspecialchars($psikometrikWebURL) ?>" alt="Psikometrik.Net Logosu">
        <?php else: ?><span>Psikometrik.Net</span><?php endif; ?>
    </div>
</div>

<div class="container">

    <?php // 1. Hata varsa göster ?>
    <?php if ($error): ?>
         <h1>Sonuçlar Yüklenemedi</h1>
         <div class="error-box"><b>Hata:</b> <?= htmlspecialchars($error) ?></div>
    <?php // 2. Hata yok ama katılımcı verisi yoksa (Bu durum artık error ile yakalanmalı) ?>
    <?php elseif (!$participantData): ?>
         <h1>Sonuçlar Yüklenemedi</h1>
         <div class="error-box">Görüntülenecek katılımcı verisi bulunamadı (Kod: VIEW_NOPARTICIPANT_UNEXPECTED). Lütfen tekrar deneyin veya yönetici ile iletişime geçin.</div>
    <?php // 3. Katılımcı var ama Grup skorları boşsa (örn. hiç 'Evet' yok veya hesaplama hatası) ?>
     <?php elseif (empty($groupScores) && $dataSource == 'db'): // Sadece DB durumunda 0 skor normal olabilir, Session'da hata kabul edilir ?>
         <h1><?= htmlspecialchars($survey_title) ?></h1>
         <div class="participant-info">
             <h2>Katılımcı Bilgileri</h2>
             <p><strong>Ad Soyad:</strong> <?= htmlspecialchars($participantData['name']) ?></p>
             <p><strong>Sınıf/Bölüm:</strong> <?= htmlspecialchars($participantData['class'] ?? 'Belirtilmemiş') ?></p>
             <p><strong>Test Tarihi:</strong> <?= date('d.m.Y H:i', strtotime($participantData['created_at'])) ?></p>
         </div>
         <div class="result-summary">
             <h2>Mesleki Eğilim Alanları ve Puanlarınız</h2>
             <div class="error-box" style="background-color: #fff3cd; border-color: #ffe69c; color: #664d03;">Hiçbir alana yönelik belirgin bir ilgi işareti bulunmadı (Tüm puanlar 0 veya cevaplar eksik).</div>
             <?php // Eğer groupInterpretations boşsa, tüm gruplar için 0 skoruna göre yorum yap
             if (empty($groupInterpretations)) {
                 foreach ($allGroups as $grp) {
                     $groupInterpretations[$grp] = interpretVocationalScore(0);
                 }
             }
             ?>
              <table class="group-results-table">
                  <thead><tr><th>Grup</th><th>Alan Adı</th><th>Puan (Max 160)</th><th>Eğilim Yorumu</th></tr></thead>
                  <tbody>
                      <?php foreach($allGroups as $groupLetter):
                           $score = $groupScores[$groupLetter] ?? 0; // Skor 0 olabilir
                           $interpretation = $groupInterpretations[$groupLetter] ?? interpretVocationalScore($score); // Yorumu al veya hesapla
                           $groupName = $groupNames[$groupLetter] ?? 'Bilinmeyen Grup';
                       ?>
                      <tr>
                          <td><?= htmlspecialchars($groupLetter) ?></td>
                          <td><?= htmlspecialchars($groupName) ?></td>
                          <td><?= $score ?></td>
                          <td><?= htmlspecialchars($interpretation) ?></td>
                      </tr>
                      <?php endforeach; ?>
                  </tbody>
              </table>
              <p style="font-size: 0.85em; margin-top: 15px; text-align: center; color: #475569;">* Bu sonuçlar sadece ilgi ve eğilimlerinizi yansıtır...</p>
         </div>
         <div class="action-buttons no-print">
            <?php if ($dataSource == 'db'): ?><a href="dashboard.php" class="action-button panel-button">Panele Dön</a><?php else: ?><a href="index.php" class="action-button panel-button">Diğer Anketler</a><?php endif; ?>
             <button onclick="window.print();" class="action-button print-button">Sayfayı Yazdır</button>
         </div>
    <?php else: // 4. Tüm veriler geçerli (katılımcı var, skorlar var), sonuçları göster ?>
        <h1><?= htmlspecialchars($survey_title) ?></h1>
        <div class="participant-info">
             <h2>Katılımcı Bilgileri</h2>
             <p><strong>Ad Soyad:</strong> <?= htmlspecialchars($participantData['name']) ?></p>
             <p><strong>Sınıf/Bölüm:</strong> <?= htmlspecialchars($participantData['class'] ?? 'Belirtilmemiş') ?></p>
             <p><strong>Test Tarihi:</strong> <?= date('d.m.Y H:i', strtotime($participantData['created_at'])) ?></p>
        </div>
        <div class="result-summary">
             <h2>Mesleki Eğilim Alanları ve Puanlarınız</h2>
             <?php // groupScores ve groupInterpretations dolu olmalı ?>
             <table class="group-results-table">
                <thead><tr><th>Grup</th><th>Alan Adı</th><th>Puan (Max 160)</th><th>Eğilim Yorumu</th></tr></thead>
                <tbody>
                    <?php foreach($allGroups as $groupLetter):
                        $score = $groupScores[$groupLetter] ?? 0;
                        $interpretation = $groupInterpretations[$groupLetter] ?? interpretVocationalScore($score);
                        $groupName = $groupNames[$groupLetter] ?? 'Bilinmeyen Grup';
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($groupLetter) ?></td>
                        <td><?= htmlspecialchars($groupName) ?></td>
                        <td><?= $score ?></td>
                        <td><?= htmlspecialchars($interpretation) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
             </table>
             <p style="font-size: 0.85em; margin-top: 15px; text-align: center; color: #475569;">* Bu sonuçlar sadece ilgi ve eğilimlerinizi yansıtır...</p>
        </div>
         <div class="action-buttons no-print">
            <?php if ($dataSource == 'db'): ?><a href="dashboard.php" class="action-button panel-button">Panele Dön</a><?php else: ?><a href="index.php" class="action-button panel-button">Diğer Anketler</a><?php endif; ?>
             <button onclick="window.print();" class="action-button print-button">Sayfayı Yazdır</button>
         </div>
    <?php endif; ?>

</div> <?php // container sonu ?>

<script> /* Özel JS gerekmiyor */ </script>

</body>
</html>
