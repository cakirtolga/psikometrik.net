<?php
session_start();
require_once '../src/config.php';

// Giriş kontrolü
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super-admin'])) {
    header('Location: ../login.php');
    exit();
}

if (!isset($_SESSION['user_email'])) {
    $_SESSION['user_email'] = 'admin@domain.com'; // backup
}

$adminId = $_SESSION['user_id'];
$adminEmail = $_SESSION['user_email'];

// Katılımcıları çek
// NOT: Bu sorgu, survey_participants tablosunda admin_id sütunu olduğunu varsayar.
// PHP mantığına müdahale edilmediği için sorgu değiştirilmemiştir.
$participantsStmt = $pdo->prepare("
    SELECT sp.id, sp.name, sp.class, s.title AS survey_title, s.id AS survey_id
    FROM survey_participants sp
    JOIN surveys s ON sp.survey_id = s.id
    WHERE admin_id = ?
    ORDER BY sp.id DESC
");
$participantsStmt->execute([$adminId]);
$participants = $participantsStmt->fetchAll(PDO::FETCH_ASSOC);

// Anketleri çek
// NOT: Bu sorgu tüm anketleri çeker ve toplam soru sayısı bilgisini içermez.
// Kart tasarımında toplam soru sayısını göstermek için bu bilginin sorguya eklenmesi gerekirdi,
// ancak PHP mantığına müdahale edilmediği için sorgu değiştirilmemiştir.
// Aşağıda HTML kısmında total_questions için placeholder kullanılacaktır.
$surveysStmt = $pdo->query("
    SELECT id, title, description
    FROM surveys
    ORDER BY id DESC
");
$surveys = $surveysStmt->fetchAll(PDO::FETCH_ASSOC);

// Ortalama Uygulama Süresi Notu:
// Mevcut veritabanı şemasında bu bilgi olmadığı için placeholder kullanılıyor.
$averageCompletionTimeNote = "Hesaplanamadı (veri eksik)";

// Toplam soru sayısı için placeholder (sorgudan gelmediği için)
$totalQuestionsPlaceholder = "N/A"; // Not Available

?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | Anket Platformu</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Genel body stili */
        body {
            font-family: sans-serif;
            line-height: 1.6;
            background-color: #f8fafc; /* Çok açık mavi-gri arka plan - Tema ile uyumlu */
            color: #334155; /* Koyu gri metin rengi - Tema ile uyumlu */
        }

        /* Navigasyon çubuğu stilleri */
        nav {
            background-color: #ffffff; /* Beyaz arka plan */
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05); /* Daha yumuşak gölge */
            padding: 1rem 2rem; /* Yatayda biraz daha boşluk */
            display: flex; /* flex */
            justify-content: space-between; /* justify-between */
            align-items: flex-end; /* Öğeleri alta hizala */
        }

        /* Logo alanı stili */
        .logo-area {
            display: flex;
            align-items: center; /* Öğeleri dikeyde ortala */
            gap: 0.75rem; /* Logo ve yazı arasına boşluk */
        }

        /* Logo resmi stili */
        .logo-area img {
            height: 6rem; /* Logo yüksekliği ayarlandı */
            vertical-align: middle;
        }

        /* Site adı linki stili */
        .logo-area a {
            font-size: 1.5rem; /* text-xl */
            font-weight: bold; /* font-bold */
            color: #0e7490; /* Çivit mavisi tonu - Tema ile uyumlu */
            text-decoration: none; /* Alt çizgiyi kaldır */
        }


        /* Buton stilleri (Tailwind sınıfları ile) */
        .btn {
            padding: 0.6rem 1.25rem; /* px-5 py-2.5 gibi */
            border-radius: 0.375rem; /* rounded-md gibi */
            color: white;
            font-weight: 500; /* Orta kalınlık */
            transition: background-color 0.2s ease-in-out;
            display: inline-block; /* Yan yana durması için */
            text-align: center;
            text-decoration: none; /* Linklerin altını çizme */
        }

        .btn-primary {
            background-color: #0ea5e9; /* Tailwind sky-500 (profesyonel mavi) - Tema ile uyumlu */
        }
        .btn-primary:hover {
            background-color: #0284c7; /* Tailwind sky-600 */
        }

        .btn-secondary {
            background-color: #64748b; /* Tailwind slate-500 (orta gri) - Tema ile uyumlu */
        }
        .btn-secondary:hover {
            background-color: #475569; /* Tailwind slate-600 */
        }

        .btn-success {
            background-color: #22c55e; /* Tailwind green-500 - Tema ile uyumlu */
        }
        .btn-success:hover {
            background-color: #16a34a; /* Tailwind green-600 */
        }

        .btn-danger {
             background-color: #ef4444; /* Tailwind red-500 - Tema ile uyumlu */
        }
         .btn-danger:hover {
             background-color: #dc2626; /* Tailwind red-600 */
         }

        /* Tablo stilleri (Katılımcılar için) */
        table {
            width: 100%;
            border-collapse: collapse; /* Kenarlıkları birleştir */
            margin-top: 1.5rem; /* mb-6 gibi */
        }

        th, td {
            text-align: left; /* Metni sola hizala */
            padding: 0.75rem 1rem; /* px-4 py-2 gibi */
            border-bottom: 1px solid #e2e8f0; /* Çok açık gri kenarlık - Tema ile uyumlu */
        }

        th {
            background-color: #f1f5f9; /* Tailwind slate-100 (açık gri) - Tema ile uyumlu */
            font-weight: 600; /* font-semibold */
            color: #475569; /* Tailwind slate-600 - Tema ile uyumlu */
        }

        tbody tr:nth-child(even) {
            background-color: #f8fafc; /* Tailwind slate-50 (çok açık gri) - Zebra deseni - Tema ile uyumlu */
        }

        tbody tr:hover {
            background-color: #e2e8f0; /* Tailwind slate-200 (hafif gri) - Hover efekti - Tema ile uyumlu */
        }

         /* Anket Kartları için grid düzeni */
        .survey-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); /* Responsive grid */
            gap: 1.5rem; /* Tailwind gap-6 */
        }

        /* Anket kartı stili */
        .survey-card {
            background-color: #ffffff; /* Beyaz arka plan */
            padding: 1.5rem; /* p-6 */
            border-radius: 0.5rem; /* rounded */
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08); /* Daha ince gölge */
            transition: box-shadow 0.3s ease-in-out; /* Gölge geçiş efekti */
            border: 1px solid #e2e8f0; /* Çok açık gri kenarlık */
            display: flex; /* Kart içeriğini flex ile düzenle */
            flex-direction: column; /* İçeriği dikey sırala */
            justify-content: space-between; /* İçeriği dikeyde yay */
        }

        .survey-card:hover {
            box-shadow: 0 5px 10px rgba(0, 0, 0, 0.1); /* Hover'da daha belirgin gölge */
        }

        /* Anket başlığı stili */
        .survey-card h2 {
            font-size: 1.25rem; /* text-xl */
            font-weight: 600; /* font-semibold */
            margin-bottom: 0.75rem; /* Biraz daha boşluk */
            color: #1e293b; /* Koyu gri */
        }

        /* Anket açıklaması stili */
        .survey-card p {
            color: #475569; /* Orta gri */
            margin-bottom: 1.5rem; /* Daha fazla boşluk */
            line-height: 1.5; /* Satır yüksekliği */
            flex-grow: 1; /* Açıklama alanının büyümesine izin ver */
        }

         /* Anket detayları stili (Soru Sayısı, Süre) */
        .survey-details {
            font-size: 0.9rem; /* Daha küçük font boyutu */
            color: #64748b; /* Tailwind slate-500 */
            margin-top: 1rem; /* Üstte boşluk */
            margin-bottom: 1.5rem; /* Altta boşluk */
            border-top: 1px solid #e2e8f0; /* Üste ince çizgi */
            padding-top: 1rem; /* Çizginin üstüne boşluk */
        }

        .survey-details p {
            margin-bottom: 0.5rem; /* Detay satırları arasında boşluk */
            color: #64748b; /* Detay metin rengi */
        }


        /* Link Popup Stilleri */
         #popup {
             /* hidden sınıfı JS ile yönetiliyor */
             /* fixed, inset-0, flex, items-center, justify-center, z-50 Tailwind sınıfları */
         }

         /* Popup arka planı için yeni stil */
        .popup-overlay {
            background-color: rgba(0, 0, 0, 0.7); /* Daha koyu ve opak siyah arka plan */
        }


         #popup > div {
             /* bg-white, p-8, rounded, shadow-md, text-center Tailwind sınıfları */
             max-width: 500px; /* Popup maksimum genişliği */
             width: 90%; /* Küçük ekranlarda genişlik */
         }

         #shareLink {
             /* w-full, p-2, border, rounded, mb-4 Tailwind sınıfları */
             background-color: #f1f5f9; /* Arka planı biraz farklı yap - Tema ile uyumlu */
             cursor: text; /* Metin kutusu imleci */
             color: #334155; /* Metin rengi - Tema ile uyumlu */
         }

         #popup button {
             /* Genel buton stilleri (.btn) ile uyumlu */
             /* px-4, py-2, rounded, hover:bg-green-600 Tailwind sınıfları */
         }

         #popup button.bg-green-500 {
             background-color: #22c55e; /* Tailwind green-500 - Tema ile uyumlu */
         }
         #popup button.bg-green-500:hover {
             background-color: #16a34a; /* Tailwind green-600 */
         }


        /* Diğer Tailwind sınıfları */
        .mx-auto { margin-left: auto; margin-right: auto; }
        .container { width: 100%; max-width: 1000px; } /* Konteyner genişliği artırıldı */
        .p-4 { padding: 1rem; }
        .mb-6 { margin-bottom: 1.5rem; }
        .text-2xl { font-size: 1.5rem; }
        .font-bold { font-weight: 700; }
        .mt-4 { margin-top: 1rem; }
        .mr-2 { margin-right: 0.5rem; }
        .ml-2 { margin-left: 0.5rem; }
        .mb-8 { margin-bottom: 2rem; }
        .text-3xl { font-size: 1.875rem; } /* Tailwind text-3xl */
        .items-center { align-items: center; }
        .mb-12 { margin-bottom: 3rem; } /* Tailwind mb-12 */
        .min-w-full { min-width: 100%; } /* Tailwind min-w-full */
        .shadow-md { box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06); } /* Tailwind shadow-md */
        .rounded { border-radius: 0.25rem; } /* Tailwind rounded */
        .border-b { border-bottom-width: 1px; } /* Tailwind border-b */
        .text-center { text-align: center; } /* Tailwind text-center */
        .underline { text-decoration: underline; } /* Tailwind underline */
        .fixed { position: fixed; } /* Tailwind fixed */
        .inset-0 { top: 0; right: 0; bottom: 0; left: 0; } /* Tailwind inset-0 */
        /* .bg-black { background-color: #000; } */ /* Bu sınıf yerine popup-overlay kullanılacak */
        /* .bg-opacity-50 { opacity: 0.5; } */ /* Bu sınıf yerine popup-overlay kullanılacak */
        .z-50 { z-index: 50; } /* Tailwind z-50 */
        .hidden { display: none; } /* Tailwind hidden */
        .w-full { width: 100%; } /* Tailwind w-full */
        .mb-2 { margin-bottom: 0.5rem; } /* Tailwind mb-2 */
        .text-xl { font-size: 1.25rem; } /* Tailwind text-xl */
         .text-gray-800 { color: #1f2937; } /* Tailwind text-gray-800 */
         .overflow-x-auto { overflow-x: auto; } /* Yatay kaydırma için */


    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <nav class="bg-white shadow p-4 flex justify-between items-end">
        <div class="logo-area">
            <a href="../index.php"><img src="/assets/Psikometrik.png" alt="Psikometrik.Net Logo" ></a>
        </div>

        <div>
            <?php if (isset($_SESSION['user_id'])): ?>
                <span class="mr-4 text-gray-700">👤 <?php echo htmlspecialchars($_SESSION['username']); ?></span>
                <a href="../create-survey.php" class="btn btn-success mr-2">Yeni Anket</a>
                <a href="../logout.php" class="btn btn-danger">Çıkış</a>
            <?php endif; ?>
        </div>
    </nav>

    <main class="container mx-auto p-4 mt-8"> <h1 class="text-2xl font-bold mb-6 text-gray-800">Admin Dashboard</h1>

        <?php if (isset($error)): ?>
            <div class="bg-red-100 text-red-700 p-4 rounded-lg mb-6"><?= $error ?></div>
        <?php endif; ?>

        <section class="mb-12">
            <h2 class="text-2xl font-semibold mb-4 text-gray-800">📋 Öğrenci Anket Katılımları</h2>

            <?php if (count($participants) > 0): ?>
              <div class="overflow-x-auto">
                <table class="min-w-full bg-white shadow-md rounded">
                    <thead>
                        <tr>
                            <th class="py-2 px-4 border-b text-left">Ad Soyad</th>
                            <th class="py-2 px-4 border-b text-left">Sınıf</th>
                            <th class="py-2 px-4 border-b text-left">Anket</th>
                            <th class="py-2 px-4 border-b text-center">İşlem</th> </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($participants as $participant): ?>
                            <tr>
                                <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($participant['name']); ?></td>
                                <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($participant['class']); ?></td>
                                <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($participant['survey_title']); ?></td>
                                <td class="py-2 px-4 border-b text-center"> <a href="../admin/view-result-<?php echo $participant['survey_id']; ?>.php?id=<?php echo $participant['id']; ?>" class="btn btn-primary inline-block">
                                        Sonuçları Gör
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
              </div>
            <?php else: ?>
                <p class="text-gray-600">Henüz katılım yok.</p>
            <?php endif; ?>
        </section>

        <section>
            <h2 class="text-2xl font-semibold mb-4 text-gray-800">📝 Anketleri Uygula</h2>

            <?php if (count($surveys) > 0): ?>
                <div class="survey-grid">
                    <?php foreach ($surveys as $survey): ?>
                        <div class="survey-card" data-survey-id="<?php echo $survey['id']; ?>">
                            <h2 class="text-xl font-semibold mb-2"><?php echo htmlspecialchars($survey['title']); ?></h2>
                            <p class="text-gray-600 mb-4"><?php echo nl2br(htmlspecialchars($survey['description'])); ?></p>

                            <div class="survey-details">
                                <p><strong>Toplam Soru:</strong> <?php echo $totalQuestionsPlaceholder; ?></p>
                                <p><strong>Ortalama Uygulama Süresi:</strong> <?php echo $averageCompletionTimeNote; ?></p>
                            </div>

                            <div class="flex justify-end mt-auto"> <button type="button"
                                        class="btn btn-success"
                                        onclick="showLink(<?php echo $survey['id']; ?>)">
                                    Linki Göster
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div id="popup" class="hidden fixed inset-0 flex items-center justify-center popup-overlay z-50">
                    <div class="bg-white p-8 rounded shadow-md text-center max-w-sm w-full">
                        <h3 class="text-xl font-bold mb-4 text-gray-800">Paylaşım Linki</h3>
                        <input type="text" id="shareLink" readonly class="w-full p-2 border rounded mb-4">
                        <button onclick="copyLink()" class="bg-green-500 text-white px-4 py-2 rounded hover:bg-green-600 w-full mb-2">Kopyala</button>
                        <button onclick="closePopup()" class="text-red-500 underline">Kapat</button>
                    </div>
                </div>

                <script>
                    // Anket linkini kopyalama işlevi
                    function showLink(surveyId) {
                        const popup = document.getElementById('popup');
                        const linkInput = document.getElementById('shareLink');
                        // Anket linki take-survey-X.php şeklinde ana dizinde olduğu varsayılır
                        // Link formatı: https://psikometrik.net/take-survey-SURVEY_ID.php?admin_id=ADMIN_ID
                        const takeSurveyPage = `take-survey-${surveyId}.php`;
                        // Admin ID'yi PHP değişkeninden al
                        const adminId = <?php echo json_encode($adminId); ?>;
                        // Linki sadece admin_id ile oluştur
                        const surveyLink = `${window.location.origin}/${takeSurveyPage}?admin_id=${encodeURIComponent(adminId)}`;
                        linkInput.value = surveyLink;
                        popup.classList.remove('hidden');
                    }

                    function copyLink() {
                        const copyText = document.getElementById('shareLink');
                        copyText.select();
                        copyText.setSelectionRange(0, 99999); /* For mobile devices */
                        try {
                            navigator.clipboard.writeText(copyText.value);
                            alert('Link panoya kopyalandı!');
                        } catch (err) {
                            console.error('Link kopyalanamadı: ', err);
                            alert('Link kopyalanamadı. Lütfen manuel olarak kopyalayın: ' + copyText.value);
                        }
                    }

                    function closePopup() {
                        const popup = document.getElementById('popup');
                        popup.classList.add('hidden');
                    }
                </script>

            <?php else: ?>
                <p class="text-gray-600">Henüz sistemde anket bulunamadı.</p>
            <?php endif; ?>
        </section>

    </main>

</body>
</html>
