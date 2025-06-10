<?php
session_start();
require_once '../src/config.php';

// Hata raporlamayı etkinleştir
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Giriş kontrolü
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super-admin'])) {
    header('Location: ../login.php');
    exit();
}

if (!isset($_SESSION['user_email'])) {
    $_SESSION['user_email'] = 'kullanici@domain.com'; 
}
if (!isset($_SESSION['username'])) {
    $_SESSION['username'] = 'Kullanıcı Adı'; 
}

$loggedInUserId = $_SESSION['user_id']; 
$loggedInUserRole = $_SESSION['role'];   
$adminEmail = $_SESSION['user_email']; 

$error = null; 
$success_message = null; 

// --- Rapor parametreleri kontrolü ---
if (isset($_GET['survey_id']) || isset($_GET['participant_ids'])) {
    if (basename($_SERVER['PHP_SELF']) !== 'view_report.php') { 
        error_log("UYARI: dashboard.php sayfası, rapor parametreleri ile yüklendi. URL: " . $_SERVER['REQUEST_URI']);
    }
}

// --- Super Admin İşlemleri (POST) ---
if ($loggedInUserRole === 'super-admin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Kullanıcı Yönetimi İşlemleri
        if ($_POST['action'] === 'update_user_details') {
            $userIdToUpdate = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
            if (!$userIdToUpdate) {
                $error = "Geçersiz kullanıcı ID'si.";
            } else {
                $newRole = filter_input(INPUT_POST, 'role', FILTER_SANITIZE_STRING);
                $newDurationDays = filter_input(INPUT_POST, 'duration_days', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                $newLicenseStartDateStr = filter_input(INPUT_POST, 'license_start_date', FILTER_SANITIZE_STRING);
                
                $updateFields = [];
                $updateParams = [':id' => $userIdToUpdate];

                if (isset($_POST['role'])) { 
                    if ($newRole && in_array($newRole, ['admin', 'super-admin'])) {
                        $updateFields[] = "role = :role";
                        $updateParams[':role'] = $newRole;
                    } else {
                         $error = (isset($error) ? $error." " : "") . "Geçersiz rol seçimi.";
                    }
                }

                if (isset($_POST['duration_days'])) {
                    if ($_POST['duration_days'] === '' && ($newDurationDays === null || $newDurationDays === false)) { 
                         $updateFields[] = "duration_days = NULL";
                    } elseif ($newDurationDays !== false && $newDurationDays !== null) { 
                        $updateFields[] = "duration_days = :duration_days";
                        $updateParams[':duration_days'] = $newDurationDays;
                    } elseif ($_POST['duration_days'] !== '') { 
                        $error = (isset($error) ? $error." " : "") . "Geçersiz süre (gün) değeri. Lütfen sayı girin veya boş bırakın.";
                    }
                }
                
                if (isset($_POST['license_start_date'])) {
                    if (!empty($newLicenseStartDateStr)) {
                        try {
                            $dt = new DateTime($newLicenseStartDateStr);
                            $newLicenseStartDate = $dt->format('Y-m-d');
                            $updateFields[] = "license_start_date = :license_start_date";
                            $updateParams[':license_start_date'] = $newLicenseStartDate;
                        } catch (Exception $e) {
                            $error = (isset($error) ? $error." " : "") . "Geçersiz lisans başlangıç tarihi formatı. YYYY-MM-DD kullanın.";
                        }
                    } elseif ($newLicenseStartDateStr === '') { 
                        $updateFields[] = "license_start_date = NULL";
                    }
                }

                if (empty($error) && !empty($updateFields)) {
                    try {
                        $sql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = :id";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($updateParams);
                        $success_message = "Kullanıcı ID: {$userIdToUpdate} için bilgiler başarıyla güncellendi.";
                    } catch (PDOException $e) {
                        $error = "Kullanıcı güncellenirken veritabanı hatası oluştu: " . $e->getMessage();
                        error_log("Kullanıcı güncelleme hatası (dashboard.php): " . $e->getMessage() . " - UserID: " . $userIdToUpdate);
                    }
                } elseif (empty($updateFields) && empty($error)) {
                    // $error = "Güncellenecek bir bilgi seçilmedi veya girilmedi."; 
                }
            }
        }
        // Anket Silme İşlemi
        elseif ($_POST['action'] === 'delete_survey') {
            $surveyIdToDelete = filter_input(INPUT_POST, 'survey_id_to_delete', FILTER_VALIDATE_INT);
            if (!$surveyIdToDelete) {
                $error = "Geçersiz anket ID'si.";
            } else {
                try {
                    $pdo->beginTransaction();
                    // İlişkili verileri silme (ÖNEMLİ: Foreign key constraint'leri ON DELETE CASCADE değilse manuel silme gerekir)
                    $stmt_delete_answers = $pdo->prepare("DELETE FROM survey_answers WHERE survey_id = :survey_id");
                    $stmt_delete_answers->execute([':survey_id' => $surveyIdToDelete]);
                    
                    $stmt_delete_questions = $pdo->prepare("DELETE FROM survey_questions WHERE survey_id = :survey_id");
                    $stmt_delete_questions->execute([':survey_id' => $surveyIdToDelete]);
                    
                    $stmt_delete_participants = $pdo->prepare("DELETE FROM survey_participants WHERE survey_id = :survey_id");
                    $stmt_delete_participants->execute([':survey_id' => $surveyIdToDelete]);

                    $stmt_delete_survey = $pdo->prepare("DELETE FROM surveys WHERE id = :survey_id");
                    $stmt_delete_survey->execute([':survey_id' => $surveyIdToDelete]);

                    $pdo->commit();
                    $success_message = "Anket (ID: {$surveyIdToDelete}) ve ilişkili tüm veriler başarıyla silindi.";
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $error = "Anket silinirken bir veritabanı hatası oluştu: " . $e->getMessage();
                    error_log("Anket silme hatası (dashboard.php): " . $e->getMessage() . " - SurveyID: " . $surveyIdToDelete);
                }
            }
        }
        // Anket Bilgisi Güncelleme İşlemi
        elseif ($_POST['action'] === 'update_survey_info') {
            $surveyIdToUpdate = filter_input(INPUT_POST, 'survey_id_to_update', FILTER_VALIDATE_INT);
            $newSurveyTitle = trim(filter_input(INPUT_POST, 'new_survey_title', FILTER_SANITIZE_STRING));
            $newSurveyDescription = trim(filter_input(INPUT_POST, 'new_survey_description', FILTER_SANITIZE_STRING));
            $newSurveyMoneyStatus = filter_input(INPUT_POST, 'new_survey_money_status', FILTER_SANITIZE_STRING); 

            if (!$surveyIdToUpdate) {
                $error = (isset($error) ? $error." " : "") . "Güncellenecek anket için geçersiz ID.";
            } elseif (empty($newSurveyTitle)) {
                $error = (isset($error) ? $error." " : "") . "Anket başlığı boş bırakılamaz.";
            } elseif (isset($_POST['new_survey_money_status']) && !in_array($newSurveyMoneyStatus, ['paid', 'free', ''])) { 
                 $error = (isset($error) ? $error." " : "") . "Geçersiz ücret durumu seçimi.";
            }
            
            if (empty($error)) { 
                try {
                    $updateFieldsSurvey = ["title = :title", "description = :description"];
                    $updateParamsSurvey = [
                        ':title' => $newSurveyTitle,
                        ':description' => $newSurveyDescription,
                        ':id' => $surveyIdToUpdate
                    ];

                    if (isset($_POST['new_survey_money_status'])) { 
                        if ($newSurveyMoneyStatus === 'paid' || $newSurveyMoneyStatus === 'free') {
                            $updateFieldsSurvey[] = "money = :money_status"; 
                            $updateParamsSurvey[':money_status'] = $newSurveyMoneyStatus;
                        } elseif ($newSurveyMoneyStatus === '') { 
                            $updateFieldsSurvey[] = "money = NULL"; 
                        }
                    }

                    $sql_update_survey = "UPDATE surveys SET " . implode(', ', $updateFieldsSurvey) . " WHERE id = :id";
                    $stmt_update_survey = $pdo->prepare($sql_update_survey);
                    $stmt_update_survey->execute($updateParamsSurvey);
                    $success_message = "Anket (ID: {$surveyIdToUpdate}) bilgileri başarıyla güncellendi.";
                } catch (PDOException $e) {
                    $error = "Anket bilgileri güncellenirken bir veritabanı hatası oluştu: " . $e->getMessage();
                    error_log("Anket bilgi güncelleme hatası (dashboard.php): " . $e->getMessage() . " - SurveyID: " . $surveyIdToUpdate);
                }
            }
        }
    }
}


// --- Admin için Kalan Gün Bilgisi ---
$adminDurationInfo = null;
if ($loggedInUserRole === 'admin') {
    try {
        $stmt = $pdo->prepare("SELECT duration_days, license_start_date FROM users WHERE id = :id");
        $stmt->execute([':id' => $loggedInUserId]);
        $adminUserData = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($adminUserData) {
            if (array_key_exists('duration_days', $adminUserData) && $adminUserData['duration_days'] !== null) { 
                $durationDays = (int)$adminUserData['duration_days'];
                if ($durationDays > 0 && !empty($adminUserData['license_start_date'])) {
                    try {
                        $licenseStartDate = new DateTime($adminUserData['license_start_date']);
                        $today = new DateTime();
                        $expiryDate = (clone $licenseStartDate)->add(new DateInterval("P{$durationDays}D"));
                        if ($today >= $expiryDate) { $adminDurationInfo = "Lisans süreniz doldu."; } 
                        else { $diff = $today->diff($expiryDate); $adminDurationInfo = "Kalan Gün: " . $diff->days; }
                    } catch (Exception $e) { $adminDurationInfo = "Süre hesaplanamadı."; error_log("Admin süre hesaplama DateTime hatası (dashboard.php): " . $e->getMessage() . " - AdminID: " . $loggedInUserId); }
                } elseif ($durationDays === 0) { $adminDurationInfo = "Süre: Limitsiz"; }
                else { $adminDurationInfo = "Süre bilgisi ayarlanmamış."; } 
            } else { $adminDurationInfo = "Süre bilgisi tanımsız."; } 
        }
    } catch (PDOException $e) { $adminDurationInfo = "Süre bilgisi alınamadı."; error_log("Admin süre bilgisi PDOException (dashboard.php): " . $e->getMessage() . " - AdminID: " . $loggedInUserId); }
}


// --- Super Admin için Kullanıcı Listesi ---
$allSystemUsers = [];
if ($loggedInUserRole === 'super-admin') {
    try {
        $stmt_users = $pdo->query("SELECT id, username, email, role, duration_days, license_start_date FROM users ORDER BY id ASC");
        $allSystemUsers = $stmt_users->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = ($error ? $error." " : "") . "Kullanıcılar listelenirken bir veritabanı hatası oluştu: " . $e->getMessage();
    }
}


// Tüm anketleri ve soru sayılarını çek
$allSurveys = [];
try {
    $allSurveysStmt = $pdo->query("
        SELECT s.id, s.title, s.description, s.creation_method, s.created_at, s.money, 
               (SELECT COUNT(sq.id) FROM survey_questions sq WHERE sq.survey_id = s.id) as question_count
        FROM surveys s 
        ORDER BY s.id DESC
    ");
    $allSurveys = $allSurveysStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = ($error ? $error." " : "") . "Anketler yüklenirken bir veritabanı hatası oluştu: " . $e->getMessage();
    $allSurveys = []; 
}

// Katılımlar sekmesi için filtreleme parametrelerini al
$filter_class = isset($_GET['filter_class']) ? trim(filter_input(INPUT_GET, 'filter_class', FILTER_SANITIZE_STRING)) : '';
$filter_survey_id = isset($_GET['filter_survey_id']) ? filter_input(INPUT_GET, 'filter_survey_id', FILTER_VALIDATE_INT) : 0;


// Tüm katılımcıları çek (Filtrelemeyi dahil et)
$allParticipants = [];
$allParticipantsSql = "
    SELECT sp.id, sp.name, sp.class, sp.survey_id, s.title AS survey_title, s.creation_method, sp.admin_id AS participant_admin_id, sp.created_at as participation_date
    FROM survey_participants sp
    JOIN surveys s ON sp.survey_id = s.id";
$allParticipantsParams = [];
$whereClauses = [];

if ($loggedInUserRole !== 'super-admin') {
    $whereClauses[] = "sp.admin_id = :loggedInAdminId";
    $allParticipantsParams[':loggedInAdminId'] = $loggedInUserId;
}

if (!empty($filter_class)) {
    $whereClauses[] = "sp.class LIKE :filter_class";
    $allParticipantsParams[':filter_class'] = '%' . $filter_class . '%';
}
if ($filter_survey_id > 0) {
    $whereClauses[] = "sp.survey_id = :filter_survey_id";
    $allParticipantsParams[':filter_survey_id'] = $filter_survey_id;
}

if (!empty($whereClauses)) {
    $allParticipantsSql .= " WHERE " . implode(" AND ", $whereClauses);
}

$allParticipantsSql .= " ORDER BY sp.created_at DESC, sp.id DESC"; 

try {
     $allParticipantsStmt = $pdo->prepare($allParticipantsSql);
     $allParticipantsStmt->execute($allParticipantsParams);
     $allParticipants = $allParticipantsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = ($error ? $error." " : "") . "Katılımcılar yüklenirken bir veritabanı hatası oluştu: " . $e->getMessage();
    $allParticipants = []; 
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | Psikometrik.Net</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; line-height: 1.6; background-color: #f0fdf4; /* Yeşil tema arka plan */ color: #1f2937; }
        nav { background-color: #ffffff; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05); padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: flex-end; }
        .logo-area { display: flex; align-items: center; gap: 0.75rem; }
        .logo-area img { height: 4rem; /* Biraz küçültüldü */ vertical-align: middle; }
        .btn { padding: 0.6rem 1.25rem; border-radius: 0.375rem; color: white; font-weight: 500; transition: background-color 0.2s ease-in-out; display: inline-flex; align-items: center; justify-content: center; text-align: center; text-decoration: none; }
        
        /* Yeşil Tema Butonları */
        .btn-primary { background-color: #10b981; } .btn-primary:hover { background-color: #059669; }
        .btn-secondary { background-color: #34d399; } .btn-secondary:hover { background-color: #10b981; }
        .btn-success { background-color: #22c55e; } .btn-success:hover { background-color: #16a34a; }
        .btn-danger { background-color: #ef4444; } .btn-danger:hover { background-color: #dc2626; }
        .btn-warning { background-color: #f59e0b; } .btn-warning:hover { background-color: #d97706; } 
        .btn-info { background-color: #0ea5e9; } .btn-info:hover { background-color: #0284c7; }
        .btn-apply { background-color: #10b981; /* Linki göster ile aynı */ } .btn-apply:hover { background-color: #059669; }


        table { width: 100%; border-collapse: collapse; margin-top: 1.5rem; }
        th, td { text-align: left; padding: 0.75rem 1rem; border-bottom: 1px solid #dcfce7; /* Açık yeşil sınır */ vertical-align: middle; }
        th { background-color: #ecfdf5; /* Çok açık yeşil */ font-weight: 600; color: #065f46; /* Koyu yeşil */ }
        tbody tr:nth-child(even) { background-color: #f0fdf4; /* Açık yeşil */ }
        tbody tr:hover { background-color: #dcfce7; /* Biraz daha koyu yeşil hover */ }
        
        .survey-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); /* Kart genişliği artırıldı */ gap: 1.5rem; }
        .survey-card { 
            background-color: #ffffff; 
            padding: 1.5rem; 
            border-radius: 0.5rem; 
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08); 
            transition: box-shadow 0.3s ease-in-out; 
            border: 1px solid #a7f3d0; /* Yeşil sınır */
            display: flex; 
            flex-direction: column; 
            justify-content: space-between; 
        }
        .survey-card:hover { box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1); }
        .survey-card h2 { font-size: 1.25rem; font-weight: 600; margin-bottom: 0.5rem; /* Soru sayısı için yer açıldı */ color: #065f46; /* Koyu yeşil başlık */ }
        .survey-card .survey-question-count {
            font-size: 0.8rem;
            font-weight: 500;
            color: #059669; /* Ana yeşil */
            background-color: #ecfdf5; /* Çok açık yeşil */
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            display: inline-block;
            margin-bottom: 0.75rem;
            border: 1px solid #a7f3d0;
        }
        .survey-card p.survey-description { color: #374151; /* Daha koyu gri metin */ margin-bottom: 1.5rem; line-height: 1.5; flex-grow: 1; font-size: 0.9rem; }
        .survey-details { font-size: 0.9rem; color: #4b5563; /* Orta gri */ margin-top: 1rem; margin-bottom: 1.5rem; border-top: 1px solid #dcfce7; padding-top: 1rem; }
        .survey-details p { margin-bottom: 0.5rem; }

        .popup-overlay { background-color: rgba(0, 0, 0, 0.7); }
        #popup > div { max-width: 500px; width: 90%; margin-left: auto; margin-right: auto; }
        #shareLink { background-color: #f1f5f9; cursor: text; color: #334155; }
        
        .tab-buttons { display: flex; margin-bottom: 1.5rem; border-bottom: 2px solid #a7f3d0; /* Yeşil sınır */ }
        .tab-button { padding: 0.75rem 1.5rem; cursor: pointer; border: none; background-color: transparent; font-size: 1rem; font-weight: 500; color: #4b5563; /* Orta gri */ transition: color 0.2s ease-in-out, border-bottom-color 0.2s ease-in-out; border-bottom: 2px solid transparent; }
        .tab-button:hover { color: #065f46; /* Koyu yeşil */ }
        .tab-button.active { color: #10b981; /* Ana yeşil */ border-bottom-color: #10b981; font-weight: 600; }
        .tab-content { display: none; padding-top: 1rem; }
        .tab-content.active { display: block; }
        
        .form-input, .form-textarea, .form-select { @apply mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-green-500 focus:border-green-500 sm:text-sm; } 
        .editable-textarea { min-height: 60px; resize: vertical; } 
        .loading-indicator { display: none; text-align: center; margin-top: 1rem; color: #10b981; /* Ana yeşil */ }
        .survey-actions { display: flex; gap: 0.5rem; /* Butonlar arası boşluk */ justify-content: flex-end; margin-top: auto; /* Kartın en altına iter */}
        .filter-form { background-color: #ffffff; padding: 1rem; border-radius: 0.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.08); margin-bottom: 1.5rem; border: 1px solid #a7f3d0;}
        .filter-form label { margin-right: 0.5rem; font-weight: 500; color: #065f46;}
        .filter-form input[type="text"], .filter-form select { padding: 0.5rem; border-radius: 0.25rem; border: 1px solid #a7f3d0; margin-right: 1rem;}
        .filter-form button { background-color: #10b981; color:white; }
        .filter-form button:hover { background-color: #059669; }
    </style>
</head>
<body>
    <nav>
        <div class="logo-area">
            <a href="../index.php">
                <img src="../assets/Psikometrik.png" alt="Psikometrik.Net Logo">
            </a>
        </div>
        <div class="flex items-center">
            <?php if (isset($_SESSION['user_id'])): ?>
                <span class="mr-4 text-gray-700 text-sm">
                    👤 <?php echo htmlspecialchars($_SESSION['username']); ?> 
                    (Rol: <?php echo htmlspecialchars(ucfirst($loggedInUserRole)); ?>)
                    <?php if ($loggedInUserRole === 'admin' && $adminDurationInfo): ?>
                        <span class="ml-2 <?php echo (strpos($adminDurationInfo, 'doldu') !== false || strpos($adminDurationInfo, 'tanımsız') !== false || strpos($adminDurationInfo, 'ayarlanmamış') !== false) ? 'text-red-500 font-semibold' : 'text-green-600'; ?>">
                            (<?php echo htmlspecialchars($adminDurationInfo); ?>)
                        </span>
                    <?php endif; ?>
                </span>
                <?php if ($loggedInUserRole === 'super-admin'): ?>
                    <a href="create_survey.php" class="btn btn-success mr-2"><i class="fas fa-plus mr-1"></i>Yeni Anket</a>
                <?php endif; ?>
                <a href="../logout.php" class="btn btn-danger"><i class="fas fa-sign-out-alt mr-1"></i>Çıkış Yap</a>
            <?php endif; ?>
        </div>
    </nav>

    <main class="container mx-auto p-4 mt-8">
        <h1 class="text-3xl font-bold mb-6 text-gray-800">Yönetim Paneli</h1>

        <?php if (isset($error) && !empty(trim($error))): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6" role="alert">
                <strong class="font-bold">Hata!</strong>
                <span class="block sm:inline"><?php echo htmlspecialchars(trim($error)); ?></span>
            </div>
        <?php endif; ?>
        <?php if (isset($success_message) && !empty(trim($success_message))): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6" role="alert">
                <strong class="font-bold">Başarılı!</strong>
                <span class="block sm:inline"><?php echo htmlspecialchars(trim($success_message)); ?></span>
            </div>
        <?php endif; ?>

        <div class="tab-buttons">
            <button class="tab-button active" data-tab="participants">Katılımlar</button>
            <button class="tab-button" data-tab="reports">Raporlar</button>
            <button class="tab-button" data-tab="surveys">Anketleri Uygula</button>
            <?php if ($loggedInUserRole === 'super-admin'): ?>
                <button class="tab-button" data-tab="survey-management">Anket Yönetimi</button>
                <button class="tab-button" data-tab="user-management">Kullanıcı Yönetimi</button>
            <?php endif; ?>
        </div>

        <div id="tab-participants" class="tab-content active">
            <h2 class="text-2xl font-semibold mb-4 text-gray-800">
                📋 <?php echo ($loggedInUserRole === 'super-admin') ? 'Tüm Anket Katılımları' : 'Size Atanmış Anket Katılımları'; ?>
            </h2>
            
            <form method="GET" action="dashboard.php" id="filterFormParticipants" class="filter-form flex flex-wrap items-center gap-4">
                <input type="hidden" name="tab" value="participants"> <div>
                    <label for="filter_class_participants">Sınıf/Grup:</label>
                    <input type="text" name="filter_class" id="filter_class_participants" value="<?php echo htmlspecialchars($filter_class); ?>" class="form-input text-sm">
                </div>
                <div>
                    <label for="filter_survey_id_participants">Anket:</label>
                    <select name="filter_survey_id" id="filter_survey_id_participants" class="form-select text-sm">
                        <option value="">Tüm Anketler</option>
                        <?php foreach ($allSurveys as $survey): ?>
                            <option value="<?php echo $survey['id']; ?>" <?php echo ($filter_survey_id == $survey['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($survey['title']); ?> (ID: <?php echo $survey['id']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary text-sm"><i class="fas fa-filter mr-1"></i>Filtrele</button>
                <a href="dashboard.php?tab=participants" class="btn btn-secondary text-sm"><i class="fas fa-times mr-1"></i>Filtreyi Temizle</a>
            </form>
            <?php if (count($allParticipants) > 0): ?>
              <div class="overflow-x-auto mt-4">
                <table class="min-w-full bg-white shadow-md rounded">
                    <thead>
                        <tr>
                             <th class="py-2 px-4 border-b text-left">ID</th>
                            <th class="py-2 px-4 border-b text-left">Ad Soyad/Açıklama</th>
                            <th class="py-2 px-4 border-b text-left">Sınıf/Branş</th>
                            <th class="py-2 px-4 border-b text-left">Anket</th>
                            <th class="py-2 px-4 border-b text-left">Katılım Tarihi</th>
                             <?php if ($loggedInUserRole === 'super-admin'): ?>
                                <th class="py-2 px-4 border-b text-left">Atanan Admin ID</th>
                            <?php endif; ?>
                            <th class="py-2 px-4 border-b text-center">İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allParticipants as $participant): ?>
                            <tr>
                                 <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($participant['id']); ?></td>
                                <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($participant['name']); ?></td>
                                <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($participant['class']); ?></td>
                                <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($participant['survey_title']); ?> (ID: <?php echo $participant['survey_id']; ?>)</td>
                                <td class="py-2 px-4 border-b"><?php echo date('d.m.Y H:i', strtotime($participant['participation_date'])); ?></td>
                                <?php if ($loggedInUserRole === 'super-admin'): ?>
                                    <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($participant['participant_admin_id'] ?? 'N/A'); ?></td>
                                <?php endif; ?>
                                <td class="py-2 px-4 border-b text-center">
                                    <?php
                                        $creation_method = $participant['creation_method'] ?? 'manual'; 
                                        $result_page_href = '';
                                        if ($creation_method === 'dynamic') {
                                            $result_page_href = "view_result_generic.php?survey_id=" . $participant['survey_id'] . "&id=" . $participant['id'];
                                        } else { 
                                            $specific_result_page = "view-result-" . $participant['survey_id'] . ".php";
                                            $default_result_page = "view_result_default.php"; 
                                            $final_result_page_href = $default_result_page;
                                            if (file_exists(__DIR__ . '/' . $specific_result_page)) {
                                                $final_result_page_href = $specific_result_page;
                                            }
                                            $result_page_href = $final_result_page_href . "?id=" . $participant['id'];
                                        }
                                    ?>
                                    <a href="<?php echo $result_page_href; ?>" class="btn btn-secondary text-xs">
                                        Sonuçları Gör
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
              </div>
            <?php else: ?>
                <p class="text-gray-600 mt-4">
                    <?php echo ((!empty($filter_class) || !empty($filter_survey_id))) ? 'Filtre kriterlerine uygun katılım bulunamadı.' : (($loggedInUserRole === 'super-admin') ? 'Henüz sisteme kayıtlı anket katılımı yok.' : 'Size atanmış anket katılımı bulunmamaktadır.'); ?>
                </p>
            <?php endif; ?>
        </div>

        <div id="tab-reports" class="tab-content">
            <h2 class="text-2xl font-semibold mb-4 text-gray-800">📊 Anket Raporları</h2>
            <div class="mb-4 flex items-center bg-white p-4 rounded-md shadow">
                <label for="survey-select-report" class="block text-sm font-medium text-gray-700 mr-3">Anket Seçin:</label>
                <select id="survey-select-report" class="form-select flex-grow">
                    <option value="">-- Anket Seçin --</option>
                    <?php foreach ($allSurveys as $survey): ?>
                        <option value="<?php echo $survey['id']; ?>" data-creation-method="<?php echo htmlspecialchars($survey['creation_method'] ?? 'manual'); ?>"><?php echo htmlspecialchars($survey['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="participants-list-report" class="bg-white p-4 rounded-md shadow">
                <p class="text-gray-600">Lütfen yukarıdan bir anket seçin.</p>
            </div>
            <button id="generate-report-button" class="btn btn-primary mt-4 hidden">
                 <i class="fas fa-file-alt mr-1"></i> Rapor Oluştur
            </button>
            <div class="loading-indicator">Rapor oluşturuluyor... <i class="fas fa-spinner fa-spin ml-2"></i></div>
        </div>

        <div id="tab-surveys" class="tab-content">
            <h2 class="text-2xl font-semibold mb-4 text-gray-800">📝 Anketleri Uygula</h2>
            <?php if (count($allSurveys) > 0): ?>
                <div class="survey-grid">
                    <?php foreach ($allSurveys as $survey): ?>
                        <div class="survey-card" 
                             data-survey-id="<?php echo $survey['id']; ?>" 
                             data-creation-method="<?php echo htmlspecialchars($survey['creation_method'] ?? 'manual'); ?>">
                            <div>
                                <h2><?php echo htmlspecialchars($survey['title']); ?></h2>
                                <p class="survey-question-count">Soru Sayısı: <?php echo htmlspecialchars($survey['question_count'] ?? '0'); ?></p>
                                <p class="survey-description"><?php echo nl2br(htmlspecialchars($survey['description'] ?? 'Açıklama bulunmamaktadır.')); ?></p>
                            </div>
                            <div class="survey-details">
                                <?php if (isset($survey['money'])): ?>
                                    <p><strong>Ücret Durumu:</strong> <?php echo ($survey['money'] === 'paid' ? 'Ücretli' : ($survey['money'] === 'free' ? 'Ücretsiz' : 'Belirtilmemiş')); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="survey-actions">
                                <button type="button" class="btn btn-secondary text-sm flex-1" 
                                        onclick="showLink(<?php echo $survey['id']; ?>, '<?php echo htmlspecialchars($survey['creation_method'] ?? 'manual'); ?>')">
                                    <i class="fas fa-link mr-1"></i> Linki Göster
                                </button>
                                <button type="button" class="btn btn-apply text-sm flex-1" 
                                        onclick="applySurvey(<?php echo $survey['id']; ?>, '<?php echo htmlspecialchars($survey['creation_method'] ?? 'manual'); ?>')">
                                    <i class="fas fa-play mr-1"></i> Uygula
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div id="popup" class="hidden fixed inset-0 flex items-center justify-center popup-overlay z-50">
                    <div class="bg-white p-8 rounded shadow-md text-center">
                        <h3 class="text-xl font-bold mb-4 text-gray-800">Paylaşım Linki</h3>
                        <input type="text" id="shareLink" readonly class="form-input w-full p-2 border rounded mb-4 text-center">
                        <button onclick="copyLink()" class="btn btn-success w-full mb-2">
                            <i class="fas fa-copy mr-1"></i> Kopyala
                        </button>
                        <button onclick="closePopup()" class="text-red-600 hover:text-red-700 underline w-full mt-2">Kapat</button>
                    </div>
                </div>
            <?php else: ?>
                <p class="text-gray-600">Henüz sistemde anket bulunmamaktadır.</p>
            <?php endif; ?>
        </div>

        <?php if ($loggedInUserRole === 'super-admin'): ?>
        <div id="tab-survey-management" class="tab-content">
            <h2 class="text-2xl font-semibold mb-4 text-gray-800">🛠️ Anket Yönetimi</h2>
            <?php if (count($allSurveys) > 0): ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full bg-white shadow-md rounded">
                        <thead>
                            <tr>
                                <th class="py-2 px-4 border-b text-left">ID</th>
                                <th class="py-2 px-4 border-b text-left">Başlık</th>
                                <th class="py-2 px-4 border-b text-left">Açıklama</th>
                                <th class="py-2 px-4 border-b text-left">Ücret Durumu</th>
                                <th class="py-2 px-4 border-b text-left">Yöntem</th>
                                <th class="py-2 px-4 border-b text-left">Tarih</th>
                                <th class="py-2 px-4 border-b text-center">İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allSurveys as $survey): ?>
                                <tr>
                                    <td class="py-2 px-4 border-b">
                                        <?php echo htmlspecialchars($survey['id']); ?>
                                        <form method="POST" action="dashboard.php#tab-survey-management" id="surveyUpdateForm_<?php echo $survey['id']; ?>" class="survey-update-form-row">
                                            <input type="hidden" name="action" value="update_survey_info">
                                            <input type="hidden" name="survey_id_to_update" value="<?php echo $survey['id']; ?>">
                                        </form>
                                    </td>
                                    <td class="py-2 px-4 border-b">
                                        <input type="text" name="new_survey_title" form="surveyUpdateForm_<?php echo $survey['id']; ?>" value="<?php echo htmlspecialchars($survey['title']); ?>" class="form-input text-sm">
                                    </td>
                                    <td class="py-2 px-4 border-b">
                                        <textarea name="new_survey_description" form="surveyUpdateForm_<?php echo $survey['id']; ?>" rows="2" class="form-textarea text-sm editable-textarea"><?php echo htmlspecialchars($survey['description'] ?? ''); ?></textarea>
                                    </td>
                                    <td class="py-2 px-4 border-b">
                                        <select name="new_survey_money_status" form="surveyUpdateForm_<?php echo $survey['id']; ?>" class="form-select text-sm">
                                            <option value="" <?php echo (!isset($survey['money']) || $survey['money'] === null || $survey['money'] === '') ? 'selected' : ''; ?>>Seçiniz</option>
                                            <option value="free" <?php echo (isset($survey['money']) && $survey['money'] === 'free') ? 'selected' : ''; ?>>Ücretsiz</option>
                                            <option value="paid" <?php echo (isset($survey['money']) && $survey['money'] === 'paid') ? 'selected' : ''; ?>>Ücretli</option>
                                        </select>
                                    </td>
                                    <td class="py-2 px-4 border-b">
                                        <?php 
                                            $method = $survey['creation_method'] ?? 'manual';
                                            echo htmlspecialchars(ucfirst($method)); 
                                        ?>
                                    </td>
                                    <td class="py-2 px-4 border-b"><?php echo date('d.m.Y H:i', strtotime($survey['created_at'])); ?></td>
                                    <td class="py-2 px-4 border-b text-center">
                                        <button type="submit" form="surveyUpdateForm_<?php echo $survey['id']; ?>" class="btn btn-success text-xs mb-1 w-full" title="Bilgileri Kaydet"><i class="fas fa-save mr-1"></i>Kaydet</button>
                                        <a href="view_survey_questions.php?survey_id=<?php echo $survey['id']; ?>" class="btn btn-info text-xs mr-1 mb-1 w-full" title="Soruları Görüntüle"><i class="fas fa-list-ol mr-1"></i>Sorular</a>
                                        <?php 
                                        $edit_link = (($survey['creation_method'] ?? 'manual') === 'dynamic') ? "create_survey.php?edit_id=" . $survey['id'] : "#";
                                        ?>
                                        <a href="<?php echo $edit_link; ?>" class="btn btn-warning text-xs mr-1 mb-1 w-full <?php if(($survey['creation_method'] ?? 'manual') !== 'dynamic') echo 'opacity-50 cursor-not-allowed';?>" title="Anketi Düzenle (Sorular)" <?php if(($survey['creation_method'] ?? 'manual') !== 'dynamic') echo 'onclick="alert(\'Manuel anketlerin soruları buradan düzenlenemez.\'); return false;"';?>>
                                            <i class="fas fa-edit mr-1"></i>Soruları Düzenle
                                        </a>
                                        <form action="dashboard.php#tab-survey-management" method="POST" class="inline-block w-full" onsubmit="return confirm('Bu anketi ve ilişkili tüm verileri (sorular, cevaplar, katılımlar) silmek istediğinizden emin misiniz? Bu işlem geri alınamaz! Not: Eğer anketle ilişkili veri varsa (katılım, cevap vb.) veritabanı kısıtlamaları nedeniyle silme işlemi başarısız olabilir.');">
                                            <input type="hidden" name="action" value="delete_survey">
                                            <input type="hidden" name="survey_id_to_delete" value="<?php echo $survey['id']; ?>">
                                            <button type="submit" class="btn btn-danger text-xs w-full" title="Anketi Sil"><i class="fas fa-trash-alt mr-1"></i>Sil</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-gray-600">Sistemde kayıtlı anket bulunmamaktadır.</p>
            <?php endif; ?>
        </div>

        <div id="tab-user-management" class="tab-content">
            <h2 class="text-2xl font-semibold mb-4 text-gray-800">👥 Kullanıcı Yönetimi</h2>
            <?php if (count($allSystemUsers) > 0): ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full bg-white shadow-md rounded">
                        <thead>
                            <tr>
                                <th class="py-2 px-4 border-b text-left">ID</th>
                                <th class="py-2 px-4 border-b text-left">Kullanıcı Adı</th>
                                <th class="py-2 px-4 border-b text-left">E-posta</th>
                                <th class="py-2 px-4 border-b text-left">Rol</th>
                                <th class="py-2 px-4 border-b text-left">Süre (Gün)</th>
                                <th class="py-2 px-4 border-b text-left">Lisans Başlangıcı</th>
                                <th class="py-2 px-4 border-b text-center">İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allSystemUsers as $systemUser): ?>
                                <tr>
                                    <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($systemUser['id']); ?></td>
                                    <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($systemUser['username']); ?></td>
                                    <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($systemUser['email']); ?></td>
                                    <form method="POST" action="dashboard.php#tab-user-management"> 
                                        <input type="hidden" name="action" value="update_user_details">
                                        <input type="hidden" name="user_id" value="<?php echo $systemUser['id']; ?>">
                                        <td class="py-2 px-4 border-b">
                                            <select name="role" class="form-select w-full">
                                                <option value="admin" <?php echo ($systemUser['role'] === 'admin') ? 'selected' : ''; ?>>Admin</option>
                                                <option value="super-admin" <?php echo ($systemUser['role'] === 'super-admin') ? 'selected' : ''; ?>>Super Admin</option>
                                            </select>
                                        </td>
                                        <td class="py-2 px-4 border-b">
                                            <input type="number" name="duration_days" value="<?php echo htmlspecialchars($systemUser['duration_days'] ?? ''); ?>" class="form-input w-full" placeholder="Örn: 30 (0 limitsiz)">
                                        </td>
                                        <td class="py-2 px-4 border-b">
                                            <input type="date" name="license_start_date" value="<?php echo htmlspecialchars($systemUser['license_start_date'] ?? ''); ?>" class="form-input w-full">
                                        </td>
                                        <td class="py-2 px-4 border-b text-center">
                                            <button type="submit" class="btn btn-warning text-xs"><i class="fas fa-save mr-1"></i>Güncelle</button>
                                        </td>
                                    </form>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-gray-600">Sistemde kayıtlı başka kullanıcı bulunmamaktadır.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </main>
     <footer class="bg-white border-t border-gray-200 mt-12 py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center text-gray-500 text-sm">
            &copy; <?php echo date('Y'); ?> Psikometrik.Net Anket Platformu. Tüm hakları saklıdır.
        </div>
    </footer>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const tabButtons = document.querySelectorAll('.tab-button');
    const tabContents = document.querySelectorAll('.tab-content');
    const surveySelectReport = document.getElementById('survey-select-report'); 
    const participantsListDivReport = document.getElementById('participants-list-report'); 
    const generateReportButton = document.getElementById('generate-report-button');
    const loadingIndicator = document.querySelector('.loading-indicator');
    
    const loggedInUserRoleJS = <?php echo json_encode($loggedInUserRole); ?>;
    const allParticipantsFromPHPJS = <?php echo json_encode($allParticipants); ?>; 
    const allSurveysFromPHPJS = <?php echo json_encode($allSurveys); ?>; 

    window.showLink = function(surveyId, creationMethod = 'manual') {
        const popup = document.getElementById('popup');
        const linkInput = document.getElementById('shareLink');
        const currentAdminId = <?php echo json_encode($loggedInUserId); ?>;
        let surveyLink = '';
        const baseOrigin = window.location.origin; 
        // const surveyPath = (baseOrigin.includes('localhost') || baseOrigin.includes('127.0.0.1')) ? '' : '/testanket'; // Bu satır kaldırıldı, direkt kök dizin varsayılıyor
         const surveyPath = ''; // Direkt kök dizin varsayımı

        if (creationMethod === 'dynamic') {
            surveyLink = `${baseOrigin}${surveyPath}/take_survey_generic.php?survey_id=${surveyId}&admin_id=${encodeURIComponent(currentAdminId)}`;
        } else { 
            surveyLink = `${baseOrigin}${surveyPath}/take-survey-${surveyId}.php?admin_id=${encodeURIComponent(currentAdminId)}`;
        }
        
        if(linkInput) linkInput.value = surveyLink;
        if(popup) popup.classList.remove('hidden');
    }

    window.applySurvey = function(surveyId, creationMethod = 'manual') {
        const currentAdminId = <?php echo json_encode($loggedInUserId); ?>;
        let surveyLink = '';
        const baseOrigin = window.location.origin;
        // const surveyPath = (baseOrigin.includes('localhost') || baseOrigin.includes('127.0.0.1')) ? '' : '/testanket'; // Bu satır kaldırıldı
        const surveyPath = ''; // Direkt kök dizin varsayımı


        if (creationMethod === 'dynamic') {
            surveyLink = `${baseOrigin}${surveyPath}/take_survey_generic.php?survey_id=${surveyId}&admin_id=${encodeURIComponent(currentAdminId)}`;
        } else {
            surveyLink = `${baseOrigin}${surveyPath}/take-survey-${surveyId}.php?admin_id=${encodeURIComponent(currentAdminId)}`;
        }
        window.open(surveyLink, '_blank');
    }


    window.copyLink = function() {
        const copyText = document.getElementById('shareLink');
        if(!copyText) return;
        copyText.select();
        copyText.setSelectionRange(0, 99999); 
        try {
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(copyText.value);
            } else {
                const successful = document.execCommand('copy');
                if (!successful) { throw new Error('Fallback copy failed'); }
            }
            alert('Link panoya kopyalandı!'); 
        } catch (err) {
            console.error('Link kopyalanamadı: ', err);
            alert('Link kopyalanamadı. Lütfen manuel olarak kopyalayın.');
        }
    }

    window.closePopup = function() {
        const popup = document.getElementById('popup');
        if(popup) popup.classList.add('hidden');
    }
    
    function escapeHTML(str) {
        if (str === null || typeof str === 'undefined') return '';
        return String(str).replace(/[&<>"']/g, function (match) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[match];
        });
    }

    function displayParticipantsForReport(selectedSurveyId) { 
        if(!participantsListDivReport) return;
        participantsListDivReport.innerHTML = ''; 
        if(generateReportButton) generateReportButton.classList.add('hidden'); 

        if (!selectedSurveyId) {
            participantsListDivReport.innerHTML = '<p class="text-gray-600">Lütfen yukarıdan bir anket seçin.</p>';
            return;
        }
        
        const selectedOption = surveySelectReport.options[surveySelectReport.selectedIndex];
        
        const filteredParticipants = allParticipantsFromPHPJS.filter(participant =>
            participant.survey_id == selectedSurveyId 
        );

        if (filteredParticipants.length > 0) {
            let tableHtml = `
                <div class="overflow-x-auto">
                    <table class="min-w-full bg-white shadow-md rounded">
                        <thead>
                            <tr>
                                <th class="py-2 px-4 border-b text-center"><input type="checkbox" id="select-all-participants-report"></th>
                                <th class="py-2 px-4 border-b text-left">ID</th>
                                <th class="py-2 px-4 border-b text-left">Ad Soyad</th>
                                <th class="py-2 px-4 border-b text-left">Sınıf</th>
                                <th class="py-2 px-4 border-b text-left">Anket</th>`;
            if (loggedInUserRoleJS === 'super-admin') {
                tableHtml += `<th class="py-2 px-4 border-b text-left">Atanan Admin ID</th>`;
            }
            tableHtml += `<th class="py-2 px-4 border-b text-center">Bireysel Sonuç</th>
                        </tr>
                    </thead>
                    <tbody>`;

            filteredParticipants.forEach(participant => {
                let resultLink = '';
                const participantSurveyCreationMethod = participant.creation_method || 'manual'; 
                const participantSurveyId = participant.survey_id;
                const participantId = participant.id;

                if (participantSurveyCreationMethod === 'dynamic') {
                     resultLink = `view_result_generic.php?survey_id=${participantSurveyId}&id=${participantId}`;
                } else {
                    let specificResultPageFilename = `view-result-${participantSurveyId}.php`;
                    resultLink = `${specificResultPageFilename}?id=${participantId}`;
                }

                tableHtml += `
                    <tr>
                        <td class="py-2 px-4 border-b text-center">
                            <input type="checkbox" class="participant-checkbox-report" value="${escapeHTML(participant.id)}">
                        </td>
                        <td class="py-2 px-4 border-b">${escapeHTML(participant.id)}</td>
                        <td class="py-2 px-4 border-b">${escapeHTML(participant.name)}</td>
                        <td class="py-2 px-4 border-b">${escapeHTML(participant.class)}</td>
                        <td class="py-2 px-4 border-b">${escapeHTML(participant.survey_title)}</td>`;
                if (loggedInUserRoleJS === 'super-admin') {
                     tableHtml += `<td class="py-2 px-4 border-b">${escapeHTML(participant.participant_admin_id || 'N/A')}</td>`;
                }
                tableHtml += `<td class="py-2 px-4 border-b text-center">
                            <a href="${resultLink}" class="btn btn-secondary text-xs"> Sonuçları Gör
                            </a>
                        </td>
                    </tr>`;
            });
            tableHtml += `</tbody></table></div>`;
            participantsListDivReport.innerHTML = tableHtml;
            if(generateReportButton) generateReportButton.classList.remove('hidden');

            const selectAllCheckboxReport = document.getElementById('select-all-participants-report');
            if(selectAllCheckboxReport) {
                selectAllCheckboxReport.addEventListener('change', function() {
                    const checkboxes = participantsListDivReport.querySelectorAll('.participant-checkbox-report');
                    checkboxes.forEach(checkbox => { checkbox.checked = this.checked; });
                });
            }
        } else {
            participantsListDivReport.innerHTML = '<p class="text-gray-600">Bu anket için ' + (loggedInUserRoleJS === 'super-admin' ? 'henüz katılım yok.' : 'size atanmış bir katılım bulunmamaktadır.') + '</p>';
            if(generateReportButton) generateReportButton.classList.add('hidden'); 
        }
    }
    
    const filterFormParticipants = document.getElementById('filterFormParticipants'); 
    if(filterFormParticipants){
        // Formun action'ı zaten dashboard.php#tab-participants olduğu için
        // ekstra JS submit handler'ına gerek yok, sayfa GET ile yeniden yüklenecek.
    }


    tabButtons.forEach(button => {
        button.addEventListener('click', () => {
            const previousActiveTab = document.querySelector('.tab-button.active');
            if (previousActiveTab) previousActiveTab.classList.remove('active');
            
            tabContents.forEach(content => content.classList.remove('active'));
            
            button.classList.add('active');
            const targetTab = button.dataset.tab;
            const targetContent = document.getElementById(`tab-${targetTab}`);
            if(targetContent) { targetContent.classList.add('active'); }

            if (targetTab === 'reports' && surveySelectReport) { 
                 const selectedSurveyId = surveySelectReport.value;
                 displayParticipantsForReport(selectedSurveyId);
            } else if (targetTab === 'participants') {
                // Katılımlar sekmesi için özel bir işlem gerekmiyor, PHP zaten filtreleri uyguluyor.
            }
            
            if (targetTab !== 'reports' && generateReportButton) { 
                generateReportButton.classList.add('hidden');
            }
             // URL hash'ini güncelle
            if (history.pushState) {
                history.pushState(null, null, `#${targetTab}`);
            } else {
                window.location.hash = targetTab;
            }
        });
    });

    if(surveySelectReport){ 
        surveySelectReport.addEventListener('change', function() {
            const activeTabButton = document.querySelector('.tab-button.active');
            if (activeTabButton && activeTabButton.dataset.tab === 'reports') {
                displayParticipantsForReport(this.value);
            }
        });
    }
    
    function redirectToReportPage(surveyId, participantIds) {
        const participantIdsString = participantIds.join(',');
        window.location.href = `view_report.php?survey_id=${surveyId}&participant_ids=${participantIdsString}`;
    }


    if(generateReportButton){ 
        generateReportButton.addEventListener('click', function() {
            const selectedSurveyId = surveySelectReport.value;
            const selectedParticipantCheckboxes = participantsListDivReport.querySelectorAll('.participant-checkbox-report:checked');
            const selectedParticipantIds = Array.from(selectedParticipantCheckboxes).map(checkbox => checkbox.value);

            if (!selectedSurveyId) { alert('Lütfen bir anket seçin.'); return; }
            if (selectedParticipantIds.length === 0) { alert('Lütfen rapor oluşturmak için en az bir katılımcı seçin.'); return; }
            
            if(loadingIndicator) loadingIndicator.style.display = 'block';
            generateReportButton.classList.add('hidden');

            redirectToReportPage(selectedSurveyId, selectedParticipantIds);
        });
    }

    // Sayfa yüklendiğinde URL hash'ine göre doğru tabı aktif et
    if (window.location.hash) {
        const hash = window.location.hash.substring(1); 
        const targetButton = document.querySelector(`.tab-button[data-tab="${hash}"]`);
        if (targetButton) { 
            targetButton.click(); 
        } else if (tabButtons.length > 0) { 
            tabButtons[0].click(); 
        }
    } else if (tabButtons.length > 0) { 
        tabButtons[0].click(); 
    }
});
</script>
</body>
</html>
