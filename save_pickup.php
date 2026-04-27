<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Sadece POST kabul edilir']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    echo json_encode(['success' => false, 'message' => 'Gecersiz JSON']);
    exit;
}

$cookieFile = __DIR__ . DIRECTORY_SEPARATOR . 'ups_cookies.txt';

function readValue(array $data, string $key, string $default = ''): string {
    $value = $data[$key] ?? $default;
    return is_scalar($value) ? trim((string)$value) : $default;
}

function readBool(array $data, string $key, bool $default = false): bool {
    if (!array_key_exists($key, $data)) return $default;
    return filter_var($data[$key], FILTER_VALIDATE_BOOLEAN);
}

function getToken(string $cookieFile): array {
    $ch = curl_init('https://apps.ups.com.tr/PickupRequest');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36',
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        return [
            'success' => false,
            'message' => 'UPS sayfasi yuklenemedi',
            'httpCode' => $httpCode,
            'error' => $error,
        ];
    }

    $token = null;
    if (preg_match('/name=["\']__RequestVerificationToken["\'][^>]*value=["\']([^"\']+)["\']/i', $response, $m)) {
        $token = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    } elseif (preg_match('/__RequestVerificationToken["\']?\s*[:=]\s*["\']([^"\']+)["\']/i', $response, $m)) {
        $token = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }

    if (!$token) {
        return [
            'success' => false,
            'message' => 'Token bulunamadi',
            'debugLength' => strlen($response),
        ];
    }

    return ['success' => true, 'token' => trim($token)];
}

$required = [
    'requesterName' => 'Ad Soyad',
    'requesterPhone' => 'Cep Telefonu',
    'requesterAddress' => 'Adres',
    'requesterCity' => 'Sehir',
    'requesterCityId' => 'Sehir kodu',
    'requesterDistrict' => 'Semt / Ilce',
    'requesterDistrictId' => 'Semt / Ilce kodu',
];

$missing = [];
foreach ($required as $key => $label) {
    if (readValue($data, $key) === '') {
        $missing[] = $label;
    }
}

$pickupSame = readBool($data, 'pickupSameAddress', true);
if (!$pickupSame) {
    foreach ([
        'pickupAddress' => 'Alim adresi',
        'pickupCity' => 'Alim sehri',
        'pickupCityId' => 'Alim sehir kodu',
        'pickupDistrict' => 'Alim semt / ilce',
        'pickupDistrictId' => 'Alim semt / ilce kodu',
        'pickupPhone' => 'Alim telefonu',
    ] as $key => $label) {
        if (readValue($data, $key) === '') {
            $missing[] = $label;
        }
    }
}

if ($missing) {
    echo json_encode([
        'success' => false,
        'message' => 'Eksik alanlar: ' . implode(', ', $missing),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$requesterName = readValue($data, 'requesterName');
$requesterCompany = readValue($data, 'requesterCompany', $requesterName);
$requesterPhone = readValue($data, 'requesterPhone');
$requesterAddress = readValue($data, 'requesterAddress');
$requesterCity = readValue($data, 'requesterCity');
$requesterCityId = readValue($data, 'requesterCityId');
$requesterDistrict = readValue($data, 'requesterDistrict');
$requesterDistrictId = readValue($data, 'requesterDistrictId');
$pickupContactName = readValue($data, 'pickupContactName', $requesterName);

$pickupCompany = $pickupSame ? $requesterCompany : readValue($data, 'pickupCompany', $requesterCompany);
$pickupAddress = $pickupSame ? $requesterAddress : readValue($data, 'pickupAddress');
$pickupCity = $pickupSame ? $requesterCity : readValue($data, 'pickupCity');
$pickupCityId = $pickupSame ? $requesterCityId : readValue($data, 'pickupCityId');
$pickupDistrict = $pickupSame ? $requesterDistrict : readValue($data, 'pickupDistrict');
$pickupDistrictId = $pickupSame ? $requesterDistrictId : readValue($data, 'pickupDistrictId');
$pickupPhone = $pickupSame ? $requesterPhone : readValue($data, 'pickupPhone');
$pickupPerson = $pickupSame ? $pickupContactName : readValue($data, 'pickupAddressContactName', $pickupContactName);
$note = readValue($data, 'note', 'Kendi form uzerinden olusturuldu');
$packageCount = readValue($data, 'packageCount', '1');

$tokenResult = getToken($cookieFile);
if (!$tokenResult['success']) {
    echo json_encode($tokenResult, JSON_UNESCAPED_UNICODE);
    exit;
}

$token = $tokenResult['token'];

$postModel = [
    '__RequestVerificationToken' => $token,
    'Durumu' => 0,
    'CVUnvani' => $requesterCompany !== '' ? $requesterCompany : $requesterName,
    'CVAdres' => $requesterAddress,
    'CVSehir' => $requesterCity,
    'CVSehirSiraNo' => $requesterCityId,
    'CVSemtIlce' => $requesterDistrict,
    'CVSemtIlceSiraNo' => $requesterDistrictId,
    'CVTelefon' => $requesterPhone,
    'CVKisi' => $requesterName,
    'CVTeslimEdecekKisi' => $pickupContactName,
    'PAUnvani' => $pickupCompany !== '' ? $pickupCompany : $pickupPerson,
    'PAAdres' => $pickupAddress,
    'PAAdresSiraNo' => 0,
    'PAMusteriSicilNo' => 0,
    'PASemtIlce' => $pickupDistrict,
    'PASemtIlceSiraNo' => $pickupDistrictId,
    'PASehir' => $pickupCity,
    'PASehirSiraNo' => $pickupCityId,
    'PATelefon' => $pickupPhone,
    'PAKisi' => $pickupPerson,
    'PATeslimEdecekKisi' => $pickupPerson,
    'PaketVeAliciAyni' => $pickupSame ? 'true' : 'false',
    'OdemeSekli' => 2,
    'PaketHazir' => 1,
    'CVMusteriSicilNo' => 0,
    'CVAdresSiraNo' => 0,
    'GonderiTipi' => 0,
    'GonderiTipiStr' => 'Yurtici',
    'RezervasyonZamani' => '',
    'CagriSebebi' => 0,
    'Aciklama' => $note . ' - ' . $packageCount . ' adet Koli',
    'CagriyiAlanSube' => 340,
    'CagriKaynagi' => 'KendiForm',
    'CariMusteri' => 'false',
    'MusteriKod' => '',
    'GonderiTipi_Yurtdisi' => '',
];

$ch = curl_init('https://apps.ups.com.tr/PickupRequest/callsave');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($postModel),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
        'RequestVerificationToken: ' . $token,
        'X-Requested-With: XMLHttpRequest',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'Referer: https://apps.ups.com.tr/PickupRequest',
    ],
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($response === false || $httpCode < 200 || $httpCode >= 300) {
    echo json_encode([
        'success' => false,
        'message' => 'UPS kayit istegi basarisiz',
        'httpCode' => $httpCode,
        'error' => $error,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$decodedResponse = json_decode($response, true);
$errorCode = is_array($decodedResponse) ? ($decodedResponse['errorcode'] ?? $decodedResponse['errorCode'] ?? $decodedResponse['ErrorCode'] ?? null) : null;
$isSuccess = is_array($decodedResponse) ? ($decodedResponse['isSuccess'] ?? $decodedResponse['IsSuccess'] ?? null) : null;
$upsSuccess = is_array($decodedResponse)
    ? ((string)$errorCode === '0' || $isSuccess === true || $isSuccess === 'true')
    : false;
$upsMessage = is_array($decodedResponse)
    ? ($decodedResponse['message'] ?? $decodedResponse['Message'] ?? $decodedResponse['errorMessage'] ?? null)
    : null;

echo json_encode([
    'success' => $upsSuccess,
    'message' => $upsSuccess ? 'Talep olusturuldu' : ($upsMessage ?: 'UPS talebi kabul etmedi'),
    'httpCode' => $httpCode,
    'errorCode' => $errorCode,
    'upsResponse' => $decodedResponse,
    'rawResponse' => is_array($decodedResponse) ? null : $response,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
