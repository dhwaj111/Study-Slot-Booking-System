<?php
header('Content-Type: application/json');
require_once '../includes/config.php';

$action = isset($_GET['action']) ? $_GET['action'] : '';
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    // Determine action from post body if not in GET
    $input = json_decode(file_get_contents('php://input'), true);
    if ($input && isset($input['action'])) {
        $action = $input['action'];
    } elseif (isset($_POST['action'])) {
        $action = $_POST['action'];
    }
}

if ($action === 'register' && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = $_POST;

    $username = Security::sanitizeInput($input['username'] ?? '');
    $email = Security::sanitizeInput($input['email'] ?? '');
    $full_name = Security::sanitizeInput($input['full_name'] ?? '');
    $password = $input['password'] ?? '';
    $confirm_password = $input['confirm_password'] ?? '';
    $phone = Security::sanitizeInput($input['phone'] ?? '');
    $department = Security::sanitizeInput($input['department'] ?? '');

    $errors = [];

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

    if (empty($errors)) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT user_id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);

        if ($stmt->fetch()) {
            $errors[] = "Username or email already exists.";
        }
    }

    if (empty($errors)) {
        try {
            $password_hash = Security::hashPassword($password);
            $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, full_name, phone, department) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$username, $email, $password_hash, $full_name, $phone, $department]);

            echo json_encode(['status' => 'success', 'message' => 'Registration successful! You can now sign in.']);
            exit;
        } catch (Exception $e) {
            logError("Registration failed: " . $e->getMessage());
            $errors[] = "Registration failed. Please try again.";
        }
    }
    
    echo json_encode(['status' => 'error', 'errors' => $errors]);
    exit;
}

if ($action === 'login' && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = $_POST;

    $username = Security::sanitizeInput($input['username'] ?? '');
    $password = $input['password'] ?? '';

    $errors = [];

    if (empty($username) || empty($password)) {
        $errors[] = "Please enter both username and password.";
    }

    if (empty($errors)) {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND is_active = 1");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && Security::verifyPassword($password, $user['password_hash'])) {
                SessionManager::login($user);
                echo json_encode(['status' => 'success', 'message' => 'Login successful']);
                exit;
            } else {
                $errors[] = "Invalid username or password.";
            }

        } catch (Exception $e) {
            logError("Login failed: " . $e->getMessage());
            $errors[] = "Login failed. Please try again.";
        }
    }

    echo json_encode(['status' => 'error', 'errors' => $errors]);
    exit;
}

if ($action === 'logout') {
    SessionManager::logout();
    echo json_encode(['status' => 'success', 'message' => 'Logged out successfully']);
    exit;
}

if ($action === 'check') {
    if (SessionManager::isLoggedIn()) {
        echo json_encode(['status' => 'success', 'data' => ['user' => ['username' => SessionManager::getUsername(), 'role' => SessionManager::getRole()]]]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
