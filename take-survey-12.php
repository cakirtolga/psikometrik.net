<?php
// Hata raporlamayı geliştirme için açalım (canlıda kapatılmalı)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start(); // Oturumu başlat
header('Content-Type: text/html; charset=utf-8'); // Karakter setini ayarla

// Veritabanı bağlantısı
require_once __DIR__ . '/src/config.php';

// --- Test Konfigürasyonu ---
$surveyId = 12; // Bu testin ID'si (Çoklu Zeka Testi)
$testTitleDefault = "Çoklu Zeka Testi"; // Varsayılan Başlık
$questionsPerPage = 8; // Sayfa başına soru sayısı (56 soru / 8 sayfa = 7 q/page, 56/7 = 8q/page. 8 daha iyi)
// --- Bitiş: Test Konfigürasyonu ---

// Değişkenleri başlat
$adminId = null;
$error = null;
$survey = null;
$questions = [];
$totalQuestions = 0; // Veritabanından gelen gerçek soru sayısı ile güncellenecek
$totalPages = 0;
$groups = [];

// --- Yönetici ID Doğrulaması (URL'den) ---
if (isset($_GET['admin_id']) && filter_var($_GET['admin_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
    $potentialAdminId = (int)$_GET['admin_id'];
    try {
        $adminStmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
        $adminStmt->execute([$potentialAdminId]);
        $admin = $adminStmt->fetch(PDO::FETCH_ASSOC);
        if ($admin) {
            $adminId = $admin['id'];
        } else {
            $error = 'Geçersiz veya bulunamayan yönetici ID\'si.';
        }
    } catch (PDOException $e) {
        $error = 'Yönetici bilgisi alınırken veritabanı hatası: ' . $e->getMessage();
        error_log("Admin ID validation PDO Exception for Survey $surveyId: " . $e->getMessage());
    }
} else {
    $error = 'Yönetici ID\'si eksik veya geçersiz formatta.';
}

if (is_null($adminId)) {
    $log_error = $error ?: 'Yönetici bilgisi doğrulanamadı.';
    error_log("Access denied for take-survey-$surveyId.php: " . $log_error);
    die('Erişim reddedildi. Geçerli bir yönetici bağlantısı gereklidir.');
}
// --- Bitiş: Yönetici ID Doğrulaması ---

// --- Anket ve Soru Bilgilerini Çek ---
try {
    $stmt = $pdo->prepare("SELECT title FROM surveys WHERE id = ?");
    $stmt->execute([$surveyId]);
    $survey = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$survey) throw new Exception('Anket bulunamadı (ID: ' . $surveyId . ').');
    $testTitle = $survey['title'];

    $stmt = $pdo->prepare("SELECT id, question, sort_order FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC");
    $stmt->execute([$surveyId]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalQuestions = count($questions);
    if ($totalQuestions === 0) throw new Exception('Bu anket için soru bulunamadı (ID: ' . $surveyId . ').');

    // Beklenen soru sayısını kontrol et (Opsiyonel ama faydalı)
    $expectedQuestions = 56;
    if ($totalQuestions !== $expectedQuestions) {
         error_log("Warning: Survey $surveyId expected $expectedQuestions questions, but found $totalQuestions in database.");
         // İsterseniz kullanıcıya bir uyarı gösterebilir veya işlemi durdurabilirsiniz.
         // $error = "Uyarı: Bu testte beklenenden farklı sayıda soru bulundu ($totalQuestions). Lütfen yönetici ile iletişime geçin.";
    }


    $groups = array_chunk($questions, $questionsPerPage, false);
    $totalGroups = count($groups);

} catch (Exception $e) {
    $error = "Anket verileri yüklenirken hata: " . $e->getMessage();
    error_log("Data Fetch Error for Survey $surveyId: " . $e->getMessage());
}
// --- Bitiş: Anket ve Soru Bilgilerini Çek ---

// --- POST İsteğini Yönet ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !is_null($adminId) && $survey && $totalQuestions > 0) {
    error_log("--- POST request received for Survey $surveyId ---");
    $name = trim($_POST['student_name'] ?? '');
    $class = trim($_POST['student_class'] ?? '');
    $answers = $_POST['answers'] ?? [];
    error_log("POST Data: Name=$name, Class=$class, Answers Count=" . count($answers));

    $isValid = true;
    if (empty($name) || empty($class)) { $error = "Lütfen Ad Soyad ve Sınıf girin."; $isValid = false; }

    // Cevap sayısını kontrol et
    if ($isValid && count($answers) !== $totalQuestions) {
        $answeredCount = count($answers);
        $error = "Lütfen tüm soruları yanıtlayın. Yanıtlanan: {$answeredCount}, Toplam Gerekli: {$totalQuestions}."; $isValid = false;
        // Eksik soruları logla
        if ($questions) {
             $allQuestionIds = array_column($questions, 'id'); $answeredQuestionIds = array_keys($answers);
             $missingQuestionIds = array_diff($allQuestionIds, $answeredQuestionIds);
             if (!empty($missingQuestionIds)) error_log("Missing question IDs for Survey $surveyId: " . implode(', ', $missingQuestionIds));
        }
    }

    // Cevap değerlerini kontrol et (Belirtilen 4 seçenekten biri olmalı)
    if ($isValid) {
        $validAnswers = ['Çoğunlukla Katılmıyorum', 'Biraz Katılmıyorum', 'Biraz Katılıyorum', 'Çoğunlukla Katılıyorum'];
        foreach ($answers as $qid => $answer) {
            if (!in_array($answer, $validAnswers)) {
                 $error = "Geçersiz cevap değeri gönderildi."; $isValid = false;
                 error_log("Invalid answer value for Survey $surveyId: QID=$qid, Value=$answer");
                 break;
            }
        }
    }

    if ($isValid) {
        try {
            $pdo->beginTransaction();
            // Katılımcı Ekle
            $sqlParticipant = "INSERT INTO survey_participants (name, class, survey_id, admin_id, created_at) VALUES (?, ?, ?, ?, NOW())";
            $stmtParticipant = $pdo->prepare($sqlParticipant);
            $participantInsertSuccess = $stmtParticipant->execute([$name, $class, $surveyId, $adminId]);
            $participantId = $pdo->lastInsertId();
            if (!$participantInsertSuccess || !$participantId) throw new Exception("Katılımcı kaydedilemedi.");

            // Cevapları Ekle
            $stmtAnswer = $pdo->prepare("INSERT INTO survey_answers (participant_id, question_id, answer_text) VALUES (?, ?, ?)");
            $allAnswersInserted = true;
            foreach ($answers as $qid => $answer) {
                if (is_numeric($qid) && is_string($answer)) {
                    if (!$stmtAnswer->execute([$participantId, $qid, $answer])) $allAnswersInserted = false;
                } else { $allAnswersInserted = false; }
            }
            if (!$allAnswersInserted) throw new Exception("Cevaplar kaydedilemedi.");

            $pdo->commit();
            if (file_exists('tamamlandi.php')) { header('Location: tamamlandi.php'); exit();}
            else { echo "Anket başarıyla kaydedildi."; exit();}

        } catch (PDOException | Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Kayıt hatası: " . $e->getMessage();
            error_log("DB/Exception Error during Survey $surveyId submission: " . $e->getMessage());
        }
    }
}
// --- Bitiş: POST İsteğini Yönet ---
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $survey ? htmlspecialchars($survey['title']) : $testTitleDefault ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Stil Bloğu (take-test-11.php'den kopyalandı, butonlar için .question-button kullanılıyor) */
        body { font-family: sans-serif; line-height: 1.6; background-color: #f0fdf4; color: #2c3e50; margin: 0; padding: 20px; }
        .container { max-width: 800px; margin: 40px auto; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); text-align: left; background-color: #ffffff;}
        .question-group { display: none; }
        .question-group.active { display: block; }
        .question-button { background: #f0fdf4; border: 2px solid #bbf7d0; color: #15803d; padding: 10px 15px; /* Biraz daha dar */ border-radius: 8px; transition: all 0.2s ease-in-out; text-align: center; cursor: pointer; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); user-select: none; font-size: 0.9rem; /* Yazı biraz küçük */}
        .question-button.active { background: #22c55e; border-color: #16a34a; color: white; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06); transform: translateY(-2px); }
        .question-button:hover:not(.active) { background-color: #dcfce7; border-color: #a7f3d0; }
        .nav-btn { padding: 12px 30px; border-radius: 8px; font-weight: 600; transition: all 0.2s ease-in-out; cursor: pointer; border: none; }
        .nav-btn.next { background: #15803d; color: white; }
        .nav-btn.next:hover { background: #0b532c; }
        .nav-btn.submit { background: #2563eb; color: white; }
        .nav-btn.submit:hover { background: #1d4ed8; }
        .nav-btn:disabled { background-color: #9ca3af; cursor: not-allowed; opacity: 0.7;}
        .hidden { display: none; }
        .question { margin-bottom: 30px; }
        .options { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px; justify-content: space-between; /* Butonları yay */}
        .info label { display: block; margin-bottom: 5px; font-weight: 600; }
        .info input { padding: 8px; width: 100%; box-sizing: border-box; border: 1px solid #ccc; border-radius: 4px; font-size: 1em; color: #2c3e50; height: 40px; background-color: white;}
        .info input:focus { border-color: #15803d; box-shadow: 0 0 0 2px rgba(34, 197, 94, 0.3); outline: none; }
        .error-message { color: #b91c1c; background-color: #fee2e2; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; border: 1px solid #fca5a5; font-weight: bold; text-align: center; }
        strong { font-weight: bold; }
        .instructions { background-color: #e0f2fe; border-left: 4px solid #3b82f6; padding: 15px; margin-bottom: 25px; border-radius: 4px; font-size: 0.9rem; color: #1e3a8a; }
        .instructions h4 { font-weight: 600; margin-bottom: 0.25rem; color: #1e40af;}
    </style>
</head>
<body class="bg-[#f0fdf4] min-h-screen p-4">
    <div class="container max-w-2xl mx-auto rounded-xl shadow-sm p-8 mt-10 bg-white">
        <h2 class="text-center text-xl md:text-2xl font-bold mb-6 pb-4 border-b-2 border-[#dcfce7]">
            <?= $survey ? htmlspecialchars($survey['title']) : $testTitleDefault ?>
        </h2>

        <?php if (!empty($error)): ?>
            <div class="error-message mb-6"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!is_null($adminId) && $survey && $totalQuestions > 0): ?>
            <form method="POST" id="surveyForm" action="take-survey-12.php?admin_id=<?= htmlspecialchars($adminId) ?>" novalidate>

                <div id="personalInfo" class="info">
                    <div class="mb-6">
                        <label for="studentName" class="block font-semibold mb-2">Ad Soyad:</label>
                        <input type="text" name="student_name" id="studentName" required
                               class="w-full p-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-[#bbf7d0]">
                    </div>
                    <div class="mb-8">
                        <label for="studentClass" class="block font-semibold mb-2">Sınıf:</label>
                        <input type="text" name="student_class" id="studentClass" required
                               class="w-full p-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-[#bbf7d0]">
                    </div>
                     <div class="instructions">
                        <h4 class="font-semibold mb-1">Yönerge:</h4>
                        Aşağıdaki ifadeleri dikkatlice okuyun. Her ifadenin size ne kadar uygun olduğunu belirtmek için 1'den 4'e kadar bir puan seçin:<br>
                        <strong>1:</strong> Çoğunlukla Katılmıyorum,
                        <strong>2:</strong> Biraz Katılmıyorum,
                        <strong>3:</strong> Biraz Katılıyorum,
                        <strong>4:</strong> Çoğunlukla Katılıyorum
                    </div>
                </div>

                <div id="questionGroups">
                    <?php
                    if (is_array($groups) && !empty($groups)):
                        $questionCounter = 1;
                        foreach ($groups as $pageIndex => $pageQuestions):
                    ?>
                        <div class="question-group <?= $pageIndex === 0 ? 'active' : '' ?>" data-group="<?= $pageIndex ?>">
                            <?php foreach ($pageQuestions as $q): ?>
                                <div class="mb-8 question" data-question-id="<?= $q['id'] ?>">
                                    <p class="text-lg font-semibold mb-4">
                                        <strong><?= $questionCounter ?>.</strong>
                                        <?= isset($q['question']) ? htmlspecialchars($q['question']) : '...' ?>
                                    </p>
                                    <div class="flex flex-wrap gap-3 md:gap-4 mb-6 options justify-center sm:justify-start">
                                        <?php
                                        // Seçenekler bu test için 1-4 arası metinler
                                        $options = [
                                            '1' => 'Çoğunlukla Katılmıyorum',
                                            '2' => 'Biraz Katılmıyorum',
                                            '3' => 'Biraz Katılıyorum',
                                            '4' => 'Çoğunlukla Katılıyorum'
                                         ];
                                        // Butonlarda rakam yerine metinleri gösterelim, value olarak metni kaydedelim
                                        foreach ($options as $value => $label):
                                        ?>
                                            <button type="button"
                                                    class="question-button"
                                                    onclick="selectAnswer(<?= $q['id'] ?>, this)"
                                                    data-value="<?= htmlspecialchars($label) ?>"> <?= htmlspecialchars($label) ?> </button>
                                        <?php endforeach; ?>
                                    </div>
                                    <input type="hidden" name="answers[<?= $q['id'] ?>]" id="answer_<?= $q['id'] ?>" required>
                                </div>
                            <?php $questionCounter++; endforeach; ?>
                        </div>
                    <?php endforeach; endif; ?>
                </div>

                <div class="flex justify-end gap-4 mt-8 navigation">
                     <button type="button" id="nextBtn" class="nav-btn next">İleri →</button>
                    <button type="submit" id="submitBtn" class="nav-btn submit hidden">Gönder</button>
                </div>
            </form>
        <?php elseif (!$survey || $totalQuestions === 0): ?>
             <div class="error-message"><?= $error ?: 'Anket veya sorular yüklenemedi.' ?></div>
        <?php endif; ?>

    </div> <script>
        // JavaScript kodu take-test-11.php ile aynı, class adları zaten uyumlu (.question-button)
        const groups = document.querySelectorAll('.question-group');
        const personalInfo = document.getElementById('personalInfo');
        const nextBtn = document.getElementById('nextBtn');
        const submitBtn = document.getElementById('submitBtn');
        const nameInput = document.getElementById('studentName');
        const classInput = document.getElementById('studentClass');
        const form = document.getElementById('surveyForm');

        let current = 0;
        const totalGroups = groups.length;

        function showPage(index) {
            if (index > current) {
                if (current === 0) {
                    if (!nameInput || nameInput.value.trim() === '' || !classInput || classInput.value.trim() === '') {
                        alert('Lütfen Ad Soyad ve Sınıf bilgilerinizi girin.'); return false;
                    }
                }
                if (groups.length > 0 && groups[current]) {
                    const currentPageQuestions = groups[current].querySelectorAll('.question');
                    let allAnswered = true;
                    currentPageQuestions.forEach(questionDiv => {
                        const hiddenInput = questionDiv.querySelector('input[type="hidden"][name^="answers"]');
                        if (!hiddenInput || hiddenInput.value.trim() === '') allAnswered = false;
                    });
                    if (!allAnswered) { alert('Lütfen bu sayfadaki tüm soruları yanıtlayın.'); return false; }
                }
            }

            if (personalInfo) personalInfo.style.display = index === 0 ? 'block' : 'none';
            groups.forEach((group, i) => group.classList.toggle('active', i === index));
            const isLastPage = index === totalGroups - 1;
            if (nextBtn) nextBtn.classList.toggle('hidden', isLastPage || totalGroups === 0);
            if (submitBtn) submitBtn.classList.toggle('hidden', !isLastPage || totalGroups === 0);
            if (nameInput) nameInput.readOnly = index > 0;
            if (classInput) classInput.readOnly = index > 0;
            current = index;

             if (groups.length > 0 && groups[current]) {
                 groups[current].querySelectorAll('.question-button').forEach(btn => { // .question-button kullanılıyor
                     const qidMatch = btn.onclick.toString().match(/selectAnswer\((\d+)/);
                     if (qidMatch && qidMatch[1]) {
                         const questionId = qidMatch[1];
                         const hiddenInput = document.getElementById(`answer_${questionId}`);
                         btn.classList.toggle('active', hiddenInput && hiddenInput.value === btn.dataset.value);
                     }
                 });
             }
             return true;
        }

        function selectAnswer(questionId, button) {
            const optionsDiv = button.closest('.options');
            if (!optionsDiv) return;
            optionsDiv.querySelectorAll('.question-button').forEach(btn => btn.classList.remove('active')); // .question-button kullanılıyor
            button.classList.add('active');
            const hiddenInput = document.getElementById(`answer_${questionId}`);
            if (hiddenInput) hiddenInput.value = button.dataset.value;
             else { console.error("Hidden input not found for question ID:", questionId); }
        }

        if (totalGroups > 0) { showPage(0); }
        else {
             if (personalInfo) personalInfo.style.display = 'block';
             if (nextBtn) nextBtn.classList.add('hidden');
             if (submitBtn) submitBtn.classList.add('hidden');
        }

        if (nextBtn) { nextBtn.addEventListener('click', () => { if (current < totalGroups - 1) showPage(current + 1); }); }
        if (form) {
            form.addEventListener('submit', function(event) {
                if (!nameInput || nameInput.value.trim() === '' || !classInput || classInput.value.trim() === '') {
                    alert('Lütfen Ad Soyad ve Sınıf bilgilerini girin.'); event.preventDefault(); showPage(0); return;
                }
                if (totalGroups > 0 && groups[current]) {
                    const lastPageQuestions = groups[current].querySelectorAll('.question');
                    let allAnsweredOnLastPage = true;
                    lastPageQuestions.forEach(questionDiv => {
                        const hiddenInput = questionDiv.querySelector('input[type="hidden"][name^="answers"]');
                        if (!hiddenInput || hiddenInput.value.trim() === '') allAnsweredOnLastPage = false;
                    });
                    if (!allAnsweredOnLastPage) { alert('Lütfen bu sayfadaki tüm soruları yanıtlayın.'); event.preventDefault(); return; }
                }
                 if (submitBtn) submitBtn.disabled = true;
            });
        }
    </script>

</body>
</html>