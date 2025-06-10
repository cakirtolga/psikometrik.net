<?php
// view-result-23.php (Sınav Kaygısı Ölçeği Sonuçları v5)

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
$surveyId = 23; // Anket ID'si
$testTitleDefault = "Sınav Kaygısı Ölçeği";
$pageTitle = $testTitleDefault . " Sonuçları";

// --- Değişkenler ---
$participantId = null; $participantData = null; $survey_title = $pageTitle;
$subscaleScores = []; // Alt ölçek puanları [scaleKey => score]
$totalOverallScore = null; // Toplam genel skor
$interpretation = "Hesaplanamadı"; // Genel yorum
$error = null; $dataSource = null;
$processedAnswersForTable = []; // Detaylı cevap tablosu için

// --- Logo URL ---
$institutionWebURL = null; $psikometrikWebURL = '/assets/Psikometrik.png'; // Logo yolunu kontrol edin

// --- Sabit Veriler (Anket 23 - Sınav Kaygısı Ölçeği) ---
// Cevap Seçenekleri
$trueFalseOptions = ['Doğru', 'Yanlış'];

// Cevap Anahtarı (sort_order'a göre hangi maddeler hangi alt ölçeğe dahil ve 1 puan kazandırır)
// PDF'deki "CEVAP ANAHTARI" bölümüne göre
$subscalesItems = [
    'I' => [3, 14, 17, 25, 32, 37, 41, 46], // Başkalarının sizi nasıl gördüğü ile ilgili endişeler (8 madde)
    'II' => [2, 9, 16, 24, 31, 38, 40], // Kendinizi nasıl gördüğünüzle ilgili endişeler (7 madde)
    'III' => [1, 8, 15, 23, 30, 49], // Gelecekle ilgili endişeler (6 madde)
    'IV' => [6, 11, 18, 26, 33, 42], // Yeterince hazırlananamakla ilgili endişeler (6 madde)
    'V' => [5, 12, 19, 27, 34, 39, 43], // Bedensel tepkiler (7 madde)
    'VI' => [4, 13, 20, 21, 28, 35, 36, 37, 48, 50], // Zihinsel tepkiler (10 madde)
    'VII' => [7, 10, 22, 29, 44, 4] // Genel sınav kaygısı (6 madde) - Not: Madde 4 hem VI hem VII'de görünüyor, PDF'e göre dahil edildi.
];

$totalExpectedQuestions = 50; // Toplam soru sayısı

// Yorum Aralıkları ve Metinleri (PDF'deki "YORUM ANAHTARI" bölümüne göre)
$interpretationKeys = [
    'I' => [
        ['min' => 4, 'max' => 8, 'text' => 'Başkalarının sizi nasıl gördüğü sizin için büyük önem taşıyor. Çevrenizdeki insanların değerlendirmeleri bir sınav durumunda zihinsel faaliyetlerinizi olumsuz etkiliyor ve sınav başarınızı tehlikeye atıyor.'],
        ['min' => 0, 'max' => 3, 'text' => 'Başkalarının sizinle ilgili görüşleri sizin için fazla önem taşımıyor. Bu sebeple sınavlara hazırlanırken çevrenizdeki insanların sizinle ilgili ne düşündükleri üzerinde kafa yorup zaman ve enerji kaybetmiyorsunuz.']
    ],
    'II' => [
        ['min' => 4, 'max' => 7, 'text' => 'Sınavlardaki başarınızla kendinize olan saygınızı eşdeğer görüyorsunuz. Sınavlarda ölçülerin kişilik değeriniz olmayıp bilgi düzeyiniz olduğunu kabullenmeniz gerekir. Düşünce biçiminiz problemleri çözmek konusunda size yardımcı olmadığı gibi, endişelerinizi arttırıp elinizi kolunuzu bağlıyor.'],
        ['min' => 0, 'max' => 3, 'text' => 'Sınavlardaki başarınızla kendi kişiliğinize verdiğiniz değeri birbirinden oldukça iyi ayırabildiğiniz anlaşılmaktadır. Bu tutumunuz problemleri daha etkili biçimde çözmenize imkân vermekte okul başarınızı olumlu yönde etkilemektedir.']
    ],
    'III' => [
        ['min' => 3, 'max' => 6, 'text' => 'Sınavlardaki başarınızı gelecekteki mutluluğunuz ve başarınızın tek ölçüsü olarak görüyorsunuz. Bu yaklaşım biçiminin sonucu olarak sınavların güvenliğiniz ve amaçlarınıza ulaşmanız konusunda engel olduğunu düşünüyorsunuz. Bu düşünceler bilginizi yeterince ortaya koymanızı güçleştiriyor ve başarınızı tehdit ediyor.'],
        ['min' => 0, 'max' => 2, 'text' => 'Gelecekteki mutluluğunuzun, başarınızın ve güvenliğinizin tek belirleyicisinin sınavlardaki başarınız olmadığının farkındasınız. Bu sebeple sınavlara geçilmesi gereken aşamalar olarak bakınız, bilginizi yeterince ortaya koymanıza imkân veriyor.']
    ],
    'IV' => [
        ['min' => 3, 'max' => 6, 'text' => 'Sınavları konusundaki değeriniz ve gelecekteki güvenliğinizin bir ölçüsü olarak gördüğünüz için herhangi bir sınava hazırlık dönemi sizin için bir kriz dönemi oluyor. Sınavda başarılı olmanızı sağlayacak olan hazırlanma tekniklerinizi öğrenirseniz kendinize güveniniz artacak, endişelerinizi kontrol etmek için önemli bir adım atmış olursunuz.'],
        ['min' => 0, 'max' => 2, 'text' => 'Bir sınava verdiğiniz önem, o sınavın kendi değerinden büyük olmadığı için, sınavlara büyük bir gerginlik hissetmeden hazırlanıyorsunuz. Sınavda başarılı olabilmek için, sınava hazırlanmanın sistemini bilmeniz, gereksiz gerginlikleri yaşamamanıza ve sınava huzurlu bir şekilde çalışarak başarınızın yükselmesine olanak sağlıyor.']
    ],
    'V' => [
        ['min' => 4, 'max' => 7, 'text' => 'Bir sınava hazırlanırken iştahsızlık, uykusuzluk, gerginlik gibi bir çok bedensel rahatsızlıkla mücadele etmek zorunda kaldığınız anlaşılmaktadır. Bu rahatsızlıklar sınavla ilgili hazırlığınızı güçleştirmekte ve başarınızı olumsuz yönde etkilemektedir. Bedensel tepkilerinizi kontrol etmeyi başarmanız zihinsel olarak hem hazırlığınızı, hem de sınavda bildiklerinizi ortaya koymanızı kolaylaştıracaktır.'],
        ['min' => 0, 'max' => 3, 'text' => 'Sınava hazırlık sırasında heyecanınızı kontrol edebildiğiniz ve bedensel olarak çalışmanızı zorlaştıracak bir rahatsızlık hissetmediğiniz anlaşılmaktadır.']
    ],
    'VI' => [
        ['min' => 4, 'max' => 10, 'text' => 'Sınava hazırlanırken veya sınav arasında çevrenizde olan bitenden fazlasıyla etkilenmeniz ve dikkatinizi toplamanızda güçlük çekmeniz yüksek sınav kaygısının işaretidir. Bu durum düşünce akışını yavaşlatır ve başarıyı engeller. Zihinsel ve bedensel rahatsızlığınız birbirini körükler ve sınava hazırlığınızı zorlaştırır. Sınavlarda başarılı olabilmek için zihinsel tepkilerinizi kontrol altına almayı öğrenmeniz gerekmektedir.'],
        ['min' => 0, 'max' => 3, 'text' => 'Zihinsel açıdan sınava hazırlanırken veya sınav sırasında önemli bir rahatsızlık yaşamadığınız görülmektedir. Heyecanınızı kontrol etmeniz zihinsel ve duygusal olarak hazırlığınızı kolaylaştırmakta ve başarınızı arttırmaktadır.']
    ],
    'VII' => [
        ['min' => 3, 'max' => 6, 'text' => 'Sınavlarda kendinize güvenemediğiniz, sınavları varlığınız ve geleceğiniz için bir tehdit olarak gördüğünüz anlaşılmaktadır. Sınavlara sahip oldukları önemin çok üzerinde değer vermekte ve belki de bu sebeple çok fazla heyecanlanmaktasınız. Sınav kaygınızı azaltacak teknikleri öğrenmeniz, hem eğitim başarınızı yükseltecek hem hayattan aldığınız zevki arttıracak, hem de sizi daha etkili bir insan yapacaktır.'],
        ['min' => 0, 'max' => 2, 'text' => 'Sınavları geçilmesi gereken zorunlu engeller olarak görüp hazırlandığınız görülmektedir. Eğitim hayatındaki sınavların hayatın bir parçası olduğunun farkındasınız ve bu tavrınız sınavlara hazırlığınızı kolaylaştırarak eğitim başarınızı olumlu yönde etkilemektedir.']
    ]
];

// Alt ölçek adları (Tabloda göstermek için)
$subscaleNames = [
    'I' => 'Başkalarının Görüşü',
    'II' => 'Kendi Görüşünüz',
    'III' => 'Gelecekle İlgili Endişeler',
    'IV' => 'Hazırlanmakla İlgili Endişeler',
    'V' => 'Bedensel Tepkiler',
    'VI' => 'Zihinsel Tepkiler',
    'VII' => 'Genel Sınav Kaygısı'
];

// Yorumlama Fonksiyonu (Alt Ölçek Puanına Göre)
function interpretSubscaleScore($score, $scaleKey, $interpretationKeys) {
    if ($score === null || !is_numeric($score) || !isset($interpretationKeys[$scaleKey])) return "Hesaplanamadı";

    $ranges = $interpretationKeys[$scaleKey];
    foreach ($ranges as $range) {
        if ($score >= $range['min'] && $score <= $range['max']) {
            return $range['text'];
        }
    }
    return "Geçersiz Puan Aralığı ({$score})"; // Beklenmeyen bir puan gelirse
}


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
                 error_log("Participant not found for view-result-23 (ID: {$participantId}, Survey: {$surveyId})");
            } else {
                $survey_title = !empty($participantData['survey_title']) ? htmlspecialchars($participantData['survey_title']) . " Sonuçları" : $testTitleDefault . " Sonuçları";
                // Logo URL Ayarla...
                if (!empty($participantData['admin_id']) && !empty($participantData['institution_logo_path'])) {
                   $rawInstitutionPathFromDB = $participantData['institution_logo_path'];
                   $cleanRelativePath = ltrim(str_replace('..', '', $rawInstitutionPathFromDB), '/');
                   $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/..');
                   $fullServerPath = rtrim($docRoot, '/') . '/' . $cleanRelativePath;
                   if (file_exists($fullServerPath)) { $institutionWebURL = '/' . $cleanRelativePath; }
                   else { error_log("Kurum logosu dosyası bulunamadı (view-result-23): " . $fullServerPath); }
                }

                // 2. Cevapları Çek (question_id artık sort_order, answer_text 'Doğru'/'Yanlış')
                $stmt_answers = $pdo->prepare("SELECT question_id AS sort_order, answer_text FROM survey_answers WHERE participant_id = ? AND survey_id = ? ORDER BY sort_order ASC");
                $stmt_answers->execute([$participantId, $surveyId]);
                $fetched_answers = $stmt_answers->fetchAll(PDO::FETCH_ASSOC);

                // Toplam beklenen soru sayısı (50)
                $stmt_total_questions = $pdo->prepare("SELECT COUNT(*) FROM survey_questions WHERE survey_id = ?");
                $stmt_total_questions->execute([$surveyId]);
                $totalExpectedQuestionsFetched = (int)$stmt_total_questions->fetchColumn();

                // Cevapları sort_order'a göre bir haritaya dök ve 'Doğru' -> 1, 'Yanlış' -> 0 yap
                $participantAnswersBySortOrder = []; // [sort_order => numerical_score (0 or 1)]
                $processedAnswerCount = 0; // İşlenen geçerli cevap sayısı
                foreach($fetched_answers as $ans) {
                    $sortOrder = (int)$ans['sort_order'];
                    $answerText = trim($ans['answer_text'] ?? '');
                    // 'Doğru' -> 1, 'Yanlış' -> 0
                    $numericalScore = ($answerText === 'Doğru') ? 1 : (($answerText === 'Yanlış') ? 0 : null);

                    if ($numericalScore !== null) {
                        $participantAnswersBySortOrder[$sortOrder] = $numericalScore;
                        $processedAnswerCount++; // Geçerli cevap sayısını artır
                    } else {
                        // Geçersiz metin karşılığı gelirse logla
                        error_log("Invalid answer_text '{$answerText}' found in DB for participant {$participantId}, survey {$surveyId}, sort_order {$sortOrder}");
                        // Bu cevabı dikkate alma
                    }
                }

                // Tüm sorular cevaplanmış mı kontrol et (50 soru bekleniyor)
                if ($processedAnswerCount < $totalExpectedQuestionsFetched) {
                     $error = "Katılımcı cevapları veritabanında eksik (ID: {$participantId}, Anket: {$surveyId}).";
                     error_log("Answers incomplete for participant {$participantId} in survey {$surveyId}. Expected {$totalExpectedQuestionsFetched}, found " . $processedAnswerCount);
                     // Hata durumunda skorları ve yorumları boşalt
                     $subscaleScores = []; $totalOverallScore = null; $interpretation = "Hesaplanamadı"; $processedAnswersForTable = [];
                } else {
                    // 3. Skorları Hesapla (Alt Ölçek ve Toplam)
                    $subscaleScores = array_fill_keys(array_keys($subscalesItems), 0); // Alt ölçek skorlarını sıfırla
                    $totalOverallScore = 0; // Toplam genel skor

                    // Soru metinlerini çek (tablo için)
                    $questionSortOrderToTextMap = [];
                    try {
                        $stmtQText = $pdo->prepare("SELECT sort_order, question AS question_text FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC");
                        $stmtQText->execute([$surveyId]);
                        $questionSortOrderToTextMap = $stmtQText->fetchAll(PDO::FETCH_KEY_PAIR); // [sort_order => question_text]
                    } catch(Exception $e) { error_log("DB result question text fetch error: " . $e->getMessage()); /* Hata set edilebilir */ }


                    foreach ($participantAnswersBySortOrder as $sortOrder => $numericalScore) { // Artık sayısal puan (0 veya 1)
                         // Toplam genel skor için puanı ekle
                         $totalOverallScore += $numericalScore;

                         // Alt ölçek skorları için puanı ilgili alt ölçeğe ekle
                         foreach ($subscalesItems as $scaleKey => $items) {
                             if (in_array($sortOrder, $items)) {
                                 $subscaleScores[$scaleKey] += $numericalScore;
                                 // Bir madde birden fazla alt ölçekte olabilir (örn. Madde 4)
                                 // break; // Bu satırı kaldırdık
                             }
                         }

                         // Detaylı tablo için veriyi hazırla
                         $questionText = $questionSortOrderToTextMap[$sortOrder] ?? 'Soru metni yüklenemedi';
                         $processedAnswersForTable[] = [
                             'madde' => $sortOrder,
                             'question_text' => $questionText,
                             'verilen_cevap' => ($numericalScore === 1) ? 'Doğru' : 'Yanlış', // Sayısal puanı metne çevir
                             'puan' => $numericalScore // Sayısal puan (0 veya 1)
                         ];
                    }

                    // 4. Yorumları Hesapla (Alt Ölçekler ve Toplam)
                    $interpretation = "Toplam Sınav Kaygısı Puanı: " . htmlspecialchars($totalOverallScore); // Toplam puanı göster

                    // Alt ölçek yorumları için döngü
                    $subscaleInterpretations = [];
                    foreach ($subscaleScores as $scaleKey => $score) {
                         $subscaleInterpretations[$scaleKey] = interpretSubscaleScore($score, $scaleKey, $interpretationKeys);
                    }
                    // Alt ölçek yorumları $interpretation değişkenine ekleyebilir veya ayrı gösterebilirsiniz.
                    // Şu anki yapıda alt ölçek yorumları tabloda gösterilecek.


                } // End if (empty($participantAnswersBySortOrder)) else
            } // End if (!$participantData) else

        } catch (Exception $e) {
             // Veritabanı veya diğer hatalar için genel hata yönetimi
             $error = "Sonuçlar yüklenirken bir hata oluştu: " . $e->getMessage();
             error_log("DB Error view-result-23 (ID: {$participantId}): ".$e->getMessage());
             $participantData = null; // Hata durumunda katılımcı verisini temizle
             $subscaleScores = []; $totalOverallScore = null; $interpretation = "Hesaplanamadı"; $processedAnswersForTable = [];
        }
    }

} else {
    // --- SENARYO 2: ID YOK -> SESSION'DAN ÇEK ---
    $dataSource = 'session';
    // take-survey-23.php Session'a 'total_overall_score', 'subscale_scores', 'answers' ([sort_order => answer_text]), katılımcı bilgisini kaydediyor.
    $subscaleScores = []; $totalOverallScore = null; $interpretation = "Hesaplanamadı"; $participantData = null; $processedAnswersForTable = []; // Başlangıç değerleri

    if (isset($_SESSION['survey_result_data'], $_SESSION['survey_result_data']['survey_id']) && $_SESSION['survey_result_data']['survey_id'] == $surveyId) {
        $sessionData = $_SESSION['survey_result_data'];
        // Gerekli anahtarların varlığını kontrol et
        if (isset($sessionData['total_overall_score'], $sessionData['subscale_scores'], $sessionData['answers'], $sessionData['participant_name']) && is_array($sessionData['subscale_scores']) && is_array($sessionData['answers'])) {
            $totalOverallScore = $sessionData['total_overall_score'];
            $subscaleScores = $sessionData['subscale_scores'];

            $participantData = [
                 'name' => $sessionData['participant_name'],
                 'class' => $sessionData['participant_class'] ?? null,
                 'created_at' => date('Y-m-d H:i:s', $sessionData['timestamp'] ?? time()),
                 'admin_id' => null // Session'dan gelen sonuçta admin_id olmaz
            ];
            $survey_title = $testTitleDefault . " Sonucunuz"; $error = null;

            // Detaylı tablo için veriyi hazırla (Session'daki answers [sort_order => answer_text] formatında)
            $sessionAnswers = $sessionData['answers'];
            // Toplam beklenen soru sayısı (50)
            $totalExpectedQuestions = 50; // Sınav Kaygısı Ölçeği'nde 50 soru var

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
                 if (($sortOrder_int > 0 && $sortOrder_int <= $totalExpectedQuestions) && in_array($answerText_str, $trueFalseOptions)) {

                     // Soru metnini bul
                     $questionText = $questionSortOrderToTextMap[$sortOrder_int] ?? 'Soru metni yüklenemedi';

                     // 'Doğru' -> 1, 'Yanlış' -> 0
                     $numericalScore = ($answerText_str === 'Doğru') ? 1 : 0;


                     $processedAnswersForTable[] = [
                         'madde' => $sortOrder_int,
                         'question_text' => $questionText,
                         'verilen_cevap' => $answerText_str, // Cevap metni
                         'puan' => $numericalScore // Sayısal puan (0 veya 1)
                     ];
                     $processedAnswerCount++;

                 } else {
                      // Beklenmeyen sort_order veya geçersiz cevap metni gelirse logla
                      error_log("Invalid sort_order ({$sortOrder}) or answer_text ('{$answerText}') in session data for survey {$surveyId}");
                 }
            }

            // Session'daki cevap sayısı beklenenle uyuşuyor mu kontrol et (opsiyonel)
             if ($processedAnswerCount < $totalExpectedQuestions) {
                 error_log("view-result-23 Session: Processed fewer valid answers ({$processedAnswerCount}) than expected ({$totalExpectedQuestions}) from session data.");
                 // Bu durumda tablo boşaltılabilir veya bir uyarı gösterilebilir.
                 // $processedAnswersForTable = []; // Tabloyu boşalt
             }

            // Yorumu Hesapla (Session'dan gelen toplam skoru kullanarak)
            if ($totalOverallScore !== null) {
                 $interpretation = "Toplam Sınav Kaygısı Puanı: " . htmlspecialchars($totalOverallScore); // Toplam puanı göster
                 // Alt ölçek yorumları için döngü
                 $subscaleInterpretations = [];
                 foreach ($subscaleScores as $scaleKey => $score) {
                      // Session'da alt ölçek skorları var, yorumu burada hesapla
                      $subscaleInterpretations[$scaleKey] = interpretSubscaleScore($score, $scaleKey, $interpretationKeys);
                 }
                 // Alt ölçek yorumları $interpretation değişkenine ekleyebilir veya ayrı gösterebilirsiniz.
                 // Şu anki yapıda alt ölçek yorumları tabloda gösterilecek.

            } else {
                 $interpretation = "Toplam Sınav Kaygısı Puanı hesaplanamadı (Session verisi eksik veya hatalı).";
            }

            unset($_SESSION['survey_result_data']); // Başarılı okuma sonrası temizle
        } else {
            // Session verisi eksikse hata set et ve logla
            error_log("Incomplete session data for survey 23: " . print_r($sessionData, true));
            $error = "Oturumdan alınan sonuç verisi eksik veya geçersiz (Kod: SESSION_INCOMPLETE).";
            $participantData = null; // Eksikse katılımcı verisi de yok
            $subscaleScores = []; $totalOverallScore = null; $interpretation = "Hesaplanamadı"; $processedAnswersForTable = [];
            unset($_SESSION['survey_result_data']); // Eksikse de temizle
        }
    } else {
        // Session verisi tamamen yoksa hata set et
        $error = "Görüntülenecek sonuç bulunamadı veya oturum süresi dolmuş olabilir (Kod: SESSION_MISSING).";
        $participantData = null;
        $subscaleScores = []; $totalOverallScore = null; $interpretation = "Hesaplanamadı"; $processedAnswersForTable = [];
    }
}
// --- VERİ KAYNAĞI SONU ---

// Psikometrik logo kontrolü...
if ($psikometrikWebURL) {
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/..');
    $fullPsikoServerPath = rtrim($docRoot, '/') . '/' . ltrim(str_replace('..', '', $psikometrikWebURL), '/');
    if (!file_exists($fullPsikoServerPath)) { $psikometrikWebURL = null; error_log("Psikometrik logo dosyası bulunamadı (view-result-23): " . $fullPsikoServerPath); }
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
            width: 90%; /* Konteyner genişliği */
            max-width: 600px; /* Maksimum genişlik */
            margin: 20px auto; /* Ortala ve üst/alt boşluk ver */
            padding: 15px;
            background-color: #ffffff; /* Grafik alanı arka planı */
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            height: 350px; /* Grafik konteynerine sabit bir yükseklik ver */
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


        /* Sonuç Özeti ve Tablolar */
        .result-summary { text-align: left; background-color: #e8f5e9; border-color: #c8e6c9; padding: 25px; }
        .result-summary h2 { margin-top: 0; text-align: center; }

        .score-table { width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: 0.95rem; background-color: #fff; }
        .score-table th, .score-table td { border: 1px solid #ddd; padding: 10px 12px; text-align: left; vertical-align: middle; }
        .score-table th { background-color: #dcfce7; font-weight: 600; color: #1f2937; }
        .score-table td:nth-child(1) { font-weight: bold; width: 40%; } /* Alt Ölçek Adı */
        .score-table td:nth-child(2) { width: 20%; text-align: center; font-weight: bold; font-size: 1.1em;} /* Puan */
        .score-table td:nth-child(3) { width: 40%; font-style: italic; color: #374151;} /* Yorum */
        .score-table tr:nth-child(even) { background-color: #f8f9fa; }

        .gsi-summary { text-align: center; margin-top: 20px; font-size: 1.1em; font-weight: bold; color: #1f2937; }
        .gsi-summary span { color: #0b532c; }


        /* Detaylı Cevap Tablosu Stilleri */
        .answers-table { width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: 0.9rem; }
        .answers-table th, .answers-table td { border: 1px solid #ddd; padding: 8px 10px; text-align: left; vertical-align: top; }
        .answers-table th { background-color: #dcfce7; font-weight: 600; color: #1f2937; }
        .answers-table td:nth-child(1) { width: 8%; text-align: center; font-weight: bold; vertical-align: middle;} /* Madde No */
        .answers-table td:nth-child(2) { width: 50%; line-height: 1.4; } /* Soru Metni */
        .answers-table td:nth-child(3) { width: 22%; text-align: center; vertical-align: middle;} /* Verilen Cevap */
        .answers-table td:nth-child(4) { width: 20%; text-align: center; font-weight: bold; vertical-align: middle;} /* Puan */
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
    <?php else: // Katılımcı verisi var ?>

        <h1><?= htmlspecialchars($survey_title) ?></h1>

        <div class="participant-info">
             <h2>Katılımcı Bilgileri</h2>
             <p><strong>Ad Soyad:</strong> <?= htmlspecialchars($participantData['name']) ?></p>
             <p><strong>Sınıf/Bölüm:</strong> <?= htmlspecialchars($participantData['class'] ?? 'Belirtilmemiş') ?></p>
             <p><strong>Test Tarihi:</strong> <?= date('d.m.Y H:i', strtotime($participantData['created_at'])) ?></p>
        </div>

        <?php // Grafik Alanı ?>
        <?php if (!empty($subscaleScores)): // Alt ölçek skorları varsa grafiği göster ?>
        <div class="chart-container no-print">
             <h3></h3>
             <canvas id="examAnxietyChart"></canvas>
        </div>
        <?php endif; ?>


        <div class="result-summary">
             <h2>Sınav Kaygısı Sonuçlarınız</h2>

             <?php if (!empty($subscaleScores)): ?>
                 <table class="score-table">
                     <thead>
                         <tr>
                             <th>Alt Ölçek</th>
                             <th>Puan</th>
                             <th>Yorum</th>
                         </tr>
                     </thead>
                     <tbody>
                         <?php
                         // Alt ölçek adlarının daha açıklayıcı olması için bir harita
                         $subscaleNames = [
                             'I' => 'Başkalarının Görüşü',
                             'II' => 'Kendi Görüşünüz',
                             'III' => 'Gelecekle İlgili Endişeler',
                             'IV' => 'Hazırlanmakla İlgili Endişeler',
                             'V' => 'Bedensel Tepkiler',
                             'VI' => 'Zihinsel Tepkiler',
                             'VII' => 'Genel Sınav Kaygısı'
                         ];
                         ?>
                         <?php foreach($subscalesItems as $scaleKey => $items): // Alt ölçekleri döngüye al ?>
                            <?php $score = $subscaleScores[$scaleKey] ?? null; // Alt ölçek skorunu al ?>
                         <tr>
                             <td><?= htmlspecialchars($subscaleNames[$scaleKey] ?? $scaleKey) ?></td>
                             <td><?= ($score !== null) ? htmlspecialchars($score) : 'Geçersiz' ?></td> <?php // Puanı doğrudan göster (tam sayı) ?>
                             <td><?= ($score !== null) ? htmlspecialchars(interpretSubscaleScore($score, $scaleKey, $interpretationKeys)) : 'Yetersiz Cevap' ?></td>
                         </tr>
                         <?php endforeach; ?>
                     </tbody>
                 </table>
             <?php else: ?>
                 <div class="error-box">Alt ölçek puanları hesaplanamadı (Yetersiz veya hatalı cevaplar).</div>
             <?php endif; ?>

             <?php if ($totalOverallScore !== null): ?>
                 <p class="gsi-summary"> <?php // GSI yerine Toplam Puan ?>
                     Toplam Sınav Kaygısı Puanı: <span><?= htmlspecialchars($totalOverallScore) ?></span>
                 </p>
             <?php else: ?>
                  <div class="error-box" style="margin-top: 20px;">Toplam Sınav Kaygısı Puanı hesaplanamadı (Yetersiz cevap sayısı).</div>
             <?php endif; ?>

             <p style="font-size: 0.85em; margin-top: 25px; text-align: center; color: #475569;">
                 * Bu sonuçlar bir ölçeğe aittir...
             </p>
        </div>

        <h2>Detaylı Cevaplarınız</h2>
         <?php if (!empty($processedAnswersForTable)): ?>
             <table class="answers-table">
                 <thead>
                     <tr>
                         <th>Madde No</th>
                         <th>Soru</th>
                         <th>Verilen Cevap</th>
                         <th>Puan (0-1)</th> <?php // Puan 0 veya 1 olacak ?>
                     </tr>
                 </thead>
                 <tbody>
                     <?php foreach ($processedAnswersForTable as $item): ?>
                     <tr>
                         <td><?= htmlspecialchars($item['madde']) ?></td>
                         <td><?= htmlspecialchars($item['question_text']) ?></td>
                         <td><?= htmlspecialchars($item['verilen_cevap']) ?></td>
                         <td><?= htmlspecialchars($item['puan']) ?></td>
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
    <?php if (!empty($subscaleScores)): // Alt ölçek skorları varsa grafiği çiz ?>
    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('examAnxietyChart').getContext('2d');

        // PHP'den gelen alt ölçek isimlerini ve skorlarını al
        // Sadece geçerli (null olmayan) skorları alalım
        const subscaleLabels = [];
        const subscaleData = [];
        const subscaleNamesMap = <?= json_encode($subscaleNames) ?>; // Alt ölçek adları haritası

        const rawSubscaleScores = <?= json_encode($subscaleScores) ?>; // PHP'den gelen ham skorlar

        for (const key in rawSubscaleScores) {
            if (rawSubscaleScores[key] !== null) {
                subscaleLabels.push(subscaleNamesMap[key] || key); // Alt ölçek adını veya anahtarı kullan
                subscaleData.push(rawSubscaleScores[key]);
            }
        }

        if (subscaleData.length > 0) {
             new Chart(ctx, {
                 type: 'bar', // Çubuk grafik
                 data: {
                     labels: subscaleLabels, // Alt ölçek adları
                     datasets: [{
                         label: 'Puan',
                         data: subscaleData, // Alt ölçek puanları
                         backgroundColor: 'rgba(21, 128, 61, 0.7)', // Yeşil renk
                         borderColor: 'rgba(11, 83, 44, 1)', // Koyu yeşil kenarlık
                         borderWidth: 1
                     }]
                 },
                 options: {
                     indexAxis: 'y', // Yatay çubuk grafik için ekseni y olarak ayarla
                     responsive: true,
                     maintainAspectRatio: false, // Konteyner boyutuna uyum sağlaması için
                     plugins: {
                         legend: {
                             display: false // Legend'ı gizle
                         },
                         title: {
                             display: false, // Ana başlık (h3 ile zaten var)
                         },
                         tooltip: { // Tooltip ayarları
                             callbacks: {
                                 label: function(context) {
                                     const label = context.dataset.label || '';
                                     const value = context.raw;
                                     // İlgili alt ölçeğin maksimum puanını bulmak biraz daha karmaşık olabilir
                                     // Basitçe puanı gösterelim
                                     return `${context.label}: ${value}`;
                                 }
                             }
                         }
                     },
                     scales: {
                         x: { // X ekseni artık puanları gösterecek
                             beginAtZero: true, // X eksenini sıfırdan başlat
                             // X ekseni maksimum değeri: Alt ölçeklerin en yüksek olası puanını bulalım
                             // Bu testte her madde 1 puan, max puan alt ölçekteki madde sayısı kadar.
                             max: Math.max(...Object.values(<?= json_encode(array_map('count', $subscalesItems)) ?>)) + 1,
                             title: {
                                 display: true,
                                 text: 'Puan'
                             },
                             ticks: {
                                 stepSize: 1 // X ekseninde 1'er artış
                             }
                         },
                         y: { // Y ekseni artık alt ölçek etiketlerini gösterecek
                              title: {
                                 display: true,
                                 text: 'Alt Ölçekler'
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
