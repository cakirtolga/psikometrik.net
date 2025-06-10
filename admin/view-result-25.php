<?php
// view-result-25.php (Şiddet Algısı Anketi Sonuçları)

// --- Hata Raporlama ---
ini_set('display_errors', 1);
error_reporting(E_ALL);
// ---------------------------

session_start(); // Session GEREKLİ

// --- Veritabanı Bağlantısı ---
require '../src/config.php'; // src/config.php dosyasının yolu projenizin yapısına göre değişebilir
if (!isset($pdo) || !$pdo instanceof PDO) {
    error_log("CRITICAL: PDO connection object (\$pdo) not created or found in src/config.php");
    header('Content-Type: text/html; charset=utf-8');
    die('<div style="border: 1px solid red; padding: 10px; margin: 10px; background-color: #ffebeb; color: red; font-family: sans-serif;"><b>Kritik Hata:</b> Veritabanı bağlantısı kurulamadı (src/config.php).</div>');
}
// --------------------------------

// --- Konfigürasyon ---
$surveyId = 25; // Anket ID'si
$testTitleDefault = "Şiddet Algısı Anketi (ÖĞRENCI)";
$pageTitle = $testTitleDefault . " Sonuçları";

// --- Değişkenler ---
$participantId = null;
$participantData = null;
$survey_title = $pageTitle;
$error = null;
$dataSource = null;
$processedAnswersForTable = []; // Detaylı cevap tablosu için
$dimensionCounts = []; // Boyutlara göre cevap sayıları [dimension => [option => count]]

// --- Logo URL ---
$institutionWebURL = null;
$psikometrikWebURL = '/assets/Psikometrik.png'; // Logo yolunu kontrol edin

// --- Sabit Veriler (Anket 25 - Şiddet Algısı) ---
// Cevap Seçenekleri
$optionsList = ["Şiddettir", "Kararsızım", "Şiddet Değildir"];

// Boyutlara göre soru aralıkları (sort_order'a göre)
$dimensions = [
    'Ev Ortamı' => range(1, 12),
    'Okul Ortamı' => range(13, 27),
    'Okul Çevresi' => range(28, 34),
    'Elektronik Ortam' => range(35, 40)
];

// --- VERİ KAYNAĞINI BELİRLE VE VERİYİ AL ---
if (isset($_GET['id'])) {
    // --- SENARYO 1: ID VAR -> VERITABANINDAN ÇEK ---
    $dataSource = 'db';
    $participantId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$participantId) {
        $error = "Geçersiz katılımcı ID'si.";
    } else {
        try {
            // 1. Katılımcı ve Anket Bilgileri
            $stmt_participant = $pdo->prepare(" SELECT sp.*, s.title as survey_title, u.institution_logo_path FROM survey_participants sp LEFT JOIN surveys s ON sp.survey_id = s.id LEFT JOIN users u ON sp.admin_id = u.id WHERE sp.id = ? AND sp.survey_id = ? ");
            $stmt_participant->execute([$participantId, $surveyId]);
            $participantData = $stmt_participant->fetch(PDO::FETCH_ASSOC);

            if (!$participantData) {
                // Katılımcı bulunamazsa hata set et ve logla
                $error = "Belirtilen ID ({$participantId}) için Anket {$surveyId} sonucu bulunamadı.";
                error_log("Participant not found for view-result-25 (ID: {$participantId}, Survey: {$surveyId})");
            } else {
                $survey_title = !empty($participantData['survey_title']) ? htmlspecialchars($participantData['survey_title']) . " Sonuçları" : $testTitleDefault . " Sonuçları";
                // Logo URL Ayarla...
                if ($dataSource == 'db' && !empty($participantData['admin_id']) && !empty($participantData['institution_logo_path'])) {
                   $rawInstitutionPathFromDB = $participantData['institution_logo_path'];
                   $cleanRelativePath = ltrim(str_replace('..', '', $rawInstitutionPathFromDB), '/');
                   $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/..');
                   $fullServerPath = rtrim($docRoot, '/') . '/' . $cleanRelativePath;
                   if (file_exists($fullServerPath)) { $institutionWebURL = '/' . $cleanRelativePath; }
                   else { error_log("Kurum logosu dosyası bulunamadı (view-result-25): " . $fullServerPath); }
                }


                // 2. Cevapları Çek (question_id artık sort_order, answer_text 'Şiddettir'/'Kararsızım'/'Şiddet Değildir')
                // question_text'i de çekelim
                $stmt_answers = $pdo->prepare("SELECT sa.question_id AS sort_order, sa.answer_text, sq.question AS question_text FROM survey_answers sa JOIN survey_questions sq ON sa.question_id = sq.sort_order AND sa.survey_id = sq.survey_id WHERE sa.participant_id = ? AND sa.survey_id = ? ORDER BY sa.question_id ASC");
                $stmt_answers->execute([$participantId, $surveyId]);
                $fetched_answers = $stmt_answers->fetchAll(PDO::FETCH_ASSOC);

                // Cevapları sort_order'a göre bir haritaya dök
                $participantAnswersBySortOrder = []; // [sort_order => answer_text]
                foreach($fetched_answers as $ans) {
                    $sortOrder = (int)$ans['sort_order'];
                    $answerText = trim($ans['answer_text'] ?? '');
                     if (in_array($answerText, $optionsList)) { // Sadece geçerli cevapları al
                        $participantAnswersBySortOrder[$sortOrder] = $answerText;
                        // Detaylı tablo için veriyi hazırla
                        $processedAnswersForTable[] = [
                            'madde' => $sortOrder,
                            'question_text' => $ans['question_text'] ?? 'Soru metni yüklenemedi',
                            'verilen_cevap' => $answerText,
                        ];
                     } else {
                         error_log("Invalid answer_text '{$answerText}' found in DB for participant {$participantId}, survey {$surveyId}, sort_order {$sortOrder}");
                     }
                }

                // Toplam beklenen soru sayısı (40)
                $stmt_total_questions = $pdo->prepare("SELECT COUNT(*) FROM survey_questions WHERE survey_id = ?");
                $stmt_total_questions->execute([$surveyId]);
                $totalExpectedQuestionsFetched = (int)$stmt_total_questions->fetchColumn();

                // Tüm sorular cevaplanmış mı control et (40 soru bekleniyor)
                if (count($participantAnswersBySortOrder) < $totalExpectedQuestionsFetched) {
                     $error = "Katılımcı cevapları veritabanında eksik (ID: {$participantId}, Anket: {$surveyId}).";
                     error_log("Answers incomplete for participant {$participantId} in survey {$surveyId}. Expected {$totalExpectedQuestionsFetched}, found " . count($participantAnswersBySortOrder));
                     // Hata durumunda boyut sayılarını boşalt
                     $dimensionCounts = [];
                } else {
                    // 3. Boyutlara Göre Cevapları Say
                    $dimensionCounts = [];
                    foreach ($dimensions as $dimName => $qNumbers) {
                        $dimensionCounts[$dimName] = array_fill_keys($optionsList, 0); // Seçenek sayılarını sıfırla
                        foreach ($qNumbers as $qNum) {
                            if (isset($participantAnswersBySortOrder[$qNum])) {
                                $answer = $participantAnswersBySortOrder[$qNum];
                                $dimensionCounts[$dimName][$answer]++;
                            }
                        }
                    }
                }

            } // End if (!$participantData) else

        } catch (Exception $e) {
            // Veritabanı veya diğer hatalar için genel hata yönetimi
            $error = "Sonuçlar yüklenirken bir hata oluştu: " . $e->getMessage();
            error_log("DB Error view-result-25 (ID: {$participantId}): " . $e->getMessage());
            $participantData = null; // Hata durumunda katılımcı verisini temizle
            $dimensionCounts = [];
            $processedAnswersForTable = [];
        }

    }

} else {
    // --- SENARYO 2: ID YOK -> SESSION'DAN ÇEK ---
    $dataSource = 'session';
    // take-survey-25.php Session'a 'answers' ([sort_order => answer_text]), katılımcı bilgisini kaydediyor.
    $dimensionCounts = [];
    $participantData = null;
    $processedAnswersForTable = []; // Başlangıç değerleri

    if (isset($_SESSION['survey_result_data'], $_SESSION['survey_result_data']['survey_id']) && $_SESSION['survey_result_data']['survey_id'] == $surveyId) {
        $sessionData = $_SESSION['survey_result_data'];
        // Gerekli anahtarların varlığını kontrol et
        if (isset($sessionData['answers'], $sessionData['participant_name']) && is_array($sessionData['answers'])) {

            $participantData = [
                'name' => $sessionData['participant_name'],
                'class' => $sessionData['participant_class'] ?? 'Belirtilmemiş',
                'created_at' => date('Y-m-d H:i:s', $sessionData['timestamp'] ?? time()),
                'admin_id' => null // Session'dan gelen sonuçta admin_id olmaz
            ];
            $survey_title = $testTitleDefault . " Sonucunuz";
            $error = null;

            // Session'daki answers [sort_order => answer_text] formatında
            $sessionAnswers = $sessionData['answers'];
            $processedAnswerCount = 0; // İşlenen geçerli cevap sayısı

            // Soruları DB'den çekerek metinlerini alalım (tablo için)
            $questionSortOrderToTextMap = [];
             try {
                 $stmtQText = $pdo->prepare("SELECT sort_order, question AS question_text FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC");
                 $stmtQText->execute([$surveyId]);
                 $questionSortOrderToTextMap = $stmtQText->fetchAll(PDO::FETCH_KEY_PAIR); // [sort_order => question_text]
             } catch(Exception $e) { error_log("Session result question text fetch error (S{$surveyId}): " . $e->getMessage()); /* Hata set edilebilir */ }


            // 3. Boyutlara Göre Cevapları Say
            $dimensionCounts = [];
            foreach ($dimensions as $dimName => $qNumbers) {
                $dimensionCounts[$dimName] = array_fill_keys($optionsList, 0); // Seçenek sayılarını sıfırla
                foreach ($qNumbers as $qNum) {
                     if (isset($sessionAnswers[$qNum])) {
                         $answer = trim($sessionAnswers[$qNum]);
                         if (in_array($answer, $optionsList)) { // Sadece geçerli cevapları say
                            $dimensionCounts[$dimName][$answer]++;
                            $processedAnswerCount++; // Geçerli cevap sayısını artır
                         } else {
                              error_log("Invalid answer text '{$answer}' found in session data for survey {$surveyId}, sort_order {$qNum}");
                         }
                     }
                }
            }

            // Toplam beklenen soru sayısı (40)
            $stmt_total_questions = $pdo->prepare("SELECT COUNT(*) FROM survey_questions WHERE survey_id = ?");
            $stmt_total_questions->execute([$surveyId]);
            $totalExpectedQuestionsFetched = (int)$stmt_total_questions->fetchColumn();

            if ($processedAnswerCount < $totalExpectedQuestionsFetched) {
                 error_log("view-result-25 Session: Processed fewer valid answers ({$processedAnswerCount}) than expected ({$totalExpectedQuestionsFetched}) from session data.");
                 // Bu durumda boyut sayıları eksik olabilir, ancak yine de gösterilebilir.
            }

            // Detaylı tablo için veriyi hazırla (Session'dan gelen ve işlenen cevapları kullan)
            // Session'daki sort_order'lar zaten sayısal ve doğru sırada olmalı
            ksort($sessionAnswers); // Sort order'a göre sırala
             foreach ($sessionAnswers as $sortOrder => $answerText) {
                 $sortOrder_int = (int)$sortOrder;
                 $answerText_str = trim($answerText);
                 // Sadece geçerli cevapları tabloya ekle
                 if (in_array($answerText_str, $optionsList)) {
                     $questionText = $questionSortOrderToTextMap[$sortOrder_int] ?? 'Soru metni yüklenemedi';
                     $processedAnswersForTable[] = [
                         'madde' => $sortOrder_int,
                         'question_text' => $questionText,
                         'verilen_cevap' => $answerText_str,
                     ];
                 }
             }


            unset($_SESSION['survey_result_data']); // Başarılı okuma sonrası temizle
        } else {
            // Session verisi eksikse hata set et ve logla
            error_log("Incomplete session data for survey 25: " . print_r($sessionData, true));
            $error = "Oturumdan alınan sonuç verisi eksik veya geçersiz (Kod: SESSION_INCOMPLETE).";
            $participantData = null; // Eksikse katılımcı verisi de yok
            $dimensionCounts = [];
            $processedAnswersForTable = [];
            unset($_SESSION['survey_result_data']); // Eksikse de temizle
        }
    } else {
        // Session verisi tamamen yoksa hata set et
        $error = "Görüntülenecek sonuç bulunamadı veya oturum süresi dolmuş olabilir (Kod: SESSION_MISSING).";
        $participantData = null;
        $dimensionCounts = [];
        $processedAnswersForTable = [];
    }
}
// --- VERİ KAYNAĞI SONU ---

// Psikometrik logo kontrolü...
if ($psikometrikWebURL) {
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/..');
    $fullPsikoServerPath = rtrim($docRoot, '/') . '/' . ltrim(str_replace('..', '', $psikometrikWebURL), '/');
    if (!file_exists($fullPsikoServerPath)) { $psikometrikWebURL = null; error_log("Psikometrik logo dosyası bulunamadı (view-result-25): " . $fullPsikometrikServerPath); }
}

// Header gönder...
if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> <?= ($participantData && isset($participantData['name'])) ? '- ' . htmlspecialchars($participantData['name']) : '' ?></title>
    <style>
        /* --- Stil Bloğu (Yeşil Tema - genel uyumluluk) --- */
        body { font-family: sans-serif; line-height: 1.6; background-color: #f0fdf4; color: #2c3e50; margin: 0; padding: 0; }
        .page-header { background-color: #ffffff; padding: 10px 25px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e0e0e0; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .page-header img { max-height: 50px; width: auto; }
        .container { max-width: 900px; margin: 20px auto; background: white; padding: 20px 30px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        h1 { text-align: center; color: #1f2937; margin-top: 0; margin-bottom: 1.5rem; font-size: 1.75rem; border-bottom: 2px solid #dcfce7; padding-bottom: 0.75rem; }
        h2 { font-size: 1.4rem; color: #15803d; /* Yeşil */ margin-top: 2rem; margin-bottom: 1rem; border-bottom: 1px solid #eee; padding-bottom: 0.4rem; }

        .participant-info, .result-summary { margin-bottom: 1.5rem; padding: 15px; background-color: #f9fafb; border: 1px solid #f3f4f6; border-radius: 8px; }
        .participant-info p { margin: 0.4rem 0; font-size: 1rem; }
        .participant-info strong { font-weight: 600; color: #374151; min-width: 120px; display: inline-block; }

        /* Grafik Alanı Stilleri */
        .chart-container {
            width: 80%; /* Konteyner genişliği küçültüldü */
            max-width: 600px; /* Maksimum genişlik küçültüldü */
            margin: 20px auto; /* Ortala ve üst/alt boşluk ver */
            padding: 15px;
            background-color: #ffffff; /* Grafik alanı arka planı */
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            height: 350px; /* Grafik konteynerine sabit bir yükseklik küçültüldü */
            display: flex; /* İçeriği ortalamak için flexbox kullan */
            justify-content: center; /* Yatayda ortala */
            align-items: center; /* Dikeyda ortala */
        }
         /* Grafik başlığı */
        .chart-container h3 {
            text-align: center;
            margin-top: 0;
            margin-bottom: 15px;
            color: #1f2937;
            font-size: 1.2rem;
            font-weight: 600;
        }
         /* Canvas elementinin kendisi */
        .chart-container canvas {
            max-width: 100%; /* Konteynerin içine sığmasını sağla */
            max-height: 100%; /* Konteynerin içine sığmasını sağla */
        }


        /* Sonuç Özeti (Boyutlara Göre Sayılar) */
        .result-summary { text-align: left; background-color: #e8f5e9; border-color: #c8e6c9; padding: 25px; }
        .result-summary h2 { margin-top: 0; text-align: center; }

        .dimension-results { margin-top: 1.5rem; }
        .dimension-results h3 { font-size: 1.2rem; color: #0b532c; margin-top: 1rem; margin-bottom: 0.8rem; border-bottom: 1px dashed #a7f3d0; padding-bottom: 0.3rem; }
        .dimension-table { width: auto; margin: 0.5rem auto 1rem auto; /* Tabloyu ortala */ border-collapse: collapse; font-size: 0.95rem; background-color: #fff; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .dimension-table th, .dimension-table td { border: 1px solid #ddd; padding: 8px 10px; text-align: center; }
        .dimension-table th { background-color: #dcfce7; font-weight: 600; color: #1f2937; }
        .dimension-table td { font-weight: bold; }
        .dimension-table td:nth-child(1) { color: #15803d; } /* Şiddettir */
        .dimension-table td:nth-child(2) { color: #f59e0b; } /* Kararsızım */
        .dimension-table td:nth-child(3) { color: #ef4444; } /* Şiddet Değildir */


        /* Yorum Notları - PDF yönergesi kaldırıldı */


        /* Detaylı Cevap Tablosu Stilleri */
        .answers-table { width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: 0.9rem; }
        .answers-table th, .answers-table td { border: 1px solid #ddd; padding: 8px 10px; text-align: left; vertical-align: top; }
        .answers-table th { background-color: #dcfce7; font-weight: 600; color: #1f2937; }
        .answers-table td:nth-child(1) { width: 10%; text-align: center; font-weight: bold; vertical-align: middle;} /* Madde No */
        .answers-table td:nth-child(2) { width: 60%; line-height: 1.4; } /* Soru Metni */
        .answers-table td:nth-child(3) { width: 30%; text-align: center; vertical-align: middle; font-weight: bold;} /* Verilen Cevap */
        .answers-table tr:nth-child(even) { background-color: #f8f9fa; }


        .error-box { color: #b91c1c; background-color: #fee2e2; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; border: 1px solid #fca5a5; font-weight: 500; text-align: left; font-size: 0.9em;}
        .error-box b { font-weight: bold; }

        .action-buttons { display: flex; justify-content: center; gap: 1rem; margin-top: 2.5rem; padding-top: 1.5rem; border-top: 1px solid #e0e0e0; }
        .action-button { display: inline-flex; align-items: center; padding: 10px 20px; font-size: 1rem; font-weight: 600; color: white; background-color: #15803d; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; transition: background-color 0.2s ease, box-shadow 0.2s ease; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .action-button:hover { background-color: #0b532c; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }

        /* Print Styles */
        @media print {
            body { background-color: #fff; padding: 0; margin: 0; font-size: 10pt; }

            .page-header {
                padding: 10px 0; /* Yazdırmada kenar boşluğu */
                border-bottom: 1px solid #000; /* Siyah çizgi */
                box-shadow: none;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .page-header img { max-height: 40px; } /* Yazdırmada logo boyutu */
            .page-header span { font-size: 10pt; }

            .container {
                box-shadow: none;
                margin: 0;
                padding: 10px 0; /* Yazdırmada kenar boşluğu */
                max-width: 100%;
            }

            h1 {
                font-size: 14pt;
                border-bottom: 1px solid #000; /* Siyah çizgi */
                padding-bottom: 5px;
                margin-bottom: 15px;
                color: #000;
            }

            h2 {
                font-size: 12pt;
                border-bottom: 1px solid #ccc;
                padding-bottom: 3px;
                margin-top: 15px;
                margin-bottom: 8px;
                color: #000;
            }

            .participant-info {
                margin-bottom: 15px;
                padding: 10px;
                border: 1px solid #ddd;
                background-color: #f9f9f9;
                font-size: 10pt;
            }
            .participant-info p { margin: 2px 0; }
            .participant-info strong { min-width: 100px; }


            .result-summary {
                 margin-bottom: 15px;
                 padding: 10px;
                 border: 1px solid #ddd;
                 background-color: #f9f9f9;
            }
            .result-summary h2 { margin-top: 0; }

            .dimension-results { margin-top: 10px; }
            .dimension-results h3 {
                 font-size: 11pt;
                 color: #000;
                 margin-top: 8px;
                 margin-bottom: 5px;
                 border-bottom: 1px dashed #ccc;
                 padding-bottom: 2px;
            }
            .dimension-table {
                 margin: 5px auto 10px auto; /* Yazdırmada tabloyu ortala */
                 font-size: 10pt;
                 box-shadow: none;
            }
            .dimension-table th, .dimension-table td { border: 1px solid #000; padding: 5px 8px; }
            .dimension-table th { background-color: #eee; color: #000; }
            .dimension-table td { font-weight: normal; } /* Yazdırmada kalınlığı azalt */
            .dimension-table td:nth-child(1) { color: #000; }
            .dimension-table td:nth-child(2) { color: #000; }
            .dimension-table td:nth-child(3) { color: #000; }


            /* Detaylı Cevap Tablosu Stilleri */
            .answers-table {
                 margin-top: 15px;
                 font-size: 9pt;
            }
            .answers-table th, .answers-table td { border: 1px solid #000; padding: 5px 8px; }
            .answers-table th { background-color: #eee; color: #000; }
            .answers-table td:nth-child(1) { font-weight: normal; } /* Yazdırmada kalınlığı azalt */
            .answers-table td:nth-child(3) { font-weight: normal; } /* Yazdırmada kalınlığı azalt */


            .chart-container {
                /* Yazdırmada grafiğin görünürlüğünü ve boyutunu ayarlayın */
                width: 80%; /* Yazdırmada daha az yer kaplaması için */
                height: 250px; /* Yazdırmada daha az yer kaplaması için */
                margin: 15px auto;
                padding: 10px;
                border: 1px solid #ddd;
                box-shadow: none;
                page-break-inside: avoid; /* Grafiğin sayfa bölünmesini engelle */
            }
            .chart-container canvas { max-width: 100%; max-height: 100%; }


            .no-print { display: none; }
        }
    </style>
     <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

    <?php // Hata veya Veri Yoksa Gösterim ?>
    <?php if ($error): ?>
         <h1>Sonuçlar Yüklenemedi</h1>
         <div class="error-box"><b>Hata:</b> <?= htmlspecialchars($error) ?></div>
    <?php elseif (!$participantData): ?>
         <h1>Sonuçlar Yüklenemedi</h1>
         <div class="error-box">Görüntülenecek katılımcı verisi bulunamadı.</div>
    <?php else: // Katılımcı verisi var ?>

        <h1><?= htmlspecialchars($survey_title) ?></h1>

        <div class="participant-info">
             <h2>Katılımcı Bilgileri</h2>
             <p><strong>Ad Soyad:</strong> <?= htmlspecialchars($participantData['name']) ?></p>
             <p><strong>Sınıf/Bölüm:</strong> <?= htmlspecialchars($participantData['class'] ?? 'Belirtilmemiş') ?></p>
             <p><strong>Test Tarihi:</strong> <?= date('d.m.Y H:i', strtotime($participantData['created_at'])) ?></p>
        </div>

        <?php // Grafik Alanı ?>
        <?php if (!empty($dimensionCounts)): // Boyutlara göre sayılar varsa grafiği göster ?>
        <div class="chart-container">
             <h5></h5>
             <canvas id="violencePerceptionChart"></canvas>
        </div>
        <?php endif; ?>


        <div class="result-summary">
             <h2>Boyutlara Göre Şiddet Algısı Sonuçları</h2>

             <?php if (!empty($dimensionCounts)): ?>
                 <?php foreach ($dimensionCounts as $dimName => $counts): ?>
                     <div class="dimension-results">
                         <h3><?= htmlspecialchars($dimName) ?></h3>
                         <table class="dimension-table">
                             <thead>
                                 <tr>
                                     <th>Şiddettir</th>
                                     <th>Kararsızım</th>
                                     <th>Şiddet Değildir</th>
                                 </tr>
                             </thead>
                             <tbody>
                                 <tr>
                                     <td><?= htmlspecialchars($counts['Şiddettir'] ?? 0) ?></td>
                                     <td><?= htmlspecialchars($counts['Kararsızım'] ?? 0) ?></td>
                                     <td><?= htmlspecialchars($counts['Şiddet Değildir'] ?? 0) ?></td>
                                 </tr>
                             </tbody>
                         </table>
                     </div>
                 <?php endforeach; ?>

             <?php else: ?>
                 <div class="error-box">Boyutlara göre cevap sayıları hesaplanamadı.</div>
             <?php endif; ?>

        </div>

        <h2>Detaylı Cevaplarınız</h2>
         <?php if (!empty($processedAnswersForTable)): ?>
             <table class="answers-table">
                 <thead>
                     <tr>
                         <th>Madde No</th>
                         <th>Soru</th>
                         <th>Verilen Cevap</th>
                     </tr>
                 </thead>
                 <tbody>
                     <?php foreach ($processedAnswersForTable as $item): ?>
                     <tr>
                         <td><?= htmlspecialchars($item['madde']) ?></td>
                         <td><?= htmlspecialchars($item['question_text']) ?></td>
                         <td><?= htmlspecialchars($item['verilen_cevap']) ?></td>
                     </tr>
                     <?php endforeach; ?>
                 </tbody>
             </table>
         <?php else: ?>
             <div class="error-box">Detaylı cevaplar görüntülenemiyor.</div>
         <?php endif; ?>


         <div class="action-buttons no-print">
            <?php if ($dataSource == 'db'): ?>
                 <a href="dashboard.php" class="action-button panel-button">Panele Dön</a>
            <?php else: ?>
                 <a href="../index.php" class="action-button panel-button">Diğer Anketler</a> <?php // Ana sayfaya yönlendirme ?>
            <?php endif; ?>
             <button onclick="window.print();" class="action-button print-button">Sayfayı Yazdır</button>
         </div>

    <?php endif; ?>

</div> <?php // container sonu ?>

<script>
    // Grafik çizimi için JavaScript
    <?php if (!empty($dimensionCounts)): // Boyutlara göre sayılar varsa grafiği çiz ?>
    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('violencePerceptionChart').getContext('2d');

        // PHP'den gelen boyutlara göre cevap sayılarını al
        const dimensionNames = Object.keys(<?= json_encode($dimensionCounts) ?>);
        const dimensionData = <?= json_encode($dimensionCounts) ?>;
        const optionsList = <?= json_encode($optionsList) ?>; // ["Şiddettir", "Kararsızım", "Şiddet Değildir"]

        // Grafik için veri setlerini hazırla
        const datasets = [];
        optionsList.forEach((option, index) => {
            const data = dimensionNames.map(dimName => dimensionData[dimName][option] ?? 0);
            let color;
            // Renkleri isteğe göre ayarla: Şiddettir kırmızı, Şiddet Değildir yeşil, Kararsızım turuncu
            if (option === 'Şiddettir') color = 'rgba(239, 68, 68, 0.7)'; // Kırmızı
            else if (option === 'Kararsızım') color = 'rgba(245, 158, 11, 0.7)'; // Turuncu
            else color = 'rgba(21, 128, 61, 0.7)'; // Yeşil

            datasets.push({
                label: option,
                data: data,
                backgroundColor: color,
                borderColor: color.replace('0.7', '1'), // Daha koyu kenarlık
                borderWidth: 1
            });
        });


        if (datasets.length > 0) {
             new Chart(ctx, {
                 type: 'bar', // Çubuk grafik
                 data: {
                     labels: dimensionNames, // Boyut adları
                     datasets: datasets
                 },
                 options: {
                     responsive: true,
                     maintainAspectRatio: false, // Konteyner boyutuna uyum sağlaması için
                     plugins: {
                         legend: {
                             display: true, // Legend'ı göster (Şiddettir, Kararsızım, Şiddet Değildir)
                             position: 'top',
                         },
                         title: {
                             display: false, // Ana başlık (h3 ile zaten var)
                         },
                         tooltip: { // Tooltip ayarları
                             callbacks: {
                                 label: function(context) {
                                     const label = context.dataset.label || ''; // Seçenek adı
                                     const count = context.raw; // Sayı
                                     const dimension = context.label; // Boyut adı
                                     return `${dimension} - ${label}: ${count}`;
                                 }
                             }
                         }
                     },
                     scales: {
                         x: { // X ekseni boyut adları
                             stacked: true, // Çubukları üst üste yığ
                             title: {
                                 display: true,
                                 text: 'Boyutlar'
                             }
                         },
                         y: { // Y ekseni sayılar
                             stacked: true, // Çubukları üst üste yığ
                             beginAtZero: true,
                             title: {
                                 display: true,
                                 text: 'Soru Sayısı'
                             },
                             ticks: {
                                 stepSize: 1 // Y ekseninde 1'er artış
                             }
                         }
                     }
                 }
             });
        }
    });
    <?php endif; ?>
</script>

</body>
</html>
