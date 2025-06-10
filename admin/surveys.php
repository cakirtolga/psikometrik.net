<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/testanket/src/config.php';

// Giriş kontrolü
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super-admin'])) {
    header('Location: ../login.php');
    exit();
}

// Admin bilgisi
$adminEmail = $_SESSION['user_email'] ?? ''; // email session'a eklenmemişse login.php'de ekleyelim

// Anketler çekiliyor
$stmt = $pdo->query("SELECT id, title FROM surveys");
$surveys = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <title>Anketler</title>
  <link href="../assets/css/style.css" rel="stylesheet">
  <style>
    .popup {
      display: none;
      position: fixed;
      top: 30%;
      left: 50%;
      transform: translate(-50%, -50%);
      background: white;
      padding: 20px;
      border: 1px solid #ccc;
      box-shadow: 0 0 10px rgba(0,0,0,0.2);
      z-index: 9999;
    }
    .popup input {
      width: 100%;
      margin-bottom: 10px;
    }
  </style>
</head>
<body class="bg-gray-100 p-4">

  <h1 class="text-2xl font-bold mb-6">Tüm Anketler</h1>

  <table class="min-w-full bg-white shadow-md rounded">
    <thead>
      <tr>
        <th class="py-2 px-4 border-b">Anket Başlığı</th>
        <th class="py-2 px-4 border-b">İşlem</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($surveys as $survey): ?>
        <tr>
          <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($survey['title']); ?></td>
          <td class="py-2 px-4 border-b text-center">
            <button onclick="showLink(<?php echo $survey['id']; ?>)" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
              Testi Uygula
            </button>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <!-- Link Gösterme Pop-up -->
  <div id="popup" class="popup">
    <input type="text" id="shareLink" readonly class="p-2 border rounded">
    <button onclick="copyLink()" class="bg-green-500 text-white px-4 py-2 rounded hover:bg-green-600 w-full">Linki Kopyala</button>
    <button onclick="closePopup()" class="mt-2 text-red-500 underline block text-center">Kapat</button>
  </div>

<script>
function showLink(surveyId) {
  const popup = document.getElementById('popup');
  const link = document.getElementById('shareLink');
  link.value = `https://dahisin.com/testanket/take-survey.php?id=${surveyId}&user_email=<?php echo $adminEmail; ?>`;
  popup.style.display = 'block';
}

function copyLink() {
  const copyText = document.getElementById('shareLink');
  copyText.select();
  copyText.setSelectionRange(0, 99999);
  document.execCommand("copy");
  alert("Link kopyalandı!");
}

function closePopup() {
  document.getElementById('popup').style.display = 'none';
}
</script>

</body>
</html>
