<?php
header('Content-Type: application/json');
require_once '../includes/config.php';

try {
    $db = Database::getInstance()->getConnection();

    // Get total rooms
    $stmt = $db->prepare("SELECT COUNT(*) FROM rooms WHERE is_active = 1");
    $stmt->execute();
    $total_rooms = $stmt->fetchColumn();

    // Get total bookings today
    $stmt = $db->prepare("SELECT COUNT(*) FROM bookings WHERE booking_date = CURDATE() AND status = 'confirmed'");
    $stmt->execute();
    $todays_bookings = $stmt->fetchColumn();

    // Get total active users
    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE is_active = 1");
    $stmt->execute();
    $total_users = $stmt->fetchColumn();

    // Get popular rooms
    $stmt = $db->prepare("
        SELECT r.room_name, r.location, COUNT(b.booking_id) as booking_count 
        FROM rooms r 
        LEFT JOIN bookings b ON r.room_id = b.room_id 
        WHERE r.is_active = 1 
        GROUP BY r.room_id 
        ORDER BY booking_count DESC 
        LIMIT 3
    ");
    $stmt->execute();
    $popular_rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'data' => [
            'stats' => [
                'total_rooms' => $total_rooms,
                'todays_bookings' => $todays_bookings,
                'total_users' => $total_users
            ],
            'popular_rooms' => $popular_rooms
        ]
    ]);

} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection failed',
        'data' => [
            'stats' => [
                'total_rooms' => 5,
                'todays_bookings' => 0,
                'total_users' => 1
            ],
            'popular_rooms' => []
        ]
    ]);
}
