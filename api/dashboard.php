<?php
header('Content-Type: application/json');
require_once '../includes/config.php';

// Ensure session is started
SessionManager::start();

// Check if user is logged in
if (!SessionManager::isLoggedIn()) {
    echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
    exit();
}

try {
    $db = Database::getInstance()->getConnection();

    // Get user's bookings
    $stmt = $db->prepare("
        SELECT * FROM booking_details 
        WHERE username = ? 
        ORDER BY booking_date DESC, start_time DESC
        LIMIT 10
    ");
    $stmt->execute([SessionManager::getUsername()]);
    $user_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get upcoming bookings
    $stmt = $db->prepare("
        SELECT * FROM booking_details 
        WHERE username = ? AND booking_date >= CURDATE() AND status = 'confirmed'
        ORDER BY booking_date ASC, start_time ASC
    ");
    $stmt->execute([SessionManager::getUsername()]);
    $upcoming_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total bookings count
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM bookings WHERE user_id = ?");
    $stmt->execute([SessionManager::getUserId()]);
    $total_bookings = $stmt->fetchColumn();
    
    // Calculate some stats for the dashboard
    $completed_bookings = 0;
    $cancelled_bookings = 0;
    foreach($user_bookings as $booking) {
        if ($booking['status'] === 'completed') $completed_bookings++;
        if ($booking['status'] === 'cancelled') $cancelled_bookings++;
    }

    echo json_encode([
        'status' => 'success',
        'data' => [
            'user' => [
                'full_name' => SessionManager::getFullName(),
                'username' => SessionManager::getUsername()
            ],
            'stats' => [
                'total_bookings' => $total_bookings,
                'upcoming_count' => count($upcoming_bookings),
                'completed_bookings' => $completed_bookings
            ],
            'upcoming_bookings' => $upcoming_bookings,
            'recent_bookings' => $user_bookings
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
}
