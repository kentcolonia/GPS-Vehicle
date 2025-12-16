<?php
// === gpsmap/approvals.php ===

session_start();

// --- Redirect to login if not authenticated or if not an admin ---
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

// --- Get User Details from Session ---
$currentUsername = $_SESSION['username'];
$currentRole = $_SESSION['role'];
$currentUserId = null;

$stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
if ($stmt) {
    $stmt->bind_param("s", $currentUsername);
    $stmt->execute();
    $result = $stmt->get_result();
    $userRow = $result->fetch_assoc();
    $currentUserId = $userRow['id'] ?? null;
    $stmt->close();
}

if ($currentUserId === null) {
    die("Could not identify user. Please log out and log back in.");
}

// --- NOTIFICATION MESSAGES ---
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);


// --- ACTION HANDLING (APPROVE) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'], $_GET['ticket_id'])) {
    $action = $_GET['action'];
    $ticketId = filter_input(INPUT_GET, 'ticket_id', FILTER_VALIDATE_INT);
    $newStatus = '';

    if ($action === 'approve') {
        $newStatus = 'Approved';
    }

    if ($ticketId && $newStatus) {
        // --- Start Transaction for Data Integrity ---
        $conn->begin_transaction();

        try {
            // 1. Get the details of the ticket being approved
            $device_id = null;
            $start_odometer = null;
            $stmt_get_ticket = $conn->prepare("SELECT device_id, start_odometer FROM trip_tickets WHERE id = ?");
            $stmt_get_ticket->bind_param("i", $ticketId);
            $stmt_get_ticket->execute();
            $result_ticket = $stmt_get_ticket->get_result();
            if ($ticket_to_approve = $result_ticket->fetch_assoc()) {
                $device_id = $ticket_to_approve['device_id'];
                $start_odometer = $ticket_to_approve['start_odometer'];
            }
            $stmt_get_ticket->close();

            // 2. Approve the current ticket
            $stmt_approve = $conn->prepare("UPDATE trip_tickets SET status = ?, approver_id = ?, approval_date = NOW() WHERE id = ? AND status = 'Pending'");
            $stmt_approve->bind_param("sii", $newStatus, $currentUserId, $ticketId);
            $stmt_approve->execute();
            
            // Check if the approval was successful
            if ($stmt_approve->affected_rows > 0) {
                 $_SESSION['success_message'] = "Ticket status updated to '{$newStatus}'.";

                // 3. If approval was successful and this ticket has a start odo, update the previous ticket's end odo
                if ($device_id && $start_odometer !== null) {
                    // This query finds the most recent previous ticket for the same vehicle
                    // that is older than the current one and still needs its end_odometer filled.
                    $stmt_update_prev = $conn->prepare(
                        "UPDATE trip_tickets 
                         SET end_odometer = ? 
                         WHERE device_id = ? AND id < ? AND end_odometer IS NULL 
                         ORDER BY id DESC 
                         LIMIT 1"
                    );
                    $stmt_update_prev->bind_param("dii", $start_odometer, $device_id, $ticketId);
                    $stmt_update_prev->execute();
                    $stmt_update_prev->close();
                }

            } else {
                 $_SESSION['error_message'] = "Failed to update ticket status. It might have been already processed.";
            }
            $stmt_approve->close();

            // If all queries were successful, commit the changes
            $conn->commit();

        } catch (mysqli_sql_exception $exception) {
            // If any query fails, roll back all changes
            $conn->rollback();
            $_SESSION['error_message'] = "A database error occurred: " . $exception->getMessage();
        }

    } else {
        $_SESSION['error_message'] = "Invalid action or ticket ID.";
    }
    header("Location: approvals.php"); // Redirect back to the approvals page
    exit();
}


// --- DATA FETCHING FOR DISPLAY (Pending and Approved tickets) ---
$approval_tickets = [];
$sql = "SELECT 
            tt.id, tt.tt_number, tt.trip_date, tt.driver_name, tt.destination_from, tt.destination_to, tt.status,
            tt.purpose, tt.custodian,
            dd.display_name AS vehicle_name,
            u_creator.username AS created_by
        FROM trip_tickets tt
        JOIN device_details dd ON tt.device_id = dd.device_id
        JOIN users u_creator ON tt.created_by_id = u_creator.id
        WHERE tt.status IN ('Pending', 'Approved')
        ORDER BY FIELD(tt.status, 'Pending', 'Approved'), tt.trip_date ASC, tt.id ASC";

$ticketResult = $conn->query($sql);
if ($ticketResult) {
    while ($row = $ticketResult->fetch_assoc()) {
        $approval_tickets[] = $row;
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approvals Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #e74c3c;
            --dark-blue: #2c3e50;
            --light-gray: #ecf0f1;
            --text-color: #34495e;
            --white: #ffffff;
            --border-color: #bdc3c7;
            --approve-color: #27ae60;
            --print-color: #8e44ad;
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
        .container { max-width: 1280px; margin: 0 auto; padding: 0 15px; }
        
        .card { background: var(--white); border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); padding: 25px; }
        .card-header { border-bottom: 1px solid #e9ecef; padding-bottom: 15px; margin-bottom: 20px; }
        .card-header h2 { margin: 0; font-size: 1.5em; color: var(--dark-blue); }
        
        .btn { padding: 10px 20px; border: 2px solid transparent; border-radius: 8px; font-weight: 600; font-size: 0.95em; cursor: pointer; transition: all .2s ease; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
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

        .action-buttons { display: flex; flex-wrap: wrap; gap: 5px; }
        .action-buttons a { font-size: 0.8em; padding: 6px 12px; color: white; border-radius: 6px; text-align: center; }
        .btn-approve { background-color: var(--approve-color); text-decoration: none; }
        
        .ticket-info { font-size: 0.9em; }
        .ticket-info strong { color: var(--dark-blue); }
        .ticket-info a { color: var(--dark-blue); text-decoration: underline; font-weight: bold; }
        .ticket-info a:hover { color: var(--primary-color); }
    </style>
</head>
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
                <div class="card-header"><h2>Tickets for Approval</h2></div>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Details</th>
                                <th>Destination</th>
                                <th>Purpose</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($approval_tickets)): ?>
                                <tr><td colspan="5" style="text-align: center; padding: 20px;">No tickets are currently awaiting action.</td></tr>
                            <?php else: ?>
                                <?php foreach ($approval_tickets as $ticket): ?>
                                    <tr>
                                        <td class="ticket-info">
                                            <strong>TT#:</strong> 
                                            <?php if ($ticket['status'] === 'Approved'): ?>
                                                <a href="print_ticket.php?ticket_id=<?php echo $ticket['id']; ?>" target="_blank" title="View/Print Ticket"><?php echo htmlspecialchars($ticket['tt_number']); ?></a>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($ticket['tt_number']); ?>
                                            <?php endif; ?>
                                            <br>
                                            <strong>Date:</strong> <?php echo date("M d, Y", strtotime($ticket['trip_date'])); ?><br>
                                            <strong>Vehicle:</strong> <?php echo htmlspecialchars($ticket['vehicle_name']); ?><br>
                                            <strong>Driver:</strong> <?php echo htmlspecialchars($ticket['driver_name']); ?><br>
                                            <strong>Requested by:</strong> <?php echo htmlspecialchars($ticket['created_by']); ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($ticket['destination_from']) . ' &rarr; ' . htmlspecialchars($ticket['destination_to']); ?></td>
                                        <td><?php echo nl2br(htmlspecialchars($ticket['purpose'])); ?></td>
                                        <td><span class="status-badge status-<?php echo strtolower(htmlspecialchars($ticket['status'])); ?>"><?php echo htmlspecialchars($ticket['status']); ?></span></td>
                                        <td class="action-buttons">
                                            <?php if ($ticket['status'] === 'Pending'): ?>
                                                <a href="approvals.php?action=approve&ticket_id=<?php echo $ticket['id']; ?>" class="btn-approve">Approve</a>
                                            <?php endif; // No action needed for 'Approved' status in this column ?>
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
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const notification = document.getElementById('notification-bar');
            if (notification) {
                setTimeout(() => {
                    notification.style.transition = 'opacity 0.5s ease';
                    notification.style.opacity = '0';
                    setTimeout(() => notification.remove(), 500);
                }, 4000);
            }
        });
    </script>
</body>
</html>
