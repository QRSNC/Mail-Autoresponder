<?php

/**
 * OpenRouter API'sini kullanarak bir e-postaya yapay zeka ile yanıt oluşturur.
 *
 * @param string $emailSubject Gelen e-postanın konusu.
 * @param string $emailBody Gelen e-postanın metni.
 * @param string $emailSender Gönderenin bilgisi ("İsim <email@adres.com>" formatında).
 * @return string Yapay zeka tarafından oluşturulan yanıt metni.
 */
function generateAiReply($emailSubject, $emailBody, $emailSender) {
    // .env dosyasından OpenRouter API anahtarını al
    $apiKey = $_ENV['OPENROUTER_API_KEY'];
    if (empty($apiKey)) {
        return "HATA: .env dosyasında OPENROUTER_API_KEY bulunamadı.";
    }

    // Eğitmen hakkındaki temel bilgileri info.txt dosyasından oku
    $instructorInfo = file_get_contents('info.txt');

    // --- Yapay Zekaya Gönderilecek Komutları (Prompt) Hazırlama ---

    // 1. Sistem Komutu: Yapay zekanın kimliğini ve kurallarını belirler.
    $systemPrompt = "Sen, bir dil eğitmeninin yardımsever ve profesyonel bir asistanısın. Görevin, sana verilen eğitmen bilgilerini kullanarak gelen e-postalara cevap vermektir. Cevapların her zaman nazik, kısa ve net olmalı. Sadece sana verilen bilgiler dahilinde cevap ver, bilmediğin konularda asla tahmin yürütme. Gelen e-postadaki isme hitap ederek başla. Konu dışı veya alakasız sorulara cevap verme, sadece derslerle ilgili soruları yanıtla. Cevabının sonuna eğitmenin adını ekle.";

    // 2. Kullanıcı Komutu: Yapay zekaya o anki görevini verir.
    $userPrompt = "Lütfen aşağıdaki bilgileri kullanarak gelen e-postayı yanıtla.\n\n"
                . "--- EĞİTMEN BİLGİLERİ ---\n"
                . $instructorInfo . "\n\n"
                . "--- GELEN E-POSTA ---\n"
                . "Gönderen: " . $emailSender . "\n"
                . "Konu: " . $emailSubject . "\n"
                . "Mesaj:\n" . $emailBody . "\n\n"
                . "--- YANITIN İÇİN TALİMATLAR ---\n"
                . "Yukarıdaki e-postaya, eğitmen bilgileri ışığında bir yanıt metni oluştur. Direkt olarak yanıt metnini yaz, herhangi bir ek açıklama yapma.";


    // OpenRouter API'sine gönderilecek veri yapısını hazırlama
    $postData = [
        'model' => 'openai/gpt-4o-mini', // Kullanacağımız model
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt]
        ]
    ];

    // API isteği için cURL oturumu başlatma
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, "https://openrouter.ai/api/v1/chat/completions");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
        'HTTP-Referer: http://localhost', // İsteğe bağlı, projenizi belirtir
        'X-Title: AI Email Responder'     // İsteğe bağlı, projenizi belirtir
    ]);

    // API isteğini çalıştır
    $response = curl_exec($ch);

    // Hata kontrolü
    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        return "API isteği sırasında cURL Hatası: " . $error_msg;
    }

    curl_close($ch);

    // Gelen JSON yanıtını diziye çevir
    $responseData = json_decode($response, true);

    // Yanıt metnini ayıkla ve geri döndür
    if (isset($responseData['choices'][0]['message']['content'])) {
        return $responseData['choices'][0]['message']['content'];
    }

    // Eğer bir sorun olduysa boş bir metin döndür
    return "";
}