<?php
session_start();

header('Content-Type: text/html; charset=utf-8');

// config.php dosyasının doğru yolunu belirleyin.
// Bu dosyanın bulunduğu dizine göre src/config.php'ye erişim.
require_once __DIR__ . '/src/config.php';

$surveyId = 4;
$error = null; // Hata mesajları için

$adminId = null;
// admin_id'nin URL'de olup olmadığını ve geçerli bir integer olup olmadığını kontrol et
if (isset($_GET['admin_id']) && filter_var($_GET['admin_id'], FILTER_VALIDATE_INT) !== false && (int)$_GET['admin_id'] > 0) {
    $potentialAdminId = (int)$_GET['admin_id'];
    try {
        // Veritabanında böyle bir admin var mı diye kontrol et (opsiyonel ama iyi bir pratik)
        $adminStmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
        $adminStmt->execute([$potentialAdminId]);
        $admin = $adminStmt->fetch(PDO::FETCH_ASSOC);

        if ($admin) {
            $adminId = $admin['id'];
        } else {
            // $error = 'Geçersiz veya bulunamayan yönetici ID\'si.'; // Bu hata mesajı yerine aşağıdaki genel mesaj kullanılabilir.
        }
    } catch (PDOException $e) {
        $error = 'Yönetici bilgisi alınırken veritabanı hatası: ' . $e->getMessage();
        error_log("take-survey-4.php Admin ID Check PDOException: " . $e->getMessage());
    }
} 

// Eğer adminId hala null ise (GET'ten alınamadıysa veya DB'de bulunamadıysa) hata ver.
// Bu anketin her zaman bir admin_id ile çağrılması gerektiğini varsayıyoruz.
if (is_null($adminId) && empty($error)) { 
    $error = 'Erişim için geçerli bir yönetici ID\'si (`admin_id`) URL\'de belirtilmelidir.';
}

$survey = null;
$questions = [];
$totalQuestions = 0;

if (empty($error)) { // Sadece önceki adımlarda hata yoksa devam et
    try {
        $stmt = $pdo->prepare("SELECT * FROM surveys WHERE id = ?");
        $stmt->execute([$surveyId]);
        $survey = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$survey) {
            $error = ($error ? $error . "<br>" : "") . "Anket bulunamadı (ID: $surveyId).";
        } else {
            $stmt_q = $pdo->prepare("SELECT * FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC");
            $stmt_q->execute([$surveyId]);
            $questions = $stmt_q->fetchAll(PDO::FETCH_ASSOC);
            $totalQuestions = count($questions);
            if ($totalQuestions === 0 && empty($error)) { // $error kontrolü eklendi
                $error = ($error ? $error . "<br>" : "") . "Bu anket için soru bulunamadı.";
            }
        }
    } catch (PDOException $e) {
        $error = ($error ? $error . "<br>" : "") . "Veritabanı hatası (anket/soru yükleme): " . $e->getMessage();
        error_log("take-survey-4.php Survey/Question Load PDOException: " . $e->getMessage());
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['student_name'] ?? '');
    // Sınıf bilgisi artık student_class_hidden'dan alınacak
    $class = trim($_POST['student_class_hidden'] ?? ''); 
    
    $answers = $_POST['answers'] ?? [];

    if (empty($name)) {
        $error = ($error ? $error . "<br>" : "") . "Lütfen Ad Soyad bilgilerinizi girin.";
    }
    if (empty($class)) { 
        $error = ($error ? $error . "<br>" : "") . "Lütfen Sınıf ve Şube seçiminizi yapın.";
    }
    
    if ($totalQuestions > 0 && count($answers) !== $totalQuestions ) {
         $error = ($error ? $error . "<br>" : "") . "Lütfen tüm soruları yanıtlayın. Yanıtlanan soru sayısı: " . count($answers) . ", Toplam soru sayısı: " . $totalQuestions;
    }
    
    // Form gönderiminde adminId'yi tekrar doğrula (gizli alandan)
    $postedAdminId = isset($_POST['admin_id_hidden']) ? filter_var($_POST['admin_id_hidden'], FILTER_VALIDATE_INT) : null;
    if (is_null($postedAdminId) || $postedAdminId <= 0) {
        // Eğer $adminId GET'ten de alınamadıysa bu bir sorun.
        if(is_null($adminId)) { // $adminId sayfanın başında GET'ten alınmıştı.
             $error = ($error ? $error . "<br>" : "") . "Form gönderiminde yönetici bilgisi eksik veya geçersiz.";
        }
        // Eğer GET'ten $adminId gelmişse, onu kullanmaya devam et. POST'taki eksiklik sorun değil.
    } else {
        $adminId = $postedAdminId; // POST'tan gelen adminId'yi kullan
    }


    if (empty($error) && !is_null($adminId)) { 
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("INSERT INTO survey_participants (name, class, survey_id, admin_id, created_at) VALUES (?, ?, ?, ?, NOW())");
            $participantInsertSuccess = $stmt->execute([$name, $class, $surveyId, $adminId]);
            $participantId = $pdo->lastInsertId();

            // survey_answers.survey_id ve admin_id sütunlarının varlığını kontrol edin. Eğer yoksa, sorgudan kaldırın.
            // Orijinal kodunuzda bu sütunlar yoktu, bu yüzden kaldırıyorum.
            $stmt_answer = $pdo->prepare("INSERT INTO survey_answers (participant_id, question_id, answer_text, created_at) VALUES (?, ?, ?, NOW())");
            $allAnswersInserted = true;
            foreach ($answers as $qid => $answer) {
                if (is_numeric($qid) && is_string($answer)) {
                    // survey_answers.question_id, survey_questions.id'ye karşılık geliyor.
                    if (!$stmt_answer->execute([$participantId, $qid, $answer])) {
                        $allAnswersInserted = false;
                        break; 
                    }
                } else {
                    $allAnswersInserted = false;
                    break;
                }
            }

            if ($participantInsertSuccess && $allAnswersInserted) {
                 $pdo->commit();
                 if (file_exists('tamamlandi.php')) {
                      // tamamlandi.php'ye admin_id ve yeni kaydedilen participantId gönderiliyor.
                      header('Location: tamamlandi.php?survey_id=' . $surveyId . '&admin_id=' . $adminId . '&participant_id=' . $participantId);
                      exit();
                 } else {
                      $error = "Anket başarıyla tamamlandı, ancak 'tamamlandi.php' sayfası bulunamadı.";
                 }
            } else {
                 $pdo->rollBack();
                 $error = ($error ? $error . "<br>" : "") . "Kayıt işlemi tamamlanamadı. Lütfen tüm alanları kontrol edin.";
            }

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Veritabanı hatası oluştu: " . $e->getMessage();
            error_log("take-survey-4.php POST PDOException: " . $e->getMessage());
        } catch (Exception $e) {
             if ($pdo->inTransaction()) $pdo->rollBack();
             $error = "Beklenmeyen bir hata oluştu: " . $e->getMessage();
             error_log("take-survey-4.php POST Exception: " . $e->getMessage());
        }
    } elseif (is_null($adminId) && empty($error)) { 
        $error = ($error ? $error . "<br>" : "") . "Kayıt işlemi için yönetici bilgisi eksik veya geçersiz.";
    }
}

$psikometrikLogoPath = 'assets/Psikometrik.png'; 
$psikometrikLogoExists = file_exists(__DIR__ . '/' . $psikometrikLogoPath);

?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($survey['title'] ?? 'Anket'); ?> - Psikometrik.Net</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            line-height: 1.6;
            background-color: #f0fdf4; 
            color: #1f2937; 
            margin: 0;
            padding: 20px;
        }
        .survey-container {
            max-width: 800px;
            margin: 20px auto; 
            background-color: #ffffff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        .logo-container {
            text-align: center;
            margin-bottom: 1.5rem;
        }
        .logo-img {
            max-height: 60px; 
            width: auto;
            margin-left: auto;
            margin-right: auto;
        }
        .survey-header h1 {
            text-align: center;
            font-size: 1.75rem; 
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #065f46; 
        }
        .survey-header .total-questions-info { 
            text-align: center;
            font-size: 0.9rem;
            color: #059669; 
            margin-bottom: 1.5rem;
            padding: 0.25rem 0.5rem;
            background-color: #ecfdf5;
            border: 1px solid #a7f3d0;
            display: inline-block; /* Ortalamak için div içine alınmıştı, şimdi span ile daha iyi */
            border-radius: 0.25rem;
        }
        .info-section label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #374151; 
        }
        .info-section input[type="text"], .info-section select {
            display: block;
            margin-bottom: 1rem;
            padding: 0.75rem; 
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #d1d5db; 
            border-radius: 6px;
            font-size: 1em;
            color: #1f2937;
            background-color: #f9fafb;
        }
        .info-section input[type="text"]:focus, .info-section select:focus {
            border-color: #10b981; 
            box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.3);
            outline: none;
        }
        .class-selection-container {
            display: flex;
            gap: 1rem; 
            margin-bottom: 1rem;
        }
        .class-selection-container > div {
            flex: 1; 
        }

        .question { margin-bottom: 2rem; }
        .question p.question-text { /* Soru metni için yeni sınıf */
            font-size: 1.1rem; 
            font-weight: 500;
            margin-bottom: 0.75rem;
            color: #111827; 
        }
        .options {
             display: flex;
             flex-wrap: wrap;
             gap: 0.75rem; 
             margin-top: 0.5rem;
        }
        .question-button {
            background: #f0fdf4; 
            border: 2px solid #a7f3d0; 
            color: #065f46; 
            padding: 10px 18px;
            border-radius: 8px;
            transition: all 0.2s ease-in-out;
            text-align: center;
            cursor: pointer;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            font-weight: 500;
        }
        .question-button.active {
            background: #10b981; 
            border-color: #059669; 
            color: white;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            transform: translateY(-1px);
        }
        .question-button:hover:not(.active) {
            background-color: #dcfce7; 
            border-color: #6ee7b7;
        }
        .submit-button-container { 
            text-align: right; 
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #dcfce7; 
        }
        .nav-btn { 
            padding: 0.75rem 1.5rem; 
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.2s ease-in-out;
            cursor: pointer;
            border: none;
            color: white;
        }
        .nav-btn.submit { background: #15803d; } /* Gönder butonu için yeşil */
        .nav-btn.submit:hover { background: #065f46; }
        .hidden { display: none; }
        .error-message {
             color: #991b1b; 
             background-color: #fef2f2; 
             padding: 1rem;
             border-radius: 0.5rem;
             margin-bottom: 1.5rem;
             border: 1px solid #fecaca; 
             font-weight: 500; 
        }
        .footer { text-align: center; margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #dcfce7; color: #047857; font-size: 0.9rem;}

    </style>
</head>
<body class="p-4">
    <div class="survey-container">
        <div class="logo-container">
            <?php if ($psikometrikLogoExists): ?>
                <img src="<?php echo htmlspecialchars($psikometrikLogoPath); ?>" alt="Psikometrik.Net Logosu" class="logo-img">
            <?php endif; ?>
        </div>

        <div class="survey-header">
            <h1><?php echo htmlspecialchars($survey['title'] ?? 'Anket'); ?></h1>
            <?php if ($totalQuestions > 0): ?>
                <div class="text-center"> 
                    <span class="total-questions-info">Toplam Soru: <?php echo $totalQuestions; ?></span>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($error)): ?>
            <div class="error-message" role="alert"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if ($survey && $totalQuestions > 0 && empty($error)): ?>
        <form method="POST" id="surveyForm" action="take-survey-<?php echo $surveyId; ?>.php<?php echo (isset($_GET['admin_id']) && (int)$_GET['admin_id'] > 0) ? '?admin_id='.htmlspecialchars((int)$_GET['admin_id']) : ''; ?>">
            <?php if (isset($_GET['admin_id']) && (int)$_GET['admin_id'] > 0): // admin_id'yi formda gizli olarak taşı ?>
                <input type="hidden" name="admin_id_hidden" value="<?php echo htmlspecialchars((int)$_GET['admin_id']); ?>">
            <?php endif; ?>
            
            <div id="personalInfo" class="info-section">
                <div class="mb-4">
                    <label for="studentName">Ad Soyad:</label>
                    <input type="text" name="student_name" id="studentName" required value="<?php echo htmlspecialchars($_POST['student_name'] ?? ''); ?>">
                </div>
                
                <label for="student_class_number_select">Sınıf:</label> 
                <div class="class-selection-container">
                    <div>
                        <select name="student_class_number_select" id="student_class_number_select" class="form-input" required>
                            <option value="">Seviye</option>
                            <?php for ($i = 1; $i <= 12; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php echo (isset($_POST['student_class_number_select']) && $_POST['student_class_number_select'] == $i) ? 'selected' : ''; ?>><?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <select name="student_class_branch_select" id="student_class_branch_select" class="form-input" required>
                            <option value="">Şube</option>
                            <?php foreach (range('A', 'Z') as $letter): ?>
                                <option value="<?php echo $letter; ?>" <?php echo (isset($_POST['student_class_branch_select']) && $_POST['student_class_branch_select'] == $letter) ? 'selected' : ''; ?>><?php echo $letter; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <input type="hidden" name="student_class_hidden" id="student_class_hidden_input">
            </div>

            <div id="allQuestions">
                <?php foreach ($questions as $q): ?>
                    <div class="mb-8 question">
                        <p class="question-text">
                            <?php // Soru numarası kaldırıldı ?>
                            <?php echo htmlspecialchars($q['question'] ?? $q['question_text'] ?? 'Soru metni bulunamadı'); ?>
                        </p>
                        <div class="options">
                            <?php 
                            $optionsForThisQuestion = ['Evet', 'Hayır']; 
                            foreach ($optionsForThisQuestion as $option): 
                                $isSelected = (isset($_POST['answers'][$q['id']]) && $_POST['answers'][$q['id']] === $option);
                            ?>
                                <button type="button"
                                        class="question-button <?php echo $isSelected ? 'active' : ''; ?>"
                                        onclick="selectAnswer(<?php echo $q['id']; ?>, this)"
                                        data-value="<?php echo htmlspecialchars($option); ?>">
                                    <?php echo htmlspecialchars($option); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="answers[<?php echo $q['id']; ?>]" id="answer_<?php echo $q['id']; ?>" value="<?php echo htmlspecialchars($_POST['answers'][$q['id']] ?? ''); ?>" required>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="submit-button-container">
                <button type="submit" id="submitBtn" class="nav-btn submit">Gönder</button>
            </div>
        </form>
        <?php elseif(!$error): ?>
            <p class="text-center text-red-600">Bu anket şu anda kullanılamıyor veya soru bulunamadı.</p>
        <?php endif; ?>
    </div>

    <footer class="footer">
        &copy; <?php echo date("Y"); ?> Psikometrik.Net Anket Platformu
    </footer>

    <script>
        const nameInput = document.getElementById('studentName');
        const classNumberSelect = document.getElementById('student_class_number_select');
        const classBranchSelect = document.getElementById('student_class_branch_select');
        const classHiddenInput = document.getElementById('student_class_hidden_input');

        function updateHiddenClassInput() {
            if (classNumberSelect && classBranchSelect && classHiddenInput) {
                const numberVal = classNumberSelect.value;
                const branchVal = classBranchSelect.value;
                if (numberVal && branchVal) {
                    classHiddenInput.value = numberVal + branchVal;
                } else if (numberVal && !branchVal) { // Sadece numara seçilmişse
                    classHiddenInput.value = numberVal;
                } else if (!numberVal && branchVal) { // Sadece şube seçilmişse (bu durum pek istenmez ama)
                     classHiddenInput.value = branchVal; 
                }
                else {
                    classHiddenInput.value = '';
                }
            }
        }

        if (classNumberSelect) classNumberSelect.addEventListener('change', updateHiddenClassInput);
        if (classBranchSelect) classBranchSelect.addEventListener('change', updateHiddenClassInput);
        
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', updateHiddenClassInput);
        } else {
            updateHiddenClassInput();
        }

        function selectAnswer(questionId, button) {
            const buttonsInGroup = button.parentElement.querySelectorAll('.question-button');
            buttonsInGroup.forEach(btn => btn.classList.remove('active'));
            button.classList.add('active');
            const hiddenInput = document.getElementById(`answer_${questionId}`);
            if (hiddenInput) {
                hiddenInput.value = button.dataset.value;
            }
        }
        
        document.querySelectorAll('#allQuestions .question-button').forEach(btn => {
             const qidMatch = btn.onclick.toString().match(/selectAnswer\((\d+)/);
             if (qidMatch && qidMatch[1]) {
                 const questionId = qidMatch[1];
                 const hiddenInput = document.getElementById(`answer_${questionId}`);
                 if (hiddenInput && hiddenInput.value === btn.dataset.value) {
                     btn.classList.add('active');
                 }
             }
        });

        const surveyForm = document.getElementById('surveyForm');
        if (surveyForm) {
            surveyForm.addEventListener('submit', function(event) {
                if (nameInput && nameInput.value.trim() === '') { 
                     alert('Lütfen Ad Soyad giriniz.');
                     event.preventDefault();
                     nameInput.focus();
                     return;
                 }
                 if (classNumberSelect && classBranchSelect && (classNumberSelect.value === '' || classBranchSelect.value === '')) {
                     alert('Lütfen Sınıf ve Şube seçiniz.');
                     event.preventDefault();
                     classNumberSelect.focus();
                     return;
                 }
                updateHiddenClassInput(); // Form gönderilmeden hemen önce gizli inputu son bir kez güncelle

                let allAnswered = true;
                document.querySelectorAll('#allQuestions input[type="hidden"][name^="answers"]').forEach(hiddenInput => {
                    if (hiddenInput.value.trim() === '') {
                        allAnswered = false;
                    }
                });

                if (!allAnswered) {
                    alert('Lütfen tüm soruları yanıtlayın.');
                    event.preventDefault();
                }
            });
        }
    </script>
</body>
</html>
