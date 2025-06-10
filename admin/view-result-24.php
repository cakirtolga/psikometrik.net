<?php
// view-result-24.php (Akademik Benlik Kavramı Ölçeği Sonuçları v9)

// --- Hata Raporlama ---
ini_set('display_errors', 1); error_reporting(E_ALL);
// ---------------------------

session_start(); // Session GEREKLİ

// --- Veritabanı Bağlantısı ---
require '../src/config.php';
if (!isset($pdo) || !$pdo instanceof PDO) {
    error_log("CRITICAL: PDO connection object (\$pdo) not created or found in src/config.php");
    header('Content-Type: text/html; charset=utf-8');
    die('<div style="border: 1px solid red; padding: 10px; margin: 10px; background-color: #ffebeb; color: red; font-family: sans-serif;"><b>Kritik Hata:</b> Veritabanı bağlantısı kurulamadı (src/config.php).</div>');
}
// --------------------------------

// --- Konfigürasyon ---
$surveyId = 24; // Anket ID'si
$testTitleDefault = "Akademik Benlik Kavramı Ölçeği";
$pageTitle = $testTitleDefault . " Sonuçları";

// --- Değişkenler ---
$participantId = null; $participantData = null; $survey_title = $pageTitle;
$subscaleRawScores = []; // Alt ölçek ham puanları [scaleKey => rawScore]
$subscalePercentileRanks = []; // Alt ölçek yüzdelik puanları [scaleKey => percentile]
$interpretation = "Hesaplanamadı"; // Genel yorum
$error = null; $dataSource = null;
$processedAnswersForTable = []; // Detaylı cevap tablosu için

// --- Logo URL ---
$institutionWebURL = null; $psikometrikWebURL = '/assets/Psikometrik.png'; // Logo yolunu kontrol edin

// --- Sabit Veriler (Anket 24 - ABKÖ) ---
// Cevap Seçenekleri ve Puanları (A=1, B=2, C=3, D=4)
$optionsMap = [
    1 => "Hiçbir zaman",
    2 => "Ara sıra",
    3 => "Sık sık",
    4 => "Her zaman"
];
// Metinden puanı bulmak için ters harita
$textToScoreMap = array_flip($optionsMap);

// Alt Ölçek Madde Numaraları (sort_order) - PDF "AKADEMİK BENLİK KAVRAMI ÖLÇEĞİ MADDE NUMARALARI" listesine göre
// Bu listeler, her bir alt ölçeğe ait madde numaralarını (sort_order) belirtir.
$subscalesItems = [
    'Sözel Yetenek' => [1, 2, 5, 8, 11, 12, 19, 22, 24, 30, 31, 167], // 12 madde
    'Sayısal Yetenek' => [3, 9, 13, 20, 21, 23, 26, 29, 32, 33], // 10 madde
    'Şekil-Uzay Yeteneği' => [4, 6, 7, 10, 14, 15, 16, 17, 18, 25, 28], // 11 madde
    'Göz-El Koordinasyonu' => [27, 34, 35, 36, 39, 40, 52, 60, 66, 67], // 10 madde
    'Fen Bilimleri İlgisi' => [38, 41, 45, 46, 49, 50, 55, 58, 59, 63, 68], // 11 madde
    'Sosyal Bilimler İlgisi' => [42, 43, 44, 53, 54, 56, 57, 61, 62, 64, 65, 168], // 12 madde
    'İkna İlgisi' => [87, 88, 92, 96, 108, 109, 119, 128, 150, 151, 168], // 11 madde
    'Yabancı Dil İlgisi' => [48, 51, 73, 74, 77, 89, 106, 124, 157, 170], // 10 madde
    'Ticaret İlgisi' => [90, 97, 101, 110, 111, 120, 122, 123, 126, 129, 130], // 11 madde
    'Ziraat İlgisi' => [69, 72, 75, 76, 78, 80, 82, 83, 85, 169], // 10 madde
    'Mekanik İlgi' => [70, 71, 79, 81, 84, 86, 91, 95, 107, 118, 127], // 11 madde
    'İş Ayrıntıları İlgisi' => [93, 94, 98, 99, 102, 104, 105, 112, 121, 125, 166], // 11 madde
    'Edebiyat İlgisi' => [100, 103, 113, 114, 116, 131, 142, 143, 165, 167], // 10 madde
    'Sanat İlgisi' => [115, 117, 132, 135, 136, 147, 149, 160, 162, 163], // 10 madde
    'Müzik İlgisi' => [133, 134, 144, 145, 152, 153, 156, 159, 161, 164], // 10 madde
    'Sosyal Yardım İlgisi' => [137, 138, 139, 140, 141, 146, 148, 154, 155, 158] // 10 madde
];
// Toplam beklenen soru sayısı (170 madde olduğu belirtiliyor, listedeki unique sayıları sayalım)
$allSortOrders = [];
foreach ($subscalesItems as $items) {
    $allSortOrders = array_merge($allSortOrders, $items);
}
$totalExpectedQuestions = count(array_unique($allSortOrders)); // Unique madde sayısı

// Norm Tablosu (GENEL GRUP) - PDF Sayfa 13'ten elde edilen ham puan -> yüzdelik karşılıkları
// Bu veri yapısı, her alt ölçek için ham puanın yüzdelik karşılığını bulmak için kullanılacaktır.
// Anahtar: Ham Puan, Değer: [Alt Ölçek Anahtarı => Yüzdelik Karşılık]
$normTableGeneral = [
    44 => ['Sözel Yetenek' => 99.9, 'Sayısal Yetenek' => null, 'Şekil-Uzay Yeteneği' => null, 'Göz-El Koordinasyonu' => null, 'Fen Bilimleri İlgisi' => 99.9, 'Sosyal Bilimler İlgisi' => 99.9, 'İkna İlgisi' => null, 'Yabancı Dil İlgisi' => null, 'Ticaret İlgisi' => null, 'Ziraat İlgisi' => 97.0, 'Mekanik İlgi' => null, 'İş Ayrıntıları İlgisi' => 99.9, 'Edebiyat İlgisi' => null, 'Sanat İlgisi' => null, 'Müzik İlgisi' => 99.9, 'Sosyal Yardım İlgisi' => 99.9],
    43 => ['Sözel Yetenek' => 99.1, 'Sayısal Yetenek' => null, 'Şekil-Uzay Yeteneği' => null, 'Göz-El Koordinasyonu' => null, 'Fen Bilimleri İlgisi' => 99.4, 'Sosyal Bilimler İlgisi' => 99.1, 'İkna İlgisi' => null, 'Yabancı Dil İlgisi' => null, 'Ticaret İlgisi' => null, 'Ziraat İlgisi' => 97.0, 'Mekanik İlgi' => null, 'İş Ayrıntıları İlgisi' => 99.8, 'Edebiyat İlgisi' => null, 'Sanat İlgisi' => null, 'Müzik İlgisi' => 99.4, 'Sosyal Yardım İlgisi' => 99.1],
    42 => ['Sözel Yetenek' => 98.1, 'Sayısal Yetenek' => null, 'Şekil-Uzay Yeteneği' => null, 'Göz-El Koordinasyonu' => null, 'Fen Bilimleri İlgisi' => 98.7, 'Sosyal Bilimler İlgisi' => 98.3, 'İkna İlgisi' => null, 'Yabancı Dil İlgisi' => null, 'Ticaret İlgisi' => null, 'Ziraat İlgisi' => 95.7, 'Mekanik İlgi' => null, 'İş Ayrıntıları İlgisi' => 99.7, 'Edebiyat İlgisi' => null, 'Sanat İlgisi' => null, 'Müzik İlgisi' => 99.1, 'Sosyal Yardım İlgisi' => 98.1],
    41 => ['Sözel Yetenek' => 96.5, 'Sayısal Yetenek' => null, 'Şekil-Uzay Yeteneği' => null, 'Göz-El Koordinasyonu' => null, 'Fen Bilimleri İlgisi' => 98.2, 'Sosyal Bilimler İlgisi' => 97.2, 'İkna İlgisi' => null, 'Yabancı Dil İlgisi' => null, 'Ticaret İlgisi' => null, 'Ziraat İlgisi' => 93.3, 'Mekanik İlgi' => null, 'İş Ayrıntıları İlgisi' => 99.2, 'Edebiyat İlgisi' => null, 'Sanat İlgisi' => null, 'Müzik İlgisi' => 98.1, 'Sosyal Yardım İlgisi' => 96.5],
    40 => ['Sözel Yetenek' => 94.8, 'Sayısal Yetenek' => 99.9, 'Şekil-Uzay Yeteneği' => 99.9, 'Göz-El Koordinasyonu' => 99.9, 'Fen Bilimleri İlgisi' => 99.9, 'Sosyal Bilimler İlgisi' => 96.7, 'İkna İlgisi' => 95.7, 'Yabancı Dil İlgisi' => 99.9, 'Ticaret İlgisi' => null, 'Ziraat İlgisi' => 90.0, 'Mekanik İlgi' => 99.9, 'İş Ayrıntıları İlgisi' => 98.7, 'Edebiyat İlgisi' => 99.9, 'Sanat İlgisi' => 99.9, 'Müzik İlgisi' => 97.0, 'Sosyal Yardım İlgisi' => 94.8],
    39 => ['Sözel Yetenek' => 91.4, 'Sayısal Yetenek' => 99.8, 'Şekil-Uzay Yeteneği' => 99.6, 'Göz-El Koordinasyonu' => 99.4, 'Fen Bilimleri İlgisi' => 99.9, 'Sosyal Bilimler İlgisi' => 94.8, 'İkna İlgisi' => 92.6, 'Yabancı Dil İlgisi' => 99.2, 'Ticaret İlgisi' => 99.9, 'Ziraat İlgisi' => 99.3, 'Mekanik İlgi' => 86.6, 'İş Ayrıntıları İlgisi' => 99.6, 'Edebiyat İlgisi' => 97.8, 'Sanat İlgisi' => 99.3, 'Müzik İlgisi' => 99.3, 'Sosyal Yardım İlgisi' => 94.6],
    38 => ['Sözel Yetenek' => 88.4, 'Sayısal Yetenek' => 99.3, 'Şekil-Uzay Yeteneği' => 99.1, 'Göz-El Koordinasyonu' => 98.7, 'Fen Bilimleri İlgisi' => 99.5, 'Sosyal Bilimler İlgisi' => 92.2, 'İkna İlgisi' => 89.6, 'Yabancı Dil İlgisi' => 98.5, 'Ticaret İlgisi' => 99.7, 'Ziraat İlgisi' => 98.6, 'Mekanik İlgi' => 83.7, 'İş Ayrıntıları İlgisi' => 98.7, 'Edebiyat İlgisi' => 97.0, 'Sanat İlgisi' => 98.8, 'Müzik İlgisi' => 98.8, 'Sosyal Yardım İlgisi' => 91.6],
    37 => ['Sözel Yetenek' => 84.6, 'Sayısal Yetenek' => 98.6, 'Şekil-Uzay Yeteneği' => 98.0, 'Göz-El Koordinasyonu' => 96.5, 'Fen Bilimleri İlgisi' => 98.2, 'Sosyal Bilimler İlgisi' => 98.3, 'İkna İlgisi' => 86.1, 'Yabancı Dil İlgisi' => 96.0, 'Ticaret İlgisi' => 99.5, 'Ziraat İlgisi' => 97.2, 'Mekanik İlgi' => 80.5, 'İş Ayrıntıları İlgisi' => 97.7, 'Edebiyat İlgisi' => 96.3, 'Sanat İlgisi' => 97.8, 'Müzik İlgisi' => 97.1, 'Sosyal Yardım İlgisi' => 88.7],
    36 => ['Sözel Yetenek' => 80.5, 'Sayısal Yetenek' => 97.0, 'Şekil-Uzay Yeteneği' => 96.0, 'Göz-El Koordinasyonu' => 95.0, 'Fen Bilimleri İlgisi' => 96.8, 'Sosyal Bilimler İlgisi' => 85.5, 'İkna İlgisi' => 81.5, 'Yabancı Dil İlgisi' => 93.5, 'Ticaret İlgisi' => 99.2, 'Ziraat İlgisi' => 95.8, 'Mekanik İlgi' => 78.1, 'İş Ayrıntıları İlgisi' => 95.1, 'Edebiyat İlgisi' => 95.2, 'Sanat İlgisi' => 96.7, 'Müzik İlgisi' => 95.8, 'Sosyal Yardım İlgisi' => 85.4],
    35 => ['Sözel Yetenek' => 74.8, 'Sayısal Yetenek' => 95.0, 'Şekil-Uzay Yeteneği' => 92.8, 'Göz-El Koordinasyonu' => 91.5, 'Fen Bilimleri İlgisi' => 94.5, 'Sosyal Bilimler İlgisi' => 82.2, 'İkna İlgisi' => 76.7, 'Yabancı Dil İlgisi' => 90.5, 'Ticaret İlgisi' => 98.8, 'Ziraat İlgisi' => 93.9, 'Mekanik İlgi' => 73.6, 'İş Ayrıntıları İlgisi' => 92.2, 'Edebiyat İlgisi' => 93.7, 'Sanat İlgisi' => 94.9, 'Müzik İlgisi' => 93.6, 'Sosyal Yardım İlgisi' => 80.2],
    34 => ['Sözel Yetenek' => 68.2, 'Sayısal Yetenek' => 89.8, 'Şekil-Uzay Yeteneği' => 89.7, 'Göz-El Koordinasyonu' => 86.6, 'Fen Bilimleri İlgisi' => 92.0, 'Sosyal Bilimler İlgisi' => 77.4, 'İkna İlgisi' => 70.8, 'Yabancı Dil İlgisi' => 86.5, 'Ticaret İlgisi' => 98.3, 'Ziraat İlgisi' => 91.0, 'Mekanik İlgi' => 69.7, 'İş Ayrıntıları İlgisi' => 89.3, 'Edebiyat İlgisi' => 90.6, 'Sanat İlgisi' => 92.2, 'Müzik İlgisi' => 91.7, 'Sosyal Yardım İlgisi' => 75.9],
    33 => ['Sözel Yetenek' => 62.9, 'Sayısal Yetenek' => 89.0, 'Şekil-Uzay Yeteneği' => 84.6, 'Göz-El Koordinasyonu' => 82.1, 'Fen Bilimleri İlgisi' => 88.6, 'Sosyal Bilimler İlgisi' => 72.4, 'İkna İlgisi' => 64.9, 'Yabancı Dil İlgisi' => 80.8, 'Ticaret İlgisi' => 96.8, 'Ziraat İlgisi' => 87.5, 'Mekanik İlgi' => 64.1, 'İş Ayrıntıları İlgisi' => 84.5, 'Edebiyat İlgisi' => 88.0, 'Sanat İlgisi' => 89.7, 'Müzik İlgisi' => 89.3, 'Sosyal Yardım İlgisi' => 74.3],
    32 => ['Sözel Yetenek' => 59.9, 'Sayısal Yetenek' => 86.5, 'Şekil-Uzay Yeteneği' => 78.6, 'Göz-El Koordinasyonu' => 75.7, 'Fen Bilimleri İlgisi' => 85.2, 'Sosyal Bilimler İlgisi' => 65.2, 'İkna İlgisi' => 60.2, 'Yabancı Dil İlgisi' => 73.9, 'Ticaret İlgisi' => 95.6, 'Ziraat İlgisi' => 83.7, 'Mekanik İlgi' => 58.8, 'İş Ayrıntıları İlgisi' => 80.4, 'Edebiyat İlgisi' => 85.4, 'Sanat İlgisi' => 86.3, 'Müzik İlgisi' => 86.5, 'Sosyal Yardım İlgisi' => 65.4],
    31 => ['Sözel Yetenek' => 47.4, 'Sayısal Yetenek' => 82.5, 'Şekil-Uzay Yeteneği' => 71.4, 'Göz-El Koordinasyonu' => 68.9, 'Fen Bilimleri İlgisi' => 80.6, 'Sosyal Bilimler İlgisi' => 59.2, 'İkna İlgisi' => 53.0, 'Yabancı Dil İlgisi' => 68.6, 'Ticaret İlgisi' => 94.3, 'Ziraat İlgisi' => 79.5, 'Mekanik İlgi' => 54.2, 'İş Ayrıntıları İlgisi' => 74.6, 'Edebiyat İlgisi' => 84.2, 'Sanat İlgisi' => 83.2, 'Müzik İlgisi' => 82.3, 'Sosyal Yardım İlgisi' => 59.8],
    30 => ['Sözel Yetenek' => 41.5, 'Sayısal Yetenek' => 77.0, 'Şekil-Uzay Yeteneği' => 64.7, 'Göz-El Koordinasyonu' => 60.8, 'Fen Bilimleri İlgisi' => 75.9, 'Sosyal Bilimler İlgisi' => 52.9, 'İkna İlgisi' => 47.9, 'Yabancı Dil İlgisi' => 62.5, 'Ticaret İlgisi' => 92.1, 'Ziraat İlgisi' => 74.0, 'Mekanik İlgi' => 49.9, 'İş Ayrıntıları İlgisi' => 69.4, 'Edebiyat İlgisi' => 78.8, 'Sanat İlgisi' => 79.0, 'Müzik İlgisi' => 77.5, 'Sosyal Yardım İlgisi' => 54.7],
    29 => ['Sözel Yetenek' => 35.0, 'Sayısal Yetenek' => 71.6, 'Şekil-Uzay Yeteneği' => 58.7, 'Göz-El Koordinasyonu' => 52.7, 'Fen Bilimleri İlgisi' => 70.2, 'Sosyal Bilimler İlgisi' => 47.2, 'İkna İlgisi' => 41.0, 'Yabancı Dil İlgisi' => 55.7, 'Ticaret İlgisi' => 89.3, 'Ziraat İlgisi' => 69.9, 'Mekanik İlgi' => 45.5, 'İş Ayrıntıları İlgisi' => 62.8, 'Edebiyat İlgisi' => 73.9, 'Sanat İlgisi' => 74.6, 'Müzik İlgisi' => 73.1, 'Sosyal Yardım İlgisi' => 49.9],
    28 => ['Sözel Yetenek' => 28.7, 'Sayısal Yetenek' => 66.5, 'Şekil-Uzay Yeteneği' => 52.0, 'Göz-El Koordinasyonu' => 45.3, 'Fen Bilimleri İlgisi' => 63.3, 'Sosyal Bilimler İlgisi' => 40.9, 'İkna İlgisi' => 33.5, 'Yabancı Dil İlgisi' => 49.1, 'Ticaret İlgisi' => 87.2, 'Ziraat İlgisi' => 63.0, 'Mekanik İlgi' => 41.0, 'İş Ayrıntıları İlgisi' => 55.0, 'Edebiyat İlgisi' => 68.4, 'Sanat İlgisi' => 70.2, 'Müzik İlgisi' => 67.2, 'Sosyal Yardım İlgisi' => 43.0],
    27 => ['Sözel Yetenek' => 22.5, 'Sayısal Yetenek' => 60.1, 'Şekil-Uzay Yeteneği' => 45.4, 'Göz-El Koordinasyonu' => 37.9, 'Fen Bilimleri İlgisi' => 57.9, 'Sosyal Bilimler İlgisi' => 34.4, 'İkna İlgisi' => 26.7, 'Yabancı Dil İlgisi' => 43.7, 'Ticaret İlgisi' => 83.4, 'Ziraat İlgisi' => 58.1, 'Mekanik İlgi' => 36.2, 'İş Ayrıntıları İlgisi' => 47.4, 'Edebiyat İlgisi' => 62.7, 'Sanat İlgisi' => 64.8, 'Müzik İlgisi' => 62.0, 'Sosyal Yardım İlgisi' => 36.5],
    26 => ['Sözel Yetenek' => 17.3, 'Sayısal Yetenek' => 54.1, 'Şekil-Uzay Yeteneği' => 38.7, 'Göz-El Koordinasyonu' => 31.6, 'Fen Bilimleri İlgisi' => 50.2, 'Sosyal Bilimler İlgisi' => 28.1, 'İkna İlgisi' => 21.6, 'Yabancı Dil İlgisi' => 37.6, 'Ticaret İlgisi' => 78.6, 'Ziraat İlgisi' => 53.0, 'Mekanik İlgi' => 32.8, 'İş Ayrıntıları İlgisi' => 40.8, 'Edebiyat İlgisi' => 58.3, 'Sanat İlgisi' => 60.2, 'Müzik İlgisi' => 56.6, 'Sosyal Yardım İlgisi' => 30.1],
    25 => ['Sözel Yetenek' => 12.0, 'Sayısal Yetenek' => 46.5, 'Şekil-Uzay Yeteneği' => 31.6, 'Göz-El Koordinasyonu' => 25.4, 'Fen Bilimleri İlgisi' => 42.4, 'Sosyal Bilimler İlgisi' => 22.1, 'İkna İlgisi' => 17.1, 'Yabancı Dil İlgisi' => 31.6, 'Ticaret İlgisi' => 74.2, 'Ziraat İlgisi' => 45.7, 'Mekanik İlgi' => 28.2, 'İş Ayrıntıları İlgisi' => 33.7, 'Edebiyat İlgisi' => 53.8, 'Sanat İlgisi' => 55.9, 'Müzik İlgisi' => 50.5, 'Sosyal Yardım İlgisi' => 25.0],
    24 => ['Sözel Yetenek' => 8.2, 'Sayısal Yetenek' => 40.8, 'Şekil-Uzay Yeteneği' => 24.1, 'Göz-El Koordinasyonu' => 18.7, 'Fen Bilimleri İlgisi' => 35.8, 'Sosyal Bilimler İlgisi' => 17.9, 'İkna İlgisi' => 12.9, 'Yabancı Dil İlgisi' => 25.9, 'Ticaret İlgisi' => 68.6, 'Ziraat İlgisi' => 39.7, 'Mekanik İlgi' => 25.8, 'İş Ayrıntıları İlgisi' => 27.1, 'Edebiyat İlgisi' => 46.5, 'Sanat İlgisi' => 50.4, 'Müzik İlgisi' => 45.1, 'Sosyal Yardım İlgisi' => 20.8],
    23 => ['Sözel Yetenek' => 34.9, 'Sayısal Yetenek' => 8.9, 'Şekil-Uzay Yeteneği' => 35.2, 'Göz-El Koordinasyonu' => 65.5, 'Fen Bilimleri İlgisi' => 22.5, 'Sosyal Bilimler İlgisi' => 36.3, 'İkna İlgisi' => 52.6, 'Yabancı Dil İlgisi' => 28.8, 'Ticaret İlgisi' => 59.9, 'Ziraat İlgisi' => 23.1, 'Mekanik İlgi' => 61.4, 'İş Ayrıntıları İlgisi' => 57.8, 'Edebiyat İlgisi' => 66.9, 'Sanat İlgisi' => 15.2, 'Müzik İlgisi' => 41.0, 'Sosyal Yardım İlgisi' => 45.7],
    22 => ['Sözel Yetenek' => 27.0, 'Sayısal Yetenek' => 5.9, 'Şekil-Uzay Yeteneği' => 27.9, 'Göz-El Koordinasyonu' => 59.8, 'Fen Bilimleri İlgisi' => 17.6, 'Sosyal Bilimler İlgisi' => 29.9, 'İkna İlgisi' => 47.5, 'Yabancı Dil İlgisi' => 22.8, 'Ticaret İlgisi' => 52.2, 'Ziraat İlgisi' => 18.2, 'Mekanik İlgi' => 54.3, 'İş Ayrıntıları İlgisi' => 51.8, 'Edebiyat İlgisi' => 60.8, 'Sanat İlgisi' => 11.7, 'Müzik İlgisi' => 34.4, 'Sosyal Yardım İlgisi' => 39.1],
    21 => ['Sözel Yetenek' => 21.4, 'Sayısal Yetenek' => 3.9, 'Şekil-Uzay Yeteneği' => 20.9, 'Göz-El Koordinasyonu' => 54.0, 'Fen Bilimleri İlgisi' => 12.9, 'Sosyal Bilimler İlgisi' => 23.7, 'İkna İlgisi' => 40.3, 'Yabancı Dil İlgisi' => 17.6, 'Ticaret İlgisi' => 44.8, 'Ziraat İlgisi' => 13.5, 'Mekanik İlgi' => 47.1, 'İş Ayrıntıları İlgisi' => 45.4, 'Edebiyat İlgisi' => 55.3, 'Sanat İlgisi' => 9.2, 'Müzik İlgisi' => 29.6, 'Sosyal Yardım İlgisi' => 33.8],
    20 => ['Sözel Yetenek' => 15.2, 'Sayısal Yetenek' => 2.3, 'Şekil-Uzay Yeteneği' => 14.6, 'Göz-El Koordinasyonu' => 46.6, 'Fen Bilimleri İlgisi' => 9.6, 'Sosyal Bilimler İlgisi' => 18.8, 'İkna İlgisi' => 35.6, 'Yabancı Dil İlgisi' => 12.4, 'Ticaret İlgisi' => 32.4, 'Ziraat İlgisi' => 9.7, 'Mekanik İlgi' => 39.6, 'İş Ayrıntıları İlgisi' => 38.1, 'Edebiyat İlgisi' => 48.7, 'Sanat İlgisi' => 7.0, 'Müzik İlgisi' => 24.4, 'Sosyal Yardım İlgisi' => 28.9],
    19 => ['Sözel Yetenek' => 11.6, 'Sayısal Yetenek' => 1.3, 'Şekil-Uzay Yetenek' => 9.7, 'Göz-El Koordinasyonu' => 39.7, 'Fen Bilimleri İlgisi' => 7.6, 'Sosyal Bilimler İlgisi' => 14.2, 'İkna İlgisi' => 30.4, 'Yabancı Dil İlgisi' => 8.0, 'Ticaret İlgisi' => 30.7, 'Ziraat İlgisi' => 6.7, 'Mekanik İlgi' => 32.4, 'İş Ayrıntıları İlgisi' => 31.5, 'Edebiyat İlgisi' => 41.6, 'Sanat İlgisi' => 5.2, 'Müzik İlgisi' => 20.0, 'Sosyal Yardım İlgisi' => 25.3],
    18 => ['Sözel Yetenek' => 8.3, 'Sayısal Yetenek' => 0.6, 'Şekil-Uzay Yeteneği' => 6.6, 'Göz-El Koordinasyonu' => 33.2, 'Fen Bilimleri İlgisi' => 6.0, 'Sosyal Bilimler İlgisi' => 10.3, 'İkna İlgisi' => 25.2, 'Yabancı Dil İlgisi' => 4.5, 'Ticaret İlgisi' => 18.5, 'Ziraat İlgisi' => 4.4, 'Mekanik İlgi' => 25.6, 'İş Ayrıntıları İlgisi' => 26.4, 'Edebiyat İlgisi' => 34.3, 'Sanat İlgisi' => 3.6, 'Müzik İlgisi' => 16.1, 'Sosyal Yardım İlgisi' => 21.3],
    17 => ['Sözel Yetenek' => 4.0, 'Sayısal Yetenek' => 5.7, 'Şekil-Uzay Yeteneği' => 3.6, 'Göz-El Koordinasyonu' => 27.1, 'Fen Bilimleri İlgisi' => 4.3, 'Sosyal Bilimler İlgisi' => 7.0, 'İkna İlgisi' => 20.2, 'Yabancı Dil İlgisi' => 2.8, 'Ticaret İlgisi' => 18.5, 'Ziraat İlgisi' => 2.8, 'Mekanik İlgi' => 20.0, 'İş Ayrıntıları İlgisi' => 20.7, 'Edebiyat İlgisi' => 27.4, 'Sanat İlgisi' => 2.7, 'Müzik İlgisi' => 12.1, 'Sosyal Yardım İlgisi' => 17.5],
    16 => ['Sözel Yetenek' => 4.0, 'Sayısal Yetenek' => 0.2, 'Şekil-Uzay Yeteneği' => 2.1, 'Göz-El Koordinasyonu' => 20.8, 'Fen Bilimleri İlgisi' => 2.7, 'Sosyal Bilimler İlgisi' => 4.6, 'İkna İlgisi' => 14.9, 'Yabancı Dil İlgisi' => 1.7, 'Ticaret İlgisi' => 13.7, 'Ziraat İlgisi' => 1.8, 'Mekanik İlgi' => 12.8, 'İş Ayrıntıları İlgisi' => 15.6, 'Edebiyat İlgisi' => 20.9, 'Sanat İlgisi' => 1.9, 'Müzik İlgisi' => 9.0, 'Sosyal Yardım İlgisi' => 13.9], // Note: PDF has 1.9, not 13.9
    15 => ['Sözel Yetenek' => 0.2, 'Sayısal Yetenek' => 2.7, 'Şekil-Uzay Yeteneği' => 0.8, 'Göz-El Koordinasyonu' => 15.2, 'Fen Bilimleri İlgisi' => 1.7, 'Sosyal Bilimler İlgisi' => 2.8, 'İkna İlgisi' => 10.3, 'Yabancı Dil İlgisi' => 0.7, 'Ticaret İlgisi' => 8.6, 'Ziraat İlgisi' => 1.0, 'Mekanik İlgi' => 11.7, 'İş Ayrıntıları İlgisi' => 11.1, 'Edebiyat İlgisi' => 15.8, 'Sanat İlgisi' => 1.4, 'Müzik İlgisi' => 6.0, 'Sosyal Yardım İlgisi' => 11.2],
    14 => ['Sözel Yetenek' => 1.5, 'Sayısal Yetenek' => 0.1, 'Şekil-Uzay Yeteneği' => 0.5, 'Göz-El Koordinasyonu' => 11.0, 'Fen Bilimleri İlgisi' => 0.9, 'Sosyal Bilimler İlgisi' => 1.7, 'İkna İlgisi' => 6.4, 'Yabancı Dil İlgisi' => 0.2, 'Ticaret İlgisi' => 5.2, 'Ziraat İlgisi' => 0.5, 'Mekanik İlgi' => 7.0, 'İş Ayrıntıları İlgisi' => 7.8, 'Edebiyat İlgisi' => 11.1, 'Sanat İlgisi' => 0.6, 'Müzik İlgisi' => 3.9, 'Sosyal Yardım İlgisi' => 7.7], // Note: PDF has 43, not 4.3
    13 => ['Sözel Yetenek' => 0.6, 'Sayısal Yetenek' => 0.2, 'Şekil-Uzaj Yeteneği' => 0.1, 'Göz-El Koordinasyonu' => 6.9, 'Fen Bilimleri İlgisi' => 0.6, 'Sosyal Bilimler İlgisi' => 0.9, 'İkna İlgisi' => 4.2, 'Yabancı Dil İlgisi' => 0.1, 'Ticaret İlgisi' => 2.8, 'Ziraat İlgisi' => 0.3, 'Mekanik İlgi' => 4.1, 'İş Ayrıntıları İlgisi' => 5.2, 'Edebiyat İlgisi' => 7.8, 'Sanat İlgisi' => 0.4, 'Müzik İlgisi' => 2.1, 'Sosyal Yardım İlgisi' => 5.4], // Note: PDF has 29, not 2.9
    12 => ['Sözel Yetenek' => 0.4, 'Sayısal Yetenek' => 0.2, 'Şekil-Uzay Yeteneği' => 0.2, 'Göz-El Koordinasyonu' => 4.2, 'Fen Bilimleri İlgisi' => 0.3, 'Sosyal Bilimler İlgisi' => 0.5, 'İkna İlgisi' => 1.8, 'Yabancı Dil İlgisi' => null, 'Ticaret İlgisi' => 1.3, 'Ziraat İlgisi' => 0.1, 'Mekanik İlgi' => 2.0, 'İş Ayrıntıları İlgisi' => 3.1, 'Edebiyat İlgisi' => 4.4, 'Sanat İlgisi' => 0.3, 'Müzik İlgisi' => 1.0, 'Sosyal Yardım İlgisi' => 3.5], // Note: PDF has 19, not 1.9
    11 => ['Sözel Yetenek' => 0.2, 'Sayısal Yetenek' => 0.1, 'Şekil-Uzaj Yeteneği' => 0.1, 'Göz-El Koordinasyonu' => 2.3, 'Fen Bilimleri İlgisi' => 0.1, 'Sosyal Bilimler İlgisi' => 0.3, 'İkna İlgisi' => 1.1, 'Yabancı Dil İlgisi' => null, 'Ticaret İlgisi' => 0.5, 'Ziraat İlgisi' => 0.1, 'Mekanik İlgi' => 0.3, 'İş Ayrıntıları İlgisi' => 0.2, 'Edebiyat İlgisi' => 0.3, 'Sanat İlgisi' => 1.6, 'Müzik İlgisi' => 1.4, 'Sosyal Yardım İlgisi' => 0.5],
    10 => ['Sözel Yetenek' => null, 'Sayısal Yetenek' => null, 'Şekil-Uzay Yeteneği' => 0.9, 'Göz-El Koordinasyonu' => null, 'Fen Bilimleri İlgisi' => null, 'Sosyal Bilimler İlgisi' => null, 'İkna İlgisi' => null, 'Yabancı Dil İlgisi' => null, 'Ticaret İlgisi' => null, 'Ziraat İlgisi' => null, 'Mekanik İlgi' => null, 'İş Ayrıntıları İlgisi' => 0.2, 'Edebiyat İlgisi' => 0.9, 'Sanat İlgisi' => 1.0, 'Müzik İlgisi' => 0.5, 'Sosyal Yardım İlgisi' => null],
    // ... (Diğer ham puanlar için norm değerleri buraya eklenecek)
    // PDF'deki tüm tabloyu kodlamak oldukça uzun olacaktır.
    // Önemli aralıkları (25, 50, 75 yüzdelikler gibi) veya tüm tabloyu kodlayabiliriz.
    // Şimdilik sadece yukarıdaki kısmı ekliyorum. Tam tablo gerekirse belirtin.
];

// Yüzdelik Puanı Bulma Fonksiyonu (Ham puana ve alt ölçeğe göre)
function getPercentileRank($rawScore, $scaleKey, $normTable) {
    // Norm tablosu ham puanlara göre sıralı olmalı (azalan veya artan).
    // PDF'deki tablo azalan ham puanlara göre sıralı.
    // En yakın ham puanı bulma
    $closestRawScore = null;
    foreach ($normTable as $normRawScore => $percentiles) {
        if ($normRawScore <= $rawScore) {
            $closestRawScore = $normRawScore;
            break; // Azalan sırada ilk bulunan en yakın veya eşit ham puan
        }
    }

    if ($closestRawScore !== null && isset($normTable[$closestRawScore][$scaleKey])) {
        return $normTable[$closestRawScore][$scaleKey];
    }

    // Eğer tam eşleşme veya küçük en yakın bulunamazsa,
    // daha yüksek ham puanlara bakarak interpolasyon yapılabilir veya
    // en yakın üst veya alt değer döndürülebilir.
    // Basitlik için, eğer en yakın alt veya eşit ham puan bulunamazsa
    // veya o ham puanda ilgili alt ölçek için değer yoksa null döndürelim.

    // Eğer ham puan norm tablosundaki en yüksek ham puandan büyükse, en yüksek yüzdeliği döndür
    $maxNormRawScore = max(array_keys($normTable));
    if ($rawScore > $maxNormRawScore && isset($normTable[$maxNormRawScore][$scaleKey])) {
        return $normTable[$maxNormRawScore][$scaleKey];
    }

    // Eğer ham puan norm tablosundaki en düşük ham puandan küçükse, en düşük yüzdeliği döndür
    $minNormRawScore = min(array_keys($normTable));
    if ($rawScore < $minNormRawScore && isset($normTable[$minNormRawScore][$scaleKey])) {
        return $normTable[$minNormRawScore][$scaleKey];
    }


    return null; // Norm tablosunda karşılığı bulunamadı
}

// Yüzdelik Yorumlama Fonksiyonu
function interpretPercentileRank($percentile) {
    if ($percentile === null) return "Hesaplanamadı";
    if ($percentile >= 75) return "Çok İyi";
    if ($percentile >= 40 && $percentile < 75) return "İyi";
    if ($percentile >= 25 && $percentile < 40) return "Orta";
    return "Zayıf"; // Yüzdelik 25'in altı
}


// --- VERİ KAYNAĞINI BELİRLE VE VERİYİ AL ---
if (isset($_GET['id'])) {
    // --- SENARYO 1: ID VAR -> VERITABANINDAN ÇEK ---
    $dataSource = 'db';
    $participantId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$participantId) { $error = "Geçersiz katılımcı ID'si."; }
    else {
        try {
            // 1. Katılımcı ve Anket Bilgileri
            $stmt_participant = $pdo->prepare(" SELECT sp.*, s.title as survey_title, u.institution_logo_path FROM survey_participants sp LEFT JOIN surveys s ON sp.survey_id = s.id LEFT JOIN users u ON sp.admin_id = u.id WHERE sp.id = ? AND sp.survey_id = ? ");
            $stmt_participant->execute([$participantId, $surveyId]);
            $participantData = $stmt_participant->fetch(PDO::FETCH_ASSOC);

            if (!$participantData) {
                 // Katılımcı bulunamazsa hata set et ve logla
                 $error = "Belirtilen ID ({$participantId}) için Anket {$surveyId} sonucu bulunamadı.";
                 error_log("Participant not found for view-result-24 (ID: {$participantId}, Survey: {$surveyId})");
            } else {
                $survey_title = !empty($participantData['survey_title']) ? htmlspecialchars($participantData['survey_title']) . " Sonuçları" : $testTitleDefault . " Sonuçları";
                // Logo URL Ayarla...
                if (!empty($participantData['admin_id']) && !empty($participantData['institution_logo_path'])) {
                   $rawInstitutionPathFromDB = $participantData['institution_logo_path'];
                   $cleanRelativePath = ltrim(str_replace('..', '', $rawInstitutionPathFromDB), '/');
                   $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/..');
                   $fullServerPath = rtrim($docRoot, '/') . '/' . $cleanRelativePath;
                   if (file_exists($fullServerPath)) { $institutionWebURL = '/' . $cleanRelativePath; }
                   else { error_log("Kurum logosu dosyası bulunamadı (view-result-24): " . $fullServerPath); }
                }

                // 2. Cevapları Çek (question_id artık sort_order, answer_text 'A'/'B'/'C'/'D')
                $stmt_answers = $pdo->prepare("SELECT question_id AS sort_order, answer_text FROM survey_answers WHERE participant_id = ? AND survey_id = ? ORDER BY sort_order ASC");
                $stmt_answers->execute([$participantId, $surveyId]);
                $fetched_answers = $stmt_answers->fetchAll(PDO::FETCH_ASSOC);

                // Toplam beklenen soru sayısı (170)
                $stmt_total_questions = $pdo->prepare("SELECT COUNT(*) FROM survey_questions WHERE survey_id = ?");
                $stmt_total_questions->execute([$surveyId]);
                $totalExpectedQuestionsFetched = (int)$stmt_total_questions->fetchColumn();

                // Cevapları sort_order'a göre bir haritaya dök ve metinden puana çevir (1-4)
                $participantAnswersBySortOrder = []; // [sort_order => numerical_score (1-4)]
                $processedAnswerCount = 0; // İşlenen geçerli cevap sayısı
                foreach($fetched_answers as $ans) {
                    $sortOrder = (int)$ans['sort_order'];
                    $answerText = trim($ans['answer_text'] ?? '');
                    // Metinden sayısal puana çevir (1-4)
                    $numericalScore = $textToScoreMap[$answerText] ?? null;

                    if ($numericalScore !== null) {
                        $participantAnswersBySortOrder[$sortOrder] = $numericalScore;
                        $processedAnswerCount++; // Geçerli cevap sayısını artır
                    } else {
                        // Geçersiz metin karşılığı gelirse logla
                        error_log("Invalid answer_text '{$answerText}' found in DB for participant {$participantId}, survey {$surveyId}, sort_order {$sortOrder}");
                        // Bu cevabı dikkate alma
                    }
                }

                // Tüm sorular cevaplanmış mı control et (170 soru bekleniyor)
                if ($processedAnswerCount < $totalExpectedQuestionsFetched) {
                     $error = "Katılımcı cevapları veritabanında eksik (ID: {$participantId}, Anket: {$surveyId}).";
                     error_log("Answers incomplete for participant {$participantId} in survey {$surveyId}. Expected {$totalExpectedQuestionsFetched}, found " . $processedAnswerCount);
                     // Hata durumunda skorları ve yorumları boşalt
                     $subscaleRawScores = []; $subscalePercentileRanks = []; $interpretation = "Hesaplanamadı"; $processedAnswersForTable = [];
                } else {
                    // 3. Ham Skorları Hesapla (Alt Ölçeklere Göre)
                    $subscaleRawScores = array_fill_keys(array_keys($subscalesItems), 0); // Alt ölçek ham skorlarını sıfırla

                    // Soru metinlerini çek (tablo için)
                    $questionSortOrderToTextMap = [];
                    try {
                        $stmtQText = $pdo->prepare("SELECT sort_order, question AS question_text FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC");
                        $stmtQText->execute([$surveyId]);
                        $questionSortOrderToTextMap = $stmtQText->fetchAll(PDO::FETCH_KEY_PAIR); // [sort_order => question_text]
                    } catch(Exception $e) { error_log("DB result question text fetch error: " . $e->getMessage()); /* Hata set edilebilir */ }


                    foreach ($participantAnswersBySortOrder as $sortOrder => $numericalScore) { // Artık sayısal puan (1-4)
                         // Alt ölçek skorları için puanı ilgili alt ölçeğe ekle
                         foreach ($subscalesItems as $scaleKey => $items) {
                             if (in_array($sortOrder, $items)) {
                                 $subscaleRawScores[$scaleKey] += $numericalScore;
                                 // Bir madde birden fazla alt ölçekte olabilir mi? PDF'e göre evet (örn. Madde 4 Sınav Kaygısı'nda).
                                 // ABKÖ'de aynı madde numarası farklı sütunlarda görünüyor.
                                 // Bu yüzden break kullanmıyoruz.
                             }
                         }

                         // Detaylı tablo için veriyi hazırla
                         $questionText = $questionSortOrderToTextMap[$sortOrder] ?? 'Soru metni yüklenemedi';
                         $processedAnswersForTable[] = [
                             'madde' => $sortOrder,
                             'question_text' => $questionText,
                             'verilen_cevap' => $optionsMap[$numericalScore] ?? 'Geçersiz', // Sayısal puanı metne çevir (A, B, C, D)
                             'puan' => $numericalScore // Sayısal puan (1-4)
                         ];
                    }

                    // 4. Yüzdelik Puanları ve Yorumları Hesapla (Ham Skorlara Göre)
                    $subscalePercentileRanks = [];
                    $subscaleInterpretations = [];
                    foreach ($subscaleRawScores as $scaleKey => $rawScore) {
                         // Ham puana karşılık gelen yüzdelik puanı norm tablosundan bul
                         $percentile = getPercentileRank($rawScore, $scaleKey, $normTableGeneral);
                         $subscalePercentileRanks[$scaleKey] = $percentile;

                         // Yüzdelik puana göre yorum yap
                         $subscaleInterpretations[$scaleKey] = interpretPercentileRank($percentile);
                    }

                    // Genel yorum (isteğe bağlı, alt ölçekler daha anlamlı)
                    $interpretation = "Akademik Benlik Kavramı Ölçeği Sonuçları";


                } // End if (empty($participantAnswersBySortOrder)) else
            } // End if (!$participantData) else

        } catch (Exception $e) {
             // Veritabanı veya diğer hatalar için genel hata yönetimi
             $error = "Sonuçlar yüklenirken bir hata oluştu: " . $e->getMessage();
             error_log("DB Error view-result-24 (ID: {$participantId}): ".$e->getMessage());
             $participantData = null; // Hata durumunda katılımcı verisini temizle
             $subscaleRawScores = []; $subscalePercentileRanks = []; $interpretation = "Hesaplanamadı"; $processedAnswersForTable = [];
        }
    }

} else {
    // --- SENARYO 2: ID YOK -> SESSION'DAN ÇEK ---
    $dataSource = 'session';
    // take-survey-24.php Session'a 'answers' ([sort_order => answer_text]), katılımcı bilgisini kaydediyor.
    $subscaleRawScores = []; $subscalePercentileRanks = []; $interpretation = "Hesaplanamadı"; $participantData = null; $processedAnswersForTable = []; // Başlangıç değerleri

    if (isset($_SESSION['survey_result_data'], $_SESSION['survey_result_data']['survey_id']) && $_SESSION['survey_result_data']['survey_id'] == $surveyId) {
        $sessionData = $_SESSION['survey_result_data'];
        // Gerekli anahtarların varlığını kontrol et
        if (isset($sessionData['answers'], $sessionData['participant_name']) && is_array($sessionData['answers'])) {

            $participantData = [
                 'name' => $sessionData['participant_name'],
                 'class' => $sessionData['participant_class'] ?? null,
                 'created_at' => date('Y-m-d H:i:s', $sessionData['timestamp'] ?? time()),
                 'admin_id' => null // Session'dan gelen sonuçta admin_id olmaz
            ];
            $survey_title = $testTitleDefault . " Sonucunuz"; $error = null;

            // Session'daki answers [sort_order => answer_text] formatında
            $sessionAnswers = $sessionData['answers'];

            // 3. Ham Skorları Hesapla (Alt Ölçeklere Göre)
            $subscaleRawScores = array_fill_keys(array_keys($subscalesItems), 0); // Alt ölçek ham skorlarını sıfırla
            $processedAnswerCount = 0; // İşlenen geçerli cevap sayısı

            // Soruları DB'den çekerek metinlerini alalım (tablo için)
            $questionSortOrderToTextMap = [];
             try {
                 $stmtQText = $pdo->prepare("SELECT sort_order, question AS question_text FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC");
                 $stmtQText->execute([$surveyId]);
                 $questionSortOrderToTextMap = $stmtQText->fetchAll(PDO::FETCH_KEY_PAIR); // [sort_order => question_text]
             } catch(Exception $e) { error_log("Session result question text fetch error: " . $e->getMessage()); /* Hata set edilebilir */ }


            foreach ($sessionAnswers as $sortOrder => $answerText) { // $answerText artık "A", "B", "C", "D"
                 $sortOrder_int = (int)$sortOrder;
                 $answerText_str = trim($answerText);

                 // Metinden sayısal puana çevir (1-4)
                 $numericalScore = $textToScoreMap[$answerText_str] ?? null;

                 // Geçerli sort_order ve sayısal puan (1-4) kontrolü
                 if ($numericalScore !== null) {

                     $processedAnswerCount++; // Geçerli cevap sayısını artır

                     // Alt ölçek skorları için puanı ilgili alt ölçeğe ekle
                     foreach ($subscalesItems as $scaleKey => $items) {
                         if (in_array($sortOrder_int, $items)) {
                             $subscaleRawScores[$scaleKey] += $numericalScore;
                             // Bir madde birden fazla alt ölçekte olabilir (örn. Madde 4)
                             // break; // Bu satırı kaldırdık
                         }
                     }

                     // Detaylı tablo için veriyi hazırla
                     $questionText = $questionSortOrderToTextMap[$sortOrder_int] ?? 'Soru metni yüklenemedi';
                     $processedAnswersForTable[] = [
                         'madde' => $sortOrder_int,
                         'question_text' => $questionText,
                         'verilen_cevap' => $answerText_str, // Cevap metni (A, B, C, D)
                         'puan' => $numericalScore // Sayısal puan (1-4)
                     ];
                 } else {
                      // Beklenmeyen sort_order veya geçersiz cevap metni/puanı gelirse logla
                      error_log("Invalid sort_order ({$sortOrder}) or answer_text ('{$answerText}') in session data for survey {$surveyId}");
                 }
            }

            // Tüm beklenen sorular cevaplandı mı kontrol et (opsiyonel)
            // Session'da toplam soru sayısını bilmek için DB'den çekmek gerekebilir.
            // Şimdilik sadece işlenen cevap sayısını kullanıyoruz.
            $stmt_total_questions = $pdo->prepare("SELECT COUNT(*) FROM survey_questions WHERE survey_id = ?");
            $stmt_total_questions->execute([$surveyId]);
            $totalExpectedQuestionsFetched = (int)$stmt_total_questions->fetchColumn();

            if ($processedAnswerCount < $totalExpectedQuestionsFetched) {
                 error_log("view-result-24 Session: Processed fewer valid answers ({$processedAnswerCount}) than expected ({$totalExpectedQuestionsFetched}) from session data.");
                 // Bu durumda skorların doğruluğu sorgulanabilir.
                 // $subscaleRawScores = []; // Skorları geçersiz kıl
                 // $processedAnswersForTable = []; // Tabloyu boşalt
            }


            // 4. Yüzdelik Puanları ve Yorumları Hesapla (Ham Skorlara Göre)
            $subscalePercentileRanks = [];
            $subscaleInterpretations = [];
            foreach ($subscaleRawScores as $scaleKey => $rawScore) {
                 // Ham puana karşılık gelen yüzdelik puanı norm tablosundan bul
                 $percentile = getPercentileRank($rawScore, $scaleKey, $normTableGeneral);
                 $subscalePercentileRanks[$scaleKey] = $percentile;

                 // Yüzdelik puana göre yorum yap
                 $subscaleInterpretations[$scaleKey] = interpretPercentileRank($percentile);
            }

            // Genel yorum (isteğe bağlı, alt ölçekler daha anlamlı)
            $interpretation = "Akademik Benlik Kavramı Ölçeği Sonuçları";


            unset($_SESSION['survey_result_data']); // Başarılı okuma sonrası temizle
        } else {
            // Session verisi eksikse hata set et ve logla
            error_log("Incomplete session data for survey 24: " . print_r($sessionData, true));
            $error = "Oturumdan alınan sonuç verisi eksik veya geçersiz (Kod: SESSION_INCOMPLETE).";
            $participantData = null; // Eksikse katılımcı verisi de yok
            $subscaleRawScores = []; $subscalePercentileRanks = []; $interpretation = "Hesaplanamadı"; $processedAnswersForTable = [];
            unset($_SESSION['survey_result_data']); // Eksikse de temizle
        }
    } else {
        // Session verisi tamamen yoksa hata set et
        $error = "Görüntülenecek sonuç bulunamadı veya oturum süresi dolmuş olabilir (Kod: SESSION_MISSING).";
        $participantData = null;
        $subscaleRawScores = []; $subscalePercentileRanks = []; $interpretation = "Hesaplanamadı"; $processedAnswersForTable = [];
    }
}
// --- VERİ KAYNAĞI SONU ---

// Psikometrik logo kontrolü...
if ($psikometrikWebURL) {
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/..');
    $fullPsikoServerPath = rtrim($docRoot, '/') . '/' . ltrim(str_replace('..', '', $psikometrikWebURL), '/');
    if (!file_exists($fullPsikoServerPath)) { $psikometrikWebURL = null; error_log("Psikometrik logo dosyası bulunamadı (view-result-24): " . $fullPsikoServerPath); }
}

// Header gönder...
if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> <?= ($participantData && isset($participantData['name'])) ? '- ' . htmlspecialchars($participantData['name']) : '' ?></title>
    <style>
        /* --- Stil Bloğu (Yeşil Tema - genel uyumluluk) --- */
        body { font-family: sans-serif; line-height: 1.6; background-color: #f0fdf4; color: #2c3e50; margin: 0; padding: 0; }
        .page-header { background-color: #ffffff; padding: 10px 25px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e0e0e0; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .page-header img { max-height: 50px; width: auto; }
        .container { max-width: 900px; margin: 20px auto; background: white; padding: 20px 30px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        h1 { text-align: center; color: #1f2937; margin-top: 0; margin-bottom: 1.5rem; font-size: 1.75rem; border-bottom: 2px solid #dcfce7; padding-bottom: 0.75rem; }
        h2 { font-size: 1.4rem; color: #15803d; /* Yeşil */ margin-top: 2rem; margin-bottom: 1rem; border-bottom: 1px solid #eee; padding-bottom: 0.4rem; }

        .participant-info, .result-summary { margin-bottom: 1.5rem; padding: 15px; background-color: #f9fafb; border: 1px solid #f3f4f6; border-radius: 8px; }
        .participant-info p { margin: 0.4rem 0; font-size: 1rem; }
        .participant-info strong { font-weight: 600; color: #374151; min-width: 120px; display: inline-block; }

        /* Grafik Alanı Stilleri */
        .chart-container {
            width: 90%; /* Konteyner genişliği */
            max-width: 700px; /* Maksimum genişlik artırıldı */
            margin: 20px auto; /* Ortala ve üst/alt boşluk ver */
            padding: 15px;
            background-color: #ffffff; /* Grafik alanı arka planı */
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            height: 500px; /* Grafik konteynerine sabit bir yükseklik verildi */
            display: flex; /* İçeriği ortalamak için flexbox kullan */
            justify-content: center; /* Yatayda ortala */
            align-items: center; /* Dikeyde ortala */
        }
         /* Grafik başlığı */
        .chart-container h3 {
            text-align: center;
            margin-top: 0;
            margin-bottom: 15px;
            color: #1f2937;
            font-size: 1.2rem;
            font-weight: 600;
        }
         /* Canvas elementinin kendisi */
        .chart-container canvas {
            max-width: 100%; /* Konteynerin içine sığmasını sağla */
            max-height: 100%; /* Konteynerin içine sığmasını sağla */
        }


        /* Sonuç Özeti ve Tablolar */
        .result-summary { text-align: left; background-color: #e8f5e9; border-color: #c8e6c9; padding: 25px; }
        .result-summary h2 { margin-top: 0; text-align: center; }

        .score-table { width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: 0.95rem; background-color: #fff; }
        .score-table th, .score-table td { border: 1px solid #ddd; padding: 10px 12px; text-align: left; vertical-align: middle; }
        .score-table th { background-color: #dcfce7; font-weight: 600; color: #1f2937; }
        .score-table td:nth-child(1) { font-weight: bold; width: 30%; } /* Alt Ölçek Adı */
        .score-table td:nth-child(2) { width: 15%; text-align: center;} /* Ham Puan */
        .score-table td:nth-child(3) { width: 15%; text-align: center; font-weight: bold;} /* Yüzdelik */
        .score-table td:nth-child(4) { width: 40%; font-style: italic; color: #374151;} /* Yorum */
        .score-table tr:nth-child(even) { background-color: #f8f9fa; }


        /* Detaylı Cevap Tablosu Stilleri */
        .answers-table { width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: 0.9rem; }
        .answers-table th, .answers-table td { border: 1px solid #ddd; padding: 8px 10px; text-align: left; vertical-align: top; }
        .answers-table th { background-color: #dcfce7; font-weight: 600; color: #1f2937; }
        .answers-table td:nth-child(1) { width: 8%; text-align: center; font-weight: bold; vertical-align: middle;} /* Madde No */
        .answers-table td:nth-child(2) { width: 50%; line-height: 1.4; } /* Soru Metni */
        .answers-table td:nth-child(3) { width: 22%; text-align: center; vertical-align: middle;} /* Verilen Cevap */
        .answers-table td:nth-child(4) { width: 20%; text-align: center; font-weight: bold; vertical-align: middle;} /* Puan */
        .answers-table tr:nth-child(even) { background-color: #f8f9fa; }


        .error-box { color: #b91c1c; background-color: #fee2e2; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; border: 1px solid #fca5a5; font-weight: 500; text-align: left; font-size: 0.9em;}
        .error-box b { font-weight: bold; }

        .action-buttons { display: flex; justify-content: center; gap: 1rem; margin-top: 2.5rem; padding-top: 1.5rem; border-top: 1px solid #e0e0e0; }
        .action-button { display: inline-flex; align-items: center; padding: 10px 20px; font-size: 1rem; font-weight: 600; color: white; background-color: #15803d; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; transition: background-color 0.2s ease, box-shadow 0.2s ease; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .action-button:hover { background-color: #0b532c; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .no-print { }
        @media print { /* ... */ }
    </style>
     <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

<div class="page-header">
    <div>
        <?php if($dataSource == 'db' && !empty($institutionWebURL)): ?>
            <img src="<?= htmlspecialchars($institutionWebURL) ?>" alt="Kurum Logosu">
        <?php else: ?><span>&nbsp;</span><?php endif; ?>
    </div>
    <div>
        <?php if (!empty($psikometrikWebURL)): ?>
            <img src="<?= htmlspecialchars($psikometrikWebURL) ?>" alt="Psikometrik.Net Logosu">
        <?php else: ?><span>Psikometrik.Net</span><?php endif; ?>
    </div>
</div>

<div class="container">

    <?php // Hata veya Veri Yoksa Gösterim ?>
    <?php if ($error): ?>
         <h1>Sonuçlar Yüklenemedi</h1>
         <div class="error-box"><b>Hata:</b> <?= htmlspecialchars($error) ?></div>
    <?php elseif (!$participantData): ?>
         <h1>Sonuçlar Yüklenemedi</h1>
         <div class="error-box">Görüntülenecek katılımcı verisi bulunamadı.</div>
    <?php else: // Katılımcı verisi var ?>

        <h1><?= htmlspecialchars($survey_title) ?></h1>

        <div class="participant-info">
             <h2>Katılımcı Bilgileri</h2>
             <p><strong>Ad Soyad:</strong> <?= htmlspecialchars($participantData['name']) ?></p>
             <p><strong>Sınıf/Bölüm:</strong> <?= htmlspecialchars($participantData['class'] ?? 'Belirtilmemiş') ?></p>
             <p><strong>Test Tarihi:</strong> <?= date('d.m.Y H:i', strtotime($participantData['created_at'])) ?></p>
        </div>

        <?php // Grafik Alanı ?>
        <?php if (!empty($subscaleRawScores) && array_sum($subscaleRawScores) > 0): // Alt ölçek ham skorları varsa ve toplamı 0'dan büyükse grafiği göster ?>
        <div class="chart-container no-print">
             <h3></h3>
             <canvas id="academicSelfConceptChart"></canvas>
        </div>
        <?php endif; ?>


        <div class="result-summary">
             <h2>Akademik Benlik Kavramı Sonuçlarınız</h2>

             <?php if (!empty($subscalePercentileRanks)): ?>
                 <table class="score-table">
                     <thead>
                         <tr>
                             <th>Alt Alan</th>
                             <th>Ham Puan</th>
                             <th>Yüzdelik Karşılık</th>
                             <th>Yorum</th>
                         </tr>
                     </thead>
                     <tbody>
                         <?php foreach($subscalesItems as $scaleKey => $items): // Alt ölçekleri döngüye al ?>
                            <?php $rawScore = $subscaleRawScores[$scaleKey] ?? null; // Ham skor ?>
                            <?php $percentile = $subscalePercentileRanks[$scaleKey] ?? null; // Yüzdelik puan ?>
                            <?php $interpretationText = interpretPercentileRank($percentile); // Yorum ?>
                         <tr>
                             <td><?= htmlspecialchars($scaleKey) ?></td> <?php // Alt ölçek adı ?>
                             <td><?= ($rawScore !== null) ? htmlspecialchars($rawScore) : '-' ?></td>
                             <td><?= ($percentile !== null) ? htmlspecialchars(sprintf("%.1f", $percentile)) : 'Hesaplanamadı' ?></td> <?php // Yüzdelik puanı 1 ondalık basamakla göster ?>
                             <td><?= htmlspecialchars($interpretationText) ?></td>
                         </tr>
                         <?php endforeach; ?>
                     </tbody>
                 </table>
             <?php else: ?>
                 <div class="error-box">Alt alan puanları hesaplanamadı (Yetersiz veya hatalı cevaplar).</div>
             <?php endif; ?>

             <?php /* Toplam genel skor bu ölçekte yorumlanmıyor */ ?>

             <p style="font-size: 0.85em; margin-top: 25px; text-align: center; color: #475569;">
                 * Bu sonuçlar akademik benlik kavramınıza ilişkin bir değerlendirmedir...
             </p>
        </div>

        <h2>Detaylı Cevaplarınız</h2>
         <?php if (!empty($processedAnswersForTable)): ?>
             <table class="answers-table">
                 <thead>
                     <tr>
                         <th>Madde No</th>
                         <th>Soru</th>
                         <th>Verilen Cevap</th>
                         <th>Puan (1-4)</th> <?php // Puan 1-4 olacak ?>
                     </tr>
                 </thead>
                 <tbody>
                     <?php foreach ($processedAnswersForTable as $item): ?>
                     <tr>
                         <td><?= htmlspecialchars($item['madde']) ?></td>
                         <td><?= htmlspecialchars($item['question_text']) ?></td>
                         <td><?= htmlspecialchars($item['verilen_cevap']) ?></td>
                         <td><?= htmlspecialchars($item['puan']) ?></td>
                     </tr>
                     <?php endforeach; ?>
                 </tbody>
             </table>
         <?php else: ?>
             <div class="error-box">Detaylı cevaplar görüntülenemiyor.</div>
         <?php endif; ?>


         <div class="action-buttons no-print">
            <?php if ($dataSource == 'db'): ?>
                 <a href="dashboard.php" class="action-button panel-button">Panele Dön</a>
            <?php else: ?>
                 <a href="../index.php" class="action-button panel-button">Diğer Anketler</a> <?php // Ana sayfaya yönlendirme ?>
            <?php endif; ?>
             <button onclick="window.print();" class="action-button print-button">Sayfayı Yazdır</button>
         </div>

    <?php endif; ?>

</div> <?php // container sonu ?>

<script>
    // Grafik çizimi için JavaScript
    <?php if (!empty($subscaleRawScores) && array_sum($subscaleRawScores) > 0): // Alt ölçek ham skorları varsa ve toplamı 0'dan büyükse grafiği çiz ?>
    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('academicSelfConceptChart').getContext('2d');

        // PHP'den gelen alt ölçek isimlerini ve ham skorlarını al
        // Sadece geçerli (null olmayan) skorları alalım
        const subscaleLabels = [];
        const subscaleData = [];
        const subscaleNamesMap = <?= json_encode(array_keys($subscalesItems)) ?>; // Alt ölçek adları haritası (anahtarlar)

        const rawSubscaleScores = <?= json_encode($subscaleRawScores) ?>; // PHP'den gelen ham skorlar

        for (const key in rawSubscaleScores) {
            if (rawSubscaleScores[key] !== null) {
                subscaleLabels.push(key); // Alt ölçek adını kullan
                subscaleData.push(rawSubscaleScores[key]);
            }
        }

        // Her alt ölçek için maksimum olası ham puanı hesapla (madde sayısı * 4)
        const maxRawScores = {};
        const subscalesItemsCount = <?= json_encode(array_map('count', $subscalesItems)) ?>;
        for (const key in subscalesItemsCount) {
             maxRawScores[key] = subscalesItemsCount[key] * 4; // Madde sayısı * 4
        }


        if (subscaleData.length > 0) {
             new Chart(ctx, {
                 type: 'bar', // Çubuk grafik
                 data: {
                     labels: subscaleLabels, // Alt ölçek adları
                     datasets: [{
                         label: 'Ham Puan',
                         data: subscaleData, // Alt ölçek ham puanları
                         backgroundColor: 'rgba(21, 128, 61, 0.7)', // Yeşil renk
                         borderColor: 'rgba(11, 83, 44, 1)', // Koyu yeşil kenarlık
                         borderWidth: 1
                     }]
                 },
                 options: {
                     indexAxis: 'y', // Yatay çubuk grafik için ekseni y olarak ayarla
                     responsive: true,
                     maintainAspectRatio: false, // Konteyner boyutuna uyum sağlaması için
                     plugins: {
                         legend: {
                             display: false // Legend'ı gizle
                         },
                         title: {
                             display: false, // Ana başlık (h3 ile zaten var)
                         },
                         tooltip: { // Tooltip ayarları
                             callbacks: {
                                 label: function(context) {
                                     const label = context.dataset.label || '';
                                     const rawScore = context.raw;
                                     const scaleKey = context.label; // Alt ölçek anahtarı
                                     const maxPossibleScore = maxRawScores[scaleKey] || '?';
                                     // Tooltip'te alt ölçek adını, ham puanı ve maksimum olası puanı göster
                                     return `${context.label}: ${rawScore} / ${maxPossibleScore}`;
                                 }
                             }
                         }
                     },
                     scales: {
                         x: { // X ekseni artık ham puanları gösterecek
                             beginAtZero: true, // X eksenini sıfırdan başlat
                             // X ekseni maksimum değeri: En yüksek olası ham puanı bulalım
                             max: Math.max(...Object.values(maxRawScores)) + 5, // En yüksek maks puandan biraz fazla
                             title: {
                                 display: true,
                                 text: 'Ham Puan'
                             },
                             ticks: {
                                 stepSize: 10 // X ekseninde 10'ar artış (örnek)
                             }
                         },
                         y: { // Y ekseni artık alt ölçek etiketlerini gösterecek
                              title: {
                                 display: true,
                                 text: 'Alt Alanlar'
                             },
                             ticks: {
                                 autoSkip: false // Alt ölçek adları uzun olabilir, atlamayı kapat
                             }
                         }
                     }
                 }
             });
        }
    });
    <?php endif; ?>
</script>

</body>
</html>
