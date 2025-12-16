<?php
// === gpsmap/manage_vehicles.php (Vehicles Only) ===

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$conn = new mysqli("localhost", "gpsuser", "gpspassword", "gps_tracker");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// --- FORM PROCESSING ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $device_id = (int) $_POST['device_id'];

    // Handle updating the display name
    if (isset($_POST['update_name'])) {
        $display_name = trim($_POST['display_name']);
        $stmt = $conn->prepare("INSERT INTO device_details (device_id, display_name) VALUES (?, ?) ON DUPLICATE KEY UPDATE display_name = ?");
        $stmt->bind_param("iss", $device_id, $display_name, $display_name);
        $stmt->execute();
        $stmt->close();
    }

    // Handle updating the plate number
    if (isset($_POST['update_plate'])) {
        $plate_number = trim($_POST['plate_number']);
        $stmt = $conn->prepare("INSERT INTO device_details (device_id, plate_number) VALUES (?, ?) ON DUPLICATE KEY UPDATE plate_number = ?");
        $stmt->bind_param("iss", $device_id, $plate_number, $plate_number);
        $stmt->execute();
        $stmt->close();
    }

    header("Location: manage_vehicles.php");
    exit();
}


// --- DATA FETCHING ---
$traccarUsername = 'it@avegabros.com';
$traccarPassword = 'it@v3ga_gWafu';

$ch = curl_init("http://10.10.0.3:8082/api/devices");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERPWD, "$traccarUsername:$traccarPassword");
$response = curl_exec($ch);
curl_close($ch);

$devices = json_decode($response, true) ?: [];

// Fetch all device details (names and plates) from local DB
$deviceDetails = [];
$detailsResult = $conn->query("SELECT device_id, display_name, plate_number FROM device_details");
if($detailsResult) {
    while ($row = $detailsResult->fetch_assoc()) {
        $deviceDetails[$row['device_id']] = [
            'display_name' => $row['display_name'],
            'plate_number' => $row['plate_number']
        ];
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Manage Vehicle Details</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8f9fa; }
        .card-header { background-color: #343a40; color: white; }
        .form-inline { display: flex; gap: 10px; align-items: center; }
    </style>
</head>
<body class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Manage Vehicle Details</h2>
        <a href="dashboard.php" class="btn btn-secondary">← Back to Dashboard</a>
    </div>

    <div class="card">
        <h5 class="card-header">All Devices</h5>
        <div class="card-body">
            <p class="text-muted">Edit the display name and plate number for each vehicle below. The display name will override the original name from Traccar.</p>
            <div class="table-responsive">
                <table class="table table-striped table-bordered align-middle">
                     <thead class="table-dark">
                        <tr>
                            <th>Display Name</th>
                            <th>Plate Number</th>
                            <th>Original Name (from Traccar)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($devices)): ?>
                            <?php foreach ($devices as $device):
                                $did = $device['id'];
                                $traccarName = htmlspecialchars($device['name'] ?? "Device $did");
                                $details = $deviceDetails[$did] ?? ['display_name' => '', 'plate_number' => ''];
                                
                                // Use local display name if it exists, otherwise fall back to Traccar name
                                $displayName = htmlspecialchars($details['display_name'] ?: $traccarName);
                                $currentPlate = htmlspecialchars($details['plate_number'] ?? '');
                            ?>
                            <tr>
                                <td>
                                    <form method="POST" class="form-inline">
                                        <input type="hidden" name="device_id" value="<?= $did ?>">
                                        <input type="text" class="form-control" name="display_name" value="<?= $displayName ?>" placeholder="Enter display name">
                                        <button type="submit" name="update_name" class="btn btn-sm btn-outline-primary">Save</button>
                                    </form>
                                </td>
                                <td>
                                    <form method="POST" class="form-inline">
                                        <input type="hidden" name="device_id" value="<?= $did ?>">
                                        <input type="text" class="form-control" name="plate_number" value="<?= $currentPlate ?>" placeholder="Enter plate number">
                                        <button type="submit" name="update_plate" class="btn btn-sm btn-outline-success">Save</button>
                                    </form>
                                </td>
                                <td><small class="text-muted"><?= $traccarName ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                             <tr>
                                <td colspan="3" class="text-center">No devices found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
