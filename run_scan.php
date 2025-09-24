<?php
// Gerekli dosyaları ve ayarları yüklüyoruz
require_once __DIR__ . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// --- YARDIMCI FONKSİYONLAR ---
function logMessage($message, $type = 'info') {
    $colors = ['info' => 'primary', 'success' => 'success', 'warning' => 'warning', 'danger' => 'danger'];
    $color = $colors[$type] ?? 'info';
    // Not: Stil ve renkler artık index.php'deki CSS tarafından yönetilecek.
    echo '<li class="list-group-item list-group-item-' . $color . ' small">[' . date('H:i:s') . '] ' . htmlspecialchars($message) . '</li>';
    flush(); ob_flush();
}
function base64url_decode($data) { return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT)); }
function base64url_encode($data) { return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); }

// --- ANA TARAMA MANTIĞI ---
try {
    logMessage("Google API istemcisi hazırlanıyor...");
    $client = new Google_Client();
    $client->setApplicationName('AI Eposta Asistanı');
    $client->setScopes(['https://www.googleapis.com/auth/gmail.readonly', 'https://www.googleapis.com/auth/gmail.modify', 'https://www.googleapis.com/auth/gmail.send']);
    $client->setAuthConfig('credentials.json');
    $client->setAccessType('offline');
    $refreshToken = $_ENV['GOOGLE_REFRESH_TOKEN'];
    if (empty($refreshToken)) { throw new Exception(".env dosyasında GOOGLE_REFRESH_TOKEN bulunamadı!"); }
    $client->fetchAccessTokenWithRefreshToken($refreshToken);
    $gmail = new Google_Service_Gmail($client);
    logMessage("Google API bağlantısı başarılı.", "success");

    logMessage("Ayarlar yükleniyor (blacklist, token limit)...");
    $blacklist = json_decode(file_get_contents('blacklist.json'), true);
    $maxTokenLimit = (int)$_ENV['MAX_TOKEN_LIMIT'];
    logMessage("Ayarlar başarıyla yüklendi.", "success");

    logMessage("Okunmamış e-postalar aranıyor...");
    $messagesResponse = $gmail->users_messages->listUsersMessages('me', ['q' => 'is:unread']);
    $messages = $messagesResponse->getMessages();

    if (empty($messages)) {
        logMessage("İşlem yapılacak yeni e-posta bulunamadı.", "warning");
    } else {
        logMessage(count($messages) . " adet yeni e-posta bulundu. İşleme başlanıyor...");
        
        foreach ($messages as $message) {
            $msg = $gmail->users_messages->get('me', $message->getId(), ['format' => 'full']);
            $payload = $msg->getPayload();
            $headers = $payload->getHeaders();
            $threadId = $msg->getThreadId();

            $fromHeader = ''; $subjectHeader = ''; $messageIdHeader = ''; $referencesHeader = '';
            foreach($headers as $header) {
                $headerName = $header->getName();
                if (strcasecmp($headerName, 'From') == 0) $fromHeader = $header->getValue();
                if (strcasecmp($headerName, 'Subject') == 0) $subjectHeader = $header->getValue();
                if (strcasecmp($headerName, 'Message-ID') == 0) $messageIdHeader = $header->getValue();
                if (strcasecmp($headerName, 'References') == 0) $referencesHeader = $header->getValue();
            }
            
            preg_match('/<([^>]+)>/', $fromHeader, $matches);
            $fromEmail = $matches[1] ?? $fromHeader;
            
            logMessage("İşleniyor: '" . htmlspecialchars($fromEmail) . "' adresinden gelen '" . htmlspecialchars($subjectHeader) . "' konulu e-posta.");
            
            if (empty(trim($fromHeader)) || strpos($fromHeader, '@') === false) {
                logMessage("E-posta atlandı (Geçersiz veya boş 'From' başlığı).", "warning");
                $gmail->users_messages->modify('me', $message->getId(), new Google_Service_Gmail_ModifyMessageRequest(['removeLabelIds' => ['UNREAD']]));
                continue;
            }

            if (in_array($fromEmail, $blacklist)) {
                logMessage("E-posta atlandı (Gönderen blacklist'te).", "warning");
                $gmail->users_messages->modify('me', $message->getId(), new Google_Service_Gmail_ModifyMessageRequest(['removeLabelIds' => ['UNREAD']]));
                continue;
            }

            $body = '';
            if ($payload->getParts()) {
                $parts = $payload->getParts();
                foreach ($parts as $part) { if ($part->getMimeType() == 'text/plain') { $body = $part->getBody()->getData(); break; } }
                if (empty($body) && isset($parts[0])) { $body = $parts[0]->getBody()->getData(); }
            } else { $body = $payload->getBody()->getData(); }
            $body = trim(base64url_decode($body));
            
            if ( (ceil(strlen($body) / 4)) > $maxTokenLimit ) {
                logMessage("E-posta atlandı (Token limiti aşıldı).", "warning");
                $gmail->users_messages->modify('me', $message->getId(), new Google_Service_Gmail_ModifyMessageRequest(['removeLabelIds' => ['UNREAD']]));
                continue;
            }

            logMessage("Filtreler geçildi. AI ile yanıt oluşturuluyor...");
            require_once 'send_ai.php';
            $aiResponse = generateAiReply($subjectHeader, $body, $fromHeader);

            if (empty(trim($aiResponse))) {
                logMessage("AI boş bir yanıt döndürdü. E-posta atlanıyor.", "warning");
                $gmail->users_messages->modify('me', $message->getId(), new Google_Service_Gmail_ModifyMessageRequest(['removeLabelIds' => ['UNREAD']]));
                continue;
            }
            logMessage("AI yanıtı başarıyla oluşturuldu.", "success");

            logMessage("Yanıt e-postası gönderiliyor...");
            // **İŞTE DÜZELTİLEN SATIR BURASI**
            $newReferences = !empty($referencesHeader) ? $referencesHeader . ' ' . $messageIdHeader : $messageIdHeader;
            $rawMessage = "To: " . mb_encode_mimeheader($fromHeader) . "\r\n";
            $rawMessage .= "Subject: Re: " . mb_encode_mimeheader($subjectHeader, 'UTF-8') . "\r-n";
            $rawMessage .= "Content-Type: text/plain; charset=utf-8\r\n";
            $rawMessage .= "Content-Transfer-Encoding: 8bit\r\n";
            $rawMessage .= "In-Reply-To: {$messageIdHeader}\r\n";
            $rawMessage .= "References: {$newReferences}\r\n\r\n";
            $rawMessage .= $aiResponse;
            $encodedMessage = base64url_encode($rawMessage);
            $gmailMessage = new Google_Service_Gmail_Message();
            $gmailMessage->setRaw($encodedMessage);
            $gmailMessage->setThreadId($threadId);
            $gmail->users_messages->send('me', $gmailMessage);
            logMessage("Yanıt başarıyla gönderildi.", "success");

            $gmail->users_messages->modify('me', $message->getId(), new Google_Service_Gmail_ModifyMessageRequest(['removeLabelIds' => ['UNREAD']]));
        }
    }
    logMessage("Tarama tamamlandı.", "success");

} catch (Exception $e) {
    logMessage("KRİTİK HATA: " . $e->getMessage(), "danger");
}