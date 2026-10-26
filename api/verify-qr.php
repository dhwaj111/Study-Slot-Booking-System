<?php
header('Content-Type: application/json');
require_once '../includes/config.php';
require_once '../includes/simple-qr.php';

SessionManager::start();

if (!SessionManager::isLoggedIn()) {
    echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
    exit();
}

$db = Database::getInstance()->getConnection();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = $_POST;

    $action = $input['action'] ?? '';

    if ($action === 'verify_qr' || $action === 'lookup_booking') {
        $booking_ref = '';
        $scan_type = '';

        if ($action === 'verify_qr') {
            $qr_data = trim($input['qr_data'] ?? '');
            
            if (empty($qr_data)) {
                echo json_encode(['status' => 'error', 'message' => 'Please provide QR code data.']);
                exit;
            }

            $json_data = @json_decode($qr_data, true);
            if ($json_data && isset($json_data['booking_reference'])) {
                $booking_ref = $json_data['booking_reference'];
                $scan_type = 'qr';
            } elseif (preg_match('/"booking_reference":"([^"]+)"/', $qr_data, $matches)) {
                $booking_ref = $matches[1];
                $scan_type = 'qr';
            } elseif (preg_match('/Reference:\s*([A-Z0-9]+)/i', $qr_data, $matches)) {
                $booking_ref = $matches[1];
                $scan_type = 'qr';
            } elseif (preg_match('/^BK\d+$/i', $qr_data)) {
                $booking_ref = $qr_data;
                $scan_type = 'qr';
            } elseif (preg_match('/ref=([A-Z0-9]+)/i', $qr_data, $matches)) {
                $booking_ref = $matches[1];
                $scan_type = 'qr';
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Could not parse QR data.']);
                exit;
            }
        } else {
            $booking_ref = Security::sanitizeInput($input['booking_reference'] ?? '');
            $scan_type = 'manual';
            if (empty($booking_ref)) {
                echo json_encode(['status' => 'error', 'message' => 'Please enter a booking reference.']);
                exit;
            }
        }

        try {
            $stmt = $db->prepare("SELECT * FROM booking_details WHERE booking_reference = ?");
            $stmt->execute([$booking_ref]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($booking) {
                if ($scan_type === 'qr') {
                    $scan_stmt = $db->prepare("INSERT INTO qr_scans (booking_id, scanned_by, scan_location, device_info) VALUES (?, ?, ?, ?)");
                    $scan_stmt->execute([
                        $booking['booking_id'],
                        SessionManager::getUserId(),
                        'Web Scanner',
                        $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
                    ]);
                }

                echo json_encode([
                    'status' => 'success', 
                    'message' => 'Booking found successfully.', 
                    'data' => $booking
                ]);
            } else {
                echo json_encode(['status' => 'error', 'message' => "Booking reference '{$booking_ref}' not found."]);
            }
        } catch (Exception $e) {
            logError("Verification failed: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Error processing request.']);
        }
        exit;
    }
}

echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
