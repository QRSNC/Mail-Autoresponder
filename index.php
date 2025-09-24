<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI E-posta Yanıtlayıcı</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .container { max-width: 960px; }
        .log-separator { font-weight: bold; background-color: #e9ecef; }
        
        /* RENKLENDİRME GÜNCELLEMESİ */
        .list-group-item { border: 0; border-left: 5px solid; }
        .list-group-item-primary { border-color: #0d6efd; }
        .list-group-item-success { border-color: #198754; color: #146c43; }
        .list-group-item-warning { border-color: #ffc107; color: #997404; }
        .list-group-item-danger { border-color: #dc3545; color: #b02a37; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container py-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3">AI E-posta Yanıtlayıcı</h1>
            </div>
            <div>
                <button id="restartButton" class="btn btn-primary">Şimdi Tara</button>
                <p class="text-muted mb-0 small text-center" id="countdown">Bir sonraki tarama için hazırlanılıyor...</p>
            </div>
        </div>
        <div class="card">
            <div class="card-header">
                İşlem Kayıtları (Log)
            </div>
            <ul class="list-group list-group-flush" id="log-list">
                </ul>
        </div>
        <footer class="text-center text-muted mt-4 small">
            AI E-posta Yanıtlayıcı - <?php echo date('Y'); ?>
        </footer>
    </div>

    <script>
        const logList = document.getElementById('log-list');
        const countdownDisplay = document.getElementById('countdown');
        const restartButton = document.getElementById('restartButton');

        const scanIntervalSeconds = 1200; // 20 dakika
        let countdownTimer;
        let timeRemaining = scanIntervalSeconds;
        let scanCounter = 0;

        // Taramayı başlatan ana fonksiyon
        async function startScan() {
            scanCounter++;
            restartButton.disabled = true; // Tarama sırasında butonu devre dışı bırak
            
            // Tarama ayracı ekle
            const separator = document.createElement('li');
            separator.className = 'list-group-item text-center log-separator';
            separator.textContent = `--- TARAMA #${scanCounter} BAŞLATILDI ---`;
            logList.appendChild(separator);

            try {
                // Arka planda run_scan.php'yi çağır ve logları al
                const response = await fetch('run_scan.php');
                const newLogs = await response.text();
                logList.insertAdjacentHTML('beforeend', newLogs);
            } catch (error) {
                const errorLi = document.createElement('li');
                errorLi.className = 'list-group-item list-group-item-danger small';
                errorLi.textContent = `[${new Date().toLocaleTimeString()}] KRİTİK HATA: Tarama betiği çalıştırılamadı. Hata: ${error}`;
                logList.appendChild(errorLi);
            }

            // En alta otomatik kaydır
            logList.scrollTop = logList.scrollHeight;

            restartButton.disabled = false; // Tarama bitince butonu aktif et
            resetCountdown(); // Yeni geri sayımı başlat
        }

        // Geri sayımı yöneten fonksiyon
        function updateCountdown() {
            timeRemaining--;
            const minutes = Math.floor(timeRemaining / 60);
            const seconds = timeRemaining % 60;
            countdownDisplay.textContent = `Sonraki tarama: ${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;

            if (timeRemaining <= 0) {
                startScan();
            }
        }

        // Geri sayımı sıfırlayıp yeniden başlatan fonksiyon
        function resetCountdown() {
            clearInterval(countdownTimer); // Mevcut zamanlayıcıyı temizle
            timeRemaining = scanIntervalSeconds;
            countdownDisplay.textContent = `Sonraki tarama: 20:00`;
            countdownTimer = setInterval(updateCountdown, 1000); // Her saniye geri sayımı güncelle
        }

        // Yeniden Başlat (Şimdi Tara) düğmesine tıklama olayı
        restartButton.addEventListener('click', () => {
            if (!restartButton.disabled) {
                startScan();
            }
        });

        // Sayfa ilk yüklendiğinde taramayı ve geri sayımı başlat
        startScan();
    </script>
</body>
</html>