<?php
header('Content-Type: application/json');
require_once '../includes/config.php';

SessionManager::start();

if (!SessionManager::isLoggedIn()) {
    echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';
$db = Database::getInstance()->getConnection();

if ($method === 'GET') {
    if ($action === 'list') {
        try {
            // Get all rooms
            $stmt = $db->prepare("SELECT * FROM rooms WHERE is_active = 1 ORDER BY capacity ASC");
            $stmt->execute();
            $all_rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get today's bookings
            $stmt = $db->prepare("
                SELECT bd.* 
                FROM booking_details bd 
                WHERE bd.booking_date = CURDATE() AND bd.status = 'confirmed'
                ORDER BY bd.start_time ASC
            ");
            $stmt->execute();
            $todays_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'status' => 'success',
                'data' => [
                    'rooms' => $all_rooms,
                    'todays_bookings' => $todays_bookings
                ]
            ]);
        } catch(Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to fetch rooms']);
        }
        exit;
    }

    if ($action === 'get_booked_seats') {
        try {
            $room_id = (int)($_GET['room_id'] ?? 0);
            $booking_date = $_GET['booking_date'] ?? '';
            $start_time = $_GET['start_time'] ?? '';
            $end_time = $_GET['end_time'] ?? '';

            if (!$room_id || !$booking_date || !$start_time || !$end_time) {
                echo json_encode(['status' => 'error', 'message' => 'Missing parameters']);
                exit;
            }

            $stmt = $db->prepare("
                SELECT seat_number 
                FROM bookings 
                WHERE room_id = ? 
                AND booking_date = ? 
                AND status = 'confirmed' 
                AND (start_time < ? AND end_time > ?)
                AND seat_number IS NOT NULL
            ");
            $stmt->execute([$room_id, $booking_date, $end_time, $start_time]);
            $raw_booked_seats = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $booked_seats = [];
            foreach ($raw_booked_seats as $seat_str) {
                $parts = array_map('trim', explode(',', $seat_str));
                foreach ($parts as $p) {
                    if (!empty($p) && !in_array($p, $booked_seats)) {
                        $booked_seats[] = $p;
                    }
                }
            }

            echo json_encode([
                'status' => 'success',
                'booked_seats' => $booked_seats
            ]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'get_daily_schedule') {
        try {
            $room_id = (int)($_GET['room_id'] ?? 0);
            $booking_date = $_GET['booking_date'] ?? '';

            if (!$room_id || !$booking_date) {
                echo json_encode(['status' => 'error', 'message' => 'Missing parameters']);
                exit;
            }

            $stmt = $db->prepare("
                SELECT start_time, end_time, seat_number 
                FROM bookings 
                WHERE room_id = ? 
                AND booking_date = ? 
                AND status = 'confirmed'
                ORDER BY start_time ASC
            ");
            $stmt->execute([$room_id, $booking_date]);
            $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'bookings' => $bookings
            ]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = $_POST;

    if (isset($input['book_room']) || $action === 'book') {
        try {
            $booking_date = $input['booking_date'];
            $start_time = $input['start_time'];
            $end_time = $input['end_time'];
            $attendees = (int) $input['attendees'];
            $purpose = Security::sanitizeInput($input['purpose'] ?? '');
            $seat_number = isset($input['seat_number']) ? Security::sanitizeInput($input['seat_number']) : null;

            // Get user role
            $stmt = $db->prepare("SELECT role FROM users WHERE user_id = ?");
            $stmt->execute([SessionManager::getUserId()]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            $isAdmin = ($user['role'] === 'admin');

            $errors = [];

            if (empty($booking_date) || $booking_date < date('Y-m-d')) {
                $errors[] = "Please select a valid future date.";
            }
            if (strtotime($start_time) >= strtotime($end_time)) {
                $errors[] = "End time must be after start time.";
            }
            if ($attendees < 1 || $attendees > 50) {
                $errors[] = "Number of attendees must be between 1 and 50.";
            }
            $is_entire_hall = ($seat_number === 'Entire Hall');
            if (empty($seat_number)) {
                $errors[] = "Please select a seat/desk from the room layout.";
            } elseif (!$is_entire_hall) {
                $requested_seats_count = count(array_filter(array_map('trim', explode(',', $seat_number))));
                if ($requested_seats_count !== $attendees) {
                    $errors[] = "Please select exactly {$attendees} seat(s). You selected {$requested_seats_count}.";
                }
            }

            if (!$isAdmin) {
                // Overlapping bookings check has been removed to allow users to book multiple seats/rooms at the same time.

                // Check active bookings limit
                $stmt = $db->prepare("
                    SELECT COUNT(*) as total_active_bookings 
                    FROM bookings 
                    WHERE user_id = ? 
                    AND booking_date >= CURRENT_DATE 
                    AND status = 'confirmed'
                ");
                $stmt->execute([SessionManager::getUserId()]);
                $result = $stmt->fetch();
                if ($result['total_active_bookings'] >= 5) {
                    $errors[] = "You have reached the maximum limit of 5 active bookings.";
                }
            }

            if (empty($errors)) {
                $db->beginTransaction();

                $room_id = isset($input['room_id']) ? (int)$input['room_id'] : 0;
                if ($room_id <= 0) {
                    echo json_encode(['status' => 'error', 'errors' => ["Please select a valid room."]]);
                    exit;
                }

                $query = "SELECT r.room_id, r.room_name, r.capacity, r.location, r.amenities
                         FROM rooms r 
                         WHERE r.room_id = ? AND r.is_active = 1";
                         
                // Removed the exclusion of rooms with existing bookings so remaining seats can be booked
                
                $stmt = $db->prepare($query);
                
                $stmt->execute([$room_id]);
                $available_room = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($available_room) {
                    // Double check seat availability in this specific room and time range
                    $stmt = $db->prepare("
                        SELECT seat_number 
                        FROM bookings 
                        WHERE room_id = ? 
                        AND booking_date = ? 
                        AND status = 'confirmed' 
                        AND (start_time < ? AND end_time > ?)
                        AND seat_number IS NOT NULL
                    ");
                    $stmt->execute([$room_id, $booking_date, $end_time, $start_time]);
                    $raw_booked_seats = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    $all_booked_seats = [];
                    foreach ($raw_booked_seats as $seat_str) {
                        $parts = array_map('trim', explode(',', $seat_str));
                        $all_booked_seats = array_merge($all_booked_seats, $parts);
                    }
                    
                    $requested_seats = array_filter(array_map('trim', explode(',', $seat_number)));
                    $has_entire_hall_booked = in_array('Entire Hall', $all_booked_seats);
                    $is_booking_entire_hall = in_array('Entire Hall', $requested_seats);

                    $has_conflict = false;
                    $conflict_reason = "";
                    if ($is_booking_entire_hall) {
                        if (!empty($all_booked_seats)) {
                            $has_conflict = true;
                            $conflict_reason = "The entire hall cannot be booked because some seats are already booked for this time range.";
                        }
                    } else {
                        if ($has_entire_hall_booked) {
                            $has_conflict = true;
                            $conflict_reason = "The selected seats cannot be booked because the entire hall is already booked for this time range.";
                        } else {
                            $conflicts = array_intersect($requested_seats, $all_booked_seats);
                            if (count($conflicts) > 0) {
                                $has_conflict = true;
                                $conflict_str = implode(', ', $conflicts);
                                $conflict_reason = "The following seats are already booked for this time range: {$conflict_str}. Please choose different seats.";
                            }
                        }
                    }

                    if ($has_conflict) {
                        $db->rollback();
                        echo json_encode(['status' => 'error', 'errors' => [$conflict_reason]]);
                        exit;
                    }

                    // Generate a guaranteed unique booking reference
                    do {
                        $booking_reference = 'BK' . date('Ymd') . strtoupper(substr(uniqid('', true), -6));
                        $refCheck = $db->prepare("SELECT COUNT(*) FROM bookings WHERE booking_reference = ?");
                        $refCheck->execute([$booking_reference]);
                    } while ($refCheck->fetchColumn() > 0);

                    $stmt = $db->prepare("
                        INSERT INTO bookings (user_id, room_id, booking_date, start_time, end_time, purpose, attendees, seat_number, booking_reference) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        SessionManager::getUserId(),
                        $available_room['room_id'],
                        $booking_date, $start_time, $end_time,
                        $purpose, $attendees, $seat_number, $booking_reference
                    ]);
                    $booking_id = $db->lastInsertId();

                    require_once '../includes/simple-qr.php';
                    $qr_manager = new BookingQRManager();
                    $booking_data = [
                        'booking_id' => $booking_id,
                        'booking_reference' => $booking_reference,
                        'room_id' => $available_room['room_id'],
                        'room_name' => $available_room['room_name'],
                        'booking_date' => $booking_date,
                        'start_time' => $start_time,
                        'end_time' => $end_time,
                        'username' => SessionManager::getUsername()
                    ];

                    $qr_code_path = $qr_manager->generateBookingQR($booking_data);
                    $qr_manager->generateHTMLQR($booking_data);

                    $stmt = $db->prepare("UPDATE bookings SET qr_code = ? WHERE booking_id = ?");
                    $stmt->execute([$qr_code_path, $booking_id]);

                    $db->commit();
                    $message = "Study slot <strong>{$available_room['room_name']}</strong> successfully booked for <strong>{$booking_date}</strong> from <strong>{$start_time}</strong> to <strong>{$end_time}</strong>. Booking Reference: <strong>{$booking_reference}</strong>";
                    echo json_encode(['status' => 'success', 'message' => $message]);
                    exit;
                } else {
                    $db->rollback();
                    echo json_encode(['status' => 'error', 'errors' => ["Sorry, no rooms available for the selected date and time with capacity for {$attendees} people."]]);
                    exit;
                }
            } else {
                echo json_encode(['status' => 'error', 'errors' => $errors]);
                exit;
            }

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollback();
            }
            logError("Booking failed: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'errors' => ["Booking failed. Please try again."]]);
            exit;
        }
    }
}

echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
