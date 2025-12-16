<?php
// === gpsmap/public_track.php ===
header('Content-Type: application/json');

// --- CONFIGURATION ---
// These details are needed to fetch data from your Traccar server's API
$traccar_host = 'http://10.10.0.3:8082';
$traccar_user = 'it@avegabros.com';
$traccar_pass = 'it@v3ga_gWafu';

// --- Database Connection ---
$conn = new mysqli("localhost", "gpsuser", "gpspassword", "gps_tracker");
if ($conn->connect_error) {
    http_response_code(503); // Service Unavailable
    echo json_encode(['status' => 'error', 'message' => 'Service is temporarily unavailable.']);
    exit();
}

// Check if plate_number is provided
if (!isset($_POST['plate_number']) || empty(trim($_POST['plate_number']))) {
    http_response_code(400); // Bad Request
    echo json_encode(['status' => 'error', 'message' => 'Please enter a Tracking ID.']);
    exit();
}

$plate_number = trim($_POST['plate_number']);

// --- Find Device ID from Plate Number ---
$stmt = $conn->prepare(
    "SELECT dd.device_id, dd.display_name, dd.plate_number 
     FROM device_details dd 
     WHERE dd.plate_number = ?"
);
$stmt->bind_param("s", $plate_number);
$stmt->execute();
$result = $stmt->get_result();
$device_details = $result->fetch_assoc();
$stmt->close();
$conn->close();

if (!$device_details) {
    http_response_code(404); // Not Found
    echo json_encode(['status' => 'error', 'message' => 'Invalid Tracking ID.']);
    exit();
}

$deviceId = $device_details['device_id'];

// --- Fetch Latest Position from Traccar ---
$url = $traccar_host . '/api/positions?deviceId=' . $deviceId;
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERPWD, "$traccar_user:$traccar_pass");
curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    http_response_code(502); // Bad Gateway
    echo json_encode(['status' => 'error', 'message' => 'Could not retrieve location from the tracking server.']);
    exit();
}

$positions = json_decode($response, true);

if (empty($positions)) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'This vehicle has not reported its location yet.']);
    exit();
}

// The API returns an array, we just need the first (and only) result
$latestPosition = $positions[0];

// --- Prepare and Send the final data ---
$data_to_return = [
    'status' => 'success',
    'lat' => $latestPosition['latitude'],
    'lon' => $latestPosition['longitude'],
    'speed' => $latestPosition['speed'],
    'course' => $latestPosition['course'],
    'serverTime' => $latestPosition['serverTime'],
    'deviceName' => $device_details['display_name'] ?: ('Device ' . $deviceId),
    'plateNumber' => $device_details['plate_number']
];

echo json_encode($data_to_return);

?>
