<?php
// === gpsmap/get_last_odometer.php ===

session_start();
header('Content-Type: application/json');

// --- Security Check: Ensure user is logged in ---
if (!isset($_SESSION['logged_in'])) {
    http_response_code(403); // Forbidden
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// --- Parameter Validation: Ensure a valid device ID is provided ---
if (!isset($_GET['device_id']) || !filter_var($_GET['device_id'], FILTER_VALIDATE_INT)) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Invalid or missing device ID']);
    exit();
}
$deviceId = (int)$_GET['device_id'];

// --- Database Connection ---
$db_host = "localhost";
$db_user = "gpsuser";
$db_pass = "gpspassword";
$db_name = "gps_tracker";

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    http_response_code(503); // Service Unavailable
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

// --- Query to get the last known end_odometer reading for the specified vehicle ---
// It looks for the most recent ticket for this device that has a non-null end_odometer.
// Ordering by trip_date and then ID ensures we get the absolute latest entry.
$stmt = $conn->prepare(
    "SELECT end_odometer FROM trip_tickets 
     WHERE device_id = ? AND end_odometer IS NOT NULL 
     ORDER BY trip_date DESC, id DESC 
     LIMIT 1"
);

$lastOdometer = null;
if ($stmt) {
    $stmt->bind_param("i", $deviceId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        // We found a previous reading.
        $lastOdometer = $row['end_odometer'];
    }
    $stmt->close();
}

$conn->close();

// --- Return the result as a JSON object ---
// The frontend JavaScript will use this response.
echo json_encode(['last_odometer' => $lastOdometer]);

?>
