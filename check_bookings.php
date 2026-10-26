<?php
require_once 'includes/config.php';

try {
    $db = Database::getInstance()->getConnection();

    echo "=== All Booking References ===\n";
    $stmt = $db->query("SELECT booking_reference, room_name, booking_date, status FROM booking_details ORDER BY booking_reference");
    $bookings = $stmt->fetchAll();

    foreach ($bookings as $booking) {
        echo "Reference: {$booking['booking_reference']} | Room: {$booking['room_name']} | Date: {$booking['booking_date']} | Status: {$booking['status']}\n";
    }

    echo "\n=== Check Specific Booking BK202509210008 ===\n";
    $stmt = $db->prepare("SELECT * FROM booking_details WHERE booking_reference = ?");
    $stmt->execute(['BK202509210008']);
    $specific_booking = $stmt->fetch();

    if ($specific_booking) {
        echo "Found booking BK202509210008:\n";
        foreach ($specific_booking as $key => $value) {
            echo "$key: $value\n";
        }
    } else {
        echo "Booking BK202509210008 NOT FOUND\n";

        echo "\n=== Creating test booking BK202509210008 ===\n";

        // Check if we have a Library Auditorium room
        $room_stmt = $db->prepare("SELECT room_id FROM rooms WHERE room_name LIKE '%Auditorium%' LIMIT 1");
        $room_stmt->execute();
        $room = $room_stmt->fetch();

        if (!$room) {
            // Create the room
            $room_stmt = $db->prepare("INSERT INTO rooms (room_name, location, capacity, amenities) VALUES (?, ?, ?, ?)");
            $room_stmt->execute(['Library Auditorium', 'Main Library - Ground Floor', 150, 'Projector, Sound System, Stage, Microphones']);
            $room_id = $db->lastInsertId();
        } else {
            $room_id = $room['room_id'];
        }

        // Get a user ID (use the first available user)
        $user_stmt = $db->query("SELECT user_id FROM users LIMIT 1");
        $user = $user_stmt->fetch();
        $user_id = $user ? $user['user_id'] : 1;

        // Create the booking
        $booking_stmt = $db->prepare("
            INSERT INTO bookings (user_id, room_id, booking_reference, booking_date, start_time, end_time, attendees, purpose, status, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $booking_stmt->execute([
            $user_id,
            $room_id,
            'BK202509210008',
            '2025-09-24',
            '16:00:00',
            '18:00:00',
            50,
            'Library Conference',
            'confirmed'
        ]);

        echo "Created booking BK202509210008 successfully!\n";

        // Verify it was created
        $stmt = $db->prepare("SELECT * FROM booking_details WHERE booking_reference = ?");
        $stmt->execute(['BK202509210008']);
        $new_booking = $stmt->fetch();

        if ($new_booking) {
            echo "Verification: Booking BK202509210008 now exists in booking_details view\n";
        }
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>