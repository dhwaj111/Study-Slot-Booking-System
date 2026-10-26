<?php
/**
 * Simple SVG QR Code Generator
 * This is a lightweight QR code generator that creates SVG images
 */
class SimpleQRGenerator
{

    public static function generateQR($text, $size = 200)
    {
        // Create a simple QR code pattern using SVG
        $modules = self::createQRMatrix($text);
        $moduleSize = $size / count($modules);

        $svg = '<svg width="' . $size . '" height="' . $size . '" xmlns="http://www.w3.org/2000/svg">';
        $svg .= '<rect width="' . $size . '" height="' . $size . '" fill="white"/>';

        for ($row = 0; $row < count($modules); $row++) {
            for ($col = 0; $col < count($modules[$row]); $col++) {
                if ($modules[$row][$col]) {
                    $x = $col * $moduleSize;
                    $y = $row * $moduleSize;
                    $svg .= '<rect x="' . $x . '" y="' . $y . '" width="' . $moduleSize . '" height="' . $moduleSize . '" fill="black"/>';
                }
            }
        }

        $svg .= '</svg>';
        return $svg;
    }

    private static function createQRMatrix($text)
    {
        // Create a simplified QR code pattern
        // This is a basic implementation for demonstration
        $size = 21; // Standard QR code size
        $matrix = array_fill(0, $size, array_fill(0, $size, false));

        // Add finder patterns (corners)
        self::addFinderPattern($matrix, 0, 0);
        self::addFinderPattern($matrix, $size - 7, 0);
        self::addFinderPattern($matrix, 0, $size - 7);

        // Add timing patterns
        for ($i = 8; $i < $size - 8; $i++) {
            $matrix[6][$i] = ($i % 2 == 0);
            $matrix[$i][6] = ($i % 2 == 0);
        }

        // Add some data pattern based on text
        $hash = crc32($text);
        for ($i = 8; $i < $size - 8; $i++) {
            for ($j = 8; $j < $size - 8; $j++) {
                if ($matrix[$i][$j] === null) {
                    $matrix[$i][$j] = (($hash >> (($i + $j) % 32)) & 1) == 1;
                }
            }
        }

        return $matrix;
    }

    private static function addFinderPattern(&$matrix, $x, $y)
    {
        $pattern = [
            [1, 1, 1, 1, 1, 1, 1],
            [1, 0, 0, 0, 0, 0, 1],
            [1, 0, 1, 1, 1, 0, 1],
            [1, 0, 1, 1, 1, 0, 1],
            [1, 0, 1, 1, 1, 0, 1],
            [1, 0, 0, 0, 0, 0, 1],
            [1, 1, 1, 1, 1, 1, 1]
        ];

        for ($i = 0; $i < 7; $i++) {
            for ($j = 0; $j < 7; $j++) {
                if ($x + $i < count($matrix) && $y + $j < count($matrix[0])) {
                    $matrix[$x + $i][$y + $j] = $pattern[$i][$j] == 1;
                }
            }
        }
    }
}

/**
 * Booking QR Code Manager
 */
class BookingQRManager
{
    private $qr_dir;

    public function __construct()
    {
        $this->qr_dir = 'qr_codes/';
        if (!file_exists($this->qr_dir)) {
            mkdir($this->qr_dir, 0755, true);
        }
        require_once 'mobile-ticket-template.php';
    }

    public function generateBookingQR($booking_data)
    {
        // Create QR code content as JSON
        $qr_data = [
            'type' => 'booking_verification',
            'booking_reference' => $booking_data['booking_reference'],
            'room_name' => $booking_data['room_name'],
            'booking_date' => $booking_data['booking_date'],
            'start_time' => $booking_data['start_time'],
            'end_time' => $booking_data['end_time'],
            'username' => $booking_data['username'],
            'verification_url' => 'verify-booking.php?ref=' . $booking_data['booking_reference']
        ];
        
        $qr_content = json_encode($qr_data);

        // Generate SVG QR code
        $svg_content = SimpleQRGenerator::generateQR($qr_content, 300);

        // Save as SVG file
        $filename = $booking_data['booking_reference'] . '.svg';
        $filepath = $this->qr_dir . $filename;

        if (file_put_contents($filepath, $svg_content)) {
            return $filename;
        }

        return false;
    }

    public function generateHTMLQR($booking_data)
    {
        // Generate QR SVG
        $svg_content = SimpleQRGenerator::generateQR(json_encode([
            'type' => 'booking_verification',
            'booking_reference' => $booking_data['booking_reference'],
            'room_name' => $booking_data['room_name'],
            'booking_date' => $booking_data['booking_date'],
            'start_time' => $booking_data['start_time'],
            'end_time' => $booking_data['end_time'],
            'username' => $booking_data['username'],
            'verification_url' => 'verify-booking.php?ref=' . $booking_data['booking_reference']
        ]), 200);

        // Get HTML template and replace QR code placeholder
        $content = MobileTicketTemplate::generateHTML($booking_data);
        $content = str_replace('[QR_CODE_PLACEHOLDER]', $svg_content, $content);

        $filename = $booking_data['booking_reference'] . '_display.html';
        $filepath = $this->qr_dir . $filename;

        if (file_put_contents($filepath, $content)) {
            return $filename;
        }

        return false;
    }
}
?>