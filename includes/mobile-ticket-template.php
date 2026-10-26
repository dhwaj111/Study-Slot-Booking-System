<?php
class MobileTicketTemplate {
    public static function generateHTML($bookingData) {
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Ticket - {$bookingData['booking_reference']}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        }
        
        body {
            background: #f0f2f5;
            padding: 20px;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .ticket {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            overflow: hidden;
            max-width: 400px;
            width: 100%;
            margin: auto;
        }
        
        .ticket-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            text-align: center;
        }
        
        .ticket-header h1 {
            font-size: 24px;
            margin: 0;
            font-weight: 600;
        }
        
        .ticket-body {
            padding: 20px;
        }
        
        .ticket-info {
            margin-bottom: 15px;
            border-bottom: 1px solid #eee;
            padding-bottom: 15px;
        }
        
        .ticket-info:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .info-label {
            color: #666;
            font-size: 14px;
            margin-bottom: 5px;
        }
        
        .info-value {
            color: #333;
            font-size: 16px;
            font-weight: 500;
        }
        
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            background: #22c55e;
            color: white;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
        }
        
        .time-badge {
            display: inline-block;
            padding: 4px 8px;
            background: #f3f4f6;
            border-radius: 6px;
            color: #4b5563;
            font-size: 14px;
        }
        
        .qr-section {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px dashed #ddd;
        }
        
        .qr-code {
            margin: 15px auto;
            max-width: 200px;
            height: auto;
        }
        
        .booking-ref {
            background: #f8f9fa;
            padding: 8px 12px;
            border-radius: 8px;
            font-family: monospace;
            font-size: 16px;
            color: #374151;
            display: inline-block;
        }
    </style>
</head>
<body>
    <div class="ticket">
        <div class="ticket-header">
            <h1>Study Room Booking</h1>
        </div>
        <div class="ticket-body">
            <div class="ticket-info">
                <div class="info-label">Booking Reference</div>
                <div class="info-value booking-ref">{$bookingData['booking_reference']}</div>
            </div>
            
            <div class="ticket-info">
                <div class="info-label">Room</div>
                <div class="info-value">{$bookingData['room_name']}</div>
            </div>
            
            <div class="ticket-info">
                <div class="info-label">Date</div>
                <div class="info-value">{$bookingData['booking_date']}</div>
            </div>
            
            <div class="ticket-info">
                <div class="info-label">Time</div>
                <div class="info-value">
                    <span class="time-badge">{$bookingData['start_time']}</span> - 
                    <span class="time-badge">{$bookingData['end_time']}</span>
                </div>
            </div>
            
            <div class="ticket-info">
                <div class="info-label">Booked By</div>
                <div class="info-value">{$bookingData['username']}</div>
            </div>
            
            <div class="ticket-info">
                <div class="info-label">Status</div>
                <div class="info-value">
                    <span class="status-badge">✓ Valid</span>
                </div>
            </div>

            <div class="qr-section">
                <div class="qr-code">
                    <!-- QR Code SVG will be inserted here -->
                    [QR_CODE_PLACEHOLDER]
                </div>
                <p style="color: #666; font-size: 14px;">Scan to verify booking</p>
            </div>
        </div>
    </div>
</body>
</html>
HTML;
        return $html;
    }
}