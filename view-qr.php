<?php
require_once 'includes/config.php';

// Check if user is logged in
if (!SessionManager::isLoggedIn()) {
    header('Location: auth.php');
    exit();
}

$qr_code = $_GET['qr'] ?? '';
$booking_ref = $_GET['ref'] ?? '';

if (empty($qr_code) && empty($booking_ref)) {
    header('Location: my-bookings.php');
    exit();
}

try {
    $db = Database::getInstance()->getConnection();

    if (!empty($booking_ref)) {
        // Get booking by reference
        $stmt = $db->prepare("SELECT * FROM booking_details WHERE booking_reference = ? AND username = ?");
        $stmt->execute([$booking_ref, SessionManager::getUsername()]);
    } else {
        // Get booking by QR code filename
        $stmt = $db->prepare("SELECT * FROM booking_details WHERE qr_code = ? AND username = ?");
        $stmt->execute([$qr_code, SessionManager::getUsername()]);
    }

    $booking = $stmt->fetch();

    if (!$booking) {
        $error = "Booking not found or you don't have permission to view it.";
    }

} catch (Exception $e) {
    $error = "Error retrieving booking information.";
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Code - <?= $booking['booking_reference'] ?? 'Error' ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .qr-container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }

        .qr-display {
            border: 3px solid #333;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            background: #f8f9fa;
            margin: 20px 0;
        }

        .booking-info {
            background: #e9ecef;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }

        .qr-code-svg {
            max-width: 300px;
            margin: 20px auto;
            display: block;
        }
    </style>
</head>

<body>
    <div class="container mt-4">
        <div class="qr-container">
            <?php if (isset($error)): ?>
                <div class="alert alert-danger">
                    <h4>❌ Error</h4>
                    <p><?= htmlspecialchars($error) ?></p>
                    <a href="my-bookings.php" class="btn btn-primary">← Back to My Bookings</a>
                </div>
            <?php else: ?>
                <div class="text-center">
                    <h2>📱 Booking QR Code</h2>
                    <p class="text-muted">Reference: <strong><?= htmlspecialchars($booking['booking_reference']) ?></strong>
                    </p>
                </div>

                <div class="booking-info">
                    <div class="row">
                        <div class="col-md-6">
                            <h5>📍 Booking Details</h5>
                            <p><strong>Room:</strong> <?= htmlspecialchars($booking['room_name']) ?></p>
                            <p><strong>Location:</strong> <?= htmlspecialchars($booking['location']) ?></p>
                            <p><strong>Date:</strong> <?= date('F j, Y', strtotime($booking['booking_date'])) ?></p>
                            <p><strong>Time:</strong> <?= date('g:i A', strtotime($booking['start_time'])) ?> -
                                <?= date('g:i A', strtotime($booking['end_time'])) ?></p>
                        </div>
                        <div class="col-md-6">
                            <h5>👤 Booking Information</h5>
                            <p><strong>Booked by:</strong> <?= htmlspecialchars($booking['full_name']) ?></p>
                            <p><strong>Attendees:</strong> <?= $booking['attendees'] ?> people</p>
                            <p><strong>Status:</strong>
                                <span
                                    class="badge bg-<?= $booking['status'] === 'confirmed' ? 'success' : ($booking['status'] === 'cancelled' ? 'danger' : 'warning') ?>">
                                    <?= ucfirst($booking['status']) ?>
                                </span>
                            </p>
                            <p><strong>Purpose:</strong> <?= htmlspecialchars($booking['purpose'] ?: 'Not specified') ?></p>
                        </div>
                    </div>
                </div>

                <div class="qr-display">
                    <h4>📱 QR Code</h4>
                    <?php
                    $qr_file = 'qr_codes/' . $booking['qr_code'];
                    if (file_exists($qr_file) && pathinfo($qr_file, PATHINFO_EXTENSION) === 'svg'):
                        ?>
                        <div class="qr-code-svg">
                            <?= file_get_contents($qr_file) ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <h5>📱 QR Code: <?= htmlspecialchars($booking['booking_reference']) ?></h5>
                            <div
                                style="border: 2px solid #333; width: 200px; height: 200px; margin: 20px auto; display: flex; align-items: center; justify-content: center; background: #f0f0f0;">
                                <div style="text-align: center;">
                                    📱<br>
                                    <small><?= htmlspecialchars($booking['booking_reference']) ?></small>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    <p class="text-muted mt-3">
                        <small>Show this QR code when entering the room</small>
                    </p>
                </div>

                <div class="text-center">
                    <a href="my-bookings.php" class="btn btn-primary">← Back to My Bookings</a>
                    <a href="qr_codes/<?= htmlspecialchars($booking['booking_reference']) ?>_display.html" target="_blank"
                        class="btn btn-secondary">🖨️ Print Version</a>
                    <?php if ($booking['status'] === 'confirmed' && strtotime($booking['booking_date']) >= strtotime(date('Y-m-d'))): ?>
                        <a href="my-bookings.php?cancel=<?= $booking['booking_id'] ?>" class="btn btn-outline-danger"
                            onclick="return confirm('Are you sure you want to cancel this booking?')">❌ Cancel Booking</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>