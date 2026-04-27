<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$type = $_GET['type'] ?? 'city';
$baseUrl = 'https://apps.ups.com.tr/pickuprequest';

if ($type === 'district') {
    $cityId = $_GET['cityid'] ?? '';
    if (!preg_match('/^\d+$/', $cityId)) {
        http_response_code(400);
        echo json_encode(['error' => 'cityid zorunlu']);
        exit;
    }
    $url = $baseUrl . '/district?cityid=' . rawurlencode($cityId);
} else {
    $url = $baseUrl . '/city';
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($response === false || $httpCode < 200 || $httpCode >= 300) {
    http_response_code(502);
    echo json_encode([
        'error' => 'UPS lokasyon servisi okunamadi',
        'httpCode' => $httpCode,
        'detail' => $error,
    ]);
    exit;
}

echo $response;
