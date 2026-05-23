<?php
require_once __DIR__ . '/../includes/config.php';

// Ito ang number na nakuha mula sa parents table
$parentNumber = '09508760485';

// I-format para sa iProgSMS
$phone = '63' . substr($parentNumber, 1);  // 09508760485 -> 639508760485

$message = "TEST: This is a test message from your attendance system. Time: " . date('Y-m-d H:i:s');

$data = [
    'api_token' => SMS_API_TOKEN,
    'message' => $message,
    'phone_number' => $phone,
];

echo "<h2>Testing iProgSMS API</h2>";
echo "<hr>";
echo "Parent Number: " . $parentNumber . "<br>";
echo "Formatted Phone: " . $phone . "<br>";
echo "Message: " . $message . "<br>";
echo "API URL: " . SMS_API_URL . "<br>";
echo "Token: " . substr(SMS_API_TOKEN, 0, 10) . "...<br>";
echo "<hr>";

$ch = curl_init(SMS_API_URL);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "<h3>Result:</h3>";
echo "HTTP Code: " . $httpCode . "<br>";
echo "Response: " . htmlspecialchars($response) . "<br>";

if ($curlError) {
    echo "cURL Error: " . $curlError . "<br>";
}

if ($httpCode == 200) {
    $decoded = json_decode($response, true);
    if (isset($decoded['success']) && $decoded['success'] === true) {
        echo "<h3 style='color:green'>✅ SMS SENT SUCCESSFULLY to {$parentNumber}!</h3>";
    } else {
        echo "<h3 style='color:orange'>⚠️ API Error: " . ($decoded['message'] ?? 'Unknown') . "</h3>";
    }
} elseif ($httpCode == 401 || $httpCode == 403) {
    echo "<h3 style='color:red'>❌ INVALID API TOKEN! Please check config.php</h3>";
} elseif ($httpCode == 402) {
    echo "<h3 style='color:red'>❌ INSUFFICIENT CREDITS! Please add load to iProgSMS</h3>";
} else {
    echo "<h3 style='color:red'>❌ SMS FAILED! HTTP Code: $httpCode</h3>";
}
?>