<?php
session_start();
require_once '../src/config.php'; // Ana dizindeki src klasörüne göre yol

// --- Giriş ve Rol Kontrolü ---
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super-admin'])) {
    header('Location: ../login.php');
    exit();
}
$adminId = $_SESSION['user_id'];
$adminUsername = $_SESSION['username'] ?? 'Admin';
$adminEmail = $_SESSION['user_email'] ?? '';
// --- Bitiş: Giriş ve Rol Kontrolü ---

// --- Mesaj ve Veri Çekme Başlangıç Değerleri ---
$successMessage = null; $errorMessage = null; $logoUploadError = null;
$passwordError = null; $profileError = null; $adminData = null;
// --- Bitiş: Mesaj ve Veri Çekme Başlangıç Değerleri ---

// --- Mevcut Admin Verilerini Çek ---
try {
    $stmtAdmin = $pdo->prepare("SELECT username, email, institution_name, institution_logo_path, password FROM users WHERE id = ?");
    $stmtAdmin->execute([$adminId]);
    $adminData = $stmtAdmin->fetch(PDO::FETCH_ASSOC);
    if (!$adminData) { session_destroy(); header('Location: ../login.php?error=user_not_found'); exit(); }
} catch (PDOException $e) { $errorMessage = "Yönetici bilgileri alınamadı."; error_log("Admin data fetch error for ID $adminId: " . $e->getMessage()); }
// --- Bitiş: Admin Verilerini Çek ---


// --- POST: Kurum Bilgilerini ve Logoyu Güncelle ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_institution'])) {

    // --- YENİ: Güncelleme Öncesi Kontrol ---
    $canUpdateInstitution = true;
    try {
        // Veritabanından güncel bilgileri tekrar çek
        $checkStmt = $pdo->prepare("SELECT institution_name, institution_logo_path FROM users WHERE id = ?");
        $checkStmt->execute([$adminId]);
        $currentInstData = $checkStmt->fetch(PDO::FETCH_ASSOC);

        // Eğer isim VEYA logo yolu veritabanında zaten doluysa, güncellemeyi engelle
        if ($currentInstData && (!empty(trim($currentInstData['institution_name'])) || !empty($currentInstData['institution_logo_path'])) ) {
            $profileError = "Kurum bilgileri ve logo daha önce kaydedildiği için değiştirilemez.";
            $canUpdateInstitution = false; // Güncelleme bayrağını false yap
        }
    } catch (PDOException $e) {
         $profileError = "Mevcut kurum bilgileri kontrol edilirken hata oluştu.";
         error_log("Institution check error: " . $e->getMessage());
         $canUpdateInstitution = false; // Hata olursa güncellemeyi engelle
    }
    // --- Bitiş: Güncelleme Öncesi Kontrol ---


    // --- Sadece güncelleme yapılabiliyorsa devam et ---
    if ($canUpdateInstitution) {
        $institutionName = trim($_POST['institution_name'] ?? '');
        // Logo yolu başlangıçta null (yeni yüklenecekse)
        $logoPath = null;

        // Logo Yükleme Mantığı
        if (isset($_FILES['institution_logo']) && $_FILES['institution_logo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['institution_logo']; $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
            $maxSize = 2 * 1024 * 1024; $uploadDir = '../uploads/logos/';

            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) { $logoUploadError = "Logo klasörü oluşturulamadı/yazılamıyor."; }
            elseif (!in_array(mime_content_type($file['tmp_name']), $allowedTypes)) { $logoUploadError = "Geçersiz dosya türü (JPG, PNG, GIF)."; }
            elseif ($file['size'] > $maxSize) { $logoUploadError = "Logo dosyası çok büyük (Maks 2MB)."; }
            else {
                $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                $safeFilename = $adminId . '_' . time() . '.' . strtolower($extension);
                $destination = $uploadDir . $safeFilename;

                // ÖNEMLİ: İlk kayıtta eski logo olmayacağı için silme işlemi GEREKMEZ.
                // if (!empty($logoPath) && file_exists('../' . $logoPath)) { unlink('../' . $logoPath); }

                if (move_uploaded_file($file['tmp_name'], $destination)) {
                    $logoPath = 'uploads/logos/' . $safeFilename; // DB'ye kaydedilecek yol
                } else { $logoUploadError = "Logo yüklenirken sunucu hatası."; $logoPath = null; }
            }
        } elseif (isset($_FILES['institution_logo']) && $_FILES['institution_logo']['error'] !== UPLOAD_ERR_NO_FILE) { $logoUploadError = "Logo yüklenirken hata (Kod: " . $_FILES['institution_logo']['error'] . ")"; }
        // --- Bitiş Logo Yükleme ---

        // Veritabanını Güncelle (Logo hatası yoksa ve güncelleme hala mümkünse)
        if (is_null($logoUploadError)) {
             // Sadece boş olan alanları ilk defa dolduruyoruz gibi düşünebiliriz.
             // İsim ve logo YOLU birlikte güncellenir.
             // Eğer kullanıcı sadece isim girip logo yüklemediyse logoPath null olur.
             // Eğer kullanıcı sadece logo yükleyip isim girmediyse institutionName boş olur.
             // Kullanıcının her ikisini de ilk seferde girmesi beklenir.
            if (!empty($institutionName) || !empty($logoPath)) { // En az biri doluysa güncelle
                try {
                    $updateStmt = $pdo->prepare("UPDATE users SET institution_name = ?, institution_logo_path = ? WHERE id = ?");
                    if ($updateStmt->execute([$institutionName, $logoPath, $adminId])) {
                        header("Location: dashboard.php?status=inst_updated"); exit();
                    } else { $profileError = "Kurum bilgileri güncellenemedi."; }
                } catch (PDOException $e) { $profileError = "Veritabanı hatası (Kurum): " . $e->getMessage(); error_log("Institution update error: " . $e->getMessage());}
            } else {
                 // Kullanıcı formu boş gönderdiyse bir şey yapma veya uyarı ver
                 $profileError = "Güncellemek için Kurum Adı veya Logo girmelisiniz.";
            }
        } else {
            $profileError = $logoUploadError; // Logo hatasını göster
        }
    } // End if($canUpdateInstitution)
}
// --- Bitiş POST: Kurum Bilgileri ---


// --- POST: Şifre Değiştir ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    // ... (Şifre değiştirme kodu öncekiyle aynı kalabilir) ...
    $currentPassword = $_POST['current_password'] ?? ''; $newPassword = $_POST['new_password'] ?? ''; $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) { $passwordError = "Tüm şifre alanlarını doldurun."; }
    elseif ($newPassword !== $confirmPassword) { $passwordError = "Yeni şifreler uyuşmuyor."; }
    elseif (strlen($newPassword) < 6) { $passwordError = "Yeni şifre en az 6 karakter olmalıdır."; }
    elseif (!password_verify($currentPassword, $adminData['password'])) { $passwordError = "Mevcut şifreniz yanlış."; }
    else {
        try {
            $newPasswordHash = password_hash($newPassword, PASSWORD_BCRYPT);
            $updatePassStmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            if ($updatePassStmt->execute([$newPasswordHash, $adminId])) {
                 header("Location: dashboard.php?status=pw_updated"); exit();
            } else { $passwordError = "Şifre güncellenirken bir hata oluştu."; }
        } catch (PDOException $e) { $passwordError = "Veritabanı hatası (Şifre): " . $e->getMessage(); error_log("Password change error: " . $e->getMessage()); }
    }
}
// --- Bitiş POST: Şifre Değiştir ---


// --- Katılımcıları ve Anketleri Çek (Mevcut Kod) ---
// ... (Kodun geri kalanı aynı) ...
$participants = []; $surveys = [];
if (is_null($errorMessage) && is_null($profileError) && is_null($passwordError)) {
    try {
        $participantsStmt = $pdo->prepare("SELECT sp.id, sp.name, sp.class, s.title AS survey_title, s.id AS survey_id FROM survey_participants sp JOIN surveys s ON sp.survey_id = s.id WHERE sp.admin_id = ? ORDER BY sp.id DESC");
        $participantsStmt->execute([$adminId]);
        $participants = $participantsStmt->fetchAll(PDO::FETCH_ASSOC);

        $surveysStmt = $pdo->query("SELECT id, title, description FROM surveys ORDER BY id ASC");
        $surveys = $surveysStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $errorMessage = "Katılımcı veya anket listesi alınamadı.";
        error_log("Dashboard data fetch error: " . $e->getMessage());
    }
}
$totalQuestionsPlaceholder = "N/A"; $averageCompletionTimeNote = "Hesaplanamadı";
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Paneli | Psikometrik.Net</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Stillendirme aynı kalabilir */
        body { font-family: sans-serif; line-height: 1.6; background-color: #f1f5f9; color: #334155; }
        nav { background-color: #ffffff; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05); padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; }
        .logo-area { display: flex; align-items: center; gap: 0.75rem; }
        .logo-area img { height: 3rem; width: auto; }
        .nav-links button, .nav-links a, .nav-links span { margin-left: 1rem; }
        .user-button { background: none; border: none; cursor: pointer; display: inline-flex; align-items: center; font-weight: 500;}
        .user-button:hover { color: #0ea5e9; }
        .btn { padding: 0.6rem 1.25rem; border-radius: 0.375rem; color: white; font-weight: 500; transition: background-color 0.2s ease-in-out; display: inline-block; text-align: center; text-decoration: none; border: none; cursor: pointer;}
        .btn-primary { background-color: #3b82f6; } .btn-primary:hover { background-color: #2563eb; }
        .btn-success { background-color: #16a34a; } .btn-success:hover { background-color: #15803d; }
        .btn-danger { background-color: #dc2626; } .btn-danger:hover { background-color: #b91c1c; }
        .btn-sm { padding: 0.4rem 1rem; font-size: 0.875rem;}
        table { width: 100%; border-collapse: collapse; margin-top: 1.5rem; background-color: white; border-radius: 0.5rem; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.08);}
        th, td { text-align: left; padding: 0.8rem 1rem; border-bottom: 1px solid #e5e7eb; }
        th { background-color: #f9fafb; font-weight: 600; color: #4b5563; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.05em;}
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background-color: #f8fafc; }
        .survey-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1.5rem; }
        .survey-card { background-color: #ffffff; padding: 1.5rem; border-radius: 0.5rem; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08); transition: box-shadow 0.3s ease-in-out; border: 1px solid #e2e8f0; display: flex; flex-direction: column; justify-content: space-between; }
        .survey-card:hover { box-shadow: 0 5px 10px rgba(0, 0, 0, 0.1); }
        .survey-card h2 { font-size: 1.1rem; font-weight: 600; margin-bottom: 0.5rem; color: #1f2937; }
        .survey-card p { color: #4b5563; margin-bottom: 1rem; line-height: 1.5; flex-grow: 1; font-size: 0.9rem;}
        .card-actions { margin-top: auto; text-align: right;}
        #popup { /* Stiller aynı */ }
        .popup-overlay { background-color: rgba(0, 0, 0, 0.7); }
        #popup > div { max-width: 500px; width: 90%; }
        #shareLink { background-color: #f1f5f9; cursor: text; color: #334155; }
        #popup button { /* Stiller aynı */ }
        .form-label { display: block; margin-bottom: 0.5rem; font-weight: 500; color: #374151; font-size: 0.9rem;}
        .form-input { display: block; width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #d1d5db; border-radius: 0.375rem; box-shadow: inset 0 1px 2px rgba(0,0,0,0.05); transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out; font-size: 0.9rem;}
        .form-input:focus { outline: none; border-color: #16a34a; box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.2); }
        /* Dosya input stili */
        input[type="file"].form-input { padding: 0.3rem 0.75rem; /* Padding dosya tipi için ayarlandı */ }
        .feedback-success { background-color: #dcfce7; color: #166534; border: 1px solid #a7f3d0; padding: 0.75rem 1rem; border-radius: 0.375rem; margin-bottom: 1.5rem; font-size: 0.9rem;}
        .feedback-error { background-color: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; padding: 0.75rem 1rem; border-radius: 0.375rem; margin-bottom: 1.5rem; font-size: 0.9rem;}
        .mx-auto { margin-left: auto; margin-right: auto; }
        .container { width: 100%; max-width: 1200px; }
        .p-4 { padding: 1rem; } .p-6 { padding: 1.5rem; }
        .mb-6 { margin-bottom: 1.5rem; } .mb-4 { margin-bottom: 1rem; }
        .text-2xl { font-size: 1.5rem; } .text-xl { font-size: 1.25rem; } .text-lg { font-size: 1.125rem; }
        .font-bold { font-weight: 700; } .font-semibold { font-weight: 600; }
        .mt-8 { margin-top: 2rem; }
        .mr-4 { margin-right: 1rem; }
        .text-gray-700 { color: #374151; } .text-gray-800 { color: #1f2937; } .text-gray-600 { color: #4b5563; } .text-gray-500 { color: #6b7280; }
        .min-h-screen { min-height: 100vh; }
        .shadow-md { box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06); }
        .rounded-lg { border-radius: 0.5rem; }
        .border-b { border-bottom-width: 1px; } .pb-2 { padding-bottom: 0.5rem; }
        .text-center { text-align: center; } .text-left { text-align: left; }
        .underline { text-decoration: underline; }
        .fixed { position: fixed; } .inset-0 { top: 0; right: 0; bottom: 0; left: 0; }
        .z-50 { z-index: 50; }
        .hidden { display: none; }
        .w-full { width: 100%; }
        .mb-2 { margin-bottom: 0.5rem; }
        .overflow-x-auto { overflow-x: auto; }
        .block { display: block; }
        .inline-block { display: inline-block; } .inline-flex { display: inline-flex; } .items-center { align-items: center; }
        .mt-1 { margin-top: 0.25rem; } .mt-2 { margin-top: 0.5rem; } .mt-auto { margin-top: auto; }
        .text-sm { font-size: 0.875rem; } .text-xs { font-size: 0.75rem; }
        .h-16 { height: 4rem; } .w-auto { width: auto; }
        .border { border-width: 1px; } .p-1 { padding: 0.25rem; } .border-gray-300 { border-color: #d1d5db; }
        .gap-6 { gap: 1.5rem; } .gap-8 { gap: 2rem; }
        .space-y-6 > :not([hidden]) ~ :not([hidden]) { margin-top: 1.5rem; } /* space-y-6 */
        .space-y-8 > :not([hidden]) ~ :not([hidden]) { margin-top: 2rem; } /* space-y-8 */
        .lg\:col-span-1 { grid-column: span 1 / span 1; }
        .lg\:col-span-2 { grid-column: span 2 / span 2; }
        .lg\:col-span-3 { grid-column: span 3 / span 3; } /* Eklendi */
        .lg\:grid-cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .file\:mr-4 { margin-right: 1rem; } .file\:py-2 { padding-top: 0.5rem; padding-bottom: 0.5rem; }
        .file\:px-4 { padding-left: 1rem; padding-right: 1rem; } .file\:rounded-full { border-radius: 9999px; }
        .file\:border-0 { border-width: 0; } .file\:text-sm { font-size: 0.875rem; }
        .file\:font-semibold { font-weight: 600; } .file\:bg-blue-50 { background-color: #eff6ff; }
        .file\:text-blue-700 { color: #1d4ed8; } .hover\:file\:bg-blue-100:hover { background-color: #dbeafe; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <nav>
        <div class="logo-area">
            <a href="../index.php"><img src="/assets/Psikometrik.png" alt="Psikometrik.Net Logo"></a>
        </div>
        <div class="nav-links flex items-center"> <?php if (isset($_SESSION['user_id']) && $adminData): // $adminData kontrolü eklendi ?>
                <button type="button" id="profilAyarlariButonu" class="user-button text-gray-700 mr-4">
                    👤 <?php echo htmlspecialchars($adminData['username']); // $adminUsername yerine $adminData kullanıldı ?>
                </button>
                <a href="../logout.php" class="btn btn-danger btn-sm">Çıkış</a>
            <?php endif; ?>
        </div>
    </nav>

    <main class="container mx-auto p-4 mt-8">
        <h1 class="text-2xl font-bold mb-6 text-gray-800">Admin Paneli</h1>

        <?php
            if(isset($_GET['status'])) {
                if($_GET['status'] === 'inst_updated') echo '<div class="feedback-success">Kurum bilgileri başarıyla güncellendi.</div>';
                elseif($_GET['status'] === 'pw_updated') echo '<div class="feedback-success">Şifre başarıyla değiştirildi.</div>';
            }
            if (!empty($errorMessage))   echo '<div class="feedback-error">' . htmlspecialchars($errorMessage) . '</div>';
            if (!empty($profileError))   echo '<div class="feedback-error">' . htmlspecialchars($profileError) . '</div>';
            if (!empty($passwordError))  echo '<div class="feedback-error">' . htmlspecialchars($passwordError) . '</div>';
         ?>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <div id="profilAyarlariPaneli" class="lg:col-span-1 space-y-6 hidden">
                <section class="bg-white p-6 rounded-lg shadow-md">
                    <h2 class="text-lg font-semibold mb-4 text-gray-700 border-b pb-2">Kurum Bilgileri</h2>
                     <form method="POST" enctype="multipart/form-data" action="dashboard.php">
                        <input type="hidden" name="update_institution" value="1">
                        <div class="mb-4">
                            <label for="institution_name" class="form-label">Kurum Adı:</label>
                            <input type="text" name="institution_name" id="institution_name" value="<?= htmlspecialchars($adminData['institution_name'] ?? '') ?>" class="form-input mt-1">
                        </div>
                        <div class="mb-4">
                            <label for="institution_logo" class="form-label">Kurum Logosu (Max 2MB - PNG, JPG, GIF):</label>
                            <input type="file" name="institution_logo" id="institution_logo" accept="image/png, image/jpeg, image/gif" class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                            <?php if (!empty($adminData['institution_logo_path']) && file_exists('../' . $adminData['institution_logo_path'])): ?>
                                <div class="mt-4">
                                    <p class="text-xs font-medium text-gray-500 mb-1">Mevcut Logo:</p>
                                    <img src="../<?= htmlspecialchars($adminData['institution_logo_path']) ?>?t=<?= time() ?>" alt="Kurum Logosu" class="h-16 w-auto border p-1 rounded bg-gray-50">
                                </div>
                             <?php elseif (!empty($adminData['institution_logo_path'])): ?>
                                 <p class="text-xs text-red-500 mt-2">Logo dosyası bulunamadı.</p>
                            <?php endif; ?>
                        </div>
                        <button type="submit" class="btn btn-success w-full">Bilgileri Kaydet</button>
                         <p class="text-xs text-gray-500 mt-3">*Kurum bilgileri ve logo sadece bir kez kaydedilebilir ve sonrasında değiştirilemez.</p>
                    </form>
                </section>

                <section class="bg-white p-6 rounded-lg shadow-md">
                    <h2 class="text-lg font-semibold mb-4 text-gray-700 border-b pb-2">Şifre Değiştir</h2>
                     <form method="POST" action="dashboard.php">
                         <input type="hidden" name="change_password" value="1">
                        <div class="mb-4">
                            <label for="current_password" class="form-label">Mevcut Şifre:</label>
                            <input type="password" name="current_password" id="current_password" required class="form-input mt-1">
                        </div>
                        <div class="mb-4">
                            <label for="new_password" class="form-label">Yeni Şifre (En az 6 karakter):</label>
                            <input type="password" name="new_password" id="new_password" required minlength="6" class="form-input mt-1">
                        </div>
                        <div class="mb-4">
                            <label for="confirm_password" class="form-label">Yeni Şifre (Tekrar):</label>
                            <input type="password" name="confirm_password" id="confirm_password" required minlength="6" class="form-input mt-1">
                        </div>
                        <button type="submit" class="btn btn-primary w-full">Şifreyi Değiştir</button>
                    </form>
                </section>
            </div>
            <div id="mainContentArea" class="lg:col-span-3 space-y-8">
                <section class="bg-white p-6 rounded-lg shadow-md">
                    <h2 class="text-xl font-semibold mb-4 text-gray-700 border-b pb-2">📋 Öğrenci Anket/Test Katılımları</h2>
                    <?php if (count($participants) > 0): ?>
                      <div class="overflow-x-auto">
                        <table class="min-w-full">
                           <thead>
                                <tr>
                                    <th class="py-2 px-4 border-b text-left text-sm">Ad Soyad</th>
                                    <th class="py-2 px-4 border-b text-left text-sm">Sınıf</th>
                                    <th class="py-2 px-4 border-b text-left text-sm">Anket/Test</th>
                                    <th class="py-2 px-4 border-b text-center text-sm">İşlem</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($participants as $participant): ?>
                                    <tr>
                                        <td class="py-2 px-4 border-b text-sm"><?= htmlspecialchars($participant['name']) ?></td>
                                        <td class="py-2 px-4 border-b text-sm"><?= htmlspecialchars($participant['class']) ?></td>
                                        <td class="py-2 px-4 border-b text-sm"><?= htmlspecialchars($participant['survey_title']) ?></td>
                                        <td class="py-2 px-4 border-b text-center">
                                            <?php
                                                $resultViewFile = "view-result-" . $participant['survey_id'] . ".php";
                                                if (!file_exists(__DIR__ . '/' . $resultViewFile)) { $resultViewFile = "view-result.php"; }
                                            ?>
                                            <a href="<?= $resultViewFile ?>?id=<?= $participant['id'] ?>" class="btn btn-primary btn-sm inline-block">Sonuç Gör</a>
                                            
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                      </div>
                    <?php else: ?>
                        <p class="text-gray-600 text-sm">Henüz bu yöneticiye ait katılım bulunmuyor.</p>
                    <?php endif; ?>
                </section>

                <section class="bg-white p-6 rounded-lg shadow-md">
                    <h2 class="text-xl font-semibold mb-4 text-gray-700 border-b pb-2">📝 Anketleri/Testleri Uygula</h2>
                    <?php if (count($surveys) > 0): ?>
                        <div class="survey-grid">
                            <?php foreach ($surveys as $survey): ?>
                                <div class="survey-card" data-survey-id="<?= $survey['id'] ?>">
                                    <h2><?= htmlspecialchars($survey['title']) ?></h2>
                                    <p><?= nl2br(htmlspecialchars($survey['description'] ?? 'Açıklama yok.')) ?></p>
                                    <div class="card-actions">
                                        <button type="button" class="btn btn-success btn-sm" onclick="showLink(<?= $survey['id'] ?>)">Paylaşım Linki</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-gray-600 text-sm">Sistemde anket/test bulunamadı.</p>
                    <?php endif; ?>
                </section>
            </div> </div> </main>

    <div id="popup" class="hidden fixed inset-0 flex items-center justify-center popup-overlay z-50 bg-black bg-opacity-50" onclick="closePopup(event)">
        <div class="bg-white p-6 md:p-8 rounded-lg shadow-md text-center max-w-md w-11/12" onclick="event.stopPropagation()">
            <h3 class="text-lg font-bold mb-4 text-gray-800">Paylaşım Linki</h3>
             <p class="text-sm text-gray-600 mb-3">Bu linki kopyalayıp katılımcılarınızla paylaşabilirsiniz.</p>
            <input type="text" id="shareLink" readonly class="w-full p-2 border rounded mb-4 form-input bg-gray-100 text-sm">
            <button onclick="copyLink()" class="btn btn-success w-full mb-2">
                 <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 inline-block mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
                 Kopyala
            </button>
            <button onclick="closePopup()" class="text-xs text-gray-500 hover:underline mt-2">Kapat</button>
        </div>
    </div>

    <script>
        const profilAyarlariButonu = document.getElementById('profilAyarlariButonu');
        const profilAyarlariPaneli = document.getElementById('profilAyarlariPaneli');
        const mainContentArea = document.getElementById('mainContentArea');

        // Profil Ayarları Panelini Aç/Kapat
        if (profilAyarlariButonu && profilAyarlariPaneli && mainContentArea) {
            profilAyarlariButonu.addEventListener('click', () => {
                const isHidden = profilAyarlariPaneli.classList.toggle('hidden');
                if (isHidden) {
                    mainContentArea.classList.remove('lg:col-span-2');
                    mainContentArea.classList.add('lg:col-span-3');
                } else {
                    mainContentArea.classList.remove('lg:col-span-3');
                    mainContentArea.classList.add('lg:col-span-2');
                }
            });
        }

        // Popup Link Fonksiyonları
        function showLink(surveyId) {
            const popup = document.getElementById('popup');
            const linkInput = document.getElementById('shareLink');
            let takeSurveyPage = '';
            // Test ID'lerine göre özel dosya adlarını belirle
             if ([4, 5, 6, 7, 8, 9, 11, 12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29].includes(surveyId)) { // 10 hariç (çünkü o test)
                 takeSurveyPage = `take-survey-${surveyId}.php`;
             } else if (surveyId === 10) { // Burdon testi için
                 takeSurveyPage = `take-test-${surveyId}.php`;
             } else {
                 takeSurveyPage = `take-survey.php?id=${surveyId}`; // Genel fallback
             }

            const adminId = <?php echo json_encode($adminId); ?>;
             // Linki oluştururken ana dizin yapısını varsayalım (../ gerekli değil)
             // Eğer uygulama bir alt klasördeyse (örn: /testanket1), onu buraya ekleyin:
             // const surveyLink = `${window.location.origin}/testanket1/${takeSurveyPage}?admin_id=${encodeURIComponent(adminId)}`;
             const surveyLink = `${window.location.origin}/${takeSurveyPage}?admin_id=${encodeURIComponent(adminId)}`; // Ana dizin varsayımı

            linkInput.value = surveyLink;
            if(popup) popup.classList.remove('hidden');
        }

        function copyLink() { /* Kopyalama kodu aynı */
             const copyText = document.getElementById('shareLink'); copyText.select(); copyText.setSelectionRange(0, 99999);
             try { navigator.clipboard.writeText(copyText.value).then(() => { alert('Link kopyalandı!'); }).catch(err => { if (document.execCommand('copy')) { alert('Link kopyalandı! (Fallback)'); } else { alert('Link otomatik kopyalanamadı.'); } }); } catch (err) { alert('Link kopyalanamadı.'); }
         }
        function closePopup(event = null) { /* Kapatma kodu aynı */
             const popup = document.getElementById('popup'); if (event && event.target !== popup) { return; } if(popup) popup.classList.add('hidden');
         }
         const popupOverlay = document.getElementById('popup'); if (popupOverlay) { popupOverlay.addEventListener('click', closePopup); }

    </script>

</body>
</html>