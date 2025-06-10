<?php
// Hata Ayarları
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/src/config.php';

$surveyId = 4;

// Anket verisi
$stmt = $pdo->prepare("SELECT * FROM surveys WHERE id = ?");
$stmt->execute([$surveyId]);
$survey = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$survey) {
    die("Anket bulunamadı.");
}

// Sorular
$stmt = $pdo->prepare("SELECT * FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC");
$stmt->execute([$surveyId]);
$questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalQuestions = count($questions);
$totalPages = ceil($totalQuestions / 5);

// Form gönderildiğinde
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['student_name']);
    $class = trim($_POST['student_class']);
    $answers = $_POST['answers'] ?? [];

    if (!$name || !$class) {
        $error = "Ad Soyad ve Sınıf zorunludur.";
    } elseif (count($answers) !== $totalQuestions) {
        $error = "Lütfen tüm soruları yanıtlayınız.";
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO survey_participants (name, class, survey_id, admin_id) VALUES (?, ?, ?, 0)");
            $stmt->execute([$name, $class, $surveyId]);
            $participantId = $pdo->lastInsertId();

            $stmt = $pdo->prepare("INSERT INTO survey_answers (participant_id, question_id, answer_text) VALUES (?, ?, ?)");
            foreach ($answers as $qid => $ans) {
                $stmt->execute([$participantId, $qid, $ans]);
            }

            $pdo->commit();
            header("Location: tamamlandi.php");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Kayıt sırasında bir hata oluştu.";
        }
    }
}

// Sayfalama
$groups = array_chunk($questions, 5);
?>

<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($survey['title']) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-green-50 min-h-screen text-gray-800">
<div class="max-w-3xl mx-auto p-6 mt-10 bg-white shadow-lg rounded-lg">

  <h1 class="text-2xl font-bold text-center mb-6"><?= htmlspecialchars($survey['title']) ?></h1>

  <?php if (!empty($error)): ?>
    <div class="bg-red-100 text-red-800 p-4 mb-6 rounded"><?= $error ?></div>
  <?php endif; ?>

  <form method="POST" id="surveyForm">
    <div id="page-0" class="question-page">
      <div class="mb-4">
        <label class="font-semibold">Ad Soyad:</label>
        <input name="student_name" type="text" required class="w-full mt-1 p-2 border rounded">
      </div>
      <div class="mb-6">
        <label class="font-semibold">Sınıf:</label>
        <input name="student_class" type="text" required class="w-full mt-1 p-2 border rounded">
      </div>
    </div>

    <?php foreach ($groups as $page => $group): ?>
      <div id="page-<?= $page + 1 ?>" class="question-page hidden">
        <?php foreach ($group as $index => $q): ?>
          <div class="mb-6">
            <p class="mb-2 font-medium"> <?= htmlspecialchars($q['question']) ?></p>
            <div class="flex gap-3 flex-wrap">
              <?php foreach (['Evet', 'Hayır', 'Kısmen'] as $opt): ?>
                <button type="button"
                        class="answer-btn px-4 py-2 bg-green-100 border border-green-300 rounded hover:bg-green-200"
                        onclick="selectAnswer(<?= $q['id'] ?>, this)"
                        data-value="<?= $opt ?>"><?= $opt ?></button>
              <?php endforeach; ?>
            </div>
            <input type="hidden" name="answers[<?= $q['id'] ?>]" id="answer_<?= $q['id'] ?>" required>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

    <div class="flex justify-end gap-4 mt-6">
      <button type="button" id="nextBtn" class="bg-green-600 text-white px-6 py-2 rounded">İleri</button>
      <button type="submit" id="submitBtn" class="bg-blue-600 text-white px-6 py-2 rounded hidden">Gönder</button>
    </div>
  </form>
</div>

<script>
  let current = 0;
  const pages = document.querySelectorAll('.question-page');
  const nextBtn = document.getElementById('nextBtn');
  const submitBtn = document.getElementById('submitBtn');

  function showPage(i) {
    pages.forEach(p => p.classList.add('hidden'));
    pages[i].classList.remove('hidden');
    if (i === pages.length - 1) {
      nextBtn.classList.add('hidden');
      submitBtn.classList.remove('hidden');
    }
  }

  function selectAnswer(qid, el) {
    const siblings = el.parentElement.querySelectorAll('.answer-btn');
    siblings.forEach(btn => btn.classList.remove('bg-green-600', 'text-white'));
    el.classList.add('bg-green-600', 'text-white');
    document.getElementById('answer_' + qid).value = el.dataset.value;
  }

  nextBtn.addEventListener('click', () => {
    const currentPage = pages[current];
    const requiredInputs = currentPage.querySelectorAll('input[type="hidden"]');
    let valid = true;
    requiredInputs.forEach(inp => {
      if (!inp.value) valid = false;
    });

    if (!valid) {
      alert("Lütfen bu sayfadaki tüm soruları cevaplayın.");
      return;
    }

    current++;
    showPage(current);
  });

  showPage(0);
</script>
</body>
</html>
