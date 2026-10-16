<?php

function mpesaAccessToken(
    string $consumerKey,
    string $consumerSecret,
    bool $sandbox = true
): string {
    $base = $sandbox
        ? 'https://sandbox.safaricom.co.ke'
        : 'https://api.safaricom.co.ke';

    $credentials = base64_encode(
        $consumerKey . ':' . $consumerSecret
    );

    $ch = curl_init(
        $base .
        '/oauth/v1/generate?grant_type=client_credentials'
    );

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . $credentials,
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        throw new Exception(curl_error($ch));
    }

    curl_close($ch);

    $data = json_decode($response, true);

    if (empty($data['access_token'])) {
        throw new Exception(
            $data['error_description'] ??
            'Unable to obtain M-Pesa access token.'
        );
    }

    return $data['access_token'];
}


function mpesaStkPush(
    string $consumerKey,
    string $consumerSecret,
    string $shortcode,
    string $passkey,
    string $phone,
    int $amount,
    string $callbackUrl,
    bool $sandbox = true
): array {

    $base = $sandbox
        ? 'https://sandbox.safaricom.co.ke'
        : 'https://api.safaricom.co.ke';

    /*
     * Normalize phone.
     */
    $phone = preg_replace('/[^0-9]/', '', $phone);

    if (str_starts_with($phone, '0')) {
        $phone = '254' . substr($phone, 1);
    }

    if (str_starts_with($phone, '+254')) {
        $phone = substr($phone, 1);
    }

    if (!preg_match('/^254[17][0-9]{8}$/', $phone)) {
        throw new Exception('Invalid M-Pesa phone number.');
    }

    if ($amount < 1) {
        throw new Exception('Invalid payment amount.');
    }

    /*
     * Get token.
     */
    $token = mpesaAccessToken(
        $consumerKey,
        $consumerSecret,
        $sandbox
    );

    /*
     * Daraja timestamp.
     */
    $timestamp = date('YmdHis');

    /*
     * STK password.
     */
    $password = base64_encode(
        $shortcode .
        $passkey .
        $timestamp
    );

    $body = [
        'BusinessShortCode' => $shortcode,
        'Password' => $password,
        'Timestamp' => $timestamp,

        'TransactionType' =>
            'CustomerPayBillOnline',

        'Amount' => $amount,

        'PartyA' => $phone,

        'PartyB' => $shortcode,

        'PhoneNumber' => $phone,

        'CallBackURL' => $callbackUrl,

        'AccountReference' => 'ONELINK',

        'TransactionDesc' =>
            'Internet Access'
    ];

    $ch = curl_init(
        $base .
        '/mpesa/stkpush/v1/processrequest'
    );

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_POST => true,

        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ],

        CURLOPT_POSTFIELDS =>
            json_encode($body),

        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        throw new Exception(curl_error($ch));
    }

    curl_close($ch);

    $data = json_decode($response, true);

    if (
        !isset($data['ResponseCode']) ||
        (string)$data['ResponseCode'] !== '0'
    ) {
        throw new Exception(
            $data['ResponseDescription'] ??
            'M-Pesa STK Push failed.'
        );
    }

    return $data;
}
