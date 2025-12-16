<?php
// === gpsmap/dashboard.php ===

session_start();

// --- CONFIGURATION ---
// IMPORTANT: For security, consider moving these credentials to a separate, non-public file.
$traccar_host = 'http://10.10.0.3:8082'; // Using IP address
$traccar_user = 'it@avegabros.com';
$traccar_pass = 'it@v3ga_gWafu';

/**
 * Fetches data from the Traccar API using cURL.
 * @param string $endpoint The API endpoint (e.g., "/api/devices").
 * @param string $username The Traccar username.
 * @param string $password The Traccar password.
 * @return array|null Decoded JSON data or null on failure.
 */
function traccarApi($endpoint, $username, $password) {
    global $traccar_host;
    $url = $traccar_host . $endpoint;
    
    $ch = curl_init($url);
    if ($ch === false) {
        error_log("cURL initialization failed for URL: " . htmlspecialchars($url));
        return null;
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log("cURL error for URL " . htmlspecialchars($url) . ": " . curl_error($ch));
        curl_close($ch);
        return null;
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) {
        error_log("Traccar API returned HTTP $httpCode for URL " . htmlspecialchars($url));
        return null;
    }
    return json_decode($response, true);
}

/**
 * Merges local device details (display name, plate number) with data from Traccar.
 * @param array $traccarDevices The array of devices from the Traccar API.
 * @param mysqli $conn The database connection object.
 * @return array The merged device array.
 */
function mergeLocalDeviceDetails($traccarDevices, $conn) {
    if (empty($traccarDevices)) {
        return [];
    }
    
    $deviceDetails = [];
    // Fetches custom names and plate numbers from the local database
    $detailsResult = $conn->query("SELECT device_id, display_name, plate_number FROM device_details");
    if ($detailsResult) {
        while ($row = $detailsResult->fetch_assoc()) {
            $deviceDetails[$row['device_id']] = [
                'display_name' => $row['display_name'],
                'plate_number' => $row['plate_number']
            ];
        }
    }

    // Merges the local details into the device data from Traccar
    foreach ($traccarDevices as &$device) {
        if (isset($deviceDetails[$device['id']])) {
            $details = $deviceDetails[$device['id']];
            if (!empty($details['display_name'])) {
                $device['name'] = $details['display_name']; // Overwrite Traccar name with custom name
            }
            $device['plate_number'] = $details['plate_number'] ?? '';
        } else {
            $device['plate_number'] = ''; // Ensure plate_number key exists
        }
    }
    return $traccarDevices;
}


// --- REAL-TIME POLLING ENDPOINT ---
// Handles AJAX requests for live data updates
if (isset($_GET['action']) && $_GET['action'] === 'get_live_data') {
    if (!isset($_SESSION['logged_in']) || !isset($_SESSION['user_device_ids'])) {
        http_response_code(401); // Unauthorized
        echo json_encode(['error' => 'Not authenticated or session expired']);
        exit();
    }

    $deviceIds = $_SESSION['user_device_ids'];

    // Fetch live data from Traccar
    $live_devices = traccarApi('/api/devices', $traccar_user, $traccar_pass) ?: [];
    $live_positions = traccarApi('/api/positions', $traccar_user, $traccar_pass);

    // Filter data based on user role (non-admins see only their assigned devices)
    if ($_SESSION['role'] !== 'admin') {
        if (!empty($live_devices)) {
            $live_devices = array_filter($live_devices, fn($dev) => in_array($dev['id'], $deviceIds));
        }
        if (!empty($live_positions)) {
            $live_positions = array_filter($live_positions, fn($pos) => in_array($pos['deviceId'], $deviceIds));
        }
    }

    // Merge custom device names from local DB
    $conn = new mysqli("localhost", "gpsuser", "gpspassword", "gps_tracker");
    if (!$conn->connect_error) {
        $live_devices = mergeLocalDeviceDetails($live_devices, $conn);
        $conn->close();
    }

    header('Content-Type: application/json');
    echo json_encode([
        'positions' => array_values($live_positions ?? []),
        'devices' => array_values($live_devices ?? [])
    ]);
    exit();
}


// --- INITIAL PAGE LOAD DATA FETCHING ---
if (!isset($_SESSION['logged_in'])) {
    header("Location: login.php");
    exit();
}

// Establish database connection
$conn = new mysqli("localhost", "gpsuser", "gpspassword", "gps_tracker");
if ($conn->connect_error) {
    die("<h1>Service Unavailable</h1><p>Could not connect to the database.</p>");
}

// Get user details from session
$currentUser = $_SESSION['username'] ?? 'Guest';
$currentRole = $_SESSION['role'] ?? 'user';
$_SESSION['role'] = $currentRole;
$userId = null;
if ($currentUser !== 'Guest') {
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    if ($stmt) {
        $stmt->bind_param("s", $currentUser);
        $stmt->execute();
        $result = $stmt->get_result();
        $userRow = $result->fetch_assoc();
        $userId = $userRow['id'] ?? null;
        $stmt->close();
    }
}

// Fetch initial device list from Traccar and merge with local data
$initial_devices = traccarApi('/api/devices', $traccar_user, $traccar_pass) ?? [];
$initial_devices = mergeLocalDeviceDetails($initial_devices, $conn);

$user_devices = [];
$user_device_ids = [];

// Determine which devices the current user can see
if ($currentRole === 'admin') {
    $user_devices = $initial_devices;
} else {
    if ($userId !== null) {
        $stmt = $conn->prepare("SELECT device_id FROM user_devices WHERE user_id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $user_device_ids[] = (int)$row['device_id'];
            }
            $stmt->close();
        }
    }
    if (!empty($initial_devices)) {
        $user_devices = array_filter($initial_devices, fn($device) => in_array($device['id'], $user_device_ids));
    }
}

// Store the list of visible device IDs in the session for the polling endpoint
if ($currentRole === 'admin' && !empty($initial_devices)) {
    $user_device_ids = array_map(fn($d) => $d['id'], $initial_devices);
}
$_SESSION['user_device_ids'] = $user_device_ids;

// Fetch initial positions and map them by deviceId for quick lookup
$initial_positions = traccarApi('/api/positions', $traccar_user, $traccar_pass);
$positionMap = [];
if (is_array($initial_positions)) {
    foreach ($initial_positions as $pos) {
        if (isset($pos['deviceId']) && in_array($pos['deviceId'], $user_device_ids)) {
            $positionMap[$pos['deviceId']] = $pos;
        }
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GPS Tracker Dashboard</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #e74c3c;
            --dark-blue: #2c3e50;
            --light-gray: #ecf0f1;
            --text-color: #34495e;
            --white: #ffffff;
            --border-color: #bdc3c7;
        }
        body { margin:0; font-family:'Inter', sans-serif; height:100vh; overflow:hidden; display:flex; flex-direction:column; background-color:var(--light-gray); }
        
        /* Navbar Styles */
        #navbar {
            background-color: var(--white);
            color: var(--text-color);
            padding: 0 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            z-index: 1000;
            height: 65px;
            border-bottom: 1px solid #ddd;
        }
        #navbar .menu { display: flex; align-items: center; height: 100%; }
        #navbar .logo-container { display: flex; align-items: center; padding-right: 25px; border-right: 1px solid #e0e0e0; height: 100%; }
        #navbar .logo { height: 35px; }
        #navbar .nav-links { display: flex; align-items: center; height: 100%; margin-left: 15px; }
        #navbar .nav-links a, #navbar .user-account a.dropbtn {
            cursor: pointer;
            padding: 0 15px;
            border-bottom: 3px solid transparent;
            transition: all .3s ease;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95em;
            color: var(--text-color);
            text-decoration: none;
            height: 100%;
        }
        #navbar .nav-links a:hover, 
        #navbar .nav-links a.selected,
        #navbar .dropdown .dropbtn.selected {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
            background-color: #f8f9fa;
        }
        #navbar .nav-links img { width: 20px; height: 20px; }
        
        /* Dropdown Styles */
        .dropdown {
            position: relative;
            display: inline-block;
            height: 100%;
        }
        .dropdown .dropbtn {
            height: 100%;
        }
        .user-account .dropdown .dropbtn:hover {
             background-color: #f8f9fa;
        }
        .dropdown-content {
            display: none;
            position: absolute;
            background-color: var(--white);
            min-width: 220px;
            box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.1);
            z-index: 1001;
            border-radius: 8px;
            border: 1px solid #ddd;
            overflow: hidden;
            right: 0;
            top: 65px; /* Position below navbar */
        }
        .dropdown.show .dropdown-content {
            display: block;
        }
        .dropdown-content a {
            color: var(--text-color);
            padding: 12px 16px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9em;
            font-weight: 500;
            transition: background-color 0.2s;
            height: auto;
            border-bottom: none;
        }
        .dropdown-content a img {
            width: 18px;
            height: 18px;
        }
        .dropdown-content a:hover {
            background-color: #f1f1f1;
            color: var(--primary-color);
        }
        .dropdown-content a.selected {
            background-color: #ffeeeb;
            font-weight: bold;
        }
        
        .user-account { display: flex; align-items: center; gap: 15px; }
        .user-avatar {
            width: 40px; height: 40px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: var(--white);
            display: flex;
            justify-content: center;
            align-items: center;
            font-weight: 600;
            font-size: 1.1em;
        }
        .user-details .user-name { font-weight: 600; }
        .user-details .user-role { font-size: 0.8em; color: #7f8c8d; }
        
        /* Main Layout */
        #main { flex:1; display:flex; height:calc(100% - 65px); position:relative; }
        #sidebar {
            width: 320px;
            background: var(--white);
            overflow-y: auto;
            border-right: 1px solid var(--border-color);
            box-shadow: 3px 0 10px rgba(0,0,0,.05);
            flex-shrink: 0;
            transition: transform .3s ease-in-out;
            position: absolute;
            left: 0; top: 0; bottom: 0;
            z-index: 500;
        }
        #sidebar.collapsed { transform: translateX(-100%); }
        #sidebar-content { padding: 20px; }
        #map { flex:1; height:100%; background-color:#e6e6e6; z-index:1; }
        
        /* Device List Styles */
        #device-list-header, #track-list-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        #device-list-header h3, #track-list-header h3 { margin: 0; font-size: 1.2em; }
        .device-item {
            cursor: pointer;
            padding: 12px;
            margin-bottom: 8px;
            background: #fdfdfd;
            border: 1px solid #e9e9e9;
            border-left: 4px solid var(--border-color);
            border-radius: 8px;
            transition: all .2s ease;
        }
        .device-item:hover { background: #f5faff; border-left-color: #3498db; }
        .device-item.selected { border-left-color: var(--primary-color); background-color: #fff5f5; }
        .device-name { font-weight: 600; display: flex; align-items: center; gap: 8px; }
        .device-details { font-size: 0.85em; color: #777; padding-left: 18px; margin-top: 4px; }
        .status-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
        .online { background:#2ecc71; }
        .offline { background:#95a5a6; }

        .device-marker-icon { transition: transform 0.2s linear; }

        /* Radar Effect CSS */
        .radar-marker {
            position: relative;
            width: 48px;
            height: 48px;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .radar-marker .radar-pulse {
            position: absolute;
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background-color: #a91618;
            opacity: 0.7;
            transform: scale(0);
            animation: radar-pulse 2s infinite;
        }
        .radar-marker .radar-icon-container {
            position: relative;
            z-index: 2;
        }
        @keyframes radar-pulse {
            0% {
                transform: scale(0.1);
                opacity: 0.7;
            }
            70% {
                transform: scale(1);
                opacity: 0;
            }
            100% {
                opacity: 0;
            }
        }
        
        /* Custom Map Controls Styling */
        .leaflet-bar.leaflet-custom-bar {
            background-color: rgba(230, 230, 230, 0.9);
            box-shadow: 0 1px 5px rgba(0,0,0,0.4);
            border-radius: 8px;
            border: 1px solid #bbb;
        }
        .leaflet-custom-bar a {
            background-color: transparent;
            border-bottom: 1px solid #bbb;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #333;
        }
        .leaflet-custom-bar a:last-child {
            border-bottom: none;
        }
        .leaflet-custom-bar a:hover {
            background-color: rgba(0,0,0,0.1);
        }
        .leaflet-custom-bar a.active {
            background-color: rgba(0, 0, 0, 0.15);
            color: #007bff;
        }

        .device-label-tooltip {
            background-color: rgba(255, 255, 255, 0.85);
            border: 1px solid #555;
            border-radius: 4px;
            padding: 3px 7px;
            font-weight: bold;
            box-shadow: 0 1px 4px rgba(0,0,0,0.3);
            color: #333;
        }

        /* Style for route point labels */
        .route-point-tooltip {
            background-color: transparent;
            border: none;
            box-shadow: none;
            font-size: 10px;
            padding: 2px 5px;
            white-space: nowrap;
            color: #000;
            font-weight: bold;
            text-shadow: 0px 0px 3px #fff, 0px 0px 3px #fff, 0px 0px 3px #fff;
        }
        
        /* Modal and other styles */
        .loading-overlay{position:absolute;top:0;left:0;width:100%;height:100%;background:rgba(255,255,255,.8);display:flex;flex-direction:column;justify-content:center;align-items:center;z-index:999;opacity:0;visibility:hidden;transition:all .3s ease}.loading-overlay.active{opacity:1;visibility:visible}.spinner{border:4px solid rgba(0,0,0,.1);border-left-color:#007bff;border-radius:50%;width:40px;height:40px;animation:spin 1s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}.loading-overlay p{margin-top:15px;font-size:1.1em;color:#333}.map-action-buttons{position:absolute;bottom:20px;left:50%;transform:translateX(-50%);z-index:401;display:flex;gap:10px}.map-action-buttons button{padding:10px 20px;border:none;border-radius:8px;font-weight:700;cursor:pointer;transition:all .2s ease;box-shadow:0 2px 5px rgba(0,0,0,.2)}.map-action-buttons .animate-button{background-color:#28a745;color:#fff}.map-action-buttons .animate-button:hover{background-color:#218838;transform:translateY(-2px)}.map-action-buttons .stop-button{background-color:#ffc107;color:#333}.map-action-buttons .stop-button:hover{background-color:#e0a800;transform:translateY(-2px)}#route-action-modal-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.6);display:flex;justify-content:center;align-items:center;z-index:1001;display:none}#route-action-modal{background:#fff;padding:25px 30px;border-radius:15px;box-shadow:0 8px 30px rgba(0,0,0,.3);width:90%;max-width:550px;transform:translateY(-20px);opacity:0;transition:opacity .3s ease,transform .3s ease}#route-action-modal.show{opacity:1;transform:translateY(0)}#route-action-modal h4{text-align:center;font-size:1.5em;color:#2c3e50;margin-top:0;margin-bottom:5px}.modal-description{text-align:center;margin-top:0;margin-bottom:25px;color:#6c757d;font-size:1em}.modal-action-choices{display:flex;justify-content:space-between;gap:20px}.modal-choice-card{background:#f8f9fa;border:1px solid #dee2e6;border-radius:12px;padding:20px;flex:1;cursor:pointer;transition:all .3s ease;display:flex;flex-direction:column;align-items:center;text-align:center}.modal-choice-card:hover{transform:translateY(-5px);box-shadow:0 8px 25px rgba(0,123,255,.15);border-color:#007bff}.modal-choice-card img{width:50px;height:50px;margin-bottom:15px;opacity:.8}.modal-choice-card h5{font-size:1.1em;font-weight:600;color:#0056b3;margin:0 0 8px}.modal-choice-card p{font-size:.9em;color:#495057;line-height:1.4;margin:0}.modal-footer-actions{margin-top:25px;width:100%;display:flex;justify-content:center}.date-range{margin:20px 0;display:flex;flex-direction:column;gap:15px}.date-range label{font-weight:700;color:#555}.date-range input[type=datetime-local]{width:100%;padding:10px;border:1px solid #ccc;border-radius:6px;box-sizing:border-box;transition:all .2s ease}.date-range input[type=datetime-local]:focus{border-color:#007bff;box-shadow:0 0 0 3px rgba(0,123,255,.2);outline:0}.modal-controls{display:flex;justify-content:flex-end;align-items:center;gap:10px}.modal-controls select{padding:10px;border-radius:6px;border:1px solid #ccc;background-color:#f8f9fa}.modal-controls button{padding:10px 20px;border:none;border-radius:8px;font-weight:700;cursor:pointer;transition:all .2s ease}#modalExecuteActionBtn{background-color:#007bff;color:#fff}#modalExecuteActionBtn:hover{background-color:#0056b3;transform:translateY(-2px)}.cancel-button{background-color:#6c757d;color:#fff}.cancel-button:hover{background-color:#5a6268;transform:translateY(-2px)}
    </style>
</head>
<body>
    <div id="navbar">
        <div class="menu">
            <div class="logo-container">
                <img src="icons/avlogo.png" class="logo" alt="Company Logo">
            </div>
            <div class="nav-links">
                <a id="nav-vehicles" class="selected" onclick="setView('vehicles')">
                    <img src="icons/vehicle.png" alt="Vehicles">
                    Vehicles
                </a>
            </div>
        </div>
        <div class="user-account">
            <!-- Subsystems Dropdown -->
            <div class="dropdown">
                <a class="dropbtn">
                    <img src="https://cdn-icons-png.flaticon.com/512/566/566001.png" alt="Subsystems Icon" style="width: 22px; height: 22px;">
                    Subsystems
                </a>
                <div class="dropdown-content">
                    <a id="nav-track" onclick="setView('track')">
                        <img src="https://cdn-icons-png.flaticon.com/512/684/684809.png" alt="Track Route">
                        Track Route
                    </a>
                    <a href="trip_ticket.php">
                        <img src="https://cdn-icons-png.flaticon.com/512/2662/2662523.png" alt="Trip Ticket">
                        Trip Ticket
                    </a>
                    <?php if ($currentRole === 'admin'): ?>
                        <a href="approvals.php">
                            <img src="https://cdn-icons-png.flaticon.com/512/190/190411.png" alt="Approvals">
                            Approvals
                        </a>
                        <a href="users.php">
                            <img src="https://cdn-icons-png.flaticon.com/512/3135/3135715.png" alt="Users">
                            Users
                        </a>
                         <a href="manage_vehicles.php">
                            <img src="icons/fleet-management.png" alt="Manage Vehicles">
                            Manage Vehicles
                        </a>
                        <a href="assign_devices.php">
                           <img src="https://cdn-icons-png.flaticon.com/512/2921/2921190.png" alt="Assign">
                            Assign
                        </a>
                      <a href="vehicle_logs.php">
                           <img src="https://cdn-icons-png.flaticon.com/512/32/32223.png" alt="Reports">
                            Reports
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="user-avatar"><?php echo strtoupper(substr($currentUser, 0, 1)); ?></div>
            <div class="user-details">
                <div class="user-name">Welcome</div>
                <div class="user-role"><?php echo htmlspecialchars(ucfirst($currentRole)); ?></div>
            </div>
            <a href="logout.php" title="Logout" style="color: #fff; background-color: #e74c3c; padding: 8px; border-radius: 50%; display:flex; align-items:center; justify-content:center;"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-box-arrow-right" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M10 12.5a.5.5 0 0 1-.5.5h-8a.5.5 0 0 1-.5-.5v-9a.5.5 0 0 1 .5-.5h8a.5.5 0 0 1 .5.5v2a.5.5 0 0 0 1 0v-2A1.5 1.5 0 0 0 9.5 2h-8A1.5 1.5 0 0 0 0 3.5v9A1.5 1.5 0 0 0 1.5 14h8a1.5 1.5 0 0 0 1.5-1.5v-2a.5.5 0 0 0-1 0v2z"/><path fill-rule="evenodd" d="M15.854 8.354a.5.5 0 0 0 0-.708l-3-3a.5.5 0 0 0-.708.708L14.293 7.5H5.5a.5.5 0 0 0 0 1h8.793l-2.147 2.146a.5.5 0 0 0 .708.708l3-3z"/></svg></a>
        </div>
    </div>

    <div id="main">
        <div id="sidebar">
            <div id="sidebar-content">
                <div id="vehicle-overview-content">
                    <div id="device-list-header">
                        <h3>Vehicles</h3>
                          <div class="filter-options">
                              <label><input type="checkbox" id="filterOnline" checked> Online</label>
                              <label><input type="checkbox" id="filterOffline" checked> Offline</label>
                          </div>
                    </div>
                    <div id="device-list"></div>
                </div>
                <div id="track-routes-content" style="display: none;">
                     <div id="track-list-header">
                        <h3>Track Route</h3>
                    </div>
                    <p id="track-instruction">Select a vehicle to see its history.</p>
                    <div id="track-device-list"></div>
                </div>
            </div>
        </div>
        <div id="map"></div>
    </div>
    
    <!-- Route Action Modal -->
    <div id="route-action-modal-overlay">
        <div id="route-action-modal">
            <div id="modal-initial-state">
                <h4>Choose Route Action</h4>
                <p class="modal-description">Select an action for the chosen device's route.</p>
                <div class="modal-action-choices">
                    <div class="modal-choice-card" id="modalSelectShowRouteBtn"><img src="icons/route-icon.gif" alt="Route Icon"><h5>Show Route Path</h5><p>Display the complete route on the map for a selected date range.</p></div>
                    <div class="modal-choice-card" id="modalSelectAnimateRouteBtn"><img src="icons/animate-icon.gif" alt="Animate Icon"><h5>Animate Route</h5><p>Playback the vehicle's movement along its path, like a video replay.</p></div>
                </div>
                <div class="modal-footer-actions"><button id="modalCancelBtn" class="cancel-button">Cancel</button></div>
            </div>
            <div id="modal-date-input-state" style="display: none;">
                <h4 id="modal-date-title">Set Date Range</h4>
                <div class="date-range"><label for="modalStartDate">From:</label><input type="datetime-local" id="modalStartDate" name="modalStartDate"><label for="modalEndDate">To:</label><input type="datetime-local" id="modalEndDate" name="modalEndDate"></div>
                <div class="modal-controls"><select id="modalSpeedSelect" aria-label="Animation Speed"><option value="1x">1x Speed</option><option value="2x" selected>2x Speed</option><option value="4x">4x Speed</option><option value="8x">8x Speed</option></select><button id="modalExecuteActionBtn">Go</button><button id="modalBackBtn" class="cancel-button">Back</button></div>
            </div>
        </div>
    </div>
    <div id="map-action-buttons" style="display: none;">
        <button id="animate-route-on-map-btn" class="animate-button">Animate Route</button>
        <button id="stop-animation-btn" class="stop-button">Stop Animation</button>
    </div>
    <div id="loading-overlay" class="loading-overlay">
        <div class="spinner"></div>
        <p>Loading route data...</p>
    </div>


    <script>
        // --- Global Variables ---
        var map = null;
        var deviceMarkers = {};
        var routePointMarkers = [];
        let devices = <?php echo json_encode(array_values($user_devices)); ?>;
        let positions = <?php echo json_encode($positionMap); ?>;
        let lastKnownCourse = {};
        let selectedDeviceId = null;
        let lastFetchedRouteData = null;
        let modalActionState = 'show';
        var currentRoutePolyline = null;
        var animationMarker = null;
        var animationInterval = null;
        let showTags = false;

        // --- DOM Cache ---
        const sidebar = document.getElementById('sidebar');
        const navVehicles = document.getElementById('nav-vehicles');
        const navTrackRoute = document.getElementById('nav-track');
        const vehicleOverviewContent = document.getElementById('vehicle-overview-content');
        const trackRoutesContent = document.getElementById('track-routes-content');
        const modalOverlay = document.getElementById('route-action-modal-overlay');
        const modal = document.getElementById('route-action-modal');
        const initialStateDiv = document.getElementById('modal-initial-state');
        const dateInputStateDiv = document.getElementById('modal-date-input-state');
        const speedSelect = document.getElementById('modalSpeedSelect');
        const modalExecuteBtn = document.getElementById('modalExecuteActionBtn');
        const mapActionButtons = document.getElementById('map-action-buttons');
        const animateOnMapBtn = document.getElementById('animate-route-on-map-btn');
        const stopAnimationBtn = document.getElementById('stop-animation-btn');
        const loadingOverlay = document.getElementById('loading-overlay');

        // --- Utility Functions ---
        function htmlspecialchars(str) {
            if (typeof str !== 'string' && typeof str !== 'number') return str;
            return String(str).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[m]);
        }
        
        function createDeviceIcon(rotation = 0) {
            const iconHtml = `
                <div class="radar-marker">
                    <div class="radar-pulse"></div>
                    <div class="radar-icon-container" style="transform: rotate(${rotation}deg); transform-origin: center center;">
                        <img src="https://cdn-icons-png.flaticon.com/512/10804/10804156.png" style="width: 24px; height: 24px;">
                    </div>
                </div>`;
            return L.divIcon({
                html: iconHtml,
                className: '',
                iconSize: [48, 48],
                iconAnchor: [24, 24]
            });
        }

        // --- View & Panel Logic ---
        function setView(viewName) {
            // Remove 'selected' from all top-level direct links and dropdown buttons
            document.querySelectorAll('#navbar .nav-links > a, #navbar .dropdown .dropbtn').forEach(el => el.classList.remove('selected'));
            // Remove 'selected' from items inside the dropdown content
            document.querySelectorAll('#navbar .dropdown-content a').forEach(el => el.classList.remove('selected'));

            const targetElement = document.getElementById(`nav-${viewName}`);
            if (targetElement) {
                targetElement.classList.add('selected'); // Highlight the specific item clicked (e.g., 'Track Route')
                const dropdown = targetElement.closest('.dropdown');
                if (dropdown) {
                    // If the item is inside a dropdown, also highlight the main dropdown button
                    dropdown.querySelector('.dropbtn').classList.add('selected');
                }
            }

            vehicleOverviewContent.style.display = (viewName === 'vehicles') ? 'block' : 'none';
            trackRoutesContent.style.display = (viewName === 'track') ? 'block' : 'none';
            
            sidebar.classList.remove('collapsed');
            
            clearMapElements();
            if (viewName === 'vehicles') {
                renderDeviceList('device-list', 'vehicles');
                showAllVehicleMarkers();
            } else { // This will handle the 'track' view
                renderDeviceList('track-device-list', 'track');
                hideAllVehicleMarkers();
            }
        }

        function renderDeviceList(targetListId, mode) {
            const list = document.getElementById(targetListId);
            list.innerHTML = '';
            const showOnline = document.getElementById('filterOnline').checked;
            const showOffline = document.getElementById('filterOffline').checked;

            if (!devices || devices.length === 0) {
                list.innerHTML = '<p style="padding: 10px; text-align: center; color: #777;">No devices found.</p>';
                return;
            }

            devices.forEach(device => {
                const pos = positions[device.id] ?? {};
                const status = device.status === 'online' ? 'online' : 'offline';
                if (mode === 'vehicles' && ((!showOnline && status === 'online') || (!showOffline && status === 'offline'))) return;

                const div = document.createElement('div');
                div.className = 'device-item';
                div.setAttribute('data-device-id', device.id);
                const speed = pos.speed?.toFixed(1) ?? "N/A";
                
                div.innerHTML = `
                    <div class="device-name">
                        <span class="status-dot ${status}"></span>
                        <span>${htmlspecialchars(device.name)}</span>
                    </div>
                    <div class="device-details">
                        Plate: ${htmlspecialchars(device.plate_number) || 'N/A'} | Speed: ${speed} km/h
                    </div>
                `;
                
                div.onclick = () => {
                    document.querySelectorAll('.device-item.selected').forEach(item => item.classList.remove('selected'));
                    div.classList.add('selected');
                    selectedDeviceId = device.id;

                    if (mode === 'vehicles') {
                        if (deviceMarkers[device.id]) {
                            map.setView(deviceMarkers[device.id].getLatLng(), 16);
                            deviceMarkers[device.id].openPopup();
                        }
                    } else if (mode === 'track') {
                        showInitialModal();
                    }
                };
                list.appendChild(div);
            });
        }

        function toggleDeviceTags() {
            showTags = !showTags;
            const tagControlButton = document.querySelector('.custom-tag-button');
            if (tagControlButton) {
                tagControlButton.classList.toggle('active', showTags);
            }
            
            for (const deviceId in deviceMarkers) {
                const marker = deviceMarkers[deviceId];
                if (showTags) {
                    marker.openTooltip();
                } else {
                    marker.closeTooltip();
                }
            }
        }

        // --- Map & Route Logic ---
        function hideAllVehicleMarkers() { Object.values(deviceMarkers).forEach(marker => map.removeLayer(marker)); }
        function showAllVehicleMarkers() { Object.values(deviceMarkers).forEach(marker => marker.addTo(map)); }
        function clearMapElements() {
            if (currentRoutePolyline) map.removeLayer(currentRoutePolyline);
            if (animationMarker) map.removeLayer(animationMarker);
            if (animationInterval) clearInterval(animationInterval);
            
            routePointMarkers.forEach(marker => map.removeLayer(marker));
            routePointMarkers = [];

            currentRoutePolyline = animationMarker = animationInterval = null;
            mapActionButtons.style.display = 'none';
        }
        
        function stopAnimation() {
            if (animationInterval) {
                clearInterval(animationInterval);
                animationInterval = null;
                if (animationMarker) map.removeLayer(animationMarker);
                animationMarker = null;
                animateOnMapBtn.style.display = 'block';
                stopAnimationBtn.style.display = 'none';
                console.log("Animation stopped.");
            }
        }

        function animateRouteWithData(data) {
            if (!data || data.length === 0) {
                alert("No route data available to animate.");
                return;
            }
            clearMapElements();
            hideAllVehicleMarkers();

            const latlngs = data.map(p => [p.latitude, p.longitude]);
            currentRoutePolyline = L.polyline(latlngs, { color: '#007bff', weight: 4, opacity: 0.3 }).addTo(map);
            const animatedProgressPolyline = L.polyline([], { color: '#ff0000', weight: 5, opacity: 0.9 }).addTo(map);

            let index = 0;
            const delay = { '1x': 1000, '2x': 500, '4x': 250, '8x': 100 }[speedSelect.value] || 500;
            
            animationMarker = L.marker(latlngs[0], { icon: createDeviceIcon() }).addTo(map);
            map.setView(latlngs[0], 15);

            mapActionButtons.style.display = 'flex';
            animateOnMapBtn.style.display = 'none';
            stopAnimationBtn.style.display = 'block';

            animationInterval = setInterval(() => {
                if (index >= data.length) {
                    clearInterval(animationInterval);
                    animationInterval = null;
                    stopAnimationBtn.style.display = 'none';
                    animateOnMapBtn.style.display = 'block';
                    if(animatedProgressPolyline) map.removeLayer(animatedProgressPolyline);
                    console.log("Route animation finished.");
                    return;
                }
                const pos = data[index];
                const latlng = [pos.latitude, pos.longitude];
                const rotation = pos.bearing ?? pos.course ?? 0;
                
                animationMarker.setLatLng(latlng);
                animationMarker.setIcon(createDeviceIcon(rotation));
                animatedProgressPolyline.addLatLng(latlng);
                
                map.panTo(latlng);
                index++;
            }, delay);
        }

        // --- Real-time Polling ---
        function fetchLiveUpdates() {
            fetch('dashboard.php?action=get_live_data')
                .then(response => response.ok ? response.json() : Promise.reject('Network Error'))
                .then(data => {
                    if (data.devices) devices = data.devices;
                    if (data.positions) data.positions.forEach(updateDevicePosition);
                    
                    if (vehicleOverviewContent.style.display !== 'none') {
                        renderDeviceList('device-list', 'vehicles');
                    }
                    if (trackRoutesContent.style.display !== 'none') {
                        renderDeviceList('track-device-list', 'track');
                    }
                })
                .catch(error => console.error("Error fetching live updates:", error));
        }

        function updateDevicePosition(position) {
            const deviceId = position.deviceId;
            let marker = deviceMarkers[deviceId];
            const device = devices.find(d => d.id === deviceId);
            
            if (!device) return;

            const rotation = position.course ?? lastKnownCourse[deviceId] ?? 0;
            if (position.speed > 0) lastKnownCourse[deviceId] = position.course;

            const icon = createDeviceIcon(rotation);

            if (!marker) {
                marker = L.marker([position.latitude, position.longitude], { icon: icon })
                    .bindPopup(`<b>${htmlspecialchars(device.name)}</b><br>Plate: ${htmlspecialchars(device.plate_number) || 'N/A'}`)
                    .bindTooltip(htmlspecialchars(device.name), {
                        permanent: false,
                        direction: 'top',
                        offset: [0, -24], // Adjust offset for new icon size
                        className: 'device-label-tooltip'
                    });
                
                if (navVehicles.classList.contains('selected')) {
                    marker.addTo(map);
                }

                deviceMarkers[deviceId] = marker;

                if (showTags) {
                    marker.openTooltip();
                }
            } else {
                 marker.setLatLng([position.latitude, position.longitude]);
                 marker.setIcon(icon);
                 marker.setTooltipContent(htmlspecialchars(device.name));
            }
            
            positions[deviceId] = position;
        }

        // --- Modal Logic ---
        function hideModal() {
            modal.classList.remove('show');
            setTimeout(() => {
                modalOverlay.style.display = 'none';
                dateInputStateDiv.style.display = 'none';
                initialStateDiv.style.display = 'block';
            }, 300);
        }

        function showInitialModal() {
            modalOverlay.style.display = 'flex';
            dateInputStateDiv.style.display = 'none';
            initialStateDiv.style.display = 'block';
            setTimeout(() => modal.classList.add('show'), 10);
        }

        function showDateInputState(mode) {
            modalActionState = mode;
            initialStateDiv.style.display = 'none';
            dateInputStateDiv.style.display = 'block';
            const modalDateTitle = document.getElementById('modal-date-title');

            if (mode === 'animate') {
                modalDateTitle.textContent = 'Set Date Range & Speed';
                speedSelect.style.display = 'block';
                modalExecuteBtn.textContent = 'Animate Route';
            } else {
                modalDateTitle.textContent = 'Set Date Range';
                speedSelect.style.display = 'none';
                modalExecuteBtn.textContent = 'Show Route';
            }
        }

        // --- Initial Load ---
        document.addEventListener('DOMContentLoaded', () => {
            const light = L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', { attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>' });
            const satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { attribution: 'Tiles &copy; Esri' });
            const standard = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors' });
            
            const mapLayers = [light, satellite, standard];
            let currentLayerIndex = 0;

            map = L.map('map', { center: [12.8797, 121.7740], zoom: 6, layers: [mapLayers[currentLayerIndex]], zoomControl: false });
            L.control.zoom({ position: 'bottomright' }).addTo(map);
            
            const customControl = L.control({ position: 'topright' });

            customControl.onAdd = function(map) {
                const container = L.DomUtil.create('div', 'leaflet-bar leaflet-control leaflet-custom-bar');
                L.DomEvent.disableClickPropagation(container);

                const fullscreenButton = L.DomUtil.create('a', 'custom-fullscreen-button', container);
                fullscreenButton.innerHTML = `<img src="https://cdn-icons-png.flaticon.com/512/722/722400.png" alt="Fullscreen Icon" style="width: 24px; height: 24px;">`;
                fullscreenButton.href = '#';
                fullscreenButton.title = 'Toggle Fullscreen';
                L.DomEvent.on(fullscreenButton, 'click', (e) => {
                    L.DomEvent.stop(e);
                    toggleFullScreen(document.getElementById('main'));
                });

                const layersButton = L.DomUtil.create('a', 'custom-layers-button', container);
                layersButton.innerHTML = `<img src="https://cdn-icons-png.flaticon.com/512/8860/8860871.png" alt="Layer Icon" style="width: 24px; height: 24px;">`;
                layersButton.href = '#';
                layersButton.title = 'Change Layers';
                L.DomEvent.on(layersButton, 'click', function(e) {
                    L.DomEvent.stop(e);
                    map.removeLayer(mapLayers[currentLayerIndex]);
                    currentLayerIndex = (currentLayerIndex + 1) % mapLayers.length;
                    map.addLayer(mapLayers[currentLayerIndex]);
                });

                const tagButton = L.DomUtil.create('a', 'custom-tag-button', container);
                tagButton.innerHTML = `<img src="https://cdn-icons-png.flaticon.com/512/1620/1620735.png" alt="Label Icon" style="width: 24px; height: 24px;">`;
                tagButton.href = '#';
                tagButton.title = 'Show/Hide Device Names';
                L.DomEvent.on(tagButton, 'click', (e) => {
                    L.DomEvent.stop(e);
                    toggleDeviceTags();
                });

                return container;
            };
            customControl.addTo(map);

            devices.forEach(device => {
                const pos = positions[device.id];
                if (pos?.latitude && pos?.longitude) {
                    updateDevicePosition(pos);
                }
            });
            setView('vehicles');

            // Dropdown toggle logic
            const dropdown = document.querySelector('.dropdown');
            if (dropdown) {
                const dropdownBtn = dropdown.querySelector('.dropbtn');
                dropdownBtn.addEventListener('click', function(event) {
                    dropdown.classList.toggle('show');
                });
            }
            // Close dropdown if clicked outside
            window.addEventListener('click', function(event) {
                if (!event.target.closest('.dropdown')) {
                    const openDropdown = document.querySelector('.dropdown.show');
                    if (openDropdown) {
                        openDropdown.classList.remove('show');
                    }
                }
            });

            document.getElementById('filterOnline').addEventListener('change', () => renderDeviceList('device-list', 'vehicles'));
            document.getElementById('filterOffline').addEventListener('change', () => renderDeviceList('device-list', 'vehicles'));
            
            document.getElementById('modalSelectShowRouteBtn').addEventListener('click', () => showDateInputState('show'));
            document.getElementById('modalSelectAnimateRouteBtn').addEventListener('click', () => showDateInputState('animate'));
            document.getElementById('modalBackBtn').addEventListener('click', () => {
                document.getElementById('modal-date-input-state').style.display = 'none';
                document.getElementById('modal-initial-state').style.display = 'block';
            });
            document.getElementById('modalCancelBtn').addEventListener('click', hideModal);
            modalOverlay.addEventListener('click', e => (e.target === modalOverlay) && hideModal());

            modalExecuteBtn.addEventListener('click', () => {
                if (!selectedDeviceId) return hideModal();
            
                const startDateValue = document.getElementById('modalStartDate').value;
                const endDateValue = document.getElementById('modalEndDate').value;

                if (!startDateValue || !endDateValue) {
                    return alert('Please select both a "From" and "To" date/time.');
                }

                const startDate = new Date(startDateValue).toISOString();
                const endDate = new Date(endDateValue).toISOString();

                loadingOverlay.classList.add('active');
                hideModal();

                fetch(`track.php?deviceId=${selectedDeviceId}&from=${encodeURIComponent(startDate)}&to=${encodeURIComponent(endDate)}`)
                    .then(res => {
                        if (!res.ok) throw new Error(`Server returned ${res.status}`);
                        return res.json();
                    })
                    .then(data => {
                        loadingOverlay.classList.remove('active');
                        if (!data || data.length === 0) return alert("No route data found for this device in the selected time range.");
                        
                        lastFetchedRouteData = data;
                        clearMapElements();
                        hideAllVehicleMarkers();

                        if (modalActionState === 'animate') {
                            animateRouteWithData(data);
                        } else {
                            const latlngs = data.map(p => [p.latitude, p.longitude]);
                            currentRoutePolyline = L.polyline(latlngs, { color: '#007bff', weight: 4, opacity: 0.7 }).addTo(map);
                            
                            data.forEach(point => {
                                const fixTime = new Date(point.fixTime);
                                const formattedDateTime = fixTime.toLocaleString('en-US', {
                                    month: '2-digit', day: '2-digit', year: 'numeric',
                                    hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true
                                }).replace(',', '');

                                // FIX: Removed device name from the label
                                const labelContent = formattedDateTime;

                                const pointMarker = L.circleMarker([point.latitude, point.longitude], {
                                    radius: 4, fillColor: "#ff7800", color: "#000",
                                    weight: 1, opacity: 1, fillOpacity: 0.8
                                });

                                pointMarker.bindTooltip(labelContent, {
                                    permanent: true, direction: 'top',
                                    offset: [0, -5], className: 'route-point-tooltip'
                                });
                                
                                routePointMarkers.push(pointMarker);
                                pointMarker.addTo(map);
                            });

                            map.fitBounds(currentRoutePolyline.getBounds());
                            mapActionButtons.style.display = 'flex';
                            animateOnMapBtn.style.display = 'block';
                            stopAnimationBtn.style.display = 'none';
                        }
                    })
                    .catch(error => {
                        loadingOverlay.classList.remove('active');
                        console.error("Error fetching track data:", error);
                        alert("Failed to load track data. Please check the date range or server connection.");
                    });
            });
            
            stopAnimationBtn.addEventListener('click', stopAnimation);
            animateOnMapBtn.addEventListener('click', () => {
                 if (!lastFetchedRouteData) {
                    alert("Please show a route first before animating.");
                    showInitialModal();
                 } else {
                    animateRouteWithData(lastFetchedRouteData);
                 }
            });

            setInterval(fetchLiveUpdates, 5000);
        });

        function toggleFullScreen(element) {
            if (!document.fullscreenElement) {
                element.requestFullscreen().catch(err => {
                    alert(`Error attempting to enable full-screen mode: ${err.message} (${err.name})`);
                });
            } else {
                if (document.exitFullscreen) {
                    document.exitFullscreen();
                }
            }
        }
    </script>
</body>
</ht