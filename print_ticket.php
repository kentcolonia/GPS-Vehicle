<?php
// === gpsmap/print_ticket.php ===

session_start();

// --- Redirect to login if not authenticated ---
if (!isset($_SESSION['logged_in'])) {
    header("Location: login.php");
    exit();
}

// --- Check if Ticket ID is provided ---
if (!isset($_GET['ticket_id']) || !filter_var($_GET['ticket_id'], FILTER_VALIDATE_INT)) {
    die("<h1>Error</h1><p>No valid ticket ID provided.</p>");
}
$ticketId = $_GET['ticket_id'];

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

// --- Fetch Ticket Data ---
$ticket = null;
// UPDATED: The SQL query now fetches the approver's first and last name and concatenates them.
$sql = "SELECT 
            tt.*,
            dd.display_name AS vehicle_name, dd.plate_number,
            u_creator.username AS created_by,
            CONCAT(u_approver.first_name, ' ', u_approver.last_name) AS approver_full_name
        FROM trip_tickets tt
        JOIN device_details dd ON tt.device_id = dd.device_id
        JOIN users u_creator ON tt.created_by_id = u_creator.id
        LEFT JOIN users u_approver ON tt.approver_id = u_approver.id
        WHERE tt.id = ? AND tt.status IN ('Approved', 'Completed')";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $ticketId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $ticket = $result->fetch_assoc();
    }
    $stmt->close();
}
$conn->close();

if ($ticket === null) {
    die("<h1>Ticket Not Found</h1><p>The requested ticket could not be found or has not been approved yet.</p>");
}

// Function to render the ticket content, avoiding code duplication
function render_ticket_content($ticket) {
?>
    <div class="header">
        <img src="icons/avlogo.png" alt="Company Logo" class="logo-img">
        <div class="company-info">
            <h1>AVEGA BROS. INTEGRATED SHIPPING CORP.</h1>
            Sitio, Baha-baha, Tayud, Consolacion, Cebu<br>
            TEL. NOS. 032 340-1802 / 032 437-6501 <br>
            www.avegabros.com
        </div>
    </div>
    
    <div class="sub-header">
         <div class="tt-no">
            <strong>TT No.:</strong> <?php echo htmlspecialchars($ticket['tt_number']); ?>
        </div>
    </div>

    <div class="title-section">
        <h2>TRAVEL TICKET</h2>
    </div>
    
    <div class="info-block">
        <div class="info-row">
            <div class="info-line">
                <label>Vehicle:</label>
                <span><?php echo htmlspecialchars($ticket['vehicle_name']); ?></span>
            </div>
            <div class="info-line">
                <label>Start Odo:</label>
                <span><?php echo $ticket['start_odometer'] ? htmlspecialchars(number_format($ticket['start_odometer'], 1)) . ' km' : 'N/A'; ?></span>
            </div>
        </div>
        <div class="info-row">
            <div class="info-line">
                <label>Plate No.:</label>
                <span><?php echo htmlspecialchars($ticket['plate_number']); ?></span>
            </div>
            <div class="info-line">
                <label>End Odo:</label>
                <span><?php echo $ticket['end_odometer'] ? htmlspecialchars(number_format($ticket['end_odometer'], 1)) . ' km' : 'N/A'; ?></span>
            </div>
        </div>
        <div class="info-row full-width">
             <div class="info-line">
                <label>Custodian:</label>
                <span><?php echo htmlspecialchars($ticket['custodian']); ?></span>
            </div>
        </div>
    </div>

    <table class="main-table">
        <thead>
            <tr>
                <th rowspan="2">Date</th>
                <th rowspan="2">Assigned Driver<br><span style="font-size: 10px;">(signature over PRINTED NAME)</span></th>
                <th colspan="2">Destination</th>
                <th rowspan="2">Purpose</th>
            </tr>
            <tr>
                <th>From</th>
                <th>To</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?php echo date("M d, Y", strtotime($ticket['trip_date'])); ?></td>
                <td><?php echo htmlspecialchars($ticket['driver_name']); ?></td>
                <td><?php echo htmlspecialchars($ticket['destination_from']); ?></td>
                <td><?php echo htmlspecialchars($ticket['destination_to']); ?></td>
                <td><?php echo nl2br(htmlspecialchars($ticket['purpose'])); ?></td>
            </tr>
        </tbody>
    </table>

    <div class="signatures">
        <div class="signature-box">
            <strong>Approved by:</strong>
            <div class="signature-line"></div>
            <!-- UPDATED: This now displays the approver's full name -->
            <div class="signature-name"><?php echo htmlspecialchars(strtoupper($ticket['approver_full_name'] ?? '')); ?></div>
            <div class="signature-label">Signature over PRINTED NAME</div>
            <strong>Date:</strong> <?php echo $ticket['approval_date'] ? date("M d, Y", strtotime($ticket['approval_date'])) : ''; ?>
        </div>
        <div class="signature-box">
            <strong>Acknowledged by:</strong>
            <div class="signature-line"></div>
            <div class="signature-label">Signature over PRINTED NAME</div>
            <strong>Date:</strong>
        </div>
    </div>
<?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Travel Ticket - <?php echo htmlspecialchars($ticket['tt_number']); ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .controls {
            margin-bottom: 20px;
            padding: 10px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            text-align: center;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            background-color: #2c3e50;
            color: white;
            font-size: 16px;
            cursor: pointer;
            transition: background-color 0.3s;
            text-decoration: none;
            margin: 0 5px;
        }
        .btn:hover {
            background-color: #e74c3c;
        }
        .ticket-container {
            width: 7.5in;
            height: 5in;
            padding: 0.25in;
            background: white;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            box-sizing: border-box;
            border: 1px solid #ccc;
            display: flex;
            flex-direction: column;
        }
        .copy-separator {
            width: 7.5in;
            border-top: 2px dashed #ccc;
            margin: 20px 0;
        }
        .header {
            display: flex;
            align-items: flex-start;
            padding-bottom: 1px;
            margin-bottom: 1px;
        }
        .logo-img {
            height: 60px;
            margin-right: 15px;
        }
        .company-info {
            font-size: 11px;
            text-align: left;
        }
        .company-info h1 {
            margin: 0 0 5px 0;
            font-size: 16px;
            color: #e74c3c;
            font-weight: bold;
        }
        .sub-header {
            text-align: right;
            margin-bottom: 10px;
        }
        .sub-header .tt-no {
            font-size: 14px;
            font-weight: bold;
        }
        .title-section {
            text-align: center;
            margin-bottom: 15px;
        }
        .title-section h2 {
            margin: 0;
            display: inline-block;
            font-size: 18px;
            font-weight: bold;
        }
        .info-block {
            font-size: 12px;
            margin-bottom: 15px;
        }
        .info-row {
            display: flex;
            width: 100%;
        }
        .info-line {
            display: flex;
            margin-bottom: 8px;
            width: 50%;
        }
        .info-line label {
            width: 90px;
            font-weight: bold;
        }
        .info-row.full-width .info-line {
            width: 100%;
        }
        .info-row.full-width .info-line span {
            max-width: calc(100% - 90px);
        }
        .main-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            margin-top: 15px;
            flex-grow: 1;
        }
        .main-table th, .main-table td {
            border: 1px solid black;
            padding: 5px;
        }
        .main-table th {
            background-color: #f2f2f2;
            text-align: center;
        }
        .signatures {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-top: 20px;
            font-size: 12px;
        }
        .signature-box {
            padding-top: 5px;
        }
        .signature-line {
            border-bottom: 1px solid black;
            padding-bottom: 5px;
            margin-bottom: 5px;
            min-height: 30px;
        }
        .signature-label {
            font-style: italic;
        }
        .signature-name {
            font-weight: bold;
            padding-top: 5px;
        }

        @media print {
            @page {
                size: 8.5in 11in;
                margin: 0.5in;
            }
            body {
                background: white;
                padding: 0;
                margin: 0;
            }
            .controls, .copy-separator {
                display: none;
            }
            .ticket-container {
                box-shadow: none;
                border: 1px solid #ccc;
                width: 100%;
                height: 4.9in;
                padding: 0.25in;
                box-sizing: border-box;
                margin: 0 auto;
                page-break-inside: avoid;
                overflow: hidden;
            }
            .ticket-container:first-of-type {
                margin-bottom: 0.2in;
            }
        }
    </style>
</head>
<body>
    <div class="controls">
        <button class="btn" onclick="window.print()">Print Two Copies</button>
        <a href="dashboard.php" class="btn" style="background-color: #7f8c8d;">Back to Approvals</a>
    </div>

    <!-- First Copy -->
    <div class="ticket-container">
        <?php render_ticket_content($ticket); ?>
    </div>

    <div class="copy-separator"></div>

    <!-- Second Copy -->
    <div class="ticket-container">
        <?php render_ticket_content($ticket); ?>
    </div>
</body>
</html>
