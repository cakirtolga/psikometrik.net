<?php
session_start();

// config.php dosyasını dahil et (veritabanı bağlantısı için)
require_once __DIR__ . '/../src/config.php'; // admin klasöründen bir üst dizindeki src klasörüne

// Hata raporlamayı geliştirme aşamasında etkinleştirin
ini_set('display_errors', 1);
error_reporting(E_ALL);

$survey_id = 56;
$default_page_title = "Rotter İç-Dış Denetim Odağı Ölçeği";
$page_error = null; 

// URL'den parametreleri al
$participant_identifier = isset($_GET['id']) ? trim($_GET['id']) : null; 
$status_from_url = isset($_GET['status']) ? trim($_GET['status']) : null;
$admin_id_viewing = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null; // Sayfayı görüntüleyen admin

// Önizleme modunu belirle
$is_preview_mode = ($status_from_url === 'preview_no_save');

// ADMIN OTURUM KONTROLÜ (Önizleme modu hariç)
if (!$is_preview_mode) { 
    if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super-admin'])) {
         header("Location: ../login.php"); 
         exit;
    }
}

$errors = [];
$total_score = 0; // Rotter ölçeği için toplam puan
$fetched_answers_map = []; // question_number => 'a' veya 'b'
$participant_name_display = "Bilinmiyor";
$participant_class_display = "Bilinmiyor";
$participant_db_admin_id = null; // Katılımcıyı kaydeden adminin ID'si

// Başlık değişkenlerini başlat
$db_survey_title_base = $default_page_title; 
try {
    $stmt_title_check = $pdo->prepare("SELECT title FROM surveys WHERE id = :survey_id");
    $stmt_title_check->bindParam(':survey_id', $survey_id, PDO::PARAM_INT);
    if ($stmt_title_check->execute()) {
        $survey_meta_data_check = $stmt_title_check->fetch(PDO::FETCH_ASSOC);
        if ($survey_meta_data_check && !empty($survey_meta_data_check['title'])) {
            $db_survey_title_base = htmlspecialchars($survey_meta_data_check['title']);
        }
    }
} catch (PDOException $e) {
    error_log("admin/view-result-{$survey_id}.php (Initial Title Check) PDOException: " . $e->getMessage());
}

if ($is_preview_mode) {
    $header_title = $db_survey_title_base . " - Sonuç Önizleme";
    $page_main_title = $db_survey_title_base . " - Sonuç Önizleme";
    $survey_info_title = $db_survey_title_base . " Sonuç Önizleme";
} else {
    $header_title = $db_survey_title_base . " Sonuçları";
    $page_main_title = $db_survey_title_base . " Katılımcı Sonuçları";
    $survey_info_title = $db_survey_title_base . " Katılımcı Sonuçları";
}

$institutionWebURL = null; 
$psikometrikLogoPath = '../assets/Psikometrik.png'; 
$psikometrikLogoExists = file_exists(__DIR__ . '/' . $psikometrikLogoPath);

if (empty($participant_identifier)) {
    $page_error = "Geçerli bir Katılımcı ID'si (`id` parametresi) URL'de belirtilmedi.";
} elseif (!$is_preview_mode && (!filter_var($participant_identifier, FILTER_VALIDATE_INT) || (int)$participant_identifier <= 0)) {
    $page_error = "Geçerli bir Katılımcı Veritabanı ID'si (`id` parametresi) URL'de belirtilmedi veya formatı yanlış.";
} elseif ($is_preview_mode && !preg_match('/^[a-zA-Z0-9_.-]+$/', $participant_identifier)) {
    $page_error = "Önizleme için geçersiz Katılımcı ID formatı.";
}

// Rotter Ölçeği Puanlama Anahtarı ve Dolgu Maddeleri
$rotter_scoring_key = [
    // Soru No => Dışsal Denetimi Gösteren Seçenek (1 puan alacak)
    2  => 'a', 3  => 'b', 4  => 'b', 5  => 'b', 6  => 'a',
    7  => 'a', 9  => 'a', 10 => 'b', 11 => 'b', 12 => 'b',
    13 => 'b', 15 => 'b', 16 => 'a', 17 => 'a', 18 => 'a',
    20 => 'a', 21 => 'a', 22 => 'b', 23 => 'a', 25 => 'a',
    26 => 'b', 28 => 'b', 29 => 'a'
];
$rotter_filler_items = [1, 8, 14, 19, 24, 27];


if (!$page_error) { 
    if ($is_preview_mode) {
        $session_answers_key = 'temp_survey_answers_' . $survey_id . '_' . $participant_identifier;
        if (isset($_SESSION[$session_answers_key]) && is_array($_SESSION[$session_answers_key])) {
            $fetched_answers_map = $_SESSION[$session_answers_key];
            unset($_SESSION[$session_answers_key]); 
             if(empty($fetched_answers_map)){
                $errors[] = "Önizleme için cevap bulunamadı. Anket yeniden doldurulmalı.";
            }
        } else {
            $errors[] = "Önizleme için cevap bulunamadı veya süresi dolmuş. Lütfen anketi tekrar doldurun.";
        }
        $participant_name_display = "Misafir Katılımcı"; 
        $participant_class_display = "N/A";
    } else { // Kayıtlı Sonuç Modu
        try {
            $participant_db_id = (int)$participant_identifier; 
            $stmt_participant = $pdo->prepare("SELECT name, class, admin_id FROM survey_participants WHERE id = :participant_db_id AND survey_id = :survey_id");
            $stmt_participant->execute([':participant_db_id' => $participant_db_id, ':survey_id' => $survey_id]);
            $participant_data = $stmt_participant->fetch(PDO::FETCH_ASSOC);

            if ($participant_data) {
                $participant_name_display = htmlspecialchars($participant_data['name']);
                $participant_class_display = htmlspecialchars($participant_data['class']);
                $participant_db_admin_id = $participant_data['admin_id']; 
            } else {
                $errors[] = "Katılımcı bilgileri bulunamadı (Veritabanı ID: {$participant_db_id}).";
                if(empty($page_error)) $page_error = $errors[0]; 
            }

            if (empty($errors)) { 
                $stmt_answers = $pdo->prepare("SELECT question_id, answer_text FROM survey_answers WHERE survey_id = :survey_id AND participant_id = :participant_db_id");
                $stmt_answers->execute([':survey_id' => $survey_id, ':participant_db_id' => $participant_db_id]);
                $db_answers = $stmt_answers->fetchAll(PDO::FETCH_ASSOC);

                if (empty($db_answers)) {
                    $errors[] = "Bu katılımcı için anket cevabı bulunamadı (Katılımcı Veritabanı ID: {$participant_db_id}).";
                } else {
                    foreach ($db_answers as $answer_row) {
                        // question_id, survey_questions.question_number'a karşılık geliyor
                        $fetched_answers_map[(int)$answer_row['question_id']] = $answer_row['answer_text']; // 'a' veya 'b'
                    }
                }
            }
        } catch (PDOException $e) {
            $errors[] = "Sonuçlar alınırken bir veritabanı hatası oluştu.";
            error_log("admin/view-result-{$survey_id}.php PDOException (DB mode): " . $e->getMessage());
            if(empty($page_error)) $page_error = $errors[0];
        }
    }

    // Puanları hesapla
    if (!empty($fetched_answers_map) && empty($errors) && empty($page_error)) {
        foreach ($fetched_answers_map as $question_number => $selected_option) {
            if (!in_array($question_number, $rotter_filler_items)) { // Dolgu maddesi değilse puanla
                if (isset($rotter_scoring_key[$question_number])) {
                    if (strtolower($selected_option) === $rotter_scoring_key[$question_number]) {
                        $total_score += 1;
                    }
                }
            }
        }
    } elseif (empty($errors) && empty($page_error)) { 
         if (empty($fetched_answers_map) && !$is_preview_mode && !$page_error) {
         } elseif (empty($fetched_answers_map) && $is_preview_mode && !$page_error) {
         } else if (!$page_error) { 
            $errors[] = "Hesaplanacak cevap verisi bulunamadı veya bir sorun oluştu.";
         }
    }
}


// Kurum logosunu çekme mantığı
if (!$is_preview_mode && $participant_db_admin_id) {
    try {
        $stmt_logo = $pdo->prepare("SELECT institution_logo_path FROM users WHERE id = :admin_id");
        $stmt_logo->bindParam(':admin_id', $participant_db_admin_id, PDO::PARAM_INT);
        $stmt_logo->execute();
        $logo_data = $stmt_logo->fetch(PDO::FETCH_ASSOC);
        if ($logo_data && !empty($logo_data['institution_logo_path'])) {
            $rawInstitutionPathFromDB = $logo_data['institution_logo_path'];
            $cleanRelativePath = ltrim(str_replace(['../', '..'], '', $rawInstitutionPathFromDB), '/'); 
            
            if (preg_match('/^(http:\/\/|https:\/\/|\/\/)/i', $cleanRelativePath)) {
                $institutionWebURL = $cleanRelativePath; 
            } else {
                $potentialWebPath = '/' . $cleanRelativePath;
                $potentialServerPath = $_SERVER['DOCUMENT_ROOT'] . $potentialWebPath;
                if (file_exists($potentialServerPath)) {
                    $institutionWebURL = $potentialWebPath;
                } else {
                    $alternativePath = '../' . $cleanRelativePath; 
                    if(file_exists(__DIR__ . '/' . $alternativePath)){
                        $institutionWebURL = $alternativePath;
                    } else {
                        error_log("Kurum logosu dosyası bulunamadı: " . $potentialServerPath . " veya " . __DIR__ . '/' . $alternativePath . " (Admin ID: " . $participant_db_admin_id . ")");
                    }
                }
            }
        } else {
             error_log("Kurum logosu yolu users tablosunda bulunamadı. Admin ID: " . $participant_db_admin_id);
        }
    } catch (PDOException $e) {
        error_log("Kurum logosu çekilirken hata: " . $e->getMessage());
    }
}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $survey_info_title; ?> - Psikometrik.net<?php if(!$is_preview_mode) echo " Yönetim"; ?></title>
    <meta name="robots" content="noindex, nofollow">

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f9fafb; color: #111827; margin:0; padding:0; }
        .page-header { background-color: #ffffff; padding: 10px 25px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e0e0e0; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .page-header .logo-left img, .page-header .logo-right img { max-height: 50px; width: auto; }
        .page-header .logo-left, .page-header .logo-right { flex: 1; display: flex; align-items: center; }
        .page-header .logo-left { justify-content: flex-start; }
        .page-header .logo-right { justify-content: flex-end; }
        .page-header .page-title-main { flex: 2; text-align: center; font-size: 1.6rem; color: #1f2937; margin: 0; font-weight: 600;}
        
        .result-container { max-width: 900px; margin: 20px auto; background-color: #ffffff; padding: 2.5rem; border-radius: 0.75rem; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04); }
        .content-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .main-content-title-h1 { color: #1f2937; font-size: 1.75rem; font-weight:700; flex-grow: 1; }
        .participant-details { text-align: center; font-size: 0.9rem; color: #374151; margin-bottom: 1.5rem; background-color: #e0e7ff; padding: 0.75rem; border-radius: 0.375rem; border: 1px solid #c7d2fe;}
        .section-title { font-size: 1.625rem; font-weight: 600; color: #1f2937; margin-top: 2.5rem; margin-bottom: 1.25rem; padding-bottom: 0.75rem; border-bottom: 3px solid #d1d5db;}
        .score-display { font-size: 1.375rem; font-weight: 500; color: #1e3a8a; }
        .interpretation-text { background-color: #eff6ff; border-left: 5px solid #3b82f6; color: #1e40af; padding: 1.25rem; margin-top: 0.75rem; margin-bottom: 2rem; border-radius: 0.375rem; font-size: 1rem; line-height: 1.7;}
        .alert-info { background-color: #eff6ff; border-color: #93c5fd; color: #1d4ed8; }
        .alert-warning { background-color: #fefce8; border-color: #fde047; color: #a16207; }
        .alert-danger { background-color: #fef2f2; border-color: #fca5a5; color: #b91c1c; }
        .alert { padding: 1.25rem; border-left-width: 5px; border-radius: 0.375rem; margin-bottom: 2rem; }
        .footer-text { color: #6b7280; }
        .button-container { display: flex; justify-content: center; gap: 1rem; margin-top: 2.5rem; padding-top: 1.5rem; border-top: 1px solid #e0e0e0; }
        .action-button { display: inline-flex; align-items: center; padding: 10px 20px; font-size: 1rem; font-weight: 600; color: white; background-color: #3b82f6; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; transition: background-color 0.2s ease, box-shadow 0.2s ease; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .action-button:hover { background-color: #2563eb; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .action-button.print-btn { background-color: #10b981; } 
        .action-button.print-btn:hover { background-color: #059669; }
        .action-button.panel-btn { background-color: #6b7280; } 
        .action-button.panel-btn:hover { background-color: #4b5563; }

        @media print {
            @page { margin: 15mm !important; size: A4; }
            html, body { margin: 10mm !important; padding: 0 !important; height: auto !important; min-height: initial !important; background-color: #fff !important; color: #000 !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; font-size: 10pt !important; }
            .page-header { display: flex !important; padding: 5mm 0 !important; border-bottom: 1px solid #000 !important; box-shadow: none !important; margin-top: 0 !important; margin-bottom: 5mm !important; width: 100% !important; position: static !important; page-break-after: avoid !important; visibility: visible !important; }
            .page-header .logo-left img, .page-header .logo-right img { max-height: 35px !important; width: auto !important; }
            .page-header .logo-left { justify-content: flex-start !important; }
            .page-header .logo-right { justify-content: flex-end !important; }
            .page-header .page-title-main { font-size: 12pt !important; color: #000 !important; font-weight: bold !important; padding: 2px 0 !important; }
            .result-container { margin: 0 !important; padding: 0 !important; box-shadow: none !important; border-radius: 0; border: none; max-width: 100% !important; width: 100% !important;}
            .content-header { display: none !important; } 
            .main-content-title-h1.print-only { display: block !important; text-align: center !important; font-size: 14pt !important; font-weight: bold !important; margin-top: 0mm !important; margin-bottom: 5mm !important; padding-bottom: 2mm !important; border-bottom: 1px solid #ccc !important; color: #000 !important; page-break-after: avoid !important; }
            .participant-details { background-color: #f0f0f0 !important; border: 1px solid #ccc !important; padding: 3mm !important; margin-bottom: 5mm !important; page-break-inside: avoid !important; font-size: 9pt !important; }
            h2.section-title { font-size: 11pt !important; color: #000 !important; border-bottom: 1px dashed #ccc !important; padding-bottom: 2px !important; margin-top: 5mm !important; margin-bottom: 3mm !important; }
            .interpretation-text { border-left-color: #ccc !important; background-color: #f9f9f9 !important; color: #333 !important; font-size: 9pt !important;}
            .no-print { display: none !important; } 
        }
    </style>
</head>
<body class="bg-gray-100">

    <div class="page-header"> 
         <div class="logo-left">
            <?php if($institutionWebURL): ?>
                <img src="<?php echo htmlspecialchars($institutionWebURL); ?>" alt="Kurum Logosu">
            <?php elseif($is_preview_mode && !$institutionWebURL): ?>
                <span>&nbsp;</span> 
            <?php else: ?><span>&nbsp;</span><?php endif; ?>
        </div>
        <div class="page-title-main">
            <?php echo $header_title; ?>
        </div>
        <div class="logo-right">
            <?php if ($psikometrikLogoExists): ?>
                <img src="<?php echo htmlspecialchars($psikometrikLogoPath); ?>" alt="Psikometrik.Net Logosu">
            <?php else: ?>
                <span>Psikometrik.Net</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="result-container">
        <div class="content-header no-print"> 
            <h1 class="main-content-title-h1"></h1>
            <?php if (!$is_preview_mode): ?>
            <a href="dashboard.php" class="action-button panel-btn">
                <i class="fas fa-arrow-left mr-2"></i>Kontrol Paneline Dön
            </a>
            <?php endif; ?>
        </div>
         <h1 class="main-content-title-h1 print-only" style="display:none;"></h1>


        <div class="participant-details">
             Anket No: <?php echo $survey_id; ?> | 
            <?php if ($is_preview_mode): ?>
                Katılımcı: Sonuç Önizleme (Geçici ID: <?php echo htmlspecialchars($participant_identifier); ?>)
            <?php else: ?>
                Katılımcı: <strong><?php echo $participant_name_display; ?></strong> (Sınıf/Grup: <?php echo $participant_class_display; ?>) | Veritabanı ID: <?php echo htmlspecialchars($participant_identifier); ?>
            <?php endif; ?>
            <?php if ($participant_db_admin_id && !$is_preview_mode): ?>
                | Kaydeden Admin ID: <?php echo $participant_db_admin_id; ?>
            <?php endif; ?>
        </div>

        <div class="text-right mb-4 no-print">
            <button onclick="window.print();" class="action-button print-btn">
                 <i class="fas fa-print mr-2"></i>Sayfayı Yazdır
            </button>
        </div>

        <?php 
        // Sadece adminin normal sonuç görüntülemesi sırasında (eğer varsa) session mesajları gösterilir.
        // Misafir kullanıcı için take-survey'den gelen bilgilendirme mesajları GÖSTERİLMEYECEK.
        if (!$is_preview_mode && isset($_SESSION['survey_message'])): 
            $message_type_class = 'alert-info'; 
            if (isset($_SESSION['survey_message_type'])) {
                if ($_SESSION['survey_message_type'] == 'success') $message_type_class = 'alert-info';
                elseif ($_SESSION['survey_message_type'] == 'warning') $message_type_class = 'alert-warning';
                elseif ($_SESSION['survey_message_type'] == 'error') $message_type_class = 'alert-danger';
            }
        ?>
            <div class="alert <?php echo $message_type_class; ?> no-print" role="alert"> <?php echo htmlspecialchars($_SESSION['survey_message']); ?>
            </div>
        <?php 
            unset($_SESSION['survey_message'], $_SESSION['survey_message_type']); 
        endif; 
        ?>

        <?php if ($page_error): ?>
            <div class="alert alert-danger" role="alert">
                <p class="font-bold">Hata!</p>
                <p><?php echo htmlspecialchars($page_error); ?></p>
            </div>
        <?php elseif (!empty($errors)): ?>
            <div class="alert alert-danger" role="alert">
                <p class="font-bold mb-2">Sonuçlar görüntülenirken bir sorun oluştu:</p>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php elseif (empty($fetched_answers_map) && !$is_preview_mode): ?>
             <div class="alert alert-warning" role="alert">
                <p class="font-bold">Bilgi</p>
                <p>Bu katılımcı (Veritabanı ID: <?php echo htmlspecialchars($participant_identifier); ?>) için anket sonuçları bulunamadı.</p>
            </div>
        <?php elseif (empty($fetched_answers_map) && $is_preview_mode): ?>
             <div class="alert alert-warning" role="alert">
                <p class="font-bold">Bilgi</p>
                <p>Önizleme için katılımcı cevapları bulunamadı (Geçici ID: <?php echo htmlspecialchars($participant_identifier); ?>). Lütfen anketi tekrar doldurun.</p>
            </div>
        <?php else: ?>
            <section>
                <h2 class="section-title">Rotter İç-Dış Denetim Odağı Puanı</h2>
                <p class="score-display">Toplam Puanınız: <strong><?php echo $total_score; ?></strong> (0-23 arası)</p>
                <div class="interpretation-text">
                    <?php
                    // Yorumlama: Yüksek puanlar dış denetim odağını gösterir.
                    if ($total_score <= 8) {
                        echo "Puanınız, <strong>iç denetim odağına</strong> sahip olduğunuzu göstermektedir. Bu, genellikle olayların kendi kontrolünüzde olduğuna, başarı ve başarısızlıklarınızın kendi çaba ve yeteneklerinizle ilişkili olduğuna inandığınız anlamına gelir. Kendi kararlarınıza güvenir ve sorumluluk almaktan çekinmezsiniz.";
                    } elseif ($total_score <= 15) {
                        echo "Puanınız, denetim odağı açısından <strong>dengeli</strong> bir yapıda olduğunuzu veya duruma göre hem içsel hem de dışsal faktörlerin etkili olabileceğine inandığınızı gösterebilir. Bazı durumlarda kontrolün sizde olduğunu hissederken, bazı durumlarda dış etkenlerin veya şansın rolünü kabul edebilirsiniz.";
                    } else { // 16-23 puan
                        echo "Puanınız, <strong>dış denetim odağına</strong> sahip olduğunuzu göstermektedir. Bu, genellikle olayların sizin kontrolünüz dışında geliştiğine, şans, kader veya güçlü dışsal faktörler tarafından belirlendiğine inanma eğiliminde olduğunuz anlamına gelir. Başarı veya başarısızlıkları daha çok dış etkenlere bağlayabilirsiniz.";
                    }
                    ?>
                     <p class="mt-3 text-sm"><em>Bu yorum genel bir değerlendirmedir ve bireysel farklılıklar gösterebilir. Detaylı bir analiz için bir uzmana danışmanız önerilir.</em></p>
                </div>
            </section>
            
            <?php if (!$is_preview_mode): // Sadece kayıtlı sonuçlarda ve admin arayüzünde alttaki butonları göster ?>
            <div class="button-container mt-8 pt-6 border-t border-gray-300 no-print">
                 <a href="dashboard.php" class="action-button panel-btn">
                    <i class="fas fa-tachometer-alt mr-2"></i>Kontrol Paneline Dön
                 </a>
                 <button onclick="window.print();" class="action-button print-btn">
                    <i class="fas fa-print mr-2"></i>Sayfayı Yazdır
                 </button>
            </div>
            <?php elseif($is_preview_mode && !empty($fetched_answers_map) && empty($page_error) && empty($errors)): // Önizleme modunda sadece yazdırma butonu ?>
             <div class="button-container mt-8 pt-6 border-t border-gray-300 no-print">
                 <button onclick="window.print();" class="action-button print-btn">
                    <i class="fas fa-print mr-2"></i>Sayfayı Yazdır
                 </button>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <footer class="text-center mt-10 py-6 border-t border-gray-300 footer-text">
            &copy; <?php echo date("Y"); ?> Psikometrik.Net - Tüm hakları saklıdır.
        </footer>
    </div>
</body>
</html>
