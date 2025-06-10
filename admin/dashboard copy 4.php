<?php
session_start();
// config.php dosyasının yolu projenizin yapısına göre yol
require_once '../src/config.php';

// --- Giriş ve Rol Kontrolü ---
// Sadece admin ve super-admin giriş yapabilir. old_user da dashboard'a erişebilir ama kısıtlı.
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super-admin', 'old_user'])) {
    header('Location: ../login.php');
    exit();
}

$adminId = $_SESSION['user_id'];
$adminUsername = $_SESSION['username'] ?? 'Admin';
$adminEmail = $_SESSION['user_email'] ?? ''; // Oturumda e-posta saklanıyorsa
$adminRole = $_SESSION['role']; // Oturumda rol saklanıyorsa

// --- Mesaj ve Veri Çekme Başlangıç Değerleri ---
$successMessage = null; $errorMessage = null; $logoUploadError = null;
$passwordError = null; $profileError = null; $adminData = null;
$usersList = []; // Kullanıcı listesi için
// --- Bitiş: Mesaj ve Veri Çekme Başlangıç Değerleri ---

// --- Mevcut Admin Verilerini Çek ---
try {
    // duration_days ve last_duration_check sütunlarını da çekiyoruz
    $stmtAdmin = $pdo->prepare("SELECT username, full_name, email, institution_name, institution_logo_path, password, role, duration_days, last_duration_check FROM users WHERE id = ?");
    $stmtAdmin->execute([$adminId]);
    $adminData = $stmtAdmin->fetch(PDO::FETCH_ASSOC);
    if (!$adminData) {
        // Eğer yönetici bilgileri çekilemezse oturumu sonlandır ve giriş sayfasına yönlendir
        session_destroy();
        header('Location: ../login.php?error=user_not_found');
        exit();
    }
    // Oturumdaki rolü veritabanındaki ile senkronize et (iyi bir pratik)
    $_SESSION['role'] = $adminData['role'];
    $adminRole = $adminData['role']; // Güncel rolü değişkene ata

} catch (PDOException $e) {
    $errorMessage = "Yönetici bilgileri alınamadı.";
    error_log("Admin data fetch error for ID $adminId: " . $e->getMessage());
}
// --- Bitiş: Admin Verilerini Çek ---

// --- SÜRE AZALTMA VE ROL DEĞİŞTİRME MANTIĞI (Sadece Admin Rolü İçin) ---
// Bu kontrol her sayfa yüklendiğinde yapılır
if ($adminRole === 'admin' && isset($adminData['duration_days'])) {
    $currentDuration = (int)($adminData['duration_days'] ?? 0);
    $lastCheckDate = $adminData['last_duration_check'];
    $today = date('Y-m-d');

    // Süre 0'dan büyükse ve son kontrol tarihi varsa
    if ($currentDuration > 0 && !empty($lastCheckDate)) {
        $lastCheckTimestamp = strtotime($lastCheckDate);
        $todayTimestamp = strtotime($today);

        // Son kontrolden bu yana geçen gün sayısını hesapla
        $daysPassed = floor(($todayTimestamp - $lastCheckTimestamp) / (60 * 60 * 24));

        // Eğer gün geçtiyse süreyi azalt
        if ($daysPassed > 0) {
            $newDuration = max(0, $currentDuration - $daysPassed); // Süre 0'ın altına düşmemeli

            try {
                // Süreyi ve son kontrol tarihini güncelle
                $updateDurationStmt = $pdo->prepare("UPDATE users SET duration_days = ?, last_duration_check = ? WHERE id = ?");
                $updateDurationStmt->execute([$newDuration, $today, $adminId]);

                // Eğer yeni süre 0 olduysa rolü 'old_user' olarak değiştir
                if ($newDuration <= 0) {
                    $updateRoleStmt = $pdo->prepare("UPDATE users SET role = 'old_user' WHERE id = ?");
                    $updateRoleStmt->execute([$adminId]);
                    $_SESSION['role'] = 'old_user'; // Oturumdaki rolü de güncelle
                    $adminRole = 'old_user'; // Değişkeni de güncelle
                    // Kullanıcıya bilgi mesajı gösterilebilir
                    $successMessage = "Süreniz doldu. Rolünüz 'old_user' olarak güncellendi.";
                } else {
                     // Süre güncellendi mesajı (isteğe bağlı)
                     // $successMessage = "Kalan süreniz güncellendi.";
                }

                // Güncel admin verilerini tekrar çek (süreyi doğru göstermek için)
                 $stmtAdmin = $pdo->prepare("SELECT username, full_name, email, institution_name, institution_logo_path, password, role, duration_days, last_duration_check FROM users WHERE id = ?");
                 $stmtAdmin->execute([$adminId]);
                 $adminData = $stmtAdmin->fetch(PDO::FETCH_ASSOC);


            } catch (PDOException $e) {
                error_log("Duration update/role change error for user ID $adminId: " . $e->getMessage());
                // Kullanıcıya görünür bir hata mesajı göstermeyebiliriz, arka planda halledilir
            }
        }
    } elseif ($currentDuration > 0 && empty($lastCheckDate)) {
        // Eğer süre 0'dan büyük ama son kontrol tarihi boşsa, ilk kontrolü yap
        try {
             $updateCheckDateStmt = $pdo->prepare("UPDATE users SET last_duration_check = ? WHERE id = ?");
             $updateCheckDateStmt->execute([$today, $adminId]);
             // Güncel admin verilerini tekrar çek
             $stmtAdmin = $pdo->prepare("SELECT username, full_name, email, institution_name, institution_logo_path, password, role, duration_days, last_duration_check FROM users WHERE id = ?");
             $stmtAdmin->execute([$adminId]);
             $adminData = $stmtAdmin->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
             error_log("Initial last_duration_check update error for user ID $adminId: " . $e->getMessage());
        }
    } elseif ($currentDuration <= 0 && $adminRole !== 'old_user') {
        // Süre 0 veya altındaysa ve rol henüz old_user değilse, rolü old_user yap
         try {
             $updateRoleStmt = $pdo->prepare("UPDATE users SET role = 'old_user' WHERE id = ?");
             $updateRoleStmt->execute([$adminId]);
             $_SESSION['role'] = 'old_user'; // Oturumdaki rolü de güncelle
             $adminRole = 'old_user'; // Değişkeni de güncelle
             $successMessage = "Süreniz doldu. Rolünüz 'old_user' olarak güncellendi.";
             // Güncel admin verilerini tekrar çek
             $stmtAdmin = $pdo->prepare("SELECT username, full_name, email, institution_name, institution_logo_path, password, role, duration_days, last_duration_check FROM users WHERE id = ?");
             $stmtAdmin->execute([$adminId]);
             $adminData = $stmtAdmin->fetch(PDO::FETCH_ASSOC);
         } catch (PDOException $e) {
             error_log("Role change to old_user error for user ID $adminId: " . $e->getMessage());
         }
    }
}
// --- Bitiş: SÜRE AZALTMA VE ROL DEĞİŞTİRME MANTIĞI ---


// --- POST: Kurum Bilgilerini ve Logoyu Güncelle ---
// Bu bölüm admin ve super-admin için geçerli kalır. old_user bu yetkiye sahip değil.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_institution'])) {

    // Sadece admin veya super-admin kurum bilgisi güncelleyebilir
    if (!in_array($adminRole, ['admin', 'super-admin'])) {
         $profileError = "Bu işlemi yapma yetkiniz yok.";
    } else {
        // --- YENİ: Güncelleme Öncesi Kontrol ---
        $canUpdateInstitution = true;
        try {
            // Veritabanından güncel bilgileri tekrar çek
            $checkStmt = $pdo->prepare("SELECT institution_name, institution_logo_path FROM users WHERE id = ?");
            $checkStmt->execute([$adminId]);
            $currentInstData = $checkStmt->fetch(PDO::FETCH_ASSOC);

            // Eğer isim VEYA logo yolu veritabanında zaten doluysa, güncellemeyi engelle
            // Bu kontrol, ilk kayıttan sonra bir daha değiştirilememesini sağlar
            if ($currentInstData && (!empty(trim($currentInstData['institution_name'])) || !empty($currentInstData['institution_logo_path'])) ) {
                 // Ancak super-admin'in kendi kurum bilgisini güncellemesine izin verilebilir
                 // Şu anki mantıkta ilk kayıttan sonra kimse değiştiremiyor.
                 // İhtiyaca göre burası güncellenebilir.
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
            $logoPath = $adminData['institution_logo_path']; // Mevcut logoyu koru varsayılan olarak

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

                    // Eski logoyu sil (varsa ve yeni logo yüklendiyse)
                    if (!empty($adminData['institution_logo_path']) && file_exists('../' . $adminData['institution_logo_path'])) {
                         unlink('../' . $adminData['institution_logo_path']);
                    }

                    if (move_uploaded_file($file['tmp_name'], $destination)) {
                        $logoPath = 'uploads/logos/' . $safeFilename; // DB'ye kaydedilecek yol
                    } else { $logoUploadError = "Logo yüklenirken sunucu hatası."; $logoPath = $adminData['institution_logo_path']; } // Hata olursa eski yolu koru
                }
            } elseif (isset($_FILES['institution_logo']) && $_FILES['institution_logo']['error'] !== UPLOAD_ERR_NO_FILE) {
                 // Dosya yükleme hatası (boyut, tür vb.)
                 $logoUploadError = "Logo yüklenirken hata oluştu. Lütfen dosya boyutunu ve türünü kontrol edin. (Kod: " . $_FILES['institution_logo']['error'] . ")";
                 $logoPath = $adminData['institution_logo_path']; // Hata olsa da mevcut yolu koru
            } else {
                // Dosya yüklenmediyse mevcut logoyu koru
                $logoPath = $adminData['institution_logo_path'];
            }
            // --- Bitiş Logo Yükleme ---

            // Veritabanını Güncelle (Logo hatası yoksa ve güncelleme hala mümkünse)
            if (is_null($logoUploadError)) {
                 // İsim ve logo YOLU birlikte güncellenir.
                if (!empty($institutionName) || !empty($logoPath)) { // En az biri doluysa güncelle
                    try {
                        // institution_name ve institution_logo_path sütunlarını güncelle
                        $updateStmt = $pdo->prepare("UPDATE users SET institution_name = ?, institution_logo_path = ? WHERE id = ?");
                        if ($updateStmt->execute([$institutionName, $logoPath, $adminId])) {
                             // Başarılı güncelleme sonrası sayfayı yenile ve mesaj göster
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
    } // End if (!in_array($adminRole, ['admin', 'super-admin'])) else
}
// --- Bitiş POST: Kurum Bilgileri ---


// --- POST: Şifre Değiştir ---
// Bu bölüm admin ve super-admin için geçerli kalır. old_user bu yetkiye sahip değil.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    // Sadece admin veya super-admin şifre değiştirebilir
    if (!in_array($adminRole, ['admin', 'super-admin'])) {
         $passwordError = "Bu işlemi yapma yetkiniz yok.";
    } else {
        // ... (Mevcut Şifre Değiştirme Kodu - Aynı Kaldı) ...
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) { $passwordError = "Tüm şifre alanlarını doldurun."; }
        elseif ($newPassword !== $confirmPassword) { $passwordError = "Yeni şifreler uyuşmuyor."; }
        elseif (strlen($newPassword) < 6) { $passwordError = "Yeni şifre en az 6 karakter olmalıdır."; }
        // Mevcut şifreyi veritabanındaki hashlenmiş şifre ile doğrula
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
    } // End if (!in_array($adminRole, ['admin', 'super-admin'])) else
}
// --- Bitiş POST: Şifre Değiştir ---

// --- POST: Kullanıcı Ekle (Sadece Super Admin) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    // Sadece super-admin bu işlemi yapabilir
    if ($adminRole !== 'super-admin') {
        $errorMessage = "Bu işlemi yapma yetkiniz yok.";
    } else {
        $username = trim($_POST['new_username'] ?? '');
        $full_name = trim($_POST['new_full_name'] ?? '');
        $email = trim($_POST['new_email'] ?? '');
        $password = $_POST['new_password'] ?? '';
        $institution_name = trim($_POST['new_institution_name'] ?? '');
        $role = trim($_POST['new_role'] ?? 'user'); // Varsayılan rol 'user'
        $duration_days = filter_input(INPUT_POST, 'new_duration_days', FILTER_VALIDATE_INT);

        // duration_days boş gelebilir, bu durumda NULL olarak kaydedeceğiz
        $duration_days_value = ($duration_days === false && ($_POST['new_duration_days'] === '' || $_POST['new_duration_days'] === null)) ? null : $duration_days;

        // Eğer süre girildiyse, last_duration_check'i de bugünün tarihi yap
        $last_duration_check_value = ($duration_days_value !== null && $duration_days_value > 0) ? date('Y-m-d') : null;


        if (empty($username) || empty($full_name) || empty($email) || empty($password) || empty($institution_name) || empty($role)) {
            $errorMessage = "Yeni kullanıcı için tüm zorunlu alanları doldurun.";
        } else {
             // Geçerli rol değerlerini kontrol et
            $allowedRoles = ['user', 'admin', 'super-admin', 'old_user'];
            if (!in_array($role, $allowedRoles)) {
                $errorMessage = "Geçersiz rol değeri.";
            } else {
                try {
                    // E-posta zaten var mı kontrol et
                    $stmtCheckEmail = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                    $stmtCheckEmail->execute([$email]);
                    if ($stmtCheckEmail->rowCount() > 0) {
                        $errorMessage = "Bu e-posta adresi zaten kullanılıyor.";
                    } else {
                        // Şifreyi hashle
                        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

                        // Kullanıcıyı ekle
                        // last_duration_check sütununu INSERT sorgusuna ekledik
                        $stmtAddUser = $pdo->prepare("INSERT INTO users (username, full_name, email, password, institution_name, role, duration_days, last_duration_check) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        if ($stmtAddUser->execute([$username, $full_name, $email, $hashedPassword, $institution_name, $role, $duration_days_value, $last_duration_check_value])) {
                            header("Location: dashboard.php?status=user_added&tab=users"); // Kullanıcılar sekmesine yönlendir
                            exit();
                        } else {
                            $errorMessage = "Yeni kullanıcı eklenirken bir hata oluştu.";
                            error_log("Add user DB error: " . print_r($stmtAddUser->errorInfo(), true));
                        }
                    }
                } catch (PDOException $e) {
                    $errorMessage = "Veritabanı hatası (Kullanıcı Ekleme): " . $e->getMessage();
                    error_log("Add user PDO Exception: " . $e->getMessage());
                }
            }
        }
    }
}
// --- Bitiş POST: Kullanıcı Ekle ---


// --- POST: Kullanıcı Sil (Sadece Super Admin) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    // Sadece super-admin bu işlemi yapabilir
    if ($adminRole !== 'super-admin') {
        $errorMessage = "Bu işlemi yapma yetkiniz yok.";
    } else {
        $userIdToDelete = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);

        if (!$userIdToDelete) {
            $errorMessage = "Geçersiz kullanıcı ID'si.";
        } else {
            try {
                // Kendi hesabımızı silmeyi engelle
                if ($userIdToDelete == $adminId) {
                    $errorMessage = "Kendi hesabınızı silemezsiniz.";
                } else {
                    // Kullanıcıya ait tüm katılımları (survey_participants) sil
                    $stmtDeleteParticipants = $pdo->prepare("DELETE FROM survey_participants WHERE admin_id = ?");
                    $stmtDeleteParticipants->execute([$userIdToDelete]);

                    // Kullanıcıyı sil
                    $stmtDeleteUser = $pdo->prepare("DELETE FROM users WHERE id = ?");
                    if ($stmtDeleteUser->execute([$userIdToDelete])) {
                         // Başarılı silme sonrası sayfayı yenile ve mesaj göster
                        header("Location: dashboard.php?status=user_deleted&tab=users"); // Kullanıcılar sekmesine yönlendir
                        exit();
                    } else {
                        $errorMessage = "Kullanıcı silinirken bir hata oluştu.";
                    }
                }
            } catch (PDOException $e) {
                $errorMessage = "Veritabanı hatası (Kullanıcı Silme): " . $e->getMessage();
                error_log("Delete user PDO Exception: " . $e->getMessage());
            }
        }
    }
}
// --- Bitiş POST: Kullanıcı Sil ---


// --- POST: Kullanıcı Rolü Güncelle (Sadece Super Admin) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user_role'])) {
    // Sadece super-admin bu işlemi yapabilir
    if ($adminRole !== 'super-admin') {
        $errorMessage = "Bu işlemi yapma yetkiniz yok.";
    } else {
        $userIdToUpdate = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        $newRole = trim($_POST['new_role'] ?? '');

        if (!$userIdToUpdate) {
            $errorMessage = "Geçersiz kullanıcı ID'si.";
        } elseif (empty($newRole)) {
            $errorMessage = "Yeni rol boş olamaz.";
        } else {
            // Geçerli rol değerlerini kontrol et (isteğe bağlı ama önerilir)
            $allowedRoles = ['user', 'admin', 'super-admin', 'old_user']; // old_user rolünü de ekledik
            if (!in_array($newRole, $allowedRoles)) {
                $errorMessage = "Geçersiz rol değeri.";
            } else {
                try {
                    // Kendi rolümüzü değiştirmemizi engelle
                    if ($userIdToUpdate == $adminId) {
                         $errorMessage = "Kendi rolünüzü değiştiremezsiniz.";
                    } else {
                        $updateRoleStmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
                        if ($updateRoleStmt->execute([$newRole, $userIdToUpdate])) {
                             // Başarılı güncelleme sonrası sayfayı yenile ve mesaj göster
                            header("Location: dashboard.php?status=role_updated&tab=users"); exit(); // Kullanıcılar sekmesine yönlendir
                        } else {
                            $errorMessage = "Kullanıcı rolü güncellenirken bir hata oluştu.";
                        }
                    }
                } catch (PDOException $e) {
                    $errorMessage = "Veritabanı hatası (Rol Güncelleme): " . $e->getMessage();
                    error_log("User role update error: " . $e->getMessage());
                }
            }
        }
    }
}
// --- Bitiş POST: Kullanıcı Rolü Güncelle ---


// --- POST: Kullanıcı Duration Days Güncelle (Sadece Super Admin) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user_duration'])) {
    // Sadece super-admin bu işlemi yapabilir
    if ($adminRole !== 'super-admin') {
        $errorMessage = "Bu işlemi yapma yetkiniz yok.";
    } else {
        $userIdToUpdate = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        $newDurationDays = filter_input(INPUT_POST, 'duration_days', FILTER_VALIDATE_INT);

        // duration_days NULL da olabilir, bu yüzden sadece INT kontrolü yapıyoruz
        if (!$userIdToUpdate) {
            $errorMessage = "Geçersiz kullanıcı ID'si.";
        } elseif ($newDurationDays === false && $_POST['duration_days'] !== '' && $_POST['duration_days'] !== null) {
             // Eğer değer boş değilse ve geçerli INT değilse hata ver
             $errorMessage = "Geçersiz süre değeri. Lütfen bir sayı girin.";
        } else {
            // Eğer input boş geldiyse (örn: '') bunu NULL olarak kaydet
            $valueToSave = ($newDurationDays === false && ($_POST['duration_days'] === '' || $_POST['duration_days'] === null)) ? null : $newDurationDays;

            // Eğer yeni süre NULL değilse ve 0'dan büyükse last_duration_check'i bugünün tarihi yap
            $last_duration_check_value = null;
            if ($valueToSave !== null && $valueToSave > 0) {
                 $last_duration_check_value = date('Y-m-d');
            } else {
                 // Eğer süre 0 veya NULL yapılıyorsa, last_duration_check'i de NULL yap
                 $last_duration_check_value = null;
            }


            try {
                // duration_days ve last_duration_check sütunlarını güncelle
                $updateDurationStmt = $pdo->prepare("UPDATE users SET duration_days = ?, last_duration_check = ? WHERE id = ?");
                if ($updateDurationStmt->execute([$valueToSave, $last_duration_check_value, $userIdToUpdate])) {

                     // Eğer süre 0 veya altına düşürüldüyse rolü old_user yap
                     if ($valueToSave !== null && $valueToSave <= 0) {
                         $updateRoleStmt = $pdo->prepare("UPDATE users SET role = 'old_user' WHERE id = ?");
                         $updateRoleStmt->execute([$userIdToUpdate]);
                         // Eğer kendi süremizi 0 yaptık ve rolümüz admin idi ise oturumu güncelle
                         if ($userIdToUpdate == $adminId && $_SESSION['role'] === 'admin') {
                             $_SESSION['role'] = 'old_user';
                         }
                     }
                     // Başarılı güncelleme sonrası sayfayı yenile ve mesaj göster
                    header("Location: dashboard.php?status=duration_updated&tab=users"); exit(); // Kullanıcılar sekmesine yönlendir
                } else {
                    $errorMessage = "Kullanıcı süresi güncellenirken bir hata oluştu.";
                }
            } catch (PDOException $e) {
                $errorMessage = "Veritabanı hatası (Süre Güncelleme): " . $e->getMessage();
                error_log("User duration update error: " . $e->getMessage());
            }
        }
    }
}
// --- Bitiş POST: Kullanıcı Duration Days Güncelle ---


// --- Veri Çekme (Sayfa Yüklendiğinde) ---
// Hata mesajı yoksa verileri çek
if (is_null($errorMessage) && is_null($profileError) && is_null($passwordError)) {
    try {
        // Katılımcıları Çek (Admin'e ait olanlar)
        // old_user rolündeki kullanıcılar sadece kendi katılımlarını görebilir
        // Admin ve Super-admin kendi oluşturduğu katılımları görebilir
        $participantsStmt = $pdo->prepare("SELECT sp.id, sp.name, sp.class, s.title AS survey_title, s.id AS survey_id FROM survey_participants sp JOIN surveys s ON sp.survey_id = s.id WHERE sp.admin_id = ? ORDER BY sp.id DESC");
        $participantsStmt->execute([$adminId]);
        $participants = $participantsStmt->fetchAll(PDO::FETCH_ASSOC);


        // Anketleri Çek
        // old_user anketleri göremez veya paylaşım linki alamaz
        if ($adminRole !== 'old_user') {
            $surveysStmt = $pdo->query("SELECT id, title, description FROM surveys ORDER BY id ASC");
            $surveys = $surveysStmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
             $surveys = []; // old_user için anket listesi boş
        }


        // Kullanıcıları Çek (Sadece Super Admin için)
        if ($adminRole === 'super-admin') {
            // duration_days ve institution_name sütunlarını da çekiyoruz
            $usersStmt = $pdo->query("SELECT id, username, full_name, email, role, institution_name, duration_days FROM users ORDER BY id ASC");
            $usersList = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
        }

    } catch (PDOException $e) {
        $errorMessage = "Veriler (katılımcı/anket/kullanıcı) alınamadı.";
        error_log("Dashboard data fetch error: " . $e->getMessage());
    }
}

// Mesajları URL'den al ve göster
if(isset($_GET['status'])) {
    if($_GET['status'] === 'inst_updated') $successMessage = 'Kurum bilgileri başarıyla güncellendi.';
    elseif($_GET['status'] === 'pw_updated') $successMessage = 'Şifre başarıyla değiştirildi.';
    elseif($_GET['status'] === 'user_added') $successMessage = 'Yeni kullanıcı başarıyla eklendi.'; // Yeni mesaj
    elseif($_GET['status'] === 'user_deleted') $successMessage = 'Kullanıcı başarıyla silindi.'; // Yeni mesaj
    elseif($_GET['status'] === 'role_updated') $successMessage = 'Kullanıcı rolü başarıyla güncellendi.';
    elseif($_GET['status'] === 'duration_updated') $successMessage = 'Kullanıcı süresi başarıyla güncellendi.';
}

$totalQuestionsPlaceholder = "N/A"; $averageCompletionTimeNote = "Hesaplanamadı"; // Bu değişkenler hala kullanılıyorsa
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Paneli | Psikometrik.Net</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" href="/favicon.png" type="image/png">
    <style>
        /* Stillendirme */
        body { font-family: sans-serif; line-height: 1.6; background-color: #f1f5f9; color: #334155; }
        nav { background-color: #ffffff; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05); padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; }
        .logo-area { display: flex; align-items: center; gap: 0.75rem; }
        .logo-area img { height: 3rem; width: auto; }
        .nav-links { display: flex; align-items: center; }
        .nav-links button, .nav-links a, .nav-links span { margin-left: 1rem; }
        .user-button { background: none; border: none; cursor: pointer; display: inline-flex; align-items: center; font-weight: 500; color: #334155;}
        .user-button:hover { color: #0ea5e9; }
        .btn { padding: 0.6rem 1.25rem; border-radius: 0.375rem; color: white; font-weight: 500; transition: background-color 0.2s ease-in-out; display: inline-block; text-align: center; text-decoration: none; border: none; cursor: pointer;}
        .btn-primary { background-color: #3b82f6; } .btn-primary:hover { background-color: #2563eb; }
        .btn-success { background-color: #16a34a; } .btn-success:hover { background-color: #15803d; }
        .btn-danger { background-color: #dc2626; } .btn-danger:hover { background-color: #b91c1c; }
        .btn-sm { padding: 0.4rem 1rem; font-size: 0.875rem;}
        .btn-xs { padding: 0.2rem 0.5rem; font-size: 0.75rem; } /* Yeni küçük buton boyutu */

        /* Sekme Navigasyon Stilleri */
        .tab-nav {
            display: flex;
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 1.5rem;
            overflow-x: auto; /* Küçük ekranlarda kaydırma */
        }
        .tab-button {
            padding: 0.75rem 1.5rem;
            font-size: 1rem;
            font-weight: 500;
            color: #64748b; /* Slate 500 */
            background-color: transparent;
            border: none;
            border-bottom: 2px solid transparent;
            cursor: pointer;
            transition: color 0.2s ease-in-out, border-color 0.2s ease-in-out;
            white-space: nowrap; /* Buton metninin tek satırda kalmasını sağla */
        }
        .tab-button:hover {
            color: #334155; /* Slate 700 */
        }
        .tab-button.active {
            color: #0ea5e9; /* Sky 500 */
            border-bottom-color: #0ea5e9; /* Sky 500 */
            font-weight: 600;
        }
        .tab-content {
            /* Sekme içeriği stilleri */
        }
        .tab-content.hidden {
            display: none;
        }


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
        .lg\:col-span-3 { grid-column: span 3 / span 3; }
        .lg\:grid-cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .file\:mr-4 { margin-right: 1rem; } .file\:py-2 { padding-top: 0.5rem; padding-bottom: 0.5rem; }
        .file\:px-4 { padding-left: 1rem; padding-right: 1rem; } .file\:rounded-full { border-radius: 9999px; }
        .file\:border-0 { border-width: 0; } .file\:text-sm { font-size: 0.875rem; }
        .file\:font-semibold { font-weight: 600; } .file\:bg-blue-50 { background-color: #eff6ff; }
        .file\:text-blue-700 { color: #1d4ed8; } .hover\:file\:bg-blue-100:hover { background-color: #dbeafe; }

        /* Kullanıcı Yönetimi Tablosu Özel Stilleri */
        .users-table td form {
            display: flex;
            align-items: center;
            gap: 0.5rem; /* Form elemanları arası boşluk */
        }
         .users-table td select.form-input,
         .users-table td input[type="number"].form-input {
             padding: 0.3rem 0.5rem; /* Küçük inputlar */
             font-size: 0.85rem;
             height: auto; /* Yüksekliği otomatik ayarla */
             display: inline-block; /* Inline flex içinde düzgün durması için */
             width: auto; /* Genişliği içeriğe göre ayarla */
             min-width: 80px; /* Minimum genişlik */
         }
         .users-table td button.btn-xs {
             /* btn-xs stili yukarıda tanımlı */
         }

         /* Modal Stilleri */
         .modal {
             position: fixed;
             top: 0;
             left: 0;
             width: 100%;
             height: 100%;
             background-color: rgba(0, 0, 0, 0.5);
             display: flex;
             justify-content: center;
             align-items: center;
             z-index: 1000;
         }
         .modal-content {
             background-color: #fff;
             padding: 2rem;
             border-radius: 0.5rem;
             box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
             width: 90%;
             max-width: 500px;
             max-height: 90vh; /* Çok uzun formlar için */
             overflow-y: auto; /* İçerik taşarsa kaydırma çubuğu */
         }
         .modal-header {
             display: flex;
             justify-content: space-between;
             align-items: center;
             border-bottom: 1px solid #e2e8f0;
             padding-bottom: 1rem;
             margin-bottom: 1.5rem;
         }
         .modal-header h3 {
             font-size: 1.25rem;
             font-weight: 600;
             color: #1f2937;
         }
         .close-button {
             background: none;
             border: none;
             font-size: 1.5rem;
             cursor: pointer;
             color: #64748b;
         }
         .close-button:hover {
             color: #334155;
         }
         .modal-body .form-input {
             margin-bottom: 1rem;
         }
         .modal-footer {
             margin-top: 1.5rem;
             border-top: 1px solid #e2e8f0;
             padding-top: 1rem;
             text-align: right;
         }

         /* Admin Duration Display */
         .admin-duration {
             font-size: 1rem;
             font-weight: 600;
             color: #16a34a; /* Yeşil renk */
             margin-left: auto; /* Sağa yasla */
         }
          /* Başlık ve Süre için Flex Container */
         .dashboard-header {
             display: flex;
             justify-content: space-between;
             align-items: center;
             margin-bottom: 1.5rem; /* H1'in alt boşluğunu ayarla */
         }
          .dashboard-header h1 {
              margin-bottom: 0; /* H1'in kendi alt boşluğunu sıfırla */
          }

    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <nav>
        <div class="logo-area">
            <a href="../index.php"><img src="/assets/Psikometrik.png" alt="Psikometrik.Net Logo"></a>
        </div>
        <div class="nav-links flex items-center">
            <?php if (isset($_SESSION['user_id']) && $adminData): ?>
                <button type="button" id="profilAyarlariButonu" class="user-button text-gray-700 mr-4">
                    👤 <?php echo htmlspecialchars($adminData['username']); ?>
                </button>
                <a href="../logout.php" class="btn btn-danger btn-sm">Çıkış</a>
            <?php endif; ?>
        </div>
    </nav>

    <main class="container mx-auto p-4 mt-8">
        <div class="dashboard-header">
             <h1 class="text-2xl font-bold text-gray-800">Admin Paneli</h1>
             <?php
             // Admin rolündeki kullanıcılar için kalan süreyi göster
             // duration_days NULL olabilir, bu durumda göstermeyebiliriz veya farklı bir mesaj gösterebiliriz.
             if ($adminRole === 'admin' && isset($adminData['duration_days']) && $adminData['duration_days'] !== null):
                 $remainingDays = (int)($adminData['duration_days']);
                 // Süre 0'dan büyükse yeşil, 0'sa veya boşsa kırmızı/gri
                 $durationColor = ($remainingDays > 0) ? 'text-green-600' : 'text-red-600';
             ?>
                 <span class="admin-duration <?= $durationColor ?>">Kalan Süre: <?= htmlspecialchars($remainingDays) ?> Gün</span>
             <?php elseif ($adminRole === 'admin' && ($adminData['duration_days'] === null || $adminData['duration_days'] <= 0)): ?>
                  <span class="admin-duration text-red-600">Süre Doldu</span>
             <?php endif; ?>
        </div>


        <?php
            // Mesajları göster
            if (!empty($successMessage)) echo '<div class="feedback-success">' . htmlspecialchars($successMessage) . '</div>';
            if (!empty($errorMessage))   echo '<div class="feedback-error">' . htmlspecialchars($errorMessage) . '</div>';
            if (!empty($profileError))   echo '<div class="feedback-error">' . htmlspecialchars($profileError) . '</div>';
            if (!empty($passwordError))  echo '<div class="feedback-error">' . htmlspecialchars($passwordError) . '</div>';
         ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <?php if (in_array($adminRole, ['admin', 'super-admin'])): ?>
            <div id="profilAyarlariPaneli" class="lg:col-span-1 space-y-6 hidden">
                <section class="bg-white p-6 rounded-lg shadow-md">
                    <h2 class="text-lg font-semibold mb-4 text-gray-700 border-b pb-2">Kurum Bilgileri</h2>
                     <form method="POST" enctype="multipart/form-data" action="dashboard.php">
                        <input type="hidden" name="update_institution" value="1">
                        <div class="mb-4">
                            <label for="institution_name" class="form-label">Kurum Adı:</label>
                            <input type="text" name="institution_name" id="institution_name" value="<?= htmlspecialchars($adminData['institution_name'] ?? '') ?>" class="form-input mt-1" <?= !empty($adminData['institution_name']) ? 'readonly' : '' ?>>
                        </div>
                        <div class="mb-4">
                            <label for="institution_logo" class="form-label">Kurum Logosu (Max 2MB - PNG, JPG, GIF):</label>
                             <input type="file" name="institution_logo" id="institution_logo" accept="image/png, image/jpeg, image/gif" class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100" <?= !empty($adminData['institution_logo_path']) ? 'disabled' : '' ?>>
                            <?php if (!empty($adminData['institution_logo_path']) && file_exists('../' . $adminData['institution_logo_path'])): ?>
                                <div class="mt-4">
                                    <p class="text-xs font-medium text-gray-500 mb-1">Mevcut Logo:</p>
                                    <img src="../<?= htmlspecialchars($adminData['institution_logo_path']) ?>?t=<?= time() ?>" alt="Kurum Logosu" class="h-16 w-auto border p-1 rounded bg-gray-50">
                                </div>
                             <?php elseif (!empty($adminData['institution_logo_path'])): ?>
                                 <p class="text-xs text-red-500 mt-2">Logo dosyası bulunamadı.</p>
                            <?php endif; ?>
                        </div>
                         <button type="submit" class="btn btn-success w-full" <?= (!empty($adminData['institution_name']) || !empty($adminData['institution_logo_path'])) ? 'disabled' : '' ?>>Bilgileri Kaydet</button>
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
                            <input type="password" name="new_password" id="new_password" class="form-input mt-1" required minlength="6">
                        </div>
                        <div class="mb-4">
                            <label for="confirm_password" class="form-label">Yeni Şifre (Tekrar):</label>
                            <input type="password" name="confirm_password" id="confirm_password" class="form-input mt-1" required minlength="6">
                        </div>
                        <button type="submit" class="btn btn-primary w-full">Şifreyi Değiştir</button>
                    </form>
                </section>
            </div>
            <?php endif; // Profil Ayarları Paneli Sonu ?>


            <div id="mainContentArea" class="lg:col-span-3 space-y-8">

                <div class="tab-nav">
                    <?php if ($adminRole === 'super-admin'): ?>
                        <button class="tab-button active" onclick="openTab(event, 'users')">Kullanıcılar</button>
                    <?php endif; ?>
                    <?php if ($adminRole !== 'old_user'): ?>
                         <button class="tab-button <?= $adminRole !== 'super-admin' ? 'active' : '' ?>" onclick="openTab(event, 'surveys')">Anketler/Testler</button>
                    <?php endif; ?>
                    <button class="tab-button <?= $adminRole === 'old_user' ? 'active' : '' ?>" onclick="openTab(event, 'results')">Sonuçlar</button>
                </div>

                <?php if ($adminRole === 'super-admin'): ?>
                <div id="users" class="tab-content">
                    <section class="bg-white p-6 rounded-lg shadow-md">
                        <div class="flex justify-between items-center mb-4 border-b pb-2">
                            <h2 class="text-xl font-semibold text-gray-700">👥 Kullanıcı Yönetimi</h2>
                             <button type="button" class="btn btn-success btn-sm" onclick="openModal('addUserModal')">Kullanıcı Ekle</button>
                        </div>

                        <?php if (!empty($usersList)): ?>
                            <div class="overflow-x-auto">
                                <table class="min-w-full users-table">
                                    <thead>
                                        <tr>
                                            <th class="py-2 px-4 border-b text-left text-sm">ID</th>
                                            <th class="py-2 px-4 border-b text-left text-sm">Kullanıcı Adı</th>
                                             <th class="py-2 px-4 border-b text-left text-sm">Ad Soyad</th>
                                            <th class="py-2 px-4 border-b text-left text-sm">E-posta</th>
                                             <th class="py-2 px-4 border-b text-left text-sm">Kurum Adı</th>
                                            <th class="py-2 px-4 border-b text-left text-sm">Rol</th>
                                            <th class="py-2 px-4 border-b text-left text-sm">Süre (Gün)</th>
                                            <th class="py-2 px-4 border-b text-center text-sm">İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($usersList as $user): ?>
                                            <tr>
                                                <td class="py-2 px-4 border-b text-sm"><?= htmlspecialchars($user['id']) ?></td>
                                                <td class="py-2 px-4 border-b text-sm"><?= htmlspecialchars($user['username']) ?></td>
                                                 <td class="py-2 px-4 border-b text-sm"><?= htmlspecialchars($user['full_name'] ?? '-') ?></td>
                                                <td class="py-2 px-4 border-b text-sm"><?= htmlspecialchars($user['email']) ?></td>
                                                 <td class="py-2 px-4 border-b text-sm"><?= htmlspecialchars($user['institution_name'] ?? '-') ?></td>
                                                <td class="py-2 px-4 border-b text-sm">
                                                    <?php if ($user['id'] == $adminId): ?>
                                                        <?= htmlspecialchars($user['role']) ?>
                                                    <?php else: ?>
                                                        <form method="POST" action="dashboard.php">
                                                            <input type="hidden" name="update_user_role" value="1">
                                                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                            <select name="new_role" class="form-input inline-block w-auto text-sm">
                                                                <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>user</option>
                                                                <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>admin</option>
                                                                <option value="super-admin" <?= $user['role'] === 'super-admin' ? 'selected' : '' ?>>super-admin</option>
                                                                 <option value="old_user" <?= $user['role'] === 'old_user' ? 'selected' : '' ?>>old_user</option> </select>
                                                            <button type="submit" class="btn btn-primary btn-xs">Güncelle</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="py-2 px-4 border-b text-sm">
                                                     <form method="POST" action="dashboard.php">
                                                         <input type="hidden" name="update_user_duration" value="1">
                                                         <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                         <input type="number" name="duration_days" value="<?= htmlspecialchars($user['duration_days'] ?? '') ?>" class="form-input inline-block w-auto text-sm" min="0">
                                                         <button type="submit" class="btn btn-primary btn-xs">Güncelle</button>
                                                     </form>
                                                </td>
                                                <td class="py-2 px-4 border-b text-center text-sm">
                                                    <?php if ($user['id'] != $adminId): ?>
                                                        <form method="POST" action="dashboard.php" onsubmit="return confirm('Bu kullanıcıyı silmek istediğinizden emin misiniz?');">
                                                             <input type="hidden" name="delete_user" value="1">
                                                             <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                            <button type="submit" class="btn btn-danger btn-xs">Sil</button>
                                                        </form>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-gray-600 text-sm">Sistemde kayıtlı kullanıcı bulunamadı.</p>
                        <?php endif; ?>
                    </section>
                </div>
                <?php endif; // Super Admin Kullanıcılar Sekmesi Sonu ?>


                <?php if ($adminRole !== 'old_user'): ?>
                <div id="surveys" class="tab-content <?= $adminRole !== 'super-admin' ? 'active' : '' ?>">
                     <section class="bg-white p-6 rounded-lg shadow-md">
                        <h2 class="text-xl font-semibold mb-4 text-gray-700 border-b pb-2">📝 Anketleri/Testleri Uygula</h2>
                        <?php if (count($surveys) > 0): ?>
                            <div class="survey-grid">
                                <?php foreach ($surveys as $survey): ?>
                                    <div class="survey-card" data-survey-id="<?= $survey['id'] ?>">
                                        <h2><?= htmlspecialchars($survey['title']) ?></h2>
                                        <p><?= nl2br(htmlspecialchars($survey['description'] ?? 'Açıklama yok.')) ?></p>
                                        <div class="card-actions">
                                            <?php if ($adminRole !== 'old_user'): ?>
                                                <button type="button" class="btn btn-success btn-sm" onclick="showLink(<?= $survey['id'] ?>)">Paylaşım Linki</button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-gray-600 text-sm">Sistemde anket/test bulunamadı.</p>
                        <?php endif; ?>
                    </section>
                </div>
                <?php endif; // Anketler/Testler Sekmesi Sonu ?>

                <div id="results" class="tab-content <?= $adminRole === 'old_user' ? 'active' : '' ?> <?= $adminRole !== 'super-admin' && $adminRole !== 'old_user' ? 'hidden' : '' ?>">
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
                                                    // Sonuç görüntüleme dosyası anket ID'sine göre belirlenir
                                                    $resultViewFile = "view-result-" . $participant['survey_id'] . ".php";
                                                    // Eğer özel dosya yoksa genel view-result.php'ye yönlendirilebilir
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
                </div>

            </div>
        </div>
    </main>

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

    <div id="addUserModal" class="modal hidden">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Yeni Kullanıcı Ekle</h3>
                <span class="close-button" onclick="closeModal('addUserModal')">&times;</span>
            </div>
            <div class="modal-body">
                <form id="addUserForm" method="POST" action="dashboard.php">
                    <input type="hidden" name="add_user" value="1">
                    <div class="mb-4">
                        <label for="new_username" class="form-label">Kullanıcı Adı:</label>
                        <input type="text" name="new_username" id="new_username" class="form-input" required>
                    </div>
                    <div class="mb-4">
                        <label for="new_full_name" class="form-label">Ad Soyad:</label>
                        <input type="text" name="new_full_name" id="new_full_name" class="form-input" required>
                    </div>
                    <div class="mb-4">
                        <label for="new_email" class="form-label">E-posta:</label>
                        <input type="email" name="new_email" id="new_email" class="form-input" required>
                    </div>
                    <div class="mb-4">
                        <label for="new_password" class="form-label">Şifre:</label>
                        <input type="password" name="new_password" id="new_password" class="form-input" required minlength="6">
                    </div>
                     <div class="mb-4">
                        <label for="new_institution_name" class="form-label">Kurum Adı:</label>
                        <input type="text" name="new_institution_name" id="new_institution_name" class="form-input" required>
                    </div>
                     <div class="mb-4">
                        <label for="new_role" class="form-label">Rol:</label>
                        <select name="new_role" id="new_role" class="form-input" required>
                            <option value="user">user</option>
                            <option value="admin">admin</option>
                            <option value="super-admin">super-admin</option>
                             <option value="old_user">old_user</option> </select>
                    </div>
                     <div class="mb-4">
                        <label for="new_duration_days" class="form-label">Süre (Gün):</label>
                        <input type="number" name="new_duration_days" id="new_duration_days" class="form-input" min="0">
                         <p class="text-xs text-gray-500 mt-1">Boş bırakılabilir.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-success">Kullanıcı Ekle</button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <script>
        const profilAyarlariButonu = document.getElementById('profilAyarlariButonu');
        const profilAyarlariPaneli = document.getElementById('profilAyarlariPaneli');
        const mainContentArea = document.getElementById('mainContentArea');
        const tabButtons = document.querySelectorAll('.tab-button');
        const tabContents = document.querySelectorAll('.tab-content');
        const addUserModal = document.getElementById('addUserModal'); // Modal elementi

        // Sayfa yüklendiğinde aktif sekmeyi belirle
        document.addEventListener('DOMContentLoaded', () => {
            // URL'de 'tab' parametresi varsa o sekmeyi aç
            const urlParams = new URLSearchParams(window.location.search);
            const activeTabId = urlParams.get('tab');

            // Kullanıcının rolünü al
            const adminRole = '<?= $adminRole ?>';

            // Eğer kullanıcı old_user ise sadece results sekmesini aç
            if (adminRole === 'old_user') {
                 openTab(null, 'results');
            } else if (activeTabId) {
                // Eğer kullanıcı old_user değilse ve URL'de tab belirtilmişse o sekmeyi aç
                // Ancak admin rolündeki kullanıcılar users sekmesini göremez
                 if (adminRole === 'admin' && activeTabId === 'users') {
                     openTab(null, 'surveys'); // Admin ise users sekmesine gidemez, surveys'e yönlendir
                 } else {
                     openTab(null, activeTabId); // Belirtilen sekmeyi aç
                 }
            } else {
                 // URL'de tab parametresi yoksa varsayılan sekmeyi aç
                 // Super admin ise kullanıcılar sekmesi, admin ise anketler sekmesi, old_user ise sonuçlar sekmesi
                 let defaultTab = 'surveys'; // Admin için varsayılan
                 if (adminRole === 'super-admin') {
                     defaultTab = 'users';
                 } else if (adminRole === 'old_user') {
                     defaultTab = 'results';
                 }
                 openTab(null, defaultTab);
            }

             // Profil Ayarları panelinin başlangıçta kapalı olduğundan emin ol
             if(profilAyarlariPaneli) {
                  profilAyarlariPaneli.classList.add('hidden');
                  mainContentArea.classList.remove('lg:col-span-2'); // Başlangıçta ana alan tam genişlikte
                  mainContentArea.classList.add('lg:col-span-3');
             }
        });


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

        // Sekmeleri Yönetme Fonksiyonu
        function openTab(event, tabId) {
            // Kullanıcının rolünü al
            const adminRole = '<?= $adminRole ?>';

            // old_user rolündeki kullanıcıların sadece results sekmesine erişimini kontrol et
            if (adminRole === 'old_user' && tabId !== 'results') {
                // old_user başka bir sekmeye tıklarsa results sekmesini aç
                tabId = 'results';
            }
             // Admin rolündeki kullanıcıların users sekmesine erişimini engelle
             if (adminRole === 'admin' && tabId === 'users') {
                 tabId = 'surveys'; // Admin users sekmesine gidemez, surveys'e yönlendir
             }


            // Tüm tab butonlarından 'active' sınıfını kaldır
            tabButtons.forEach(button => {
                button.classList.remove('active');
            });

            // Tüm tab içeriklerini gizle
            tabContents.forEach(content => {
                content.classList.add('hidden');
            });

            // Tıklanan butona 'active' sınıfını ekle
            // Eğer event varsa tıklanan butonu aktif yap, yoksa (sayfa yüklenmesi) targetId'ye göre butonu bul
            if (event) {
                event.currentTarget.classList.add('active');
            } else {
                 // Event yoksa (sayfa yüklenmesi gibi), ilgili butonu bul ve aktif yap
                 // Sadece görünür butonlar arasında ara
                 const targetButton = document.querySelector(`.tab-nav .tab-button[onclick*="'${tabId}'"]:not(.hidden)`);
                 if(targetButton) {
                      targetButton.classList.add('active');
                 } else {
                      // Eğer targetId'ye uygun görünür buton yoksa, ilk görünür butonu aktif yap
                      const firstVisibleButton = document.querySelector('.tab-nav .tab-button:not(.hidden)');
                      if(firstVisibleButton) {
                          firstVisibleButton.classList.add('active');
                          tabId = firstVisibleButton.getAttribute('onclick').match(/'([^']+)'/)[1]; // Yeni aktif sekme ID'sini al
                      }
                 }
            }


            // İlgili tab içeriğini göster
            const targetTabContent = document.getElementById(tabId);
            if (targetTabContent) {
                targetTabContent.classList.remove('hidden');
            }

             // URL'yi güncelle (sayfa yenilenmeden sekme bilgisini korumak için)
             const url = new URL(window.location.href);
             url.searchParams.set('tab', tabId);
             window.history.pushState({}, '', url);

        }


        // Popup Link Fonksiyonları (Aynı kaldı)
        function showLink(surveyId) {
            const popup = document.getElementById('popup');
            const linkInput = document.getElementById('shareLink');
            let takeSurveyPage = '';
            // Test ID'lerine göre özel dosya adlarını belirle
             if ([4, 5, 6, 7, 8, 9, 11, 12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,32,33,34,35,36,37].includes(surveyId)) { // 10 hariç (çünkü o test)
                 takeSurveyPage = `../take-survey-${surveyId}.php`;
             } else if (surveyId === 10) { // Burdon testi için
                 takeSurveyPage = `take-test-${surveyId}.php`;
             } else {
                 takeSurveyPage = `take-survey.php?id=${surveyId}`; // Genel fallback
             }

            const adminId = <?php echo json_encode($adminId); ?>;
             // Linki oluştururken ana dizin yapısını varsayalım (../ gerekli değil)
             // Eğer uygulama bir alt klasördeyse (örn: /testanket1), onu buraya ekleyin:
             // const surveyLink = `${window.location.origin}/testanket1/${takeSurveyPage}?admin_id=${encodeURIComponent(adminId)}`;
             const surveyLink = `${window.location.origin}/admin/${takeSurveyPage}?admin_id=${encodeURIComponent(adminId)}`; // Admin klasörü içinde varsayımı

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

        // Modal Fonksiyonları
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('hidden');
            }
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.add('hidden');
            }
        }

        // Modal dışına tıklayınca kapatma
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.add('hidden');
            }
        }


    </script>

</body>
</html>
