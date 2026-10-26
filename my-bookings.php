<?php
require_once 'includes/config.php';

// Check if user is logged in
if (!SessionManager::isLoggedIn()) {
    header('Location: auth.php');
    exit();
}

$db = Database::getInstance()->getConnection();
$message = '';
$message_type = '';

// Handle booking cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_booking'])) {
    $booking_id = (int) $_POST['booking_id'];

    try {
        // Verify the booking belongs to the current user
        $stmt = $db->prepare("SELECT booking_id FROM bookings WHERE booking_id = ? AND user_id = ? AND status = 'confirmed'");
        $stmt->execute([$booking_id, SessionManager::getUserId()]);

        if ($stmt->fetch()) {
            // Cancel the booking
            $stmt = $db->prepare("UPDATE bookings SET status = 'cancelled' WHERE booking_id = ?");
            $stmt->execute([$booking_id]);

            $message = "Booking cancelled successfully.";
            $message_type = 'success';
        } else {
            $message = "Booking not found or cannot be cancelled.";
            $message_type = 'danger';
        }

    } catch (Exception $e) {
        logError("Booking cancellation failed: " . $e->getMessage());
        $message = "Failed to cancel booking. Please try again.";
        $message_type = 'danger';
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$date_filter = $_GET['date'] ?? 'all';

// Build query conditions
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

// Get user's bookings
$stmt = $db->prepare("
    SELECT * FROM booking_details 
    WHERE {$where_clause}
    ORDER BY booking_date DESC, start_time DESC
");
$stmt->execute($params);
$bookings = $stmt->fetchAll();

// Get statistics
$stmt = $db->prepare("SELECT COUNT(*) FROM bookings WHERE user_id = ?");
$stmt->execute([SessionManager::getUserId()]);
$total_bookings = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM bookings WHERE user_id = ? AND status = 'confirmed'");
$stmt->execute([SessionManager::getUserId()]);
$confirmed_bookings = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM bookings WHERE user_id = ? AND booking_date >= CURDATE() AND status = 'confirmed'");
$stmt->execute([SessionManager::getUserId()]);
$upcoming_bookings = $stmt->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookings - <?php echo APP_NAME; ?></title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .navbar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .main-content {
            padding: 2rem 0;
        }

        .booking-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            border: none;
            margin-bottom: 1.5rem;
            transition: transform 0.3s ease;
        }

        .booking-card:hover {
            transform: translateY(-3px);
        }

        .booking-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px 15px 0 0;
            padding: 1rem 1.5rem;
        }

        .booking-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-confirmed {
            background: #d4edda;
            color: #155724;
        }

        .status-cancelled {
            background: #f8d7da;
            color: #721c24;
        }

        .status-completed {
            background: #d1ecf1;
            color: #0c5460;
        }

        .amenity-badge {
            background: #e9ecef;
            color: #495057;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            margin: 2px;
        }

        .filter-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            border: none;
        }

        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
            text-align: center;
        }

        .stats-number {
            font-size: 2rem;
            font-weight: 700;
        }

        .btn-cancel {
            background: #dc3545;
            border: none;
            border-radius: 8px;
            color: white;
            padding: 6px 12px;
            font-size: 0.8rem;
        }

        .btn-view-qr {
            background: #28a745;
            border: none;
            border-radius: 8px;
            color: white;
            padding: 6px 12px;
            font-size: 0.8rem;
        }

        .qr-modal img {
            max-width: 100%;
            height: auto;
        }

        .page-header {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
    </style>
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
                        <a class="nav-link" href="book-room.php">
                            <i class="fas fa-plus me-1"></i>Book Room
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="my-bookings.php">
                            <i class="fas fa-calendar me-1"></i>My Bookings
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="qr-scanner.php">
                            <i class="fas fa-qrcode me-1"></i>QR Scanner
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
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h1 class="h3 mb-0">
                        <i class="fas fa-calendar text-primary me-2"></i>My Bookings
                    </h1>
                    <p class="text-muted mb-0">Manage your room reservations and view booking history</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <a href="book-room.php" class="btn btn-primary">
                        <i class="fas fa-plus me-2"></i>New Booking
                    </a>
                </div>
            </div>
        </div>

        <!-- Display message -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Statistics -->
            <div class="col-md-4 mb-4">
                <div class="stats-card mb-3">
                    <div class="stats-number"><?php echo $total_bookings; ?></div>
                    <div>Total Bookings</div>
                </div>
            </div>
            <div class="col-md-4 mb-4">
                <div class="stats-card mb-3">
                    <div class="stats-number"><?php echo $confirmed_bookings; ?></div>
                    <div>Confirmed</div>
                </div>
            </div>
            <div class="col-md-4 mb-4">
                <div class="stats-card mb-3">
                    <div class="stats-number"><?php echo $upcoming_bookings; ?></div>
                    <div>Upcoming</div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-card mb-4">
            <div class="card-body">
                <form method="GET" action="" class="row g-3">
                    <div class="col-md-4">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status" onchange="this.form.submit()">
                            <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status
                            </option>
                            <option value="confirmed" <?php echo $status_filter === 'confirmed' ? 'selected' : ''; ?>>
                                Confirmed</option>
                            <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>
                                Cancelled</option>
                            <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>
                                Completed</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="date" class="form-label">Date Range</label>
                        <select class="form-select" id="date" name="date" onchange="this.form.submit()">
                            <option value="all" <?php echo $date_filter === 'all' ? 'selected' : ''; ?>>All Dates</option>
                            <option value="upcoming" <?php echo $date_filter === 'upcoming' ? 'selected' : ''; ?>>Upcoming
                            </option>
                            <option value="today" <?php echo $date_filter === 'today' ? 'selected' : ''; ?>>Today</option>
                            <option value="past" <?php echo $date_filter === 'past' ? 'selected' : ''; ?>>Past</option>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <a href="my-bookings.php" class="btn btn-outline-secondary">
                            <i class="fas fa-undo me-2"></i>Clear Filters
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Bookings List -->
        <?php if (empty($bookings)): ?>
            <div class="text-center py-5">
                <i class="fas fa-calendar-times fa-4x text-muted mb-3"></i>
                <h4>No bookings found</h4>
                <p class="text-muted mb-3">You haven't made any bookings yet or no bookings match your filters.</p>
                <a href="book-room.php" class="btn btn-primary">
                    <i class="fas fa-plus me-2"></i>Make Your First Booking
                </a>
            </div>
        <?php else: ?>
            <?php foreach ($bookings as $booking): ?>
                <div class="booking-card">
                    <div class="booking-header">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h5 class="mb-0"><?php echo htmlspecialchars($booking['room_name']); ?></h5>
                                <small class="opacity-75">
                                    <i class="fas fa-hashtag me-1"></i>
                                    <?php echo htmlspecialchars($booking['booking_reference']); ?>
                                </small>
                            </div>
                            <div class="col-md-4 text-md-end">
                                <span class="booking-status status-<?php echo $booking['status']; ?>">
                                    <?php echo ucfirst($booking['status']); ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-2">
                                    <i class="fas fa-calendar text-primary me-2"></i>
                                    <strong>Date:</strong>
                                    <?php echo date('l, F j, Y', strtotime($booking['booking_date'])); ?>
                                </div>

                                <div class="mb-2">
                                    <i class="fas fa-clock text-primary me-2"></i>
                                    <strong>Time:</strong>
                                    <?php echo date('g:i A', strtotime($booking['start_time'])); ?> -
                                    <?php echo date('g:i A', strtotime($booking['end_time'])); ?>
                                </div>

                                <div class="mb-2">
                                    <i class="fas fa-map-marker-alt text-primary me-2"></i>
                                    <strong>Location:</strong>
                                    <?php echo htmlspecialchars($booking['location']); ?>
                                </div>

                                <?php
                                    // Extract meals from purpose field
                                    $raw_purpose_val = $booking['purpose'] ?? '';
                                    $extracted_meals = '';
                                    $clean_purpose = $raw_purpose_val;
                                    if (strpos($raw_purpose_val, 'Meals:') === 0) {
                                        // Handle new format with || separator
                                        if (strpos($raw_purpose_val, ' || Purpose: ') !== false) {
                                            $parts = explode(' || Purpose: ', $raw_purpose_val, 2);
                                            $extracted_meals = trim(str_replace('Meals: ', '', $parts[0]));
                                            $clean_purpose = $parts[1];
                                        // Handle old format with | separator
                                        } elseif (strpos($raw_purpose_val, ' | Purpose: ') !== false) {
                                            $parts = explode(' | Purpose: ', $raw_purpose_val, 2);
                                            $extracted_meals = trim(str_replace('Meals: ', '', $parts[0]));
                                            $clean_purpose = $parts[1];
                                        } else {
                                            $extracted_meals = trim(str_replace('Meals: ', '', $raw_purpose_val));
                                            $clean_purpose = '';
                                        }
                                    }
                                    // Format detailed meals: split by | and display each
                                    $meal_lines = array_filter(array_map('trim', explode(' | ', $extracted_meals)));
                                ?>

                                <?php if ($extracted_meals): ?>
                                    <div class="mb-2">
                                        <i class="fas fa-utensils text-primary me-2"></i>
                                        <strong>Meals:</strong>
                                        <?php if (!empty($meal_lines)): ?>
                                            <?php foreach ($meal_lines as $ml): ?>
                                                <span class="badge bg-warning text-dark me-1 mb-1" style="font-weight:500; font-size:0.75rem;"><?php echo htmlspecialchars($ml); ?></span>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($extracted_meals); ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="mb-2">
                                    <i class="fas fa-users text-primary me-2"></i>
                                    <strong>Attendees:</strong>
                                    <?php echo $booking['attendees']; ?> people
                                </div>

                                <?php if (!empty($booking['seat_number'])): ?>
                                    <div class="mb-2">
                                        <i class="fas fa-chair text-primary me-2"></i>
                                        <strong>Seat/Desk:</strong>
                                        <span class="badge bg-primary"><?php echo htmlspecialchars($booking['seat_number']); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="col-md-6">
                                <?php if ($clean_purpose): ?>
                                    <div class="mb-2">
                                        <i class="fas fa-comment text-primary me-2"></i>
                                        <strong>Purpose:</strong><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($clean_purpose); ?></small>
                                    </div>
                                <?php endif; ?>

                                <?php if (isset($booking['amenities']) && $booking['amenities']): ?>
                                    <div class="mb-2">
                                        <i class="fas fa-star text-primary me-2"></i>
                                        <strong>Amenities:</strong><br>
                                        <?php
                                        $amenities = json_decode($booking['amenities'], true);
                                        if ($amenities) {
                                            foreach ($amenities as $amenity) {
                                                echo '<span class="amenity-badge">' . htmlspecialchars(str_replace('_', ' ', $amenity)) . '</span>';
                                            }
                                        }
                                        ?>
                                    </div>
                                <?php endif; ?>

                                <div class="mb-2">
                                    <small class="text-muted">
                                        <i class="fas fa-plus me-1"></i>
                                        Booked on <?php echo date('M j, Y \a\t g:i A', strtotime($booking['created_at'])); ?>
                                    </small>
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="border-top pt-3 mt-3">
                            <div class="d-flex gap-2 flex-wrap">
                                <?php if ($booking['status'] === 'confirmed'): ?>
                                    <a href="generate-qr.php?ref=<?php echo $booking['booking_reference']; ?>"
                                        class="btn btn-view-qr btn-sm" target="_blank">
                                        <i class="fas fa-qrcode me-1"></i>View QR Code
                                    </a>
                                <?php endif; ?>

                                <?php if ($booking['status'] === 'confirmed' && $booking['booking_date'] >= date('Y-m-d')): ?>
                                    <form method="POST" action="" class="d-inline"
                                        onsubmit="return confirm('Are you sure you want to cancel this booking?')">
                                        <input type="hidden" name="booking_id" value="<?php echo $booking['booking_id']; ?>">
                                        <button type="submit" name="cancel_booking" class="btn btn-cancel btn-sm">
                                            <i class="fas fa-times me-1"></i>Cancel
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <small class="text-muted ms-auto align-self-center">
                                    Capacity: <?php echo $booking['capacity']; ?> people
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>