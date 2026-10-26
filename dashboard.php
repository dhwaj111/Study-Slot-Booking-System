<?php
require_once 'includes/config.php';

// Ensure session is started
SessionManager::start();

// Check if user is logged in
if (!SessionManager::isLoggedIn()) {
    header('Location: auth.php');
    exit();
}

// Handle logout
if (isset($_GET['logout'])) {
    SessionManager::logout();
    header('Location: auth.php');
    exit();
}

$db = Database::getInstance()->getConnection();

// Get user's bookings
$stmt = $db->prepare("
    SELECT * FROM booking_details 
    WHERE username = ? 
    ORDER BY booking_date DESC, start_time DESC
    LIMIT 10
");
$stmt->execute([SessionManager::getUsername()]);
$user_bookings = $stmt->fetchAll();

// Get upcoming bookings
$stmt = $db->prepare("
    SELECT * FROM booking_details 
    WHERE username = ? AND booking_date >= CURDATE() AND status = 'confirmed'
    ORDER BY booking_date ASC, start_time ASC
");
$stmt->execute([SessionManager::getUsername()]);
$upcoming_bookings = $stmt->fetchAll();

// Get total bookings count
$stmt = $db->prepare("SELECT COUNT(*) as total FROM bookings WHERE user_id = ?");
$stmt->execute([SessionManager::getUserId()]);
$total_bookings = $stmt->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo APP_NAME; ?></title>

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

        .navbar-brand {
            font-weight: 600;
            font-size: 1.3rem;
        }

        .main-content {
            padding: 2rem 0;
        }

        .dashboard-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            border: none;
            transition: transform 0.3s ease;
        }

        .dashboard-card:hover {
            transform: translateY(-5px);
        }

        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }

        .stats-card .stats-number {
            font-size: 2.5rem;
            font-weight: 700;
        }

        .stats-card .stats-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .quick-action-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 10px;
            color: white;
            padding: 12px 20px;
            font-weight: 500;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }

        .quick-action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
            color: white;
        }

        .booking-card {
            border-left: 4px solid #667eea;
            margin-bottom: 1rem;
        }

        .booking-status {
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .booking-status.confirmed {
            color: #28a745;
        }

        .booking-status.cancelled {
            color: #dc3545;
        }

        .booking-status.completed {
            color: #6c757d;
        }

        .amenity-badge {
            background: #e9ecef;
            color: #495057;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            margin: 2px;
        }

        .page-header {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .welcome-text {
            color: #667eea;
            font-weight: 600;
        }
    </style>
</head>

<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-door-open me-2"></i>
                <?php echo APP_NAME; ?>
            </a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="dashboard.php">
                            <i class="fas fa-tachometer-alt me-1"></i>Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="book-room.php">
                            <i class="fas fa-plus me-1"></i>Book Study Slot
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="my-bookings.php">
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
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button"
                            data-bs-toggle="dropdown">
                            <i class="fas fa-user me-1"></i>
                            <?php echo htmlspecialchars(SessionManager::getUsername()); ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="profile.php">
                                    <i class="fas fa-user-cog me-2"></i>Profile Settings
                                </a></li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li><a class="dropdown-item" href="?logout=1">
                                    <i class="fas fa-sign-out-alt me-2"></i>Logout
                                </a></li>
                        </ul>
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
                    <h1 class="h3 mb-0">Welcome back, <span
                            class="welcome-text"><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>!</h1>
                    <p class="text-muted mb-0">Manage your study slot bookings and view your schedule</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <div class="text-muted">
                        <i class="fas fa-calendar me-1"></i>
                        <?php echo date('l, F j, Y'); ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Stats Column -->
            <div class="col-lg-3 col-md-6 mb-4">
                <div class="stats-card">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <div class="stats-number"><?php echo $total_bookings; ?></div>
                            <div class="stats-label">Total Bookings</div>
                        </div>
                        <div class="ms-3">
                            <i class="fas fa-calendar-check fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>

                <div class="stats-card">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <div class="stats-number"><?php echo count($upcoming_bookings); ?></div>
                            <div class="stats-label">Upcoming</div>
                        </div>
                        <div class="ms-3">
                            <i class="fas fa-clock fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="col-lg-9 col-md-6 mb-4">
                <div class="dashboard-card h-100">
                    <div class="card-body">
                        <h5 class="card-title mb-3">
                            <i class="fas fa-bolt text-primary me-2"></i>Quick Actions
                        </h5>

                        <div class="row g-3">
                            <div class="col-md-4">
                                <a href="book-room.php" class="quick-action-btn w-100 text-center">
                                    <i class="fas fa-plus me-2"></i>Book New Study Slot
                                </a>
                            </div>
                            <div class="col-md-4">
                                <a href="qr-scanner.php" class="quick-action-btn w-100 text-center">
                                    <i class="fas fa-qrcode me-2"></i>Scan QR Code
                                </a>
                            </div>
                            <div class="col-md-4">
                                <a href="my-bookings.php" class="quick-action-btn w-100 text-center">
                                    <i class="fas fa-list me-2"></i>View All Bookings
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Upcoming Bookings -->
            <div class="col-lg-6 mb-4">
                <div class="dashboard-card h-100">
                    <div class="card-body">
                        <h5 class="card-title mb-3">
                            <i class="fas fa-calendar-alt text-primary me-2"></i>Upcoming Bookings
                        </h5>

                        <?php if (empty($upcoming_bookings)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No upcoming bookings</p>
                                <a href="book-room.php" class="btn btn-primary btn-sm">Book a Study Slot</a>
                            </div>
                        <?php else: ?>
                            <?php foreach ($upcoming_bookings as $booking): ?>
                                <div class="card booking-card">
                                    <div class="card-body py-3">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <h6 class="mb-1"><?php echo htmlspecialchars($booking['room_name']); ?></h6>
                                                <p class="text-muted small mb-1">
                                                    <i class="fas fa-calendar me-1"></i>
                                                    <?php echo date('M j, Y', strtotime($booking['booking_date'])); ?>
                                                    <i class="fas fa-clock ms-2 me-1"></i>
                                                    <?php echo date('g:i A', strtotime($booking['start_time'])); ?> -
                                                    <?php echo date('g:i A', strtotime($booking['end_time'])); ?>
                                                </p>
                                                <p class="text-muted small mb-0">
                                                    <i class="fas fa-users me-1"></i>
                                                    <?php echo $booking['attendees']; ?> attendees
                                                </p>
                                            </div>
                                            <div class="text-end">
                                                <span class="booking-status <?php echo $booking['status']; ?>">
                                                    <?php echo ucfirst($booking['status']); ?>
                                                </span>
                                                <?php if ($booking['qr_code']): ?>
                                                    <br><small class="text-muted">
                                                        <i class="fas fa-qrcode"></i> QR Available
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="col-lg-6 mb-4">
                <div class="dashboard-card h-100">
                    <div class="card-body">
                        <h5 class="card-title mb-3">
                            <i class="fas fa-history text-primary me-2"></i>Recent Bookings
                        </h5>

                        <?php if (empty($user_bookings)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-history fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No booking history</p>
                            </div>
                        <?php else: ?>
                            <?php foreach (array_slice($user_bookings, 0, 5) as $booking): ?>
                                <div class="d-flex align-items-center mb-3 pb-3 border-bottom">
                                    <div class="flex-grow-1">
                                        <h6 class="mb-1"><?php echo htmlspecialchars($booking['room_name']); ?></h6>
                                        <small class="text-muted">
                                            <?php echo date('M j, Y', strtotime($booking['booking_date'])); ?> •
                                            <?php echo date('g:i A', strtotime($booking['start_time'])); ?>
                                        </small>
                                    </div>
                                    <span class="booking-status <?php echo $booking['status']; ?>">
                                        <?php echo ucfirst($booking['status']); ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>

                            <div class="text-center mt-3">
                                <a href="my-bookings.php" class="btn btn-outline-primary btn-sm">
                                    View All Bookings
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>