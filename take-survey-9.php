<?php
session_start();
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/src/config.php';

// --- Sakıncalı Kelime Listesi (Basit Örnek) ---
// Gerçek bir uygulamada bu liste daha kapsamlı olmalı ve ayrı bir dosyada/veritabanında tutulmalıdır.
$badWords = [
    'aptal', 'salak', 'gerizekalı', 'lan', 'öküz',
    // ... küfürler ve argo kelimeler (buraya eklenmeli)
    'mal', 'dangalak'
];
// Regex için kelimeleri hazırlayalım (kelime sınırları ve case-insensitive)
$badWordsPattern = '/\b(' . implode('|', array_map('preg_quote', $badWords, ['/'])) . ')\b/iu'; // 'u' flag'i UTF-8 için
// --- Bitiş: Sakıncalı Kelime Listesi ---


$surveyId = 9;
$adminId = null;
$error = null;

if (isset($_GET['admin_id']) && filter_var($_GET['admin_id'], FILTER_VALIDATE_INT) !== false && $_GET['admin_id'] > 0) {
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
    }
} else {
    $error = 'Yönetici ID\'si eksik veya geçersiz.';
}

if (is_null($adminId) && $error) {
    $survey = null; $questions = []; $totalQuestions = 0; $totalPages = 0; $groups = [];
} else {
    try {
        $stmt = $pdo->prepare("SELECT * FROM surveys WHERE id = ?");
        $stmt->execute([$surveyId]);
        $survey = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$survey) {
            $error = 'Anket bulunamadı.'; $questions = []; $totalQuestions = 0; $totalPages = 0; $groups = [];
        } else {
            $stmt = $pdo->prepare("SELECT * FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC");
            $stmt->execute([$surveyId]);
            $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $totalQuestions = count($questions);
            $totalPages = ceil($totalQuestions / 5);
            $groups = array_chunk($questions, 5, true);
        }
    } catch (PDOException $e) {
        $error = 'Anket veya soru bilgileri alınırken veritabanı hatası: ' . $e->getMessage();
        $survey = null; $questions = []; $totalQuestions = 0; $totalPages = 0; $groups = [];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !is_null($adminId) && $survey) {
    $name = trim($_POST['student_name'] ?? '');
    $class = trim($_POST['student_class'] ?? '');
    $answers = $_POST['answers'] ?? [];

    // 1. Boş Alan Kontrolü
    if (empty($name) || empty($class)) {
       $error = "Lütfen Ad Soyad ve Sınıf bilgilerinizi girin.";
    }
    // 2. Sakıncalı Kelime Kontrolü (Boş değilse)
    elseif (preg_match($badWordsPattern, $name) || preg_match($badWordsPattern, $class)) {
         $error = "Lütfen Ad Soyad ve Sınıf alanlarında uygun bir dil kullanın.";
    }
    // 3. Cevap Sayısı Kontrolü
    else if (count($answers) !== $totalQuestions) {
        $missingAnswers = $totalQuestions - count($answers);
        $error = "Lütfen tüm soruları yanıtlayın. Yanıtlanan: " . count($answers) . ", Toplam: " . $totalQuestions . ". Eksik: " . $missingAnswers;
    }
    // 4. Cevap Geçerliliği Kontrolü
    else {
        $allValid = true;
        foreach ($answers as $qid => $answer) {
            if (!is_numeric($qid) || !is_string($answer) || empty($answer)) {
                $allValid = false; break;
            }
        }

        if (!$allValid) {
             $error = "Geçersiz cevap formatı veya boş cevap gönderildi.";
        } else {
            // Veritabanı işlemleri (Eğer tüm kontroller geçerse)
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO survey_participants (name, class, survey_id, admin_id) VALUES (?, ?, ?, ?)");
                $participantInsertSuccess = $stmt->execute([$name, $class, $surveyId, $adminId]);
                $participantId = $pdo->lastInsertId();

                if ($participantInsertSuccess && $participantId) {
                    $stmt = $pdo->prepare("INSERT INTO survey_answers (participant_id, question_id, answer_text) VALUES (?, ?, ?)");
                    $allAnswersInserted = true;
                    foreach ($answers as $qid => $answer) {
                        if (!$stmt->execute([$participantId, $qid, $answer])) {
                            $allAnswersInserted = false; break;
                        }
                    }
                    if ($allAnswersInserted) {
                        $pdo->commit();
                        if (file_exists('tamamlandi.php')) { header('Location: tamamlandi.php'); exit(); }
                        else { $error = "Anket başarıyla tamamlandı ancak 'tamamlandi.php' bulunamadı."; }
                    } else { $pdo->rollBack(); $error = "Cevaplar veritabanına kaydedilirken bir hata oluştu."; }
                } else { $pdo->rollBack(); $error = "Katılımcı bilgileri kaydedilemedi."; }
            } catch (PDOException $e) { $pdo->rollBack(); $error = "Veritabanı hatası oluştu: " . $e->getMessage(); }
              catch (Exception $e) { $pdo->rollBack(); $error = "Beklenmeyen bir hata oluştu: " . $e->getMessage(); }
        }
    }
}

$surveyTitle = $survey ? htmlspecialchars($survey['title']) : 'Anket Yüklenemedi';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $surveyTitle ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* --- Stil Bloğu (Önceki ile aynı) --- */
        body { font-family: sans-serif; line-height: 1.6; background-color: #f0fdf4; color: #2c3e50; margin: 0; padding: 20px; }
        .container { max-width: 800px; margin: 40px auto; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); text-align: left; background-color: #ffffff; }
        .question-group { display: none; }
        .question-group.active { display: block; }
        .question-button { background: #f0fdf4; border: 2px solid #bbf7d0; color: #15803d; padding: 10px 18px; border-radius: 8px; transition: all 0.2s ease-in-out; text-align: center; cursor: pointer; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); }
        .question-button.active { background: #22c55e; border-color: #16a34a; color: white; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06); transform: translateY(-2px); }
        .question-button:hover:not(.active) { background-color: #dcfce7; border-color: #a7f3d0; }
        .nav-btn { padding: 12px 30px; border-radius: 8px; font-weight: 600; transition: all 0.2s ease-in-out; cursor: pointer; border: none; }
        .nav-btn.next { background: #15803d; color: white; }
        .nav-btn.next:hover { background: #0b532c; }
        .nav-btn.submit { background: #2563eb; color: white; }
        .nav-btn.submit:hover { background: #1d4ed8; }
        .hidden { display: none; }
        .question { margin-bottom: 30px; }
        .options { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px; }
        .info label { display: block; margin-bottom: 5px; font-weight: 600; }
        .info input { padding: 8px; width: 100%; box-sizing: border-box; border: 1px solid #ccc; border-radius: 4px; font-size: 1em; color: #2c3e50; }
        .error-message { color: #b91c1c; background-color: #fee2e2; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; border: 1px solid #fca5a5; font-weight: bold; text-align: center; }
        .info input:focus { border-color: #15803d; box-shadow: 0 0 0 2px rgba(34, 197, 94, 0.3); outline: none; }
        strong { font-weight: bold; }
        /* --- Yeni: İstemci tarafı uyarı stili --- */
        .input-warning {
            color: #b91c1c; /* Kırmızı */
            font-size: 0.875rem; /* Biraz daha küçük */
            margin-top: 4px; /* Üstteki elemandan boşluk */
            display: none; /* Varsayılan olarak gizli */
        }
    </style>
</head>
<body class="bg-[#f0fdf4] min-h-screen p-4">
    <div class="container max-w-2xl mx-auto rounded-xl shadow-lg p-8 mt-10 bg-white">
        <h2 class="text-center text-2xl font-bold mb-6 pb-4 border-b-2 border-[#dcfce7]">
            <?= $surveyTitle ?>
        </h2>

        <?php if (!empty($error)): ?>
            <div class="error-message mb-6">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($survey && !$error || ($survey && $error && $_SERVER['REQUEST_METHOD'] !== 'POST')): // Başlangıçta hata yoksa veya POST hatası varsa formu göster?>
            <form method="POST" id="surveyForm" novalidate> <div id="personalInfo" class="info <?= ($totalGroups > 0 && $totalPages > 0) ? '' : 'active' ?>">
                    <div class="mb-4"> <label for="studentName" class="block font-semibold mb-2">Ad Soyad:</label>
                        <input type="text" name="student_name" id="studentName" required
                               class="w-full p-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-[#bbf7d0] focus:border-[#15803d]">
                        <span id="studentNameWarning" class="input-warning">Lütfen uygun bir dil kullanın.</span>
                    </div>
                    <div class="mb-6"> <label for="studentClass" class="block font-semibold mb-2">Sınıf:</label>
                        <input type="text" name="student_class" id="studentClass" required
                               class="w-full p-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-[#bbf7d0] focus:border-[#15803d]">
                        <span id="studentClassWarning" class="input-warning">Lütfen uygun bir dil kullanın.</span>
                    </div>
                </div>

                <div id="questionGroups">
                    <?php $questionCounter = 1; foreach ($groups as $pageIndex => $pageQuestions): ?>
                        <div class="question-group <?= $pageIndex === 0 ? 'active' : '' ?>" data-group="<?= $pageIndex ?>">
                             <?php foreach ($pageQuestions as $qid => $q): ?>
                                <div class="question mb-8">
                                    <p class="text-lg font-semibold mb-4">
                                        <strong><?= $questionCounter ?>.</strong> <?= htmlspecialchars($q['question']) ?>
                                    </p>
                                    <div class="options flex flex-wrap gap-3 mt-2">
                                         <?php foreach (['Tamamen Yanlış', 'Katılmıyorum', 'Kısmen Katılıyorum', 'Katılıyorum', 'Tamamen Katılıyorum'] as $option): ?>
                                            <button type="button"
                                                    class="question-button flex-grow sm:flex-grow-0 py-2 px-4"
                                                    onclick="selectAnswer(<?= $q['id'] ?>, this)"
                                                    data-value="<?= htmlspecialchars($option) ?>">
                                                <?= htmlspecialchars($option) ?>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                    <input type="hidden" name="answers[<?= $q['id'] ?>]" id="answer_<?= $q['id'] ?>" required>
                                </div>
                                <?php $questionCounter++; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="navigation flex justify-end gap-4 mt-8">
                    <button type="button" id="nextBtn" class="nav-btn next">İleri →</button>
                    <button type="submit" id="submitBtn" class="nav-btn submit hidden">Gönder</button>
                </div>
            </form>
        <?php elseif (!$survey && !$error): ?>
            <p class="text-center text-gray-600 mt-6">Belirtilen anket bulunamadı.</p>
        <?php endif; ?>

    </div>

    <script>
        const groups = document.querySelectorAll('.question-group');
        const personalInfo = document.getElementById('personalInfo');
        const nextBtn = document.getElementById('nextBtn');
        const submitBtn = document.getElementById('submitBtn');
        const nameInput = document.getElementById('studentName');
        const classInput = document.getElementById('studentClass');
        const nameWarning = document.getElementById('studentNameWarning'); // Uyarı span'ı
        const classWarning = document.getElementById('studentClassWarning'); // Uyarı span'ı
        const form = document.getElementById('surveyForm');

        let current = 0;
        const totalGroups = groups.length;

        // --- İstemci Tarafı Sakıncalı Kelime Listesi ve Regex ---
        const jsBadWords = [
            'göt','sik','aptal', 'salak', 'gerizekalı', 'lan', 'öküz', 'moruk', 'gerzek', 'mankafa', 'şerefsiz', 'hıyar', 'puşt', 'kafasız', 'yarrak', 'amcık', 'siktir', 'orospu', 'piç', 'kahpe', 'sürtük', 'kevaşe', 'ibne', 'it', 'çingene', 'kaltak', 'yavşak'
        ];
        const jsBadWordsPattern = new RegExp('\\b(' + jsBadWords.map(word => word.replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&')).join('|') + ')\\b', 'iu');
        // --- Bitiş: İstemci Tarafı Sakıncalı Kelime Listesi ---

        // Fonksiyon: Input alanını kontrol et ve uyarı göster/gizle
        function checkProfanity(inputElement, warningElement) {
            const value = inputElement.value;
            if (jsBadWordsPattern.test(value)) {
                warningElement.style.display = 'block'; // Uyarıyı göster
                inputElement.style.borderColor = '#fca5a5'; // Kenarlığı kırmızı yap
                return true; // Sakıncalı kelime var
            } else {
                warningElement.style.display = 'none'; // Uyarıyı gizle
                inputElement.style.borderColor = '#ccc'; // Kenarlığı normale döndür (veya focus stili varsa onu koru)
                 // Eğer eleman focus durumundaysa yeşil kenarlığı geri yükle
                 if (document.activeElement === inputElement) {
                     inputElement.style.borderColor = '#15803d'; // Focus rengi
                 }
                 return false; // Sakıncalı kelime yok
            }
        }

        // Input olay dinleyicileri
        nameInput.addEventListener('input', () => checkProfanity(nameInput, nameWarning));
        classInput.addEventListener('input', () => checkProfanity(classInput, classWarning));

        // Input'tan çıkıldığında (blur) da kenarlık rengini düzelt
         nameInput.addEventListener('blur', () => {
             if (!checkProfanity(nameInput, nameWarning)) { // Sakıncalı değilse
                 nameInput.style.borderColor = '#ccc'; // Normal kenarlık
             }
         });
         classInput.addEventListener('blur', () => {
             if (!checkProfanity(classInput, classWarning)) { // Sakıncalı değilse
                 classInput.style.borderColor = '#ccc'; // Normal kenarlık
             }
         });

        function showPage(index) {
            const movingForwardFromFirst = current === 0 && index > 0;
            if (movingForwardFromFirst) {
                // İleri gitmeden önce sakıncalı kelime kontrolü
                const nameHasProfanity = checkProfanity(nameInput, nameWarning);
                const classHasProfanity = checkProfanity(classInput, classWarning);
                if (nameHasProfanity || classHasProfanity) {
                     alert('Lütfen Ad Soyad ve Sınıf alanlarında uygun bir dil kullanın.');
                     return false;
                 }
                 if (nameInput.value.trim() === '' || classInput.value.trim() === '') {
                    alert('Lütfen Ad Soyad ve Sınıf bilgilerinizi giriniz.');
                    current = 0;
                    personalInfo.style.display = 'block';
                    groups.forEach((group, i) => group.classList.toggle('active', i === 0));
                    nextBtn.classList.toggle('hidden', totalGroups === 1 || totalGroups === 0);
                    submitBtn.classList.toggle('hidden', totalGroups !== 1);
                    return false;
                }
            }

            if (index > current && groups[current]) {
                 const currentPageQuestions = groups[current].querySelectorAll('.question');
                 let allAnswered = true;
                 currentPageQuestions.forEach(questionDiv => {
                     const hiddenInput = questionDiv.querySelector('input[type="hidden"]');
                     if (hiddenInput && hiddenInput.value.trim() === '') allAnswered = false;
                 });
                 if (!allAnswered) { alert('Lütfen bu sayfadaki tüm soruları yanıtlayın.'); return false; }
            }

            personalInfo.style.display = index === 0 ? 'block' : 'none';
            groups.forEach((group, i) => group.classList.toggle('active', i === index));
            const isLastPage = index === totalGroups - 1;
            const noPages = totalGroups === 0;
            nextBtn.classList.toggle('hidden', isLastPage || noPages);
            submitBtn.classList.toggle('hidden', !isLastPage || noPages);
            nameInput.readOnly = index > 0;
            classInput.readOnly = index > 0;
            current = index;
            return true;
        }

        function selectAnswer(questionId, button) {
            const optionsDiv = button.closest('.options');
            if (!optionsDiv) return;
            const buttons = optionsDiv.querySelectorAll('.question-button');
            buttons.forEach(btn => btn.classList.remove('active'));
            button.classList.add('active');
            const hiddenInput = document.getElementById(`answer_${questionId}`);
            if (hiddenInput) { hiddenInput.value = button.dataset.value; }
            else { console.error(`Hidden input for question ${questionId} not found.`); }
        }

        nextBtn.addEventListener('click', () => {
            if (current < totalGroups - 1) { showPage(current + 1); }
        });

        form.addEventListener('submit', function(event) {
            // Göndermeden önce son kontroller
            // 1. Sakıncalı kelime kontrolü
            const nameHasProfanity = checkProfanity(nameInput, nameWarning);
            const classHasProfanity = checkProfanity(classInput, classWarning);
            if (nameHasProfanity || classHasProfanity) {
                 alert('Lütfen Ad Soyad ve Sınıf alanlarında uygun bir dil kullanın.');
                 event.preventDefault(); // Gönderimi engelle
                 showPage(0); // İlk sayfaya dön
                 return;
             }

            // 2. Boş alan kontrolü (tekrar)
            if (nameInput.value.trim() === '' || classInput.value.trim() === '') {
                alert('Ad Soyad ve Sınıf bilgileri eksik. Lütfen kontrol edin.');
                event.preventDefault();
                showPage(0);
                return;
            }

            // 3. Son sayfadaki cevap kontrolü
            if (current === totalGroups - 1 && groups[current]) {
                 const lastPageQuestions = groups[current].querySelectorAll('.question');
                 let allAnswered = true;
                 lastPageQuestions.forEach(questionDiv => {
                     const hiddenInput = questionDiv.querySelector('input[type="hidden"]');
                     if (hiddenInput && hiddenInput.value.trim() === '') allAnswered = false;
                 });
                 if (!allAnswered) {
                     alert('Lütfen göndermeden önce bu sayfadaki tüm soruları yanıtlayın.');
                     event.preventDefault();
                     return;
                 }
            }
            // Tüm kontrollerden geçerse form gönderilir
        });

        if (totalGroups > 0) { showPage(0); }
        else { personalInfo.style.display = 'block'; nextBtn.classList.add('hidden'); submitBtn.classList.add('hidden'); }

    </script>
</body>
</html>