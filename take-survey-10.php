<?php
session_start(); // Oturumu başlat
header('Content-Type: text/html; charset=utf-8'); // Karakter setini ayarla
require_once __DIR__ . '/src/config.php'; // Veritabanı bağlantısı ve yapılandırma

// --- Test Konfigürasyonu ---
$testId = 10; // Burdon Dikkat Testi ID'si (survey_id olarak kaydedilecek)
$testTitle = "Burdon Dikkat Testi";
$targetLetters = ['a', 'b', 'd', 'g']; // Hedef harfler (küçük harf)
// Süre limitleri (saniye cinsinden)
$timeLimitOrta = 180; // Ortaokul: 3 dakika
$timeLimitLise = 120; // Lise: 2 dakika
// Skor hesaplaması için toplam hedef harf sayısı (ızgaradaki gerçek sayıdan bağımsız, örneğe göre)
$totalTargetLettersInGrid = 120;
// --- Bitiş: Test Konfigürasyonu ---

// --- Harf Izgarası (30x30 = 900 harf) ---
$letterGridString = "hfpvaşkfgşübçldjısertgıkjhbmngçöüğpoiuytrewqadgfdsahfpvaşkfgşübçldjısertgıkjhbmngçöüğpoiuytrewqadgfdsa"; // 100
$letterGridString .= "mnbvcçxzlkhgşfdsapoiuytrewqğüiöçbmkgadşflkgjdshfkjghagdgdfsgfdadsgfdbgçasdkjlfhaksjdfhglaksdfjghbzzxc"; // 200
$letterGridString .= "qwertyuıopğüasdfghjklşizxcvbnmöçaqdgdfshsgdhfgdjhfgjkdghslkfşjdghslkdfjghlskdfgjhlaskdfjgbhgadbadg"; // 300 - Section 1 End
$letterGridString .= "poiuytrewqasdfghjklşizxcvbnmöçgpıoıuytfdsağüzçlökmjnıhbvgcfxdzsaqwertyuıopğüadghjklşizxcvbnmöçad"; // 400
$letterGridString .= "asdfghjklşizxcvbnmöçüğpoiuytfdaşlkjhgfdsaqwertyuıopğübvncmöçşlikjumınhbgvcfxdzsaqdgghjklşizxcvbd"; // 500
$letterGridString .= "zxcvbnmöçlkjhgfdsaqwertyuıopğübgghjklişzxcvbnmöçpoiuytrewqsadfghjklşizxvbnmöçlkjhgfdsaqwetyuıopğü"; // 600 - Section 2 End
$letterGridString .= "qdgdfshsgdhfgdjhfgjkdghslkfşjdghslkdfjghlskdfgjhlaskdfjgbhgadbadgmnbvcçxzlkhgşfdsapoiuytrewqğüiöçb"; // 700
$letterGridString .= "asdfghjklşizxcvbnmöçüğpoiuytfdaşlkjhgfdsaqwertyuıopğübvncmöçşlikjumınhbgvcfxdzsaqdgghjklşizxcvbd"; // 800
$letterGridString .= "hfpvaşkfgşübçldjısertgıkjhbmngçöüğpoiuytrewqadgfdsahfpvaşkfgşübçldjısertgıkjhbmngçöüğpoiuytrewqadgfda"; // 900 - Section 3 End
// --- Bitiş: Harf Izgarası ---

// Harf ızgarasını diziye dönüştür (UTF-8 uyumlu)
$gridArray = mb_str_split($letterGridString, 1, 'UTF-8');
$lettersPerRow = 30; // Izgara genişliği
$rowsPerSection = 10;
$totalRows = 30;

// Değişkenleri başlat
$adminId = null;
$error = null;
// $resultData değişkeni artık kullanılmayacak
// $resultData = null;

// Yönetici ID kontrolü (GET parametresinden)
if (isset($_GET['admin_id']) && filter_var($_GET['admin_id'], FILTER_VALIDATE_INT) !== false && $_GET['admin_id'] > 0) {
    $potentialAdminId = (int)$_GET['admin_id'];
    try {
        // Yönetici veritabanında var mı kontrol et
        $adminStmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
        $adminStmt->execute([$potentialAdminId]);
        $admin = $adminStmt->fetch(PDO::FETCH_ASSOC);
        if ($admin) {
            $adminId = $admin['id']; // Yönetici ID'sini ayarla
        } else {
            $error = 'Geçersiz veya bulunamayan yönetici ID\'si.';
        }
    } catch (PDOException $e) {
        // Veritabanı hatası durumunda
        error_log("Yönetici kontrol hatası: " . $e->getMessage()); // Hatayı logla (isteğe bağlı)
        $error = 'Yönetici bilgisi alınırken veritabanı hatası oluştu.';
    }
} else {
    $error = 'Yönetici ID\'si eksik veya geçersiz.';
}

// POST İsteği Yönetimi (Form gönderildiğinde)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !is_null($adminId)) {
    // Form verilerini al ve temizle
    $name = trim($_POST['student_name'] ?? '');
    $class = trim($_POST['student_class'] ?? '');
    // Okul seviyesi kaydedilmeyecek.
    $selectedIndicesInput = $_POST['selected_indices'] ?? ''; // Seçilen harf indeksleri (string)
    // İndeksleri diziye çevir
    $selectedIndices = empty($selectedIndicesInput) ? [] : array_map('intval', explode(',', $selectedIndicesInput));

    // Gerekli alan kontrolleri
    if (empty($name) || empty($class)) {
        $error = "Lütfen Ad Soyad ve Sınıf bilgilerinizi girin.";
    } else {
        // Skoru Hesapla
        $correctlySelectedCount = 0;
        foreach ($selectedIndices as $selectedIndex) {
            if (isset($gridArray[$selectedIndex]) && in_array(mb_strtolower($gridArray[$selectedIndex], 'UTF-8'), $targetLetters)) {
                 $correctlySelectedCount++;
            }
        }
        $scorePercentage = ($totalTargetLettersInGrid > 0) ? round(($correctlySelectedCount / $totalTargetLettersInGrid) * 100) : 0;

        // Yorumlama artık gerekli değil (gösterilmeyecek)
        // $interpretation = ...

        // --- Kayıt Mekanizması (survey_participants'a) ---
        try {
            // survey_participants tablosunda 'score' sütunu OLMALIDIR.
            $sql = "INSERT INTO survey_participants (name, class, survey_id, admin_id, score, created_at)
                    VALUES (?, ?, ?, ?, ?, NOW())";
            // Eğer created_at sütunu yoksa:
            // $sql = "INSERT INTO survey_participants (name, class, survey_id, admin_id, score) VALUES (?, ?, ?, ?, ?)";

            $stmt = $pdo->prepare($sql);
            $insertSuccess = $stmt->execute([
                $name,
                $class,
                $testId,            // Bu testin ID'si (10)
                $adminId,
                $scorePercentage    // Hesaplanan skor
            ]);

            if ($insertSuccess) {
                // --- BAŞARILI KAYIT -> YÖNLENDİRME ---
                if (file_exists('tamamlandi.php')) {
                     header('Location: tamamlandi.php');
                     exit(); // Yönlendirme sonrası betiği durdurmak önemlidir
                } else {
                    // tamamlandi.php yoksa fallback (hatayı logla ve basit mesaj göster)
                    error_log("take-test-10.php: tamamlandi.php bulunamadı!");
                    // Ekrana basit bir mesaj bas ve çık
                     echo "<!DOCTYPE html><html lang='tr'><head><meta charset='UTF-8'><title>Başarılı</title></head><body><p>Testiniz başarıyla kaydedildi. Teşekkür ederiz.</p></body></html>";
                     exit();
                }
                // --- Bitiş: Yönlendirme ---
            } else {
                // Ekleme başarısız olursa hata mesajı ayarla
                $error = "Sonuç kaydedilirken bir hata oluştu. Lütfen tekrar deneyin.";
            }

        } catch (PDOException $e) {
            // Veritabanı hatalarını yakala ve logla/bildir
            error_log("Veritabanı Kayıt Hatası (Burdon): " . $e->getMessage());
             if ($e->getCode() == '42S02') { $error = "Veritabanı hatası: Sonuçların kaydedileceği tablo (survey_participants) bulunamadı."; }
             elseif ($e->getCode() == '42S22') { $error = "Veritabanı hatası: Gerekli sütun ('score'?) 'survey_participants' tablosunda bulunamadı veya başka bir sütun eksik/yanlış."; }
             else { $error = "Sonuç kaydedilirken beklenmedik bir veritabanı hatası oluştu."; }
        } catch (Exception $e) { // Diğer olası hatalar
             error_log("Genel Kayıt Hatası (Burdon): " . $e->getMessage());
             $error = "Beklenmeyen bir hata oluştu.";
        }
        // --- Bitiş: Kayıt Mekanizması ---
    } // if (empty($name) || empty($class)) sonu
} // if ($_SERVER['REQUEST_METHOD'] === 'POST') sonu

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($testTitle) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* --- Stil Bloğu (Öncekiyle aynı) --- */
        body { font-family: sans-serif; line-height: 1.6; background-color: #f0fdf4; color: #2c3e50; margin: 0; padding: 20px; }
        .container { max-width: 900px; margin: 40px auto; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); text-align: left; background-color: #ffffff; }
        .info label { display: block; margin-bottom: 5px; font-weight: 600; }
        .info input, .info select { padding: 8px; width: 100%; box-sizing: border-box; border: 1px solid #ccc; border-radius: 4px; font-size: 1em; color: #2c3e50; height: 40px; background-color: white; }
        .info input:focus, .info select:focus { border-color: #15803d; box-shadow: 0 0 0 2px rgba(34, 197, 94, 0.3); outline: none; }
        .info select { appearance: none; background-image: url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22292.4%22%20height%3D%22292.4%22%3E%3Cpath%20fill%3D%22%23007bff%22%20d%3D%22M287%2069.4a17.6%2017.6%200%200%200-13-5.4H18.4c-5%200-9.3%201.8-12.9%205.4A17.6%2017.6%200%200%200%200%2082.2c0%205%201.8%209.3%205.4%2012.9l128%20127.9c3.6%203.6%207.8%205.4%2012.8%205.4s9.2-1.8%2012.8-5.4L287%2095c3.5-3.5%205.4-7.8%205.4-12.8%200-5-1.9-9.2-5.5-12.8z%22%2F%3E%3C%2Fsvg%3E'); background-repeat: no-repeat; background-position: right .7em top 50%; background-size: .65em auto; padding-right: 2.5em; }
        .error-message { color: #b91c1c; background-color: #fee2e2; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; border: 1px solid #fca5a5; font-weight: bold; text-align: center; }
        strong { font-weight: bold; }
        .hidden { display: none; }
        .nav-btn { padding: 12px 30px; border-radius: 8px; font-weight: 600; transition: all 0.2s ease-in-out; cursor: pointer; border: none; }
        .nav-btn.submit { background: #2563eb; color: white; }
        .nav-btn.submit:hover { background: #1d4ed8; }
        .nav-btn:disabled { background-color: #9ca3af; cursor: not-allowed; opacity: 0.7;}

        /* Teste Özel Stiller */
        .instructions { background-color: #eefbf3; border-left: 4px solid #22c55e; padding: 15px; margin-bottom: 25px; border-radius: 4px; }
        .instructions ul { margin-top: 0.5rem; list-style-type: disc; margin-left: 20px; }
        .instructions ul ul { margin-top: 0.25rem; }
        .grid-section { font-family: 'Courier New', Courier, monospace; line-height: 1.7; font-size: 1.1rem; border: 1px solid #d1d5db; padding: 15px; margin-bottom: 1.5rem; overflow-x: auto; background-color: #f9fafb; border-radius: 6px; }
        .grid-section span.letter { display: inline-block; padding: 0 1px; cursor: pointer; border-radius: 3px; transition: background-color 0.1s ease-in-out; min-width: 15px; text-align: center; user-select: none; }
        .grid-section span.letter:hover { background-color: #dcfce7; }
        .grid-section span.letter.selected { background-color: #22c55e; color: white; font-weight: bold; }
        #timer { font-size: 1.5rem; font-weight: bold; color: #15803d; text-align: center; margin-bottom: 20px; padding: 10px; background-color: #f0fdf4; border-radius: 6px; border: 1px dashed #bbf7d0; }
        /* .results-section stili artık gereksiz */

    </style>
</head>
<body class="bg-[#f0fdf4] min-h-screen p-4">
    <div class="container max-w-3xl mx-auto rounded-xl shadow-lg p-6 md:p-8 mt-10 bg-white">
        <h2 class="text-center text-xl md:text-2xl font-bold mb-6 pb-4 border-b-2 border-[#dcfce7]">
            <?= htmlspecialchars($testTitle) ?>
        </h2>

        <?php if (!empty($error)): ?>
            <div class="error-message mb-6"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (is_null($adminId)): // Geçerli admin yoksa sadece uyarı gösterilir ?>
             <p class="text-center text-red-600 font-semibold">Teste devam etmek için geçerli bir bağlantı gereklidir.</p>
        <?php else: // Admin geçerliyse formu göster (POST sonrası hata varsa hata mesajı da görünür) ?>
            <form method="POST" id="burdonTestForm" novalidate>
                 <div class="info grid grid-cols-1 md:grid-cols-3 gap-4 md:gap-6 mb-6">
                    <div>
                        <label for="studentName" class="block font-semibold mb-2">Ad Soyad:</label>
                        <input type="text" name="student_name" id="studentName" required>
                    </div>
                    <div>
                        <label for="studentClass" class="block font-semibold mb-2">Sınıf:</label>
                        <input type="text" name="student_class" id="studentClass" required>
                    </div>
                    <div>
                        <label for="schoolLevel" class="block font-semibold mb-2">Okul Düzeyi:</label>
                        <select name="school_level" id="schoolLevel" required>
                             <option value="" disabled selected>-- Seçiniz --</option>
                             <option value="ortaokul">Ortaokul (5-8. Sınıf)</option>
                             <option value="lise">Lise (9-12. Sınıf)</option>
                         </select>
                    </div>
                </div>

                <div class="instructions">
                    <h3 class="text-lg font-semibold mb-2 text-[#166534]">Yönerge</h3>
                    <p>Bu test dikkat gücünüzü ölçmeyi amaçlamaktadır.</p>
                    <ul class="space-y-1">
                         <li>Lütfen önce Ad Soyad, Sınıf ve Okul Düzeyi bilgilerinizi giriniz.</li>
                         <li>Aşağıdaki harf ızgarasında bulunan bütün <strong class="text-[#16a34a]">'a', 'b', 'd' ve 'g'</strong> harflerini bulup üzerlerine tıklayarak işaretleyiniz.</li>
                         <li>İşaretlediğiniz harfin seçimi kalkması için tekrar tıklayabilirsiniz.</li>
                        <li>Bir satırı gözden geçirirken bulduğunuz tüm hedef harfleri işaretleyiniz.</li>
                        <li>Test süresi seçtiğiniz okul düzeyine göre belirlenecektir:
                            <ul class="list-disc ml-5">
                                <li>Ortaokul: <strong class="text-[#16a34a]">3 dakika</strong></li>
                                <li>Lise: <strong class="text-[#16a34a]">2 dakika</strong></li>
                            </ul>
                         </li>
                         <li>Süre dolduğunda test otomatik olarak tamamlanacaktır.</li>
                         <li>İsterseniz süreniz dolmadan "Testi Bitir" butonuna basabilirsiniz.</li>
                    </ul>
                    <p class="mt-3 font-semibold">Hazır olduğunuzda aşağıdaki butona basarak testi başlatabilirsiniz.</p>
                </div>

                <div id="test-area" class="hidden">
                    <div id="timer">00:00</div>

                    <div id="letter-grid-container">
                        <?php
                        $lettersPerSection = $lettersPerRow * $rowsPerSection;
                        for ($section = 0; $section < 3; $section++):
                            echo '<div class="grid-section">'; // Bölüm başlangıcı
                            $startIndex = $section * $lettersPerSection;
                            $endIndex = $startIndex + $lettersPerSection;
                            for ($i = $startIndex; $i < $endIndex && $i < count($gridArray); $i++) {
                                // Tıklanabilir harf span'ı
                                echo '<span class="letter" data-index="' . $i . '">' . htmlspecialchars($gridArray[$i]) . '</span>';
                                // Satır sonu ekle
                                if (($i + 1) % $lettersPerRow == 0) { echo "<br>"; }
                            }
                            echo '</div>'; // Bölüm sonu
                        endfor;
                        ?>
                    </div>
                    <input type="hidden" name="selected_indices" id="selectedIndicesInput">
                    <div class="navigation flex justify-center mt-6">
                        <button type="submit" id="submitBtn" class="nav-btn submit">Testi Bitir</button>
                    </div>
                </div>

                 <div id="start-button-area" class="text-center mt-6">
                     <button type="button" id="startBtn" class="nav-btn submit bg-green-600 hover:bg-green-700 text-lg px-6 py-3 rounded-lg shadow transition duration-150 ease-in-out">
                         <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 inline-block mr-2" viewBox="0 0 20 20" fill="currentColor">
                           <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-8.707l-3-3a1 1 0 00-1.414 1.414L10.586 9H7a1 1 0 100 2h3.586l-1.293 1.293a1 1 0 101.414 1.414l3-3a1 1 0 000-1.414z" clip-rule="evenodd" />
                         </svg>
                         Teste Başla
                     </button>
                 </div>

            </form>
        <?php endif; ?> </div> <script>
          // Gerekli DOM elementlerini seç
          const startBtn = document.getElementById('startBtn');
          const testArea = document.getElementById('test-area');
          const letterGridContainer = document.getElementById('letter-grid-container');
          const timerDisplay = document.getElementById('timer');
          const form = document.getElementById('burdonTestForm');
          const selectedIndicesInput = document.getElementById('selectedIndicesInput');
          const submitBtn = document.getElementById('submitBtn');
          const nameInputTest = document.getElementById('studentName');
          const classInputTest = document.getElementById('studentClass');
          const levelSelect = document.getElementById('schoolLevel');
          const startButtonArea = document.getElementById('start-button-area');

          // Zaman limitlerini PHP'den al
          const timeLimitOrta = <?= $timeLimitOrta ?>;
          const timeLimitLise = <?= $timeLimitLise ?>;

          // Durum değişkenleri
          let timerInterval = null;
          let timeLeft = 0;
          let selectedIndices = new Set(); // Seçilen harf indekslerini tutar

          // Zamanı MM:SS formatına çevirir
          function formatTime(seconds) {
              const minutes = Math.floor(seconds / 60);
              const remainingSeconds = seconds % 60;
              return `${String(minutes).padStart(2, '0')}:${String(remainingSeconds).padStart(2, '0')}`;
          }

          // Zamanlayıcı ekranını günceller
          function updateTimerDisplay() {
              timerDisplay.textContent = formatTime(timeLeft);
          }

          // Testi bitirme işlemleri
          function endTest() {
              clearInterval(timerInterval); // Zamanlayıcıyı durdur
              timerDisplay.textContent = "Süre Doldu!";
              timerDisplay.style.color = '#b91c1c'; // Rengi kırmızı yap
              // Harfleri tıklanamaz yap
              if (letterGridContainer) { // Elementin varlığını kontrol et
                letterGridContainer.querySelectorAll('.letter').forEach(span => span.style.pointerEvents = 'none');
              }
              if (submitBtn) { // Elementin varlığını kontrol et
                 submitBtn.disabled = true; // Bitir butonunu devre dışı bırak
              }
              // Formu otomatik gönder
              if (form && !form.dataset.submitted) { // Form varsa ve daha önce gönderilmediyse
                  // Göndermeden önce seçili indeksleri inputa yaz
                  selectedIndicesInput.value = Array.from(selectedIndices).join(',');
                  form.dataset.submitted = 'true'; // Gönderildi olarak işaretle
                  // Formu göndermeden önce küçük bir bekleme
                   setTimeout(() => { form.submit(); }, 100);
              }
          }

          // Zamanlayıcıyı belirtilen süre ile başlatır
          function startTimer(startTime) {
              timeLeft = startTime;
              updateTimerDisplay(); // İlk gösterim
              timerInterval = setInterval(() => {
                  timeLeft--;
                  updateTimerDisplay();
                  if (timeLeft <= 0) {
                      endTest(); // Süre bittiğinde testi bitir
                  }
              }, 1000); // Her saniyede bir çalıştır
          }

          // Başlat Butonu Tıklama Olayı
          if (startBtn) {
              startBtn.addEventListener('click', () => {
                  const selectedLevel = levelSelect ? levelSelect.value : ''; // levelSelect var mı kontrol et

                  // Gerekli bilgilerin girilip girilmediğini kontrol et
                  if (!nameInputTest || nameInputTest.value.trim() === '' || !classInputTest || classInputTest.value.trim() === '') {
                      alert('Lütfen başlamadan önce Ad Soyad ve Sınıf bilgilerinizi girin.');
                      return;
                  }
                  if (!levelSelect || selectedLevel === '') {
                      alert('Lütfen başlamadan önce Okul Düzeyini seçin.');
                      return;
                  }

                  // Seçilen seviyeye göre zaman limitini belirle
                  const timeLimit = (selectedLevel === 'lise') ? timeLimitLise : timeLimitOrta;

                  // Arayüzü güncelle: test alanını göster, başlat butonunu gizle
                  if (testArea) testArea.classList.remove('hidden');
                  if (startButtonArea) startButtonArea.classList.add('hidden');

                  // Zamanlayıcıyı başlat
                  startTimer(timeLimit);

                  // Kişisel bilgi alanlarını kilitle
                  if (nameInputTest) nameInputTest.readOnly = true;
                  if (classInputTest) classInputTest.readOnly = true;
                  if (levelSelect) levelSelect.disabled = true;
              });
          }

          // Harf Tıklama Olayı (Event Delegation)
          if (letterGridContainer) {
              letterGridContainer.addEventListener('click', (event) => {
                  // Sadece '.letter' sınıfına sahip bir elemana tıklandıysa
                  if (event.target.classList.contains('letter')) {
                      const span = event.target;
                      const index = parseInt(span.dataset.index, 10); // Harfin indeksini al

                       // Eğer zamanlayıcı durduysa (süre dolduysa) tıklamayı engelle
                       if (timeLeft <= 0 && timerInterval == null) return;

                      // Seçimi tersine çevir
                      if (selectedIndices.has(index)) {
                          selectedIndices.delete(index); // Seçiliyse kaldır
                          span.classList.remove('selected'); // Görsel seçimi kaldır
                      } else {
                          selectedIndices.add(index); // Seçili değilse ekle
                          span.classList.add('selected'); // Görsel olarak seç
                      }
                  }
              });
          }

          // Form Gönderme Olayı (Manuel "Testi Bitir")
          if (form) {
              form.addEventListener('submit', (event) => {
                   // Güvenlik için tekrar kontrol
                   if (!nameInputTest || nameInputTest.value.trim() === '' || !classInputTest || classInputTest.value.trim() === '' || !levelSelect || levelSelect.value === '') {
                       alert('Lütfen Ad Soyad, Sınıf ve Okul Düzeyi bilgilerini kontrol edin.');
                       event.preventDefault(); // Gönderimi engelle
                       return;
                   }
                   // Gönderilmeden önce seçili indeksleri gizli inputa yaz
                   if (selectedIndicesInput) {
                       selectedIndicesInput.value = Array.from(selectedIndices).join(',');
                   }
                   if (timerInterval) { // Zamanlayıcı çalışıyorsa durdur
                       clearInterval(timerInterval);
                       timerInterval = null; // Temizle
                   }
                   if (submitBtn) { // Buton varsa devre dışı bırak
                     submitBtn.disabled = true;
                   }
                   form.dataset.submitted = 'true'; // Gönderildi olarak işaretle
              });
          }

     </script>

</body>
</html>