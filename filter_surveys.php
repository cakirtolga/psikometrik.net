<?php

// Hata raporlamayı aç (Geliştirme için)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Gerekli dosyaları dahil et
require_once __DIR__ . '/src/config.php'; // Veritabanı bağlantısı

// Yanıt başlığını JSON olarak ayarla
header('Content-Type: application/json');

// Veritabanı bağlantı kontrolü
if (!isset($pdo) || !$pdo instanceof PDO) {
    http_response_code(500); // Sunucu Hatası
    // Hata durumunda JSON formatında yanıt döndür
    echo json_encode(['error' => 'Veritabanı bağlantısı kurulamadı.']);
    exit;
}

// Filtre parametrelerini al (GET ile gönderiliyor)
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
$moneyFilter = isset($_GET['money']) ? trim($_GET['money']) : ''; // 'free', 'paid', veya '' (tümü)
$categoryFilter = isset($_GET['category']) ? trim($_GET['category']) : ''; // Kategori anahtarı veya '' (tümü)

// Kategori anahtarlarına göre arama terimlerini tanımla
// Bu eşleşmeleri anket başlıklarınıza ve açıklamalarınıza göre daha da iyileştirebilirsiniz.
// Anahtar kelimeleri küçük harfe çevirmek, büyük/küçük harf duyarsız arama için önemlidir.
$categoryKeywords = [
    'psikolojik' => ['beck anksiyete', 'beck depresyon', 'kişilik', 'benlik tasarımı', 'yalnızlık', 'scl-90', 'psikolojik belirti'],
    'aile' => ['aile envanteri', 'aile desteği', 'ana baba tutumu', 'ebeveyn'],
    'egitim' => ['çalışma davranışı', 'çoklu zeka', 'öğrenme stilleri', 'sınav kaygısı', 'akademik benlik', 'rehberlik ihtiyacı', 'riba', 'öğrenci', 'öğretmen', 'okul öncesi', 'ilkokul', 'ortaokul', 'lise'],
    'mesleki' => ['holland mesleki', 'mesleki eğilim', 'mesleki olgunluk', 'meslek seçimi'],
    'bagimlilik' => ['internet bağımlılığı', 'oyun bağımlılığı'],
    'sosyal' => ['şiddet algısı', 'şiddet sıklığı', 'rathus atılganlık', 'sosyal'],
    'dikkat_zeka' => ['burdon dikkat', 'dikkat testi', 'zeka ölçeği']
];


// SQL sorgusunu oluşturmaya başla
// type sütununu hala seçiyoruz, belki kartta göstermek istersiniz.
$sql = "SELECT id, title, description, created_at, money, type FROM surveys WHERE status = 'active'";
$params = []; // PDO için parametre dizisi

// Arama terimi filtresi ekle (başlık veya açıklamada)
if (!empty($searchTerm)) {
    // LOWER() fonksiyonu ile büyük/küçük harf duyarsız arama
    $sql .= " AND (LOWER(title) LIKE LOWER(:search) OR LOWER(description) LIKE LOWER(:search))";
    $params[':search'] = '%' . $searchTerm . '%';
}

// Ücret durumu filtresi ekle
if ($moneyFilter === 'free' || $moneyFilter === 'paid') {
    $sql .= " AND money = :money";
    $params[':money'] = $moneyFilter;
}

// Kategori filtresi ekle
if (!empty($categoryFilter) && isset($categoryKeywords[$categoryFilter])) {
    $keywords = $categoryKeywords[$categoryFilter];
    $categoryConditions = [];
    $keywordIndex = 0;
    foreach ($keywords as $keyword) {
        $paramName = ':keyword' . $keywordIndex;
        // Başlıkta VEYA açıklamada anahtar kelimeyi ara (büyük/küçük harf duyarsız)
        // Anahtar kelimelerin de küçük harfle arandığından emin oluyoruz (PDO parametresi zaten küçük harf)
        $categoryConditions[] = "(LOWER(title) LIKE LOWER(" . $paramName . ") OR LOWER(description) LIKE LOWER(" . $paramName . "))";
        $params[$paramName] = '%' . $keyword . '%'; // Anahtar kelimeyi parametreye ekle
        $keywordIndex++;
    }
    // Eğer kategori için koşul oluşturulduysa, SQL'e ekle
    if (!empty($categoryConditions)) {
        $sql .= " AND (" . implode(' OR ', $categoryConditions) . ")";
    }
}

// Sıralama ekle (örneğin, en yeniye göre)
$sql .= " ORDER BY created_at DESC";

// Sorguyu hazırla ve çalıştır
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $filteredSurveys = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Sonuçları JSON olarak döndür
    echo json_encode($filteredSurveys);

} catch (PDOException $e) {
    // Hata oluşursa logla ve JSON hatası döndür
    error_log("Filter Surveys PDO Exception: " . $e->getMessage() . " SQL: " . $sql . " Params: " . print_r($params, true));
    http_response_code(500); // Sunucu Hatası
    echo json_encode(['error' => 'Anketler filtrelenirken bir veritabanı hatası oluştu.']);
    exit;
}

?>
