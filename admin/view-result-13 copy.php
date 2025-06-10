<?php
// Oturum başlatılır. Kullanıcı durumu veya admin bilgisi gibi verileri saklamak için.
session_start();

// Hata raporlama ayarları. Geliştirme aşamasında hataları görmek için açılır.
// Canlı sunucuya geçerken kapatılması veya sadece loglama yapması güvenlik açısından önemlidir.
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Sayfa içeriğinin HTML ve karakter setinin UTF-8 olduğunu belirtir.
header('Content-Type: text/html; charset=utf-8');

// Veritabanı yapılandırma dosyasını dahil et.
// Bu dosyanın $pdo adında bir PDO veritabanı bağlantı nesnesi döndürdüğünü varsaymaktadır.
// require '../src/config.php'; ifadesi, bu dosyanın (view-result-13.php)
// admin/ klasörü içinde olduğunu ve config.php'nin admin klasörünün bir üstündeki src klasöründe olduğunu varsayar.
// Dosya yolunu kendi projenize göre ayarlayın.
require __DIR__ . '/../src/config.php';


// --- Oturum ve Yetki Kontrolü (view-result-12.php'den alındı) ---
// Kullanıcının giriş yapmış olduğunu ve belirli rollere ('admin', 'super-admin') sahip olduğunu kontrol et.
// Bu, sonuçların yetkisiz kişilerce görüntülenmesini engeller.
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super-admin'])) {
    // Eğer kullanıcı giriş yapmamışsa veya yetkili role sahip değilse login sayfasına yönlendir.
    header('Location: ../login.php');
    exit(); // Yönlendirmeden sonra scriptin çalışmasını durdur.
}
// Giriş yapmış kullanıcının ID'sini al.
$loggedInUserId = $_SESSION['user_id'];
// --- Bitiş: Oturum ve Yetki Kontrolü ---


// Bu scriptin hangi anketin (Beck Depresyon Ölçeği) sonuçlarını görüntülediğini belirten ID.
$surveyId = 13;

// Kullanıcıya gösterilecek hata mesajları için değişken. Başlangıçta null veya boş.
$error = null;

// Veritabanından çekilecek katılımcı bilgilerini saklayacak değişken.
$participant = null;

// Sayfa başlığı. Anket başlığı çekildikten sonra güncellenir.
$surveyTitle = 'Anket Sonucu';

// Veritabanından çekilecek anket sorularını saklayacak dizi.
$questions = [];

// Katılımcının veritabanından çekilen cevaplarını (question_id => answer_text) saklayacak dizi.
$participantAnswers = [];

// Gösterim için işlenmiş cevap detaylarını saklayacak dizi (madde no, metin, seçilen metin, puan).
$answersWithDetails = [];

// Hesaplanan toplam puan.
$totalScore = 0;

// Grafik için madde puanlarını saklayacak dizi (sıralı: Madde 1 puanı, Madde 2 puanı...).
$scoresForGraph = [];

// Kurum bilgileri için değişkenler (view-result-12.php'den alındı).
$institutionLogoPath = null;
$institutionName = null;

// URL'den 'participant_id' parametresini al ve integer'a dönüştür. Yoksa 0 olur.
// **ÖNEMLİ DEĞİŞİKLİK (view-result-13.php'nin önceki versiyonuna göre):** Hem 'participant_id' hem de 'id' parametrelerini kontrol et.
// Bu, dashboarddan hala 'id' parametresi gönderiliyorsa çalışmasını sağlar.
$participantId = 0; // Başlangıç değeri 0.
if (isset($_GET['participant_id']) && $_GET['participant_id'] !== '') {
    // Eğer 'participant_id' parametresi varsa, onu kullan.
    $participantId = (int)$_GET['participant_id'];
} elseif (isset($_GET['id']) && $_GET['id'] !== '') {
    // Eğer 'participant_id' yoksa VEYA boşsa, ve 'id' parametresi varsa, 'id'yi kullan.
    $participantId = (int)$_GET['id'];
    // İsteğe bağlı: Yanlış parametre kullanıldığına dair loglama yap.
    error_log("Warning: 'id' parameter used instead of 'participant_id' in view-result-13.php for participant ID " . $participantId);
}
// Eğer ne 'participant_id' ne de 'id' geçerli bir değer içeriyorsa, $participantId 0 kalır.


// Katılımcı ID'si hala geçerli değilse (0 veya daha az ise) hata mesajı ayarla.
if ($participantId <= 0) {
    $error = "Görüntülenecek katılımcı kimliği belirtilmemiş veya geçersiz.";
} else {
    // Katılımcı ID'si geçerliyse, veritabanından ilgili bilgileri çekmeye çalış.
    try {
        // 1. Katılımcı Bilgilerini VE İLİŞKİLİ ADMİNİN LOGO YOLUNU/ADINI Çek (view-result-12.php'den alındı)
        // 'survey_participants' tablosunu 'users' tablosuyla LEFT JOIN yaparak admin bilgilerini al.
        // Belirtilen ID'ye sahip katılımcıyı ve anket ID'sinin bu anketle (13) eşleştiğini kontrol et.
        $stmt = $pdo->prepare("
            SELECT sp.*, u.institution_logo_path, u.institution_name
            FROM survey_participants sp
            LEFT JOIN users u ON sp.admin_id = u.id
            WHERE sp.id = ? AND sp.survey_id = ?
        ");
        $stmt->execute([$participantId, $surveyId]); // participantId ve surveyId parametrelerini güvenli bağla.
        $participant = $stmt->fetch(PDO::FETCH_ASSOC); // Sonucu al.

        // Katılımcı bulunamazsa veya bulunan katılımcının anket ID'si bu anketle (13) eşleşmezse hata fırlat.
        if (!$participant) {
            // Eğer katılımcı bulunamazsa veya anket ID'si eşleşmezse hata mesajı.
            throw new Exception("Belirtilen kimlik ile Anket ID {$surveyId} için katılımcı sonucu bulunamadı.");
        }

        // --- Yetkilendirme Kontrolü (view-result-12.php'den alındı ve buraya eklendi) ---
        // Giriş yapmış kullanıcının (loggedInUserId) bu katılımcının admini olup olmadığını VEYA super-admin rolüne sahip olup olmadığını kontrol et.
        $isSuperAdmin = (isset($_SESSION['role']) && $_SESSION['role'] === 'super-admin');
        if ($participant['admin_id'] != $loggedInUserId && !$isSuperAdmin) {
             // Eğer yetkisi yoksa hata fırlat.
             throw new Exception('Bu anket sonucunu görüntüleme yetkiniz yok.');
        }
        // Yetkilendirme başarılıysa devam et.
        // --- Bitiş: Yetkilendirme Kontrolü ---

        // Kurum bilgilerini katılımcı bilgisinden al.
        $institutionLogoPath = $participant['institution_logo_path'];
        $institutionName = $participant['institution_name'];


        // 2. Anket Başlığını Çek
        // 'surveys' tablosundan bu anketin başlığını çek.
        $stmt = $pdo->prepare("SELECT title FROM surveys WHERE id = ?");
        $stmt->execute([$surveyId]); // Anket ID'sini bağla.
        $survey = $stmt->fetch(PDO::FETCH_ASSOC); // Sonucu al.
        if ($survey) {
            // Anket başlığı bulunduysa sayfa başlığını ayarla.
            $surveyTitle = htmlspecialchars($survey['title']) . ' Sonucu';
        } else {
             // Anket başlığı bulunamazsa (beklenmedik durum), logla ama scripti durdurma, genel başlık kalsın.
             error_log("Survey title not found for ID: " . $surveyId . " for Participant ID: " . $participantId);
        }

        // 3. Anket Sorularını Çek
        // 'survey_questions' tablosından bu ankete ait soruları çek.
        // **ÖNEMLİ:** Soruları 'sort_order' sütununa göre artan sırada sırala. Bu sıra, soruların Beck maddeleriyle eşleştirilmesinde kullanılacak.
        // 'id', 'question' (soru metni) ve 'sort_order' (sıralama numarası) sütunlarını çek.
        $stmt = $pdo->prepare("SELECT id, question, sort_order FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC");
        $stmt->execute([$surveyId]); // Anket ID'sini bağla.
        $questions = $stmt->fetchAll(PDO::FETCH_ASSOC); // Tüm sonuçları al.

        // Eğer anketin hiç sorusu yoksa hata fırlat.
        if (count($questions) === 0) {
            throw new Exception('Bu anket için tanımlanmış soru bulunamadı.');
        }

        // 4. Katılımcının Cevaplarını Çek
        // 'survey_answers' tablosundan bu katılımcının verdiği cevapları çek.
        // Daha önceki take-survey kodumuzda answer_text sütununa seçeneğin TAM METNİNİ kaydetmiştik.
        // Sorgu, 'question_id' ve 'answer_text' sütunlarını çeker.
        $stmt = $pdo->prepare("SELECT question_id, answer_text FROM survey_answers WHERE participant_id = ?");
        $stmt->execute([$participantId]); // Katılımcı ID'sini bağla.

        // Çekilen cevapları 'question_id' ye göre erişimi kolaylaştırmak için bir diziye al (qid => answer_text).
        // Bu, bir sorunun cevabına ID'si üzerinden hızlıca erişmemizi sağlar.
        $participantAnswers = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $participantAnswers[$row['question_id']] = $row['answer_text'];
        }

        // Eğer çekilen cevap sayısı toplam soru sayısından az ise (veya hiç yoksa) uyarı logla.
        // Bu, kullanıcının anketi tam doldurmadığı veya kaydetme hatası yaşandığı anlamına gelebilir.
        if (count($participantAnswers) === 0) {
             // Cevap yoksa hata ver.
             throw new Exception("Bu katılımcı için anket cevapları veritabanında bulunamadı.");
        } elseif (count($participantAnswers) < count($questions)) {
             // Cevap sayısı eksikse logla.
             error_log("Warning: Participant " . $participantId . " has fewer answers (".count($participantAnswers).") than questions (".count($questions).") for Survey ID " . $surveyId);
             // İsterseniz kullanıcıya da uyarı gösterebilirsiniz.
             // $error = ($error ? $error . '<br>' : '') . "Katılımcının tüm soruları yanıtlamamış olabilir veya bazı cevaplar kaydedilememiş.";
        }


        // 5. Beck Seçenekleri ve Puanları (Sabit Tanımlama)
        // Bu dizi, Beck maddeleri için metinleri ve bunların puan karşılıklarını içerir.
        // Anahtar: Beck madde numarası (1-21). Değer: Puan indeksine (0-3) karşılık gelen metin dizisi.
        // Bu dizi, kaydedilen cevap metninden puanı bulmak için kullanılacak.
        $beck_options = [
            1 => ["Kendimi üzüntülü ve sıkıntılı hissetmiyorum.", "Kendimi üzüntülü ve sıkıntılı hissediyorum.", "Hep üzüntülü ve sıkıntılıyım. Bundan kurtulamıyorum.", "O kadar üzüntülü ve sıkıntılıyım ki artık dayanamıyorum."],
            2 => ["Gelecek hakkında mutsuz ve karamsar değilim.", "Gelecek hakkında karamsarım.", "Gelecekten beklediğim hiçbir şey yok.", "Geleceğim hakkında umutsuzum ve sanki hiçbir şey düzelmeyecekmiş gibi geliyor."],
            3 => ["Kendimi başarısız bir insan olarak görmüyorum.", "Çevremdeki birçok kişiden daha çok başarısızlıklarım olmuş gibi hissediyorum.", "Geçmişe baktığımda başarısızlıklarla dolu olduğunu görüyorum.", "Kendimi tümüyle başarısız biri olarak görüyorum."],
            4 => ["Birçok şeyden eskisi kadar zevk alıyorum.", "Eskiden olduğu gibi her şeyden hoşlanmıyorum.", "Artık hiçbir şey bana tam anlamıyla zevk vermiyor.", "Her şeyden sıkılıyorum."],
            5 => ["Kendimi herhangi bir şekilde suçlu hissetmiyorum.", "Kendimi zaman zaman suçlu hissediyorum.", "Çoğu zaman kendimi suçlu hissediyorum.", "Kendimi her zaman suçlu hissediyorum."],
            6 => ["Bana cezalandırılmışım gibi gelmiyor.", "Cezalandırılabileceğimi hissediyorum.", "Cezalandırılmayı bekliyorum.", "Cezalandırıldığımı hissediyorum."],
            7 => ["Kendimden memnunum.", "Kendi kendimden pek memnun değilim.", "Kendime çok kızıyorum.", "Kendimden nefret ediyorum."],
            8 => ["Başkalarından daha kötü olduğumu sanmıyorum.", "Zayıf yanlarım veya hatalarım için kendi kendimi eleştiririm.", "Hatalarımdan dolayı ve her zaman kendimi kabahatli bulurum.", "Her aksilik karşısında kendimi hatalı bulurum."],
            9 => ["Kendimi öldürmek gibi düşüncelerim yok.", "Zaman zaman kendimi öldürmeyi düşündüğüm olur. Fakat yapmıyorum.", "Kendimi öldürmek isterdim.", "Fırsatını bulsam kendimi öldürdüm."],
            10 => ["Her zamankinden fazla içimden ağlamak gelmiyor.", "Zaman zaman içimden ağlamak geliyor.", "Çoğu zaman ağlıyorum.", "Eskiden ağlayabilirdim şimdi istesem de ağlayamıyorum."],
            11 => ["Şimdi her zaman olduğumdan daha sinirli değilim.", "Eskisine kıyasla daha kolay kızıyor ya da sinirleniyorum.", "Şimdi hep sinirliyim.", "Bir zamanlar beni sinirlendiren şeyler şimdi hiç sinirlendirmiyor."],
            12 => ["Başkaları ile görüşmek, konuşmak isteğimi kaybetmedim.", "Başkaları ile eskiden daha az konuşmak, görüşmek istiyorum.", "Başkaları ile konuşma ve görüşme isteğimi kaybetmedim.", "Hiç kimseyle konuşmak görüşmek istemiyorum."],
            13 => ["Eskiden olduğu gibi kolay karar verebiliyorum.", "Eskiden olduğu kadar kolay karar veremiyorum.", "Karar verirken eskisine kıyasla çok güçlük çekiyorum.", "Artık hiç karar veremiyorum."],
            14 => ["Aynada kendime baktığımda değişiklik görmüyorum.", "Daha yaşlanmış ve çirkinleşmişim gibi geliyor.", "Görünüşümün çok değiştiğini ve çirkinleştiğimi hissediyorum.", "Kendimi çok çirkin buluyorum."],
            15 => ["Eskisi kadar iyi çalışabiliyorum.", "Bir şeyler yapabilmek için gayret göstermem gerekiyor.", "Herhangi bir şeyi yapabilmek için kendimi çok zorlamam gerekiyor.", "Hiçbir şey yapamıyorum."],
            16 => ["Her zamanki gibi iyi uyuyabiliyorum.", "Eskiden olduğu gibi iyi uyuyamıyorum.", "Her zamankinden 1-2 saat daha erken uyanıyorum ve tekrar uyuyamıyorum.", "Her zamankinden çok daha erken uyanıyor ve tekrar uyuyamıyorum."],
            17 => ["Her zamankinden daha çabuk yorulmuyorum.", "Her zamankinden daha çabuk yoruluyorum.", "Yaptığım her şey beni yoruyor.", "Kendimi hemen hiçbir şey yapamayacak kadar yorgun hissediyorum."],
            18 => ["İştahım her zamanki gibi.", "İştahım her zamanki kadar iyi değil.", "İştahım çok azaldı.", "Artık hiç iştahım yok."],
            19 => ["Son zamanlarda kilo vermedim.", "İki kilodan fazla kilo verdim.", "Dört kilodan fazla kilo verdim.", "Altı kilodan fazla kilo vermeye çalışıyorum."],
            20 => ["Sağlığım beni fazla endişelendirmiyor.", "Ağrı, sancı, mide bozukluğu veya kabızlık gibi rahatsızlıklar beni endişelendirmiyor.", "Sağlığım beni endişelendirdiği için başka şeyleri düşünmek zorlaşıyor.", "Sağlığım hakkında o kadar endişeliyim ki başka hiçbir şey düşünemiyorum."],
            21 => ["Son zamanlarda cinsel konulara olan ilgimde bir değişme fark etmedim.", "Cinsel konularla eskisinden daha az ilgiliyim.", "Cinsel konularla şimdi çok daha az ilgiliyim.", "Cinsel konulara olan ilgimi tamamen kaybettim."]
        ];

        // Beck Depresyon Envanteri Puan Aralıkları ve Yorumları (PDF'ten alınmıştır)
        // Bu yorumlar tıbbi/psikolojik tavsiye değildir ve sadece bilgi amaçlıdır.
        $beck_score_interpretation = [
            [0, 9, "Minimal Düzeyde Depresyon"],
            [10, 16, "Hafif Düzeyde Depresyon"],
            [17, 29, "Orta Düzeyde Depresyon"],
            [30, 63, "Şiddetli Düzeyde Depresyon"]
        ];


        // 6. Cevapları işle, puanları hesapla ve gösterim için hazırla.
        // Sorular veritabanından sort_order'a göre sıralı çekildiği için,
        // döngü indeksi ($index) kullanılarak 1-tabanlı Beck madde numarasına ($index + 1) erişilir.
        // Bu, Beck seçeneklerine ($beck_options) erişimde kullanılacak anahtardır.
        // Bu şekilde, veritabanındaki sort_order'lar düzeltilmese bile, çekilen sıraya göre Beck seçenekleri eşlenir.

        $scoresForGraph = []; // Grafik için madde puanlarını saklayacak dizi (sıralı: Madde 1 puanı, Madde 2 puanı...)

        // Veritabanından çekilen her soruyu (sort_order'a göre sıralı) döngüye al.
        if (count($questions) > 0) {
             foreach ($questions as $index => $q) {
                 $qid = $q['id']; // Sorunun veritabanı ID'si
                 // Kullanıcıya gösterilecek ve $beck_options anahtarı olarak kullanılacak 1-tabanlı madde numarası
                 // Bu, döngüdeki sorunun sırasıdır (0-tabanlı indeks + 1).
                 $maddeNumber = $index + 1;

                 // Katılımcının bu soruya verdiği cevabı (metin olarak kaydedilmiş) participantAnswers dizisinden al.
                 // Eğer yanıt yoksa 'Yanıtlanmamış' varsay.
                 $answerText = $participantAnswers[$qid] ?? 'Yanıtlanmamış';

                 // Bu madde için hesaplanacak puanı saklayacak değişken. Varsayılan '-' (henüz hesaplanmadı).
                 $answerScore = '-';

                 // Eğer cevap metni geldiyse (yani yanıtlanmışsa) ve ilgili madde için beck_options mevcutsa...
                 if ($answerText !== 'Yanıtlanmamış' && isset($beck_options[$maddeNumber])) {
                     // İlgili maddenin 0-3 puanlık metinlerini beck_options dizisinden al.
                     $optionsForMadde = $beck_options[$maddeNumber];

                     // Kaydedilmiş cevap metnini ($answerText), o maddeye ait beklenen metinler (optionsForMadde) içinde arayarak puanı bul.
                     // array_search, bulunarsa metnin dizideki anahtarını (bu durumda 0, 1, 2 veya 3 olan puanı) döndürür.
                     // Eğer metin bulunamazsa false döner.
                     $score = array_search($answerText, $optionsForMadde);

                     // Eğer metin beklenen metinler arasında bulunduysa ($score false değilse)...
                     if ($score !== false) {
                         $answerScore = (int)$score; // Bulunan puanı integer yap.
                         $totalScore += $answerScore; // Toplam puana bu maddenin puanını ekle.
                         $scoresForGraph[] = $answerScore; // Grafik için puan dizisine ekle.
                     } else {
                         // Cevap metni geldi ama beck_options içinde o madde için beklenen metinler arasında bulunamadı.
                         // Bu bir veri tutarsızlığı veya beck_options'ta hata olduğunu gösterir.
                         // Hata detayını sunucu loglarına kaydet.
                         error_log("Data Error: Saved answer text '" . htmlspecialchars($answerText) . "' not found in \$beck_options for Madde " . $maddeNumber . " (QID: " . $qid . ") for Participant ID " . $participantId);
                         // Bu durumda puanı 0 kabul edebilir (grafik ve toplam puan için) veya '-' bırakabiliriz.
                         // Puanı 0 kabul etmek toplam puan ve grafik için daha tutarlı olabilir.
                         $answerScore = 0; // Hata durumunda puanı 0 kabul et.
                         $scoresForGraph[] = $answerScore; // Grafik için puana 0 ekle.
                         // $answerScore = '-'; // Puanı '-' olarak bırakırsanız grafikte göstermeyebilirsiniz.
                     }
                 } else {
                      // Eğer cevap metni gelmediyse ('Yanıtlanmamış' ise)...
                      // Bu durumda puanı 0 kabul et ve grafik için puan dizisine ekle.
                      $answerScore = 0;
                      $scoresForGraph[] = $answerScore;
                      // $answerScore zaten '-' olarak varsayılan değeri taşıyor, değiştirmeye gerek yok eğer '-' göstermek isterseniz.
                 }

                 // Gösterim için işlenmiş cevap detayları dizisine bu maddenin bilgilerini ekle.
                 $answersWithDetails[] = [
                     'number' => $maddeNumber, // 1-tabanlı madde numarası
                     'text' => htmlspecialchars($q['question']), // Soru metni (veritabanından çekildi, HTML'den koru)
                     'selected_text' => htmlspecialchars($answerText), // Katılımcının seçtiği veya 'Yanıtlanmamış' metni (HTML'den koru)
                     'score' => $answerScore, // Bu madde için hesaplanan puan (int veya '-')
                     'question_id' => $qid // Debug için soru ID'si
                 ];
             } // foreach $questions sonu.

        } else {
             // Anket soruları veritabanından çekilemediyse (questions dizisi boşsa) hata mesajı.
             $error = ($error ? $error . '<br>' : '') . "Anket soruları veritabanından yüklenemedi. Sonuçlar gösterilemiyor.";
        }


    } catch (PDOException $e) {
        // Veritabanı ile ilgili (PDO) bir hata oluşursa.
        $error = "Veritabanı hatası nedeniyle sonuçlar yüklenemedi. Lütfen yönetici ile iletişime geçin."; // Kullanıcıya gösterilecek genel mesaj.
        error_log("PDO Error in view-result-13.php for Participant ID " . $participantId . ": " . $e->getMessage()); // Hata detayını sunucu loglarına kaydet.
    } catch (Exception $e) {
         // Diğer genel hatalar (kendi fırlattığımız Exception'lar dahil) oluşursa.
         $error = "Sonuçlar yüklenirken bir hata oluştu: " . $e->getMessage(); // Kullanıcıya hata mesajı.
         error_log("General Error in view-result-13.php for Participant ID " . $participantId . ": " . $e->getMessage()); // Hata detayını sunucu loglarına kaydet.
    }
}

// Toplam puana göre yorumu belirle (beck_score_interpretation dizisini kullan).
// $totalScore hesaplandıktan sonra bu kısım çalışır.
$interpretation = "Puan yorumu mevcut değil."; // Varsayılan yorum.
// beck_score_interpretation dizisindeki her aralığı kontrol et.
foreach($beck_score_interpretation as $range) {
    // $totalScore, current $range'in minimum ($range[0]) ve maksimum ($range[1]) değerleri arasında mı?
    if ($totalScore >= $range[0] && $totalScore <= $range[1]) {
        $interpretation = $range[2]; // Uygun yorumu al.
        break; // Yorum bulununca döngüyü kır.
    }
}

// Grafik için madde numarası etiketlerini hazırla (1'den madde sayısına kadar).
// Eğer hiç soru çekilemediyse (totalQuestions 0 ise) veya $scoresForGraph boşsa, boş bir dizi kullanılır.
$graphLabels = range(1, count($scoresForGraph) > 0 ? count($scoresForGraph) : (count($questions) > 0 ? count($questions) : 21));
// Grafik için puanlar PHP'de hesaplandı ($scoresForGraph dizisi). Bu dizi JavaScript'e aktarılacak.
// json_encode() PHP dizisini JavaScript/JSON formatına çevirir.
$scoresJson = json_encode($scoresForGraph);

// Grafik verisi JSON formatında geçerli değilse, Chart.js hata almaması için boş diziye ayarla.
if ($scoresJson === false) {
     error_log("JSON Encode Error for graph data: " . json_last_error_msg());
     $scoresJson = '[]'; // Hata durumunda boş JSON dizisi gönder.
}


// Grafik için etiketler JSON formatında geçerli değilse, Chart.js hata almaması için boş diziye ayarla.
$graphLabelsJson = json_encode($graphLabels);
if ($graphLabelsJson === false) {
     error_log("JSON Encode Error for graph labels: " . json_last_error_msg());
     $graphLabelsJson = '[]'; // Hata durumunda boş JSON dizisi gönder.
}


?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $surveyTitle ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        /* --- Genel Sayfa ve Konteyner Stili (take-survey-13.php ile tutarlı) --- */
        body {
            font-family: sans-serif; /* Sans-serif font ailesi kullan */
            line-height: 1.6; /* Metin satır yüksekliği */
            background-color: #f0fdf4; /* Açık yeşil arka plan rengi (pastel ton) */
            color: #2c3e50; /* Varsayılan metin rengi (koyu gri) */
            margin: 0;
            padding: 20px; /* Sayfa kenarlarına iç boşluk */
            display: flex; /* Flexbox kullanarak içerik düzeni */
            justify-content: center; /* Ana içeriği yatayda (ana eksen) ortala */
            align-items: flex-start; /* İçeriği dikeyde (çapraz eksen) üstten başlat */
            min-height: 100vh; /* Sayfanın minimum yüksekliği */
            box-sizing: border-box; /* Padding ve kenarlığın elementin toplam boyutuna dahil edilmesini sağla */
        }
        .container {
            max-width: 700px; /* Sonuç sayfası için biraz daha geniş konteyner */
            width: 100%; /* Ekran daraldığında tam genişlik kullan */
            margin: 30px auto; /* Üstte ve altta 30px boşluk, solda ve sağda otomatik boşluk (yatayda ortalar) */
            background: white; /* Konteynerin arka plan rengi beyaz */
            padding: 30px; /* Konteynerin iç kenarlarına boşluk */
            border-radius: 10px; /* Konteynerin köşe yuvarlaklığı */
            box-shadow: 0 2px 15px rgba(0,0,0,0.1); /* Konteyner için hafif bir alt ve kenar gölgesi */
            box-sizing: border-box; /* Padding'in toplam genişliğe dahil olmasını sağla */
        }

        /* --- Başlık Stili (Anket Sonucu Başlığı) --- */
        h2.survey-title {
            color: #166534; /* Koyu tema yeşili başlık metin rengi */
            text-align: center; /* Başlığı yatayda ortala */
            margin-bottom: 1.5rem; /* Alt boşluk */
            font-size: 1.8rem; /* Başlık font boyutu */
            border-bottom: 2px solid #dcfce7; /* Başlığın altına açık yeşil bir çizgi */
            padding-bottom: 0.75rem; /* Başlık metni ile altındaki çizgi arasındaki boşluk */
        }
         h3 {
             color: #166534; /* Koyu tema yeşili alt başlık metin rengi */
             margin-top: 1.5rem; /* Üst boşluk */
             margin-bottom: 1rem; /* Alt boşluk */
             font-size: 1.4rem; /* Alt başlık font boyutu */
             border-bottom: 1px solid #dcfce7; /* Alt başlığın altına açık yeşil bir çizgi */
             padding-bottom: 0.5rem; /* Metin ile çizgi arası boşluk */
         }

        /* --- Katılımcı Bilgileri Kutusu Stili --- */
        .participant-info {
            background-color: #e0f2fe; /* Açık mavi arka plan (pastel ton) */
            border-left: 4px solid #3b82f6; /* Sol kenarda mavi kalın çizgi */
            padding: 15px; /* İç boşluk */
            margin-bottom: 25px; /* Alt boşluk */
            border-radius: 4px; /* Köşe yuvarlaklığı */
            font-size: 0.95rem; /* Font boyutu */
            color: #1e3a8a; /* Koyu mavi metin rengi */
        }
         .participant-info p {
             margin-bottom: 5px; /* Paragraflar arasına alt boşluk */
         }
         .participant-info p:last-child {
              margin-bottom: 0; /* Son paragrafın altında ekstra boşluk olmasın */
         }
         .participant-info p strong {
             margin-right: 5px; /* Kalın metin (etiket) ile değer arasına boşluk */
         }

        /* --- Soru Sonucu Stili --- */
        .question-result {
            margin-bottom: 1.5rem; /* Soru sonuçları arasına alt boşluk */
            padding-bottom: 1.5rem; /* İçerik ile altındaki ayırıcı çizgi arasına boşluk */
            border-bottom: 1px dashed #d1d5db; /* Soru sonuçları arasına kesikli çizgi */
        }
        .question-result:last-child {
            border-bottom: none; /* Son soru sonucunun altında ayırıcı çizgi olmasın */
            margin-bottom: 0; /* Son maddenin altında ekstra boşluk olmasın */
            padding-bottom: 0; /* Son maddenin altında ekstra boşluk olmasın */
        }
        /* Soru numarası stili */
        .question-result p strong {
             margin-right: 8px; /* Soru numarası ile metin arasına boşluk */
             color: #1e3a8a; /* Soru numarası metin rengi (koyu mavi) */
             font-weight: 700; /* Ekstra kalın font */
        }
        /* Soru metni stili */
        .question-result p {
             font-weight: 600; /* Yarı kalın font */
             margin-bottom: 0.5rem; /* Soru metni ile seçilen cevap arasına boşluk */
             color: #333; /* Soru metni rengi */
        }
         /* Kullanıcının seçtiği cevap metni stili */
         .selected-answer {
             margin-top: 0.75rem; /* Üst boşluk */
             padding: 10px 15px; /* İç boşluk */
             background-color: #dcfce7; /* Arka plan rengi (açık yeşil - Beck stilinden) */
             border: 1px solid #15803d; /* Kenarlık rengi (koyu tema yeşili) */
             border-radius: 6px; /* Köşe yuvarlaklığı */
             color: #14532d; /* Metin rengi (koyu tema yeşili) */
             font-weight: 600; /* Kalın font */
             display: inline-block; /* İçeriği kadar yer kapla */
         }
         /* Cevabın puanı stili */
         .answer-score {
              margin-top: 0.5rem; /* Üst boşluk */
              font-size: 0.9em; /* Font boyutu biraz küçük */
              color: #555; /* Metin rengi (orta gri) */
         }

        /* --- Toplam Puan Alanı Stili --- */
        .total-score-section {
            margin-top: 2rem; /* Üst boşluk */
            padding: 20px; /* İç boşluk */
            background-color: #d1fae5; /* Arka plan rengi (daha belirgin açık yeşil) */
            border-radius: 8px; /* Köşe yuvarlaklığı */
            text-align: center; /* Metni ortala */
            font-size: 1.2rem; /* Font boyutu */
            font-weight: 700; /* Ekstra kalın font */
            color: #065f46; /* Metin rengi (koyu tema yeşili) */
            border: 2px solid #34d399; /* Kenarlık (tema yeşili) */
        }

        /* --- Hata Mesajı Stili --- */
         .error-message {
             color: #b91c1c; /* Koyu kırmızı metin */
             background-color: #fee2e2; /* Açık kırmızı arka plan */
             padding: 1rem; /* İç boşluk */
             border-radius: 0.5rem; /* Köşe yuvarlaklığı */
             margin-bottom: 1.5rem; /* Alt boşluk */
             border: 1px solid #fca5a5; /* Kırmızı kenarlık */
             font-weight: bold; /* Kalın metin */
             text-align: center; /* Metni ortala */
        }

        /* --- Geri Dön Linki Stili --- */
         .back-link {
             display: block; /* Bloğa çevir */
             text-align: center; /* Ortala */
             margin-top: 2rem; /* Üst boşluk */
             color: #2563eb; /* Mavi link rengi */
             text-decoration: none; /* Alt çizgi olmasın */
             font-size: 1.1em; /* Font boyutu biraz büyük */
         }
         .back-link:hover {
             text-decoration: underline; /* Üzerine gelince alt çizgi çıksın */
         }

        /* --- Grafik Alanı Stili --- */
         .graph-section {
             margin-top: 2rem; /* Üst boşluk */
             padding-top: 1.5rem; /* Üst iç boşluk */
             border-top: 1px dashed #d1d5db; /* Üstte kesikli çizgi */
         }
         .graph-section canvas {
              max-width: 100%; /* Konteyner genişliğini aşmasın */
              height: auto !important; /* Chart.js'in otomatik yüksekliği korunsun */
         }
         /* --- Yazdırma Stilleri (@media print) --- */
        @media print {
            /* Yazdırma sırasında gizlenecek elementler */
            .back-link, #printButton {
                display: none !important;
            }

            /* Yazdırma için arka planları ve gölgeleri sıfırla */
            body {
                background-color: #fff !important; /* Arka planı beyaz yap */
                color: #000 !important; /* Metin rengini siyah yap */
            }
            .container {
                box-shadow: none !important; /* Gölgeyi kaldır */
                margin: 0 auto !important; /* Ortalamayı koru */
                padding: 10mm !important; /* Kenar boşlukları */
                max-width: 100% !important; /* Tam genişlik kullan */
            }

            /* Metin ve diğer elementlerin renklerini siyah yap */
            h2, h3, p, div, span, strong {
                color: #000 !important;
            }

            /* Kutu ve bölüm arka planlarını/kenarlıklarını ayarla */
            .total-score-section, .participant-info, .selected-answer {
                 background-color: #fff !important; /* Arka planı beyaz yap */
                 border-color: #000 !important; /* Kenarlıkları siyah yap */
                 box-shadow: none !important; /* Gölgeyi kaldır */
            }
            .question-result {
                 border-bottom-color: #000 !important; /* Ayırıcı çizgi rengini siyah yap */
                 border-bottom-style: solid !important; /* Kesikli çizgiyi düz çizgi yap (isteğe bağlı) */
             }
            .question-result p, .info-section p, .result-section li {
                 margin-bottom: 4px !important; /* Paragraflar arasına boşluk azalt */
            }

            /* Flexbox/Grid düzenlerini yazdırma için bloğa çevir (varsayılan yazdırma davranışı) */
            .grid, .flex, .options-group {
                display: block !important;
            }

            /* Kenar boşluklarını yazdırma için ayarla */
             .mb-6, .mt-8, .mb-4, .mt-4, .mb-3, .mt-3, .mt-1, .mb-1 {
                 margin-bottom: 10px !important;
                 margin-top: 10px !important;
             }
             .p-4, .p-6 { padding: 0 !important; } /* Konteyner iç paddingini kaldır (isteğe bağlı) */

             /* Grafik boyutunu yazdırma için ayarla (isteğe bağlı, Chart.js genellikle iyi yönetir) */
             .graph-section canvas {
                 max-width: 95% !important;
                 height: auto !important;
                 margin: 0 auto !important; /* Ortala */
             }

             /* Sayfa sonu kırma (isteğe bağlı) */
             .question-result {
                 page-break-inside: avoid; /* Soru sonucunun ortasından sayfa sonu kırma */
             }
             .graph-section {
                 page-break-before: always; /* Grafikten önce her zaman yeni sayfa başlat (isteğe bağlı) */
             }

            /* Yazdırma başlığı (Logolar ve Başlık) */
             .print-header {
                 display: flex !important; /* Flexbox kullanarak logo ve başlığı düzenle */
                 justify-content: space-between !important; /* Elemanları iki yana yasla */
                 align-items: center !important; /* Dikeyde ortala */
                 margin-bottom: 10mm !important; /* Başlığın altına boşluk */
                 width: 100%; /* Tam genişlik */
                 position: relative; /* İçindeki sabit elemanlar için konum */
                 padding-bottom: 10mm; /* Başlık alt çizgisinin altındaki boşluk */
                 border-bottom: 1px solid #ccc !important; /* Başlık alt çizgisi */
             }

             .institution-logo { /* Kurum Logosu (Sol Üst) */
                 max-height: 40px !important; /* Logoların maksimum yüksekliği */
                 width: auto;
                 display: block !important; /* Göster */
                 position: absolute; /* Sabit pozisyondan çıkar */
                 left: 0;
                 top: 0;
             }

             .psikometrik-logo-print { /* Psikometrik Logo (Sağ Üst) */
                 max-height: 30px !important; /* Logoların maksimum yüksekliği */
                 width: auto;
                 display: block !important; /* Göster */
                 position: absolute; /* Sabit pozisyondan çıkar */
                 right: 0;
                 top: 0;
             }

             h1.survey-title {
                  flex-grow: 1; /* Ortada yer kapla */
                  text-align: center !important; /* Ortala */
                  font-size: 12pt !important; /* Font boyutu */
                  margin: 0 10mm !important; /* Logolarla arasında boşluk bırak */
                  border-bottom: none !important; /* Üstteki başlık alt çizgisini kaldır */
                  padding-bottom: 0 !important; /* Üstteki başlık alt çizgisinin boşluğunu kaldır */
                  color: #000 !important;
             }
        }
         /* Tailwind btn-gray sınıfı tanımı (eğer tailwind.config.js dosyanızda yoksa) */
         .btn-gray {
             background-color: #6b7280; /* Gri arka plan */
             color: white; /* Beyaz metin */
         }
         .btn-gray:hover {
             background-color: #4b5563; /* Üzerine gelindiğinde daha koyu gri */
         }

    </style>
</head>
<body class="bg-[#f0fdf4] min-h-screen p-4">
<div class="container">
    <div class="print-only print-header">
        <?php
        // Kurum logosunun URL'sini hazırla. Dosyanın varlığını ve güvenli yolunu kontrol et.
        $institutionLogoUrl = '';
        if ($participant && !empty($institutionLogoPath)) {
            // __DIR__ bu dosyanın (view-result-13.php) bulunduğu dizindir (örn: admin/).
            // Logo yolu genellikle 'uploads/logos/...' şeklindedir ve web sitesinin kök dizininden başlar.
            // Realpath ile yolu çöz ve strpos ile güvenli klasörde (uploads/logos) olduğundan emin ol.
            $potentialPath = realpath(__DIR__ . '/../' . $institutionLogoPath); // admin/view-result-13.php -> admin/../uploads/logos -> uploads/logos
            if ($potentialPath && strpos($potentialPath, realpath(__DIR__ . '/../uploads/logos')) === 0 && file_exists($potentialPath)) {
                 // Web'den erişilebilir URL'yi oluştur. '..' ile üst dizine çık.
                 $institutionLogoUrl = '../' . htmlspecialchars($institutionLogoPath) . '?t=' . time(); // ?t=time() önbelleği önlemek için
            } else {
                 error_log("Güvenlik Hatası veya Dosya Bulunamadı: Kurum logo dosyası beklendiği yolda değil veya bulunamadı: " . $institutionLogoPath . " (Participant ID: " . $participantId . ")");
            }
        }
        ?>
        <?php if (!empty($institutionLogoUrl)): ?>
            <img src="<?= $institutionLogoUrl ?>" alt="<?= htmlspecialchars($institutionName ?? 'Kurum Logosu') ?>" class="institution-logo">
        <?php else: ?>
            <div>&nbsp;</div>
        <?php endif; ?>

        <h1 class="survey-title"><?= $surveyTitle ?></h1>

        <img src="https://psikometrik.net/assets/Psikometrik.png" alt="Psikometrik.Net Logo" class="psikometrik-logo-print">
    </div>


    <h1 class="survey-title"><?= $surveyTitle ?></h1>


    <?php
    // Eğer $error değişkeni null değilse (bir hata oluştuysa) hata mesajını göster.
    if (!empty($error)): ?>
        <div class="error-message"><?= htmlspecialchars($error) // Hata mesajı metin içeriyor, HTML etiketlerinden koru ?></div>
    <?php
    // Eğer hata yoksa VE katılımcı bilgileri başarıyla çekildiyse sonuçları göster.
    elseif ($participant): ?>
        <div class="participant-info">
            <p><strong>Katılımcı:</strong> <?= htmlspecialchars($participant['name']) ?></p>
            <p><strong>Sınıf:</strong> <?= htmlspecialchars($participant['class']) ?></p>
            <p><strong>Anket Tarihi:</strong> <?= htmlspecialchars(date('d.m.Y H:i', strtotime($participant['created_at']))) ?></p> </div>

        <h3>Yanıtlar</h3>

        <?php
        // Eğer işlenmiş cevap detayları dizisi boş değilse (cevaplar çekilebildiyse)
        if (count($answersWithDetails) > 0): ?>
            <?php
            // İşlenmiş her bir cevap detayını döngüye al ve göster.
            foreach ($answersWithDetails as $answerDetail): ?>
                <div class="question-result">
                    <p><strong><?= $answerDetail['number'] ?>. Madde</strong></p>
                    <?php if (!empty($answerDetail['text'])): ?>
                         <p><?= htmlspecialchars($answerDetail['text']) ?></p>
                    <?php endif; ?>

                    <div class="selected-answer">
                        <?= htmlspecialchars($answerDetail['selected_text']) ?>
                    </div>
                    <div class="answer-score">
                         Puan: <?= $answerDetail['score'] ?>
                    </div>
                     <?php
                     // Debug için: Soru ID'sini göstermek isterseniz.
                     // echo "<small> (QID: {$answerDetail['question_id']}) </small>";
                     ?>
                </div>
            <?php endforeach; // foreach $answersWithDetails sonu ?>

            <div class="total-score-section">
                Toplam Puan: <?= $totalScore ?>
            </div>

            <div class="total-score-section" style="margin-top: 1rem; background-color: #ffedd5; border-color: #f97316; color: #c2410c;">
                  Depresyon Düzeyi: <?= htmlspecialchars($interpretation) ?>
             </div>

            <div class="graph-section">
                <h3>Madde Puanları Grafiği</h3>
                <canvas id="itemScoresChart"></canvas>
            </div>

        <?php else: ?>
            <p>Bu katılımcı için anket cevapları bulunamadı.</p>
        <?php endif; // if count($answersWithDetails) > 0 sonu ?>

    <?php else: ?>
         <?php if (!$error): // Eğer zaten bir hata gösterilmediyse genel mesajı göster ?>
              <div class="error-message">Sonuçlar yüklenemedi. Lütfen yönetici ile iletişime geçin.</div>
         <?php endif; ?>
    <?php endif; // if $participant sonu ?>

    <div class="button-container" style="text-align: center; margin-top: 3rem;">
         <button id="printButton" class="btn btn-primary inline-block mr-4">
             <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 inline-block mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6m4-12H9m2-4H9m6 4v8m-6-8v8"/></svg>
             Yazdır
         </button>

         <a href="dashboard.php" class="btn btn-gray inline-block">
             <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 inline-block mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
             Panele Dön
         </a>
         </div>


</div>

<script>
// DOM (HTML yapısı) tamamen yüklendiğinde bu fonksiyon çalıştırılır.
document.addEventListener('DOMContentLoaded', function() {
    // Grafik için gerekli veriyi (madde puanları dizisi) PHP'den al.
    // json_encode ile PHP dizisi JavaScript dizisine çevrilmiştir.
    // itemScores dizisi, madde sırasına göre puanları içerir (0-20 indeksli).
    const itemScores = <?= $scoresJson ?>;
    // Grafik için etiketleri (1'den 21'e) PHP'den al.
    const itemLabels = <?= $graphLabelsJson ?>;


    // Eğer itemScores dizisi boş değilse ve beklenen Beck madde sayısı kadar (21) eleman içeriyorsa grafiği oluştur.
    // itemLabels dizisi de itemScores ile aynı uzunlukta olmalıdır.
    if (itemScores && itemScores.length === 21 && itemLabels && itemLabels.length === 21) {
        // Grafik çizdirilecek canvas elementini ID'si ile al.
        const ctx = document.getElementById('itemScoresChart');

        // Eğer canvas elementi bulunamazsa veya getContext başarısız olursa grafik oluşturma.
        if (ctx) {
             try {
                 const chart = new Chart(ctx, {
                     type: 'bar', // Grafik tipi: Çubuk grafik.
                     data: {
                         labels: itemLabels, // X ekseni etiketleri (Madde 1, Madde 2, ... Madde 21).
                         datasets: [{
                             label: 'Puan', // Veri setinin etiketi (grafik üzerinde görünmeyebilir, ayarlara bağlı).
                             data: itemScores, // Grafik verisi (her madde için alınan puan dizisi).
                             backgroundColor: 'rgba(75, 192, 192, 0.6)', // Çubukların arka plan rengi (yarı şeffaf turkuaz).
                             borderColor: 'rgba(75, 192, 192, 1)', // Çubukların kenarlık rengi (tam turkuaz).
                             borderWidth: 1 // Çubuk kenarlık kalınlığı.
                         }]
                     },
                     options: {
                         responsive: true, // Grafik, bulunduğu konteynerin boyutuna göre otomatik yeniden boyutlansın.
                         maintainAspectRatio: true, // En boy oranını koru.
                          aspectRatio: 2, // Grafik genişliğinin yüksekliğine oranı (örn: 2'ye 1). Bu değeri değiştirerek grafiğin daha geniş veya daha uzun görünmesini sağlayabilirsiniz.
                         scales: {
                             y: { // Y ekseni (Puan Değeri) ayarları.
                                 beginAtZero: true, // Y ekseni 0'dan başlasın.
                                 max: 3, // Y ekseni maksimum değeri 3 olsun (Beck puanları 0-3 arası).
                                 ticks: {
                                     stepSize: 1 // Y ekseni adımları 1 puanlık olsun (0, 1, 2, 3).
                                 },
                                 title: { // Y ekseni başlığı.
                                     display: true, // Başlığı göster.
                                     text: 'Puan' // Başlık metni.
                                 }
                             },
                             x: { // X ekseni (Madde Numarası) ayarları.
                                  title: { // X ekseni başlığı.
                                      display: true, // Başlığı göster.
                                      text: 'Madde Numarası' // Başlık metni.
                                  }
                             }
                         },
                         plugins: {
                             legend: {
                                 display: false // Veri setinin etiketini (label) gösteren lejantı gizle.
                             },
                             title: {
                                 display: true, // Grafik başlığını göster.
                                 text: 'Her Madde İçin Alınan Puanlar' // Grafik başlık metni.
                             }
                         },
                         // Tooltip ayarları (isteğe bağlı, üzerine gelindiğinde bilgi göstermek için)
                          tooltip: {
                              callbacks: {
                                  label: function(context) {
                                      let label = context.dataset.label || '';
                                      if (label) {
                                          label += ': ';
                                      }
                                      // context.raw seçilen puanı (0-3) verir.
                                      if (context.raw !== null && context.raw !== undefined) {
                                           label += context.raw; // Seçilen puanı göster (0-3).
                                      }

                                      // İsteğe bağlı: Ek bilgi (örn. madde metni) göstermek isterseniz,
                                      // bunu itemLabels dizisine metin olarak dahil edip burada kullanabilirsiniz.
                                      return label;
                                  }
                              }
                          }
                     }
                 });
                 console.log("[DEBUG] Grafik başarıyla oluşturuldu.");

             } catch (chartError) {
                 // Chart.js oluşurken bir hata olursa (örn. geçersiz veri, canvas hatası)
                 console.error("[DEBUG] Grafik oluşturma hatası:", chartError);
                 const chartContainer = document.querySelector('.graph-section .chart-container'); // Konteyneri bul
                 if(chartContainer) {
                      // Hata mesajı göster
                      chartContainer.innerHTML = '<p class="text-red-600 text-center font-semibold">Grafik oluşturulamadı (JS Hatası). Konsolu kontrol edin.</p>';
                 }
             }
        } else {
            console.error("[DEBUG] Grafik için canvas elementi (ID: itemScoresChart) bulunamadı.");
            const chartContainer = document.querySelector('.graph-section .chart-container'); // Konteyneri bul
            if(chartContainer) {
                 // Hata mesajı göster
                 chartContainer.innerHTML = '<p class="text-red-600 text-center font-semibold">Grafik için canvas elementi bulunamadı.</p>';
            }
        }

    } else {
         // Grafik verisi yoksa veya beklenen sayıda (21) değilse (genellikle bir hata durumunda) konsola bilgi yazdır.
         // Bu durumda grafik çizdirilmez.
         console.warn("[DEBUG] Grafik oluşturmak için yeterli veya beklenen sayıda (21) veri yok. Veri sayısı:", itemScores ? itemScores.length : 0);
         const chartContainer = document.querySelector('.graph-section .chart-container'); // Konteyneri bul
         if(chartContainer) {
              // Grafik verisi eksik/hatalı mesajı göster
              chartContainer.innerHTML = '<p class="text-center text-gray-500 chart-error-msg">Grafik oluşturulamadı (Veri eksik veya hatalı).</p>';
         }
    }

     // --- Yazdır Butonu Olay Dinleyicisi ---
     // Yazdır butonunu ID'si ile al.
     const printButton = document.getElementById('printButton');
     if(printButton) { // Buton bulunduysa
          // Tıklama olay dinleyicisi ekle.
          printButton.addEventListener('click', function() {
               // Tarayıcının yazdırma penceresini aç.
               window.print();
          });
     }


     // --- Geri Dön Linki Olay Dinleyicisi ---
     // Geri dön linkini (class="btn-gray") sınıfı ile veya başka bir seçici ile al.
     // view-result-12.php'deki gibi dashboard.php'ye yönlendiren linke tıklandığında çalışır.
     // Doğrudan <a> elementini seçiyoruz ve href'i dashboard.php.
     // Eğer history.back() kullanıyorsanız aşağıdaki kodu kullanın.
     /*
     const backLink = document.querySelector('.back-link'); // Geri Dön linkini seçici ile al
     if(backLink) { // Link bulunduysa
          backLink.addEventListener('click', function(e) {
               e.preventDefault(); // Linkin varsayılan tıklama davranışını (URL'e gitme) engelle.
               history.back(); // Tarayıcı geçmişinde bir önceki sayfaya dön.
          });
     }
     */

}); // DOMContentLoaded sonu

// Tailwind CSS 'btn-gray' sınıfı tanımı (eğer tailwind.config.js dosyanızda yoksa)
// Bu stil, Geri Dön/Panele Dön butonu için kullanılır.
/*
.btn-gray {
    background-color: #6b7280; // Gri arka plan
    color: white; // Beyaz metin
}
.btn-gray:hover {
    background-color: #4b5563; // Üzerine gelindiğinde daha koyu gri
}
*/
</style>
</body>
</html>