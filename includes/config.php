<?php
/**
 * Database Configuration for Meeting Room Booking System
 * Enhanced with PDO connection, error handling, and security
 */

// Database configuration constants
define('DB_HOST', 'localhost');
define('DB_NAME', 'meeting_room_booking');
define('DB_USER', 'root'); // Change this to your MySQL username
define('DB_PASS', ''); // Change this to your MySQL password
define('DB_CHARSET', 'utf8mb4');

// Security and application constants
define('APP_NAME', 'Study Slot Booking System');
define('APP_VERSION', '2.0');
define('SESSION_TIMEOUT', 3600); // 1 hour
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_TIME', 900); // 15 minutes

// Encryption key for session security (change this to a random string)
define('ENCRYPTION_KEY', 'your-secret-encryption-key-change-this');

class Database
{
    private static $instance = null;
    private $pdo;

    private function __construct()
    {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET,
                PDO::ATTR_PERSISTENT => false
            ];

            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            die("Database connection failed. Please try again later.");
        }
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection()
    {
        return $this->pdo;
    }

    public function beginTransaction()
    {
        return $this->pdo->beginTransaction();
    }

    public function commit()
    {
        return $this->pdo->commit();
    }

    public function rollback()
    {
        return $this->pdo->rollback();
    }

    // Prevent cloning
    private function __clone()
    {
    }

    // Prevent unserialization
    public function __wakeup()
    {
    }
}

/**
 * Security helper functions
 */
class Security
{

    public static function sanitizeInput($data)
    {
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
        return $data;
    }

    public static function generateCSRFToken()
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function validateCSRFToken($token)
    {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }

    public static function hashPassword($password)
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public static function verifyPassword($password, $hash)
    {
        return password_verify($password, $hash);
    }

    public static function generateRandomString($length = 32)
    {
        return bin2hex(random_bytes($length / 2));
    }

    public static function isValidEmail($email)
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    public static function isValidUsername($username)
    {
        // Username must be 3-20 characters, alphanumeric and underscore only
        return preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username);
    }

    public static function getClientIP()
    {
        $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];

        foreach ($ipKeys as $key) {
            if (array_key_exists($key, $_SERVER) && !empty($_SERVER[$key])) {
                $ips = explode(',', $_SERVER[$key]);
                $ip = trim($ips[0]);

                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
}

/**
 * Session management class
 */
class SessionManager
{

    public static function start()
    {
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.cookie_httponly', 1);
            ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
            ini_set('session.use_strict_mode', 1);
            session_start();

            // Regenerate session ID periodically
            if (!isset($_SESSION['last_regeneration'])) {
                $_SESSION['last_regeneration'] = time();
            } elseif (time() - $_SESSION['last_regeneration'] > 300) {
                session_regenerate_id(true);
                $_SESSION['last_regeneration'] = time();
            }
        }
    }

    public static function destroy()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            if (isset($_COOKIE[session_name()])) {
                setcookie(session_name(), '', time() - 3600, '/');
            }
            session_destroy();
        }
    }

    public static function isLoggedIn()
    {
        return isset($_SESSION['user_id']) && isset($_SESSION['username']);
    }

    public static function getUserId()
    {
        return $_SESSION['user_id'] ?? null;
    }

    public static function getUsername()
    {
        return $_SESSION['username'] ?? null;
    }

    public static function getUserRole()
    {
        return $_SESSION['role'] ?? 'user';
    }

    public static function isAdmin()
    {
        return self::getUserRole() === 'admin';
    }

    public static function login($user)
    {
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['login_time'] = time();

        // Update last login
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("UPDATE users SET updated_at = NOW() WHERE user_id = ?");
        $stmt->execute([$user['user_id']]);
    }

    public static function logout()
    {
        self::destroy();
    }

    public static function checkTimeout()
    {
        if (self::isLoggedIn() && isset($_SESSION['login_time'])) {
            if (time() - $_SESSION['login_time'] > SESSION_TIMEOUT) {
                self::logout();
                return false;
            }
        }
        return true;
    }
}

/**
 * Error logging and handling
 */
function logError($message, $file = __FILE__, $line = __LINE__)
{
    $timestamp = date('Y-m-d H:i:s');
    $error = "[$timestamp] Error in $file on line $line: $message" . PHP_EOL;
    error_log($error, 3, 'logs/error.log');
}

/**
 * Ensure default application users exist so the demo/admin login works.
 */
function initializeDefaultUsers()
{
    try {
        $db = Database::getInstance()->getConnection();

        $defaultUsers = [
            [
                'username' => 'admin',
                'email' => 'admin@company.com',
                'password' => 'admin123',
                'full_name' => 'System Administrator',
                'role' => 'admin'
            ],
            [
                'username' => 'demo',
                'email' => 'demo@company.com',
                'password' => 'demo123',
                'full_name' => 'Demo User',
                'role' => 'user'
            ]
        ];

        foreach ($defaultUsers as $user) {
            $stmt = $db->prepare("SELECT user_id FROM users WHERE username = ?");
            $stmt->execute([$user['username']]);

            if ($stmt->fetchColumn() === false) {
                $stmt = $db->prepare(
                    "INSERT INTO users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, ?, ?, ?, 1)"
                );
                $stmt->execute([
                    $user['username'],
                    $user['email'],
                    Security::hashPassword($user['password']),
                    $user['full_name'],
                    $user['role']
                ]);
            }
        }

        $defaultRooms = [
            ['room_id' => 5, 'room_name' => 'Conference Room A', 'capacity' => 5, 'location' => 'Floor 7', 'description' => 'Small meeting room with projector', 'amenities' => ['projector', 'whiteboard', 'wifi']],
            ['room_id' => 6, 'room_name' => 'Conference Room B', 'capacity' => 7, 'location' => 'Floor 9', 'description' => 'Medium meeting room with video conferencing', 'amenities' => ['projector', 'video_conference', 'whiteboard', 'wifi']],
            ['room_id' => 3, 'room_name' => 'Large Conference Room', 'capacity' => 12, 'location' => 'Floor 3', 'description' => 'Large meeting room for presentations', 'amenities' => ['projector', 'sound_system', 'whiteboard', 'wifi', 'microphone']],
            ['room_id' => 2, 'room_name' => 'Training Room', 'capacity' => 12, 'location' => 'Floor 3', 'description' => 'Training room with multiple screens', 'amenities' => ['multiple_screens', 'sound_system', 'whiteboard', 'wifi']],
            ['room_id' => 4, 'room_name' => 'Board Room', 'capacity' => 15, 'location' => 'Floor 6', 'description' => 'Executive board room with premium amenities', 'amenities' => ['projector', 'video_conference', 'sound_system', 'whiteboard', 'wifi', 'catering_facility']],
        ];

        foreach ($defaultRooms as $room) {
            $stmt = $db->prepare("SELECT room_id FROM rooms WHERE room_id = ?");
            $stmt->execute([$room['room_id']]);

            if ($stmt->fetchColumn() === false) {
                $stmt = $db->prepare(
                    "INSERT INTO rooms (room_id, room_name, capacity, location, description, amenities, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)"
                );
                $stmt->execute([
                    $room['room_id'],
                    $room['room_name'],
                    $room['capacity'],
                    $room['location'],
                    $room['description'],
                    json_encode($room['amenities'])
                ]);
            }
        }
    } catch (Exception $e) {
        logError("Default user initialization failed: " . $e->getMessage());
    }
}

/**
 * Auto-create logs directory if it doesn't exist
 */
if (!file_exists('logs')) {
    mkdir('logs', 0755, true);
}

initializeDefaultUsers();

// Start session automatically
SessionManager::start();

// Check for session timeout
SessionManager::checkTimeout();

?>