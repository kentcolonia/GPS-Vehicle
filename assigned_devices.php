<?php
// === gpsmap/assign_devices.php ===
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$conn = new mysqli("localhost", "gpsuser", "gpspassword", "gps_tracker");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Assign device to user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'], $_POST['device_id'])) {
    $user_id = (int) $_POST['user_id'];
    $device_id = (int) $_POST['device_id'];

    $stmt = $conn->prepare("INSERT IGNORE INTO user_devices (user_id, device_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $user_id, $device_id);
    $stmt->execute();
    $stmt->close();

    header("Location: assign_devices.php");
    exit();
}

// Remove device access
if (isset($_GET['remove']) && isset($_GET['user']) && isset($_GET['device'])) {
    $user_id = (int) $_GET['user'];
    $device_id = (int) $_GET['device'];

    $stmt = $conn->prepare("DELETE FROM user_devices WHERE user_id = ? AND device_id = ?");
    $stmt->bind_param("ii", $user_id, $device_id);
    $stmt->execute();
    $stmt->close();

    header("Location: assign_devices.php");
    exit();
}

// Get users from correct table
$userResult = $conn->query("SELECT id, username FROM users ORDER BY username");
if (!$userResult) {
    die("User query failed: " . $conn->error);
}
$users = $userResult->fetch_all(MYSQLI_ASSOC);

// Fetch Traccar devices
$traccarUsername = 'it@avegabros.com';
$traccarPassword = 'it@v3ga_gWafu';

$ch = curl_init("http://127.0.0.1:8082/api/devices");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERPWD, "$traccarUsername:$traccarPassword");
$response = curl_exec($ch);
curl_close($ch);

$devices = json_decode($response, true);
if (!is_array($devices)) {
    $devices = [];
}

// Map assigned devices
$userDeviceMap = [];
$result = $conn->query("SELECT user_id, device_id FROM user_devices");
while ($row = $result->fetch_assoc()) {
    $userDeviceMap[$row['user_id']][] = $row['device_id'];
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Assign Devices</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="container py-4">
    <h2 class="mb-4">Assign Devices to Users</h2>
<a href="dashboard.php" class="btn btn-secondary mb-3">← Back to Home</a>


    <form method="POST" class="row g-3 mb-4">
        <div class="col-md-4">
            <label for="user_id" class="form-label">User</label>
            <select name="user_id" id="user_id" class="form-select" required>
                <option value="">Select user</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['username']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label for="device_id" class="form-label">Device</label>
            <select name="device_id" id="device_id" class="form-select" required>
                <option value="">Select device</option>
                <?php foreach ($devices as $device): ?>
                    <option value="<?= $device['id'] ?>"><?= htmlspecialchars($device['name'] ?? 'Device ' . $device['id']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4 align-self-end">
            <button type="submit" class="btn btn-primary w-100">Assign Device</button>
        </div>
    </form>

    <h4>Assigned Devices</h4>
    <?php foreach ($users as $u):
        $uid = $u['id'];
        $uname = htmlspecialchars($u['username']);
        $assigned = $userDeviceMap[$uid] ?? [];
        if (empty($assigned)) continue;
    ?>
        <div class="card mb-3">
            <div class="card-header bg-secondary text-white"><?= $uname ?></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($assigned as $did):
                    $dev = array_filter($devices, fn($d) => $d['id'] == $did);
                    $dname = $dev ? htmlspecialchars(array_values($dev)[0]['name'] ?? "Device $did") : "Device $did";
                ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <?= $dname ?>
                        <a href="?remove=1&user=<?= $uid ?>&device=<?= $did ?>" class="btn btn-sm btn-danger">Remove</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endforeach; ?>
</body>
</html>
