<?php
session_start();
require_once '../src/config.php';

// Hata raporlamayı etkinleştir (Geliştirme ortamı için uygundur, canlı ortamda kapatılmalıdır)
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
$success_message = null; // Başarı mesajları için

// --- Rapor parametrelerinin yanlışlıkla dashboard'a gelmesi durumunu kontrol et ---
if (isset($_GET['survey_id']) || isset($_GET['participant_ids'])) {
    error_log("UYARI: dashboard.php sayfası, rapor parametreleri ile yüklendi. URL: " . $_SERVER['REQUEST_URI']);
    $error = "Rapor bağlantısı geçersiz veya rapor sayfası yüklenirken bir sorun oluştu. Lütfen tekrar deneyin veya dashboard menüsünü kullanın.";
}

// --- Super Admin Kullanıcı Yönetimi İşlemleri (POST) ---
if ($loggedInUserRole === 'super-admin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $userIdToUpdate = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);

        if (!$userIdToUpdate) {
            $error = "Geçersiz kullanıcı ID'si.";
        } else {
            if ($_POST['action'] === 'update_user_details') {
                $newRole = filter_input(INPUT_POST, 'role', FILTER_SANITIZE_STRING);
                $newDurationDays = filter_input(INPUT_POST, 'duration_days', FILTER_VALIDATE_INT);
                $newLicenseStartDateStr = filter_input(INPUT_POST, 'license_start_date', FILTER_SANITIZE_STRING);
                
                $updateFields = [];
                $updateParams = [':id' => $userIdToUpdate];

                if ($newRole && in_array($newRole, ['admin', 'super-admin'])) {
                    $updateFields[] = "role = :role";
                    $updateParams[':role'] = $newRole;
                } else if (isset($_POST['role'])) {
                     $error = "Geçersiz rol seçimi.";
                }

                if ($newDurationDays !== false && $newDurationDays >= 0) { // 0 da geçerli bir değer (limitsiz)
                    $updateFields[] = "duration_days = :duration_days";
                    $updateParams[':duration_days'] = $newDurationDays;
                } else if (isset($_POST['duration_days']) && $_POST['duration_days'] !== '') {
                    $error = (isset($error) ? $error." " : "") . "Geçersiz süre (gün) değeri.";
                }
                
                $newLicenseStartDate = null;
                if (!empty($newLicenseStartDateStr)) {
                    try {
                        $dt = new DateTime($newLicenseStartDateStr);
                        $newLicenseStartDate = $dt->format('Y-m-d');
                        $updateFields[] = "license_start_date = :license_start_date";
                        $updateParams[':license_start_date'] = $newLicenseStartDate;
                    } catch (Exception $e) {
                        $error = (isset($error) ? $error." " : "") . "Geçersiz lisans başlangıç tarihi formatı. YYYY-MM-DD kullanın.";
                    }
                } else if (isset($_POST['license_start_date']) && $_POST['license_start_date'] === '') { // Tarihi silmek için
                    $updateFields[] = "license_start_date = NULL";
                }


                if (empty($error) && !empty($updateFields)) {
                    try {
                        $sql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = :id";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($updateParams);
                        $success_message = "Kullanıcı bilgileri başarıyla güncellendi.";
                    } catch (PDOException $e) {
                        $error = "Kullanıcı güncellenirken veritabanı hatası oluştu: " . $e->getMessage();
                        error_log("Kullanıcı güncelleme hatası (dashboard.php): " . $e->getMessage() . " - UserID: " . $userIdToUpdate);
                    }
                } elseif (empty($updateFields) && empty($error)) {
                    $error = "Güncellenecek bir bilgi seçilmedi veya girilmedi.";
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
            if (isset($adminUserData['duration_days'])) {
                $durationDays = (int)$adminUserData['duration_days'];
                if ($durationDays > 0 && !empty($adminUserData['license_start_date'])) {
                    try {
                        $licenseStartDate = new DateTime($adminUserData['license_start_date']);
                        $today = new DateTime();
                        $expiryDate = (clone $licenseStartDate)->add(new DateInterval("P{$durationDays}D"));
                        
                        if ($today >= $expiryDate) { 
                            $adminDurationInfo = "Lisans süreniz doldu.";
                        } else {
                            $diff = $today->diff($expiryDate);
                            $remainingDays = $diff->days; 
                            $adminDurationInfo = "Kalan Gün: " . $remainingDays;
                        }
                    } catch (Exception $e) {
                         $adminDurationInfo = "Süre hesaplanamadı (tarih hatası).";
                         error_log("Admin süre hesaplama DateTime hatası: " . $e->getMessage() . " - AdminID: " . $loggedInUserId);
                    }
                } elseif ($durationDays === 0) { 
                    $adminDurationInfo = "Süre: Limitsiz";
                } else {
                    $adminDurationInfo = "Süre bilgisi ayarlanmamış.";
                }
            } else {
                $adminDurationInfo = "Süre bilgisi tanımsız.";
            }
        }
    } catch (PDOException $e) {
        $adminDurationInfo = "Süre bilgisi alınamadı.";
        error_log("Admin süre bilgisi PDOException (dashboard.php): " . $e->getMessage() . " - AdminID: " . $loggedInUserId);
    }
}


// --- Super Admin için Kullanıcı Listesi ---
$allSystemUsers = [];
if ($loggedInUserRole === 'super-admin') {
    try {
        $stmt_users = $pdo->query("SELECT id, username, email, role, duration_days, license_start_date FROM users ORDER BY id ASC");
        $allSystemUsers = $stmt_users->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        if (!isset($error)) {
            $error = "Kullanıcılar listelenirken bir veritabanı hatası oluştu: " . $e->getMessage();
        } else {
            $error .= " Kullanıcılar listelenirken ek hata: " . $e->getMessage();
        }
    }
}


// Tüm anketleri çek
$allSurveys = [];
try {
    $allSurveysStmt = $pdo->query("SELECT id, title, description FROM surveys ORDER BY id DESC");
    $allSurveys = $allSurveysStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    if (!isset($error)) { $error = "Anketler yüklenirken bir veritabanı hatası oluştu: " . $e->getMessage(); } 
    else { $error .= " Anketler yüklenirken ek hata: " . $e->getMessage(); }
    $allSurveys = [];
}

// Tüm katılımcıları çek (Rol bazlı filtreleme ile)
$allParticipants = [];
$allParticipantsSql = "
    SELECT sp.id, sp.name, sp.class, sp.survey_id, s.title AS survey_title, sp.admin_id AS participant_admin_id
    FROM survey_participants sp
    JOIN surveys s ON sp.survey_id = s.id";
$allParticipantsParams = [];

if ($loggedInUserRole !== 'super-admin') {
    $allParticipantsSql .= " WHERE sp.admin_id = :loggedInAdminId";
    $allParticipantsParams[':loggedInAdminId'] = $loggedInUserId;
}
$allParticipantsSql .= " ORDER BY sp.id DESC";

try {
     $allParticipantsStmt = $pdo->prepare($allParticipantsSql);
     $allParticipantsStmt->execute($allParticipantsParams);
     $allParticipants = $allParticipantsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    if (!isset($error)) { $error = "Katılımcılar yüklenirken bir veritabanı hatası oluştu: " . $e->getMessage(); }
    else { $error .= " Katılımcılar yüklenirken ek hata: " . $e->getMessage(); }
    $allParticipants = [];
}

$averageCompletionTimeNote = "Hesaplanamadı (veri eksik)";
$totalQuestionsPlaceholder = "N/A"; 
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | Anket Platformu</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: sans-serif; line-height: 1.6; background-color: #f8fafc; color: #334155; }
        nav { background-color: #ffffff; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05); padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: flex-end; }
        .logo-area { display: flex; align-items: center; gap: 0.75rem; }
        .logo-area img { height: 6rem; vertical-align: middle; }
        .logo-area a { font-size: 1.5rem; font-weight: bold; color: #0e7490; text-decoration: none; }
        .btn { padding: 0.6rem 1.25rem; border-radius: 0.375rem; color: white; font-weight: 500; transition: background-color 0.2s ease-in-out; display: inline-block; text-align: center; text-decoration: none; }
        .btn-primary { background-color: #0ea5e9; } .btn-primary:hover { background-color: #0284c7; }
        .btn-secondary { background-color: #64748b; } .btn-secondary:hover { background-color: #475569; }
        .btn-success { background-color: #22c55e; } .btn-success:hover { background-color: #16a34a; }
        .btn-danger { background-color: #ef4444; } .btn-danger:hover { background-color: #dc2626; }
        .btn-warning { background-color: #f59e0b; } .btn-warning:hover { background-color: #d97706; } 
        table { width: 100%; border-collapse: collapse; margin-top: 1.5rem; }
        th, td { text-align: left; padding: 0.75rem 1rem; border-bottom: 1px solid #e2e8f0; }
        th { background-color: #f1f5f9; font-weight: 600; color: #475569; }
        tbody tr:nth-child(even) { background-color: #f8fafc; }
        tbody tr:hover { background-color: #e2e8f0; }
        .survey-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.5rem; }
        .survey-card { background-color: #ffffff; padding: 1.5rem; border-radius: 0.5rem; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08); transition: box-shadow 0.3s ease-in-out; border: 1px solid #e2e8f0; display: flex; flex-direction: column; justify-content: space-between; }
        .survey-card:hover { box-shadow: 0 5px 10px rgba(0, 0, 0, 0.1); }
        .survey-card h2 { font-size: 1.25rem; font-weight: 600; margin-bottom: 0.75rem; color: #1e293b; }
        .survey-card p { color: #475569; margin-bottom: 1.5rem; line-height: 1.5; flex-grow: 1; }
        .survey-details { font-size: 0.9rem; color: #64748b; margin-top: 1rem; margin-bottom: 1.5rem; border-top: 1px solid #e2e8f0; padding-top: 1rem; }
        .survey-details p { margin-bottom: 0.5rem; color: #64748b; }
        .popup-overlay { background-color: rgba(0, 0, 0, 0.7); }
        #popup > div { max-width: 500px; width: 90%; }
        #shareLink { background-color: #f1f5f9; cursor: text; color: #334155; }
        #popup button.bg-green-500 { background-color: #22c55e; }
        .btn-green-500:hover { background-color: #16a34a; }
        .mx-auto { margin-left: auto; margin-right: auto; }
        .container { width: 100%; max-width: 1200px; } 
        .p-4 { padding: 1rem; } .mb-6 { margin-bottom: 1.5rem; } .text-2xl { font-size: 1.5rem; } .font-bold { font-weight: 700; }
        .mt-4 { margin-top: 1rem; } .mr-2 { margin-right: 0.5rem; } .ml-2 { margin-left: 0.5rem; } .mb-8 { margin-bottom: 2rem; }
        .text-3xl { font-size: 1.875rem; } .items-center { align-items: center; } .mb-12 { margin-bottom: 3rem; }
        .min-w-full { min-width: 100%; } .shadow-md { box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06); }
        .rounded { border-radius: 0.25rem; } .border-b { border-bottom-width: 1px; } .text-center { text-align: center; }
        .underline { text-decoration: underline; } .fixed { position: fixed; } .inset-0 { top: 0; right: 0; bottom: 0; left: 0; }
        .z-50 { z-index: 50; } .hidden { display: none; } .w-full { width: 100%; } .mb-2 { margin-bottom: 0.5rem; }
        .text-xl { font-size: 1.25rem; } .text-gray-800 { color: #1f2937; } .overflow-x-auto { overflow-x: auto; }
        #report-section { margin-top: 0; } #report-section .mb-4 { margin-bottom: 1.5rem; }
        #survey-select { padding: 0.5rem; border-radius: 0.25rem; border: 1px solid #d1d5db; margin-right: 1rem; }
        .tab-buttons { display: flex; margin-bottom: 1.5rem; border-bottom: 2px solid #e2e8f0; }
        .tab-button { padding: 0.75rem 1.5rem; cursor: pointer; border: none; background-color: transparent; font-size: 1rem; font-weight: 500; color: #64748b; transition: color 0.2s ease-in-out, border-bottom-color 0.2s ease-in-out; border-bottom: 2px solid transparent; }
        .tab-button:hover { color: #1e293b; }
        .tab-button.active { color: #0ea5e9; border-bottom-color: #0ea5e9; font-weight: 600; }
        .tab-content { display: none; padding-top: 1rem; }
        .tab-content.active { display: block; }
        .loading-indicator { display: none; text-align: center; margin-top: 1rem; color: #0ea5e9; }
        .form-input { @apply mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm; }
        .form-select { @apply mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <nav class="bg-white shadow p-4 flex justify-between items-center"> 
        <div class="logo-area">
            <a href="../index.php"><img src="../assets/Psikometrik.png" alt="Psikometrik.Net Logo" ></a>
        </div>

        <div class="flex items-center">
            <?php if (isset($_SESSION['user_id'])): ?>
                <span class="mr-4 text-gray-700">
                    👤 <?php echo htmlspecialchars($_SESSION['username']); ?> 
                    (Rol: <?php echo htmlspecialchars(ucfirst($loggedInUserRole)); ?>)
                    <?php if ($loggedInUserRole === 'admin' && $adminDurationInfo): ?>
                        <span class="ml-2 text-sm <?php echo (strpos($adminDurationInfo, 'doldu') !== false || strpos($adminDurationInfo, 'tanımsız') !== false || strpos($adminDurationInfo, 'ayarlanmamış') !== false) ? 'text-red-500 font-semibold' : 'text-green-600'; ?>">
                            (<?php echo htmlspecialchars($adminDurationInfo); ?>)
                        </span>
                    <?php endif; ?>
                </span>
                <a href="create_survey.php" class="btn btn-success mr-2">Yeni Anket</a>
                <a href="../logout.php" class="btn btn-danger">Çıkış</a>
            <?php endif; ?>
        </div>
    </nav>

    <main class="container mx-auto p-4 mt-8">
        <h1 class="text-3xl font-bold mb-6 text-gray-800">Admin Dashboard</h1>

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
                <button class="tab-button" data-tab="user-management">Kullanıcı Yönetimi</button>
            <?php endif; ?>
        </div>

        <div id="tab-participants" class="tab-content active">
            <h2 class="text-2xl font-semibold mb-4 text-gray-800">
                📋 <?php echo ($loggedInUserRole === 'super-admin') ? 'Tüm Öğrenci Anket Katılımları' : 'Size Atanmış Öğrenci Anket Katılımları'; ?>
            </h2>
            <?php if (count($allParticipants) > 0): ?>
              <div class="overflow-x-auto">
                <table class="min-w-full bg-white shadow-md rounded">
                    <thead>
                        <tr>
                             <th class="py-2 px-4 border-b text-left">ID</th>
                            <th class="py-2 px-4 border-b text-left">Ad Soyad</th>
                            <th class="py-2 px-4 border-b text-left">Sınıf</th>
                            <th class="py-2 px-4 border-b text-left">Anket</th>
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
                                <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($participant['survey_title']); ?></td>
                                <?php if ($loggedInUserRole === 'super-admin'): ?>
                                    <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($participant['participant_admin_id'] ?? 'N/A'); ?></td>
                                <?php endif; ?>
                                <td class="py-2 px-4 border-b text-center">
                                    <?php
                                        // dashboard.php dosyası admin klasöründe olduğu varsayıldı.
                                        // view-result-X.php dosyaları da admin klasöründe olmalı.
                                        $specific_result_page_filename = "view-result-" . $participant['survey_id'] . ".php";
                                        $default_result_page_filename = "view_result_default.php"; 
                                        
                                        $final_result_page_href = $default_result_page_filename; // Varsayılanı ata
                                        // __DIR__ dashboard.php'nin bulunduğu dizini verir (örn: /path/to/project/admin)
                                        if (file_exists(__DIR__ . '/' . $specific_result_page_filename)) {
                                            $final_result_page_href = $specific_result_page_filename;
                                        }
                                    ?>
                                    <a href="<?php echo $final_result_page_href; ?>?id=<?php echo $participant['id']; ?>" class="btn btn-primary inline-block text-xs">
                                        Sonuçları Gör
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
              </div>
            <?php else: ?>
                <p class="text-gray-600">
                    <?php echo ($loggedInUserRole === 'super-admin') ? 'Henüz sisteme kayıtlı anket katılımı yok.' : 'Size atanmış anket katılımı bulunmamaktadır.'; ?>
                </p>
            <?php endif; ?>
        </div>

        <div id="tab-reports" class="tab-content">
            <h2 class="text-2xl font-semibold mb-4 text-gray-800">📊 Anket Raporları</h2>
            <div class="mb-4 flex items-center">
                <label for="survey-select" class="block text-gray-700 font-semibold mr-4">Anket Seçin:</label>
                <select id="survey-select" class="form-select flex-grow p-2 border rounded">
                    <option value="">-- Anket Seçin --</option>
                    <?php foreach ($allSurveys as $survey): ?>
                        <option value="<?php echo $survey['id']; ?>"><?php echo htmlspecialchars($survey['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="participants-list">
                <p class="text-gray-600">Lütfen yukarıdan bir anket seçin.</p>
            </div>
            <button id="generate-report-button" class="btn btn-primary mt-4 hidden">Rapor Oluştur</button>
            <div class="loading-indicator">Rapor oluşturuluyor...</div>
        </div>

        <div id="tab-surveys" class="tab-content">
            <h2 class="text-2xl font-semibold mb-4 text-gray-800">📝 Anketleri Uygula</h2>
            <?php if (count($allSurveys) > 0): ?>
                <div class="survey-grid">
                    <?php foreach ($allSurveys as $survey): ?>
                        <div class="survey-card" data-survey-id="<?php echo $survey['id']; ?>">
                            <h2 class="text-xl font-semibold mb-2"><?php echo htmlspecialchars($survey['title']); ?></h2>
                            <p class="text-gray-600 mb-4 flex-grow"><?php echo nl2br(htmlspecialchars($survey['description'] ?? 'Açıklama bulunmamaktadır.')); ?></p>
                            <div class="survey-details"></div>
                            <div class="flex justify-end mt-auto">
                                <button type="button" class="btn btn-success" onclick="showLink(<?php echo $survey['id']; ?>)">
                                    Linki Göster
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div id="popup" class="hidden fixed inset-0 flex items-center justify-center popup-overlay z-50">
                    <div class="bg-white p-8 rounded shadow-md text-center max-w-md w-full">
                        <h3 class="text-xl font-bold mb-4 text-gray-800">Paylaşım Linki</h3>
                        <input type="text" id="shareLink" readonly class="w-full p-2 border rounded mb-4 bg-gray-100">
                        <button onclick="copyLink()" class="btn btn-success w-full mb-2">Kopyala</button>
                        <button onclick="closePopup()" class="text-red-500 underline w-full">Kapat</button>
                    </div>
                </div>
            <?php else: ?>
                <p class="text-gray-600">Henüz sistemde anket bulunmamaktadır.</p>
            <?php endif; ?>
        </div>

        <?php if ($loggedInUserRole === 'super-admin'): ?>
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
                                            <button type="submit" class="btn btn-warning text-xs">Güncelle</button>
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
        function showLink(surveyId) {
            const popup = document.getElementById('popup');
            const linkInput = document.getElementById('shareLink');
            const takeSurveyPageFilename = `take-survey-${surveyId}.php`;
            const currentAdminId = <?php echo json_encode($loggedInUserId); ?>;
            
            // dashboard.php'nin bulunduğu dizin /admin/ ise ve take-survey-ID.php dosyaları kök dizindeyse:
            // window.location.origin -> https://psikometrik.net
            // surveyLink -> https://psikometrik.net/take-survey-ID.php?...
            const surveyLink = `${window.location.origin}/${takeSurveyPageFilename}?admin_id=${encodeURIComponent(currentAdminId)}`;
            
            linkInput.value = surveyLink;
            popup.classList.remove('hidden');
        }

        function copyLink() {
            const copyText = document.getElementById('shareLink');
            copyText.select();
            copyText.setSelectionRange(0, 99999); 
            try {
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(copyText.value);
                } else {
                    const successful = document.execCommand('copy');
                    if (!successful) { throw new Error('Fallback copy failed'); }
                }
                showNotification('Link panoya kopyalandı!');
            } catch (err) {
                console.error('Link kopyalanamadı: ', err);
                showNotification('Link kopyalanamadı. Lütfen manuel olarak kopyalayın.', 'error');
            }
        }

        function closePopup() {
            const popup = document.getElementById('popup');
            popup.classList.add('hidden');
        }

        function showNotification(message, type = 'success') {
            alert(message); 
            console.log(`Bildirim (${type}): ${message}`);
        }

        document.addEventListener('DOMContentLoaded', function() {
            const tabButtons = document.querySelectorAll('.tab-button');
            const tabContents = document.querySelectorAll('.tab-content');
            const surveySelect = document.getElementById('survey-select');
            const participantsListDiv = document.getElementById('participants-list');
            const generateReportButton = document.getElementById('generate-report-button');
            
            const loggedInUserRoleJS = <?php echo json_encode($loggedInUserRole); ?>;
            const allParticipantsFromPHPJS = <?php echo json_encode($allParticipants); ?>;

            function escapeHTML(str) {
                if (str === null || typeof str === 'undefined') return '';
                return String(str).replace(/[&<>"']/g, function (match) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[match];
                });
            }

            function displayParticipantsForReport(surveyId) {
                participantsListDiv.innerHTML = ''; 
                if(generateReportButton) generateReportButton.classList.add('hidden'); 

                if (!surveyId) {
                    participantsListDiv.innerHTML = '<p class="text-gray-600">Lütfen yukarıdan bir anket seçin.</p>';
                    return;
                }

                const filteredParticipants = allParticipantsFromPHPJS.filter(participant =>
                    participant.survey_id == surveyId 
                );

                if (filteredParticipants.length > 0) {
                    let tableHtml = `
                        <div class="overflow-x-auto">
                            <table class="min-w-full bg-white shadow-md rounded">
                                <thead>
                                    <tr>
                                        <th class="py-2 px-4 border-b text-center"><input type="checkbox" id="select-all-participants"></th>
                                        <th class="py-2 px-4 border-b text-left">ID</th>
                                        <th class="py-2 px-4 border-b text-left">Ad Soyad</th>
                                        <th class="py-2 px-4 border-b text-left">Sınıf</th>
                                        <th class="py-2 px-4 border-b text-left">Anket</th>`;
                    if (loggedInUserRoleJS === 'super-admin') {
                        tableHtml += `<th class="py-2 px-4 border-b text-left">Atanan Admin ID</th>`;
                    }
                    tableHtml += `      <th class="py-2 px-4 border-b text-center">Bireysel Sonuç</th>
                                    </tr>
                                </thead>
                                <tbody>
                    `;

                    filteredParticipants.forEach(participant => {
                        // dashboard.php ile aynı dizinde olduklarını varsayıyoruz (admin klasörü)
                        const specificResultPageFilename = `view-result-${participant.survey_id}.php`;
                        // JavaScript'te file_exists olmadığı için, ya spesifik sayfanın var olduğunu varsayacağız
                        // ya da her zaman view_result_default.php'ye yönlendireceğiz.
                        // Şimdilik spesifik olana link veriyoruz, bu dosyanın var olması beklenir.
                        // Alternatif olarak, PHP'de olduğu gibi bir default sayfa mantığı sunucuda olmalı.
                        const finalResultPageLink = `${specificResultPageFilename}?id=${escapeHTML(participant.id)}`;


                        tableHtml += `
                            <tr>
                                <td class="py-2 px-4 border-b text-center">
                                    <input type="checkbox" class="participant-checkbox" value="${escapeHTML(participant.id)}">
                                </td>
                                <td class="py-2 px-4 border-b">${escapeHTML(participant.id)}</td>
                                <td class="py-2 px-4 border-b">${escapeHTML(participant.name)}</td>
                                <td class="py-2 px-4 border-b">${escapeHTML(participant.class)}</td>
                                <td class="py-2 px-4 border-b">${escapeHTML(participant.survey_title)}</td>`;
                        if (loggedInUserRoleJS === 'super-admin') {
                             tableHtml += `<td class="py-2 px-4 border-b">${escapeHTML(participant.participant_admin_id || 'N/A')}</td>`;
                        }
                        tableHtml += `  <td class="py-2 px-4 border-b text-center">
                                    <a href="${finalResultPageLink}" class="btn btn-primary inline-block text-xs">
                                        Sonuçları Gör
                                    </a>
                                </td>
                            </tr>
                        `;
                    });

                    tableHtml += `</tbody></table></div>`;
                    participantsListDiv.innerHTML = tableHtml;
                    if(generateReportButton) generateReportButton.classList.remove('hidden');

                    const selectAllCheckbox = document.getElementById('select-all-participants');
                    if(selectAllCheckbox) {
                        selectAllCheckbox.addEventListener('change', function() {
                            const checkboxes = participantsListDiv.querySelectorAll('.participant-checkbox');
                            checkboxes.forEach(checkbox => { checkbox.checked = this.checked; });
                        });
                    }
                } else {
                    participantsListDiv.innerHTML = '<p class="text-gray-600">Bu anket için ' + (loggedInUserRoleJS === 'super-admin' ? 'henüz katılım yok.' : 'size atanmış bir katılım bulunmamaktadır.') + '</p>';
                    if(generateReportButton) generateReportButton.classList.add('hidden'); 
                }
            }

            tabButtons.forEach(button => {
                button.addEventListener('click', () => {
                    tabButtons.forEach(btn => btn.classList.remove('active'));
                    tabContents.forEach(content => content.classList.remove('active'));
                    button.classList.add('active');
                    const targetTab = button.dataset.tab;
                    const targetContent = document.getElementById(`tab-${targetTab}`);
                    if(targetContent) { 
                        targetContent.classList.add('active');
                    }

                    if (targetTab === 'reports' && surveySelect) { 
                         const selectedSurveyId = surveySelect.value;
                         displayParticipantsForReport(selectedSurveyId);
                    } else if (generateReportButton) { 
                        generateReportButton.classList.add('hidden');
                    }
                });
            });

            if(surveySelect){ 
                surveySelect.addEventListener('change', function() {
                    const activeTabButton = document.querySelector('.tab-button.active');
                    if (activeTabButton && activeTabButton.dataset.tab === 'reports') {
                        displayParticipantsForReport(this.value);
                    }
                });
            }

            if(generateReportButton){ 
                generateReportButton.addEventListener('click', function() {
                    const selectedSurveyId = surveySelect.value;
                    const selectedParticipantCheckboxes = participantsListDiv.querySelectorAll('.participant-checkbox:checked');
                    const selectedParticipantIds = Array.from(selectedParticipantCheckboxes).map(checkbox => checkbox.value);

                    if (!selectedSurveyId) { showNotification('Lütfen bir anket seçin.', 'warning'); return; }
                    if (selectedParticipantIds.length === 0) { showNotification('Lütfen rapor oluşturmak için en az bir katılımcı seçin.', 'warning'); return; }
                    redirectToReportPage(selectedSurveyId, selectedParticipantIds);
                });
            }

            function redirectToReportPage(surveyId, participantIds) {
                const participantIdsString = participantIds.join(',');
                // dashboard.php ile aynı dizinde olduğu varsayılan view_report.php
                window.location.href = `view_report.php?survey_id=${surveyId}&participant_ids=${participantIdsString}`;
            }

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
