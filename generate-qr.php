<?php
require_once 'includes/config.php';

// Check login
if (!SessionManager::isLoggedIn()) {
    header('Location: auth.php');
    exit();
}

// Get booking reference
$booking_ref = $_GET['ref'] ?? '';
if (empty($booking_ref)) {
    header('Location: my-bookings.php');
    exit();
}

// Fetch booking details
try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT * FROM booking_details WHERE booking_reference = ?");
    $stmt->execute([$booking_ref]);
    $booking = $stmt->fetch();

    if (!$booking) {
        header('Location: my-bookings.php?error=booking_not_found');
        exit();
    }
} catch (Exception $e) {
    header('Location: my-bookings.php?error=database_error');
    exit();
}

// QR data
$qr_data = json_encode([
    'type' => 'booking_verification',
    'booking_reference' => $booking['booking_reference'],
    'room_name' => $booking['room_name'],
    'booking_date' => $booking['booking_date'],
    'start_time' => $booking['start_time'],
    'end_time' => $booking['end_time'],
    'user' => $booking['full_name'],
    'verify_url' => 'http://localhost/Study-Slot-Booking-System-main/verify-booking.php?ref=' . $booking['booking_reference']
]);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking QR Code - <?php echo htmlspecialchars($booking['booking_reference']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/gh/davidshimjs/qrcodejs/qrcode.min.js"></script>
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }
        .qr-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            padding: 2rem;
            text-align: center;
            max-width: 700px;
            margin: 2rem auto;
        }
        .qr-code-display {
            width: 100%;
            max-width: 280px;
            margin: 0 auto;
            padding: 15px;
            background: white;
            border: 2px solid #dee2e6;
            border-radius: 10px;
            overflow: hidden;
        }
        #qr-code canvas,
        #qr-code img {
            width: 100% !important;
            height: auto !important;
        }
        @media print {
            .no-print { display: none; }
            .qr-container {
                box-shadow: none;
                border: 1px solid #000;
            }
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="no-print mb-3">
            <a href="my-bookings.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to My Bookings
            </a>
            <button onclick="window.print()" class="btn btn-primary float-end">
                <i class="fas fa-print me-2"></i>Print QR Code
            </button>
        </div>

        <div class="qr-container">
            <h2 class="mb-4"><i class="fas fa-qrcode text-primary me-2"></i>Booking QR Code</h2>

            <div class="row mb-4">
                <div class="col-md-6 text-start">
                    <h6>Booking Reference:</h6>
                    <strong><?php echo htmlspecialchars($booking['booking_reference']); ?></strong>
                </div>
                <div class="col-md-6 text-start">
                    <h6>Room:</h6>
                    <strong><?php echo htmlspecialchars($booking['room_name']); ?></strong>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-md-6 text-start">
                    <h6>Date:</h6>
                    <strong><?php echo date('F j, Y', strtotime($booking['booking_date'])); ?></strong>
                </div>
                <div class="col-md-6 text-start">
                    <h6>Time:</h6>
                    <strong><?php echo date('g:i A', strtotime($booking['start_time'])); ?> - <?php echo date('g:i A', strtotime($booking['end_time'])); ?></strong>
                </div>
            </div>

            <div class="qr-code-display mb-4">
                <div id="qr-code"></div>
            </div>

            <p class="text-muted mb-0">
                <i class="fas fa-info-circle me-2"></i>
                Scan this QR code at the room entrance or show it to the librarian for verification
            </p>

            <div class="no-print mt-4">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0">QR Code Data (for manual entry)</h6>
                    </div>
                    <div class="card-body">
                        <textarea class="form-control" rows="6" readonly><?php echo $qr_data; ?></textarea>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const qrData = `<?php echo $qr_data; ?>`;
            const qrContainer = document.getElementById('qr-code');
            qrContainer.innerHTML = '';
            new QRCode(qrContainer, {
                text: qrData,
                width: 240,
                height: 240,
                colorDark: '#000000',
                colorLight: '#FFFFFF',
                correctLevel: QRCode.CorrectLevel.H
            });
        });
    </script>
</body>
</html>
