<?php
require_once 'includes/config.php';
require_once 'includes/simple-qr.php';

// Check if user is logged in
if (!SessionManager::isLoggedIn()) {
    header('Location: auth.php');
    exit();
}

$db = Database::getInstance()->getConnection();
$scan_result = '';
$scan_type = '';
$booking = null;
$prefilled_data = '';

// Check for test data in URL
if (isset($_GET['test_data'])) {
    $prefilled_data = $_GET['test_data'];
}

// Handle QR code verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_qr'])) {
    $qr_data = trim($_POST['qr_data'] ?? '');
    
    if (!empty($qr_data)) {
        // Try to extract booking reference from QR data
        $booking_ref = '';
        $scan_result = '';
        $scan_type = '';

        // Debug logging
        error_log("QR Data received: " . substr($qr_data, 0, 200));

        // Check if it's JSON data (our new format)
        $json_data = @json_decode($qr_data, true);
        if ($json_data && isset($json_data['booking_reference'])) {
            $booking_ref = $json_data['booking_reference'];
            
            // Create a formatted HTML table for the booking details
            $scan_result = '
            <div class="ticket-container">
                <table class="ticket-table">
                    <tr>
                        <th colspan="2"><h3>🎫 Booking Ticket</h3></th>
                    </tr>
                    <tr>
                        <td><strong>Reference</strong></td>
                        <td>' . htmlspecialchars($json_data['booking_reference']) . '</td>
                    </tr>
                    <tr>
                        <td><strong>Room</strong></td>
                        <td>' . htmlspecialchars($json_data['room_name']) . '</td>
                    </tr>
                    <tr>
                        <td><strong>Date</strong></td>
                        <td>' . htmlspecialchars($json_data['booking_date']) . '</td>
                    </tr>
                    <tr>
                        <td><strong>Time</strong></td>
                        <td>' . htmlspecialchars($json_data['start_time']) . ' - ' . htmlspecialchars($json_data['end_time']) . '</td>
                    </tr>
                    <tr>
                        <td><strong>User</strong></td>
                        <td>' . htmlspecialchars($json_data['username']) . '</td>
                    </tr>
                    <tr>
                        <td><strong>Status</strong></td>
                        <td><span class="status-badge">✓ Valid</span></td>
                    </tr>
                </table>
            </div>';
            $scan_type = 'success';
        }
        // Try to fix incomplete JSON by looking for booking_reference in the raw data
        elseif (preg_match('/"booking_reference":"([^"]+)"/', $qr_data, $matches)) {
            $booking_ref = $matches[1];
            $scan_result = "Incomplete JSON detected. Extracted booking reference: " . $booking_ref;
            $scan_type = 'warning';
        }
        // Check if it's plain text with booking reference
        elseif (preg_match('/Reference:\s*([A-Z0-9]+)/i', $qr_data, $matches)) {
            $booking_ref = $matches[1];
            $scan_result = "Text format detected. Booking reference: " . $booking_ref;
            $scan_type = 'info';
        }
        // Check if it's just a booking reference
        elseif (preg_match('/^BK\d+$/i', $qr_data)) {
            $booking_ref = $qr_data;
            $scan_result = "Direct booking reference detected: " . $booking_ref;
            $scan_type = 'info';
        }
        // Extract from verification URL
        elseif (preg_match('/ref=([A-Z0-9]+)/i', $qr_data, $matches)) {
            $booking_ref = $matches[1];
            $scan_result = "URL format detected. Booking reference: " . $booking_ref;
            $scan_type = 'info';
        } else {
            $scan_result = "Could not parse QR data. Data preview: " . htmlspecialchars(substr($qr_data, 0, 100)) . (strlen($qr_data) > 100 ? '...' : '');
            $scan_type = 'danger';
        }

        if ($booking_ref) {
            try {
                $stmt = $db->prepare("SELECT * FROM booking_details WHERE booking_reference = ?");
                $stmt->execute([$booking_ref]);
                $booking = $stmt->fetch();

                if ($booking) {
                    $scanner_room_id = $_POST['room_id'] ?? null;
                    
                    if (!empty($scanner_room_id) && $booking['room_id'] != $scanner_room_id) {
                        $scan_result = "Invalid room! This booking is for " . htmlspecialchars($booking['room_name']) . " and cannot be verified here.";
                        $scan_type = 'danger';
                        $booking = null; // Hide the booking details
                    } else {
                        // Log the scan
                        $scan_stmt = $db->prepare("INSERT INTO qr_scans (booking_id, scanned_by, scan_location, device_info) VALUES (?, ?, ?, ?)");
                        $scan_stmt->execute([
                            $booking['booking_id'],
                            SessionManager::getUserId(),
                            'Web Scanner',
                            $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
                        ]);

                        $scan_result = "QR Code verified successfully! Booking details loaded.";
                        $scan_type = 'success';
                    }
                } else {
                    $scan_result = "Booking reference '{$booking_ref}' not found.";
                    $scan_type = 'danger';
                }
            } catch (Exception $e) {
                logError("QR scan failed: " . $e->getMessage());
                $scan_result = "Error processing QR code.";
                $scan_type = 'danger';
            }
        } else {
            $scan_result = "Invalid QR code format. Could not extract booking reference.";
            $scan_type = 'warning';
        }
    } else {
        $scan_result = "Please provide QR code data.";
        $scan_type = 'warning';
    }
}

// Handle manual booking reference lookup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lookup_booking'])) {
    $booking_ref = Security::sanitizeInput($_POST['booking_reference']);

    if (!empty($booking_ref)) {
        try {
            $stmt = $db->prepare("SELECT * FROM booking_details WHERE booking_reference = ?");
            $stmt->execute([$booking_ref]);
            $booking = $stmt->fetch();

            if ($booking) {
                $scan_result = "Booking found successfully!";
                $scan_type = 'success';
            } else {
                $scan_result = "Booking reference not found.";
                $scan_type = 'danger';
            }
        } catch (Exception $e) {
            $scan_result = "Error looking up booking.";
            $scan_type = 'danger';
        }
    } else {
        $scan_result = "Please enter a booking reference.";
        $scan_type = 'warning';
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Scanner - <?php echo APP_NAME; ?></title>

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

        .scanner-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            border: none;
        }

        .ticket-container {
            max-width: 600px;
            margin: 20px auto;
            padding: 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
        }

        .ticket-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(90deg, #667eea, #764ba2);
        }

        .ticket-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-top: 10px;
        }

        .ticket-table th {
            text-align: center;
            padding: 15px;
            background: #f8f9fa;
            border-bottom: 2px solid #eee;
        }

        .ticket-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
        }

        .ticket-table tr:last-child td {
            border-bottom: none;
        }

        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            background: #28a745;
            color: white;
            border-radius: 15px;
            font-size: 0.9em;
        }

        .ticket-table h3 {
            margin: 0;
            color: #333;
            font-size: 1.5em;
        }
        }

        .scanner-area {
            background: #f8f9fa;
            border: 2px dashed #dee2e6;
            border-radius: 15px;
            padding: 3rem 2rem;
            text-align: center;
            transition: all 0.3s ease;
        }

        .scanner-area:hover {
            border-color: #667eea;
            background: #f8f9ff;
        }

        .scanner-icon {
            font-size: 4rem;
            color: #667eea;
            margin-bottom: 1rem;
        }

        .btn-scan {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 10px;
            color: white;
            padding: 12px 30px;
            font-weight: 600;
        }

        .btn-scan:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
            color: white;
        }

        .booking-details {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
        }

        .form-control {
            border-radius: 10px;
            border: 2px solid #e9ecef;
            padding: 12px 15px;
        }

        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }

        .page-header {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .qr-camera {
            width: 100%;
            max-width: 400px;
            height: 300px;
            border: 2px solid #667eea;
            border-radius: 15px;
            background: #000;
            margin: 0 auto;
            display: none;
        }

        .scan-line {
            width: 100%;
            height: 2px;
            background: #667eea;
            animation: scan 2s linear infinite;
            position: relative;
        }

        @keyframes scan {
            0% {
                transform: translateY(-150px);
            }

            100% {
                transform: translateY(150px);
            }
        }

        .recent-scans {
            max-height: 400px;
            overflow-y: auto;
        }

        .scan-item {
            border-left: 4px solid #667eea;
            background: #f8f9fa;
            border-radius: 0 10px 10px 0;
            padding: 1rem;
            margin-bottom: 1rem;
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
                        <a class="nav-link active" href="qr-scanner.php">
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
            <h1 class="h3 mb-0">
                <i class="fas fa-qrcode text-primary me-2"></i>QR Code Scanner
            </h1>
            <p class="text-muted mb-0">Scan booking QR codes or enter booking reference manually</p>
        </div>

        <!-- Display scan result -->
        <?php if (!empty($scan_result)): ?>
            <div class="alert alert-<?php echo $scan_type; ?> alert-dismissible fade show" role="alert">
                <?php echo $scan_result; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- QR Scanner -->
            <div class="col-lg-8 mb-4">
                <div class="scanner-card">
                    <div class="card-body">
                        <h5 class="card-title mb-4">
                            <i class="fas fa-camera text-primary me-2"></i>QR Code Scanner
                        </h5>

                        <!-- Camera Scanner -->
                        <div class="row">

    <!-- Left Side - Camera Scanner -->
    <div class="col-lg-8">

        <div class="scanner-area mb-4">
            <div class="qr-camera" id="qr-camera">
                                <video id="camera-stream" autoplay playsinline></video>
                                <div class="scan-line"></div>
                            </div>

                            <div id="scanner-placeholder">
                                <i class="fas fa-qrcode scanner-icon"></i>
                                <h4>Scan QR Code</h4>
                                <p class="text-muted mb-3">
                                    Click the button below to start scanning QR codes with your camera
                                </p>
                                <button type="button" class="btn btn-scan" onclick="startCamera()">
                                    <i class="fas fa-camera me-2"></i>Start Camera
                                </button>
                                <br><small class="text-muted mt-2 d-block">
                                    Make sure to allow camera access when prompted
                                </small>
                            </div>

        </div>

    </div>

    <!-- Right Side - How to Use -->
    <div class="col-lg-4">

        <div class="scanner-card h-100">
            <div class="card-body">

                <h6 class="card-title">
                    <i class="fas fa-info-circle text-primary me-2"></i>
                    How to Use
                </h6>

                <ol class="small">
                    <li><strong>Camera Scanning:</strong> Click "Start Camera" and point your device at a QR code.</li>

                    <li><strong>Manual Input:</strong> Copy and paste QR data into the text area.</li>

                    <li><strong>Reference Lookup:</strong> Enter booking reference directly.</li>
                </ol>

                <div class="mt-3 p-3 bg-light rounded">
                    <small class="text-muted">
                        <i class="fas fa-lightbulb me-1"></i>
                        <strong>Tip:</strong> QR codes can be found on booking confirmations and emails.
                    </small>
                </div>

            </div>
        </div>

    </div>

</div>

                        <!-- Manual QR Data Input -->
                        <div class="border-top pt-4">
                            <h6 class="mb-3">
                                <i class="fas fa-keyboard me-2"></i>Manual QR Data Input
                            </h6>
                            <form method="POST" action="" id="verify-qr-form">
                                <div class="mb-3">
                                    <label for="room_id" class="form-label fw-bold text-primary">Select Scanner Location (Room)</label>
                                    <select name="room_id" id="room_id" class="form-select mb-3" required>
                                        <option value="">-- Please select the room you are scanning for --</option>
                                        <?php
                                        $stmt = $db->query("SELECT room_id, room_name FROM rooms ORDER BY room_name");
                                        $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                        foreach ($rooms as $room) {
                                            $selected = (isset($_POST['room_id']) && $_POST['room_id'] == $room['room_id']) ? 'selected' : '';
                                            echo '<option value="' . $room['room_id'] . '" ' . $selected . '>' . htmlspecialchars($room['room_name']) . '</option>';
                                        }
                                        ?>
                                    </select>
                                    
                                    <label for="qr_data" class="form-label fw-bold">QR Code Data</label>
                                    <textarea
                                        class="form-control"
                                        name="qr_data"
                                        id="qr_data"
                                        rows="4"
                                        placeholder="Paste QR Code Here... (or use Camera Scanner)"></textarea>
                                </div>
                                <button type="submit" name="verify_qr" class="btn btn-primary w-100">
                                    Verify QR
                                </button>
                            </form>
                        </div>
                                
                        <!-- Manual Booking Lookup -->
                        <div class="border-top pt-4 mt-4">
                            <h6 class="mb-3">
                                <i class="fas fa-search me-2"></i>Booking Reference Lookup
                            </h6>
                            <form method="POST" action="">
                                <div class="input-group">
                                    <input type="text" class="form-control" name="booking_reference"
                                        placeholder="Enter booking reference (e.g., BK20250921001)">
                                    <button type="submit" name="lookup_booking" class="btn btn-scan">
                                        <i class="fas fa-search me-2"></i>Look Up
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Test QR Codes -->
                        <div class="border-top pt-4 mt-4">
                            <h6 class="mb-3">
                                <i class="fas fa-vial me-2"></i>Test QR Codes
                            </h6>
                            <p class="small text-muted mb-3">Click to fill QR data for testing:</p>
                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-outline-primary btn-sm"
                                    onclick="fillTestQRData('BK202509210001')">
                                    📚 Main Library Study Room A
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-sm"
                                    onclick="fillTestQRData('BK202509210005')">
                                    🤫 University Library Quiet Study Hall
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-sm"
                                    onclick="fillTestQRData('BK202509210006')">
                                    🏛️ Central Library Conference Room
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Booking Details & Recent Scans -->
            <div class="col-lg-4 mb-4">
                <?php if (isset($booking) && $booking): ?>
                    <!-- Booking Details -->
                    <div class="booking-details mb-4">
                        <h6 class="mb-3">
                            <i class="fas fa-info-circle me-2"></i>Booking Details
                        </h6>

                        <div class="mb-2">
                            <strong>Reference:</strong><br>
                            <span class="h6"><?php echo htmlspecialchars($booking['booking_reference']); ?></span>
                        </div>

                        <div class="mb-2">
                            <strong>Room:</strong><br>
                            <?php echo htmlspecialchars($booking['room_name']); ?>
                        </div>

                        <div class="mb-2">
                            <strong>Location:</strong><br>
                            <?php echo htmlspecialchars($booking['location']); ?>
                        </div>

                        <div class="mb-2">
                            <strong>Date:</strong><br>
                            <?php echo date('l, F j, Y', strtotime($booking['booking_date'])); ?>
                        </div>

                        <div class="mb-2">
                            <strong>Time:</strong><br>
                            <?php echo date('g:i A', strtotime($booking['start_time'])); ?> -
                            <?php echo date('g:i A', strtotime($booking['end_time'])); ?>
                        </div>

                        <?php
                            $qr_raw_purpose = $booking['purpose'] ?? '';
                            $qr_meals = '';
                            $qr_clean_purpose = $qr_raw_purpose;
                            if (strpos($qr_raw_purpose, 'Meals:') === 0) {
                                if (strpos($qr_raw_purpose, ' || Purpose: ') !== false) {
                                    $qr_parts = explode(' || Purpose: ', $qr_raw_purpose, 2);
                                    $qr_meals = trim(str_replace('Meals: ', '', $qr_parts[0]));
                                    $qr_clean_purpose = $qr_parts[1];
                                } elseif (strpos($qr_raw_purpose, ' | Purpose: ') !== false) {
                                    $qr_parts = explode(' | Purpose: ', $qr_raw_purpose, 2);
                                    $qr_meals = trim(str_replace('Meals: ', '', $qr_parts[0]));
                                    $qr_clean_purpose = $qr_parts[1];
                                } else {
                                    $qr_meals = trim(str_replace('Meals: ', '', $qr_raw_purpose));
                                    $qr_clean_purpose = '';
                                }
                            }
                            $qr_meal_lines = array_filter(array_map('trim', explode(' | ', $qr_meals)));
                        ?>

                        <?php if ($qr_meals): ?>
                            <div class="mb-2">
                                <strong>Meals:</strong><br>
                                <?php if (!empty($qr_meal_lines)): ?>
                                    <?php foreach ($qr_meal_lines as $qml): ?>
                                        <span class="badge bg-warning text-dark me-1 mb-1" style="font-size:0.7rem;"><?php echo htmlspecialchars($qml); ?></span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($qr_meals); ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($booking['seat_number'])): ?>
                            <div class="mb-2">
                                <strong>Seat/Desk:</strong><br>
                                <span class="badge bg-light text-dark"><?php echo htmlspecialchars($booking['seat_number']); ?></span>
                            </div>
                        <?php endif; ?>

                        <div class="mb-2">
                            <strong>Booked by:</strong><br>
                            <?php echo htmlspecialchars($booking['full_name']); ?>
                        </div>

                        <div class="mb-2">
                            <strong>Attendees:</strong><br>
                            <?php echo $booking['attendees']; ?> people
                        </div>

                        <div class="mb-0">
                            <strong>Status:</strong><br>
                            <span class="badge bg-light text-dark">
                                <?php echo ucfirst($booking['status']); ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>

            
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- QR Code Scanner Library -->
    <script src="https://unpkg.com/jsqr@1.4.0/dist/jsQR.js"></script>

    <script>
        let cameraStream = null;
        let scanning = false;
        let scanInterval = null;

        function startCamera() {
            const video = document.getElementById('camera-stream');
            const camera = document.getElementById('qr-camera');
            const placeholder = document.getElementById('scanner-placeholder');

            // Check if browser supports getUserMedia
            if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
                navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: 'environment', // Use back camera on mobile
                        width: { ideal: 640 },
                        height: { ideal: 480 }
                    }
                })
                    .then(function (stream) {
                        cameraStream = stream;
                        video.srcObject = stream;

                        // Show camera, hide placeholder
                        camera.style.display = 'block';
                        placeholder.style.display = 'none';
                        scanning = true;

                        // Start QR code detection
                        video.addEventListener('loadedmetadata', function () {
                            startQRDetection();
                        });

                        // Add stop button
                        placeholder.innerHTML = `
                        <div class="text-center">
                            <button type="button" class="btn btn-danger mb-3" onclick="stopCamera()">
                                <i class="fas fa-stop me-2"></i>Stop Camera
                            </button>
                            <br><small class="text-muted">
                                Point your camera at a QR code to scan
                            </small>
                            <div class="mt-2">
                                <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                                <span class="text-muted">Scanning for QR codes...</span>
                            </div>
                        </div>
                    `;
                        placeholder.style.display = 'block';
                    })
                    .catch(function (error) {
                        console.error('Camera access denied:', error);

                        // Show detailed error message
                        placeholder.innerHTML = `
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Camera Access Required</strong><br>
                                <small class="text-muted">
                                    ${error.name === 'NotAllowedError' ?
                                'Please allow camera access when prompted by your browser.' :
                                error.name === 'NotFoundError' ?
                                    'No camera found on this device.' :
                                    'Camera access failed: ' + error.message
                            }
                                </small>
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="startCamera()">
                                <i class="fas fa-camera me-2"></i>Try Again
                            </button>
                            <br><small class="text-muted mt-3 d-block">
                                If camera doesn't work, use manual input below
                            </small>
                        `;
                    });
            } else {
                alert('Camera not supported by this browser. Please use manual input instead.');
            }
        }

        function startQRDetection() {
            const video = document.getElementById('camera-stream');
            const canvas = document.createElement('canvas');
            const context = canvas.getContext('2d');

            scanInterval = setInterval(function () {
                if (video.readyState === video.HAVE_ENOUGH_DATA && scanning) {
                    canvas.width = video.videoWidth;
                    canvas.height = video.videoHeight;
                    context.drawImage(video, 0, 0, canvas.width, canvas.height);

                    const imageData = context.getImageData(0, 0, canvas.width, canvas.height);
                    const qrCode = jsQR(imageData.data, imageData.width, imageData.height);

                    if (qrCode) {
                        // QR code detected!
                        console.log('QR Code detected:', qrCode.data);
                        processQRCode(qrCode.data);
                    }
                }
            }, 500); // Scan every 500ms
        }

        function processQRCode(qrData) {
            // Stop scanning
            stopCamera();

            // Fill the manual input with QR data
            document.getElementById('qr_data').value = qrData;

            // Show success message with QR data preview
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-success alert-dismissible fade show';
            alertDiv.innerHTML = `
                <i class="fas fa-check-circle me-2"></i>
                <strong>QR Code Detected!</strong> The data has been filled in below.<br>
                <small class="text-muted">Preview: ${qrData.substring(0, 100)}${qrData.length > 100 ? '...' : ''}</small><br>
                <button type="button" class="btn btn-sm btn-success mt-2" onclick="document.getElementById('verify-qr-form').submit()">
                    <i class="fas fa-check me-1"></i>Verify Now
                </button>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;

            // Insert alert before the scanner card
            const scannerCard = document.querySelector('.scanner-card');
            scannerCard.parentNode.insertBefore(alertDiv, scannerCard);

            // Auto-scroll to the form and highlight it
            document.getElementById('qr_data').scrollIntoView({ behavior: 'smooth' });
            document.getElementById('qr_data').focus();
            document.getElementById('qr_data').style.borderColor = '#28a745';

            // Automatically submit the form
            document.getElementById('verify-qr-form').submit();

            // Reset border color after 3 seconds
            setTimeout(() => {
                document.getElementById('qr_data').style.borderColor = '';
            }, 3000);
        }

        function stopCamera() {
            if (scanInterval) {
                clearInterval(scanInterval);
                scanInterval = null;
            }

            if (cameraStream) {
                cameraStream.getTracks().forEach(track => track.stop());
                cameraStream = null;
            }

            const camera = document.getElementById('qr-camera');
            const placeholder = document.getElementById('scanner-placeholder');

            camera.style.display = 'none';
            scanning = false;

            // Reset placeholder
            placeholder.innerHTML = `
                <i class="fas fa-qrcode scanner-icon"></i>
                <h4>Scan QR Code</h4>
                <p class="text-muted mb-3">
                    Click the button below to start scanning QR codes with your camera
                </p>
                <button type="button" class="btn btn-scan" onclick="startCamera()">
                    <i class="fas fa-camera me-2"></i>Start Camera
                </button>
                <br><small class="text-muted mt-2 d-block">
                    Make sure to allow camera access when prompted
                </small>
            `;
            placeholder.style.display = 'block';
        }

        // Cleanup on page unload
        window.addEventListener('beforeunload', function () {
            if (cameraStream) {
                cameraStream.getTracks().forEach(track => track.stop());
            }
            if (scanInterval) {
                clearInterval(scanInterval);
            }
        });

        // Auto-focus on manual input when clicking
        document.getElementById('qr_data').addEventListener('focus', function () {
            if (scanning) {
                stopCamera();
            }
        });

        // Test QR codes for demo
        function fillTestQRData(bookingRef) {
            const testData = {
                type: 'booking_verification',
                booking_reference: bookingRef,
                room_name: bookingRef === 'BK202509210001' ? 'Main Library Study Room A' :
                    bookingRef === 'BK202509210005' ? 'University Library Quiet Study Hall' :
                        bookingRef === 'BK202509210006' ? 'Central Library Conference Room' : 'Test Room',
                booking_date: '2025-09-21',
                start_time: '09:00:00',
                end_time: '10:30:00',
                user: 'demo',
                verify_url: `http://localhost/Meeting-room-booking-system-main/verify-booking.php?ref=${bookingRef}`
            };

            document.getElementById('qr_data').value = JSON.stringify(testData, null, 2);

            // Highlight the filled field
            document.getElementById('qr_data').style.borderColor = '#007bff';
            document.getElementById('qr_data').focus();

            // Reset border color after 2 seconds
            setTimeout(() => {
                document.getElementById('qr_data').style.borderColor = '';
            }, 2000);
        }
    </script>
</body>

</html>