<?php
require_once 'includes/config.php';

/**
 * QR Code Generator for Meeting Room Bookings
 * Handles QR code creation and management for bookings
 */
class QRCodeGenerator
{
    private $qr_code_dir;

    public function __construct()
    {
        $this->qr_code_dir = 'qr_codes/';
        if (!file_exists($this->qr_code_dir)) {
            mkdir($this->qr_code_dir, 0755, true);
        }
    }

    /**
     * Generate QR code for a booking (direct URL)
     */
    public function generateBookingQR($booking_data)
    {
        try {
            // Direct URL to booking verification page
            $qr_data = $this->getBaseUrl() . 'verify-booking.php?ref=' . urlencode($booking_data['booking_reference']);

            $filename = $booking_data['booking_reference'] . '.png';
            $filepath = $this->qr_code_dir . $filename;

            // Google Charts API for QR code
            $qr_url = 'https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=' . urlencode($qr_data) . '&choe=UTF-8';

            $qr_image = file_get_contents($qr_url);
            if ($qr_image !== false) {
                file_put_contents($filepath, $qr_image);
                return $filepath;
            }

            return false;

        } catch (Exception $e) {
            logError("QR Code generation failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get base URL accessible from mobile (handles localhost)
     */
    private function getBaseUrl()
    {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'];

        // Replace localhost with LAN IP for mobile access
        if (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) {
            $host = getHostByName(getHostName()); // e.g., 192.168.1.5
        }

        $path = dirname($_SERVER['SCRIPT_NAME']);
        if (substr($path, -1) !== '/') {
            $path .= '/';
        }

        return $protocol . $host . $path;
    }
}

/**
 * Helper to regenerate QR code for existing bookings
 */
function regenerateQRForBooking($booking_id)
{
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM booking_details WHERE booking_id = ?");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch();

        if ($booking) {
            $qr_generator = new QRCodeGenerator();
            $qr_path = $qr_generator->generateBookingQR($booking);
            
            if ($qr_path) {
                $stmt = $db->prepare("UPDATE booking_details SET qr_code = ? WHERE booking_id = ?");
                $stmt->execute([$qr_path, $booking_id]);
                return $qr_path;
            }
        }

        return false;

    } catch (Exception $e) {
        logError("QR regeneration failed: " . $e->getMessage());
        return false;
    }
}
?>
