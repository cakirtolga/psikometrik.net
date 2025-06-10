<?php
session_start();
?>

<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <title>Burdon Dikkat Testi</title>
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <style>
    .cell { width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid #ccc; cursor: pointer; }
    .selected { background-color: #4ade80; }
    .disabled { pointer-events: none; opacity: 0.6; }
  </style>
</head>
<body class="bg-gray-100 p-8">

<div class="max-w-4xl mx-auto bg-white p-6 rounded shadow">
  <h1 class="text-3xl font-bold mb-6 text-center">Burdon Dikkat Testi</h1>

  <form id="burdonTestForm" method="POST" action="testi-bitir.php">

    <div class="mb-4">
      <label class="block mb-1 font-semibold">Ad Soyad:</label>
      <input type="text" name="ad_soyad" class="w-full border p-2 rounded" required>
    </div>

    <div class="mb-4">
      <label class="block mb-1 font-semibold">Doğum Yılı:</label>
      <select id="dogumYiliSecimi" name="dogum_yili" class="w-full border p-2 rounded" required>
        <option value="">Seçiniz</option>
        <?php
        $buYil = date('Y');
        for ($yil = $buYil; $yil >= 1980; $yil--) {
            echo "<option value='$yil'>$yil</option>";
        }
        ?>
      </select>
    </div>

    <div id="sayac" class="text-xl font-bold text-red-600 mb-4 text-center"></div>

    <div id="grid" class="grid grid-cols-50 gap-1 justify-center">
      <!-- Grid burada oluşacak -->
    </div>

    <input type="hidden" name="selected_cells" id="selectedCellsInput">

    <button type="submit" id="bitirButonu" class="bg-blue-500 text-white px-6 py-2 rounded hover:bg-blue-600 mt-6 block mx-auto" disabled>Testi Bitir</button>

  </form>
</div>

<script>
// Harfler
const harfler = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'K', 'L', 'M', 'N', 'O', 'P', 'R', 'S', 'T', 'U', 'V', 'Y'];
const hedefHarf = 'A'; // Hedef harf
let testSuresi = 0;
let kalanSaniye = 0;
let sayac;
let secilenKutular = [];

function dogumYilindanYasHesapla(dogumYili) {
    const bugun = new Date();
    return bugun.getFullYear() - dogumYili;
}

function sureyi_baslat(yas) {
    if (yas >= 34) return 310;
    else if (yas >= 27 && yas <= 33) return 250;
    else if (yas >= 19 && yas <= 26) return 290;
    else if (yas >= 16 && yas <= 18) return 300;
    else if (yas >= 11 && yas <= 15) return 500;
    else if (yas >= 5 && yas <= 10) return 900;
    else {
        alert("Yaş bu testi uygulamak için çok küçük.");
        return null;
    }
}

function baslatSayac(saniye) {
    kalanSaniye = saniye;
    document.getElementById('bitirButonu').disabled = false;

    sayac = setInterval(function() {
        let dakika = Math.floor(kalanSaniye / 60);
        let saniyeKalan = kalanSaniye % 60;
        document.getElementById('sayac').innerText = `Kalan Süre: ${dakika}:${saniyeKalan.toString().padStart(2, '0')}`;

        if (kalanSaniye <= 0) {
            clearInterval(sayac);
            alert("Süre doldu. Test gönderiliyor!");
            document.getElementById('burdonTestForm').submit();
        }
        kalanSaniye--;
    }, 1000);
}

function gridOlustur() {
    const grid = document.getElementById('grid');
    grid.innerHTML = '';

    for (let i = 0; i < 500; i++) {
        const harf = harfler[Math.floor(Math.random() * harfler.length)];
        const cell = document.createElement('div');
        cell.className = 'cell';
        cell.innerText = harf;
        cell.dataset.index = i;
        cell.dataset.harf = harf;

        cell.addEventListener('click', function() {
            if (cell.classList.contains('selected')) {
                cell.classList.remove('selected');
                secilenKutular = secilenKutular.filter(x => x !== i);
            } else {
                cell.classList.add('selected');
                secilenKutular.push(i);
            }
            document.getElementById('selectedCellsInput').value = JSON.stringify(secilenKutular);
        });

        grid.appendChild(cell);
    }
}

document.getElementById('dogumYiliSecimi').addEventListener('change', function() {
    const dogumYili = parseInt(this.value);
    if (dogumYili) {
        const yas = dogumYilindanYasHesapla(dogumYili);
        const sure = sureyi_baslat(yas);
        if (sure) {
            gridOlustur();
            baslatSayac(sure);
            this.disabled = true; // Doğum yılı seçimi kilitlensin
        }
    }
});
</script>

</body>
</html>
