<?php
require_once 'includes/config.php';

// Handle registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    $username = Security::sanitizeInput($_POST['username']);
    $email = Security::sanitizeInput($_POST['email']);
    $full_name = Security::sanitizeInput($_POST['full_name']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $phone = Security::sanitizeInput($_POST['phone']);
    $department = Security::sanitizeInput($_POST['department']);

    $errors = [];

    // Validation
    if (empty($username) || !Security::isValidUsername($username)) {
        $errors[] = "Username must be 3-20 characters, alphanumeric and underscore only.";
    }

    if (empty($email) || !Security::isValidEmail($email)) {
        $errors[] = "Please enter a valid email address.";
    }

    if (empty($full_name) || strlen($full_name) < 2) {
        $errors[] = "Full name is required (minimum 2 characters).";
    }

    if (empty($password) || strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters long.";
    }

    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match.";
    }

    // Check if username or email already exists
    if (empty($errors)) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT user_id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);

        if ($stmt->fetch()) {
            $errors[] = "Username or email already exists.";
        }
    }

    // Create user if no errors
    if (empty($errors)) {
        try {
            $password_hash = Security::hashPassword($password);

            $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, full_name, phone, department) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$username, $email, $password_hash, $full_name, $phone, $department]);

            $success_message = "Registration successful! You can now sign in.";

        } catch (Exception $e) {
            logError("Registration failed: " . $e->getMessage());
            $errors[] = "Registration failed. Please try again.";
        }
    }
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = Security::sanitizeInput($_POST['username']);
    $password = $_POST['password'];

    $errors = [];

    if (empty($username) || empty($password)) {
        $errors[] = "Please enter both username and password.";
    }

    if (empty($errors)) {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND is_active = 1");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();

            if ($user && Security::verifyPassword($password, $user['password_hash'])) {
                SessionManager::login($user);
                header('Location: dashboard.php');
                exit();
            } else {
                $errors[] = "Invalid username or password.";
            }

        } catch (Exception $e) {
            logError("Login failed: " . $e->getMessage());
            $errors[] = "Login failed. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In / Sign Up - <?php echo APP_NAME; ?></title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .auth-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .auth-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            max-width: 900px;
            width: 100%;
        }

        .auth-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }

        .auth-body {
            padding: 2rem;
        }

        .nav-pills .nav-link {
            border-radius: 50px;
            padding: 12px 30px;
            margin: 0 5px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .nav-pills .nav-link.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .form-control {
            border-radius: 10px;
            border: 2px solid #e9ecef;
            padding: 12px 15px;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }

        .btn-auth {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 50px;
            padding: 12px 30px;
            font-weight: 600;
            color: white;
            transition: all 0.3s ease;
        }

        .btn-auth:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
            color: white;
        }

        .input-group-text {
            background: transparent;
            border: 2px solid #e9ecef;
            border-right: none;
            border-radius: 10px 0 0 10px;
        }

        .input-group .form-control {
            border-left: none;
            border-radius: 0 10px 10px 0;
        }

        .alert {
            border-radius: 10px;
            border: none;
        }

        .logo {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }
    </style>
</head>

<body>
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <div class="logo">
                    <i class="fas fa-door-open"></i>
                </div>
                <h2 class="mb-0"><?php echo APP_NAME; ?></h2>
                <p class="mb-0 mt-2">Secure • Efficient • Easy to Use</p>
            </div>

            <div class="auth-body">
                <!-- Display errors -->
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <!-- Display success message -->
                <?php if (isset($success_message)): ?>
                    <div class="alert alert-success">
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                <?php endif; ?>

                <!-- Navigation tabs -->
                <ul class="nav nav-pills nav-justified mb-4" id="authTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="signin-tab" data-bs-toggle="pill" data-bs-target="#signin"
                            type="button" role="tab">
                            <i class="fas fa-sign-in-alt me-2"></i>Sign In
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="signup-tab" data-bs-toggle="pill" data-bs-target="#signup"
                            type="button" role="tab">
                            <i class="fas fa-user-plus me-2"></i>Sign Up
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="authTabsContent">
                    <!-- Sign In Form -->
                    <div class="tab-pane fade show active" id="signin" role="tabpanel">
                        <form method="POST" action="">
                            <div class="mb-3">
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-user"></i>
                                    </span>
                                    <input type="text" class="form-control" name="username"
                                        placeholder="Username or Email" required>
                                </div>
                            </div>

                            <div class="mb-4">
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-lock"></i>
                                    </span>
                                    <input type="password" class="form-control" name="password" placeholder="Password"
                                        required>
                                </div>
                            </div>

                            <div class="d-grid">
                                <button type="submit" name="login" class="btn btn-auth btn-lg">
                                    <i class="fas fa-sign-in-alt me-2"></i>Sign In
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Sign Up Form -->
                    <div class="tab-pane fade" id="signup" role="tabpanel">
                        <form method="POST" action="">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="input-group">
                                        <span class="input-group-text">
                                            <i class="fas fa-user"></i>
                                        </span>
                                        <input type="text" class="form-control" name="username" placeholder="Username"
                                            required>
                                    </div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <div class="input-group">
                                        <span class="input-group-text">
                                            <i class="fas fa-envelope"></i>
                                        </span>
                                        <input type="email" class="form-control" name="email" placeholder="Email"
                                            required>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-id-card"></i>
                                    </span>
                                    <input type="text" class="form-control" name="full_name" placeholder="Full Name"
                                        required>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="input-group">
                                        <span class="input-group-text">
                                            <i class="fas fa-phone"></i>
                                        </span>
                                        <input type="tel" class="form-control" name="phone"
                                            placeholder="Phone (Optional)">
                                    </div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <div class="input-group">
                                        <span class="input-group-text">
                                            <i class="fas fa-building"></i>
                                        </span>
                                        <input type="text" class="form-control" name="department"
                                            placeholder="Department (Optional)">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="input-group">
                                        <span class="input-group-text">
                                            <i class="fas fa-lock"></i>
                                        </span>
                                        <input type="password" class="form-control" name="password"
                                            placeholder="Password" required>
                                    </div>
                                </div>

                                <div class="col-md-6 mb-4">
                                    <div class="input-group">
                                        <span class="input-group-text">
                                            <i class="fas fa-lock"></i>
                                        </span>
                                        <input type="password" class="form-control" name="confirm_password"
                                            placeholder="Confirm Password" required>
                                    </div>
                                </div>
                            </div>

                            <div class="d-grid">
                                <button type="submit" name="register" class="btn btn-auth btn-lg">
                                    <i class="fas fa-user-plus me-2"></i>Create Account
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="text-center mt-4">
                    <small class="text-muted">
                        Already have an account? Use the Sign In tab above.<br>
                        Need help? Contact your system administrator.
                    </small>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Auto-switch to signup tab if registration was attempted
        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])): ?>
            document.getElementById('signup-tab').click();
        <?php endif; ?>

        // Form validation
        document.addEventListener('DOMContentLoaded', function () {
            const forms = document.querySelectorAll('form');

            forms.forEach(form => {
                form.addEventListener('submit', function (e) {
                    const passwordInputs = form.querySelectorAll('input[type="password"]');

                    if (passwordInputs.length === 2) {
                        // Sign up form validation
                        if (passwordInputs[0].value !== passwordInputs[1].value) {
                            e.preventDefault();
                            alert('Passwords do not match!');
                        }
                    }
                });
            });
        });
    </script>
</body>

</html>