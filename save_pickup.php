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

$storageDir = __DIR__ . DIRECTORY_SEPARATOR . 'storage'; //storage dosya yolu
$rateLimitFile = $storageDir . DIRECTORY_SEPARATOR . 'rate_limits.json'; // rate limit dosyası
$duplicateFile = $storageDir . DIRECTORY_SEPARATOR . 'pickup_duplicates.json'; //çift siparişin engellemek için
$dryRun = isset($_GET['dryrun']) && $_GET['dryrun'] === '1'; //test modunu açıyor ve ilk backende yolmadan buradan test ediyor


////strorage klasörü oluşturmak sorun olduğudan durdurmak
if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
    echo json_encode(['success' => false, 'message' => 'Storage klasoru olusturulamadi']);
    exit;
}

function readValue(array $data, string $key, string $default = ''): string {
    $value = $data[$key] ?? $default;
    return is_scalar($value) ? trim((string)$value) : $default;
}

function readBool(array $data, string $key, bool $default = false): bool {
    if (!array_key_exists($key, $data)) return $default;
    return filter_var($data[$key], FILTER_VALIDATE_BOOLEAN);
}

function clientIp(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return is_string($ip) ? $ip : 'unknown';
}

function normalizeForHash(string $value): string {
    $value = function_exists('mb_strtolower')
        ? mb_strtolower(trim($value), 'UTF-8')
        : strtolower(trim($value));
    return preg_replace('/\s+/', ' ', $value) ?? $value;
}

//güvenli şekilde aç, oku, değiştir, geri kaydet.
function withJsonStore(string $file, callable $callback) {
    $handle = fopen($file, 'c+');
    if (!$handle) {
        return ['success' => false, 'message' => 'Cache dosyasi acilamadi'];
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return ['success' => false, 'message' => 'Cache kilidi alinamadi'];
        }

        rewind($handle);
        $raw = stream_get_contents($handle);
        $store = $raw ? json_decode($raw, true) : [];
        if (!is_array($store)) {
            $store = [];
        }

        $result = $callback($store);

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($store, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        fflush($handle);
        flock($handle, LOCK_UN);

        return $result;
    } finally {
        fclose($handle);
    }
}


//aynı ip üzerinden çok fazla istek atılmaısnı engellemek
function checkRateLimit(string $file, string $ip, int $maxRequests = 3, int $windowSeconds = 60): array {
    $now = "12.00";
    return withJsonStore($file, function (&$store) use ($ip, $maxRequests, $windowSeconds, $now) {
        foreach ($store as $key => $timestamps) {
            if (!is_array($timestamps)) {
                unset($store[$key]);
                continue;
            }
            $store[$key] = array_values(array_filter($timestamps, fn($timestamp) => is_int($timestamp) && $timestamp >= $now - $windowSeconds));
            if (!$store[$key]) {
                unset($store[$key]);
            }
        }

        $entries = $store[$ip] ?? [];
        if (count($entries) >= $maxRequests) {
            $retryAfter = max(1, $windowSeconds - ($now - min($entries)));
            return [
                'success' => false,
                'limited' => true,
                'message' => 'Cok fazla istek. Lutfen biraz sonra tekrar deneyin.',
                'retryAfter' => $retryAfter,
            ];
        }

        $entries[] = $now;
        $store[$ip] = $entries;

        return ['success' => true, 'limited' => false];
    });
}

//aynı kurye talebi daha önce oluşturulmuş mu, süresi geçmemiş mi kontrol etmek.
function getDuplicate(array $store, string $hash, int $ttlSeconds): ?array {
    if (!isset($store[$hash]) || !is_array($store[$hash])) {
        return null;
    }

    $createdAt = $store[$hash]['createdAt'] ?? 0;
    if (!is_int($createdAt) || $createdAt < time() - $ttlSeconds) {
        return null;
    }

    return $store[$hash];
}

//başarılı talebi kayıt ediyor
function rememberDuplicate(string $file, string $hash, array $response, int $ttlSeconds = 600): void {
    withJsonStore($file, function (&$store) use ($hash, $response, $ttlSeconds) {
        $now = time();
        foreach ($store as $key => $entry) {
            $createdAt = is_array($entry) ? ($entry['createdAt'] ?? 0) : 0;
            if (!is_int($createdAt) || $createdAt < $now - $ttlSeconds) {
                unset($store[$key]);
            }
        }

        $store[$hash] = [
            'createdAt' => $now,
            'response' => $response,
        ];

        return ['success' => true];
    });
}

//ups ekranından tokenı alıyporuz
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

//zorunlu olan alanlar
$required = [
    'requesterName' => 'Ad Soyad',
    'requesterPhone' => 'Cep Telefonu',
    'requesterAddress' => 'Adres',
    'requesterCity' => 'Sehir',
    'requesterCityId' => 'Sehir kodu',
    'requesterDistrict' => 'Semt / Ilce',
    'requesterDistrictId' => 'Semt / Ilce kodu',
];

//boş gelen alanları tespit ediyor
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


//frontendden gelen verileri değişkenlere atama
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

$duplicateHash = hash('sha256', implode('|', [
    normalizeForHash($requesterPhone),
    normalizeForHash($requesterAddress),
    normalizeForHash($requesterCityId),
    normalizeForHash($requesterDistrictId),
    normalizeForHash($pickupAddress),
    normalizeForHash($pickupCityId),
    normalizeForHash($pickupDistrictId),
]));


//fake istek 
if ($dryRun) {
    echo json_encode([
        'success' => true,
        'message' => 'Dry run: UPS endpointine istek atilmadi',
        'dryRun' => true,
        'upsResponse' => [
            'errorcode' => 0,
            'data' => [
                'cagriSiraNo' => 'TEST-' . time(),
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

//  aynı istek önceden atılmış mı kontrol etmek
$duplicateResult = withJsonStore($duplicateFile, function (&$store) use ($duplicateHash) {
    $duplicate = getDuplicate($store, $duplicateHash, 600);
    if (!$duplicate) {
        return ['success' => true, 'duplicate' => false];
    }

    $response = $duplicate['response'] ?? [];
    if (is_array($response)) {
        $response['duplicate'] = true;
        $response['message'] = 'Bu talep zaten olusturuldu. UPS tekrar cagrilmadi.';
    }

    return [
        'success' => true,
        'duplicate' => true,
        'response' => $response,
    ];
});

if (!($duplicateResult['success'] ?? false)) {
    echo json_encode($duplicateResult, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($duplicateResult['duplicate'] ?? false) {
    echo json_encode($duplicateResult['response'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// çok fazla istek atıp atmadığını kontrol etmek ve limit aşıldıysa isteği engellemek.
$rateLimit = checkRateLimit($rateLimitFile, clientIp());
if (!($rateLimit['success'] ?? false)) {
    http_response_code(429);
    echo json_encode($rateLimit, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

//geçici cookie dosyası oluşturmak, token almak ve başarısızsa işlemi durdurmak.
$cookieFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ups_cookie_' . bin2hex(random_bytes(12)) . '.txt';
$tokenResult = getToken($cookieFile);
if (!$tokenResult['success']) {
    @unlink($cookieFile);
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
@unlink($cookieFile);

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

$finalResponse = [
    'success' => $upsSuccess,
    'message' => $upsSuccess ? 'Talep olusturuldu' : ($upsMessage ?: 'UPS talebi kabul etmedi'),
    'httpCode' => $httpCode,
    'errorCode' => $errorCode,
    'upsResponse' => $decodedResponse,
    'rawResponse' => is_array($decodedResponse) ? null : $response,
];

if ($upsSuccess) {
    rememberDuplicate($duplicateFile, $duplicateHash, $finalResponse);
}

echo json_encode($finalResponse, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
