<?php
session_start();
require_once '../src/config.php'; // Veritabanı bağlantısı için

// Hata raporlamayı etkinleştir (Geliştirme ortamı için uygundur, canlı ortamda kapatılmalıdır)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Giriş kontrolü
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super-admin'])) {
    header('Location: ../login.php');
    exit();
}

$adminId = $_SESSION['user_id']; // Mevcut giriş yapmış admin ID'si

// Psikometrik logo yolu - assets klasöründeki PsikoMetrik.png dosyasına işaret ediyor
// Dosya adının ve büyük/küçük harf duyarlılığının doğru olduğundan emin olun.
$psikometrikWebURL = '../assets/Psikometrik.png';
$institutionWebURL = null; // Kurum logosu için web yolu (DB'den çekilecek)


// --- HATA AYIKLAMA ÇIKTILARI BAŞLANGICI (İsteğe bağlı, kaldırılabilir) ---
/*
echo "<pre>GET Parametreleri: ";
print_r($_GET);
echo "</pre>";

$surveyIdDebug = filter_input(INPUT_GET, 'survey_id', FILTER_VALIDATE_INT);
$selectedParticipantIdsStringDebug = filter_input(INPUT_GET, 'participant_ids', FILTER_SANITIZE_STRING);

echo "<pre>Alınan survey_id (filter_input): ";
var_dump($surveyIdDebug);
echo "</pre>";

echo "<pre>Alınan participant_ids (string, filter_input): ";
var_dump($selectedParticipantIdsStringDebug);
echo "</pre>";
*/
// --- HATA AYIKLAMA ÇIKTILARI SONU ---


$surveyId = filter_input(INPUT_GET, 'survey_id', FILTER_VALIDATE_INT);
$selectedParticipantIdsString = filter_input(INPUT_GET, 'participant_ids', FILTER_SANITIZE_STRING);


$reportData = null;
$surveyTitle = 'Rapor'; // Varsayılan başlık
$error = null; // Hata değişkenini tanımla
$allAnswersWithQuestionDetails = []; // Cevapları ve ilgili soru detaylarını tutacak array


if (!$surveyId || empty($selectedParticipantIdsString)) {
    $error = "Rapor görüntülemek için geçerli anket ve katılımcı bilgisi gerekli.";
} else {
    // Katılımcı ID'lerini array'e çevir ve sanitize et
    $selectedParticipantIds = explode(',', $selectedParticipantIdsString);
    $sanitizedParticipantIds = array_filter($selectedParticipantIds, 'filter_var', FILTER_VALIDATE_INT);

    /*
    echo "<pre>Sanitized Participant IDs (array): ";
    print_r($sanitizedParticipantIds);
    echo "</pre>";
    */


    if (empty($sanitizedParticipantIds)) {
        $error = "Geçersiz katılımcı ID'leri.";
    } else {
        $participantPlaceholders = implode(',', array_fill(0, count($sanitizedParticipantIds), '?'));

        // Anket bilgilerini çek (admin_id'yi çekme kaldırıldı)
        $surveyStmt = $pdo->prepare("SELECT title FROM surveys WHERE id = ?"); // admin_id kaldırıldı
        try {
            $surveyStmt->execute([$surveyId]);
            $survey = $surveyStmt->fetch(PDO::FETCH_ASSOC);

            if ($survey) {
                $surveyTitle = htmlspecialchars($survey['title']) . ' Raporu';
                // Anketin admin_id'si artık buradan gelmiyor
            } else {
                $error = "Belirtilen anket bulunamadı.";
            }
        } catch (PDOException $e) {
             $error = (isset($error) ? $error . " " : "") . "Anket bilgileri çekilirken bir veritabanı hatası oluştu: " . $e->getMessage();
        }

        // Seçilen katılımcılardan birinin admin_id'sini çekmek için survey_participants tablosunu kullan
        $surveyAdminId = null;
        if (!isset($error) && !empty($sanitizedParticipantIds)) {
            // Sadece ilk katılımcının admin_id'sini çekmek yeterli, aynı anket için aynı admin olmalı
            $stmt_participant_admin = $pdo->prepare("SELECT admin_id FROM survey_participants WHERE id = ? LIMIT 1");
            try {
                $stmt_participant_admin->execute([$sanitizedParticipantIds[0]]);
                $surveyAdminId = $stmt_participant_admin->fetchColumn();
            } catch (PDOException $e) {
                 error_log("Katılımcı admin_id çekilirken veritabanı hatası (view-report): " . $e->getMessage());
                 // Hata mesajını kullanıcıya göstermeyebiliriz, sadece loglayabiliriz.
            }
        }


        // Anketin admin_id'si varsa, kurum logosu yolunu users tablosundan çek
        if (!isset($error) && !empty($surveyAdminId)) {
            $stmt_institution_logo = $pdo->prepare("SELECT institution_logo_path FROM users WHERE id = ?");
            try {
                $stmt_institution_logo->execute([$surveyAdminId]);
                $institutionLogoPathFromDB = $stmt_institution_logo->fetchColumn();

                // Kurum logosu yolunu belirle (DB'den geldiyse ve dosya varsa)
                if (!empty($institutionLogoPathFromDB)) {
                   $rawInstitutionPathFromDB = $institutionLogoPathFromDB;
                   // Yolu temizle ve kök dizine göre ayarla
                   $cleanRelativePath = ltrim(str_replace('..', '', $rawInstitutionPathFromDB), '/');
                   $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/..');
                   $fullServerPath = rtrim($docRoot, '/') . '/' . $cleanRelativePath;
                   if (file_exists($fullServerPath)) { $institutionWebURL = '/' . $cleanRelativePath; }
                   else { error_log("Kurum logosu dosyası bulunamadı (view-report): " . $fullServerPath); }
                }
            } catch (PDOException $e) {
                 error_log("Kurum logosu çekilirken veritabanı hatası (view-report): " . $e->getMessage());
                 // Hata mesajını kullanıcıya göstermeyebiliriz, sadece loglayabiliriz.
            }
        }


        // Seçili katılımcıların ilgili ankete ait cevaplarını ve soru detaylarını çek
        // survey_answers.question_id, survey_questions.question_number ile eşleşiyor
        if (!isset($error)) { // Hata olmadıysa devam et
             $answersStmt = $pdo->prepare("
                 SELECT
                     sa.participant_id,
                     sa.question_id, -- Bu aslında question_number
                     sa.answer_text,
                     sq.question_number,
                     sq.question_text,
                     sq.question_type,
                     sq.options
                 FROM
                     survey_answers sa
                 JOIN
                     survey_questions sq ON sa.question_id = sq.question_number AND sa.survey_id = sq.survey_id -- Join koşulu düzeltildi
                 WHERE
                     sa.survey_id = ? AND sa.participant_id IN ($participantPlaceholders)
                 ORDER BY
                     sq.question_number ASC -- Soru numarasına göre sırala
             ");

             // Parametreleri birleştir: önce surveyId, sonra katılımcı ID'leri
             $params = array_merge([$surveyId], $sanitizedParticipantIds);

             try {
                 $answersStmt->execute($params);
                 $allAnswersWithQuestionDetails = $answersStmt->fetchAll(PDO::FETCH_ASSOC);

                 // --- HATA AYIKLAMA ÇIKTISI: Ham Cevap Verisi (Join Sonrası) ---
                 /*
                 echo "<pre>Ham Cevap Verisi (Join Sonrası): ";
                 print_r($allAnswersWithQuestionDetails);
                 echo "</pre>";
                 */
                 // --- HATA AYIKLAMA ÇIKTISI SONU ---


             } catch (PDOException $e) {
                 $error = (isset($error) ? $error . " " : "") . "Cevaplar ve soru detayları çekilirken bir veritabanı hatası oluştu: " . $e->getMessage();
                 $allAnswersWithQuestionDetails = []; // Hata durumunda boş array
             }
        }


        // Rapor verisini oluştur
        $reportData = []; // question_number bazında rapor verisi
        $questionsData = []; // Soru numarası bazında soru detayları (tekrar edenleri engellemek için)

        if (!isset($error) && !empty($allAnswersWithQuestionDetails)) { // Hata olmadıysa ve cevaplar çekildiyse devam et

             foreach ($allAnswersWithQuestionDetails as $row) {
                 $questionNumber = $row['question_number'];
                 $answerText = $row['answer_text'];

                 // Soru detaylarını kaydet (ilk kez karşılaşıldığında)
                 if (!isset($questionsData[$questionNumber])) {
                     $questionsData[$questionNumber] = [
                         'question_text' => $row['question_text'],
                         'question_type' => $row['question_type'],
                         'options' => json_decode($row['options'], true),
                     ];
                 }

                 // answer_text sayımını yap
                 if (!isset($reportData[$questionNumber]['answer_counts'][$answerText])) {
                     $reportData[$questionNumber]['answer_counts'][$answerText] = 0;
                 }
                 $reportData[$questionNumber]['answer_counts'][$answerText]++;
             }

             // Rapor verisini soru numarasına göre sırala
             ksort($reportData);

        } else if (!isset($error)) {
             // Cevap bulunamadıysa ancak hata yoksa
             $error = "Seçilen katılımcılar için bu ankete ait yanıt bulunamadı.";
        }
    }
}

// HTML kaçış fonksiyonu
function escapeHTML($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

// Psikometrik logo dosyasının varlığını kontrol et
$psikometrikLogoExists = false;
if ($psikometrikWebURL) {
     $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/..');
     $fullPsikoServerPath = rtrim($docRoot, '/') . '/' . ltrim(str_replace('..', '', $psikometrikWebURL), '/');
     if (file_exists($fullPsikoServerPath)) {
         $psikometrikLogoExists = true;
     } else {
         error_log("Psikometrik logo dosyası bulunamadı (view-report): " . $fullPsikoServerPath);
     }
}


if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $surveyTitle; ?> | Anket Platformu</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Genel Stil */
        body {
            font-family: sans-serif;
            line-height: 1.6;
            background-color: #f8fafc; /* Çok açık mavi-gri arka plan - Tema ile uyumlu */
            color: #334155; /* Koyu gri metin rengi - Tema ile uyumlu */
            padding: 1rem;
            font-size: 0.9rem; /* Genel metin boyutunu küçült */
        }

        .container {
             width: 100%;
             max-width: 800px; /* Rapor alanı için max genişlik */
             margin-left: auto;
             margin-right: auto;
             padding: 1.5rem;
             background-color: #ffffff;
             border-radius: 0.5rem;
             box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
             border: 1px solid #e2e8f0;
        }

        /* Sayfa Başlığı ve Logoları İçeren Üst Kısım */
        .page-header {
            background-color: #ffffff; /* Beyaz arka plan */
            padding: 10px 25px; /* view-result-31.php'den */
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e0e0e0; /* view-result-31.php'den */
            box-shadow: 0 1px 3px rgba(0,0,0,0.05); /* view-result-31.php'den */
            margin-bottom: 1.5rem; /* Konteyner ile arasına boşluk */
        }
         .page-header .logo-left,
         .page-header .logo-right {
             flex: 1; /* Alanı doldur */
             display: flex;
             align-items: center;
         }
         .page-header .logo-left {
             justify-content: flex-start; /* Sol logoyu sola hizala */
         }
         .page-header .logo-right {
             justify-content: flex-end; /* Sağ logoyu sağa hizala */
         }
         .page-header .logo-left img,
         .page-header .logo-right img {
            max-height: 50px; /* Logo boyutu - view-result-31.php'den */
            width: auto;
         }
         .page-header .page-title {
             flex: 2; /* Başlık için daha fazla alan - view-result-31.php'den */
             text-align: center;
             font-size: 1.8rem; /* view-result-31.php'den */
             color: #1f2937; /* view-result-27.php teması */
             margin: 0;
         }


        /* Rapor Başlığı ve Buton Konteyneri (Container içinde) */
        .report-main-title-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid #e2e8f0; /* Alt çizgi ekle */
            padding-bottom: 1rem; /* Çizgi altına boşluk */
        }

        .report-main-title-container h1 {
            font-size: 1.5rem; /* Ana başlık boyutunu küçült */
            font-weight: bold;
            margin: 0; /* Varsayılan margin'leri kaldır */
            color: #1e293b;
            text-align: left; /* Sola hizala */
            flex-grow: 1; /* Başlığın kalan alanı kaplamasını sağla */
        }

        .output-buttons {
            display: flex;
            gap: 0.5rem; /* Butonlar arasına boşluk */
            align-items: center;
        }

        .output-button {
            background-color: #22c55e; /* Tailwind green-500 - Varsayılan yeşil */
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 0.25rem;
            text-decoration: none;
            font-size: 0.9rem; /* Buton metin boyutunu küçült */
            transition: background-color 0.2s ease-in-out;
            border: none; /* Varsayılan border'ı kaldır */
            cursor: pointer;
        }

        .output-button:hover {
            background-color: #16a34a; /* Tailwind green-600 */
        }

        .print-button {
             background-color: #0ea5e9; /* Tailwind sky-500 - Mavi */
        }
         .print-button:hover {
             background-color: #0284c7; /* Tailwind sky-600 */
         }


        .question-section {
            background-color: #f1f5f9; /* Tailwind slate-100 - Açık gri arka plan */
            padding: 1.25rem; /* p-5 */
            border-radius: 0.375rem; /* rounded-md */
            margin-bottom: 1.5rem; /* mb-6 */
            border: 1px solid #e2e8f0; /* Çok açık gri kenarlık */
        }

        .question-section h2 {
             font-size: 1.125rem; /* Soru başlığı boyutunu küçült */
             font-weight: 600;
             margin-top: 0; /* Üst boşluğu kaldır */
             margin-bottom: 1rem; /* Alt boşluk */
             color: #1e293b; /* Koyu gri */
             border-bottom: 1px solid #cbd5e1; /* Tailwind slate-300 - Daha belirgin çizgi */
             padding-bottom: 0.75rem; /* Çizgi altına boşluk */
        }

        .answer-counts-table { /* Tablo için yeni sınıf adı */
            width: 100%;
            border-collapse: collapse; /* Kenarlıkları birleştir */
            margin-top: 1rem; /* Üst boşluk */
        }

        .answer-counts-table th,
        .answer-counts-table td {
            text-align: left; /* Metni sola hizala */
            padding: 0.5rem 0.75rem; /* Padding'i küçült */
            border-bottom: 1px solid #e2e8f0; /* Çok açık gri kenarlık */
            font-size: 0.9rem; /* Tablo metin boyutunu küçült */
        }

        .answer-counts-table th {
            background-color: #e2e8f0; /* Tailwind slate-200 - Hafif gri başlık arka planı */
            font-weight: 600; /* font-semibold */
            color: #475569; /* Tailwind slate-600 */
        }

        .answer-counts-table tbody tr:nth-child(even) {
            background-color: #f8fafc; /* Tailwind slate-50 - Zebra deseni */
        }

        .answer-counts-table tbody tr:hover {
            background-color: #e2e8f0; /* Tailwind slate-200 - Hover efekti */
        }

        .answer-counts-table td:last-child {
            text-align: right; /* Sayı sütununu sağa hizala */
            font-weight: 600; /* Sayıyı kalın yap */
            color: #0e7490; /* Çivit mavisi tonu */
        }


        .error-message {
            background-color: #fee2e2; /* Tailwind red-100 */
            color: #dc2626; /* Tailwind red-700 */
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
        }

        .back-link {
            display: inline-block;
            margin-top: 2rem;
            color: #0ea5e9; /* Tailwind sky-500 */
            text-decoration: underline;
        }

        /* Yazdırma için gizlenecek elementler */
        @media print {
            .output-buttons {
                display: none;
            }
            .back-link {
                display: none;
            }
             .page-header {
                 display: flex !important; /* Yazdırmada göster */
                 padding: 10px 0;
                 border-bottom: 1px solid #000;
                 box-shadow: none;
                 justify-content: space-between;
                 align-items: center;
             }
             .page-header .logo-left img,
             .page-header .logo-right img {
                max-height: 40px; /* Yazdırma boyutu */
                width: auto;
             }
             .page-header .logo-left { justify-content: flex-start; }
             .page-header .logo-right { justify-content: flex-end; }
             .page-header .page-title {
                 flex: 2;
                 text-align: center;
                 font-size: 14pt; /* Yazdırma boyutu */
                 color: #000;
                 margin: 0;
             }
        }
    </style>
</head>
<body>

    <div class="page-header"> <div class="logo-left">
            <?php if(!empty($institutionWebURL)): ?>
                <img src="<?= htmlspecialchars($institutionWebURL) ?>" alt="Kurum Logosu">
            <?php else: ?><span>&nbsp;</span><?php endif; ?>
        </div>
        <div class="page-title">
            <?= htmlspecialchars($surveyTitle) ?> Sonuçları
        </div>
        <div class="logo-right">
            <?php if ($psikometrikLogoExists): ?>
                <img src="<?= htmlspecialchars($psikometrikWebURL) ?>" alt="Psikometrik.Net Logosu">
            <?php else: ?>
                <span>Psikometrik.Net</span>
            <?php endif; ?>
        </div>
    </div>


    <div class="container">
        <div class="report-main-title-container">
             <h1><?php echo $surveyTitle; ?></h1>
             <div class="output-buttons no-print"> <?php if ($reportData !== null && !empty($reportData)): // Rapor verisi varsa butonları göster ?>
                    <button id="print-report" class="output-button print-button">Yazdır</button>
                    <button id="export-excel" class="output-button">Excel İndir</button>
                 <?php endif; ?>
             </div>
        </div>


        <?php if (isset($error)): ?>
            <div class="error-message"><?= $error ?></div>
        <?php elseif ($reportData !== null && !empty($reportData)): ?>
            <?php foreach ($reportData as $questionNumber => $data): ?>
                <?php
                // Soru detaylarını questionsData'dan al
                $questionDetails = $questionsData[$questionNumber] ?? null;
                if (!$questionDetails) continue; // Soru detayları bulunamazsa atla

                $questionText = $questionDetails['question_text'];
                $questionType = $questionDetails['question_type'];
                $options = $questionDetails['options']; // Options already decoded
                $answerCounts = $data['answer_counts'] ?? [];
                ?>

                <div class="question-section">
                    <h2>Soru <?php echo escapeHTML($questionNumber); ?>: <?php echo escapeHTML($questionText); ?></h2>

                    <?php
                    // Eğer bu soru için yanıt verisi varsa
                    if (!empty($answerCounts)):
                    ?>
                         <div class="answer-counts">
                             <table class="answer-counts-table">
                                 <thead>
                                     <tr>
                                         <th>Yanıt</th>
                                         <th>Katılımcı Sayısı</th>
                                     </tr>
                                 </thead>
                                 <tbody>
                                <?php
                                // answer_counts'taki her benzersiz answer_text değerini ve sayısını listele
                                foreach ($answerCounts as $answerText => $count):
                                     if ($answerText !== ''): // Boş yanıtları gösterme
                                     ?>
                                         <tr>
                                             <td>"<?php echo escapeHTML($answerText); ?>"</td>
                                             <td><?php echo $count; ?></td>
                                         </tr>
                                     <?php endif; ?>
                                <?php endforeach; ?>
                                 </tbody>
                             </table>
                         </div>
                    <?php else: ?>
                         <p>Bu soru için yanıt verisi bulunamadı.</p>
                    <?php endif; // Kapatılan if (!empty($answerCounts)) ?>

                </div> <?php endforeach; // Kapatılan foreach ($reportData as ...) ?>
        <?php else: // Kapatılan elseif ($reportData !== null && !empty($reportData)) ?>
             <p>Seçilen katılımcılar için rapor verisi bulunamadı.</p>
        <?php endif; // Kapatılan if (isset($error)) / elseif ($reportData ...) ?>

        <a href="dashboard.php" class="back-link no-print">Dashboard'a Geri Dön</a> </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const exportExcelButton = document.getElementById('export-excel');
            const printButton = document.getElementById('print-report');


            if (exportExcelButton) {
                exportExcelButton.addEventListener('click', function() {
                    exportReportToXls(); // Fonksiyon adı XLS'ye göre güncellendi
                });
            }

             if (printButton) {
                 printButton.addEventListener('click', function() {
                     window.print(); // Tarayıcının yazdırma iletişim kutusunu açar
                 });
             }


            function exportReportToXls() { // Fonksiyon adı güncellendi
                let tableHtml = `
                    <html>
                    <head>
                        <meta charset="UTF-8">
                        <title><?php echo escapeHTML($surveyTitle); ?> Raporu</title>
                        <style>
                            table {
                                border-collapse: collapse;
                                width: 100%;
                                margin-bottom: 20px; /* Tablolar arasına boşluk */
                            }
                            th, td {
                                border: 1px solid #000;
                                padding: 8px;
                                text-align: left;
                                vertical-align: top; /* Metin üstte hizalansın */
                            }
                            th {
                                background-color: #f2f2f2;
                                font-weight: bold;
                            }
                             h1, h2 {
                                 color: #333;
                             }
                             h1 { font-size: 20pt; margin-bottom: 15px; text-align: center;}
                             h2 { font-size: 16pt; margin-top: 20px; margin-bottom: 10px; border-bottom: 1px solid #ccc; padding-bottom: 5px;}

                        </style>
                    </head>
                    <body>
                        <h1><?php echo escapeHTML($surveyTitle); ?> Raporu</h1>
                `;

                // Her soru için
                const questionSections = document.querySelectorAll('.question-section');
                questionSections.forEach(section => {
                    const questionTitle = section.querySelector('h2').innerText;
                    tableHtml += `<h2>${escapeHTML(questionTitle)}</h2>`; // Soru başlığı

                    const table = section.querySelector('.answer-counts-table');
                    if (table) {
                        // Tablonun HTML'ini al
                        tableHtml += table.outerHTML;
                    } else {
                        // Tablo yoksa (örn. yanıt verisi bulunamadı mesajı)
                        const noDataMessage = section.querySelector('p');
                        if(noDataMessage) {
                            tableHtml += `<p>${escapeHTML(noDataMessage.innerText.trim())}</p>`;
                        }
                    }
                    // Soru bölümleri arasına boşluk eklemek için <br> yerine CSS margin kullanıldı
                });

                tableHtml += `
                    </body>
                    </html>
                `;


                // HTML içeriğini Blob olarak oluştur
                const blob = new Blob([tableHtml], { type: 'application/vnd.ms-excel;charset=utf-8' }); // MIME tipi XLS'ye göre ayarlandı
                const url = URL.createObjectURL(blob);

                // İndirme linki oluştur
                const link = document.createElement("a");
                link.setAttribute("href", url);
                link.setAttribute("download", "anket_raporu_<?php echo $surveyId; ?>.xls"); // Dosya uzantısı XLS yapıldı

                document.body.appendChild(link); // Linki body'ye ekle
                link.click(); // İndirmeyi başlat
                document.body.removeChild(link); // Linki kaldır

                URL.revokeObjectURL(url); // Blob URL'ini serbest bırak
            }

            // HTML kaçış fonksiyonu (JavaScript için)
            function escapeHTML(str) {
                const div = document.createElement('div');
                div.appendChild(document.createTextNode(str));
                return div.innerHTML;
            }

        });
    </script>

</body>
</html>
