<?php
session_start();
require_once __DIR__ . '/src/config.php';

$surveyId = 9;

// Admin ID çek
$adminId = isset($_GET['admin_id']) ? intval($_GET['admin_id']) : 0;
if (!$adminId) die('Geçersiz bağlantı: Admin ID belirtilmedi.');

// Admin var mı kontrol et
$adminStmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
$adminStmt->execute([$adminId]);
$admin = $adminStmt->fetch(PDO::FETCH_ASSOC);
if (!$admin) die('Admin bulunamadı.');

// Anket bilgisi
$stmt = $pdo->prepare("SELECT * FROM surveys WHERE id = ?");
$stmt->execute([$surveyId]);
$survey = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$survey) die('Anket bulunamadı.');

// Soruları çek
$stmt = $pdo->prepare("SELECT * FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC");
$stmt->execute([$surveyId]);
$questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalQuestions = count($questions);
$totalPages = ceil($totalQuestions / 5);

// POST işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['student_name']);
    $class = trim($_POST['student_class']);
    $answers = $_POST['answers'] ?? [];

    if (!$name || !$class || count($answers) !== $totalQuestions) {
        $error = "Lütfen tüm bilgileri ve soruları doldurun.";
    } else {
        $stmt = $pdo->prepare("INSERT INTO survey_participants (name, class, survey_id, admin_id) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $class, $surveyId, $adminId]);
        $participantId = $pdo->lastInsertId();

        $stmt = $pdo->prepare("INSERT INTO survey_answers (participant_id, question_id, answer_text) VALUES (?, ?, ?)");
        foreach ($answers as $qid => $answer) {
            $stmt->execute([$participantId, $qid, $answer]);
        }
        header('Location: tamamlandi.php');
        exit();
    }
}

$groups = array_chunk($questions, 5, true);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($survey['title']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>body {
    font-family: sans-serif; /* Yazı tipi */
    line-height: 1.6; /* Satır yüksekliği */
    background-color: #f0fdf4; /* Çok açık yeşil arka plan */
    color: #2c3e50; /* Koyu gri metin rengi */
    margin: 0;
    padding: 20px;
    text-align: center; /* İçeriği yatayda ortalamak için */

}


/* Ana içerik konteyneri stili */
.container {
    max-width: 800px; /* Maksimum genişlik */
    margin: 40px auto; /* Yatayda ortala ve dikeyde boşluk bırak */
    /* background: #ffffff; /* Beyaz arka plan kaldırıldı */
    padding: 30px; /* İç boşluk */
    border-radius: 8px; /* Köşe yuvarlaklığı */
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); /* Hafif gölge */
    display: block; /* Blok seviyesi element olduğundan emin ol */
    text-align: left; /* İçindeki metni sola hizala */
    /* İsteğe bağlı: Çok hafif bir arka plan rengi */
    /* background-color: rgba(255, 255, 255, 0.8); */
}

/* Sayfa/Soru grubu stilleri */
.question-group {
    display: none; /* Sayfaları varsayılan olarak gizle */
}
.question-group.active {
    display: block; /* Aktif sayfayı göster */
}

/* Cevap butonu stilleri (Evet, Kısmen, Hayır vb.) */
.question-button {
    background: #f0fdf4; /* Çok açık yeşil arka plan */
    border: 2px solid #bbf7d0; /* Açık yeşil kenarlık */
    color: #15803d; /* Koyu yeşil metin */
    padding: 10px 18px; /* İç boşluk */
    border-radius: 8px; /* Köşe yuvarlaklığı */
    transition: all 0.2s ease-in-out; /* Geçiş efekti */
    /* flex: 1; */ /* Bu özellik kaldırıldı veya ayarlandı (duyarlılık için) */
    /* min-width: 120px; */ /* Bu özellik kaldırıldı veya ayarlandı (duyarlılık için) */
    text-align: center; /* Metni ortala */
    cursor: pointer; /* İmleç tipi */
    box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); /* Hafif gölge */
    display: inline-block; /* Flex konteyner içinde yan yana durması için */
}

/* Seçilmiş (aktif) cevap butonu stili */
.question-button.active {
    background: #22c55e; /* Yeşil arka plan */
    border-color: #16a34a; /* Daha koyu yeşil kenarlık */
    color: white; /* Beyaz metin */
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06); /* Daha belirgin gölge */
    transform: translateY(-2px); /* Hafif yukarı hareket */
}

/* Cevap butonu üzerine gelme (hover) stili */
.question-button:hover:not(.active) {
    background-color: #dcfce7; /* Hafif daha koyu açık yeşil */
    border-color: #a7f3d0; /* Hafif daha koyu yeşil kenarlık */
}


/* Gezinme butonu stilleri (İleri, Gönder) */
.nav-btn {
    padding: 12px 30px; /* İç boşluk */
    border-radius: 8px; /* Köşe yuvarlaklığı */
    font-weight: 600; /* Kalın metin */
    transition: all 0.2s ease-in-out; /* Geçiş efekti */
    cursor: pointer; /* İmleç tipi */
    border: none; /* Varsayılan kenarlığı kaldır */
}

/* İleri butonu stili */
.nav-btn.next {
    background: #15803d; /* Koyu yeşil */
    color: white; /* Beyaz metin */
}

.nav-btn.next:hover {
     background: #0b532c; /* Daha da koyu yeşil */
}

/* Gönder butonu stili */
.nav-btn.submit {
    background: #2563eb; /* Mavi */
    color: white; /* Beyaz metin */
}

 .nav-btn.submit:hover {
     background: #1d4ed8; /* Daha koyu mavi */
}

/* Geri butonu stili (Eğer kullanılacaksa) */
.nav-btn.prev {
    background: #e5e7eb; /* Açık gri */
    color: #374151; /* Koyu gri metin */
}
.nav-btn.prev:hover {
     background: #d1d5db; /* Hafif daha koyu gri */
}


/* Gizleme yardımcı sınıfı */
.hidden {
    display: none;
}

/* Soru bloğu stili */
.question {
    margin-bottom: 30px; /* Alt boşluk */
}

/* Seçenekler konteyneri (yan yana ve duyarlı yerleşim için) */
.options {
     display: flex; /* Flexbox kullan */
     flex-wrap: wrap; /* Küçük ekranlarda alt satıra geç */
     gap: 10px; /* Öğeler arası boşluk */
     margin-top: 10px; /* Üst boşluk */
}
 /* Options içindeki butonların kenar boşluğunu kaldır (gap kullandığımız için) */
 .options .question-button {
     margin: 0;
 }


/* Kişisel bilgi (Ad Soyad, Sınıf) alanı input stilleri */
.info label {
    display: block; /* Etiketleri blok yap */
    margin-bottom: 5px; /* Alt boşluk */
    font-weight: 600; /* Kalın metin */
}
.info input {
    display: block; /* Inputları blok yap (alt alta gelmesi için) */
    margin: 0 0 15px 0; /* Alt boşluk */
    padding: 8px; /* İç boşluk */
    width: 100%; /* Tam genişlik */
    box-sizing: border-box; /* Padding ve border genişliğe dahil */
    border: 1px solid #ccc; /* Gri kenarlık */
    border-radius: 4px; /* Köşe yuvarlaklığı */
    font-size: 1em; /* Yazı boyutu */
    color: #2c3e50; /* Metin rengi */
}

/* Hata mesajı stili */
.error-message {
     color: #b91c1c; /* Kırmızı metin */
     background-color: #fee2e2; /* Açık kırmızı arka plan */
     padding: 1rem; /* İç boşluk */
     border-radius: 0.5rem; /* Köşe yuvarlaklığı */
     margin-bottom: 1.5rem; /* Alt boşluk */
     border: 1px solid #fca5a5; /* Açık kırmızı kenarlık */
     font-weight: bold; /* Kalın metin */
     text-align: center; /* Metni ortala */
}

/* Tailwind benzeri yardımcı sınıflar (HTML'de kullanılmış olabilir) */
/* Bu sınıflar Tailwind yüklüyse zaten çalışır, tutarlılık için buraya eklendi */
.mx-auto { margin-left: auto; margin-right: auto; }
.mt-10 { margin-top: 2.5rem; }
.p-8 { padding: 2rem; }
.rounded-xl { border-radius: 0.75rem; }
.shadow-sm { box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); }
.text-2xl { font-size: 1.5rem; }
.font-bold { font-weight: 700; }
.text-center { text-align: center; }
.mb-8 { margin-bottom: 2rem; }
.pb-4 { padding-bottom: 1rem; }
.border-b-2 { border-bottom-width: 2px; }
.border-[#dcfce7] { border-color: #dcfce7; }
.mb-6 { margin-bottom: 1.5rem; }
.block { display: block; }
.font-semibold { font-weight: 600; }
.mb-2 { margin-bottom: 0.5rem; }
.w-full { width: 100%; }
.p-3 { padding: 0.75rem; }
.border { border-width: 1px; }
.rounded-lg { border-radius: 0.5rem; }
.focus\:outline-none:focus { outline: 0; }
.focus\:ring-2:focus { --tw-ring-offset-shadow: var(--tw-ring-inset) 0 0 0 var(--tw-ring-offset-width) var(--tw-ring-offset-color); --tw-ring-shadow: var(--tw-ring-inset) 0 0 0 calc(2px + var(--tw-ring-offset-width)) var(--tw-ring-color); box-shadow: var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow, 0 0 #0000); }
.focus\:ring-[#bbf7d0]:focus { --tw-ring-color: #bbf7d0; }
.text-lg { font-size: 1.125rem; }
.mb-4 { margin-bottom: 1rem; }
.flex { display: flex; }
.gap-3 { gap: 0.75rem; }
.flex-wrap { flex-wrap: wrap; }
.justify-end { justify-content: flex-end; }
.gap-4 { gap: 1rem; }
.mt-8 { margin-top: 2rem; }
.min-h-screen { min-height: 100vh; }

    </style>
</head>
<body>
<div class="container">
    <h2 class="text-2xl font-bold mb-6"><?= htmlspecialchars($survey['title']) ?></h2>
    <?php if (!empty($error)): ?>
        <div class="bg-red-100 text-red-700 p-4 rounded mb-6"><?= $error ?></div>
    <?php endif; ?>

    <form method="POST" id="surveyForm">
        <div id="personalInfo">
            <label>Ad Soyad:
                <input type="text" name="student_name" required class="w-full p-2 border rounded mb-4">
            </label>
            <label>Sınıf:
                <input type="text" name="student_class" required class="w-full p-2 border rounded mb-6">
            </label>
        </div>

        <div id="questionGroups">
            <?php
            $pageIndex = 0;
            foreach ($groups as $groupIndex => $pageQuestions): ?>
                <div class="question-group <?= $pageIndex === 0 ? 'active' : '' ?>" data-group="<?= $pageIndex ?>">
                    <?php $questionIndexInGroup = 0;
                    foreach ($pageQuestions as $q): ?>
                        <div class="mb-6">
                            <p><strong><?= ($groupIndex * 5) + $questionIndexInGroup + 1 ?>.</strong> <?= htmlspecialchars($q['question']) ?></p>
                            <div class="flex flex-wrap gap-2 mt-2">
                                <?php foreach (['Tamamen Yanlış', 'Katılmıyorum', 'Kısmen Katılıyorum', 'Katılıyorum', 'Tamamen Katılıyorum'] as $option): ?>
                                    <button type="button" class="question-button" onclick="selectAnswer(<?= $q['id'] ?>, this)" data-value="<?= $option ?>"><?= $option ?></button>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="answers[<?= $q['id'] ?>]" id="answer_<?= $q['id'] ?>" required>
                        </div>
                        <?php $questionIndexInGroup++; endforeach; ?>
                </div>
            <?php $pageIndex++; endforeach; ?>
        </div>

        <div class="flex justify-end gap-4 mt-6">
            <button type="button" id="nextBtn" class="nav-btn next">İleri →</button>
            <button type="submit" id="submitBtn" class="nav-btn submit hidden">Gönder</button>
        </div>
    </form>
</div>

<script>
    const groups = document.querySelectorAll('.question-group');
    const nextBtn = document.getElementById('nextBtn');
    const submitBtn = document.getElementById('submitBtn');
    const nameInput = document.querySelector('input[name="student_name"]');
    const classInput = document.querySelector('input[name="student_class"]');
    let current = 0;

    function showPage(index) {
        if (index > 0 && (nameInput.value.trim() === '' || classInput.value.trim() === '')) {
            alert('Lütfen ad ve sınıf giriniz.');
            return;
        }
        groups.forEach((group, i) => group.classList.toggle('active', i === index));
        nameInput.readOnly = index > 0;
        classInput.readOnly = index > 0;
        nextBtn.classList.toggle('hidden', index === groups.length - 1);
        submitBtn.classList.toggle('hidden', index !== groups.length - 1);
        current = index;
    }

    function selectAnswer(questionId, button) {
        const buttons = button.parentElement.querySelectorAll('.question-button');
        buttons.forEach(btn => btn.classList.remove('active'));
        button.classList.add('active');
        document.getElementById(`answer_${questionId}`).value = button.dataset.value;
    }

    nextBtn.addEventListener('click', () => {
        const currentPage = groups[current];
        const allAnswered = [...currentPage.querySelectorAll('input[type="hidden"]')].every(input => input.value);
        if (!allAnswered) {
            alert('Lütfen bu sayfadaki tüm soruları yanıtlayın.');
            return;
        }
        showPage(current + 1);
    });

    document.getElementById('surveyForm').addEventListener('submit', function(event) {
        const lastPage = groups[groups.length - 1];
        const allAnswered = [...lastPage.querySelectorAll('input[type="hidden"]')].every(input => input.value);
        if (!allAnswered) {
            alert('Lütfen bu sayfadaki tüm soruları yanıtlayın.');
            event.preventDefault();
        }
    });

    showPage(0);
</script>
</body>
</html>
