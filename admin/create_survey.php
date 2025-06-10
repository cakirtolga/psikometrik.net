<?php
session_start();
require_once '../src/config.php'; // Veritabanı bağlantısı

// Hata raporlamayı geliştirme aşamasında etkinleştirin
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Giriş ve rol kontrolü
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super-admin'])) {
    header('Location: ../login.php');
    exit();
}

$loggedInUserId = $_SESSION['user_id'];
$form_error_message = null;
$form_success_message = null;

// Sabit soru tipleri ve seçenekleri
$question_types = [
    'multiple_choice_radio' => 'Çoktan Seçmeli (Tek Cevap)',
    'text_short' => 'Kısa Metin Cevap',
    'text_long' => 'Uzun Metin Cevap',
    'evet_hayir' => 'Evet/Hayır',
    'likert_5_point' => "5'li Likert Ölçeği"
];

// Ön tanımlı seçenekler (JSON formatında)
$predefined_options = [
    'evet_hayir' => json_encode(["Evet", "Hayır"]),
    'likert_5_point' => json_encode([
        "Kesinlikle Katılmıyorum",
        "Katılmıyorum",
        "Kararsızım",
        "Katılıyorum",
        "Kesinlikle Katılıyorum"
    ])
];


if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Form verilerini güvenli bir şekilde al
    $survey_title = trim(filter_input(INPUT_POST, 'survey_title', FILTER_SANITIZE_STRING));
    $survey_description = trim(filter_input(INPUT_POST, 'survey_description', FILTER_SANITIZE_STRING));
    $questions_data = $_POST['questions'] ?? []; // Sorular bir dizi olarak gelecek

    // Temel doğrulamalar
    if (empty($survey_title)) {
        $form_error_message = "Anket başlığı boş bırakılamaz.";
    } elseif (empty($questions_data)) {
        $form_error_message = "En az bir soru eklemelisiniz.";
    } else {
        // Soruların geçerliliğini kontrol et
        $valid_questions = true;
        foreach ($questions_data as $index => $q_data) {
            if (empty(trim($q_data['text']))) {
                $form_error_message = "Soru metinleri boş bırakılamaz (Soru ".($index+1).")."; // Hata mesajında soru sırasını göster
                $valid_questions = false;
                break;
            }
            if (!isset($question_types[$q_data['type']])) {
                $form_error_message = "Geçersiz soru tipi seçildi (Soru ".($index+1).").";
                $valid_questions = false;
                break;
            }
            // Çoktan seçmeli ise seçenekleri kontrol et
            if (($q_data['type'] === 'multiple_choice_radio') && (empty($q_data['options']) || !is_array($q_data['options']) || count(array_filter(array_map('trim', $q_data['options']))) < 2)) {
                $form_error_message = "Çoktan seçmeli sorular için en az 2 geçerli seçenek girilmelidir (Soru ".($index+1).").";
                $valid_questions = false;
                break;
            }
        }

        if ($valid_questions && !$form_error_message) {
            try {
                $pdo->beginTransaction();

                // 1. Anketi 'surveys' tablosuna ekle
                $stmt_survey = $pdo->prepare(
                    "INSERT INTO surveys (title, description, created_by, created_at, status, creation_method) 
                     VALUES (:title, :description, :created_by, NOW(), :status, :creation_method)"
                );
                $status = 'active'; // Varsayılan durum
                $creation_method_value = 'dynamic'; // Dinamik oluşturulduğunu belirt

                $stmt_survey->bindParam(':title', $survey_title, PDO::PARAM_STR);
                $stmt_survey->bindParam(':description', $survey_description, PDO::PARAM_STR);
                $stmt_survey->bindParam(':created_by', $loggedInUserId, PDO::PARAM_INT);
                $stmt_survey->bindParam(':status', $status, PDO::PARAM_STR);
                $stmt_survey->bindParam(':creation_method', $creation_method_value, PDO::PARAM_STR); 
                
                if (!$stmt_survey->execute()) {
                    // SQL hatasını logla veya daha detaylı bir mesaj göster
                    throw new PDOException("Anket kaydı başarısız: " . implode(";", $stmt_survey->errorInfo()));
                }
                $new_survey_id = $pdo->lastInsertId();

                if (!$new_survey_id) {
                    throw new Exception("Yeni anket ID'si alınamadı.");
                }

                // 2. Soruları 'survey_questions' tablosuna ekle
                $stmt_question = $pdo->prepare(
                    "INSERT INTO survey_questions (survey_id, question_text, question_type, options, question, answer_type, sort_order, question_number)
                     VALUES (:survey_id, :question_text, :question_type, :options, :question, :answer_type, :sort_order, :question_number)"
                );

                foreach ($questions_data as $sort_order_key => $q_data) {
                    $question_text = trim($q_data['text']);
                    $question_type = $q_data['type'];
                    $options_json = null; // Varsayılan olarak null

                    if ($question_type === 'multiple_choice_radio') {
                        // Sadece dolu olan seçenekleri al ve JSON formatına çevir
                        $filtered_options = [];
                        if (!empty($q_data['options']) && is_array($q_data['options'])) {
                             $filtered_options = array_values(array_filter(array_map('trim', $q_data['options']))); // Boş seçenekleri filtrele
                        }
                        if (count($filtered_options) >= 2) { // En az 2 seçenek olmalı
                            $options_json = json_encode($filtered_options);
                        } else {
                             // Bu durum yukarıda zaten kontrol edilmiş olmalı ama çift kontrol
                             throw new Exception("Soru ".($sort_order_key+1)." için en az 2 geçerli seçenek gereklidir.");
                        }
                    } elseif (isset($predefined_options[$question_type])) {
                        // 'evet_hayir' veya 'likert_5_point' gibi tipler için ön tanımlı seçenekleri kullan
                        $options_json = $predefined_options[$question_type];
                    }
                    
                    // `question` ve `answer_type` sütunları için değerler (eski yapıyla uyumluluk için)
                    $question_col_value = $question_text; // `question` sütununa soru metnini yaz
                    $answer_type_col_value = $question_type; // `answer_type` sütununa soru tipini yaz
                    $current_sort_order = $sort_order_key + 1; // Sıra numarası (1'den başlar)

                    $stmt_question->bindParam(':survey_id', $new_survey_id, PDO::PARAM_INT);
                    $stmt_question->bindParam(':question_text', $question_text, PDO::PARAM_STR);
                    $stmt_question->bindParam(':question_type', $question_type, PDO::PARAM_STR);
                    $stmt_question->bindParam(':options', $options_json, PDO::PARAM_STR); // NULL olabilir
                    $stmt_question->bindParam(':question', $question_col_value, PDO::PARAM_STR);
                    $stmt_question->bindParam(':answer_type', $answer_type_col_value, PDO::PARAM_STR);
                    $stmt_question->bindParam(':sort_order', $current_sort_order, PDO::PARAM_INT); 
                    $stmt_question->bindParam(':question_number', $current_sort_order, PDO::PARAM_INT); // question_number da eklendi

                    if (!$stmt_question->execute()) {
                        throw new PDOException("Soru kaydı başarısız (Soru ".($current_sort_order)."): " . implode(";", $stmt_question->errorInfo()));
                    }
                }

                $pdo->commit();
                $form_success_message = "Anket başarıyla oluşturuldu! Anket ID: " . $new_survey_id;
                // Başarılı gönderim sonrası formu temizlemek için POST verilerini sıfırla
                $_POST = array(); 

            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $form_error_message = "Anket oluşturulurken bir veritabanı hatası oluştu: " . $e->getMessage();
                error_log("create_survey.php PDOException: " . $e->getMessage());
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $form_error_message = "Anket oluşturulurken bir hata oluştu: " . $e->getMessage();
                error_log("create_survey.php Exception: " . $e->getMessage());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yeni Anket Oluştur | Anket Platformu</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f0fdf4; /* Açık yeşil arka plan */
        }
        /* Tailwind CSS ile form elemanları için temel stil */
        .form-input, .form-textarea, .form-select {
            @apply mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-0 focus:ring-green-500 focus:border-green-500 sm:text-sm;
        }
        /* Genel buton stili */
        .btn {
            @apply inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white focus:outline-none focus:ring-2 focus:ring-offset-2;
        }
        .btn-primary { @apply bg-green-600 hover:bg-green-700 focus:ring-green-500; }
        .btn-secondary { @apply bg-blue-500 hover:bg-blue-600 focus:ring-blue-500; }
        .btn-info { @apply bg-sky-500 hover:bg-sky-600 focus:ring-sky-500; }
        .btn-success-alt { @apply bg-emerald-500 hover:bg-emerald-600 focus:ring-emerald-400; }
        .btn-danger { @apply bg-red-500 hover:bg-red-600 focus:ring-red-500; }
        
        /* Soru blokları için stil */
        .question-block {
            @apply p-6 border border-gray-200 rounded-lg mb-6 bg-white shadow; /* Hafif gölge ve arkaplan */
        }
        .question-header {
            @apply flex justify-between items-center mb-3 pb-3 border-b border-gray-200;
        }
        .question-title {
            @apply text-lg font-semibold text-gray-700;
        }
        /* Seçenek konteyneri için stil */
        .options-container {
            @apply mt-4 pl-4 border-l-2 border-gray-200; /* Sol kenarlık ve iç boşluk */
        }
        .options-title {
            @apply text-sm font-semibold text-gray-600 mb-2 block;
        }
        .option-input-group {
            @apply flex items-center mb-2;
        }
        .option-input-group input[type="text"] {
            @apply flex-grow form-input py-1.5; /* Daha kompakt seçenek giriş alanı */
        }
        .option-input-group button {
            @apply ml-2 btn btn-danger p-1.5 leading-none; /* Küçük sil butonu */
        }
        /* Navigasyon logosu */
        .nav-logo {
            height: 2rem; /* Logo boyutu */
        }
        /* JSON içe aktarma bölümü */
        .json-import-section {
            @apply bg-white shadow-lg rounded-lg p-6 mb-8;
        }
    </style>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="text-gray-800">
    <nav class="bg-white shadow-md">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <div class="flex items-center">
                    <a href="dashboard.php" class="flex items-center text-xl font-bold text-green-600">
                        <img src="../assets/Psikometrik.png" alt="Psikometrik.Net Logo" class="nav-logo mr-2">
                        </a>
                </div>
                <div class="flex items-center">
                    <span class="text-gray-600 mr-4 text-sm">Hoş geldiniz, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Kullanıcı'); ?>!</span>
                    <a href="dashboard.php" class="text-sm text-gray-600 hover:text-green-600 mr-4 px-3 py-2 rounded-md hover:bg-gray-100">Dashboard</a>
                    <a href="../logout.php" class="btn btn-danger btn-sm py-1.5 px-3"><i class="fas fa-sign-out-alt mr-1"></i>Çıkış Yap</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container mx-auto px-4 py-8 max-w-5xl"> <h1 class="text-3xl font-bold text-gray-700 mb-8 text-center">Yeni Anket Oluştur</h1>

        <?php if (isset($form_error_message) && $form_error_message): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-md shadow" role="alert">
                <strong class="font-bold">Hata!</strong>
                <span class="block sm:inline"><?php echo htmlspecialchars($form_error_message); ?></span>
            </div>
        <?php endif; ?>
        <?php if (isset($form_success_message) && $form_success_message): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-md shadow" role="alert">
                <strong class="font-bold">Başarılı!</strong>
                <span class="block sm:inline"><?php echo htmlspecialchars($form_success_message); ?></span>
            </div>
        <?php endif; ?>

        <form action="create_survey.php" method="POST" id="createSurveyForm" class="space-y-8">
            <div class="bg-white shadow-lg rounded-lg p-6">
                <h2 class="text-xl font-semibold text-gray-700 mb-4 pb-2 border-b border-gray-200">Anket Bilgileri</h2>
                <div class="mb-4">
                    <label for="survey_title" class="block text-sm font-medium text-gray-700">Anket Başlığı <span class="text-red-500">*</span></label>
                    <input type="text" name="survey_title" id="survey_title" class="form-input" required value="<?php echo htmlspecialchars($_POST['survey_title'] ?? ''); ?>">
                </div>
                <div class="mb-4">
                    <label for="survey_description" class="block text-sm font-medium text-gray-700">Anket Açıklaması (Opsiyonel)</label>
                    <textarea name="survey_description" id="survey_description" rows="3" class="form-textarea"><?php echo htmlspecialchars($_POST['survey_description'] ?? ''); ?></textarea>
                </div>
            </div>

            <div class="json-import-section">
                <h2 class="text-xl font-semibold text-gray-700 mb-4 pb-2 border-b border-gray-200">Soruları JSON ile İçe Aktar</h2>
                <div class="mb-4">
                    <label for="json_input" class="block text-sm font-medium text-gray-700">JSON Verisi:</label>
                    <textarea id="json_input" rows="15" class="form-textarea" placeholder='<?php echo htmlspecialchars("{\n  \"sorular\": [\n    {\n      \"soru\": \"İlk soru metni?\",\n      \"secenekler\": {\"A\": \"Evet\", \"B\": \"Hayır\"}\n    },\n    {\n      \"soru\": \"İkinci soru metni?\",\n      \"secenekler\": {\"A\": \"Evet\", \"B\": \"Hayır\"}\n    }\n    // ... daha fazla soru\n  ]\n}"); ?>'></textarea>
                    <p class="mt-1 text-xs text-gray-500">Yukarıdaki formatta sorularınızı yapıştırın. Her soru için "soru" (soru metni) ve "secenekler" altında "A" ve "B" anahtarları (Evet/Hayır veya diğer seçenekler için) olmalıdır.</p>
                </div>
                <button type="button" id="importJsonBtn" class="btn btn-info">
                    <i class="fas fa-upload mr-2"></i> Soruları JSON'dan İçe Aktar
                </button>
            </div>

            <div class="bg-white shadow-lg rounded-lg p-6">
                <div class="flex justify-between items-center mb-6 pb-3 border-b border-gray-200">
                    <h2 class="text-xl font-semibold text-gray-700">Sorular</h2>
                    <button type="button" id="addQuestionBtn" class="btn btn-secondary">
                        <i class="fas fa-plus mr-2"></i> Soru Ekle
                    </button>
                </div>
                <div id="questionsContainer" class="space-y-6">
                    <?php
                    // Form gönderimi sonrası hata durumunda soruları yeniden yüklemek için.
                    // Bu kısım, PHP'den gelen veriyi JavaScript'e aktararak soruları yeniden oluşturur.
                    // Bu, sayfa yenilendiğinde kullanıcının girdiği verilerin kaybolmamasını sağlar.
                    if (isset($_POST['questions']) && is_array($_POST['questions'])) {
                        // Bu veriyi JS'ye aktarıp orada işlemek daha temiz bir yaklaşım olabilir.
                        // Şimdilik, bu blok JS'nin başlangıçta boş başlamasına veya
                        // sayfa ilk yüklendiğinde bir soru eklemesine izin veriyor.
                    }
                    ?>
                </div>
            </div>

            <div class="mt-8 flex justify-end">
                <button type="submit" class="btn btn-primary text-lg px-8 py-3">
                    <i class="fas fa-save mr-2"></i> Anketi Kaydet
                </button>
            </div>
        </form>
    </div>

    <footer class="bg-white border-t border-gray-200 mt-12 py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center text-gray-500 text-sm">
            &copy; <?php echo date('Y'); ?> Psikometrik.Net Anket Platformu. Tüm hakları saklıdır.
        </div>
    </footer>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const questionsContainer = document.getElementById('questionsContainer');
    const addQuestionBtn = document.getElementById('addQuestionBtn');
    const importJsonBtn = document.getElementById('importJsonBtn');
    const jsonInput = document.getElementById('json_input');
    let questionCounter = 0; // Bu sayaç, her soru için benzersiz ID'ler ve name attributeları oluşturmak için kullanılır.

    // PHP'den gelen soru tiplerini ve ön tanımlı seçenekleri JS'ye aktar
    const questionTypes = <?php echo isset($question_types) ? json_encode($question_types) : '{}'; ?>;
    const predefinedOptions = <?php echo isset($predefined_options) ? json_encode($predefined_options) : '{}'; ?>;

    // HTML karakterlerinden kaçış fonksiyonu
    function escapeHTML(str) {
        if (typeof str !== 'string') return ''; // Girdi string değilse boş döndür
        return str.replace(/[&<>"']/g, function (match) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;' // Tek tırnak için HTML entity
            }[match];
        });
    }

    // Yeni bir soru elementi oluşturan fonksiyon
    function createQuestionElement(uniqueQuestionId, initialData = {}) {
        const questionDiv = document.createElement('div');
        questionDiv.classList.add('question-block');
        questionDiv.dataset.questionId = uniqueQuestionId; // Her soruya benzersiz bir ID ata

        // Soru başlığı (Soru X) updateQuestionNumbers fonksiyonu tarafından ayarlanacak
        questionDiv.innerHTML = `
            <div class="question-header">
                <h3 class="question-title">Soru</h3>
                <button type="button" class="removeQuestionBtn btn btn-danger btn-sm py-1 px-2">
                    <i class="fas fa-trash-alt mr-1"></i> Sil
                </button>
            </div>
            <div class="mb-3">
                <label for="question_text_${uniqueQuestionId}" class="block text-sm font-medium text-gray-700">Soru Metni <span class="text-red-500">*</span></label>
                <textarea name="questions[${uniqueQuestionId}][text]" id="question_text_${uniqueQuestionId}" rows="3" class="form-textarea" required>${escapeHTML(initialData.text || '')}</textarea>
            </div>
            <div class="mb-3">
                <label for="question_type_${uniqueQuestionId}" class="block text-sm font-medium text-gray-700">Soru Tipi <span class="text-red-500">*</span></label>
                <select name="questions[${uniqueQuestionId}][type]" id="question_type_${uniqueQuestionId}" class="form-select question-type-select">
                    ${Object.entries(questionTypes).map(([value, text]) => `<option value="${value}" ${initialData.type === value ? 'selected' : ''}>${escapeHTML(text)}</option>`).join('')}
                </select>
            </div>
            <div id="optionsContainer_${uniqueQuestionId}" class="options-container ${initialData.type === 'multiple_choice_radio' ? '' : 'hidden'}">
                <span class="options-title">Seçenekler (Çoktan Seçmeli)</span>
                <div id="option_fields_${uniqueQuestionId}" class="space-y-2">
                    </div>
                <button type="button" class="addOptionBtn btn btn-success-alt btn-sm py-1 px-2 mt-2">
                    <i class="fas fa-plus mr-1"></i> Seçenek Ekle
                </button>
            </div>
        `;

        // Soru silme butonu olayı
        questionDiv.querySelector('.removeQuestionBtn').addEventListener('click', function () {
            questionDiv.remove();
            updateQuestionNumbers(); // Sorular silindiğinde numaraları güncelle
        });

        const typeSelect = questionDiv.querySelector('.question-type-select');
        const optionsContainerElement = questionDiv.querySelector(`#optionsContainer_${uniqueQuestionId}`);
        const optionFieldsDiv = questionDiv.querySelector(`#option_fields_${uniqueQuestionId}`);
        const addOptionButton = questionDiv.querySelector('.addOptionBtn');

        // Soru tipi değiştiğinde seçenek alanını göster/gizle
        typeSelect.addEventListener('change', function () {
            if (this.value === 'multiple_choice_radio') {
                optionsContainerElement.classList.remove('hidden');
                // Eğer hiç seçenek yoksa, varsayılan olarak 2 tane ekle
                if (optionFieldsDiv.children.length === 0) {
                    addOptionElement(optionFieldsDiv, uniqueQuestionId);
                    addOptionElement(optionFieldsDiv, uniqueQuestionId);
                }
            } else {
                optionsContainerElement.classList.add('hidden');
                optionFieldsDiv.innerHTML = ''; // Diğer soru tipleri için seçenekleri temizle
            }
        });

        // Seçenek ekleme butonu olayı
        addOptionButton.addEventListener('click', function() {
            addOptionElement(optionFieldsDiv, uniqueQuestionId);
        });

        // Başlangıç verisi varsa seçenekleri doldur
        if (initialData.type === 'multiple_choice_radio' && initialData.options && Array.isArray(initialData.options)) {
            optionFieldsDiv.innerHTML = ''; // Önce mevcutları temizle
            initialData.options.forEach(optText => {
                addOptionElement(optionFieldsDiv, uniqueQuestionId, optText);
            });
            // Çoktan seçmeli için en az 2 seçenek olmasını sağla
            while(optionFieldsDiv.children.length < 2){
                addOptionElement(optionFieldsDiv, uniqueQuestionId);
            }
        } else if (initialData.type && predefinedOptions[initialData.type]) {
            // Bu kısım, JSON formatında seçenekler direkt geliyorsa genellikle kullanılmaz.
            // Eğer JSON'dan 'evet_hayir' tipi gelirse ve seçenekleri predefinedOptions'dan almak isterseniz burası düzenlenebilir.
            // Mevcut JSON formatımızda seçenekler (A:"Evet", B:"Hayır") zaten geliyor.
        } else {
            // Başlangıçta 'multiple_choice_radio' seçili değilse veya seçenek yoksa,
            // ve en az iki seçenek eklenmemişse, change eventini tetikle.
             if (typeSelect.value === 'multiple_choice_radio' && optionFieldsDiv.children.length < 2) {
                addOptionElement(optionFieldsDiv, uniqueQuestionId);
                addOptionElement(optionFieldsDiv, uniqueQuestionId);
            }
            // Seçenekleri göstermek/gizlemek için 'change' olayını tetikle
            typeSelect.dispatchEvent(new Event('change'));
        }
        return questionDiv;
    }

    // Çoktan seçmeli sorular için seçenek elementi ekleyen fonksiyon
    function addOptionElement(container, questionId, optionText = '') {
        const optionIndex = container.children.length;
        const optionDiv = document.createElement('div');
        optionDiv.classList.add('option-input-group');
        optionDiv.innerHTML = `
            <input type="text" name="questions[${questionId}][options][]" class="form-input" placeholder="Seçenek ${optionIndex + 1}" value="${escapeHTML(optionText)}" required>
            <button type="button" class="removeOptionBtn btn btn-danger p-1.5 leading-none">
                <i class="fas fa-times"></i>
            </button>
        `;
        // Seçenek silme butonu olayı
        optionDiv.querySelector('.removeOptionBtn').addEventListener('click', function() {
            // Çoktan seçmeli tipinde en az 2 seçenek kalmalı
            const currentTypeSelect = container.closest('.question-block').querySelector('.question-type-select');
            if (container.children.length > 2 || currentTypeSelect.value !== 'multiple_choice_radio') {
                 optionDiv.remove();
            } else {
                alert("Çoktan seçmeli sorular için en az 2 seçenek kalmalıdır.");
            }
        });
        container.appendChild(optionDiv);
        return optionDiv;
    }

    // Soruların görsel sıra numaralarını güncelleyen fonksiyon
    function updateQuestionNumbers() {
        const questionBlocks = questionsContainer.querySelectorAll('.question-block');
        questionBlocks.forEach((block, idx) => {
            block.querySelector('.question-title').textContent = `Soru ${idx + 1}`;
        });
    }

    // "Soru Ekle" butonuna tıklandığında yeni bir soru oluştur
    addQuestionBtn.addEventListener('click', function () {
        const newQuestion = createQuestionElement(questionCounter); // Benzersiz ID ile oluştur
        questionsContainer.appendChild(newQuestion);
        questionCounter++; // Benzersiz ID sayacını artır
        updateQuestionNumbers(); // Her soru eklendiğinde numaraları güncelle
    });

    // JSON'dan soruları içe aktarma butonu olayı
    importJsonBtn.addEventListener('click', function() {
        const jsonString = jsonInput.value.trim();
        if (!jsonString) {
            alert("Lütfen içe aktarmak için JSON verisini girin.");
            return;
        }
        try {
            const parsedJson = JSON.parse(jsonString);
            if (parsedJson.sorular && Array.isArray(parsedJson.sorular)) {

                // JSON'dan içe aktarma öncesi mevcut soruları temizle
                questionsContainer.innerHTML = '';
                questionCounter = 0; // Soru ID'leri için sayacı sıfırla

                parsedJson.sorular.forEach(item => {
                    // Beklenen JSON formatı: {"soru": "Soru metni?", "secenekler": {"A": "Evet", "B": "Hayır"}}
                    // veya {"soru": "Soru metni?", "secenekler": {"A": "Seçenek A", "B": "Seçenek B", "C": "Seçenek C"}}
                    if (item.soru && typeof item.soru === 'string' && item.secenekler && typeof item.secenekler === 'object') {

                        let questionTypeForImport = 'multiple_choice_radio'; // Varsayılan çoktan seçmeli
                        let optionsForImport = [];

                        // Seçenekleri diziye çevir
                        if (item.secenekler.A !== undefined && item.secenekler.B !== undefined) {
                             optionsForImport.push(item.secenekler.A);
                             optionsForImport.push(item.secenekler.B);
                             if (item.secenekler.C !== undefined) optionsForImport.push(item.secenekler.C);
                             if (item.secenekler.D !== undefined) optionsForImport.push(item.secenekler.D);
                             if (item.secenekler.E !== undefined) optionsForImport.push(item.secenekler.E);
                             // ... daha fazla seçenek eklenebilir
                        }


                        // Eğer seçenekler sadece "Evet" ve "Hayır" ise tipi 'evet_hayir' yap
                        if (optionsForImport.length === 2 &&
                            optionsForImport[0].toLowerCase() === 'evet' &&
                            optionsForImport[1].toLowerCase() === 'hayır') {
                            questionTypeForImport = 'evet_hayir';
                        }


                        const initialData = {
                            text: item.soru, // JSON'daki 'soru' alanı soru metnidir
                            type: questionTypeForImport,
                            options: optionsForImport // Seçenekleri dizi olarak aktar
                        };
                        const newQuestionElement = createQuestionElement(questionCounter, initialData);
                        questionsContainer.appendChild(newQuestionElement);
                        questionCounter++;
                    } else {
                        console.warn("JSON'da eksik veya hatalı formatta soru bulundu:", item);
                    }
                });
                updateQuestionNumbers(); // Tüm sorular eklendikten sonra numaraları güncelle
                jsonInput.value = ''; // JSON giriş alanını temizle
                alert(`${questionsContainer.children.length} soru başarıyla içe aktarıldı ve forma eklendi.`);
            } else {
                alert("Geçersiz JSON formatı. 'sorular' adında bir dizi içermelidir ve her soru 'soru' metni ile 'secenekler: {A:..., B:...}' yapısında olmalıdır.");
            }
        } catch (e) {
            alert("JSON ayrıştırma hatası: " + e.message);
            console.error("JSON Parse Error:", e);
        }
    });

    // Sayfa ilk yüklendiğinde veya form gönderimi sonrası hata varsa (PHP ile veri aktarımı)
    <?php
    if (isset($_POST['questions']) && is_array($_POST['questions'])) {
        // Hatalı form gönderimi sonrası soruları yeniden yükle
        $posted_questions_json = json_encode(array_values($_POST['questions'])); // Diziyi yeniden indeksle
        echo "const postedQuestionsData = " . $posted_questions_json . ";\n";
        echo "if (postedQuestionsData && Array.isArray(postedQuestionsData)) {\n";
        echo "    postedQuestionsData.forEach((qData, index) => {\n";
        echo "        const uniqueId = questionCounter++;\n"; // Her soru için yeni bir uniqueId oluştur
        echo "        const initialData = {\n";
        echo "            text: qData.text || '',\n";
        echo "            type: qData.type || 'multiple_choice_radio',\n";
        // Seçenekler bir dizi değilse boş bir diziye ayarla
        echo "            options: Array.isArray(qData.options) ? qData.options : []\n";
        echo "        };\n";
        echo "        const newQuestion = createQuestionElement(uniqueId, initialData);\n";
        echo "        questionsContainer.appendChild(newQuestion);\n";
        echo "    });\n";
        echo "    updateQuestionNumbers();\n";
        echo "}\n";
    }
    // Sayfa ilk yüklendiğinde ve POST yoksa, varsayılan olarak boş bırak, kullanıcı eklesin veya import etsin.
    // Eğer varsayılan bir soru eklenmesi isteniyorsa:
    // else if (empty($_POST['questions']) && $_SERVER["REQUEST_METHOD"] !== "POST") {
    //     addQuestionBtn.click();
    // }
    ?>
});
</script>
</body>
</html>
