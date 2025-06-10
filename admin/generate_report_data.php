<?php
session_start();
require_once '../src/config.php'; // Veritabanı bağlantısı için

// Hata raporlamayı etkinleştir (Geliştirme ortamı için uygundur, canlı ortamda kapatılmalıdır)
error_reporting(E_ALL);
ini_set('display_errors', 1);


// Giriş kontrolü (Bu dosyanın doğrudan erişimini engellemek ve yetkiyi kontrol etmek için)
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super-admin'])) {
    // Yetkisiz erişim
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Yetkisiz erişim.']);
    exit();
}

// Sadece POST isteklerini ve 'generate_report' eylemini kabul et
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action']) || $_POST['action'] !== 'generate_report') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Geçersiz istek.']);
    exit();
}

// Çıktı tamponlamayı başlat
ob_start();

header('Content-Type: application/json');

$surveyId = filter_input(INPUT_POST, 'survey_id', FILTER_VALIDATE_INT);
$selectedParticipantIds = $_POST['selected_participant_ids'] ?? [];

if (!$surveyId || !is_array($selectedParticipantIds) || empty($selectedParticipantIds)) {
    // Tamponlanmış çıktıyı temizle ve JSON hatasını gönder
    ob_clean();
    echo json_encode(['error' => 'Geçersiz anket veya katılımcı seçimi.']);
    exit();
}

// Katılımcı ID'lerini sanitize et
$sanitizedParticipantIds = array_filter($selectedParticipantIds, 'filter_var', FILTER_VALIDATE_INT);
if (empty($sanitizedParticipantIds)) {
     ob_clean();
     echo json_encode(['error' => 'Geçersiz katılımcı ID\'leri.']);
     exit();
}
$placeholders = implode(',', array_fill(0, count($sanitizedParticipantIds), '?'));


// Anket sorularını çek
$questionsStmt = $pdo->prepare("
    SELECT id, question_text, question_type, options
    FROM questions
    WHERE survey_id = ?
    ORDER BY id ASC
");
try {
    $questionsStmt->execute([$surveyId]);
    $questions = $questionsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    ob_clean();
    echo json_encode(['error' => 'Sorular çekilirken bir veritabanı hatası oluştu: ' . $e->getMessage()]);
    exit();
}


if (empty($questions)) {
    ob_clean();
    echo json_encode(['message' => 'Bu ankete ait soru bulunamadı.']);
    exit();
}

// Seçili katılımcıların ilgili ankete ait cevaplarını çek
// survey_answers tablosunda participant_id ve question_id sütunları olduğunu varsayıyoruz.
$answersStmt = $pdo->prepare("
    SELECT participant_id, question_id, answer_text, answer_value
    FROM survey_answers
    WHERE survey_id = ? AND participant_id IN ($placeholders)
");

// Parametreleri birleştir: önce surveyId, sonra katılımcı ID'leri
$params = array_merge([$surveyId], $sanitizedParticipantIds);

try {
    $answersStmt->execute($params);
    $answers = $answersStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    ob_clean();
    echo json_encode(['error' => 'Cevaplar çekilirken bir veritabanı hatası oluştu: ' . $e->getMessage()]);
    exit();
}

// Rapor verisini oluştur
$reportData = [];
$answersByQuestionAndParticipant = [];

// Cevapları soru ve katılımcıya göre grupla
foreach ($answers as $answer) {
    $answersByQuestionAndParticipant[$answer['question_id']][$answer['participant_id']][] = $answer;
}

foreach ($questions as $question) {
    $questionId = $question['id'];
    $questionText = $question['question_text'];
    $questionType = $question['question_type']; // 'radio', 'checkbox', 'text', etc.
    $options = json_decode($question['options'], true); // Seçenekleri JSON'dan array'e çevir

    $reportData[$questionId] = [
        'question_text' => $questionText,
        'question_type' => $questionType,
        'options' => $options,
        'counts' => [], // Seçenek sayımları veya metin cevapları için
    ];

    // Seçili katılımcıların bu soruya verdiği cevapları işle
    $participantsAnswersForThisQuestion = $answersByQuestionAndParticipant[$questionId] ?? [];

    if ($questionType === 'radio' || $questionType === 'checkbox') {
        // Çoktan seçmeli veya onay kutusu soruları için seçenek sayımlarını yap
        // Seçenekler array'i boşsa veya geçersizse atla
        if (is_array($options) && !empty($options)) {
             $optionValues = array_column($options, 'value');
             $optionCounts = array_fill_keys($optionValues, 0);

             foreach ($sanitizedParticipantIds as $pId) {
                  // Katılımcının bu soruya verdiği cevapları al
                  $answersForThisParticipant = $participantsAnswersForThisQuestion[$pId] ?? [];

                  foreach($answersForThisParticipant as $answer) {
                      $answerValue = $answer['answer_value']; // radio/checkbox için değer
                      if (isset($optionCounts[$answerValue])) {
                          $optionCounts[$answerValue]++;
                      }
                  }
             }
              $reportData[$questionId]['counts'] = $optionCounts;
        }


    } else if ($questionType === 'text' || $questionType === 'textarea') {
        // Metin cevapları için (şimdilik sadece listeliyoruz, sayım yapmak mantıklı değil)
        $textAnswers = [];
         foreach ($sanitizedParticipantIds as $pId) {
              $answersForThisParticipant = $answersByQuestionAndParticipant[$questionId][$pId] ?? [];
              foreach($answersForThisParticipant as $answer) {
                   if (!empty($answer['answer_text'])) {
                        $textAnswers[] = [
                            'participant_id' => $pId,
                            'answer' => $answer['answer_text']
                        ];
                   }
              }
         }
         $reportData[$questionId]['text_answers'] = $textAnswers;

    }
    // Diğer soru tipleri buraya eklenebilir
}

// Tamponlanmış çıktıyı temizle ve JSON yanıtını gönder
ob_clean();
echo json_encode(['success' => true, 'report' => $reportData, 'questions' => $questions]);
exit();

?>
