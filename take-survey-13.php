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
// Bu dosyanın $pdo adında bir PDO veritabanı bağlantı nesnesi oluşturup döndürmesi gerekmektedir.
// '__DIR__' sihirli sabiti, mevcut dosyanın bulunduğu dizini verir. Dosya yolunu kendi projenize göre ayarlayın.
require_once __DIR__ . '/src/config.php';

// Bu scriptin hangi anket (Beck Depresyon Ölçeği) için çalıştığını belirten ID.
$surveyId = 13;

// Kullanıcıya gösterilecek hata mesajları için değişken. Başlangıçta null veya boş.
$error = null;

// URL'den 'admin_id' parametresini alır ve integer'a dönüştürür. Yoksa null olur.
$adminId = isset($_GET['admin_id']) ? (int)$_GET['admin_id'] : null;

// Veritabanından çekilecek anket başlığı gibi bilgileri saklayacak değişken.
$survey = null;

// Veritabanından çekilecek anket sorularını saklayacak dizi.
$questions = [];

// Anketin toplam soru sayısı. Veritabanından çekildikten sonra belirlenir.
$totalQuestions = 0;

// --- Admin Kontrolü ---
// Güvenlik: Bu sayfaya sadece geçerli bir admin ID ile URL üzerinden erişildiğinden emin olun.
// Admin ID URL'de gelmeli ve veritabanında var olmalı.
if (is_null($adminId) || $adminId <= 0) {
    // Geçersiz admin ID durumunda kullanıcıya mesaj gösterilir ve script durdurulur.
    // Gerçek bir uygulamada burası daha şık bir hata sayfasına yönlendirilebilir.
    die('Geçersiz erişim: Yönetici kimliği belirtilmemiş veya geçersiz.');
}

try {
    // Belirtilen admin ID'sinin 'users' tablosunda gerçekten var olup olmadığını veritabanından kontrol et.
    $checkAdmin = $pdo->prepare("SELECT id FROM users WHERE id = ?");
    // Parametreyi güvenli bir şekilde execute metoduna dizi olarak geçirerek SQL enjeksiyonunu önleriz.
    $checkAdmin->execute([$adminId]);
    // Eğer fetch() metodu sonuç döndürmezse, admin bulunamamıştır.
    if (!$checkAdmin->fetch()) {
        // Admin bulunamazsa hata mesajı gösterilir ve script durdurulur.
        die('Erişim engellendi: Belirtilen yönetici kimliği veritabanında bulunamadı.');
    }
    // Eğer script buraya kadar gelirse, admin kimliği geçerlidir ve işleme devam edilebilir.

} catch(PDOException $e) {
    // Veritabanı bağlantısı kurulurken veya admin kontrol sorgusu çalışırken bir PDO hatası oluşursa.
    // Hata detayını logla ve kullanıcıya genel bir hata mesajı göster.
    error_log("Veritabanı hatası (Admin kontrolü): " . $e->getMessage());
    // Kullanıcıya daha genel bir hata mesajı gösterilir.
    die('Veritabanı sistemi hatası oluştu. Lütfen daha sonra tekrar deneyin veya yönetici ile iletişime geçin.');
}
// --- Bitiş Admin Kontrolü ---


// --- Anket Bilgilerini (Başlık, Sorular) Veritabanından Çek ---
// Formu göstermeden önce anketin mevcut olduğundan ve sorularının bulunduğundan emin olmalıyız.
try {
    // 1. 'surveys' tablosundan anket başlığını çek.
    $stmt = $pdo->prepare("SELECT title FROM surveys WHERE id = ?");
    $stmt->execute([$surveyId]); // Anket ID'sini parametre olarak bağla.
    $survey = $stmt->fetch(PDO::FETCH_ASSOC); // Tek bir satır sonuç beklenir.

    // Eğer anket veritabanında bulunamazsa özel bir Exception fırlat.
    if (!$survey) {
        throw new Exception('Anket veritabanında bulunamadı.');
    }

    // 2. 'survey_questions' tablosundan bu ankete ait soruları çek.
    // ID, soru metni (question) ve sıralama numarasını (sort_order) çek.
    // **ÖNEMLİ:** Soruları 'sort_order' sütununa göre artan sırada sırala.
    // Bu sıralama, soruların formda hangi sırada görüneceğini belirler ve biz bu sırayı Beck maddelerinin 1'den 21'e kadar olan sırası olarak KABUL EDECEĞİZ.
    // question sütununun soru metnini içerdiğinden emin olun.
    $stmt = $pdo->prepare("SELECT id, question, sort_order FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC");
    $stmt->execute([$surveyId]); // Anket ID'sini parametre olarak bağla.
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC); // Sorgunun tüm sonuçlarını bir dizi olarak al.
    $totalQuestions = count($questions); // Çekilen soru sayısını belirle.

    // Eğer anketin hiç sorusu yoksa özel bir Exception fırlat.
    if ($totalQuestions === 0) {
        throw new Exception('Bu anket için tanımlanmış soru bulunamadı.');
    }

    // Beck Depresyon Envanteri'nin standart olarak 21 sorusu vardır.
    // Çekilen soru sayısı 21 değilse bu bir yapılandırma hatası olabilir.
    // Kullanıcıya hata göstermeyebiliriz ama sunucu loglarına uyarı yazabiliriz.
    if ($totalQuestions !== 21) {
        error_log("Warning: Beck Depression (Survey ID: $surveyId) expected 21 questions, but found $totalQuestions.");
        // İsterseniz kullanıcıya da bir uyarı gösterebilirsiniz ama zorunlu değil.
    }

} catch (Exception $e) {
    // try bloğu içinde fırlatılan kendi Exception'larımız veya diğer genel hatalar burada yakalanır.
    $error = "Anket verileri yüklenirken bir hata oluştu: " . $e->getMessage();
    error_log("Data Fetch Error for Survey ID $surveyId: " . $e->getMessage()); // Hata detayını logla.
    $questions = []; // Hata oluştuğu için soruları boşalt ki form gösterilmesin.
    $totalQuestions = 0; // Toplam soru sayısını da sıfırla.
} catch (PDOException $e) {
    // Veri çekme sırasında veritabanı ile ilgili (PDO) hatalar burada yakalanır.
    $error = "Veritabanı hatası nedeniyle anket bilgileri yüklenemedi. Lütfen yönetici ile iletişime geçin."; // Kullanıcıya gösterilecek genel mesaj.
    error_log("PDO Data Fetch Error for Survey ID $surveyId: " . $e->getMessage()); // Hata detayını logla.
    $questions = []; // Hata oluştuğu için soruları boşalt.
    $totalQuestions = 0; // Toplam soru sayısını da sıfırla.
}
// --- Bitiş Anket Bilgilerini Çek ---

// --- Beck Depresyon Envanteri Seçenekleri ve Puanları (Sabit Tanımlama) ---
// Bu dizi, her Beck maddesi için 0, 1, 2 ve 3 puanlarına karşılık gelen metin ifadelerini içerir.
// Dizi anahtarları, Beck maddelerinin 1'den başlayan numaralarına karşılık gelir (1'den 21'e).
// Bu diziyi, veritabanından çekilen soruların sırasına (döngü indeksine) göre kullanacağız.
// Örneğin, çekilen ilk soru (index 0) için beck_options[1]'e, çekilen ikinci soru (index 1) için beck_options[2]'ye erişeceğiz.
$beck_options = [
    1 => ["Kendimi üzüntülü ve sıkıntılı hissetmiyorum.", "Kendimi üzüntülü ve sıkıntılı hissediyorum.", "Hep üzüntülü ve sıkıntılıyım. Bundan kurtulamıyorum.", "O kadar üzüntülü ve sıkıntılıyım ki artık dayanamıyorum."],
    2 => ["Gelecek hakkında mutsuz ve karamsar değilim.", "Gelecek hakkında karamsarım.", "Gelecekten beklediğim hiçbir şey yok.", "Geleceğim hakkında umutsuzum ve sanki hiçbir şey düzelmeyecekmiş gibi geliyor."],
    3 => ["Kendimi başarısız bir insan olarak görmüyorum.", "Çevremdeki birçok kişiden daha çok başarısızlıklarım olmuş gibi hissediyorum.", "Geçmişe baktığımda başarısızlıklarla dolu olduğunu görüyorum.", "Kendimi tümüyle başarısız biri olarak görüyorum."],
    4 => ["Birçok şeyden eskisi kadar zevk alıyorum.", "Eskiden olduğu gibi her şeyden hoşlanmıyorum.", "Artık hiçbir şey bana tam anlamıyla zevk vermiyor.", "Her şeyden sıkılıyorum."],
    5 => ["Kendimi herhangi bir şekilde suçlu hissetmiyorum.", "Kendimi zaman zaman suçlu hissediyorum.", "Çoğu zaman kendimi suçlu hissediyorum.", "Kendimi her zaman suçlu hissediyorum."],
    6 => ["Bana cezalandırılmışım gibi gelmiyor.", "Cezalandırılabileceğimi hissediyorum.", "Cezalandırılmayı bekliyorum.", "Cezalandırıldığımı hissediyorum."],
    7 => ["Kendimden memnunum.", "Kendi kendimden pek memnun değilim.", "Kendime çok kızıyorum.", "Kendimden nefret ediyorum."],
    8 => ["Başkalarından daha kötü olduğumu sanmıyorum.", "Zayıf yanlarım veya hatalarım için kendi kendimi eleştiririm.", "Hatalarımdan dolayı ve her zaman kendimi kabahatli bulurum.", "Her aksilik karşısında kendimi hatalı bulurum."],
    9 => ["Kendimi öldürmek gibi düşüncelerim yok.", "Zaman zaman kendimi öldürmeyi düşündüğüm olur. Fakat yapmıyorum.", "Kendimi öldürmek isterdim.", "Fırsatını bulsam kendimi öldürürdüm."],
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

// beck_options dizisinin tam ve doğru yapılandırıldığından emin olmak için temel bir kontrol.
// 21 madde ve her maddede 4 seçenek beklenir.
if (count($beck_options) !== 21) {
     error_log("Configuration Error: beck_options array does not contain exactly 21 items for Survey ID $surveyId.");
     // Eğer madde sayısı 21 değilse bu bir sistem/yapılandırma hatasıdır. Formu göstermemek için $questions'ı boşaltırız.
     $error = ($error ? $error . '<br>' : '') . "Anket seçenekleri sistemde eksik sayıda tanımlanmış. Lütfen yöneticiye bildirin.";
     $questions = []; // Soruları temizle ki form gösterilmesin.
     $totalQuestions = 0; // Toplam soru sayısını da sıfırla.

} else {
     // Eğer madde sayısı 21 ise, her maddede 4 seçenek olduğundan emin ol.
     foreach($beck_options as $maddeNum => $options){
         if(count($options) !== 4){
              error_log("Configuration Error: beck_options item {$maddeNum} does not contain exactly 4 options for Survey ID $surveyId.");
              // Hata mesajına hangi maddede sorun olduğunu ekle.
               $error = ($error ? $error . '<br>' : '') . "Anket seçenekleri sistemde yanlış tanımlanmış (Madde {$maddeNum}). Lütfen yöneticiye bildirin.";
               $questions = []; // Soruları temizle.
               $totalQuestions = 0; // Toplam soru sayısını da sıfırla.
               break; // Hata bulununca döngüyü kır.
         }
     }
}

// --- Bitiş Beck Seçenekleri ---


// --- POST İsteğini Yönet (Form Gönderildiğinde Çalışır) ---
// Bu blok, form submit edildiğinde HTTP POST metoduyla gelen isteği işler.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Güvenlik: Sayfanın geçerli bir admin ID ile açıldığından emin ol (form action URL'de taşınıyor).
    // Bu kontrol, sayfa yüklenirkenki kontrolde yakalanmalı ama ek güvenlik için tekrar kontrol edilir.
    if (is_null($adminId) || $adminId <= 0) {
        $error = "Geçersiz oturum veya yönetici kimliği. Lütfen sayfayı doğru bağlantı üzerinden açın.";
    } else {
        // Formdan POST edilen verileri güvenli bir şekilde al. Güvenlik için trim() ve ?? '' kullanılır.
        $name = trim($_POST['student_name'] ?? ''); // Ad Soyad alanı. trim() baştaki ve sondaki boşlukları kaldırır. ?? '' eğer $_POST['student_name'] yoksa boş string atar.
        $class = trim($_POST['student_class'] ?? ''); // Sınıf alanı.
        // Anket cevapları, JavaScript tarafından doldurulan hidden inputlardan dizi olarak gelir.
        // Dizi anahtarları soru ID'si (question_id), değerler seçilen puan (0-3).
        // Örnek POST verisi: $_POST['answers'] = [ 'qid1' => 'score1', 'qid2' => 'score2', ... ]
        $answers = $_POST['answers'] ?? [];

        // --- Sunucu Tarafı Doğrulama (Server-side Validation) ---
        // Hem client-side (JavaScript) hem de server-side doğrulama yapmak önemlidir,
        // çünkü client-side doğrulama atlanabilir veya manipüle edilebilir.

        $isValid = true; // Genel doğrulama durumu bayrağı. Başlangıçta başarılı varsayılır.
        $validationErrors = []; // Doğrulama sırasında bulunan hataları tutacak dizi.
        $processedAnswersToSave = []; // Doğrulamadan geçen ve veritabanına kaydedilecek cevapları (SEÇENEK METİN halleriyle) tutacak dizi.

        // 1. Ad Soyad ve Sınıf alanlarının boş olup olmadığını kontrol et.
        if (empty($name)) {
            $validationErrors[] = 'Lütfen Ad Soyad bilgilerinizi doldurun.';
        }
         if (empty($class)) {
            $validationErrors[] = 'Lütfen Sınıf bilgilerinizi doldurun.';
        }

        // 2. Tüm soruların yanıtlanıp yanıtlanmadığını ve gelen yanıtların geçerli puanlar olup olmadığını kontrol et.
        $answeredCount = 0; // Başarıyla ve geçerli bir şekilde yanıtlanan soru sayısı sayacı.

        // Veritabanından çekilen (ve formda gösterilmesi BEKLENEN) her soruyu DÖNGÜ SIRASINA GÖRE işleme al.
        // Bu döngü, formun eksiksiz doldurulduğundan emin olmak için kullanılır.
        foreach ($questions as $index => $q) { // $index: Döngüdeki 0-tabanlı sıra (0, 1, ..., totalQuestions-1)
             $qid = $q['id']; // Sorunun veritabanındaki benzersiz ID'si.
             // $q['sort_order'] veritabanındaki sort_order değeri (şu an bunu Beck madde numarası olarak kullanmıyoruz, sadece sıralama için çektik).
             $maddeNumber = $index + 1; // Kullanıcının gördüğü ve beck_options anahtarı olarak kullanacağımız 1-tabanlı madde numarası.

             // $_POST['answers'] dizisinde bu soru ID'sine karşılık gelen bir değer var mı VE değeri boş değil mi kontrol et.
             // JavaScript hidden inputun değerini ayarlamazsa key olmayabilir (isset hatası) veya değeri boş kalabilir.
             // Eğer cevap yoksa veya boşsa, bu soru yanıtlanmamıştır.
             if (!isset($answers[$qid]) || $answers[$qid] === '') {
                 // Yanıtlanmamış soru bulunduğunda doğrulama hatası mesajı ekle.
                 $validationErrors[] = "Soru " . htmlspecialchars($maddeNumber) . " yanıtlanmadı.";
                 // Bu soruyu daha fazla işlemeye gerek yok, bir sonraki soruya geç ('continue' kullanarak).
                 continue;
             }

             // Eğer buraya geldiysek, bu soru için bir cevap değeri (puan) POST edilmiş demektir.
             // Gelen değerin (puanın) geçerli olup olmadığını kontrol et (sayısal mı ve 0, 1, 2, veya 3 aralığında mı?).
             $ans_score = $answers[$qid]; // Gelen değer: puan (0, 1, 2, 3) string veya integer olabilir.

              if (is_numeric($ans_score) && in_array((int)$ans_score, [0, 1, 2, 3])) {
                  // Cevap geçerli bir puan değeri ise (sayısal ve 0-3 aralığında):
                  $answeredCount++; // Geçerli yanıtlanan soru sayısı bir artır.
                  $score_index = (int)$ans_score; // Puanı integer indekse çevir, bu $beck_options içindeki metin dizisi için index olacaktır.

                  // --- Seçeneğin Tam Metnini Bul ---
                  // beck_options dizisinden ilgili madde numarası (DÖNGÜ İNDEKSİNE GÖRE BELİRLENEN $maddeNumber)
                  // ve puan indeksini ($score_index) kullanarak seçeneğin TAM METNİNİ al.
                  // $beck_options anahtarı Beck madde numarasıdır ($maddeNumber: 1-21). İkincil index puandır ($score_index: 0-3).
                  $selectedAnswerText = "Metin Bulunamadı"; // Varsayılan hata metni, metin bulunamazsa kullanılır.

                  // beck_options dizisinde beklenen madde numarası ve puana karşılık gelen metin var mı kontrol et.
                  // Eğer beck_options doğru yapılandırılmışsa bu kontrol her zaman true olmalıdır.
                  if (isset($beck_options[$maddeNumber][$score_index])) {
                      $selectedAnswerText = $beck_options[$maddeNumber][$score_index];
                  } else {
                       // beck_options dizisinde beklenen metin bulunamazsa (bu genellikle bir sistem/yapılandırma hatasıdır).
                       // Hata detayını sunucu loglarına kaydet.
                       error_log("Configuration Error: Beck option text not found in \$beck_options for Madde No: {$maddeNumber}, Score: {$score_index} for QID: {$qid} (Survey ID: $surveyId).");
                       // Bu durumda kullanıcıya da genel bir hata mesajı gösterebilir veya sadece loglayabiliriz.
                       // $validationErrors[] = "Soru " . htmlspecialchars($maddeNumber) . " için seçenek metni bulunamadı (Sistem Hatası).";
                  }

                  // Veritabanına kaydedilecek cevaplar dizisine bu sorunun ID'si ($qid) ve bulduğumuz seçeneğin METNİNİ ($selectedAnswerText) ekle.
                  // Bu dizi, tüm doğrulamalar geçtikten sonra kaydetme işlemi için kullanılacak.
                  // Dizi yapısı: [ 'qid1' => 'Metin 1', 'qid2' => 'Metin 2', ... ]
                  $processedAnswersToSave[$qid] = $selectedAnswerText;

              } else {
                  // Gelen cevap değeri geçerli bir puan (sayısal ve 0-3 aralığında) değilse, doğrulama hatası ekle.
                   $validationErrors[] = "Soru " . htmlspecialchars($maddeNumber) . " için geçersiz cevap değeri alındı.";
                   // Bu durumda bu soru için işleme devam etme, bir sonraki soruya geç.
                   continue;
              }
        } // foreach ($questions as $index => $q) döngüsü sonu.
        // Bu döngü, tüm BEKLENEN soruların yanıtlanıp yanıtlanmadığını ve yanıtların geçerliliğini kontrol etti.


        // --- Genel Doğrulama Sonucunu Değerlendir ve Hata Mesajını Oluştur ---
        // Genel doğrulamanın başarılı sayılması için:
        // 1. $validationErrors dizisi boş olmalı (Ad/Sınıf ve bireysel soru yanıtları geçerli).
        // 2. Geçerli bir şekilde yanıtlanan soru sayısı ($answeredCount) toplam soru sayısına ($totalQuestions) eşit olmalı.
        if (empty($validationErrors) && $answeredCount === $totalQuestions) {
             $isValid = true; // Genel doğrulama BAŞARILI. Artık veritabanı kaydına geçilebilir.
        } else {
             $isValid = false; // Genel doğrulama BAŞARISIZ.
             // Hata mesajları zaten $validationErrors dizisinde toplandı.
             // $error değişkenine tüm doğrulama hata mesajlarını birleştirerek ata.
             // implode('<br>', ...) kullanarak her hata mesajı arasına <br> koyarız, bu da onları HTML'de alt alta gösterir.
             $error = implode('<br>', $validationErrors);
             // Doğrulama başarısız olduğu için veritabanı kaydı yapılmayacak.
             // Form, hata mesajı ve kullanıcının girdiği önceki verilerle yeniden gösterilecektir (aşağıdaki HTML/JS sayesinde).
        }


        // --- Doğrulama Başarılıysa Veritabanı İşlemini Başlat ---
        // Eğer $isValid bayrağı true ise (yani tüm doğrulamalar geçtiyse) bu blok çalışır.
        if ($isValid) {
            try {
                // Veritabanı işlemini başlat. Bu, aşağıdaki INSERT sorgularının ya hep birlikte başarılı olmasını ya da
                // herhangi bir hata durumunda tüm yapılan değişikliklerin geri alınmasını (rollback) sağlar.
                // Bu, veritabanında eksik veya tutarsız veri kalmasını önler.
                $pdo->beginTransaction();

                // 1. 'survey_participants' tablosuna yeni katılımcı kaydını ekle.
                // Sütun adlarının (name, class, survey_id, admin_id, created_at) veritabanı şemanızla tam eşleştiğinden emin olun.
                // 'created_at' sütununa veritabanının güncel zamanını (NOW()) kaydet.
                $stmt_participant = $pdo->prepare("INSERT INTO survey_participants (name, class, survey_id, admin_id, created_at) VALUES (?, ?, ?, ?, NOW())");
                // Güvenli bir şekilde parametreleri (name, class, surveyId, adminId) sorguya bağla ve çalıştır.
                // execute() metodu başarılı olursa true, başarısız olursa false döner.
                if (!$stmt_participant->execute([$name, $class, $surveyId, $adminId])) {
                    // Eğer katılımcı ekleme sorgusu başarısız olursa, özel bir Exception fırlat.
                    // Bu, aşağıdaki catch bloğunda yakalanacak ve işlemi geri alacaktır.
                    throw new Exception("Katılımcı bilgileri veritabanına kaydedilirken hata oluştu.");
                }

                // Başarıyla eklenen yeni katılımcının veritabanında otomatik olarak oluşturulan birincil anahtar (ID) değerini al.
                // Bu ID, 'survey_answers' tablosundaki 'participant_id' sütunu için Foreign Key olarak kullanılacak.
                $participantId = $pdo->lastInsertId();
                if(!$participantId) {
                    // lastInsertId() bir değer döndürmezse (beklenmedik durum, ekleme muhtemelen başarısız oldu) hata fırlat.
                    throw new Exception("Yeni katılımcı ID'si alınamadı. Katılımcı kaydı tamamlanamadı.");
                }

                // 2. 'survey_answers' tablosuna kullanıcının verdiği cevapları kaydet.
                // Her bir yanıtlanan soru için bu tabloya bir satır eklenecek.
                // 'answer_text' sütununa seçeneğin TAM METNİNİ kaydediyoruz (daha önce $processedAnswersToSave'e attığımız değerler).
                // 'survey_answers' tablonuzda 'participant_id' (INT), 'question_id' (INT), 'answer_text' (VARCHAR veya TEXT) sütunlarının olduğundan emin olun.
                // 'answer_text' sütununun, Beck seçeneklerinin en uzun metnini saklayabilecek yeterli uzunlukta olduğundan emin olun (örneğin VARCHAR(500) veya TEXT tipi).
                $stmt_answer = $pdo->prepare("INSERT INTO survey_answers (participant_id, question_id, answer_text) VALUES (?, ?, ?)");

                // Doğrulama aşamasında başarıyla işlediğimiz ve METİN hallerine dönüştürdüğümüz cevapları içeren $processedAnswersToSave dizisini döngüye al.
                // Bu döngü, her bir cevap metnini veritabanına eklemek için kullanılır.
                foreach ($processedAnswersToSave as $qid => $answerText) {
                     // $qid = Sorunun veritabanı ID'si (integer).
                     // $answerText = Seçeneğin tam metni (string).

                     // Sorguyu güvenli bir şekilde çalıştır. participantId, questionId (int'e çevrildi) ve answerText (string) değerlerini bağla.
                    if (!$stmt_answer->execute([$participantId, (int)$qid, $answerText])) {
                         // Eğer bir cevabın eklenmesi sırasında execute() başarısız olursa, hata fırlat.
                         // Bu, catch bloğunda yakalanacak ve işlemi geri alacaktır.
                         throw new Exception("Bir cevabın kaydedilmesi sırasında veritabanı hatası oluştu (Soru ID: $qid).");
                    }
                }

                // Eğer hem katılımcı ekleme hem de tüm cevapları ekleme sorguları başarıyla çalıştıysa, veritabanı işlemini kalıcı hale getir (commit).
                // Bu noktadan sonra değişiklikler veritabanına yazılır.
                $pdo->commit();

                // Kayıt başarıyla tamamlandıysa, kullanıcıyı bir "Tamamlandı" sayfasına yönlendir.
                // 'tamamlandi.php' adında bir dosyanızın olması ve bu URL'nin doğru olması gerekir.
                // İsteğe bağlı olarak, tamamlandı sayfasına katılımcı ID'sini de gönderebilirsiniz,
                // böylece o sayfada katılımcıya özel bir mesaj veya sonuç gösterebilirsiniz.
                header('Location: tamamlandi.php?participant_id=' . $participantId);
                // header() fonksiyonundan sonra scriptin daha fazla çalışmasını önlemek iyi pratiktir.
                exit;

            } catch (Exception $e) {
                // try bloğu içinde herhangi bir Exception (kendi fırlattığımız veya PHP/diğer hatalar) yakalanırsa bu blok çalışır.
                // Eğer bir veritabanı işlemi başlamışsa (beginTransaction çağrılmışsa), yapılan değişiklikleri geri al (rollback).
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                // Hata mesajını $error değişkenine kaydet. Bu, sayfanın üstünde kullanıcıya gösterilir.
                // Üretim ortamında $e->getMessage() gibi detaylı teknik bilgileri kullanıcıya göstermek yerine daha genel bir mesaj kullanın.
                $error = "Anketiniz kaydedilirken beklenmedik bir hata oluştu. Lütfen tekrar deneyin. Hata Detayı: " . $e->getMessage();
                // Hata detayını sunucu loglarına kaydet. Bu, sizin sorunu teşhis etmeniz için önemlidir.
                error_log("Survey ID $surveyId submission error for admin $adminId: " . $e->getMessage());

            } catch (PDOException $e) {
                 // Veritabanı ile ilgili özel hatalar (PDOException) burada yakalanır. Örneğin SQL syntax hatası, bağlantı hatası, kısıtlama hatası vb.
                 // Eğer bir veritabanı işlemi başlamışsa, yapılan değişiklikleri geri al (rollback).
                 if ($pdo->inTransaction()) {
                     $pdo->rollBack();
                 }
                 // Hata mesajını kaydet. Kullanıcıya veritabanı hatası olduğunu belirten bir mesaj göster.
                 // Üretim ortamında $e->getMessage() detayını göstermekten kaçının.
                 $error = "Veritabanı sisteminde bir hata oluştu. Lütfen tekrar deneyin veya yönetici ile iletişime geçin. Hata Detayı: " . $e->getMessage();
                 // Hata detayını sunucu loglarına kaydet.
                 error_log("Survey ID $surveyId PDO submission error for admin $adminId: " . $e->getMessage());
             }
        }
         // Eğer genel doğrulama başarısız olursa ($isValid false ise) veya yukarıdaki catch bloklarından biri çalışırsa,
         // $error değişkeni set edilmiş olur. Script HTML kısmına geçer ve $error mesajını sayfanın üstünde gösterir.
         // Form, POST edildiğinde gönderilen Ad Soyad/Sınıf değerleri ve yapılan seçeneklerle yeniden doldurulur (aşağıdaki HTML/JS sayesinde).

    } // else adminId geçerli (POST içinde)
} // if method POST
// --- Bitiş: POST İsteğini Yönet ---


// --- HTML Formunun Gösterildiği Kısım ---
// Bu kısım, sayfa ilk yüklendiğinde (HTTP GET isteği) veya form POST edildikten sonra (doğrulama hatası veya veritabanı hatası nedeniyle) çalışır.
// Eğer $totalQuestions 0 ise (veritabanından sorular çekilemediyse veya beck_options hatası varsa), form gösterilmez, sadece hata mesajı gösterilir.
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $survey ? htmlspecialchars($survey['title']) : 'Anket' ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* --- Genel Sayfa ve Konteyner Stili (Görseldeki gibi, kanvas daraltıldı) --- */
        body {
            font-family: sans-serif; /* Sans-serif font ailesi kullan */
            line-height: 1.6; /* Metin satır yüksekliği */
            background-color: #f0fdf4; /* Açık yeşil arka plan rengi (pastel ton) */
            color: #2c3e50; /* Varsayılan metin rengi (koyu gri) */
            margin: 0;
            padding: 20px; /* Sayfa kenarlarına iç boşluk */
            display: flex; /* Flexbox kullanarak içerik düzeni */
            justify-content: center; /* Ana içeriği yatayda (ana eksen) ortala */
            align-items: flex-start; /* İçeriği dikeyde (çapraz eksen) üstten başlat (form uzun olabileceğinden) */
            min-height: 100vh; /* Sayfanın minimum yüksekliği, görünüm alanının %100'ü kadar */
            box-sizing: border-box; /* Padding ve kenarlığın elementin toplam boyutuna dahil edilmesini sağla */
        }
        .container {
            max-width: 550px; /* Anket formunun maksimum genişliği (Kanvas daraltıldı) */
            width: 100%; /* Ekran daraldığında tam genişlik kullan */
            margin: 30px auto; /* Üstte ve altta 30px boşluk, solda ve sağda otomatik boşluk (yatayda ortalar) */
            background: white; /* Konteynerin arka plan rengi beyaz */
            padding: 30px; /* Konteynerin iç kenarlarına boşluk */
            border-radius: 10px; /* Konteynerin köşe yuvarlaklığı */
            box-shadow: 0 2px 15px rgba(0,0,0,0.1); /* Konteyner için hafif bir alt ve kenar gölgesi */
            box-sizing: border-box; /* Padding'in toplam genişliğe dahil olmasını sağla */
        }

        /* --- Başlık Stili (Anket Başlığı) --- */
        h2.survey-title {
            color: #166534; /* Koyu tema yeşili başlık metin rengi */
            text-align: center; /* Başlığı yatayda ortala */
            margin-bottom: 1.5rem; /* Başlığın altına boşluk (1.5 * 16px = 24px) */
            font-size: 1.8rem; /* Başlık font boyutu */
            border-bottom: 2px solid #dcfce7; /* Başlığın altına açık yeşil bir çizgi */
            padding-bottom: 0.75rem; /* Başlık metni ile altındaki çizgi arasındaki boşluk */
        }

        /* --- Ad Soyad ve Sınıf Input Alanları Stili --- */
        label {
             display: block; /* Label'ı bloğa çevir, kendi satırını kaplar */
             margin-bottom: 5px; /* Label ile altındaki input arasına boşluk */
             font-weight: 600; /* Kalın font */
             color: #1f2937; /* Koyu gri metin rengi */
        }
        input[type="text"] {
            padding: 10px; /* Input içindeki metnin kenarlardan boşluğu */
            width: 100%; /* Konteyner içinde tam genişlik */
            box-sizing: border-box; /* Padding ve kenarlığın inputun belirlenen width'ine dahil edilmesini sağla */
            border: 1px solid #d1d5db; /* Input kenarlığı (açık gri) */
            border-radius: 6px; /* Input köşelerinin yuvarlaklığı */
            font-size: 1em; /* Font boyutu (varsayılan) */
            color: #2c3e50; /* Input içine yazılan metnin rengi */
            height: 40px; /* Sabit yükseklik */
            background-color: white; /* Input arka plan rengi beyaz */
            /* Input odaklandığında veya kenarlığı/gölgesi değiştiğinde yumuşak geçiş efekti */
            transition: border-color 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
        }
        /* Input alanı odaklandığında (tıklandığında veya Tab ile gelindiğinde) */
        input[type="text"]:focus {
            border-color: #15803d; /* Kenarlık rengini tema rengine çevir (koyu yeşil) */
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.2); /* Hafif bir odak gölgesi ekle */
            outline: none; /* Tarayıcının varsayılan mavi veya siyah odak çerçevesini kaldır */
        }


        /* --- Soru (Madde) ve Seçenek Grupları Stili --- */
        .question {
            margin-bottom: 1.75rem; /* Her anket maddesi arasına alt boşluk */
            padding-bottom: 1.75rem; /* Maddenin içeriği (seçenekler) ile altındaki ayırıcı çizgi arasına boşluk */
            border-bottom: 1px dashed #d1d5db; /* Maddeler arasına kesikli gri bir çizgi */
        }
        .question:last-child {
            border-bottom: none; /* Son anket maddesinin altında ayırıcı çizgi olmasın */
            margin-bottom: 0; /* Son maddenin altında ekstra boşluk olmasın */
            padding-bottom: 0; /* Son maddenin altında ekstra boşluk olmasın */
        }
        /* Soru numarası (kalın metin) stili */
        .question p strong {
             margin-right: 8px; /* Soru numarası ile metin arasına boşluk */
             color: #1e3a8a; /* Soru numarası metin rengi (koyu mavi) */
             font-weight: 700; /* Ekstra kalın font */
        }
        /* Soru metni stili */
        .question p {
            font-weight: 600; /* Yarı kalın font */
            margin-bottom: 0.75rem; /* Soru metni ile seçenekler arasına boşluk */
            color: #333; /* Soru metni rengi */
        }

        /* --- Seçenek Butonları (div.option-button) Grubu Stili --- */
        .options-group {
            display: flex; /* Flexbox kullanarak iç elemanları düzenle */
            flex-direction: column; /* Flex elemanlarını (seçenek butonlarını) dikey olarak, alt alta sırala */
            gap: 8px; /* Alt alta sıralanan flex elemanları arasına (dikey) boşluk */
            margin-top: 10px; /* Üst boşluk */
        }

        /* Her bir seçenek butonu (div elementi) için temel stil */
        .option-button {
            display: flex; /* İçindeki metni (span) düzenlemek için flexbox kullan */
            align-items: flex-start; /* İçerik (metin) dikeyde en üstten başlasın (uzun metinlerde faydalı) */
            cursor: pointer; /* Fare imlecini üzerine gelindiğinde pointer (el) yap */
            padding: 12px 15px; /* Butonun iç kenarlarına boşluk */
            border: 1px solid #a7d9d7; /* Buton kenarlığı (açık yeşil/mavi) - Görseldeki butona benzer */
            border-radius: 8px; /* Buton köşelerinin yuvarlaklığı */
            /* Stil değişiklikleri (arka plan, kenarlık, gölge) için yumuşak geçiş efekti */
            transition: background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
            background-color: #e0f7fa; /* Butonun arka plan rengi (açık turkuaz/mavi) - Görseldeki butona benzer */
            color: #1f2937; /* Buton metin rengi (koyu gri) */
            font-size: 0.95em; /* Buton metni font boyutu */
            user-select: none; /* Metnin fare ile seçilmesini engelle (bir butona tıklıyormuş hissi verir) */
            line-height: 1.4; /* Metin satır aralığı */
        }
         /* Seçenek butonunun üzerine gelindiğinde (hover) stil */
         .option-button:hover {
            background-color: #ccf1f5; /* Üzerine gelindiğinde biraz daha koyu arka plan */
            border-color: #7bc0bf; /* Üzerine gelindiğinde biraz daha koyu kenarlık */
         }

         /* --- Seçili Seçenek Butonu Stili --- */
         /* JavaScript tarafından bir seçenek tıklandığında '.option-button' elementine eklenen 'selected' sınıfına sahip buton */
         .option-button.selected {
            background-color: #b2e0e0; /* Seçili olduğunda daha belirgin arka plan (orta turkuaz/mavi) */
            border-color: #14532d; /* Seçili olduğunda koyu tema rengi kenarlık */
            color: #14532d; /* Seçili olduğunda koyu tema rengi metin */
            box-shadow: 0 2px 6px rgba(20, 83, 45, 0.2); /* Seçili olduğunda belirgin bir gölge ekle */
            font-weight: 600; /* Seçili metni kalın yap */
         }
         /* Seçili butondaki metin (span) stili */
         .option-button.selected span {
             color: #14532d; /* Seçili metin rengini koru (parenttan alabilir ama burada tekrar belirtildi) */
         }


        /* --- Diğer Element Stilleri --- */

        /* Hata mesajı stili (PHP tarafından $error değişkeni doluysa gösterilen div) */
        .error-message {
             color: #b91c1c; /* Koyu kırmızı metin rengi */
             background-color: #fee2e2; /* Açık kırmızı arka plan rengi */
             padding: 1rem; /* İç boşluk */
             border-radius: 0.5rem; /* Köşe yuvarlaklığı */
             margin-bottom: 1.5rem; /* Alt boşluk */
             border: 1px solid #fca5a5; /* Kırmızı kenarlık */
             font-weight: bold; /* Kalın metin */
             text-align: center; /* Metni ortala */
        }

        /* Gönder butonu stili */
        button[type="submit"] {
            display: block; /* Kendi satırına al, blok element yap */
            width: 100%; /* Konteynerin tamamını kapla */
            margin-top: 2rem; /* Üst boşluk */
            padding: 12px 30px; /* İç boşluk (dikey/yatay) */
            border-radius: 8px; /* Köşe yuvarlaklığı */
            font-weight: 600; /* Yarı kalın font */
            transition: all 0.2s ease-in-out; /* Tüm stil değişikliklerine yumuşak geçiş */
            cursor: pointer; /* İmleci pointer yap */
            border: none; /* Kenarlık yok */
            background: #2563eb; /* Arka plan rengi mavi */
            color: white; /* Metin rengi beyaz */
            font-size: 1.1em; /* Font boyutu biraz büyük */
        }
        /* Gönder butonunun üzerine gelindiğinde stil */
        button[type="submit"]:hover {
            background: #1d4ed8; /* Üzerine gelindiğinde biraz daha koyu mavi */
        }
        /* Gönder butonu devre dışı bırakıldığında stil (disabled attribute olduğunda) */
        button[type="submit"]:disabled {
            background-color: #9ca3af; /* Devre dışı bırakıldığında gri arka plan */
            cursor: not-allowed; /* İmleci yasak işareti yap */
            opacity: 0.7; /* Biraz şeffaf yap */
        }

        /* Yönerge kutusu stili */
        .instructions {
            background-color: #e0f2fe; /* Açık mavi arka plan */
            border-left: 4px solid #3b82f6; /* Solda mavi kalın çizgi */
            padding: 15px; /* İç boşluk */
            margin-bottom: 25px; /* Alt boşluk */
            border-radius: 4px; /* Köşe yuvarlaklığı */
            font-size: 0.95rem; /* Font boyutu */
            color: #1e3a8a; /* Koyu mavi metin rengi */
        }
        .instructions h4 {
            font-weight: 700; /* Başlık için ekstra kalın font */
            margin-bottom: 0.5rem; /* Başlık alt boşluk */
            color: #1e40af; /* Başlık için daha koyu mavi */
            font-size: 1rem; /* Başlık font boyutu */
        }
    </style>
</head>
<body class="bg-[#f0fdf4] min-h-screen p-4"> <div class="container">
    <h2 class="survey-title"><?= $survey ? htmlspecialchars($survey['title']) : 'Anket' ?></h2>

    <?php
    // PHP tarafından atanan hata mesajını burada göster. Eğer $error null değilse gösterilir.
    // $error değişkeni HTML <br> etiketleri içerebilir, bu yüzden htmlspecialchars tekrar uygulanmaz.
    if ($error): ?>
        <div class="error-message"><?= $error ?></div>
    <?php endif; ?>

    <?php
    // Eğer anket bilgileri ve sorular başarıyla yüklendiyse (ve Beck seçeneklerinde temel hata yoksa) formu göster.
    // totalQuestions'ın 0'dan büyük olması, soruların yüklendiğini ve temel beck_options kontrolünün geçtiğini gösterir.
    if ($survey && $totalQuestions > 0): ?>
    <form method="POST" id="surveyForm" action="take-survey-13.php?admin_id=<?= htmlspecialchars($adminId) ?>" novalidate>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
            <div>
                <label for="studentName">Ad Soyad:</label>
                <input type="text" name="student_name" id="studentName" required value="<?= htmlspecialchars($_POST['student_name'] ?? '') ?>">
            </div>
            <div>
                <label for="studentClass">Sınıf:</label>
                 <input type="text" name="student_class" id="studentClass" required value="<?= htmlspecialchars($_POST['student_class'] ?? '') ?>">
            </div>
        </div>

        <div class="instructions mb-6">
            <h4 class="font-semibold mb-1">Yönerge:</h4>
            Bu ankette gruplanmış ifadeler bulunmaktadır. Lütfen her gruptaki ifadeleri dikkatlice okuyunuz. Sonra, her gruptan **BUGÜN DAHİL GEÇEN HAFTA içinde** kendinizi nasıl hissettiğinizi en iyi tanımlayan bir ifadeyi seçiniz. Seçtiğiniz ifadenin yanındaki seçeneği işaretleyiniz. Eğer gruptaki ifadelerden birkaçı sizin hissettiklerinize eşit derecede uyuyorsa, en yüksek numaralı olan ifadeyi seçiniz. Seçiminizi yapmadan önce bütün ifadeleri dikkatlice okuduğunuzdan emin olunuz.
         </div>

        <?php
        // $questions dizisi, veritabanından çekilen tüm soruları içerir, sort_order'a göre sıralıdır.
        // Bu döngü 0'dan $totalQuestions - 1'e kadar çalışır.
        foreach ($questions as $index => $q): ?>
            <?php
            $qid = $q['id']; // Sorunun veritabanındaki benzersiz ID'si.
            // **ÖNEMLİ DEĞİŞİKLİK:** Kullanıcıya gösterilecek madde numarası ve beck_options'a erişim için DÖNGÜ İNDEKSİNİ kullanıyoruz.
            // Veritabanındaki sort_order değeri sadece soruların çekilme sırasını belirler, doğrudan Beck madde numarasına denk gelmek ZORUNDA DEĞİLDİR bu versiyonda.
            $maddeNumber = $index + 1; // Kullanıcıya gösterilecek ve beck_options anahtarı olarak kullanacağımız 1'den başlayan madde numarası (0-tabanlı index + 1).

            // Hata durumunda formu yeniden gösterirken, bu soru için kullanıcının daha önce seçtiği puan değerini (hidden inputtan gelen) $_POST'tan al.
            $previousAnswerScore = $_POST['answers'][$qid] ?? null;

            ?>
            <div class="question" data-question-id="<?= $qid ?>" data-sort-order="<?= htmlspecialchars($q['sort_order']) ?>">
                <p class="font-semibold mb-3 text-gray-800">
                    <strong><?= $maddeNumber ?>. Madde</strong>
                    <?php
                    // Eğer survey_questions tablosundan soru metni ('question' sütunu) çekiliyorsa onu göster.
                    // SELECT sorgusunda 'question' sütunu çekiliyor.
                     if (!empty($q['question'])) { ?>
                         - <?= htmlspecialchars($q['question']) ?>
                    <?php } ?>
                </p>

                <div class="options-group">
                    <?php
                    // Bu sorunun Beck seçeneklerini `$beck_options` dizisinden madde numarasına ($maddeNumber = $index + 1) göre al.
                    $choices = $beck_options[$maddeNumber] ?? []; // `$beck_options[1]` -> 1. madde seçenekleri, `$beck_options[2]` -> 2. madde seçenekleri, vb.

                    // Beck ölçeğinde her zaman 4 seçenek (0, 1, 2, 3 puanları için) olmalıdır.
                    // Bu kontrol yukarıda yapıldı ve eğer başarısızsa form zaten gösterilmez.
                    if (count($choices) == 4):
                        // Bu maddenin dört seçeneğini döngüye al. $scoreValue 0, 1, 2, 3 olacak.
                        foreach ($choices as $scoreValue => $choiceText):
                            // Eğer form daha önce POST edilmiş ve bu seçenek seçilmişse 'selected' CSS classı ekle.
                            // $previousAnswerScore (hidden inputtan gelen puan) ile $scoreValue (bu seçeneğin puanı) eşit mi?
                            $isSelected = ($previousAnswerScore !== null && (int)$previousAnswerScore === (int)$scoreValue) ? 'selected' : '';
                    ?>
                        <div class="option-button <?= $isSelected ?>"
                             data-question-id="<?= $qid ?>"
                             data-sort-order="<?= htmlspecialchars($q['sort_order']) ?>"
                             data-value="<?= $scoreValue ?>">
                             <span><?= htmlspecialchars($choiceText) ?></span> </div>
                    <?php
                        endforeach; // foreach $choices sonu
                    else:
                        // Bu durum normalde yukarıdaki PHP kontrolü tarafından yakalanır ve form gösterilmez.
                        // Eğer Beck seçenekleri eksik veya yanlışsa kullanıcıya bir mesaj gösterilebilir.
                        // error_log("Beck options missing or incorrect count for question sort_order {$q['sort_order']}"); // Log yukarıda yapıldı.
                    endif;
                    ?>
                </div>
                <input type="hidden" name="answers[<?= $qid ?>]" id="answer_<?= $qid ?>" value="<?= htmlspecialchars($previousAnswerScore ?? '') ?>">
                 </div>
        <?php endforeach; // foreach $questions (sorular döngüsü) sonu ?>

        <?php if ($totalQuestions > 0): ?>
             <button type="submit" id="submitBtn">Gönder</button>
        <?php endif; ?>

    </form>
     <?php
     // Eğer anket bilgileri veya sorular yüklenemedi (veya beck_options hatası oluştuysa) ve $error değişkeni doluysa, bu mesaj gösterilir.
     // Bu blok, if ($survey && $totalQuestions > 0) bloğu çalışmadığında çalışır.
     elseif ($error): // $error değişkeni null değilse, hata mesajını göster
         // Eğer $error null değilse, form gösterilmemiştir ve bu hata mesajı gösterilir.
         // $totalQuestions 0 olduğunda buraya düşülür.
         // Zaten yukarıdaki PHP kodunda eğer veri çekme veya beck_options hatası varsa $error set edilir ve $totalQuestions 0 yapılır.
         // Bu kontrol aslında yeterlidir: if ($error) { ... }
         // Ancak önceki yapıya sadık kalmak için elseif ($error) { ... } kullanıldı.
     ?>
         <div class="error-message"><?= $error ?></div>
     <?php
     // Eğer anket ve sorular yüklenemedi ancak $error da set edilmediyse (beklenmedik bir durum), genel bir mesaj göster.
     // Normalde yukarıdaki catch blokları tüm hataları $error'a atamalıdır.
     elseif ($survey === null || $totalQuestions === 0): ?>
         <div class="error-message">Anket bilgileri veya sorular yüklenemedi. Lütfen sistem yöneticinize başvurun.</div>
     <?php endif; ?>

</div>

<script>
// DOM (HTML yapısı) tamamen yüklendiğinde bu fonksiyon çalıştırılır.
document.addEventListener('DOMContentLoaded', function() {
    // Form elementini ID'si ile al.
    const form = document.getElementById('surveyForm');
    // Gönder butonunu ID'si ile al.
    const submitBtn = document.getElementById('submitBtn');

    // PHP tarafından belirlenen toplam soru sayısını al.
    // Bu değer, JS doğrulaması için kullanılır. PHP'de bir hata varsa 0 olabilir.
    const totalQuestions = <?= $totalQuestions ?>;

    // Sayfadaki tüm seçenek butonlarını (div.option-button) seç.
    const optionButtons = document.querySelectorAll('.option-button');

    // --- Sayfa Yüklendiğinde Önceki Seçimleri Görsel Olarak İşaretle ---
    // Bu döngü, sayfa ilk yüklendiğinde (GET isteği) veya form hata alıp yeniden gösterildiğinde (POST sonrası) çalışır.
    // Hidden inputlardaki mevcut değerlere göre ilgili seçenek butonlarına görsel olarak 'selected' sınıfını ekler.
    optionButtons.forEach(button => {
        const questionId = button.getAttribute('data-question-id'); // Butonun ait olduğu soru ID'si.
        const value = button.getAttribute('data-value'); // Bu butonun temsil ettiği puan değeri (0-3) string olarak.
        const hiddenInput = document.getElementById('answer_' + questionId); // İlgili gizli input elementi.

        // Eğer ilgili gizli input varsa, değeri boş değilse VE inputun değeri bu butonun data-value'suna eşitse...
        // Bu, hata sonrası form tekrar gösterildiğinde kullanıcının önceki seçimlerinin işaretli gelmesini sağlar.
        if (hiddenInput && hiddenInput.value !== '' && hiddenInput.value === value) {
             button.classList.add('selected'); // ...bu butona 'selected' sınıfını ekle.
        }
    });


    // --- Seçenek Butonlarına Tıklama Olay Dinleyicisi Ekle ---
    // Sayfadaki her bir '.option-button' elementine tıklandığında çalışacak fonksiyonu tanımla ve bağla.
    optionButtons.forEach(button => {
        button.addEventListener('click', function() {
            // Tıklanan butonun data özelliklerinden soru ID'sini al.
            const clickedQuestionId = this.getAttribute('data-question-id');
            // Tıklanan butonun data özelliklerinden seçilen puan değerini (0, 1, 2, 3) al.
            const clickedValue = this.getAttribute('data-value');

            // Tıklanan butonun ait olduğu sorunun ilgili gizli input elementini ID'si ile bul.
            const targetHiddenInput = document.getElementById('answer_' + clickedQuestionId);

            // Eğer gizli input elementi başarıyla bulunduysa işlemi yap.
            if (targetHiddenInput) {
                // Tıklanan butonun en yakın üst atası olan '.question' divini bul.
                // Bu div, aynı soru grubundaki diğer seçenekleri bulmak için kullanılır.
                const questionDiv = this.closest('.question');
                if (questionDiv) {
                    // Aynı soru grubundaki (bu '.question' divi içindeki) TÜM '.option-button' elementlerini bul.
                    // Her birinden varsa 'selected' sınıfını kaldır. Bu, sadece son tıklananın seçili kalmasını sağlar.
                    questionDiv.querySelectorAll('.option-button').forEach(btn => {
                        btn.classList.remove('selected');
                    });
                }

                // Tıklanan butona 'selected' sınıfını ekle. Bu, CSS stilini tetikler ve butonu görsel olarak seçili yapar.
                this.classList.add('selected');

                // İlgili gizli inputun değerini, tıklanan butonun data-value'sından alınan puan değerine ayarla.
                // Bu değer, form POST edildiğinde PHP tarafından alınacak ve metne çevrilecek.
                targetHiddenInput.value = clickedValue;

                // İsteğe bağlı: Debug için konsola bilgi yazdır.
                console.log(`Soru ID: ${clickedQuestionId}, Seçilen Puan: ${clickedValue}. Gizli input (ID: answer_${clickedQuestionId}) güncellendi.`);
            } else {
                // Eğer gizli input elementi bulunamazsa (beklenmedik bir HTML veya JavaScript hatası), konsola hata yazdır.
                console.error(`JavaScript Hatası: Soru ID ${clickedQuestionId} için gizli input elementi (ID: answer_${clickedQuestionId}) bulunamadı.`);
            }
        }); // button.addEventListener('click') sonu
    }); // optionButtons.forEach sonu


    // --- Form Gönderiminde Client-side (Tarayıcı Tarafı) Doğrulama ---
    // Form elementini ve Gönder butonunu DOM'da bulunduysa bu olay dinleyicisini ekle.
    // PHP'den $totalQuestions 0 gelirse (veri çekme veya config hatası) form ve buton olmayacağı için bu kod çalışmaz.
    if (form && submitBtn) {
        form.addEventListener('submit', function(event) {
            // Form gönderilmeden hemen önce çalışır. Client-side doğrulamayı yapar.

            // Ad Soyad ve Sınıf input elementlerini al.
            const nameInput = document.getElementById('studentName');
            const classInput = document.getElementById('studentClass');
            let clientSideValidationErrors = []; // Client tarafı doğrulama hataları mesajları için boş bir dizi oluştur.

            // 1. Ad Soyad alanının dolu olup olmadığını kontrol et. trim() ile baştaki/sondaki boşluklar göz ardı edilir.
            if (!nameInput || nameInput.value.trim() === '') {
                clientSideValidationErrors.push('Lütfen Ad Soyad bilgilerinizi doldurun.');
            }
            // 2. Sınıf alanının dolu olup olmadığını kontrol et.
             if (!classInput || classInput.value.trim() === '') {
                 clientSideValidationErrors.push('Lütfen Sınıf bilgilerinizi doldurun.');
             }

            // 3. Tüm anket sorularının yanıtlanıp yanıtlanmadığını kontrol et.
            // Her '.question' div'ine (yani her soruya) karşılık gelen gizli inputun dolu olup olmadığını kontrol et.
            const questionDivs = form.querySelectorAll('.question'); // Sayfadaki tüm soru divlerini al.
            let firstUnansweredQDiv = null; // İlk yanıtsız soru divini saklamak için değişken (varsayılan null).

            // Her soru divini döngüye al.
            questionDivs.forEach(qDiv => {
                 const qid = qDiv.getAttribute('data-question-id'); // Soru divinin data özelliğinden soru ID'sini al.
                 const hiddenInput = document.getElementById('answer_' + qid); // İlgili gizli inputu ID'si ile bul.

                 // Eğer gizli input bulunamazsa VEYA değeri boşsa VE henüz ilk yanıtsız soruyu bulmadıysak...
                 if ((!hiddenInput || hiddenInput.value === '') && !firstUnansweredQDiv) {
                     firstUnansweredQDiv = qDiv; // ...bu divi ilk yanıtsız soru divi olarak işaretle.
                 }
                 // Not: Gizli inputun değerinin 0,1,2,3 aralığında geçerli bir puan olup olmadığı server-side (PHP) doğrulamada kontrol edilir.
                 // Client-side'da sadece bir değer girilip girilmediği genellikle yeterlidir.
            });

            // Eğer yanıtsız soru bulunduysa (firstUnansweredQDiv null değilse) hata mesajı ekle.
            if(firstUnansweredQDiv){
                clientSideValidationErrors.push("Lütfen tüm soruları yanıtlayın.");
            }


            // --- Client-side Doğrulama Sonucunu Değerlendir ---
            // Eğer clientSideValidationErrors dizisi boş değilse (yani en az bir hata varsa).
            if (clientSideValidationErrors.length > 0) {
                // Doğrulama hataları mesajlarını kullanıcıya bir alert kutusu içinde göster.
                // Aynı hata mesajlarının (örn. "Lütfen tüm soruları yanıtlayın") tekrarlanmasını önlemek için Set kullanılır.
                let uniqueErrors = [...new Set(clientSideValidationErrors)];
                alert('Formu göndermeden önce lütfen şu hataları düzeltin:\n\n' + uniqueErrors.join('\n')); // Hataları satır sonlarıyla birleştirip alert olarak göster.

                event.preventDefault(); // Formun server'a gönderilmesini ENGELLE.

                // İlk hata nerede ise oraya odaklan ve kullanıcıyı yönlendir.
                if (!nameInput || nameInput.value.trim() === '') {
                   nameInput.focus(); // Ad Soyad boşsa oraya odaklan.
                } else if (!classInput || classInput.value.trim() === '') {
                   classInput.focus(); // Sınıf boşsa oraya odaklan.
                } else if (firstUnansweredQDiv) {
                   // Ad/Sınıf dolu ama sorular eksikse, ilk yanıtsız soru divine kaydır.
                   firstUnansweredQDiv.scrollIntoView({ behavior: 'smooth', block: 'center' }); // Sayfayı yumuşak bir şekilde kaydır.
                   // Kaydırılan maddeyi geçici olarak görsel olarak vurgula (arka plan rengini değiştir).
                   firstUnansweredQDiv.style.backgroundColor = '#fee2e2'; // Geçici arka plan rengi (açık kırmızımsı).
                   // Kısa bir gecikme (1.5 saniye) sonra vurgu rengini kaldır.
                   setTimeout(() => { firstUnansweredQDiv.style.backgroundColor = ''; }, 1500);
                }

            } else {
                // Eğer client-side doğrulama başarılıysa:
                // Gönder butonunu devre dışı bırak ve metnini değiştir. Bu, kullanıcının hızla iki kez tıklayarak çift gönderim yapmasını engeller.
                submitBtn.disabled = true;
                submitBtn.textContent = "Gönderiliyor...";
                // Form gönderilmesine izin verilir. Tarayıcı formu POST metoduyla 'action' URL'ine gönderecektir.
                // Server-side PHP kodu çalışmaya başlayacaktır.
            }
        }); // form.addEventListener('submit') sonu
    } else {
        // Eğer 'surveyForm' ID'li form veya 'submitBtn' ID'li buton DOMContentLoaded anında bulunamazsa konsola hata yazdır.
        // Bu genellikle HTML kodunda bir hata olduğunu gösterir (ID'ler yanlış yazılmış veya elementler eksik).
        console.error("HTML hatası: 'surveyForm' ID'li form elementi veya 'submitBtn' ID'li buton elementi DOM'da bulunamadı.");
    }
}); // DOMContentLoaded sonu

</script>
</body>
</html>