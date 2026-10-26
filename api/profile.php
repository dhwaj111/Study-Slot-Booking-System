<?php
header('Content-Type: application/json');
require_once '../includes/config.php';

SessionManager::start();

if (!SessionManager::isLoggedIn()) {
    echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
    exit();
}

$db = Database::getInstance()->getConnection();
$user_id = SessionManager::getUserId();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    try {
        $stmt = $db->prepare("SELECT user_id, username, email, full_name, phone, department, role, created_at FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $user_profile = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user_profile) {
            echo json_encode(['status' => 'error', 'message' => 'User not found']);
            exit;
        }

        $stats_stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_bookings,
                COUNT(CASE WHEN status = 'confirmed' THEN 1 END) as confirmed_bookings,
                COUNT(CASE WHEN booking_date >= CURDATE() AND status = 'confirmed' THEN 1 END) as upcoming_bookings
            FROM bookings 
            WHERE user_id = ?
        ");
        $stats_stmt->execute([$user_id]);
        $user_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success',
            'data' => [
                'profile' => $user_profile,
                'stats' => $user_stats
            ]
        ]);
    } catch(Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to load profile data']);
    }
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = $_POST;

    $action = $input['action'] ?? '';

    if ($action === 'update_profile') {
        $full_name = trim($input['full_name'] ?? '');
        $email = trim($input['email'] ?? '');
        $phone = trim($input['phone'] ?? '');
        $department = trim($input['department'] ?? '');

        $errors = [];

        if (empty($full_name)) $errors[] = "Full name is required.";
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email address is required.";
        if (!empty($phone) && !preg_match('/^[\+]?[0-9\s\-\(\)]+$/', $phone)) $errors[] = "Invalid phone number format.";

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

                if (isset($_SESSION['email']) && $_SESSION['email'] !== $email) {
                    $_SESSION['email'] = $email;
                    $_SESSION['full_name'] = $full_name;
                }
                echo json_encode(['status' => 'success', 'message' => 'Profile updated successfully!']);
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'errors' => ['Failed to update profile.']]);
            }
        } else {
            echo json_encode(['status' => 'error', 'errors' => $errors]);
        }
        exit;
    }

    if ($action === 'change_password') {
        $current_password = $input['current_password'] ?? '';
        $new_password = $input['new_password'] ?? '';
        $confirm_password = $input['confirm_password'] ?? '';

        $errors = [];

        if (empty($current_password)) $errors[] = "Current password is required.";
        if (empty($new_password) || strlen($new_password) < 6) $errors[] = "New password must be at least 6 characters long.";
        if ($new_password !== $confirm_password) $errors[] = "New password and confirmation do not match.";

        if (empty($errors)) {
            $stmt = $db->prepare("SELECT password_hash FROM users WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();

            if ($user && Security::verifyPassword($current_password, $user['password_hash'])) {
                try {
                    $new_hash = Security::hashPassword($new_password);
                    $stmt = $db->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE user_id = ?");
                    $stmt->execute([$new_hash, $user_id]);
                    echo json_encode(['status' => 'success', 'message' => 'Password changed successfully!']);
                } catch (Exception $e) {
                    echo json_encode(['status' => 'error', 'errors' => ['Failed to change password.']]);
                }
            } else {
                echo json_encode(['status' => 'error', 'errors' => ['Current password is incorrect.']]);
            }
        } else {
            echo json_encode(['status' => 'error', 'errors' => $errors]);
        }
        exit;
    }

    if ($action === 'delete_account') {
        $confirm_deletion = $input['confirm_deletion'] ?? '';
        $password_confirm = $input['password_confirm'] ?? '';

        if ($confirm_deletion === 'DELETE' && !empty($password_confirm)) {
            $stmt = $db->prepare("SELECT password_hash FROM users WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();

            if ($user && Security::verifyPassword($password_confirm, $user['password_hash'])) {
                try {
                    $db->beginTransaction();
                    $stmt = $db->prepare("UPDATE bookings SET status = 'cancelled' WHERE user_id = ? AND booking_date >= CURDATE()");
                    $stmt->execute([$user_id]);

                    $stmt = $db->prepare("UPDATE users SET is_active = FALSE, updated_at = NOW() WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                    $db->commit();
                    SessionManager::logout();
                    echo json_encode(['status' => 'success', 'message' => 'Account deactivated.']);
                } catch (Exception $e) {
                    $db->rollBack();
                    echo json_encode(['status' => 'error', 'errors' => ['Failed to deactivate account.']]);
                }
            } else {
                echo json_encode(['status' => 'error', 'errors' => ['Password confirmation failed.']]);
            }
        } else {
            echo json_encode(['status' => 'error', 'errors' => ["Please type 'DELETE' and enter your password."]]);
        }
        exit;
    }
}

echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
