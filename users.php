<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit();
}

$mysqli = new mysqli("localhost", "gpsuser", "gpspassword", "gps_tracker");
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add'])) {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'user';
        $first_name = $_POST['first_name'] ?? '';
        $last_name = $_POST['last_name'] ?? '';
        $position = $_POST['position'] ?? '';

        if (!empty($password)) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $mysqli->prepare("INSERT INTO users (username, password, role, first_name, last_name, position) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssss", $username, $hash, $role, $first_name, $last_name, $position);
            $stmt->execute();
        }
    } elseif (isset($_POST['edit'])) {
        $id = $_POST['id'];
        $username = $_POST['username'] ?? '';
        $role = $_POST['role'] ?? 'user';
        $first_name = $_POST['first_name'] ?? '';
        $last_name = $_POST['last_name'] ?? '';
        $position = $_POST['position'] ?? '';
        $password = $_POST['password'] ?? '';

        // Dynamically build the query based on whether a new password is provided
        if (!empty($password)) {
            // If password is changed, hash it and update it
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $mysqli->prepare("UPDATE users SET username=?, role=?, first_name=?, last_name=?, position=?, password=? WHERE id=?");
            $stmt->bind_param("ssssssi", $username, $role, $first_name, $last_name, $position, $hash, $id);
        } else {
            // If password is not changed, update other fields only
            $stmt = $mysqli->prepare("UPDATE users SET username=?, role=?, first_name=?, last_name=?, position=? WHERE id=?");
            $stmt->bind_param("sssssi", $username, $role, $first_name, $last_name, $position, $id);
        }
        $stmt->execute();

    } elseif (isset($_POST['delete'])) {
        $id = $_POST['id'];
        $stmt = $mysqli->prepare("DELETE FROM users WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
    }

    header("Location: users.php");
    exit();
}

$users = $mysqli->query("SELECT * FROM users ORDER BY last_name, first_name")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>User Accounts</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f4f6f9; font-family: 'Segoe UI', sans-serif; }
        .container { margin-top: 40px; }
        .table td, .table th { vertical-align: middle; }
        .table th { background-color: #dee2e6; }
        .card { border-radius: 10px; box-shadow: 0 4px 8px rgba(0,0,0,0.05); }
        .action-icons { display: flex; gap: 5px; }
        .search-box { max-width: 300px; }
    </style>
</head>
<body>
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="fw-semibold">User Accounts</h3>
        <div>
            <a href="dashboard.php" class="btn btn-dark me-2">Back</a>
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addModal">Add New User</button>
        </div>
    </div>

    <!-- Add User Modal -->
    <div class="modal fade" id="addModal" tabindex="-1" aria-labelledby="addModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addModalLabel">Add New User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">First Name</label>
                                <input type="text" name="first_name" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Last Name</label>
                                <input type="text" name="last_name" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Username</label>
                                <input type="text" name="username" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Password</label>
                                <input type="password" name="password" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Position</label>
                                <input type="text" name="position" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Role</label>
                                <select name="role" class="form-select">
                                    <option value="user" selected>User</option>
                                    <option value="admin">Admin</option>
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer mt-3">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="submit" name="add" class="btn btn-primary">Add User</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Edit User Modal -->
    <div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editModalLabel">Edit User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editForm" method="POST">
                        <input type="hidden" id="edit_id" name="id">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">First Name</label>
                                <input type="text" id="edit_first_name" name="first_name" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Last Name</label>
                                <input type="text" id="edit_last_name" name="last_name" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Username</label>
                                <input type="text" id="edit_username" name="username" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">New Password</label>
                                <input type="password" name="password" class="form-control" placeholder="Leave blank to keep current password">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Position</label>
                                <input type="text" id="edit_position" name="position" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Role</label>
                                <select id="edit_role" name="role" class="form-select">
                                    <option value="user">User</option>
                                    <option value="admin">Admin</option>
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer mt-3">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="submit" name="edit" class="btn btn-primary">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="card p-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">User List</h5>
            <input type="text" class="form-control search-box" id="searchInput" placeholder="Search...">
        </div>
        <div class="table-responsive">
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>First Name</th>
                        <th>Last Name</th>
                        <th>Position</th>
                        <th>Role</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="userTable">
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= $user['id']; ?></td>
                        <td><?= htmlspecialchars($user['username']) ?></td>
                        <td><?= htmlspecialchars($user['first_name']) ?></td>
                        <td><?= htmlspecialchars($user['last_name']) ?></td>
                        <td><?= htmlspecialchars($user['position']) ?></td>
                        <td><?= ucfirst(htmlspecialchars($user['role'])) ?></td>
                        <td class="action-icons">
                            <button type="button" class="btn btn-info btn-sm text-white btn-edit"
                                data-id="<?= $user['id'] ?>"
                                data-username="<?= htmlspecialchars($user['username']) ?>"
                                data-first-name="<?= htmlspecialchars($user['first_name']) ?>"
                                data-last-name="<?= htmlspecialchars($user['last_name']) ?>"
                                data-position="<?= htmlspecialchars($user['position']) ?>"
                                data-role="<?= $user['role'] ?>"
                                data-bs-toggle="modal" data-bs-target="#editModal">
                                <i class="fas fa-pencil-alt"></i> Edit
                            </button>
                            <form method="POST" onsubmit="return confirm('Are you sure you want to delete this user?');">
                                <input type="hidden" name="id" value="<?= $user['id']; ?>">
                                <button type="submit" name="delete" class="btn btn-danger btn-sm">
                                    <i class="fas fa-trash-alt"></i> Delete
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Edit button functionality to populate and show the modal
    document.querySelectorAll('.btn-edit').forEach(button => {
        button.addEventListener('click', function() {
            const userData = this.dataset;
            
            document.getElementById('edit_id').value = userData.id;
            document.getElementById('edit_username').value = userData.username;
            document.getElementById('edit_first_name').value = userData.firstName;
            document.getElementById('edit_last_name').value = userData.lastName;
            document.getElementById('edit_position').value = userData.position;
            document.getElementById('edit_role').value = userData.role;
        });
    });

    // Search functionality
    document.getElementById('searchInput').addEventListener('input', function() {
        const filter = this.value.toLowerCase();
        const rows = document.querySelectorAll("#userTable tr");
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(filter) ? "" : "none";
        });
    });
});
</script>
</body>
</html>
