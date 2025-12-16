<?php
// === gpsmap/vehicle_logs.php ===

session_start();

// --- Security Check: Ensure user is logged in and is an admin ---
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
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
    die("<h1>Service Unavailable</h1><p>Could not connect to the database.</p>");
}

// --- Fetch all vehicles for the filter dropdown ---
$vehicles = [];
$vehicleResult = $conn->query("SELECT device_id, display_name, plate_number FROM device_details ORDER BY display_name ASC");
if ($vehicleResult) {
    while ($row = $vehicleResult->fetch_assoc()) {
        $vehicles[] = $row;
    }
}

// --- Initialize variables for filtering ---
$filtered_tickets = [];
$selected_device_id = '';
$from_date = '';
$to_date = '';

// --- Handle Form Submission for Filtering ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected_device_id = filter_input(INPUT_POST, 'device_id', FILTER_VALIDATE_INT);
    $from_date = filter_input(INPUT_POST, 'from_date', FILTER_SANITIZE_STRING);
    $to_date = filter_input(INPUT_POST, 'to_date', FILTER_SANITIZE_STRING);

    if ($selected_device_id && $from_date && $to_date) {
        $sql = "SELECT 
                    tt.*,
                    dd.display_name AS vehicle_name,
                    u_creator.username AS created_by,
                    u_approver.username AS approved_by
                FROM trip_tickets tt
                JOIN device_details dd ON tt.device_id = dd.device_id
                JOIN users u_creator ON tt.created_by_id = u_creator.id
                LEFT JOIN users u_approver ON tt.approver_id = u_approver.id
                WHERE tt.device_id = ? AND tt.trip_date BETWEEN ? AND ?
                ORDER BY tt.trip_date DESC, tt.id DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iss", $selected_device_id, $from_date, $to_date);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $filtered_tickets[] = $row;
        }
        $stmt->close();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Ticket Logs</title>
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
        body { margin:0; font-family:'Inter', sans-serif; background-color:var(--light-gray); }
        
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
        .card-header { border-bottom: 1px solid #e9ecef; padding-bottom: 15px; margin-bottom: 20px; }
        .card-header h2 { margin: 0; font-size: 1.5em; color: var(--dark-blue); }
        
        .btn { padding: 10px 20px; border: 2px solid transparent; border-radius: 8px; font-weight: 600; font-size: 0.95em; cursor: pointer; transition: all .2s ease; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
        .btn-primary { background-color: var(--primary-color); color: var(--white); border-color: var(--primary-color); }
        .btn-primary:hover { background-color: #c0392b; border-color: #c0392b; transform: translateY(-2px); }
        .btn-secondary { background-color: var(--white); color: var(--text-color); border-color: #d1d5db; }
        .btn-secondary:hover { background-color: #f3f4f6; border-color: #9ca3af; color: var(--dark-blue); }

        .filter-form { display: flex; gap: 15px; align-items: flex-end; margin-bottom: 20px; flex-wrap: wrap; }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { font-weight: 600; margin-bottom: 5px; font-size: 0.9em; }
        .form-group select, .form-group input { padding: 10px; border-radius: 8px; border: 1px solid #ccc; }

        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef; vertical-align: middle; }
        th { background-color: #f8f9fa; font-weight: 600; color: #495057; }
        tbody tr:hover { background-color: #f1f3f5; }
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
                <a href="trip_ticket.php"><img src="https://cdn-icons-png.flaticon.com/512/2662/2662523.png" alt="Trip Ticket">Trip Ticket</a>
                <a href="vehicle_logs.php" class="selected"><img src="https://cdn-icons-png.flaticon.com/512/32/32223.png" alt="Vehicle Logs">Vehicle Logs</a>
                <?php if ($currentRole === 'admin'): ?>
                    <a href="approvals.php"><img src="https://cdn-icons-png.flaticon.com/512/190/190411.png" alt="Approvals">Approvals</a>
                    <a href="users.php"><img src="https://cdn-icons-png.flaticon.com/512/3135/3135715.png" alt="Users">Users</a>
                    <a href="manage_vehicles.php"><img src="icons/fleet-management.png" alt="Manage Vehicles">Manage Vehicles</a>
                    <a href="assign_devices.php"><img src="https://cdn-icons-png.flaticon.com/512/2921/2921190.png" alt="Assign">Assign</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="user-account">
            <!-- User account info -->
        </div>
    </div>

    <div id="main-content">
        <div class="container">
            <div class="card">
                <div class="card-header">
                    <h2>Vehicle Ticket Logs</h2>
                </div>
                
                <form method="POST" class="filter-form">
                    <div class="form-group">
                        <label for="device_id">Select Vehicle</label>
                        <select name="device_id" id="device_id" required>
                            <option value="">-- All Vehicles --</option>
                            <?php foreach ($vehicles as $vehicle): ?>
                                <option value="<?php echo $vehicle['device_id']; ?>" <?php echo ($selected_device_id == $vehicle['device_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($vehicle['display_name']) . ' (' . htmlspecialchars($vehicle['plate_number']) . ')'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="from_date">From</label>
                        <input type="date" name="from_date" id="from_date" value="<?php echo htmlspecialchars($from_date); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="to_date">To</label>
                        <input type="date" name="to_date" id="to_date" value="<?php echo htmlspecialchars($to_date); ?>" required>
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">Filter</button>
                    </div>
                </form>

                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>TT Number</th>
                                <th>Date</th>
                                <th>Driver</th>
                                <th>Destination</th>
                                <th>Purpose</th>
                                <th>Odometer (Start/End)</th>
                                <th>Status</th>
                                <th>Processed By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($filtered_tickets) && $_SERVER['REQUEST_METHOD'] === 'POST'): ?>
                                <tr><td colspan="8" style="text-align: center; padding: 20px;">No tickets found for the selected criteria.</td></tr>
                            <?php elseif (!empty($filtered_tickets)): ?>
                                <?php foreach ($filtered_tickets as $ticket): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($ticket['tt_number']); ?></td>
                                        <td><?php echo date("M d, Y", strtotime($ticket['trip_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($ticket['driver_name']); ?></td>
                                        <td><?php echo htmlspecialchars($ticket['destination_from']) . ' &rarr; ' . htmlspecialchars($ticket['destination_to']); ?></td>
                                        <td><?php echo htmlspecialchars($ticket['purpose']); ?></td>
                                        <td><?php echo ($ticket['start_odometer'] ? number_format($ticket['start_odometer'], 1) : 'N/A') . ' / ' . ($ticket['end_odometer'] ? number_format($ticket['end_odometer'], 1) : 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($ticket['status']); ?></td>
                                        <td>
                                            Requested: <?php echo htmlspecialchars($ticket['created_by']); ?><br>
                                            <?php if($ticket['approved_by']): ?>
                                                Approved: <?php echo htmlspecialchars($ticket['approved_by']); ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="8" style="text-align: center; padding: 20px;">Please select a vehicle and date range to view logs.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
