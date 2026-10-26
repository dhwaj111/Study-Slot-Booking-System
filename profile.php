<?php
require_once 'includes/config.php';

// Check if user is logged in
if (!SessionManager::isLoggedIn()) {
    header('Location: auth.php');
    exit();
}

$db = Database::getInstance()->getConnection();
$user_id = SessionManager::getUserId();
$success_message = '';
$error_message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Update Profile
    if (isset($_POST['update_profile'])) {
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $department = trim($_POST['department'] ?? '');

        // Validation
        $errors = [];

        if (empty($full_name)) {
            $errors[] = "Full name is required.";
        }

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Valid email address is required.";
        }

        if (!empty($phone) && !preg_match('/^[\+]?[0-9\s\-\(\)]+$/', $phone)) {
            $errors[] = "Invalid phone number format.";
        }

        // Check if email is already used by another user
        $email_check = $db->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
        $email_check->execute([$email, $user_id]);
        if ($email_check->fetch()) {
            $errors[] = "Email address is already in use by another account.";
        }

        if (empty($errors)) {
            try {
                $stmt = $db->prepare("
                    UPDATE users 
                    SET full_name = ?, email = ?, phone = ?, department = ?, updated_at = NOW() 
                    WHERE user_id = ?
                ");
                $stmt->execute([$full_name, $email, $phone, $department, $user_id]);

                $success_message = "Profile updated successfully!";

                // Update session data if email changed
                if (isset($_SESSION['email']) && $_SESSION['email'] !== $email) {
                    $_SESSION['email'] = $email;
                    $_SESSION['full_name'] = $full_name;
                }

            } catch (PDOException $e) {
                $error_message = "Failed to update profile. Please try again.";
                error_log("Profile update error: " . $e->getMessage());
            }
        } else {
            $error_message = implode('<br>', $errors);
        }
    }

    // Change Password
    elseif (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        $errors = [];

        if (empty($current_password)) {
            $errors[] = "Current password is required.";
        }

        if (empty($new_password) || strlen($new_password) < 6) {
            $errors[] = "New password must be at least 6 characters long.";
        }

        if ($new_password !== $confirm_password) {
            $errors[] = "New password and confirmation do not match.";
        }

        if (empty($errors)) {
            // Verify current password
            $stmt = $db->prepare("SELECT password_hash FROM users WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();

            if ($user && Security::verifyPassword($current_password, $user['password_hash'])) {
                try {
                    $new_hash = Security::hashPassword($new_password);
                    $stmt = $db->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE user_id = ?");
                    $stmt->execute([$new_hash, $user_id]);

                    $success_message = "Password changed successfully!";
                } catch (PDOException $e) {
                    $error_message = "Failed to change password. Please try again.";
                    error_log("Password change error: " . $e->getMessage());
                }
            } else {
                $error_message = "Current password is incorrect.";
            }
        } else {
            $error_message = implode('<br>', $errors);
        }
    }

    // Delete Account
    elseif (isset($_POST['delete_account'])) {
        $confirm_deletion = $_POST['confirm_deletion'] ?? '';
        $password_confirm = $_POST['password_confirm'] ?? '';

        if ($confirm_deletion === 'DELETE' && !empty($password_confirm)) {
            // Verify password
            $stmt = $db->prepare("SELECT password_hash FROM users WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();

            if ($user && Security::verifyPassword($password_confirm, $user['password_hash'])) {
                try {
                    $db->beginTransaction();

                    // Cancel all future bookings
                    $stmt = $db->prepare("UPDATE bookings SET status = 'cancelled' WHERE user_id = ? AND booking_date >= CURDATE()");
                    $stmt->execute([$user_id]);

                    // Deactivate account instead of deleting (for data integrity)
                    $stmt = $db->prepare("UPDATE users SET is_active = FALSE, updated_at = NOW() WHERE user_id = ?");
                    $stmt->execute([$user_id]);

                    $db->commit();

                    // Logout and redirect
                    SessionManager::logout();
                    header('Location: auth.php?message=account_deactivated');
                    exit();

                } catch (PDOException $e) {
                    $db->rollBack();
                    $error_message = "Failed to deactivate account. Please try again.";
                    error_log("Account deletion error: " . $e->getMessage());
                }
            } else {
                $error_message = "Password confirmation failed.";
            }
        } else {
            $error_message = "Please type 'DELETE' and enter your password to confirm account deletion.";
        }
    }
}

// Get current user profile data
try {
    $stmt = $db->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user_profile = $stmt->fetch();

    if (!$user_profile) {
        header('Location: auth.php');
        exit();
    }
} catch (PDOException $e) {
    $error_message = "Failed to load profile data.";
    error_log("Profile load error: " . $e->getMessage());
}

// Get user statistics
try {
    $stats_stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_bookings,
            COUNT(CASE WHEN status = 'confirmed' THEN 1 END) as confirmed_bookings,
            COUNT(CASE WHEN booking_date >= CURDATE() AND status = 'confirmed' THEN 1 END) as upcoming_bookings
        FROM bookings 
        WHERE user_id = ?
    ");
    $stats_stmt->execute([$user_id]);
    $user_stats = $stats_stmt->fetch();
} catch (PDOException $e) {
    $user_stats = ['total_bookings' => 0, 'confirmed_bookings' => 0, 'upcoming_bookings' => 0];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - <?php echo APP_NAME; ?></title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .navbar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .profile-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
        }

        .profile-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            border: none;
        }

        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
            text-align: center;
            transition: transform 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
        }

        .danger-zone {
            border: 2px solid #dc3545;
            border-radius: 10px;
            background: #fff5f5;
        }

        .nav-tabs .nav-link {
            border: none;
            color: #6c757d;
            font-weight: 500;
        }

        .nav-tabs .nav-link.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px 10px 0 0;
        }

        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%);
        }
    </style>
</head>

<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-door-open me-2"></i>
                <?php echo APP_NAME; ?>
            </a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-tachometer-alt me-1"></i>Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="book-room.php">
                            <i class="fas fa-plus me-1"></i>Book Room
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="my-bookings.php">
                            <i class="fas fa-list me-1"></i>My Bookings
                        </a>
                    </li>
                </ul>

                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-arrow-left me-1"></i>Back to Dashboard
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Profile Header -->
    <div class="profile-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <div class="d-flex align-items-center">
                        <div class="profile-avatar me-4">
                            <div class="bg-white bg-opacity-25 rounded-circle d-flex align-items-center justify-content-center"
                                style="width: 80px; height: 80px;">
                                <i class="fas fa-user fa-2x text-white"></i>
                            </div>
                        </div>
                        <div>
                            <h2 class="mb-1"><?php echo htmlspecialchars($user_profile['full_name']); ?></h2>
                            <p class="mb-0 opacity-75">
                                <i
                                    class="fas fa-envelope me-2"></i><?php echo htmlspecialchars($user_profile['email']); ?>
                            </p>
                            <p class="mb-0 opacity-75">
                                <i class="fas fa-clock me-2"></i>Member since
                                <?php echo date('F Y', strtotime($user_profile['created_at'])); ?>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="text-md-end">
                        <span class="badge bg-light text-dark px-3 py-2">
                            <i class="fas fa-shield-alt me-2"></i>
                            <?php echo ucfirst($user_profile['role']); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container mb-5">
        <!-- Success/Error Messages -->
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- User Statistics -->
        <div class="row mb-4">
            <div class="col-md-4 mb-3">
                <div class="stat-card">
                    <i class="fas fa-calendar-check fa-2x mb-2"></i>
                    <h3 class="mb-1"><?php echo $user_stats['total_bookings']; ?></h3>
                    <p class="mb-0">Total Bookings</p>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="stat-card">
                    <i class="fas fa-check-circle fa-2x mb-2"></i>
                    <h3 class="mb-1"><?php echo $user_stats['confirmed_bookings']; ?></h3>
                    <p class="mb-0">Confirmed Bookings</p>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="stat-card">
                    <i class="fas fa-clock fa-2x mb-2"></i>
                    <h3 class="mb-1"><?php echo $user_stats['upcoming_bookings']; ?></h3>
                    <p class="mb-0">Upcoming Bookings</p>
                </div>
            </div>
        </div>

        <!-- Profile Management Tabs -->
        <div class="profile-card">
            <div class="card-header bg-transparent">
                <ul class="nav nav-tabs card-header-tabs" id="profileTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="profile-tab" data-bs-toggle="tab" data-bs-target="#profile"
                            type="button" role="tab">
                            <i class="fas fa-user me-2"></i>Profile Information
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="password-tab" data-bs-toggle="tab" data-bs-target="#password"
                            type="button" role="tab">
                            <i class="fas fa-lock me-2"></i>Change Password
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="account-tab" data-bs-toggle="tab" data-bs-target="#account"
                            type="button" role="tab">
                            <i class="fas fa-cog me-2"></i>Account Settings
                        </button>
                    </li>
                </ul>
            </div>

            <div class="card-body">
                <div class="tab-content" id="profileTabsContent">
                    <!-- Profile Information Tab -->
                    <div class="tab-pane fade show active" id="profile" role="tabpanel">
                        <form method="POST" action="">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="full_name" class="form-label">Full Name *</label>
                                        <input type="text" class="form-control" id="full_name" name="full_name"
                                            value="<?php echo htmlspecialchars($user_profile['full_name']); ?>"
                                            required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="email" class="form-label">Email Address *</label>
                                        <input type="email" class="form-control" id="email" name="email"
                                            value="<?php echo htmlspecialchars($user_profile['email']); ?>" required>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="phone" class="form-label">Phone Number</label>
                                        <input type="tel" class="form-control" id="phone" name="phone"
                                            value="<?php echo htmlspecialchars($user_profile['phone']); ?>"
                                            placeholder="+1 (555) 123-4567">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="department" class="form-label">Department</label>
                                        <input type="text" class="form-control" id="department" name="department"
                                            value="<?php echo htmlspecialchars($user_profile['department']); ?>"
                                            placeholder="e.g., IT, HR, Finance">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="username" class="form-label">Username</label>
                                        <input type="text" class="form-control" id="username"
                                            value="<?php echo htmlspecialchars($user_profile['username']); ?>" readonly>
                                        <div class="form-text">Username cannot be changed.</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="role" class="form-label">Account Role</label>
                                        <input type="text" class="form-control" id="role"
                                            value="<?php echo ucfirst($user_profile['role']); ?>" readonly>
                                        <div class="form-text">Contact admin to change your role.</div>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between">
                                <button type="submit" name="update_profile" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>Update Profile
                                </button>
                                <small class="text-muted align-self-center">
                                    Last updated:
                                    <?php echo date('M j, Y g:i A', strtotime($user_profile['updated_at'])); ?>
                                </small>
                            </div>
                        </form>
                    </div>

                    <!-- Change Password Tab -->
                    <div class="tab-pane fade" id="password" role="tabpanel">
                        <form method="POST" action="">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="current_password" class="form-label">Current Password *</label>
                                        <input type="password" class="form-control" id="current_password"
                                            name="current_password" required>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="new_password" class="form-label">New Password *</label>
                                        <input type="password" class="form-control" id="new_password"
                                            name="new_password" required minlength="6">
                                        <div class="form-text">Password must be at least 6 characters long.</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="confirm_password" class="form-label">Confirm New Password *</label>
                                        <input type="password" class="form-control" id="confirm_password"
                                            name="confirm_password" required minlength="6">
                                    </div>
                                </div>
                            </div>

                            <button type="submit" name="change_password" class="btn btn-primary">
                                <i class="fas fa-lock me-2"></i>Change Password
                            </button>
                        </form>
                    </div>

                    <!-- Account Settings Tab -->
                    <div class="tab-pane fade" id="account" role="tabpanel">
                        <div class="row">
                            <div class="col-md-8">
                                <h5 class="mb-3">Account Information</h5>
                                <div class="mb-3">
                                    <strong>Account Status:</strong>
                                    <span class="badge bg-success ms-2">
                                        <?php echo $user_profile['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </div>
                                <div class="mb-3">
                                    <strong>Account Created:</strong>
                                    <?php echo date('F j, Y g:i A', strtotime($user_profile['created_at'])); ?>
                                </div>
                                <div class="mb-3">
                                    <strong>Last Updated:</strong>
                                    <?php echo date('F j, Y g:i A', strtotime($user_profile['updated_at'])); ?>
                                </div>

                                <hr class="my-4">

                                <!-- Danger Zone -->
                                <div class="danger-zone p-4">
                                    <h5 class="text-danger mb-3">
                                        <i class="fas fa-exclamation-triangle me-2"></i>Danger Zone
                                    </h5>
                                    <p class="text-muted">
                                        Deactivating your account will cancel all your future bookings and
                                        prevent you from accessing the system. This action cannot be undone.
                                    </p>

                                    <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal"
                                        data-bs-target="#deleteAccountModal">
                                        <i class="fas fa-user-times me-2"></i>Deactivate Account
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Account Modal -->
    <div class="modal fade" id="deleteAccountModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle me-2"></i>Confirm Account Deactivation
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <div class="alert alert-warning">
                            <strong>Warning:</strong> This action will deactivate your account and cancel all future
                            bookings.
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Type <strong>DELETE</strong> to confirm:</label>
                            <input type="text" class="form-control" name="confirm_deletion" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Enter your password to confirm:</label>
                            <input type="password" class="form-control" name="password_confirm" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="delete_account" class="btn btn-danger">
                            <i class="fas fa-user-times me-2"></i>Deactivate Account
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Password confirmation validation
        document.getElementById('confirm_password').addEventListener('input', function () {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;

            if (newPassword !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });

        // Show success message and scroll to top
        <?php if (!empty($success_message)): ?>
            window.scrollTo(0, 0);
        <?php endif; ?>
    </script>
</body>

</html>