-- Database setup for Meeting Room Booking System
-- Run this in phpMyAdmin to create the database structure

CREATE DATABASE IF NOT EXISTS meeting_room_booking;
USE meeting_room_booking;

-- Users table for authentication
CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    department VARCHAR(50),
    role ENUM('user', 'admin') DEFAULT 'user',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Rooms table
CREATE TABLE rooms (
    room_id VARCHAR(10) PRIMARY KEY,
    room_name VARCHAR(100) NOT NULL,
    capacity INT NOT NULL,
    location VARCHAR(100),
    description TEXT,
    amenities JSON,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Bookings table
CREATE TABLE bookings (
    booking_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    room_id VARCHAR(10) NOT NULL,
    booking_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    purpose TEXT,
    attendees INT NOT NULL,
    status ENUM('confirmed', 'cancelled', 'completed') DEFAULT 'confirmed',
    qr_code VARCHAR(255),
    booking_reference VARCHAR(50) UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE,
    INDEX idx_booking_date (booking_date),
    INDEX idx_room_date (room_id, booking_date),
    INDEX idx_user_bookings (user_id, booking_date)
);

-- QR Codes table for tracking scanned QR codes
CREATE TABLE qr_scans (
    scan_id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    scanned_by INT NOT NULL,
    scan_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    scan_location VARCHAR(100),
    device_info TEXT,
    FOREIGN KEY (booking_id) REFERENCES bookings(booking_id) ON DELETE CASCADE,
    FOREIGN KEY (scanned_by) REFERENCES users(user_id) ON DELETE CASCADE
);

-- User sessions table for better session management
CREATE TABLE user_sessions (
    session_id VARCHAR(128) PRIMARY KEY,
    user_id INT NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- Insert initial room data
INSERT INTO rooms (room_id, room_name, capacity, location, description, amenities) VALUES
('7.21', 'Conference Room A', 5, 'Floor 7', 'Small meeting room with projector', '["projector", "whiteboard", "wifi"]'),
('9.15', 'Conference Room B', 7, 'Floor 9', 'Medium meeting room with video conferencing', '["projector", "video_conference", "whiteboard", "wifi"]'),
('3.53', 'Large Conference Room', 12, 'Floor 3', 'Large meeting room for presentations', '["projector", "sound_system", "whiteboard", "wifi", "microphone"]'),
('3.15', 'Training Room', 12, 'Floor 3', 'Training room with multiple screens', '["multiple_screens", "sound_system", "whiteboard", "wifi"]'),
('6.72', 'Board Room', 15, 'Floor 6', 'Executive board room with premium amenities', '["projector", "video_conference", "sound_system", "whiteboard", "wifi", "catering_facility"]');

-- Create admin user (password: admin123) and demo user (password: demo123)
INSERT INTO users (username, email, password_hash, full_name, role) VALUES
('admin', 'admin@company.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'admin'),
('demo', 'demo@company.com', '$2y$10$dWzOpAQmp/NeMqxG5HEOvu6dEetTaCXRkzixQz/qcPqMw4HHKwVlm', 'Demo User', 'user');

-- Create views for better data access
CREATE VIEW booking_details AS
SELECT 
    b.booking_id,
    b.booking_reference,
    b.booking_date,
    b.start_time,
    b.end_time,
    b.purpose,
    b.attendees,
    b.status,
    b.qr_code,
    u.username,
    u.full_name,
    u.email,
    r.room_id,
    r.room_name,
    r.location,
    r.capacity,
    b.created_at
FROM bookings b
JOIN users u ON b.user_id = u.user_id
JOIN rooms r ON b.room_id = r.room_id;

-- Add some constraints and triggers
DELIMITER //

CREATE TRIGGER generate_booking_reference
BEFORE INSERT ON bookings
FOR EACH ROW
BEGIN
    SET NEW.booking_reference = CONCAT('BK', DATE_FORMAT(NOW(), '%Y%m%d'), LPAD(LAST_INSERT_ID() + 1, 4, '0'));
END//

DELIMITER ;