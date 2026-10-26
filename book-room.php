<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'includes/config.php';

// Check if user is logged in
if (!SessionManager::isLoggedIn()) {
    header('Location: auth.php');
    exit();
}

$db = Database::getInstance()->getConnection();
$message = '';
$message_type = '';

// Handle room booking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_room'])) {
    try {
        $booking_date = $_POST['booking_date'];
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
        $attendees = (int) $_POST['attendees'];
        
        // Build detailed meals string from submitted data
        $meals_arr = isset($_POST['meals']) && is_array($_POST['meals']) ? array_map('trim', $_POST['meals']) : [];
        $meal_persons = isset($_POST['meal_persons']) && is_array($_POST['meal_persons']) ? $_POST['meal_persons'] : [];
        $meal_items = isset($_POST['meal_items']) && is_array($_POST['meal_items']) ? $_POST['meal_items'] : [];
        
        $meals_parts = [];
        foreach ($meals_arr as $meal_name) {
            $persons = isset($meal_persons[$meal_name]) ? (int)$meal_persons[$meal_name] : $attendees;
            $items_for_meal = isset($meal_items[$meal_name]) && is_array($meal_items[$meal_name])
                ? array_map('trim', $meal_items[$meal_name])
                : [];
            $items_str = !empty($items_for_meal) ? implode(', ', $items_for_meal) : 'All items';
            $meals_parts[] = "{$meal_name} ({$persons} person(s): {$items_str})";
        }
        $meals_str = !empty($meals_parts) ? "Meals: " . implode(' | ', $meals_parts) : "";
        $raw_purpose = isset($_POST['purpose']) ? Security::sanitizeInput($_POST['purpose']) : '';
        
        if ($meals_str && $raw_purpose) {
            $purpose = $meals_str . " || Purpose: " . $raw_purpose;
        } elseif ($meals_str) {
            $purpose = $meals_str;
        } else {
            $purpose = $raw_purpose;
        }
        
        $room_id = isset($_POST['room_id']) ? (int)$_POST['room_id'] : 0;
        $seat_number = isset($_POST['seat_number']) ? Security::sanitizeInput($_POST['seat_number']) : '';

        // Get user role
        $stmt = $db->prepare("SELECT role FROM users WHERE user_id = ?");
        $stmt->execute([SessionManager::getUserId()]);
        $user = $stmt->fetch();
        $isAdmin = ($user['role'] === 'admin');

        // Validation
        $errors = [];

        if (empty($booking_date) || $booking_date < date('Y-m-d')) {
            $errors[] = "Please select a valid future date.";
        }

        if (empty($start_time) || empty($end_time) || strtotime($start_time) >= strtotime($end_time)) {
            $errors[] = "End time must be after start time.";
        }

        if ($attendees < 1 || $attendees > 500) {
            $errors[] = "Number of attendees must be between 1 and 500.";
        }

        if ($room_id <= 0) {
            $errors[] = "Please select a room.";
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

        // Check if selected room can accommodate attendees
        if ($room_id > 0) {
            $stmt = $db->prepare("
                SELECT capacity
                FROM rooms
                WHERE room_id = ?
            ");
            $stmt->execute([$room_id]);
            $available_room = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$available_room) {
                $errors[] = "Selected room not found.";
            } elseif ($attendees > $available_room['capacity']) {
                $errors[] = "The selected room can accommodate only {$available_room['capacity']} people.";
            }
        }

        // Only apply these restrictions for non-admin users
        if (!$isAdmin) {
            // Overlapping bookings check has been removed to allow users to book multiple seats/rooms at the same time.

            // Check total number of active bookings for the user
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
                $errors[] = "You have reached the maximum limit of 5 active bookings. Please wait for some bookings to complete or cancel existing ones.";
            }
        }

        if (empty($errors)) {
            $db->beginTransaction();

            // Fetch the selected room and verify it exists and is active
            $query = "SELECT r.room_id, r.room_name, r.capacity, r.location, r.amenities
                     FROM rooms r 
                     WHERE r.room_id = ? AND r.is_active = 1";
            
            $stmt = $db->prepare($query);
            $stmt->execute([$room_id]);

            $available_room = $stmt->fetch();

            if ($available_room) {
                // Double check seat availability
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
                            $conflict_reason = "The following seats are already booked for this time range: {$conflict_str}. Please select different seats.";
                        }
                    }
                }

                if ($has_conflict) {
                    $db->rollback();
                    $errors[] = $conflict_reason;
                    $message = implode('<br>', $errors);
                    $message_type = 'danger';
                } else {
                    // Generate a guaranteed unique booking reference
                    do {
                        $booking_reference = 'BK' . date('Ymd') . strtoupper(substr(uniqid('', true), -6));
                        $refCheck = $db->prepare("SELECT COUNT(*) FROM bookings WHERE booking_reference = ?");
                        $refCheck->execute([$booking_reference]);
                    } while ($refCheck->fetchColumn() > 0);

                    // Create booking
                    $stmt = $db->prepare("
                        INSERT INTO bookings (user_id, room_id, booking_date, start_time, end_time, purpose, attendees, seat_number, booking_reference) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");

                    $stmt->execute([
                        SessionManager::getUserId(),
                        $available_room['room_id'],
                        $booking_date,
                        $start_time,
                        $end_time,
                        $purpose,
                        $attendees,
                        $seat_number,
                        $booking_reference
                    ]);

                $booking_id = $db->lastInsertId();

                // Generate QR code using our simple QR generator
                require_once 'includes/simple-qr.php';
                $qr_manager = new BookingQRManager();

                // Prepare booking data for QR generation
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
                $qr_manager->generateHTMLQR($booking_data); // Also generate HTML version

                // Update booking with QR code
                $stmt = $db->prepare("UPDATE bookings SET qr_code = ? WHERE booking_id = ?");
                $stmt->execute([$qr_code_path, $booking_id]);

                $db->commit();

                $message = "Study slot <strong>{$available_room['room_name']}</strong> successfully booked (Seat: <strong>{$seat_number}</strong>) for <strong>{$booking_date}</strong> from <strong>{$start_time}</strong> to <strong>{$end_time}</strong>. Booking Reference: <strong>{$booking_reference}</strong>";
                $message_type = 'success';
                }
            } else {
                $db->rollback();
                $message = "Sorry, no rooms available for the selected date and time with capacity for {$attendees} people.";
                $message_type = 'danger';
            }
        } else {
            $message = implode('<br>', $errors);
            $message_type = 'danger';
        }

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        logError("Booking failed: " . $e->getMessage());
        $message = "Booking failed: " . htmlspecialchars($e->getMessage());
        $message_type = 'danger';
    }
}

// Ensure Banquet Hall and Auditorium exist in rooms table
try {
    $check_halls = $db->query("SELECT room_name FROM rooms WHERE room_name IN ('Banquet Hall', 'Auditorium')")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('Banquet Hall', $check_halls)) {
        $max_id = (int)$db->query("SELECT IFNULL(MAX(room_id), 0) FROM rooms")->fetchColumn();
        $ins = $db->prepare("INSERT INTO rooms (room_id, room_name, capacity, location, amenities, is_active) VALUES (?, 'Banquet Hall', 400, 'Ground Floor - Main Wing', ?, 1)");
        $ins->execute([$max_id + 1, json_encode(['stage', 'sound_system', 'projector', 'catering_facility', 'wifi', 'central_ac'])]);
    }

    $check_halls = $db->query("SELECT room_name FROM rooms WHERE room_name IN ('Banquet Hall', 'Auditorium')")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('Auditorium', $check_halls)) {
        $max_id = (int)$db->query("SELECT IFNULL(MAX(room_id), 0) FROM rooms")->fetchColumn();
        $ins = $db->prepare("INSERT INTO rooms (room_id, room_name, capacity, location, amenities, is_active) VALUES (?, 'Auditorium', 200, 'Second Floor - East Wing', ?, 1)");
        $ins->execute([$max_id + 1, json_encode(['auditorium_seating', 'projector', 'stage_lighting', 'sound_system', 'wifi', 'ac'])]);
    }
} catch (Exception $e) {
    // Suppress insertion error if room already exists
}

// Get available rooms for display
$stmt = $db->prepare("SELECT * FROM rooms WHERE is_active = 1 ORDER BY capacity ASC");
$stmt->execute();
$all_rooms = $stmt->fetchAll();

// Get today's bookings
$stmt = $db->prepare("
    SELECT bd.* 
    FROM booking_details bd 
    WHERE bd.booking_date = CURDATE() AND bd.status = 'confirmed'
    ORDER BY bd.start_time ASC
");
$stmt->execute();
$todays_bookings = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Study Slot - <?php echo APP_NAME; ?></title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/styles.css" rel="stylesheet">
</head>

<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-door-open me-2"></i>
                <?php echo APP_NAME; ?>
            </a>

            <div class="collapse navbar-collapse">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-tachometer-alt me-1"></i>Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="book-room.php">
                            <i class="fas fa-plus me-1"></i>Book Study Slot
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="my-bookings.php">
                            <i class="fas fa-calendar me-1"></i>My Bookings
                        </a>
                    </li>
                </ul>

                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php?logout=1">
                            <i class="fas fa-sign-out-alt me-1"></i>Logout
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="h3 mb-0">
                <i class="fas fa-plus text-primary me-2"></i>Book a Study Slot
            </h1>
            <p class="text-muted mb-0">Select date, time, and room preferences for your study session</p>
        </div>

        <!-- Display message -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Booking Form -->
            <div class="col-lg-8 mb-4">
                <div class="booking-card">
                    <div class="card-body">
                        <h5 class="card-title mb-4">
                            <i class="fas fa-calendar-plus text-primary me-2"></i>Booking Details
                        </h5>

                        <form  method="POST" action="">
                            <input type="hidden" id="selected_room_id" name="room_id">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="booking_date" class="form-label">
                                        <i class="fas fa-calendar me-1"></i>Date
                                    </label>
                                    <input type="date" class="form-control" id="booking_date" name="booking_date"
                                        min="<?php echo date('Y-m-d'); ?>" required>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="attendees" class="form-label">
                                        <i class="fas fa-users me-1"></i>Number of Attendees
                                    </label>
                                    <select class="form-select" id="attendees" name="attendees" required>
                                        <option value="">Select attendees</option>
                                        <?php 
                                        $att_list = array_merge(range(1, 30), [35, 40, 45, 50, 75, 100, 150, 200, 250, 300, 350, 400]);
                                        foreach ($att_list as $i): 
                                        ?>
                                            <option value="<?php echo $i; ?>"><?php echo $i; ?>
                                                person<?php echo $i > 1 ? 's' : ''; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="start_time" class="form-label">
                                        <i class="fas fa-clock me-1"></i>Start Time
                                    </label>
                                    <select class="form-select" id="start_time" name="start_time" required>
                                        <option value="">Select start time</option>
                                        <?php
                                        for ($hour = 8; $hour <= 22; $hour++) {
                                            $time = sprintf('%02d:00', $hour);
                                            echo "<option value='{$time}'>{$time}</option>";
                                            $time = sprintf('%02d:30', $hour);
                                            echo "<option value='{$time}'>{$time}</option>";
                                        }
                                        ?>
                                    </select>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="end_time" class="form-label">
                                        <i class="fas fa-clock me-1"></i>End Time
                                    </label>
                                    <select class="form-select" id="end_time" name="end_time" required>
                                        <option value="">Select end time</option>
                                        <?php
                                        for ($hour = 8; $hour <= 23; $hour++) {
                                            $time = sprintf('%02d:00', $hour);
                                            echo "<option value='{$time}'>{$time}</option>";
                                            $time = sprintf('%02d:30', $hour);
                                            echo "<option value='{$time}'>{$time}</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-4" id="seating-layout-container" style="display:none;">
                                <label class="form-label fw-bold text-dark">
                                    <i class="fas fa-chair me-1 text-primary"></i>Choose Your Seat/Desk
                                </label>
                                <div class="p-4 bg-white rounded border shadow-sm text-center">
                                    <!-- Option to book entire room -->
                                    <div id="book-entire-hall-container" class="mb-3 p-3 bg-light rounded border text-start" style="display:none;">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="book_entire_hall_switch">
                                            <label class="form-check-label fw-bold text-primary" for="book_entire_hall_switch">
                                                <i class="fas fa-door-open me-2"></i>Book Entire Room
                                            </label>
                                        </div>
                                        <small class="text-muted d-block mt-1">Check this option if you want to book the entire room instead of individual seats.</small>
                                    </div>

                                    <div id="seating-layout-grid-wrapper">
                                        <div class="room-screen mb-4 py-2 border rounded text-secondary fw-bold" style="background:#eef1f6; font-size: 0.85rem; letter-spacing: 1px;">
                                            📺 FRONT (BOARD / SCREEN)
                                        </div>
                                        <div style="max-height: 400px; overflow-y: auto; padding: 5px;">
                                            <div class="d-flex flex-wrap justify-content-center gap-2" id="seat-grid" style="max-width: 500px; margin: 0 auto;">
                                                <!-- Rendered dynamically by JS -->
                                            </div>
                                        </div>
                                        <div class="mt-4 pt-3 border-top d-flex justify-content-center gap-4 text-muted small">
                                            <div><span class="badge bg-success" style="width:12px; height:12px; display:inline-block; border-radius:3px;">&nbsp;</span> Available</div>
                                            <div><span class="badge bg-danger" style="width:12px; height:12px; display:inline-block; border-radius:3px;">&nbsp;</span> Booked</div>
                                            <div><span class="badge bg-primary" style="width:12px; height:12px; display:inline-block; border-radius:3px;">&nbsp;</span> Selected</div>
                                        </div>
                                    </div>
                                    <input type="hidden" name="seat_number" id="selected_seat" required>
                                </div>
                            </div>

                            <div class="mb-3" id="meal-options-container" style="display:none;">
                                <label class="form-label fw-bold text-dark">
                                    <i class="fas fa-utensils text-primary me-2"></i>Add Meals
                                </label>
                                <div class="p-3 bg-light rounded border shadow-sm">
                                    <div id="meal-checkboxes" class="d-flex flex-wrap gap-3">
                                        <!-- Checkboxes injected by JS -->
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="purpose" class="form-label">
                                    <i class="fas fa-comment-dots text-primary me-2"></i>Meeting Purpose (Optional)
                                </label>
                                <textarea name="purpose" id="purpose" class="form-control" rows="3" placeholder="Brief description of the meeting purpose..."></textarea>
                            </div>

                            <!-- Booking Summary Card -->
                            <div class="card bg-light border-0 mb-4" id="booking-summary-card" style="display: none;">
                                <div class="card-body">
                                    <h6 class="card-title fw-bold text-primary border-bottom pb-2 mb-3"><i class="fas fa-clipboard-check me-2"></i>Booking Summary</h6>
                                    
                                    <div class="row small mb-2">
                                        <div class="col-4 text-muted">Room:</div>
                                        <div class="col-8 fw-bold" id="summary-room">-</div>
                                    </div>
                                    <div class="row small mb-2">
                                        <div class="col-4 text-muted">Seats:</div>
                                        <div class="col-8 fw-bold text-primary" id="summary-seats">-</div>
                                    </div>
                                    <div class="row small mb-2">
                                        <div class="col-4 text-muted">Date:</div>
                                        <div class="col-8 fw-bold" id="summary-date">-</div>
                                    </div>
                                    <div class="row small mb-2">
                                        <div class="col-4 text-muted">Time:</div>
                                        <div class="col-8 fw-bold" id="summary-time">-</div>
                                    </div>
                                    <div class="row small mb-2" id="summary-meals-row" style="display:none;">
                                        <div class="col-4 text-muted">Meals:</div>
                                        <div class="col-8 fw-bold text-warning" id="summary-meals">-</div>
                                    </div>
                                    <div class="row small">
                                        <div class="col-4 text-muted">Attendees:</div>
                                        <div class="col-8 fw-bold" id="summary-attendees">-</div>
                                    </div>
                                </div>
                            </div>

                            <div class="d-grid">
                                <button type="submit" name="book_room" id="book-submit-btn"
                                        class="btn btn-lg"
                                        disabled
                                        style="background: #adb5bd; color: #fff; border: none; cursor: not-allowed; border-radius: 12px; font-weight: 600; transition: all 0.3s ease;">
                                    <i class="fas fa-lock me-2"></i>Book Study Slot
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Available Rooms -->
            <div class="col-lg-4 mb-4">
                <div class="booking-card">
                    <div class="card-body">
                        <h5 class="card-title mb-4">
                            <i class="fas fa-door-open text-primary me-2"></i>Available Rooms
                        </h5>

                        <?php foreach ($all_rooms as $room): ?>

<div class="room-card mb-3 p-3 selectable-room"
     data-room-id="<?php echo $room['room_id']; ?>"
     data-capacity="<?php echo $room['capacity']; ?>"
     id="room-card-<?php echo $room['room_id']; ?>">

    <div class="d-flex justify-content-between mb-2">

        <div>

            <h6 class="mb-1 text-primary fw-bold">
                <i class="fas fa-door-open me-2"></i><?php echo htmlspecialchars($room['room_name']); ?>
            </h6>

            <p class="small text-muted mb-2 mt-2">
                <i class="fas fa-map-marker-alt me-1"></i>
                <?php echo htmlspecialchars($room['location']); ?>
            </p>

            <p class="small text-muted mb-2 room-occupancy-display" id="occupancy-display-<?php echo $room['room_id']; ?>">
                <span class="occupancy-stats mt-1 d-block" style="display:none;">
                    <span class="badge bg-primary rounded-pill">Seats Left: <span class="avail-count"><?php echo $room['capacity']; ?></span> / <?php echo $room['capacity']; ?></span>
                </span>
            </p>

            <?php if ($room['amenities']): ?>

                <div class="amenities">

                    <?php
                    $amenities = json_decode($room['amenities'], true);

                    if ($amenities) {

                        foreach ($amenities as $amenity) {

                            echo '<span class="amenity-badge">'
                                . htmlspecialchars(str_replace('_',' ',$amenity))
                                . '</span>';
                                                         }
                                    }
                    ?>

                </div>

            <?php endif; ?>

        </div>

        <div class="text-end">

    <button
        type="button"
        class="btn btn-outline-info btn-sm view-layout-btn mb-1"
        data-bs-toggle="modal"
        data-bs-target="#roomLayoutModal"
        data-room-id="<?php echo $room['room_id']; ?>"
        data-room-name="<?php echo htmlspecialchars($room['room_name']); ?>"
        data-capacity="<?php echo $room['capacity']; ?>">
        <i class="fas fa-eye"></i> View Layout
    </button><br>

    <button
        type="button"
        class="btn btn-outline-primary btn-sm select-room-btn">
        Select
    </button>

    <div class="status-badge badge-available mt-2">
        ✓ Available
    </div>

</div>

    </div>
    
    <!-- Live Timeline -->
    <div class="mt-2 timeline-container border-top pt-2" id="timeline-<?php echo $room['room_id']; ?>" style="display: none;">
        <small class="fw-bold text-muted d-block mb-2">Today's Schedule</small>
        <div class="schedule-list small" id="schedule-list-<?php echo $room['room_id']; ?>">
            <div class="text-muted fst-italic">Select time to see availability</div>
        </div>
    </div>

</div>

<?php endforeach; ?>
                    </div>
                </div>

                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Room Layout Modal -->
    <div class="modal fade" id="roomLayoutModal" tabindex="-1" aria-labelledby="roomLayoutModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="roomLayoutModalLabel">Room Layout</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="room-screen mb-4 py-2 border rounded text-secondary fw-bold" style="background:#eef1f6; font-size: 0.85rem; letter-spacing: 1px;">
                        📺 FRONT (BOARD / SCREEN)
                    </div>
                    <div style="max-height: 380px; overflow-y: auto; padding: 5px;">
                        <div class="d-flex flex-wrap justify-content-center gap-2" id="modal-seat-grid" style="max-width: 420px; margin: 0 auto;">
                            <!-- Rendered dynamically by JS -->
                        </div>
                    </div>
                    
                    <!-- Option to book entire room in modal -->
                    <div id="modal-book-entire-hall-container" class="mt-3 mb-2 px-2" style="display:none;">
                        <button type="button" class="btn btn-primary w-100 fw-bold" id="modal_book_entire_hall_btn">
                            <i class="fas fa-door-open me-2"></i>Book Entire Room
                        </button>
                    </div>
                    
                    <!-- Room Rules -->
                    <div class="mt-4 text-start border-top pt-3">
                        <h6 class="fw-bold text-secondary mb-3"><i class="fas fa-list-ul me-2"></i>Room Rules</h6>
                        <ul class="list-unstyled small text-muted">
                            <li class="mb-2"><i class="fas fa-users text-primary me-2" style="width:16px;"></i>Maximum Capacity Strictly Enforced</li>
                            <li class="mb-2"><i class="fas fa-volume-mute text-warning me-2" style="width:16px;"></i>Keep Silent During Study Sessions</li>
                            <li class="mb-2"><i class="fas fa-broom text-info me-2" style="width:16px;"></i>Clean Desk After Use</li>
                            <li><i class="fas fa-lightbulb text-success me-2" style="width:16px;"></i>Switch Off Lights When Leaving</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
document.addEventListener("DOMContentLoaded", function () {
    // 1. DOM Elements
    const bookingDate = document.getElementById('booking_date');
    const startTime = document.getElementById('start_time');
    const endTime = document.getElementById('end_time');
    const attendee = document.getElementById('attendees');
    const hiddenRoom = document.getElementById('selected_room_id');
    const selectedSeatInput = document.getElementById('selected_seat');
    const seatGrid = document.getElementById('seat-grid');
    const seatingContainer = document.getElementById('seating-layout-container');
    const summaryCard = document.getElementById('booking-summary-card');
    const mealContainer = document.getElementById('meal-options-container');
    const mealCheckboxes = document.getElementById('meal-checkboxes');
    const rooms = document.querySelectorAll('.room-card');
    const bookSubmitBtn = document.getElementById('book-submit-btn');

    // Book Entire Hall Switch Listener
    const bookEntireSwitch = document.getElementById('book_entire_hall_switch');
    if (bookEntireSwitch) {
        bookEntireSwitch.addEventListener('change', function() {
            const gridWrapper = document.getElementById('seating-layout-grid-wrapper');
            if (this.checked) {
                if (gridWrapper) gridWrapper.style.display = 'none';
                if (selectedSeatInput) selectedSeatInput.value = 'Entire Hall';
            } else {
                if (gridWrapper) gridWrapper.style.display = 'block';
                if (selectedSeatInput) selectedSeatInput.value = '';
                // Clear active styles on main seats
                if (seatGrid) {
                    seatGrid.querySelectorAll('.seat-btn').forEach(btn => {
                        if (btn.classList.contains('btn-primary')) {
                            btn.className = 'btn btn-outline-success seat-btn m-1';
                        }
                    });
                }
            }
            updateSummary();
            updateBookButton();
        });
    }

    // 2. Set min date to today
    if (bookingDate) {
        const today = new Date().toISOString().split('T')[0];
        bookingDate.min = today;
        if (!bookingDate.value) {
            bookingDate.value = today;
        }
    }

    // 3. Update End Time Options based on Start Time
    function updateEndTimeOptions() {
        if (!startTime || !endTime) return;
        const startVal = startTime.value;
        const endTimeOptions = endTime.options;

        for (let i = 0; i < endTimeOptions.length; i++) {
            endTimeOptions[i].disabled = false;
            endTimeOptions[i].style.display = '';
        }

        if (startVal) {
            const startHour = parseInt(startVal.split(':')[0]);
            const startMinute = parseInt(startVal.split(':')[1]);
            const startTotalMinutes = startHour * 60 + startMinute;

            for (let i = 1; i < endTimeOptions.length; i++) {
                const eVal = endTimeOptions[i].value;
                if (!eVal) continue;
                const endHour = parseInt(eVal.split(':')[0]);
                const endMinute = parseInt(eVal.split(':')[1]);
                const endTotalMinutes = endHour * 60 + endMinute;

                if (endTotalMinutes <= startTotalMinutes) {
                    endTimeOptions[i].disabled = true;
                    endTimeOptions[i].style.display = 'none';
                }
            }

            if (endTime.value && startVal >= endTime.value) {
                endTime.value = '';
            }
        }
    }

    // 4. Update Meals
    function updateMeals() {
        if (!mealContainer || !mealCheckboxes) return;
        const startVal = startTime ? startTime.value : '';
        const endVal = endTime ? endTime.value : '';

        if (!startVal || !endVal) {
            mealContainer.style.display = 'none';
            mealCheckboxes.innerHTML = '';
            return;
        }

        const mealData = {
            'Breakfast':        { price: 120, icon: '🌅', color: '#f59e0b', colorLight: '#fffbeb', colorBorder: '#fcd34d' },
            'Breakfast Buffet': { price: 150, icon: '🍳', color: '#d97706', colorLight: '#fef3c7', colorBorder: '#fbbf24' },
            'Lunch':            { price: 250, icon: '☀️', color: '#10b981', colorLight: '#ecfdf5', colorBorder: '#6ee7b7' },
            'Executive Lunch':  { price: 300, icon: '🍱', color: '#059669', colorLight: '#d1fae5', colorBorder: '#34d399' },
            'Hi-Tea':           { price: 150, icon: '🍵', color: '#8b5cf6', colorLight: '#f5f3ff', colorBorder: '#c4b5fd' },
            'Dinner':           { price: 300, icon: '🌙', color: '#3b82f6', colorLight: '#eff6ff', colorBorder: '#93c5fd' },
            'Premium Dinner':   { price: 450, icon: '🍽️', color: '#1d4ed8', colorLight: '#dbeafe', colorBorder: '#60a5fa' }
        };

        const eligibleMeals = [];
        if (startVal < '11:00' && endVal > '08:00') { eligibleMeals.push('Breakfast'); eligibleMeals.push('Breakfast Buffet'); }
        if (startVal < '15:00' && endVal > '12:00') { eligibleMeals.push('Lunch'); eligibleMeals.push('Executive Lunch'); }
        if (startVal < '18:00' && endVal > '16:00') eligibleMeals.push('Hi-Tea');
        if (startVal < '22:30' && endVal > '19:00') { eligibleMeals.push('Dinner'); eligibleMeals.push('Premium Dinner'); }

        const maxAttendees = attendee ? (parseInt(attendee.value) || 1) : 1;

        if (eligibleMeals.length > 0) {
            mealContainer.style.display = 'block';
            const currentChecked = Array.from(document.querySelectorAll('input[name="meals[]"]:checked')).map(cb => cb.value);

            let html = '<div class="row g-3 w-100 m-0">';
            eligibleMeals.forEach(m => {
                const d = mealData[m];
                const isChecked = currentChecked.includes(m);
                const mKey = m.replace(/[^a-zA-Z]/g, '');

                html += `
                    <div class="col-12 px-1">
                        <div class="meal-card rounded-3 p-0 overflow-hidden"
                             id="meal-card-${mKey}"
                             style="border: 2px solid ${isChecked ? d.color : d.colorBorder};
                                    background: #fff;
                                    transition: border-color 0.2s ease;">

                            <div class="d-flex align-items-center justify-content-between px-3 py-2"
                                 id="meal-header-${mKey}"
                                 style="background:${isChecked ? d.colorLight : '#fafafa'};
                                        border-bottom: 1px solid ${d.colorBorder};">
                                <div class="d-flex align-items-center gap-2">
                                    <span style="font-size:1.5rem;">${d.icon}</span>
                                    <div>
                                        <div class="fw-bold" style="color:${d.color}; font-size:1rem;">${m}</div>
                                        <div class="text-muted" style="font-size:0.72rem;">&#8377;${d.price}/person</div>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-3">
                                    <div id="meal-total-${mKey}" class="fw-bold" style="color:${d.color}; font-size:0.9rem;">
                                        Total: &#8377;${d.price}
                                    </div>
                                    <div class="form-check mb-0">
                                        <input class="form-check-input meal-cb" type="checkbox"
                                               name="meals[]" value="${m}"
                                               id="meal_${m}"
                                               ${isChecked ? 'checked' : ''}
                                               style="width:1.3rem; height:1.3rem; border-color:${d.color};">
                                    </div>
                                </div>
                            </div>

                            <div class="px-3 py-2 d-flex align-items-center justify-content-end gap-3"
                                 style="background: ${d.colorLight};">
                                <span class="fw-semibold small" style="color:${d.color};">&#128101; Persons:</span>
                                <div class="d-flex align-items-center gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-secondary px-2 py-0 meal-persons-btn"
                                            data-action="dec" data-meal="${m}" data-price="${d.price}" data-max="${maxAttendees}">&#8722;</button>
                                    <input type="number"
                                           name="meal_persons[${m}]"
                                           id="meal_persons_${mKey}"
                                           value="1"
                                           min="1" max="${maxAttendees}"
                                           class="form-control form-control-sm text-center px-1"
                                           style="width:55px;"
                                           oninput="window.recalcMealTotal('${m}', ${d.price})">
                                    <button type="button" class="btn btn-sm btn-outline-secondary px-2 py-0 meal-persons-btn"
                                            data-action="inc" data-meal="${m}" data-price="${d.price}" data-max="${maxAttendees}">+</button>
                                </div>
                                <span class="text-muted" style="font-size:0.7rem;">max ${maxAttendees}</span>
                            </div>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            mealCheckboxes.innerHTML = html;

            document.querySelectorAll('.meal-cb').forEach(cb => {
                cb.addEventListener('change', function() {
                    const mKey = this.value.replace(/[^a-zA-Z]/g, '');
                    const card = document.getElementById('meal-card-' + mKey);
                    const header = document.getElementById('meal-header-' + mKey);
                    const d = mealData[this.value];
                    if (card && d) {
                        card.style.borderColor = this.checked ? d.color : d.colorBorder;
                        if (header) header.style.background = this.checked ? d.colorLight : '#fafafa';
                    }
                    updateSummary();
                });
            });
        } else {
            mealContainer.style.display = 'none';
            mealCheckboxes.innerHTML = '';
        }
    }

    // 5. Global Window Meal Handlers
    window.recalcMealTotal = function(mealName, pricePerPerson) {
        const mKey = mealName.replace(/[^a-zA-Z]/g, '');
        const personsInput = document.getElementById('meal_persons_' + mKey);
        const totalDiv = document.getElementById('meal-total-' + mKey);
        if (!personsInput || !totalDiv) return;
        let persons = parseInt(personsInput.value) || 1;
        if (persons < 1) persons = 1;
        personsInput.value = persons;
        totalDiv.innerText = 'Total: ₹' + (pricePerPerson * persons);
        updateSummary();
    };

    window.changeMealPersons = function(mealName, pricePerPerson, delta, max) {
        const mKey = mealName.replace(/[^a-zA-Z]/g, '');
        const inp = document.getElementById('meal_persons_' + mKey);
        if (!inp) return;
        let val = parseInt(inp.value) || 1;
        val = val + delta;
        if (val < 1) val = 1;
        if (max && val > max) val = max;
        inp.value = val;
        window.recalcMealTotal(mealName, pricePerPerson);
    };

    if (mealCheckboxes) {
        mealCheckboxes.addEventListener('click', function(e) {
            const btn = e.target.closest('.meal-persons-btn');
            if (btn) {
                e.preventDefault();
                e.stopPropagation();
                const action = btn.dataset.action;
                const meal = btn.dataset.meal;
                const price = parseInt(btn.dataset.price);
                const max = parseInt(btn.dataset.max);
                window.changeMealPersons(meal, price, action === 'inc' ? 1 : -1, max);
            }
        });
    }

    // 6. Update Booking Summary
    function updateSummary() {
        if (!summaryCard) return;

        const dateVal = bookingDate ? bookingDate.value : '';
        const startVal = startTime ? startTime.value : '';
        const endVal = endTime ? endTime.value : '';
        const attVal = attendee ? attendee.value : '';
        const seatsVal = selectedSeatInput ? selectedSeatInput.value : '';

        let roomName = "-";
        if (hiddenRoom && hiddenRoom.value) {
            const activeRoomCard = document.querySelector(`.room-card[data-room-id="${hiddenRoom.value}"]`);
            if (activeRoomCard) {
                const h6 = activeRoomCard.querySelector('h6');
                if (h6) roomName = h6.innerText.trim();
            }
        }

        if (dateVal || startVal || endVal || attVal || seatsVal || roomName !== "-") {
            summaryCard.style.display = 'block';
            const sumRoom = document.getElementById('summary-room');
            if (sumRoom) sumRoom.innerText = roomName;

            const sumDate = document.getElementById('summary-date');
            if (sumDate) sumDate.innerText = dateVal || '-';

            const sumTime = document.getElementById('summary-time');
            if (sumTime) sumTime.innerText = (startVal && endVal) ? `${startVal} - ${endVal}` : '-';

            const sumAtt = document.getElementById('summary-attendees');
            if (sumAtt) sumAtt.innerText = attVal ? `${attVal} person(s)` : '-';

            const sumSeats = document.getElementById('summary-seats');
            if (sumSeats) sumSeats.innerText = (seatsVal === 'Entire Hall') ? 'Booked Entire Room' : (seatsVal || '-');

            const mealPrices = { 'Breakfast': 120, 'Breakfast Buffet': 150, 'Lunch': 250, 'Executive Lunch': 300, 'Hi-Tea': 150, 'Dinner': 300, 'Premium Dinner': 450 };
            const selectedMeals = Array.from(document.querySelectorAll('input[name="meals[]"]:checked')).map(cb => cb.value);
            const sumMealsRow = document.getElementById('summary-meals-row');
            const sumMeals = document.getElementById('summary-meals');
            if (selectedMeals.length > 0) {
                if (sumMealsRow) sumMealsRow.style.display = 'flex';
                const mealSummary = selectedMeals.map(m => {
                    const mKey = m.replace(/[^a-zA-Z]/g, '');
                    const personsInput = document.getElementById('meal_persons_' + mKey);
                    const persons = personsInput ? (parseInt(personsInput.value) || 1) : 1;
                    const total = (mealPrices[m] || 0) * persons;
                    return `${m} (₹${total} for ${persons} person${persons > 1 ? 's' : ''})`;
                }).join(', ');
                if (sumMeals) sumMeals.innerText = mealSummary;
            } else {
                if (sumMealsRow) sumMealsRow.style.display = 'none';
            }
        } else {
            summaryCard.style.display = 'none';
        }
    }

    // 7. Book Submit Button Enabler
    function updateBookButton() {
        if (!bookSubmitBtn) return;
        const hasDate = bookingDate && bookingDate.value;
        const hasStart = startTime && startTime.value;
        const hasEnd = endTime && endTime.value;
        const hasRoom = hiddenRoom && hiddenRoom.value;
        const hasSeat = selectedSeatInput && selectedSeatInput.value && selectedSeatInput.value.trim() !== '';

        const allReady = hasDate && hasStart && hasEnd && hasRoom && hasSeat;
        bookSubmitBtn.disabled = !allReady;
        if (allReady) {
            bookSubmitBtn.style.background = 'linear-gradient(135deg, #6f42c1, #8551d9)';
            bookSubmitBtn.style.cursor = 'pointer';
            bookSubmitBtn.innerHTML = '<i class="fas fa-check me-2"></i>Book Study Slot';
        } else {
            bookSubmitBtn.style.background = '#adb5bd';
            bookSubmitBtn.style.cursor = 'not-allowed';
            bookSubmitBtn.innerHTML = '<i class="fas fa-lock me-2"></i>Book Study Slot';
        }
    }

    // 8. Fetch Daily Schedules for Timeline
    function fetchSchedules(dateVal) {
        if (!dateVal) return;
        document.querySelectorAll(".room-card").forEach(room => {
            const roomId = room.dataset.roomId;
            const capacity = parseInt(room.dataset.capacity) || 0;
            const timelineContainer = document.getElementById(`timeline-${roomId}`);
            const scheduleList = document.getElementById(`schedule-list-${roomId}`);

            if (!timelineContainer || !scheduleList) return;

            timelineContainer.style.display = 'block';
            scheduleList.innerHTML = '<span class="text-muted"><i class="fas fa-spinner fa-spin"></i> Loading...</span>';

            fetch(`api/rooms.php?action=get_daily_schedule&room_id=${roomId}&booking_date=${dateVal}`)
                .then(res => res.json())
                .then(res => {
                    if (res.status === 'success') {
                        scheduleList.innerHTML = '';
                        const bookings = res.bookings || [];
                        const intervals = [];

                        let startH = 8, startM = 0;
                        while (startH < 22 || (startH === 22 && startM === 0)) {
                            let endH = startH, endM = startM + 30;
                            if (endM === 60) { endH++; endM = 0; }

                            const sTime = `${startH.toString().padStart(2, '0')}:${startM.toString().padStart(2, '0')}`;
                            const eTime = `${endH.toString().padStart(2, '0')}:${endM.toString().padStart(2, '0')}`;

                            let bookedSeats = 0;
                            bookings.forEach(b => {
                                const bStart = b.start_time.substring(0, 5);
                                const bEnd = b.end_time.substring(0, 5);
                                if (sTime >= bStart && sTime < bEnd) {
                                    const count = b.seat_number ? b.seat_number.split(',').filter(x => x.trim() !== '').length : 0;
                                    bookedSeats += count;
                                }
                            });

                            intervals.push({
                                start: sTime,
                                end: eTime,
                                status: bookedSeats === 0 ? 'available' : (bookedSeats >= capacity ? 'booked' : 'partial'),
                                seatsLeft: Math.max(0, capacity - bookedSeats)
                            });

                            startH = endH;
                            startM = endM;
                        }

                        const merged = [];
                        intervals.forEach(inv => {
                            if (merged.length === 0) {
                                merged.push(inv);
                            } else {
                                const last = merged[merged.length - 1];
                                if (last.status === inv.status && last.seatsLeft === inv.seatsLeft) {
                                    last.end = inv.end;
                                } else {
                                    merged.push(inv);
                                }
                            }
                        });

                        merged.forEach(b => {
                            const div = document.createElement('div');
                            div.className = 'd-flex justify-content-between border-bottom py-2';

                            if (b.status === 'booked') {
                                div.innerHTML = `
                                    <span><i class="far fa-clock me-1 text-danger"></i> ${b.start} - ${b.end}</span>
                                    <span class="text-danger fw-bold">Booked</span>
                                `;
                            } else if (b.status === 'partial') {
                                div.innerHTML = `
                                    <span><i class="far fa-clock me-1 text-warning"></i> ${b.start} - ${b.end}</span>
                                    <span class="text-warning fw-bold">${b.seatsLeft} seat(s) left</span>
                                `;
                            } else {
                                div.innerHTML = `
                                    <span><i class="far fa-clock me-1 text-success"></i> ${b.start} - ${b.end}</span>
                                    <span class="text-success fw-bold">Available</span>
                                `;
                            }
                            scheduleList.appendChild(div);
                        });

                        const formStart = startTime ? startTime.value : '';
                        const formEnd = endTime ? endTime.value : '';

                        if (formStart && formEnd) {
                            const uniqueSeats = new Set();
                            bookings.forEach(b => {
                                const bStart = b.start_time.substring(0, 5);
                                const bEnd = b.end_time.substring(0, 5);
                                if (formStart < bEnd && formEnd > bStart) {
                                    if (b.seat_number) {
                                        b.seat_number.split(',').forEach(s => {
                                            if (s.trim()) uniqueSeats.add(s.trim());
                                        });
                                    }
                                }
                            });

                            const occDisplay = document.querySelector(`#occupancy-display-${roomId} .occupancy-stats`);
                            if (occDisplay) {
                                occDisplay.style.display = 'inline-block';
                                const availSpan = occDisplay.querySelector('.avail-count');
                                if (availSpan) {
                                    availSpan.innerText = Math.max(0, capacity - uniqueSeats.size);
                                }
                            }
                        } else {
                            const occDisplay = document.querySelector(`#occupancy-display-${roomId} .occupancy-stats`);
                            if (occDisplay) {
                                occDisplay.style.display = 'inline-block';
                                const availSpan = occDisplay.querySelector('.avail-count');
                                if (availSpan) {
                                    availSpan.innerText = capacity;
                                }
                            }
                        }
                    } else {
                        scheduleList.innerHTML = '<span class="text-danger">Failed to load schedule</span>';
                    }
                })
                .catch(() => {
                    scheduleList.innerHTML = '<span class="text-danger">Failed to load</span>';
                });
        });
    }

    // 9. Fetch Booked Seats for Selected Room
    function fetchBookedSeats() {
        if (!hiddenRoom || !bookingDate || !startTime || !endTime || !seatGrid || !seatingContainer) return;
        const roomId = hiddenRoom.value;
        const dateVal = bookingDate.value;
        const startVal = startTime.value;
        const endVal = endTime.value;

        const currentlySelectedSeat = selectedSeatInput ? selectedSeatInput.value : '';

        if (!roomId || !dateVal || !startVal || !endVal) {
            seatingContainer.style.display = "none";
            return;
        }

        const activeRoomCard = document.querySelector(`.room-card[data-room-id="${roomId}"]`);
        if (!activeRoomCard) return;
        const capacity = parseInt(activeRoomCard.dataset.capacity) || 0;

        seatingContainer.style.display = "block";
        seatGrid.innerHTML = `<div class="text-muted"><i class="fas fa-spinner fa-spin"></i> Loading room layout...</div>`;

        // Always show Book Entire Room option for all rooms
        const bookEntireContainer = document.getElementById("book-entire-hall-container");
        const bookEntireSwitch = document.getElementById("book_entire_hall_switch");
        const gridWrapper = document.getElementById("seating-layout-grid-wrapper");

        if (bookEntireContainer) bookEntireContainer.style.display = "block";
        if (bookEntireSwitch && bookEntireSwitch.checked) {
            if (gridWrapper) gridWrapper.style.display = "none";
            if (selectedSeatInput) selectedSeatInput.value = "Entire Hall";
        }

        // Fetch booked seats
        fetch(`api/rooms.php?action=get_booked_seats&room_id=${roomId}&booking_date=${dateVal}&start_time=${startVal}&end_time=${endVal}`)
            .then(res => res.json())
            .then(res => {
                if (res.status === 'success') {
                    const booked = res.booked_seats || [];
                    const isEntireHallBooked = booked.includes('Entire Hall');
                    const hasSomeSeatsBooked = booked.length > 0;

                    // Always update Book Entire Room switch state based on availability
                    const switchInput = document.getElementById("book_entire_hall_switch");
                    const infoText = bookEntireContainer ? bookEntireContainer.querySelector("small") : null;
                    if (switchInput) {
                        if (isEntireHallBooked) {
                            switchInput.disabled = true;
                            switchInput.checked = false;
                            if (infoText) infoText.innerHTML = `<span class="text-danger fw-bold"><i class="fas fa-exclamation-triangle"></i> This room is already fully booked for the selected time range.</span>`;
                            if (gridWrapper) gridWrapper.style.display = "none";
                            selectedSeatInput.value = "";
                        } else if (hasSomeSeatsBooked) {
                            switchInput.disabled = true;
                            switchInput.checked = false;
                            if (infoText) infoText.innerHTML = `<span class="text-warning fw-bold"><i class="fas fa-exclamation-circle"></i> Book Entire Room is unavailable because some seats are already booked.</span>`;
                            if (gridWrapper) gridWrapper.style.display = "block";
                        } else {
                            switchInput.disabled = false;
                            if (infoText) infoText.innerText = "Check this option if you want to book the entire room instead of individual seats.";
                        }
                    }

                    seatGrid.innerHTML = `<div class="w-100 text-center mb-3 py-1 border rounded text-secondary fw-bold" style="background:#eef1f6; font-size: 0.75rem; letter-spacing: 1px;">📺 SCREEN</div>`;

                    const occDisplay = document.querySelector(`#occupancy-display-${roomId} .occupancy-stats`);
                    if (occDisplay) {
                        occDisplay.style.display = 'block';
                        const availSpan = occDisplay.querySelector('.avail-count');
                        if (availSpan) {
                            availSpan.innerText = isEntireHallBooked ? 0 : Math.max(0, capacity - booked.length);
                        }
                    }
                    
                    // Restore previously selected seats if they are still available
                    let validSelected = [];
                    if (currentlySelectedSeat && currentlySelectedSeat !== "Entire Hall") {
                        let seats = currentlySelectedSeat.split(',').map(s=>s.trim());
                        seats.forEach(s => {
                            if (!booked.includes(s) && s !== "") {
                                validSelected.push(s);
                            }
                        });
                    }
                    if (selectedSeatInput && selectedSeatInput.value !== "Entire Hall") {
                        selectedSeatInput.value = validSelected.join(',');
                    }
                    let selectedSeatsArr = validSelected;

                    const seatsPerRow = capacity > 50 ? 10 : 3;

                    for (let i = 1; i <= capacity; i++) {
                        const rowIdx = Math.floor((i - 1) / seatsPerRow);
                        const colNum = ((i - 1) % seatsPerRow) + 1;
                        let rowLetter = "";
                        let r = rowIdx;
                        do {
                            rowLetter = String.fromCharCode(65 + (r % 26)) + rowLetter;
                            r = Math.floor(r / 26) - 1;
                        } while (r >= 0);
                        const seatNum = `${rowLetter}${colNum}`;
                        const isBooked = booked.includes(seatNum) || isEntireHallBooked;

                        const seatBtn = document.createElement("button");
                        seatBtn.type = "button";
                        seatBtn.className = `btn ${isBooked ? 'btn-danger disabled' : 'btn-outline-success'} seat-btn m-1`;
                        seatBtn.style.width = "52px";
                        seatBtn.style.height = "52px";
                        seatBtn.style.fontWeight = "bold";
                        seatBtn.style.borderRadius = "8px";
                        seatBtn.style.display = "inline-flex";
                        seatBtn.style.flexDirection = "column";
                        seatBtn.style.alignItems = "center";
                        seatBtn.style.justifyContent = "center";
                        seatBtn.innerHTML = `<i class="fas ${isBooked ? 'fa-lock' : 'fa-chair'} mb-1"></i><span style="font-size:0.65rem;">${seatNum}</span>`;

                        if (!isBooked) {
                            seatBtn.addEventListener("click", function() {
                                let requiredSeats = parseInt(document.getElementById("attendees").value) || 1;
                                let currentArr = selectedSeatInput.value ? selectedSeatInput.value.split(',').map(s=>s.trim()) : [];
                                
                                if (currentArr.includes(seatNum)) {
                                    // Deselect it
                                    currentArr = currentArr.filter(s => s !== seatNum);
                                    seatBtn.className = "btn btn-outline-success seat-btn m-1";
                                } else {
                                    // Select it
                                    if (currentArr.length >= requiredSeats) {
                                        alert("You can only select " + requiredSeats + " seat(s) for " + requiredSeats + " attendee(s).");
                                        return;
                                    }
                                    currentArr.push(seatNum);
                                    seatBtn.className = "btn btn-primary seat-btn text-white m-1";
                                }
                                selectedSeatInput.value = currentArr.join(',');
                                updateSummary();
                                updateBookButton();
                            });
                            
                            if (selectedSeatsArr.includes(seatNum)) {
                                seatBtn.className = "btn btn-primary seat-btn text-white m-1";
                            }
                        }

                        seatGrid.appendChild(seatBtn);
                    }
                    const doorDiv = document.createElement("div");
                    doorDiv.className = "w-100 mt-2 text-center border-top pt-2 text-muted small fw-bold";
                    doorDiv.innerHTML = "🚪 DOOR";
                    seatGrid.appendChild(doorDiv);

                } else {
                    seatGrid.innerHTML = `<div class="text-danger">Failed to load layout.</div>`;
                }
            })
            .catch(err => {
                seatGrid.innerHTML = `<div class="text-danger">Error loading layout.</div>`;
            });
    }

    // 10. Central Trigger Function
    function triggerAllUpdates() {
        updateEndTimeOptions();
        updateMeals();
        updateSummary();
        fetchBookedSeats();
        updateBookButton();
        if (bookingDate && bookingDate.value) {
            fetchSchedules(bookingDate.value);
        }
    }

    // 11. Event Listeners
    ['change', 'input'].forEach(evtType => {
        if (bookingDate) bookingDate.addEventListener(evtType, triggerAllUpdates);
        if (startTime) startTime.addEventListener(evtType, triggerAllUpdates);
        if (endTime) endTime.addEventListener(evtType, triggerAllUpdates);
        if (attendee) attendee.addEventListener(evtType, triggerAllUpdates);
    });

    // 12. Room Cards Selection & Filtering
    function resetRooms() {
        if (hiddenRoom) hiddenRoom.value = "";
        if (seatingContainer) seatingContainer.style.display = "none";
        if (selectedSeatInput) selectedSeatInput.value = "";

        rooms.forEach(room => {
            room.classList.remove("selected", "available", "unavailable");
            const btn = room.querySelector(".select-room-btn");
            const badge = room.querySelector(".status-badge");
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = "Select";
                btn.classList.remove("btn-primary");
                btn.classList.add("btn-outline-primary");
            }
            if (badge) {
                badge.className = "status-badge";
                badge.innerHTML = "Select attendees first";
            }
        });
    }

    function updateRooms() {
        let people = attendee ? parseInt(attendee.value) : NaN;
        if (isNaN(people)) {
            resetRooms();
            return;
        }

        rooms.forEach(room => {
            room.classList.remove("selected", "available", "unavailable");
            const capacity = parseInt(room.dataset.capacity) || 0;
            const btn = room.querySelector(".select-room-btn");
            const badge = room.querySelector(".status-badge");

            if (btn) {
                btn.innerHTML = "Select";
                btn.classList.remove("btn-primary");
                btn.classList.add("btn-outline-primary");
            }

            if (capacity >= people) {
                room.classList.add("available");
                if (btn) btn.disabled = false;
                if (badge) {
                    badge.className = "status-badge badge-available";
                    badge.innerHTML = "✓ Available";
                }
            } else {
                room.classList.add("unavailable");
                if (btn) btn.disabled = true;
                if (badge) {
                    badge.className = "status-badge badge-unavailable";
                    badge.innerHTML = "✖ Capacity exceeded";
                }
            }
        });
    }

    if (attendee) attendee.addEventListener("change", updateRooms);
    updateRooms();

    rooms.forEach(room => {
        room.addEventListener("click", function(e) {
            if (e.target.closest('.view-layout-btn')) return;

            if (this.classList.contains("unavailable")) return;
            const btn = this.querySelector(".select-room-btn");
            if (btn && btn.disabled) return;

            rooms.forEach(r => {
                r.classList.remove("selected");
                const rBtn = r.querySelector(".select-room-btn");
                const rBadge = r.querySelector(".status-badge");

                if (rBtn && !rBtn.disabled) {
                    rBtn.innerHTML = "Select";
                    rBtn.classList.remove("btn-primary");
                    rBtn.classList.add("btn-outline-primary");
                    if (rBadge) {
                        rBadge.className = "status-badge badge-available";
                        rBadge.innerHTML = "✓ Available";
                    }
                }
            });

            this.classList.add("selected");
            if (btn) {
                btn.innerHTML = "✓ Selected";
                btn.classList.remove("btn-outline-primary");
                btn.classList.add("btn-primary");
            }
            const badge = this.querySelector(".status-badge");
            if (badge) badge.innerHTML = "";

            if (hiddenRoom) hiddenRoom.value = this.dataset.roomId;
            updateSummary();
            fetchBookedSeats();
            updateBookButton();
        });
    });

    // 13. Room Layout Modal Logic
    let lastViewLayoutBtn = null;
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.view-layout-btn');
        if (btn) {
            lastViewLayoutBtn = btn;
        }
    });

    const roomLayoutModal = document.getElementById('roomLayoutModal');
    if (roomLayoutModal) {
        roomLayoutModal.addEventListener('show.bs.modal', function (event) {
            const button = (event && event.relatedTarget) ?
                (event.relatedTarget.closest('.view-layout-btn') || event.relatedTarget) :
                lastViewLayoutBtn;

            if (!button) return;
            const roomName = button.getAttribute('data-room-name') || button.dataset.roomName || 'Room';
            const capacity = parseInt(button.getAttribute('data-capacity') || button.dataset.capacity) || 0;
            const roomId = button.getAttribute('data-room-id') || button.dataset.roomId;

            const modalTitle = roomLayoutModal.querySelector('.modal-title');
            const modalGrid = roomLayoutModal.querySelector('#modal-seat-grid');

            if (modalTitle) modalTitle.textContent = roomName + ' - Structure / Layout';
            if (modalGrid) modalGrid.innerHTML = `<div class="text-muted"><i class="fas fa-spinner fa-spin"></i> Loading room layout...</div>`;

            const dateVal = bookingDate ? bookingDate.value : '';
            const startVal = startTime ? startTime.value : '';
            const endVal = endTime ? endTime.value : '';

            let fetchUrl = null;
            if (dateVal && startVal && endVal && roomId) {
                fetchUrl = `api/rooms.php?action=get_booked_seats&room_id=${roomId}&booking_date=${dateVal}&start_time=${startVal}&end_time=${endVal}`;
            }

            const roomCard = document.querySelector(`.room-card[data-room-id="${roomId}"]`);

            const modalBookEntireContainer = document.getElementById("modal-book-entire-hall-container");
            const modalBookEntireBtn = document.getElementById("modal_book_entire_hall_btn");

            // Always show Book Entire Room option for all rooms
            if (modalBookEntireContainer) modalBookEntireContainer.style.display = "block";

            if (modalBookEntireBtn) {
                modalBookEntireBtn.onclick = function() {
                    const bookEntireSwitch = document.getElementById("book_entire_hall_switch");
                    if (bookEntireSwitch) {
                        bookEntireSwitch.checked = true;
                        bookEntireSwitch.dispatchEvent(new Event('change'));
                    }
                    if (roomCard && !roomCard.classList.contains("selected")) {
                        roomCard.click();
                    }
                    if (window.bootstrap && bootstrap.Modal) {
                        const modalInstance = bootstrap.Modal.getInstance(roomLayoutModal);
                        if (modalInstance) modalInstance.hide();
                    }
                };
            }

            const renderModalSeats = (booked) => {
                if (!modalGrid) return;
                modalGrid.innerHTML = '';

                const isEntireHallBooked = Array.isArray(booked) && booked.includes('Entire Hall');
                const hasSomeSeatsBooked = Array.isArray(booked) && booked.length > 0;

                // Always update modal Book Entire Room button state
                if (modalBookEntireBtn) {
                    if (isEntireHallBooked) {
                        modalBookEntireBtn.disabled = true;
                        modalBookEntireBtn.innerHTML = `<i class="fas fa-exclamation-triangle me-2"></i>Room Already Booked`;
                        modalBookEntireBtn.className = "btn btn-danger w-100 fw-bold";
                    } else if (hasSomeSeatsBooked) {
                        modalBookEntireBtn.disabled = true;
                        modalBookEntireBtn.innerHTML = `<i class="fas fa-exclamation-circle me-2"></i>Seats Already Booked`;
                        modalBookEntireBtn.className = "btn btn-warning w-100 fw-bold text-white";
                    } else {
                        modalBookEntireBtn.disabled = false;
                        modalBookEntireBtn.innerHTML = `<i class="fas fa-door-open me-2"></i>Book Entire Room`;
                        modalBookEntireBtn.className = "btn btn-primary w-100 fw-bold";
                    }
                }

                const seatsCount = capacity > 0 ? capacity : 10;
                const seatsPerRow = seatsCount > 50 ? 10 : 3;

                for (let i = 1; i <= seatsCount; i++) {
                    const rowIdx = Math.floor((i - 1) / seatsPerRow);
                    const colNum = ((i - 1) % seatsPerRow) + 1;
                    let rowLetter = "";
                    let r = rowIdx;
                    do {
                        rowLetter = String.fromCharCode(65 + (r % 26)) + rowLetter;
                        r = Math.floor(r / 26) - 1;
                    } while (r >= 0);
                    const seatNum = `${rowLetter}${colNum}`;
                    const isBooked = (Array.isArray(booked) && booked.includes(seatNum)) || isEntireHallBooked;

                    const seatBtn = document.createElement("button");
                    seatBtn.type = "button";
                    seatBtn.className = `btn ${isBooked ? 'btn-danger disabled' : 'btn-outline-success'} m-1 modal-seat-btn`;
                    seatBtn.style.width = "52px";
                    seatBtn.style.height = "52px";
                    seatBtn.style.fontWeight = "bold";
                    seatBtn.style.borderRadius = "8px";
                    seatBtn.style.display = "inline-flex";
                    seatBtn.style.flexDirection = "column";
                    seatBtn.style.alignItems = "center";
                    seatBtn.style.justifyContent = "center";
                    seatBtn.innerHTML = `<i class="fas ${isBooked ? 'fa-lock' : 'fa-chair'} mb-1"></i><span style="font-size:0.65rem;">${seatNum}</span>`;

                    if (!isBooked) {
                        seatBtn.addEventListener("click", function() {
                            let requiredSeats = attendee ? (parseInt(attendee.value) || 1) : 1;
                            let currentSelections = (selectedSeatInput && selectedSeatInput.value) ? selectedSeatInput.value.split(',').map(s=>s.trim()) : [];

                            if (currentSelections.includes(seatNum)) {
                                currentSelections = currentSelections.filter(s => s !== seatNum);
                                seatBtn.className = "btn btn-outline-success m-1 modal-seat-btn";
                            } else {
                                if (currentSelections.length >= requiredSeats) {
                                    alert("You can only select " + requiredSeats + " seat(s).");
                                    return;
                                }
                                currentSelections.push(seatNum);
                                seatBtn.className = "btn btn-primary text-white m-1 modal-seat-btn";
                            }

                            if (selectedSeatInput) selectedSeatInput.value = currentSelections.join(',');
                            updateSummary();

                            const roomCard = document.querySelector(`.room-card[data-room-id="${roomId}"]`);
                            if (roomCard && !roomCard.classList.contains("unavailable") && !roomCard.classList.contains("selected")) {
                                roomCard.click();
                            } else if (roomCard && roomCard.classList.contains("unavailable")) {
                                if (hiddenRoom) hiddenRoom.value = roomId;
                            }

                            if (currentSelections.length === requiredSeats) {
                                if (window.bootstrap && bootstrap.Modal) {
                                    const modalInstance = bootstrap.Modal.getInstance(roomLayoutModal);
                                    if (modalInstance) modalInstance.hide();
                                }
                            }
                        });

                        let currentSelections = (selectedSeatInput && selectedSeatInput.value) ? selectedSeatInput.value.split(',').map(s=>s.trim()) : [];
                        if (currentSelections.includes(seatNum)) {
                            seatBtn.className = "btn btn-primary text-white m-1 modal-seat-btn";
                        }
                    }
                    modalGrid.appendChild(seatBtn);
                }

                const doorDiv = document.createElement("div");
                doorDiv.className = "w-100 mt-3 text-center border-top pt-2 text-muted small fw-bold";
                doorDiv.innerHTML = "🚪 DOOR";
                modalGrid.appendChild(doorDiv);
            };

            if (fetchUrl) {
                fetch(fetchUrl)
                    .then(res => res.json())
                    .then(res => {
                        if (res.status === 'success') {
                            renderModalSeats(res.booked_seats || []);
                        } else {
                            renderModalSeats([]);
                        }
                    })
                    .catch(err => {
                        renderModalSeats([]);
                    });
            } else {
                renderModalSeats([]);
            }
        });
    }

    // 14. Initial Kick-off
    triggerAllUpdates();
});
</script>
</body>
</html>