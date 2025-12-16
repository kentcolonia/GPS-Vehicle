<?php
// === gpsmap/track.php ===
session_start();

if (!isset($_SESSION['logged_in'])) {
    http_response_code(403);
    echo json_encode(["error" => "Unauthorized"]);
    exit();
}

if (!isset($_GET['deviceId'], $_GET['from'], $_GET['to'])) {
    http_response_code(400);
    echo json_encode(["error" => "Missing parameters"]);
    exit();
}

$deviceId = (int)$_GET['deviceId'];
$from = date('c', strtotime($_GET['from'])); // ISO 8601 with timezone
$to = date('c', strtotime($_GET['to']));     // ISO 8601 with timezone

$traccarUrl = "http://10.10.0.3:8082/api/reports/route?deviceId=$deviceId&from=" . urlencode($from) . "&to=" . urlencode($to);
$traccarUsername = 'user@email.com';
$traccarPassword = 'password';

$ch = curl_init($traccarUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERPWD, "$traccarUsername:$traccarPassword");
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Accept: application/json"
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error || $httpCode !== 200) {
    http_response_code(500);
    echo json_encode(["error" => $error ?: "HTTP error $httpCode"]);
    exit();
}

// Output as JSON
header('Content-Type: application/json');
echo $response;

