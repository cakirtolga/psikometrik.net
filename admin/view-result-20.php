<?php
// view-result-20.php (Öğrenme Stilleri Belirleme Testi Sonuçları v5 - Grafik Boyutu Düzeltildi)

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
$surveyId = 20; // Anket ID'si
$testTitleDefault = "Öğrenme Stilleri Belirleme Testi";
$pageTitle = $testTitleDefault . " Sonuçları";

// --- Değişkenler ---
$participantId = null; $participantData = null; $survey_title = $pageTitle;
$styleScores = []; // Stil skorları [stil => skor]
$interpretation = "Hesaplanamadı"; // Genel yorum veya dominant stil
$error = null; $dataSource = null;
$processedAnswersForTable = []; // Detaylı cevap tablosu için

// --- Logo URL ---
$institutionWebURL = null; $psikometrikWebURL = '/assets/Psikometrik.png'; // Logo yolunu kontrol edin

// --- Sabit Veriler (Anket 20 - Öğrenme Stilleri) ---
// Cevap Seçenekleri
$yesNoOptions = ['Evet', 'Hayır'];

// Soru No (sort_order) -> Öğrenme Stili Eşleşmesi (PDF'deki tabloya göre)
$sortOrderToStyleMap = [
    1 => 'İşitsel', 3 => 'İşitsel', 6 => 'İşitsel', 8 => 'İşitsel', 10 => 'İşitsel',
    13 => 'İşitsel', 14 => 'İşitsel', 22 => 'İşitsel', 23 => 'İşitsel', 24 => 'İşitsel',
    28 => 'İşitsel', 31 => 'İşitsel', // Toplam 12 İşitsel soru

    2 => 'Görsel', 4 => 'Görsel', 7 => 'Görsel', 18 => 'Görsel', 19 => 'Görsel',
    20 => 'Görsel', 21 => 'Görsel', 25 => 'Görsel', 26 => 'Görsel', 32 => 'Görsel', // Toplam 10 Görsel soru

    5 => 'Kinestetik', 9 => 'Kinestetik', 11 => 'Kinestetik', 12 => 'Kinestetik', 15 => 'Kinestetik',
    16 => 'Kinestetik', 17 => 'Kinestetik', 27 => 'Kinestetik', 29 => 'Kinestetik', 30 => 'Kinestetik',
    33 => 'Kinestetik' // Toplam 11 Kinestetik soru
];
$allStyles = ['İşitsel', 'Görsel', 'Kinestetik'];
$maxScores = [ // Her stil için maksimum puan (soru sayısı)
    'İşitsel' => 12,
    'Görsel' => 10,
    'Kinestetik' => 11
];


// Yorumlama Fonksiyonu (Stil Skorlarına Göre)
function interpretLearningStyles($styleScores, $maxScores) {
     if (empty($styleScores) || empty($maxScores)) return "Hesaplanamadı";

     // Skorları büyükten küçüğe sırala
     arsort($styleScores);

     $dominantStyles = [];
     $highestScore = reset($styleScores); // En yüksek skoru al

     // En yüksek skora sahip stilleri bul
     foreach ($styleScores as $style => $score) {
         if ($score === $highestScore) {
             $dominantStyles[] = $style;
         }
     }

     if (empty($dominantStyles)) {
         return "Öğrenme stilleri belirlenemedi.";
     } elseif (count($dominantStyles) === 1) {
         $dominantStyle = $dominantStyles[0];
         // Yüksek skora göre daha detaylı yorum eklenebilir
         $percentage = ($highestScore / $maxScores[$dominantStyle]) * 100;
         if ($percentage >= 80) {
              // HTML etiketleri doğrudan döndürülüyor
              return "En belirgin öğrenme stiliniz: <strong>{$dominantStyle}</strong>. Bu stile yönelik yöntemlerle çok daha etkili öğrenirsiniz.";
         } elseif ($percentage >= 60) {
              // HTML etiketleri doğrudan döndürülüyor
              return "Belirgin öğrenme stiliniz: <strong>{$dominantStyle}</strong>. Bu stile uygun yöntemler öğrenmenizi kolaylaştıracaktır.";
         } else {
              // HTML etiketleri doğrudan döndürülüyor
              return "Öğrenme stiliniz: <strong>{$dominantStyle}</strong>. Ancak diğer stillere de yatkınlığınız olabilir.";
         }

     } else {
         // Birden fazla dominant stil varsa
         // HTML etiketleri doğrudan döndürülüyor
         return "Birden fazla belirgin öğrenme stiliniz var: <strong>" . implode(" ve ", $dominantStyles) . "</strong>. Bu stillerin bir kombinasyonunu kullanarak en iyi şekilde öğrenebilirsiniz.";
     }
}
// -------------------------------------------


// --- VERİ KAYNAĞINI BELİRLE VE VERİYİ AL ---
if (isset($_GET['id'])) {
    // --- SENARYO 1: ID VAR -> VERITABANINDAN ÇEK ---
    $dataSource = 'db';
    $participantId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$participantId) { $error = "Geçersiz katılımcı ID'si."; }
    else {
        try {
            // 1. Katılımcı ve Anket Bilgileri
            $stmt_participant = $pdo->prepare(" SELECT sp.*, s.title as survey_title, u.institution_logo_path FROM survey_participants sp LEFT JOIN surveys s ON sp.survey_id = s.id LEFT JOIN users u ON sp.admin_id = u.id WHERE sp.id = ? AND sp.survey_id = ? ");
            $stmt_participant->execute([$participantId, $surveyId]);
            $participantData = $stmt_participant->fetch(PDO::FETCH_ASSOC);

            if (!$participantData) {
                 // Katılımcı bulunamazsa hata set et ve logla
                 $error = "Belirtilen ID ({$participantId}) için Anket {$surveyId} sonucu bulunamadı.";
                 error_log("Participant not found for view-result-20 (ID: {$participantId}, Survey: {$surveyId})");
            } else {
                $survey_title = !empty($participantData['survey_title']) ? htmlspecialchars($participantData['survey_title']) . " Sonuçları" : $testTitleDefault . " Sonuçları";
                // Logo URL Ayarla...
                if (!empty($participantData['admin_id']) && !empty($participantData['institution_logo_path'])) {
                   $rawInstitutionPathFromDB = $participantData['institution_logo_path'];
                   $cleanRelativePath = ltrim(str_replace('..', '', $rawInstitutionPathFromDB), '/');
                   $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/..');
                   $fullServerPath = rtrim($docRoot, '/') . '/' . $cleanRelativePath;
                   if (file_exists($fullServerPath)) { $institutionWebURL = '/' . $cleanRelativePath; }
                   else { error_log("Kurum logosu dosyası bulunamadı (view-result-20): " . $fullServerPath); }
                }

                // 2. Cevapları Çek (question_id artık sort_order, answer_text 'Evet'/'Hayır')
                $stmt_answers = $pdo->prepare("SELECT question_id AS sort_order, answer_text FROM survey_answers WHERE participant_id = ? AND survey_id = ? ORDER BY sort_order ASC");
                $stmt_answers->execute([$participantId, $surveyId]);
                $fetched_answers = $stmt_answers->fetchAll(PDO::FETCH_ASSOC);

                // Toplam beklenen soru sayısı (33)
                $stmt_total_questions = $pdo->prepare("SELECT COUNT(*) FROM survey_questions WHERE survey_id = ?");
                $stmt_total_questions->execute([$surveyId]);
                $totalExpectedQuestions = (int)$stmt_total_questions->fetchColumn();


                if (empty($fetched_answers) || count($fetched_answers) < $totalExpectedQuestions) {
                     // Cevap bulunamazsa veya eksikse hata set et ve logla
                     $error = "Katılımcı cevapları veritabanında bulunamadı veya eksik (ID: {$participantId}, Anket: {$surveyId}).";
                     error_log("Answers not found or incomplete for participant {$participantId} in survey {$surveyId}. Expected {$totalExpectedQuestions}, found " . count($fetched_answers));
                     // Hata durumunda skorları ve yorumları boşalt
                     $styleScores = [];
                     $interpretation = "Hesaplanamadı";
                } else {
                    // 3. Stil Skorlarını Hesapla
                    $styleScores = array_fill_keys($allStyles, 0); // Skorları sıfırla
                    $processedAnswerCount = 0; // İşlenen geçerli cevap sayısı

                    // Soru metinlerini çek (tablo için)
                    $questionSortOrderToTextMap = [];
                    try {
                        $stmtQText = $pdo->prepare("SELECT sort_order, question AS question_text FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC");
                        $stmtQText->execute([$surveyId]);
                        $questionSortOrderToTextMap = $stmtQText->fetchAll(PDO::FETCH_KEY_PAIR); // [sort_order => question_text]
                    } catch(Exception $e) { error_log("DB result question text fetch error: " . $e->getMessage()); /* Hata set edilebilir */ }


                    foreach($fetched_answers as $ans) {
                        $sortOrder = (int)$ans['sort_order']; // question_id sütunundaki değer (sort_order)
                        $answerText = trim($ans['answer_text'] ?? ''); // 'Evet' veya 'Hayır'

                        // Geçerli bir sort_order ve cevap metni varsa işleme devam et
                        if (($sortOrder > 0 && $sortOrder <= $totalExpectedQuestions) && in_array($answerText, $yesNoOptions)) {

                            // Skoru hesapla ('Evet' ise 1 puan)
                            if ($answerText === 'Evet') {
                                $style = $sortOrderToStyleMap[$sortOrder] ?? null; // sort_order'dan stili bul
                                if ($style !== null && isset($styleScores[$style])) {
                                    $styleScores[$style] += 1; // İlgili stile 1 puan ekle
                                } else {
                                     // sortOrderToStyleMap'te olmayan bir sort_order gelirse logla
                                     error_log("Sort_order {$sortOrder} from survey_answers not found in sortOrderToStyleMap for survey {$surveyId}, participant {$participantId}");
                                }
                            }
                            $processedAnswerCount++;

                            // Detaylı tablo için veriyi hazırla
                            $questionText = $questionSortOrderToTextMap[$sortOrder] ?? 'Soru metni yüklenemedi';
                            $processedAnswersForTable[] = [
                                'madde' => $sortOrder,
                                'question_text' => $questionText,
                                'verilen_cevap' => $answerText,
                                'kategori' => $sortOrderToStyleMap[$sortOrder] ?? 'Bilinmiyor' // Hangi kategoriye ait olduğu
                            ];

                        } else {
                             // Beklenmeyen sort_order veya geçersiz cevap metni gelirse logla
                             error_log("Invalid sort_order ({$sortOrder}) or answer_text ('{$answerText}') found in survey_answers for participant {$participantId}, survey {$surveyId}.");
                             // Bu durumda bu cevabı skorlamaya dahil etme veya tabloya ekleme
                        }
                    }

                    // Tüm beklenen cevaplar işlendi mi kontrol et
                    if ($processedAnswerCount < $totalExpectedQuestions) {
                         error_log("view-result-20 DB: Processed fewer valid answers ({$processedAnswerCount}) than expected ({$totalExpectedQuestions}) for participant {$participantId}.");
                         // Bu durumda skorun doğruluğu sorgulanabilir.
                         // $styleScores = []; // Skorları geçersiz kıl
                         // $interpretation = "Hesaplanamadı (Eksik veya hatalı cevaplar işlendi)";
                    }

                    // 4. Yorumu Hesapla (Stil skorları geçerliyse)
                    if (!empty($styleScores)) {
                         $interpretation = interpretLearningStyles($styleScores, $maxScores);
                    } else {
                         $interpretation = "Öğrenme stilleri hesaplanamadı (Cevaplar işlenemedi).";
                    }


                } // End if (empty($fetched_answers)) else
            } // End if (!$participantData) else

        } catch (Exception $e) {
             // Veritabanı veya diğer hatalar için genel hata yönetimi
             $error = "Sonuçlar yüklenirken bir hata oluştu: " . $e->getMessage();
             error_log("DB Error view-result-20 (ID: {$participantId}): ".$e->getMessage());
             $participantData = null; // Hata durumunda katılımcı verisini temizle
             $styleScores = []; // Hata durumunda skorları temizle
             $interpretation = "Hesaplanamadı"; // Hata durumunda yorumu temizle
             $processedAnswersForTable = []; // Hata durumunda tablo verisini temizle
        }
    }

} else {
    // --- SENARYO 2: ID YOK -> SESSION'DAN ÇEK ---
    $dataSource = 'session';
    // take-survey-20.php Session'a 'style_scores', 'answers' ([sort_order => 'Evet'/'Hayır']), katılımcı bilgisini kaydediyor.
    $styleScores = []; $interpretation = "Hesaplanamadı"; $participantData = null; $processedAnswersForTable = []; // Başlangıç değerleri

    if (isset($_SESSION['survey_result_data'], $_SESSION['survey_result_data']['survey_id']) && $_SESSION['survey_result_data']['survey_id'] == $surveyId) {
        $sessionData = $_SESSION['survey_result_data'];
        // Gerekli anahtarların varlığını kontrol et
        if (isset($sessionData['style_scores'], $sessionData['answers'], $sessionData['participant_name']) && is_array($sessionData['style_scores']) && is_array($sessionData['answers'])) {
            $styleScores = $sessionData['style_scores'];
            // Yorumu Session'dan almak yerine burada yeniden hesaplamak daha güvenli olabilir
            // $interpretation = $sessionData['interpretation']; // take-survey-20 Session'a yorum kaydetmiyor

            $participantData = [
                 'name' => $sessionData['participant_name'],
                 'class' => $sessionData['participant_class'] ?? null,
                 'created_at' => date('Y-m-d H:i:s', $sessionData['timestamp'] ?? time()),
                 'admin_id' => null // Session'dan gelen sonuçta admin_id olmaz
            ];
            $survey_title = $testTitleDefault . " Sonucunuz"; $error = null;

            // Detaylı tablo için veriyi hazırla (Session'daki answers [sort_order => 'Evet'/'Hayır'] formatında)
            $sessionAnswers = $sessionData['answers'];
            // Toplam beklenen soru sayısı (33)
            $totalExpectedQuestions = count($sortOrderToStyleMap); // Haritadaki soru sayısı

            // Soruları DB'den çekerek metinlerini alalım (sort_order'a göre)
            $questionSortOrderToTextMap = [];
             try {
                 $stmtQText = $pdo->prepare("SELECT sort_order, question AS question_text FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC");
                 $stmtQText->execute([$surveyId]);
                 $questionSortOrderToTextMap = $stmtQText->fetchAll(PDO::FETCH_KEY_PAIR); // [sort_order => question_text]
             } catch(Exception $e) { error_log("Session result question text fetch error: " . $e->getMessage()); /* Hata set edilebilir */ }

            $processedAnswerCount = 0;
            foreach ($sessionAnswers as $sortOrder => $answerText) {
                 $sortOrder_int = (int)$sortOrder;
                 $answerText_str = trim($answerText);

                 // Geçerli sort_order ve cevap metni kontrolü
                 if (($sortOrder_int > 0 && $sortOrder_int <= $totalExpectedQuestions) && in_array($answerText_str, $yesNoOptions)) {

                     // Soru metnini bul
                     $questionText = $questionSortOrderToTextMap[$sortOrder_int] ?? 'Soru metni yüklenemedi';

                     $processedAnswersForTable[] = [
                         'madde' => $sortOrder_int,
                         'question_text' => $questionText,
                         'verilen_cevap' => $answerText_str,
                         'kategori' => $sortOrderToStyleMap[$sortOrder_int] ?? 'Bilinmiyor' // Hangi kategoriye ait olduğu
                     ];
                     $processedAnswerCount++;

                 } else {
                      // Beklenmeyen sort_order veya geçersiz cevap metni gelirse logla
                      error_log("Invalid sort_order ({$sortOrder}) or answer_text ('{$answerText}') in session data for survey {$surveyId}");
                 }
            }

            // Session'daki cevap sayısı beklenenle uyuşuyor mu kontrol et (opsiyonel)
             if ($processedAnswerCount < $totalExpectedQuestions) {
                 error_log("view-result-20 Session: Processed fewer valid answers ({$processedAnswerCount}) than expected ({$totalExpectedQuestions}) from session data.");
                 // Bu durumda tablo boşaltılabilir veya bir uyarı gösterilebilir.
                 // $processedAnswersForTable = []; // Tabloyu boşalt
             }

            // Yorumu Hesapla (Session'dan gelen skorları kullanarak)
            if (!empty($styleScores)) {
                 $interpretation = interpretLearningStyles($styleScores, $maxScores);
            } else {
                 $interpretation = "Öğrenme stilleri hesaplanamadı (Session skorları eksik veya hatalı).";
            }


            unset($_SESSION['survey_result_data']); // Başarılı okuma sonrası temizle
        } else {
            // Session verisi eksikse hata set et ve logla
            error_log("Incomplete session data for survey 20: " . print_r($sessionData, true));
            $error = "Oturumdan alınan sonuç verisi eksik veya geçersiz (Kod: SESSION_INCOMPLETE).";
            $participantData = null; // Eksikse katılımcı verisi de yok
            $styleScores = []; // Eksikse skor yok
            $interpretation = "Hesaplanamadı"; // Eksikse yorum yok
            $processedAnswersForTable = []; // Eksikse tablo verisi yok
            unset($_SESSION['survey_result_data']); // Eksikse de temizle
        }
    } else {
        // Session verisi tamamen yoksa hata set et
        $error = "Görüntülenecek sonuç bulunamadı veya oturum süresi dolmuş olabilir (Kod: SESSION_MISSING).";
        $participantData = null;
        $styleScores = [];
        $interpretation = "Hesaplanamadı";
        $processedAnswersForTable = [];
    }
}
// --- VERİ KAYNAĞI SONU ---

// Psikometrik logo kontrolü...
if ($psikometrikWebURL) {
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/..');
    $fullPsikoServerPath = rtrim($docRoot, '/') . '/' . ltrim(str_replace('..', '', $psikometrikWebURL), '/');
    if (!file_exists($fullPsikoServerPath)) { $psikometrikWebURL = null; error_log("Psikometrik logo dosyası bulunamadı (view-result-20): " . $fullPsikoServerPath); }
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
            width: 80%; /* Konteyner genişliği */
            max-width: 400px; /* Pasta grafik için maksimum genişlik biraz azaltılabilir */
            margin: 20px auto; /* Ortala ve üst/alt boşluk ver */
            padding: 15px;
            background-color: #ffffff; /* Grafik alanı arka planı */
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            /* Grafik konteynerine sabit bir yükseklik ver */
            height: 400px; /* Örnek bir yükseklik, ihtiyaca göre ayarlanabilir */
            display: flex; /* İçeriği ortalamak için flexbox kullan */
            justify-content: center; /* Yatayda ortala */
            align-items: center; /* Dikeyde ortala */
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


        /* Sonuç Özeti Kutusu - Vurgulu Yeşil */
        .result-summary { text-align: left; background-color: #e8f5e9; border-color: #c8e6c9; padding: 25px; }
        .result-summary h2 { margin-top: 0; text-align: center; }
        .style-scores-list { list-style: none; padding: 0; margin: 15px 0; text-align: center; }
        .style-scores-list li { font-size: 1.1em; margin-bottom: 8px; }
        .style-scores-list strong { color: #0b532c; }
        .dominant-style-interpretation { font-size: 1.15em; color: #374151; line-height: 1.7; text-align: center; margin-top: 20px;}


        /* Detaylı Cevap Tablosu Stilleri */
        .answers-table { width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: 0.9rem; }
        .answers-table th, .answers-table td { border: 1px solid #ddd; padding: 8px 10px; text-align: left; vertical-align: top; }
        .answers-table th { background-color: #dcfce7; font-weight: 600; color: #1f2937; }
        .answers-table td:nth-child(1) { width: 8%; text-align: center; font-weight: bold; vertical-align: middle;} /* Madde No */
        .answers-table td:nth-child(2) { width: 45%; line-height: 1.4; } /* Soru Metni */
        .answers-table td:nth-child(3) { width: 15%; text-align: center; vertical-align: middle;} /* Verilen Cevap */
        .answers-table td:nth-child(4) { width: 15%; text-align: center; font-weight: bold; vertical-align: middle;} /* Kategori */
        .answers-table tr:nth-child(even) { background-color: #f8f9fa; }


        .error-box { color: #b91c1c; background-color: #fee2e2; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; border: 1px solid #fca5a5; font-weight: 500; text-align: left; font-size: 0.9em;}
        .error-box b { font-weight: bold; }

        .action-buttons { display: flex; justify-content: center; gap: 1rem; margin-top: 2.5rem; padding-top: 1.5rem; border-top: 1px solid #e0e0e0; }
        .action-button { display: inline-flex; align-items: center; padding: 10px 20px; font-size: 1rem; font-weight: 600; color: white; background-color: #15803d; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; transition: background-color 0.2s ease, box-shadow 0.2s ease; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .action-button:hover { background-color: #0b532c; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .no-print { }
        @media print { /* ... */ }
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
    <?php elseif (empty($styleScores)): // Katılımcı var ama skor hesaplanamadıysa ?>
        <h1><?= htmlspecialchars($survey_title) ?></h1>
        <div class="participant-info">
             <h2>Katılımcı Bilgileri</h2>
             <p><strong>Ad Soyad:</strong> <?= htmlspecialchars($participantData['name']) ?></p>
             <p><strong>Sınıf/Bölüm:</strong> <?= htmlspecialchars($participantData['class'] ?? 'Belirtilmemiş') ?></p>
             <p><strong>Test Tarihi:</strong> <?= date('d.m.Y H:i', strtotime($participantData['created_at'])) ?></p>
        </div>
        <div class="error-box">Sonuç hesaplanırken bir sorun oluştu veya tüm cevaplar bulunamadı.</div>
        <div class="action-buttons no-print">
            <?php if ($dataSource == 'db'): ?>
                 <a href="dashboard.php" class="action-button panel-button">Panele Dön</a>
            <?php else: ?>
                 <a href="../index.php" class="action-button panel-button">Diğer Anketler</a> <?php // Ana sayfaya yönlendirme ?>
            <?php endif; ?>
             <button onclick="window.print();" class="action-button print-button">Sayfayı Yazdır</button>
         </div>
    <?php else: // Veri var, sonuçları göster ?>

        <h1><?= htmlspecialchars($survey_title) ?></h1>

        <div class="participant-info">
             <h2>Katılımcı Bilgileri</h2>
             <p><strong>Ad Soyad:</strong> <?= htmlspecialchars($participantData['name']) ?></p>
             <p><strong>Sınıf/Bölüm:</strong> <?= htmlspecialchars($participantData['class'] ?? 'Belirtilmemiş') ?></p>
             <p><strong>Test Tarihi:</strong> <?= date('d.m.Y H:i', strtotime($participantData['created_at'])) ?></p>
        </div>

        <?php // Grafik Alanı ?>
        <div class="chart-container no-print">
             <h3>Öğrenme Stili Puanları Dağılımı</h3>
             <canvas id="learningStyleChart"></canvas>
        </div>


        <div class="result-summary">
             <h2>Öğrenme Stili Sonuçlarınız</h2>
             <ul class="style-scores-list">
                 <?php foreach($allStyles as $style): ?>
                     <li><strong><?= htmlspecialchars($style) ?>:</strong> <?= htmlspecialchars($styleScores[$style] ?? 0) ?> / <?= htmlspecialchars($maxScores[$style] ?? '?') ?></li>
                 <?php endforeach; ?>
             </ul>
             <p class="dominant-style-interpretation">
                 <strong>Yorum:</strong><br>
                 <?php
                    // Yorumu hesapla (styleScores doluysa)
                    if (!empty($styleScores)) {
                         // htmlspecialchars() fonksiyonu kaldırıldı
                         echo interpretLearningStyles($styleScores, $maxScores);
                    } else {
                         echo "Öğrenme stilleri yorumlanamadı.";
                    }
                 ?>
             </p>
             <p style="font-size: 0.85em; margin-top: 15px; color: #475569;">(Puanlar ilgili öğrenme stiline ait "Evet" cevaplarının toplamıdır.)</p>
        </div>

        <h2>Detaylı Cevaplarınız</h2>
         <?php if (!empty($processedAnswersForTable)): ?>
             <table class="answers-table">
                 <thead>
                     <tr>
                         <th>Madde No</th>
                         <th>Soru</th>
                         <th>Verilen Cevap</th>
                         <th>Kategori</th>
                     </tr>
                 </thead>
                 <tbody>
                     <?php foreach ($processedAnswersForTable as $item): ?>
                     <tr>
                         <td><?= htmlspecialchars($item['madde']) ?></td>
                         <td><?= htmlspecialchars($item['question_text']) ?></td>
                         <td><?= htmlspecialchars($item['verilen_cevap']) ?></td>
                         <td><?= htmlspecialchars($item['kategori']) ?></td>
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
    <?php if (!empty($styleScores)): ?>
    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('learningStyleChart').getContext('2d');

        // PHP'den gelen stil skorlarını ve isimlerini al
        const styleLabels = <?= json_encode(array_keys($styleScores)) ?>;
        const styleData = <?= json_encode(array_values($styleScores)) ?>;
        // Max skorlar pasta grafik için doğrudan kullanılmaz ama tooltipte gösterilebilir

        new Chart(ctx, {
            type: 'pie', // Pasta grafik olarak değiştirildi
            data: {
                labels: styleLabels, // Stillerin isimleri (Görsel, İşitsel, Kinestetik)
                datasets: [{
                    label: 'Puan', // Bu label pasta grafikte genellikle gösterilmez
                    data: styleData, // Puanlar
                    backgroundColor: [ // Dilim renkleri
                        'rgba(75, 192, 192, 0.8)', // İşitsel için yeşilimsi
                        'rgba(54, 162, 235, 0.8)', // Görsel için mavimsi
                        'rgba(255, 159, 64, 0.8)'  // Kinestetik için turuncumsu
                    ],
                    borderColor: '#ffffff', // Dilim kenarlık rengi (beyaz)
                    borderWidth: 2 // Dilim kenarlık kalınlığı
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false, // Konteyner boyutuna uyum sağlaması için
                plugins: {
                    legend: {
                        display: true, // Legend'ı göster (hangi rengin hangi stile ait olduğunu belirtir)
                        position: 'bottom', // Legend'ı alta yerleştir
                        labels: {
                            boxWidth: 20, // Legend kutucuk genişliği
                            padding: 15 // Legend öğeleri arası boşluk
                        }
                    },
                    title: {
                        display: false, // Ana başlık (h3 ile zaten var)
                    },
                     tooltip: { // Tooltip ayarları (üzerine gelince bilgi gösterme)
                        callbacks: {
                            label: function(context) {
                                const style = context.label;
                                const value = context.raw;
                                const total = context.dataset.data.reduce((sum, current) => sum + current, 0);
                                const percentage = ((value / total) * 100).toFixed(1);
                                // Tooltip'te stil adını, puanı ve yüzdesini göster
                                return `${style}: ${value} (${percentage}%)`;
                            }
                        }
                    }
                },
                // Pasta grafik için eksen ayarları kaldırıldı
                // scales: { ... }
            }
        });
    });
    <?php endif; ?>
</script>

</body>
</html>
