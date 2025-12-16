<?php
// === gpsmap/send_test_data.php ===

// For debugging: Uncomment the next two lines to see all PHP errors.
// error_reporting(E_ALL);
// ini_set('display_errors', 1);

session_start();

header('Content-Type: application/json');

// Ensure user is logged in and is an admin
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Unauthorized: Admin access required."]);
    exit();
}

// Check if the cURL extension is loaded. This is a common point of failure.
if (!function_exists('curl_init')) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Server configuration error: cURL extension is not enabled."]);
    exit();
}

// Traccar server details
$traccar_host = '10.10.0.4'; // The IP of your Traccar server
$traccar_port = '5055';     // The OsmAnd protocol port

// Check for required POST data
if (!isset($_POST['deviceId'], $_POST['lat'], $_POST['lon'], $_POST['speed'], $_POST['course'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Client error: Missing required parameters."]);
    exit();
}

// Sanitize and build the query string for the OsmAnd protocol
$queryParams = http_build_query([
    'id'      => $_POST['deviceId'],
    'lat'     => $_POST['lat'],
    'lon'     => $_POST['lon'],
    'speed'   => $_POST['speed'],
    'course'  => $_POST['course'],
    'bearing' => $_POST['course'] // Sending both for compatibility
]);

$traccarUrl = "http://{$traccar_host}:{$traccar_port}/?{$queryParams}";

// Use cURL to send the GET request to the Traccar server
$ch = curl_init($traccarUrl);

if ($ch === false) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Server error: Failed to initialize cURL session."]);
    exit();
}

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

// Respond to the front-end with the result
if ($error) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "cURL Error to Traccar: " . $error, "url" => $traccarUrl]);
} else {
    // Traccar's OsmAnd port often returns 200 OK with an empty body on success.
    // Any response is considered a success in terms of sending the data.
    echo json_encode([
        "status" => "success", 
        "message" => "Data sent to Traccar. Server responded with HTTP " . $httpCode, 
        "response" => $response, 
        "url" => $traccarUrl
    ]);
}
?>
