<?php
header('Content-Type: application/json');
require_once '../includes/config.php';

SessionManager::start();

if (!SessionManager::isLoggedIn()) {
    echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
    exit();
}

$db = Database::getInstance()->getConnection();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = $_POST;

    $action = $input['action'] ?? '';
    
    if ($action === 'cancel') {
        $booking_id = (int) ($input['booking_id'] ?? 0);
        try {
            $stmt = $db->prepare("SELECT booking_id FROM bookings WHERE booking_id = ? AND user_id = ? AND status = 'confirmed'");
            $stmt->execute([$booking_id, SessionManager::getUserId()]);
            if ($stmt->fetch()) {
                $stmt = $db->prepare("UPDATE bookings SET status = 'cancelled' WHERE booking_id = ?");
                $stmt->execute([$booking_id]);
                echo json_encode(['status' => 'success', 'message' => 'Booking cancelled successfully.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Booking not found or cannot be cancelled.']);
            }
        } catch (Exception $e) {
            logError("Booking cancellation failed: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Failed to cancel booking. Please try again.']);
        }
        exit;
    }
}

if ($method === 'GET') {
    $status_filter = $_GET['status'] ?? 'all';
    $date_filter = $_GET['date'] ?? 'all';

    $where_conditions = ["username = ?"];
    $params = [SessionManager::getUsername()];

    if ($status_filter !== 'all') {
        $where_conditions[] = "status = ?";
        $params[] = $status_filter;
    }

    if ($date_filter === 'upcoming') {
        $where_conditions[] = "booking_date >= CURDATE()";
    } elseif ($date_filter === 'past') {
        $where_conditions[] = "booking_date < CURDATE()";
    } elseif ($date_filter === 'today') {
        $where_conditions[] = "booking_date = CURDATE()";
    }

    $where_clause = implode(' AND ', $where_conditions);

    try {
        $stmt = $db->prepare("
            SELECT * FROM booking_details 
            WHERE {$where_clause}
            ORDER BY booking_date DESC, start_time DESC
        ");
        $stmt->execute($params);
        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $db->prepare("SELECT COUNT(*) FROM bookings WHERE user_id = ?");
        $stmt->execute([SessionManager::getUserId()]);
        $total_bookings = $stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM bookings WHERE user_id = ? AND status = 'confirmed'");
        $stmt->execute([SessionManager::getUserId()]);
        $confirmed_bookings = $stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM bookings WHERE user_id = ? AND booking_date >= CURDATE() AND status = 'confirmed'");
        $stmt->execute([SessionManager::getUserId()]);
        $upcoming_bookings = $stmt->fetchColumn();

        echo json_encode([
            'status' => 'success',
            'data' => [
                'bookings' => $bookings,
                'stats' => [
                    'total' => $total_bookings,
                    'confirmed' => $confirmed_bookings,
                    'upcoming' => $upcoming_bookings
                ]
            ]
        ]);
    } catch(Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to fetch bookings.']);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
