<?php
session_start();
require_once '/home/dahisinc/public_html/testanket/src/config.php';

$surveyId = 4;

// Anket bilgisi
$stmt = $pdo->prepare("SELECT * FROM surveys WHERE id = ?");
$stmt->execute([$surveyId]);
$survey = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$survey) die('Anket bulunamadı.');

// Soruları çek
$stmt = $pdo->prepare("SELECT * FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC");
$stmt->execute([$surveyId]);
$questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalPages = ceil(count($questions) / 5);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['student_name']);
    $class = trim($_POST['student_class']);
    $answers = $_POST['answers'] ?? [];

    if (!$name || !$class || empty($answers)) {
        $error = "Lütfen tüm bilgileri doldurun ve her soruya yanıt verin.";
    } else {
        $stmt = $pdo->prepare("INSERT INTO survey_participants (name, class, survey_id, admin_id) VALUES (?, ?, ?, 0)");
        $stmt->execute([$name, $class, $surveyId]);
        $participantId = $pdo->lastInsertId();

        foreach ($answers as $qid => $answer) {
            $stmt = $pdo->prepare("INSERT INTO survey_answers (participant_id, question_id, answer_text) VALUES (?, ?, ?)");
            $stmt->execute([$participantId, $qid, $answer]);
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
  <title><?php echo htmlspecialchars($survey['title']); ?></title>
  <link href="assets/css/soft-green.css" rel="stylesheet">
  <style>
    .container { max-width: 800px; margin: 40px auto; background: #f6fff7; padding: 30px; border-radius: 8px; }
    .page { display: none; }
    .active { display: block; }
    .option-btn {
      display: inline-block; margin: 5px; padding: 10px 20px;
      border: 2px solid #a3d9a5; background-color: #e4f6e7;
      cursor: pointer; border-radius: 8px; font-weight: 500;
      transition: all 0.2s;
    }
    .option-btn:hover { background-color: #bde7c0; }
    .option-btn.selected { background-color: #7ccc89; color: white; font-weight: bold; }
    .navigation button {
      margin-top: 20px;
      padding: 12px 20px;
      font-size: 16px;
      border: none;
      background-color: #5ba979;
      color: white;
      border-radius: 6px;
      cursor: pointer;
    }
    .navigation button[disabled] { display: none; }
    .navigation { display: flex; justify-content: space-between; }
    .question { margin-bottom: 30px; }
    .info input { margin: 0 10px 20px 10px; padding: 8px; width: 45%; }
  </style>
</head>
<body>

<div class="container">
  <h2 style="text-align:center"><?php echo htmlspecialchars($survey['title']); ?></h2>

  <?php if (!empty($error)) echo "<p style='color:red;'>$error</p>"; ?>

  <form method="POST" id="surveyForm">

    <div class="info">
      <label>Ad Soyad:</label>
      <input type="text" name="student_name" id="studentName" required>
      <label>Sınıf:</label>
      <input type="text" name="student_class" id="studentClass" required>
    </div>

    <?php
    $pageIndex = 0;
    foreach (array_chunk($questions, 5, true) as $pageQuestions):
    ?>
      <div class="page <?php echo $pageIndex === 0 ? 'active' : ''; ?>" data-page="<?php echo $pageIndex; ?>">
        <?php foreach ($pageQuestions as $index => $q): ?>
          <div class="question">
            <p><strong><?php echo $index + 1 + ($pageIndex * 5); ?>.</strong> <?php echo htmlspecialchars($q['question']); ?></p>

            <div class="options">
              <?php foreach (['Evet', 'Hayır'] as $opt): ?>
                <span class="option-btn" data-qid="<?php echo $q['id']; ?>" data-val="<?php echo $opt; ?>"><?php echo $opt; ?></span>
              <?php endforeach; ?>
            </div>
            <input type="hidden" name="answers[<?php echo $q['id']; ?>]" id="input-<?php echo $q['id']; ?>" required>
          </div>
        <?php endforeach; ?>
      </div>
    <?php $pageIndex++; endforeach; ?>

    <div class="navigation">
      <button type="button" id="backBtn" onclick="prevPage()">← Geri</button>
      <button type="button" id="nextBtn" onclick="nextPage()">İleri →</button>
      <button type="submit" id="submitBtn">Gönder</button>
    </div>

  </form>
</div>

<script>
  let currentPage = 0;
  const pages = document.querySelectorAll('.page');
  const totalPages = pages.length;
  const backBtn = document.getElementById('backBtn');
  const nextBtn = document.getElementById('nextBtn');
  const submitBtn = document.getElementById('submitBtn');
  const nameInput = document.getElementById('studentName');
  const classInput = document.getElementById('studentClass');

  function showPage(index) {
    pages.forEach(p => p.classList.remove('active'));
    pages[index].classList.add('active');

    backBtn.disabled = index === 0;
    nextBtn.style.display = (index < totalPages - 1) ? 'inline-block' : 'none';
    submitBtn.style.display = (index === totalPages - 1) ? 'inline-block' : 'none';

    nameInput.readOnly = index > 0;
    classInput.readOnly = index > 0;
  }

  function nextPage() {
    if (currentPage < totalPages - 1) {
      currentPage++;
      showPage(currentPage);
    }
  }

  function prevPage() {
    if (currentPage > 0) {
      currentPage--;
      showPage(currentPage);
    }
  }

  document.querySelectorAll('.option-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const qid = btn.dataset.qid;
      const val = btn.dataset.val;

      document.querySelectorAll(`.option-btn[data-qid="${qid}"]`).forEach(b => b.classList.remove('selected'));
      btn.classList.add('selected');

      document.getElementById(`input-${qid}`).value = val;
    });
  });

  showPage(0);
</script>

</body>
</html>
