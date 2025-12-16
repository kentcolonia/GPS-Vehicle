<?php
// === gpsmap/trip_ticket.php ===

session_start();

// --- Redirect to login if not authenticated ---
if (!isset($_SESSION['logged_in'])) {
    header("Location: login.php");
    exit();
}

// --- Database Configuration ---
$db_host = "localhost";
$db_user = "gpsuser";
$db_pass = "gpspassword";
$db_name = "gps_tracker";

// Establish database connection
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die("<h1>Service Unavailable</h1><p>Could not connect to the database. Please try again later.</p>");
}

// --- Get User Details from Session ---
$currentUsername = $_SESSION['username'] ?? 'Guest';
$currentRole = $_SESSION['role'] ?? 'user';
$currentUserId = null;

if ($currentUsername !== 'Guest') {
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    if ($stmt) {
        $stmt->bind_param("s", $currentUsername);
        $stmt->execute();
        $result = $stmt->get_result();
        $userRow = $result->fetch_assoc();
        $currentUserId = $userRow['id'] ?? null;
        $stmt->close();
    }
}

if ($currentUserId === null) {
    die("Could not identify user. Please log out and log back in.");
}

// --- NOTIFICATION MESSAGES ---
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);


// --- ACTION HANDLING (DELETE TICKET) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'delete') {
    $ticketIdToDelete = filter_input(INPUT_GET, 'ticket_id', FILTER_VALIDATE_INT);

    if ($ticketIdToDelete) {
        $canDelete = false;

        if ($currentRole === 'admin') {
            $canDelete = true;
        } else {
            $stmtCheck = $conn->prepare("SELECT created_by_id, status FROM trip_tickets WHERE id = ?");
            $stmtCheck->bind_param("i", $ticketIdToDelete);
            $stmtCheck->execute();
            $resultCheck = $stmtCheck->get_result();

            if ($ticketData = $resultCheck->fetch_assoc()) {
                if ($ticketData['status'] === 'Pending' && $ticketData['created_by_id'] == $currentUserId) {
                    $canDelete = true;
                } else {
                    $_SESSION['error_message'] = "You do not have permission to delete this ticket. It may have already been approved or you are not the creator.";
                }
            } else {
                $_SESSION['error_message'] = "Ticket not found.";
            }
            $stmtCheck->close();
        }

        if ($canDelete) {
            $stmtDelete = $conn->prepare("DELETE FROM trip_tickets WHERE id = ?");
            $stmtDelete->bind_param("i", $ticketIdToDelete);
            if ($stmtDelete->execute()) {
                $_SESSION['success_message'] = "Ticket successfully deleted.";
            } else {
                $_SESSION['error_message'] = "Error deleting ticket: " . $stmtDelete->error;
            }
            $stmtDelete->close();
        }
    } else {
        $_SESSION['error_message'] = "Invalid ticket ID for deletion.";
    }
    header("Location: trip_ticket.php");
    exit();
}

// --- FORM SUBMISSION LOGIC (UPDATE TICKET) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_ticket'])) {
    $ticketId = filter_input(INPUT_POST, 'ticket_id', FILTER_VALIDATE_INT);
    $deviceId = filter_input(INPUT_POST, 'device_id', FILTER_VALIDATE_INT);
    $custodian = filter_input(INPUT_POST, 'custodian', FILTER_SANITIZE_STRING);
    $driverName = filter_input(INPUT_POST, 'driver_name', FILTER_SANITIZE_STRING);
    $tripDate = filter_input(INPUT_POST, 'trip_date', FILTER_SANITIZE_STRING);
    $destFrom = filter_input(INPUT_POST, 'destination_from', FILTER_SANITIZE_STRING);
    $destTo = filter_input(INPUT_POST, 'destination_to', FILTER_SANITIZE_STRING);
    $purpose = filter_input(INPUT_POST, 'purpose', FILTER_SANITIZE_STRING);
    $startOdometer = filter_input(INPUT_POST, 'start_odometer', FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
    $endOdometer = filter_input(INPUT_POST, 'end_odometer', FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);

    if (!$ticketId || !$deviceId || !$driverName || !$tripDate || !$destFrom || !$destTo || !$purpose) {
        $_SESSION['error_message'] = "All fields are required to update. Please fill out the form completely.";
    } else {
        $canEdit = false;
        $stmtCheck = $conn->prepare("SELECT created_by_id, status FROM trip_tickets WHERE id = ?");
        $stmtCheck->bind_param("i", $ticketId);
        $stmtCheck->execute();
        $resultCheck = $stmtCheck->get_result();
        if ($ticketData = $resultCheck->fetch_assoc()) {
            if ($ticketData['status'] === 'Pending' && ($currentRole === 'admin' || $ticketData['created_by_id'] == $currentUserId)) {
                $canEdit = true;
            }
        }
        $stmtCheck->close();

        if ($canEdit) {
            $stmt = $conn->prepare(
                "UPDATE trip_tickets SET 
                    device_id = ?, custodian = ?, driver_name = ?, trip_date = ?, 
                    destination_from = ?, destination_to = ?, purpose = ?, 
                    start_odometer = ?, end_odometer = ?
                 WHERE id = ?"
            );
            if ($stmt) {
                $stmt->bind_param("issssssddi", $deviceId, $custodian, $driverName, $tripDate, $destFrom, $destTo, $purpose, $startOdometer, $endOdometer, $ticketId);
                if ($stmt->execute()) {
                    $_SESSION['success_message'] = "Travel ticket updated successfully!";
                } else {
                    $_SESSION['error_message'] = "Error updating ticket: " . $stmt->error;
                }
                $stmt->close();
            } else {
                $_SESSION['error_message'] = "Database error: Could not prepare statement for update.";
            }
        } else {
            $_SESSION['error_message'] = "You do not have permission to edit this ticket, or it is no longer pending.";
        }
    }
    header("Location: trip_ticket.php");
    exit();
}


// --- FORM SUBMISSION LOGIC (CREATE TICKET) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_ticket'])) {
    $deviceId = filter_input(INPUT_POST, 'device_id', FILTER_VALIDATE_INT);
    $custodian = filter_input(INPUT_POST, 'custodian', FILTER_SANITIZE_STRING);
    $driverName = filter_input(INPUT_POST, 'driver_name', FILTER_SANITIZE_STRING);
    $tripDate = filter_input(INPUT_POST, 'trip_date', FILTER_SANITIZE_STRING);
    $destFrom = filter_input(INPUT_POST, 'destination_from', FILTER_SANITIZE_STRING);
    $destTo = filter_input(INPUT_POST, 'destination_to', FILTER_SANITIZE_STRING);
    $purpose = filter_input(INPUT_POST, 'purpose', FILTER_SANITIZE_STRING);
    $startOdometer = filter_input(INPUT_POST, 'start_odometer', FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
    $endOdometer = filter_input(INPUT_POST, 'end_odometer', FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);


    if (!$deviceId || !$driverName || !$tripDate || !$destFrom || !$destTo || !$purpose) {
        $_SESSION['error_message'] = "All fields are required. Please fill out the form completely.";
    } else {
        $datePrefix = date("Ym");
        $searchPrefix = $datePrefix . '-';
        $stmtSerial = $conn->prepare("SELECT tt_number FROM trip_tickets WHERE tt_number LIKE ? ORDER BY tt_number DESC LIMIT 1");
        $likePattern = $searchPrefix . "%";
        $stmtSerial->bind_param("s", $likePattern);
        $stmtSerial->execute();
        $resultSerial = $stmtSerial->get_result();
        
        $nextSerial = 1;
        if ($resultSerial->num_rows > 0) {
            $lastTtNumber = $resultSerial->fetch_assoc()['tt_number'];
            $serialPart = (int)substr($lastTtNumber, strpos($lastTtNumber, '-') + 1);
            $nextSerial = $serialPart + 1;
        }
        $stmtSerial->close();
        
        $tt_number = $searchPrefix . str_pad($nextSerial, 2, '0', STR_PAD_LEFT);

        $stmt = $conn->prepare(
            "INSERT INTO trip_tickets (tt_number, device_id, custodian, driver_name, trip_date, destination_from, destination_to, purpose, created_by_id, start_odometer, end_odometer) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if ($stmt) {
            $stmt->bind_param("sissssssidd", $tt_number, $deviceId, $custodian, $driverName, $tripDate, $destFrom, $destTo, $purpose, $currentUserId, $startOdometer, $endOdometer);
            if ($stmt->execute()) {
                $_SESSION['success_message'] = "Travel ticket created successfully! TT Number: " . htmlspecialchars($tt_number);
            } else {
                $_SESSION['error_message'] = "Error creating ticket: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $_SESSION['error_message'] = "Database error: Could not prepare statement.";
        }
    }
    header("Location: trip_ticket.php");
    exit();
}


// --- DATA FETCHING FOR DISPLAY ---
$vehicles = [];
$vehicleResult = $conn->query("SELECT device_id, display_name, plate_number FROM device_details ORDER BY display_name ASC");
if ($vehicleResult) {
    while ($row = $vehicleResult->fetch_assoc()) {
        $vehicles[] = $row;
    }
}

$trip_tickets = [];
$sql = "SELECT 
            tt.*,
            dd.display_name AS vehicle_name, dd.plate_number,
            u_creator.username AS created_by,
            u_approver.username AS approved_by
        FROM trip_tickets tt
        JOIN device_details dd ON tt.device_id = dd.device_id
        JOIN users u_creator ON tt.created_by_id = u_creator.id
        LEFT JOIN users u_approver ON tt.approver_id = u_approver.id";

if ($currentRole !== 'admin') {
    $sql .= " WHERE tt.created_by_id = ?";
}

$sql .= " ORDER BY tt.trip_date DESC, tt.id DESC";

$stmt = $conn->prepare($sql);

if ($currentRole !== 'admin') {
    $stmt->bind_param("i", $currentUserId);
}

$stmt->execute();
$ticketResult = $stmt->get_result();

if ($ticketResult) {
    while ($row = $ticketResult->fetch_assoc()) {
        $trip_tickets[] = $row;
    }
}
$stmt->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trip Ticket System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Correct Order for Map Libraries -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
    <link href="https://api.mapbox.com/mapbox-gl-js/v2.15.0/mapbox-gl.css" rel="stylesheet">
    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
    <script src="https://api.mapbox.com/mapbox-gl-js/v2.15.0/mapbox-gl.js"></script>
    <script src="https://unpkg.com/mapbox-gl-leaflet/leaflet-mapbox-gl.js"></script>

    <style>
        :root {
            --primary-color: #e74c3c;
            --dark-blue: #2c3e50;
            --light-gray: #ecf0f1;
            --text-color: #34495e;
            --white: #ffffff;
            --border-color: #bdc3c7;
            --success-color: #2ecc71;
            --error-color: #e74c3c;
            --approve-color: #27ae60;
            --edit-color: #f39c12;
        }
        body { margin:0; font-family:'Inter', sans-serif; height:100vh; overflow-y:auto; display:flex; flex-direction:column; background-color:var(--light-gray); }
        
        #navbar { background-color: var(--white); color: var(--text-color); padding: 0 25px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); z-index: 1000; height: 65px; border-bottom: 1px solid #ddd; }
        #navbar .menu { display: flex; align-items: center; height: 100%; }
        #navbar .logo-container { display: flex; align-items: center; padding-right: 25px; border-right: 1px solid #e0e0e0; height: 100%; }
        #navbar .logo { height: 35px; }
        #navbar .nav-links { display: flex; align-items: center; height: 100%; margin-left: 15px; }
        #navbar .nav-links a { cursor: pointer; padding: 0 15px; border-bottom: 3px solid transparent; transition: all .3s ease; font-weight: 600; display: flex; align-items: center; gap: 8px; font-size: 0.95em; color: var(--text-color); text-decoration: none; height: 100%; }
        #navbar .nav-links a:hover, #navbar .nav-links a.selected { color: var(--primary-color); border-bottom-color: var(--primary-color); background-color: #f8f9fa; }
        #navbar .nav-links img { width: 20px; height: 20px; }
        .user-account { display: flex; align-items: center; gap: 15px; }
        .user-avatar { width: 40px; height: 40px; border-radius: 50%; background-color: var(--primary-color); color: var(--white); display: flex; justify-content: center; align-items: center; font-weight: 600; font-size: 1.1em; }
        .user-details .user-name { font-weight: 600; }
        .user-details .user-role { font-size: 0.8em; color: #7f8c8d; }

        #main-content { padding: 30px; }
        .container { max-width: 1400px; margin: 0 auto; padding: 0 15px; }
        
        .card { background: var(--white); border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); padding: 25px; }
        .card-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e9ecef; padding-bottom: 15px; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;}
        .card-header h2 { margin: 0; font-size: 1.5em; color: var(--dark-blue); }
        
        .btn { padding: 10px 20px; border: 2px solid transparent; border-radius: 8px; font-weight: 600; font-size: 0.95em; cursor: pointer; transition: all .2s ease; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
        .btn-primary { background-color: var(--primary-color); color: var(--white); border-color: var(--primary-color); }
        .btn-primary:hover { background-color: #c0392b; border-color: #c0392b; transform: translateY(-2px); }
        .btn-secondary { background-color: var(--white); color: var(--text-color); border-color: #d1d5db; }
        .btn-secondary:hover { background-color: #f3f4f6; border-color: #9ca3af; color: var(--dark-blue); }

        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef; vertical-align: middle; }
        th { background-color: #f8f9fa; font-weight: 600; color: #495057; }
        tbody tr:hover { background-color: #f1f3f5; }
        
        .status-badge { padding: 4px 10px; border-radius: 15px; font-size: 0.8em; font-weight: 700; color: white; text-transform: uppercase; }
        .status-pending { background-color: #f39c12; }
        .status-approved { background-color: var(--approve-color); }

        .notification { padding: 15px; margin-bottom: 20px; border-radius: 8px; font-weight: 500; animation: fadeIn 0.5s; }
        .notification.success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .notification.error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        
        .approval-info { font-size: 0.85em; color: #7f8c8d; }
        .tt-number-link { color: var(--dark-blue); text-decoration: underline; font-weight: bold; }
        .tt-number-link:hover { color: var(--primary-color); }

        .action-cell { width: 1%; white-space: nowrap; display: flex; gap: 5px; }
        .btn-delete, .btn-edit { background: none; border: none; cursor: pointer; padding: 5px; font-size: 0.9em; border-radius: 5px; }
        .btn-delete { color: var(--primary-color); }
        .btn-delete:hover { background-color: #fbebeb; }
        .btn-edit { color: var(--edit-color); }
        .btn-edit:hover { background-color: #fef5e7; }

        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); justify-content: center; align-items: center; padding: 20px; backdrop-filter: blur(5px); }
        .modal-content { background-color: #fefefe; margin: auto; border-radius: 15px; box-shadow: 0 8px 25px rgba(0,0,0,0.2); width: 100%; max-width: 900px; animation: zoomIn 0.3s ease-out; }
        .modal-header { padding: 20px 30px; border-bottom: 1px solid #e9ecef; display: flex; justify-content: space-between; align-items: center; }
        .modal-header h2 { margin: 0; font-size: 1.6em; color: var(--dark-blue); }
        .close-button { color: #aaa; background: none; border: none; font-size: 32px; font-weight: bold; cursor: pointer; transition: color 0.2s; }
        .close-button:hover, .close-button:focus { color: var(--primary-color); }
        .modal-body { padding: 30px; max-height: 80vh; overflow-y: auto; }
        .modal-footer { padding: 15px 25px; background-color: #f8f9fa; border-top: 1px solid #e9ecef; display: flex; justify-content: flex-end; gap: 10px; border-bottom-left-radius: 15px; border-bottom-right-radius: 15px; }
        .modal-footer .btn { padding: 8px 16px; font-size: 0.9em; }

        .form-container { display: flex; gap: 20px; flex-wrap: wrap; }
        .form-fields { flex: 1 1 400px; }
        .map-container { flex: 1 1 400px; display: flex; flex-direction: column; }
        #destination-map { height: 100%; min-height: 400px; border-radius: 8px; border: 1px solid #ccc; }
        
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .form-group { margin-bottom: 0; }
        .form-group.full-width { grid-column: 1 / -1; position: relative; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 8px; color: var(--text-color); font-size: 0.9em; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 12px; border: 1px solid var(--border-color); border-radius: 8px; box-sizing: border-box; transition: all .2s ease; font-size: 1em; background-color: #fdfdfd; }
        
        .autocomplete-suggestions { border: 1px solid #ddd; background: #fff; position: absolute; top: 100%; left: 0; right: 0; z-index: 1010; max-height: 150px; overflow-y: auto; }
        .suggestion-item { padding: 10px; cursor: pointer; }
        .suggestion-item:hover { background-color: #f0f0f0; }
        .suggestion-item small { color: #555; }

        @keyframes zoomIn { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    </style>
</head>
<body>
    <div id="navbar">
        <div class="menu">
            <div class="logo-container">
                <img src="icons/avlogo.png" class="logo" alt="Company Logo">
            </div>
            <div class="nav-links">
                <a href="dashboard.php"><img src="icons/vehicle.png" alt="Map">Map</a>
                <a href="trip_ticket.php" class="selected"><img src="https://cdn-icons-png.flaticon.com/512/2662/2662523.png" alt="Trip Ticket">Trip Ticket</a>
                <?php if ($currentRole === 'admin'): ?>
                    <a href="approvals.php"><img src="https://cdn-icons-png.flaticon.com/512/190/190411.png" alt="Approvals">Approvals</a>
                    <a href="users.php"><img src="https://cdn-icons-png.flaticon.com/512/3135/3135715.png" alt="Users">Users</a>
                    <a href="manage_vehicles.php"><img src="icons/fleet-management.png" alt="Manage Vehicles">Manage Vehicles</a>
                    <a href="assign_devices.php"><img src="https://cdn-icons-png.flaticon.com/512/2921/2921190.png" alt="Assign">Assign</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="user-account">
            <div class="user-avatar"><?php echo strtoupper(substr($currentUsername, 0, 1)); ?></div>
            <div class="user-details">
                <div class="user-name">Welcome</div>
                <div class="user-role"><?php echo htmlspecialchars(ucfirst($currentRole)); ?></div>
            </div>
            <a href="logout.php" title="Logout" style="color: #fff; background-color: var(--primary-color); padding: 8px; border-radius: 50%; display:flex; align-items:center; justify-content:center;"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-box-arrow-right" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M10 12.5a.5.5 0 0 1-.5.5h-8a.5.5 0 0 1-.5-.5v-9a.5.5 0 0 1 .5-.5h8a.5.5 0 0 1 .5.5v2a.5.5 0 0 0 1 0v-2A1.5 1.5 0 0 0 9.5 2h-8A1.5 1.5 0 0 0 0 3.5v9A1.5 1.5 0 0 0 1.5 14h8a1.5 1.5 0 0 0 1.5-1.5v-2a.5.5 0 0 0-1 0v2z"/><path fill-rule="evenodd" d="M15.854 8.354a.5.5 0 0 0 0-.708l-3-3a.5.5 0 0 0-.708.708L14.293 7.5H5.5a.5.5 0 0 0 0 1h8.793l-2.147 2.146a.5.5 0 0 0 .708.708l3-3z"/></svg></a>
        </div>
    </div>

    <div id="main-content">
        <div class="container">
            
            <a href="dashboard.php" class="btn btn-secondary" style="margin-bottom: 20px;">&larr; Back to Map</a>

            <?php if ($success_message): ?>
                <div class="notification success" id="notification-bar"><?php echo $success_message; ?></div>
            <?php endif; ?>
            <?php if ($error_message): ?>
                <div class="notification error" id="notification-bar"><?php echo $error_message; ?></div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h2>Existing Travel Tickets</h2>
                    <button id="newTicketBtn" class="btn btn-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-plus-circle-fill" viewBox="0 0 16 16"><path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zM8.5 4.5a.5.5 0 0 0-1 0v3h-3a.5.5 0 0 0 0 1h3v3a.5.5 0 0 0 1 0v-3h3a.5.5 0 0 0 0-1h-3v-3z"/></svg>
                        New Travel Ticket
                    </button>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>TT Number</th>
                                <th>Date</th>
                                <th>Vehicle</th>
                                <th>Driver</th>
                                <th>Destination</th>
                                <th>Odometer</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($trip_tickets)): ?>
                                <tr><td colspan="8" style="text-align: center; padding: 20px;">No trip tickets found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($trip_tickets as $ticket): ?>
                                    <tr>
                                        <td>
                                            <?php if (in_array($ticket['status'], ['Approved', 'Completed'])): ?>
                                                <a href="print_ticket.php?ticket_id=<?php echo $ticket['id']; ?>" target="_blank" class="tt-number-link" title="View/Print Ticket">
                                                    <?php echo htmlspecialchars($ticket['tt_number']); ?>
                                                </a>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($ticket['tt_number']); ?>
                                            <?php endif; ?>
                                            <div class="approval-info">By: <?php echo htmlspecialchars($ticket['created_by']); ?></div>
                                        </td>
                                        <td><?php echo date("M d, Y", strtotime($ticket['trip_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($ticket['vehicle_name']); ?></td>
                                        <td><?php echo htmlspecialchars($ticket['driver_name']); ?></td>
                                        <td><?php echo htmlspecialchars($ticket['destination_from']) . ' &rarr; ' . htmlspecialchars($ticket['destination_to']); ?></td>
                                        <td>
                                            <?php
                                                echo 'Start: ' . ($ticket['start_odometer'] ? htmlspecialchars(number_format($ticket['start_odometer'], 1)) . ' km' : 'N/A') . '<br>';
                                                echo 'End: ' . ($ticket['end_odometer'] ? htmlspecialchars(number_format($ticket['end_odometer'], 1)) . ' km' : 'N/A');
                                            ?>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo strtolower(htmlspecialchars($ticket['status'])); ?>"><?php echo htmlspecialchars($ticket['status']); ?></span>
                                            <?php if ($ticket['approved_by']): ?>
                                                <div class="approval-info">By: <?php echo htmlspecialchars($ticket['approved_by']); ?></div>
                                                <div class="approval-info">On: <?php echo date("M d, Y h:i A", strtotime($ticket['approval_date'])); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="action-cell">
                                            <?php if ($ticket['status'] === 'Pending' && ($currentRole === 'admin' || $ticket['created_by_id'] == $currentUserId)): ?>
                                                <button class="btn-edit"
                                                    data-ticket-id="<?php echo $ticket['id']; ?>"
                                                    data-device-id="<?php echo $ticket['device_id']; ?>"
                                                    data-custodian="<?php echo htmlspecialchars($ticket['custodian']); ?>"
                                                    data-driver-name="<?php echo htmlspecialchars($ticket['driver_name']); ?>"
                                                    data-trip-date="<?php echo date('Y-m-d', strtotime($ticket['trip_date'])); ?>"
                                                    data-destination-from="<?php echo htmlspecialchars($ticket['destination_from']); ?>"
                                                    data-destination-to="<?php echo htmlspecialchars($ticket['destination_to']); ?>"
                                                    data-purpose="<?php echo htmlspecialchars($ticket['purpose']); ?>"
                                                    data-start-odometer="<?php echo $ticket['start_odometer']; ?>"
                                                    data-end-odometer="<?php echo $ticket['end_odometer']; ?>"
                                                    title="Edit Ticket">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-pencil-square" viewBox="0 0 16 16"><path d="M15.502 1.94a.5.5 0 0 1 0 .706L14.459 3.69l-2-2L13.502.646a.5.5 0 0 1 .707 0l1.293 1.293zm-1.75 2.456-2-2L4.939 9.21a.5.5 0 0 0-.121.196l-.805 2.414a.25.25 0 0 0 .316.316l2.414-.805a.5.5 0 0 0 .196-.12l6.813-6.814z"/><path fill-rule="evenodd" d="M1 13.5A1.5 1.5 0 0 0 2.5 15h11a1.5 1.5 0 0 0 1.5-1.5v-6a.5.5 0 0 0-1 0v6a.5.5 0 0 1-.5.5h-11a.5.5 0 0 1-.5-.5v-11a.5.5 0 0 1 .5-.5H9a.5.5 0 0 0 0-1H2.5A1.5 1.5 0 0 0 1 2.5v11z"/></svg>
                                                </button>
                                            <?php endif; ?>

                                            <?php if ($currentRole === 'admin' || ($ticket['status'] === 'Pending' && $ticket['created_by_id'] == $currentUserId)): ?>
                                                <a href="trip_ticket.php?action=delete&ticket_id=<?php echo $ticket['id']; ?>" class="btn-delete" onclick="return confirm('Are you sure you want to delete this ticket? This action cannot be undone.');" title="Delete Ticket">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-trash3-fill" viewBox="0 0 16 16"><path d="M11 1.5v1h3.5a.5.5 0 0 1 0 1h-.538l-.853 10.66A2 2 0 0 1 11.115 16h-6.23a2 2 0 0 1-1.994-1.84L2.038 3.5H1.5a.5.5 0 0 1 0-1H5v-1A1.5 1.5 0 0 1 6.5 0h3A1.5 1.5 0 0 1 11 1.5Zm-5 0v1h4v-1a.5.5 0 0 0-.5-.5h-3a.5.5 0 0 0-.5.5ZM4.5 5.029l.5 8.5a.5.5 0 1 0 .998-.06l-.5-8.5a.5.5 0 1 0-.998.06Zm3.5.029l.5 8.5a.5.5 0 1 0 .998-.06l-.5-8.5a.5.5 0 1 0-.998.06Zm3.5-.029l.5 8.5a.5.5 0 1 0 .998-.06l-.5-8.5a.5.5 0 1 0-.998.06Z"/></svg>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- The Modal -->
    <div id="ticketModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">New Travel Ticket</h2>
                <button class="close-button">&times;</button>
            </div>
            <form id="ticketForm" action="trip_ticket.php" method="POST">
                <input type="hidden" id="ticket_id" name="ticket_id" value="">
                <div class="modal-body">
                    <div class="form-container">
                        <div class="form-fields">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="device_id">Vehicle</label>
                                    <select id="device_id" name="device_id" required>
                                        <option value="">-- Select a Vehicle --</option>
                                        <?php foreach ($vehicles as $vehicle): ?>
                                            <option value="<?php echo $vehicle['device_id']; ?>"><?php echo htmlspecialchars($vehicle['display_name']) . ' (' . htmlspecialchars($vehicle['plate_number']) . ')'; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="trip_date">Date</label>
                                    <input type="date" id="trip_date" name="trip_date" required>
                                </div>
                                <div class="form-group">
                                    <label for="custodian">Custodian</label>
                                    <input type="text" id="custodian" name="custodian" placeholder="Enter custodian name">
                                </div>
                                <div class="form-group">
                                    <label for="driver_name">Assigned Driver</label>
                                    <input type="text" id="driver_name" name="driver_name" placeholder="Enter driver's full name" required>
                                </div>
                                <div class="form-group full-width">
                                    <label for="destination_from">Destination From</label>
                                    <input type="text" id="destination_from" name="destination_from" placeholder="Type to search..." required autocomplete="off">
                                    <div id="suggestions-from" class="autocomplete-suggestions"></div>
                                </div>
                                <div class="form-group full-width">
                                    <label for="destination_to">Destination To</label>
                                    <input type="text" id="destination_to" name="destination_to" placeholder="Type to search..." required autocomplete="off">
                                    <div id="suggestions-to" class="autocomplete-suggestions"></div>
                                </div>
                                <div class="form-group">
                                    <label for="start_odometer">Start Odometer (km)</label>
                                    <input type="number" step="0.1" id="start_odometer" name="start_odometer" placeholder="e.g., 12345.6">
                                </div>
                                <div class="form-group">
                                    <label for="end_odometer">End Odometer (km)</label>
                                    <input type="number" step="0.1" id="end_odometer" name="end_odometer" placeholder="e.g., 12399.9">
                                </div>
                                <div class="form-group full-width">
                                    <label for="purpose">Purpose</label>
                                    <textarea id="purpose" name="purpose" placeholder="Describe the purpose of the trip" required></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="map-container">
                            <div id="destination-map"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary close-button">Cancel</button>
                    <button type="submit" id="modalSubmitBtn" name="create_ticket" class="btn btn-primary">Create Ticket</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('ticketModal');
            const newTicketBtn = document.getElementById('newTicketBtn');
            const closeButtons = document.querySelectorAll('.close-button');
            const ticketForm = document.getElementById('ticketForm');
            const modalTitle = document.getElementById('modalTitle');
            const modalSubmitBtn = document.getElementById('modalSubmitBtn');
            const hiddenTicketIdInput = document.getElementById('ticket_id');
            
            let destinationMap = null;
            let fromMarker = null;
            let toMarker = null;
            let debounceTimer;

            function openModal() {
                modal.style.display = "flex";
                if (!destinationMap) {
                    destinationMap = L.map('destination-map').setView([10.3157, 123.8854], 12);
                    L.mapboxGL({
                        accessToken: 'pk.eyJ1Ijoia2NvbG9uaWEiLCJhIjoiY21kN2FremVnMGtjajJscHlubXUxdTFkdiJ9.oNxOHXJ5jbHfYJxi3PTEKw',
                        style: 'mapbox://styles/mapbox/streets-v11' // Using a standard Mapbox style
                    }).addTo(destinationMap);
                }
                setTimeout(() => destinationMap.invalidateSize(), 10);
            }

            function closeModal() {
                modal.style.display = "none";
            }

            newTicketBtn.onclick = function() {
                ticketForm.reset();
                modalTitle.textContent = 'New Travel Ticket';
                modalSubmitBtn.textContent = 'Create Ticket';
                modalSubmitBtn.name = 'create_ticket';
                hiddenTicketIdInput.value = '';
                if(fromMarker) destinationMap.removeLayer(fromMarker);
                if(toMarker) destinationMap.removeLayer(toMarker);
                fromMarker = null;
                toMarker = null;
                openModal();
            };

            const editButtons = document.querySelectorAll('.btn-edit');
            editButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const ticketData = this.dataset;

                    ticketForm.reset();
                    modalTitle.textContent = 'Edit Travel Ticket';
                    hiddenTicketIdInput.value = ticketData.ticketId;
                    document.getElementById('device_id').value = ticketData.deviceId;
                    document.getElementById('trip_date').value = ticketData.tripDate;
                    document.getElementById('custodian').value = ticketData.custodian;
                    document.getElementById('driver_name').value = ticketData.driverName;
                    document.getElementById('destination_from').value = ticketData.destinationFrom;
                    document.getElementById('destination_to').value = ticketData.destinationTo;
                    document.getElementById('purpose').value = ticketData.purpose;
                    document.getElementById('start_odometer').value = ticketData.startOdometer;
                    document.getElementById('end_odometer').value = ticketData.endOdometer;

                    modalSubmitBtn.textContent = 'Update Ticket';
                    modalSubmitBtn.name = 'update_ticket';

                    if(fromMarker) destinationMap.removeLayer(fromMarker);
                    if(toMarker) destinationMap.removeLayer(toMarker);
                    fromMarker = null;
                    toMarker = null;

                    openModal();
                });
            });

            closeButtons.forEach(button => button.onclick = closeModal);
            window.onclick = function(event) {
                if (event.target == modal) closeModal();
            };

            function setupAutocomplete(inputId, suggestionsId, targetType) {
                const input = document.getElementById(inputId);
                const suggestionsContainer = document.getElementById(suggestionsId);

                input.addEventListener('input', function() {
                    clearTimeout(debounceTimer);
                    const query = this.value;
                    if (query.length < 3) {
                        suggestionsContainer.innerHTML = '';
                        suggestionsContainer.style.display = 'none';
                        return;
                    }
                    debounceTimer = setTimeout(() => {
                        const searchUrl = `https://api.mapbox.com/geocoding/v5/mapbox.places/${encodeURIComponent(query)}.json?access_token=pk.eyJ1Ijoia2NvbG9uaWEiLCJhIjoiY21kN2FremVnMGtjajJscHlubXUxdTFkdiJ9.oNxOHXJ5jbHfYJxi3PTEKw&autocomplete=true&country=PH&limit=5`;
                        
                        fetch(searchUrl)
                            .then(response => response.json())
                            .then(data => {
                                suggestionsContainer.innerHTML = '';
                                if (data.features && data.features.length > 0) {
                                    suggestionsContainer.style.display = 'block';
                                    data.features.forEach(item => {
                                        const div = document.createElement('div');
                                        div.className = 'suggestion-item';
                                        div.innerHTML = `<strong>${item.text}</strong><br><small>${item.place_name}</small>`;
                                        div.onclick = () => {
                                            input.value = item.place_name;
                                            suggestionsContainer.innerHTML = '';
                                            suggestionsContainer.style.display = 'none';
                                            updateMapWithSelection({
                                                lat: item.center[1],
                                                lon: item.center[0],
                                                display_name: item.place_name
                                            }, targetType);
                                        };
                                        suggestionsContainer.appendChild(div);
                                    });
                                } else {
                                    suggestionsContainer.style.display = 'none';
                                }
                            });
                    }, 300);
                });
            }

            function updateMapWithSelection(item, targetType) {
                const latlng = L.latLng(item.lat, item.lon);
                let marker;
                if (targetType === 'from') {
                    if (fromMarker) fromMarker.setLatLng(latlng);
                    else fromMarker = L.marker(latlng).addTo(destinationMap);
                    marker = fromMarker;
                } else {
                    if (toMarker) toMarker.setLatLng(latlng);
                    else toMarker = L.marker(latlng).addTo(destinationMap);
                    marker = toMarker;
                }
                marker.bindPopup(item.display_name).openPopup();
                
                if (fromMarker && toMarker) {
                    const group = new L.featureGroup([fromMarker, toMarker]);
                    destinationMap.fitBounds(group.getBounds().pad(0.5));
                } else {
                    destinationMap.setView(latlng, 15);
                }
            }

            setupAutocomplete('destination_from', 'suggestions-from', 'from');
            setupAutocomplete('destination_to', 'suggestions-to', 'to');

            document.addEventListener('click', function(e) {
                if (!e.target.closest('.form-group')) {
                    document.getElementById('suggestions-from').style.display = 'none';
                    document.getElementById('suggestions-to').style.display = 'none';
                }
            });

            const dateInput = document.getElementById('trip_date');
            if(dateInput) {
                const today = new Date();
                const yyyy = today.getFullYear();
                const mm = String(today.getMonth() + 1).padStart(2, '0');
                const dd = String(today.getDate()).padStart(2, '0');
                dateInput.value = `${yyyy}-${mm}-${dd}`;
            }

            const notification = document.getElementById('notification-bar');
            if (notification) {
                setTimeout(() => {
                    notification.style.transition = 'opacity 0.5s ease';
                    notification.style.opacity = '0';
                    setTimeout(() => notification.remove(), 500);
                }, 4000);
            }

            const vehicleSelect = document.getElementById('device_id');
            const startOdometerInput = document.getElementById('start_odometer');

            if (vehicleSelect && startOdometerInput) {
                vehicleSelect.addEventListener('change', function() {
                    const deviceId = this.value;
                    if (!deviceId) {
                        startOdometerInput.value = '';
                        return;
                    }
                    fetch(`get_last_odometer.php?device_id=${deviceId}`)
                        .then(response => {
                            if (!response.ok) {
                                throw new Error('Network response was not ok');
                            }
                            return response.json();
                        })
                        .then(data => {
                            if (data && data.last_odometer !== null) {
                                startOdometerInput.value = data.last_odometer;
                            } else {
                                startOdometerInput.value = '';
                                startOdometerInput.placeholder = 'First trip, please enter manually';
                            }
                        })
                        .catch(error => {
                            console.error('Error fetching last odometer:', error);
                            startOdometerInput.value = '';
                        });
                });
            }
        });
    </script>
</body>
</html>
