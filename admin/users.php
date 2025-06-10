<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/testanket/src/config.php';

// Sadece super-admin erişebilir
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super-admin') {
    header('Location: ../login.php');
    exit();
}

// Kullanıcıları listele
$stmt = $pdo->query("SELECT id, username, email, role, is_active FROM users");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Aktif/Pasif Değiştirme
if (isset($_GET['toggle']) && isset($_GET['id'])) {
    $userId = intval($_GET['id']);
    $currentStatus = intval($_GET['toggle']) === 1 ? 0 : 1;

    $update = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?");
    $update->execute([$currentStatus, $userId]);

    header('Location: users.php');
    exit();
}

// Kullanıcı Silme
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $userId = intval($_GET['id']);

    $delete = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $delete->execute([$userId]);

    header('Location: users.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <title>Kullanıcı Yönetimi</title>
  <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-gray-100 min-h-screen">
  <nav class="bg-white shadow p-4 flex justify-between">
    <a href="dashboard.php" class="text-lg font-bold">Admin Paneli</a>
    <a href="../logout.php" class="bg-red-500 text-white px-4 py-2 rounded hover:bg-red-600">Çıkış Yap</a>
  </nav>

  <main class="container mx-auto p-4">
    <h1 class="text-2xl font-bold mb-6">Kullanıcı Yönetimi</h1>

    <table class="min-w-full bg-white shadow-md rounded">
      <thead>
        <tr>
          <th class="py-2 px-4 border-b">ID</th>
          <th class="py-2 px-4 border-b">Kullanıcı Adı</th>
          <th class="py-2 px-4 border-b">E-posta</th>
          <th class="py-2 px-4 border-b">Rol</th>
          <th class="py-2 px-4 border-b">Durum</th>
          <th class="py-2 px-4 border-b">İşlemler</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $user): ?>
          <tr>
            <td class="py-2 px-4 border-b text-center"><?php echo $user['id']; ?></td>
            <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($user['username']); ?></td>
            <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($user['email']); ?></td>
            <td class="py-2 px-4 border-b text-center"><?php echo htmlspecialchars($user['role']); ?></td>
            <td class="py-2 px-4 border-b text-center">
              <?php echo $user['is_active'] ? 'Aktif' : 'Pasif'; ?>
            </td>
            <td class="py-2 px-4 border-b text-center flex gap-2 justify-center">
              <a href="?id=<?php echo $user['id']; ?>&toggle=<?php echo $user['is_active']; ?>"
                 class="bg-blue-500 text-white px-3 py-1 rounded hover:bg-blue-600">
                <?php echo $user['is_active'] ? 'Pasifleştir' : 'Aktifleştir'; ?>
              </a>
              <a href="?id=<?php echo $user['id']; ?>&delete=1"
                 class="bg-red-500 text-white px-3 py-1 rounded hover:bg-red-600"
                 onclick="return confirm('Bu kullanıcıyı silmek istediğinize emin misiniz?');">
                Sil
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </main>
</body>
</html>
