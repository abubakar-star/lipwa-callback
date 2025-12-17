<?php

function sendTalkSasaSMS($phone, $message)
{
    $apiKey = 'YOUR_TALKSASA_API_KEY';
    $senderId = 'DLINK'; // your sender ID

    $payload = [
        'api_key' => $apiKey,
        'sender'  => $senderId,
        'phone'   => $phone,
        'message' => $message
    ];

    $ch = curl_init('https://bulksms.talksasa.com/api/v1/send');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 10
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    // Optional logging
    file_put_contents(
        'sms_log.txt',
        date('Y-m-d H:i:s') . " | $phone | $response\n",
        FILE_APPEND
    );

    return $response;
}
