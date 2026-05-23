<?php
require_once __DIR__ . '/../includes/config.php';

$number = '09508760485';
$phone = '63' . substr($number, 1);
$message = "TEST DIRECT API: " . date('Y-m-d H:i:s');

$data = [
    'api_token' => SMS_API_TOKEN,
    'message' => $message,
    'phone_number' => $phone,
];

echo "<h2>Testing iProgSMS API</h2>";
echo "Phone: " . $phone . "<br>";
echo "Token: " . substr(SMS_API_TOKEN, 0, 10) . "...<br>";
echo "<hr>";

$ch = curl_init('https://www.iprogsms.com/api/v1/sms_messages');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: " . $httpCode . "<br>";
echo "Response: " . htmlspecialchars($response) . "<br>";

if ($httpCode == 200) {
    echo "<h3 style='color:green'>✅ API is working!</h3>";
} else {
    echo "<h3 style='color:red'>❌ API failed! Check your token and credits.</h3>";
}
?>