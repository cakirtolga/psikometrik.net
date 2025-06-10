<?php
session_start();
require_once '/home/dahisinc/public_html/testanket/src/config.php';

// Anket ID'sini URL'den al
$surveyId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$userEmail = isset($_GET['user_email']) ? trim($_GET['user_email']) : '';

if (!$surveyId || !$userEmail) {
    die('Geçersiz anket veya admin bağlantısı.');
}

// Admin bilgisini çek
$adminStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$adminStmt->execute([$userEmail]);
$admin = $adminStmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    die('Admin bulunamadı.');
}
$adminId = $admin['id'];

// Anket bilgisi
$stmt = $pdo->prepare("SELECT * FROM surveys WHERE id = ?");
$stmt->execute([$surveyId]);
$survey = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$survey) {
    die('Anket bulunamadı.');
}

// Soruları çek
$questionsStmt = $pdo->prepare("SELECT * FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC");
$questionsStmt->execute([$surveyId]);
$questions = $questionsStmt->fetchAll(PDO::FETCH_ASSOC);

// POST işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $class = trim($_POST['class']);

    if (!$name || !$class) {
        $error = "Lütfen adınızı ve sınıfınızı doldurun.";
    } else {
        // Katılımcı kaydet
        $participantStmt = $pdo->prepare("INSERT INTO survey_participants (name, class, survey_id, admin_id) VALUES (?, ?, ?, ?)");
        $participantStmt->execute([$name, $class, $surveyId, $adminId]);
        $participantId = $pdo->lastInsertId();

        // Cevapları kaydet
        foreach ($_POST['answers'] as $questionId => $answer) {
            if (is_array($answer)) {
                $answer = implode(', ', $answer); // Çoklu seçim için
            }
            $answerStmt = $pdo->prepare("INSERT INTO survey_answers (participant_id, question_id, answer_text) VALUES (?, ?, ?)");
            $answerStmt->execute([$participantId, $questionId, $answer]);
        }

        header('Location: tamamlandi.php');
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <title><?php echo htmlspecialchars($survey['title']); ?> | Anket</title>
  <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>

<div class="container">
    <h1><?php echo htmlspecialchars($survey['title']); ?></h1>

    <?php if (isset($error)): ?>
        <div class="result"><?php echo $error; ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="question-group">
            <div class="question">
                <label>Ad Soyad:</label>
                <input type="text" name="name" required class="w-full p-2 border rounded mt-2">
            </div>
            <div class="question">
                <label>Sınıf:</label>
                <input type="text" name="class" required class="w-full p-2 border rounded mt-2">
            </div>
        </div>

        <?php
        $counter = 0;
        foreach ($questions as $index => $question):
            if ($counter % 5 == 0 && $counter != 0) {
                echo '<hr style="margin:20px 0;">'; // Her 5 soruda bir ayırıcı çizgi
            }
        ?>
            <div class="question-group">
                <div class="question">
                    <strong><?php echo ($index + 1) . '. ' . htmlspecialchars($question['question']); ?></strong>
                    <div class="options">
                        <?php if (in_array($question['answer_type'], ['evet_hayir', 'puanlama_1_5', 'coktan_secmeli'])): ?>

                            <?php if ($question['answer_type'] == 'evet_hayir'): ?>
                                <label><input type="radio" name="answers[<?php echo $question['id']; ?>]" value="Evet" required> Evet</label>
                                <label><input type="radio" name="answers[<?php echo $question['id']; ?>]" value="Hayır" required> Hayır</label>

                            <?php elseif ($question['answer_type'] == 'puanlama_1_5'): ?>
                                <select name="answers[<?php echo $question['id']; ?>]" required>
                                    <option value="">Seçiniz</option>
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                                    <?php endfor; ?>
                                </select>

                            <?php elseif ($question['answer_type'] == 'coktan_secmeli'): ?>
                                <label><input type="radio" name="answers[<?php echo $question['id']; ?>]" value="Annem" required> Annem</label>
                                <label><input type="radio" name="answers[<?php echo $question['id']; ?>]" value="Babam" required> Babam</label>
                                <label><input type="radio" name="answers[<?php echo $question['id']; ?>]" value="Her ikisi" required> Her ikisi</label>
                            <?php endif; ?>

                        <?php elseif ($question['answer_type'] == 'acik_cevap'): ?>
                            <textarea name="answers[<?php echo $question['id']; ?>]" rows="3" class="w-full p-2 border rounded" required></textarea>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php 
        $counter++;
        endforeach;
        ?>

        <button type="submit" class="button-submit">Gönder</button>
    </form>
</div>

</body>
</html>
