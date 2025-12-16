<?php
session_start();

// If user is already logged in, redirect to the main dashboard
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header("Location: dashboard.php");
    exit();
}

// Database connection details
$host = "localhost";
$db = "gps_tracker";
$user = "gpsuser";
$pass = "gpspassword";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Service unavailable. Please try again later.");
}

$error = '';

// Handle form submission for login
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
    if ($stmt) {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        if ($row && password_verify($password, $row['password'])) {
            $_SESSION['username'] = $row['username'];
            $_SESSION['role'] = $row['role'];
            $_SESSION['logged_in'] = true;
            header("Location: dashboard.php");
            exit();
        } else {
            $error = "Invalid username or password.";
        }
        $stmt->close();
    } else {
        $error = "An unexpected error occurred. Please try again.";
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - GPS Tracker</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
    </style>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="w-full max-w-md bg-white rounded-lg shadow-md p-8">
        <div class="text-center mb-6">
            <img src="icons/avlogo.png" alt="Company Logo" class="h-12 w-auto mx-auto mb-4">
            <h2 class="text-2xl font-bold text-gray-800">Admin Login</h2>
            <p class="text-gray-500">Access the fleet management dashboard.</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                <span class="block sm:inline"><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="post" action="login.php">
            <input type="hidden" name="login" value="1">
            <div class="mb-4">
                <label for="username" class="block text-sm font-medium text-gray-700">Username</label>
                <input type="text" name="username" id="username" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-red-500 focus:border-red-500 sm:text-sm">
            </div>
            <div class="mb-6">
                <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                <input type="password" name="password" id="password" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-red-500 focus:border-red-500 sm:text-sm">
            </div>
            <div>
                <button type="submit" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                    Sign in
                </button>
            </div>
        </form>
         <div class="text-center mt-6">
            <a href="index.php" class="text-sm font-medium text-red-600 hover:text-red-500">&larr; Back to Homepage</a>
        </div>
    </div>
</body>
</html>
