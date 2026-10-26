<?php
require_once 'includes/config.php';

$booking_ref = $_GET['ref'] ?? '';
$error = '';
$booking = null;

if (empty($booking_ref)) {
    $error = "No booking reference provided.";
} else {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM booking_details WHERE booking_reference = ?");
        $stmt->execute([$booking_ref]);
        $booking = $stmt->fetch();

        if (!$booking) {
            $error = "Booking reference not found.";
        }

    } catch (Exception $e) {
        $error = "Error retrieving booking information.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Booking Verification - <?= htmlspecialchars($booking_ref) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
<style>
body { background: #f8f9fa; }
.verification-container { max-width: 700px; margin: 2rem auto; padding: 20px; }
.verification-card { border-radius: 15px; background: #fff; padding: 25px; text-align: center; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
.status-icon { font-size: 4rem; color: #28a745; margin-bottom: 15px; }
.table { background: white; border-radius: 10px; overflow: hidden; }
th { background-color: #e9f7ef; }
@media (max-width: 576px) {
    .verification-container { padding: 10px; }
    .table td, .table th { font-size: 0.9rem; }
}
</style>
</head>
<body>
<div class="container verification-container">
<?php if ($error): ?>
    <div class="verification-card border-danger">
        <div class="status-icon text-danger">
            <i class="fas fa-times-circle"></i>
        </div>
        <h3 class="text-danger">Verification Failed</h3>
        <p class="text-muted"><?= htmlspecialchars($error) ?></p>
        <a href="auth.php" class="btn btn-primary"><i class="fas fa-sign-in-alt me-2"></i>Login</a>
    </div>
<?php else: ?>
    <div class="verification-card">
        <div class="status-icon">
            <i class="fas fa-check-circle"></i>
        </div>
        <h3 class="text-success mb-3">Booking Verified</h3>
        <p class="text-muted mb-4">This is a valid booking!</p>

        <div class="table-responsive">
            <table class="table table-bordered align-middle text-start">
                <tbody>
                    <tr>
                        <th><i class="fas fa-hashtag text-success me-2"></i>Reference</th>
                        <td><span class="badge bg-success fs-6"><?= htmlspecialchars($booking['booking_reference']) ?></span></td>
                    </tr>
                    <tr>
                        <th><i class="fas fa-user text-success me-2"></i>Booked By</th>
                        <td><?= htmlspecialchars($booking['full_name']) ?></td>
                    </tr>
                    <tr>
                        <th><i class="fas fa-door-open text-success me-2"></i>Room</th>
                        <td><?= htmlspecialchars($booking['room_name']) ?></td>
                    </tr>
                    <tr>
                        <th><i class="fas fa-map-marker-alt text-success me-2"></i>Location</th>
                        <td><?= htmlspecialchars($booking['location']) ?></td>
                    </tr>
                    <tr>
                        <th><i class="fas fa-calendar text-success me-2"></i>Date</th>
                        <td><?= date('l, F j, Y', strtotime($booking['booking_date'])) ?></td>
                    </tr>
                    <tr>
                        <th><i class="fas fa-clock text-success me-2"></i>Time</th>
                        <td><?= date('g:i A', strtotime($booking['start_time'])) ?> - <?= date('g:i A', strtotime($booking['end_time'])) ?></td>
                    </tr>
                    <tr>
                        <th><i class="fas fa-users text-success me-2"></i>Attendees</th>
                        <td><?= htmlspecialchars($booking['attendees']) ?> people</td>
                    </tr>
                    <tr>
                        <th><i class="fas fa-info-circle text-success me-2"></i>Status</th>
                        <td>
                            <span class="badge bg-<?= $booking['status'] === 'confirmed' ? 'success' : ($booking['status'] === 'cancelled' ? 'danger' : 'warning') ?>">
                                <?= ucfirst($booking['status']) ?>
                            </span>
                        </td>
                    </tr>
                    <?php if ($booking['purpose']): ?>
                    <tr>
                        <th><i class="fas fa-comment text-success me-2"></i>Purpose</th>
                        <td><em><?= htmlspecialchars($booking['purpose']) ?></em></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="alert alert-info mt-4">
            <i class="fas fa-info-circle me-2"></i>
            <strong>Verification Time:</strong> <?= date('F j, Y \a\t g:i A') ?>
        </div>

        <?php if ($booking['status'] === 'confirmed'): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i>This booking is <strong>confirmed</strong>.
            </div>
        <?php elseif ($booking['status'] === 'cancelled'): ?>
            <div class="alert alert-danger">
                <i class="fas fa-ban me-2"></i>This booking has been <strong>cancelled</strong>.
            </div>
        <?php endif; ?>

        <a href="auth.php" class="btn btn-outline-primary mt-3"><i class="fas fa-arrow-left me-2"></i>Return to Login</a>
    </div>
<?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
