# Study Slot Booking System v2.0

A modern, secure, and feature-rich study slot booking system built with PHP, MySQL, and Bootstrap 5. This system includes user authentication, QR code generation, and a responsive design.

## 🚀 Features

### Core Features

- **User Authentication**: Secure sign-up and sign-in with password hashing
- **Study Slot Booking**: Advanced booking system with conflict detection
- **QR Code Generation**: Automatic QR code creation for each booking
- **QR Code Scanner**: Built-in scanner for booking verification
- **User Dashboard**: Comprehensive dashboard with booking statistics
- **Booking Management**: View, filter, and cancel bookings
- **Responsive Design**: Modern Bootstrap 5 interface

### Security Features

- **Session Management**: Secure session handling with timeout
- **CSRF Protection**: Cross-site request forgery protection
- **SQL Injection Prevention**: Prepared statements and input validation
- **Password Security**: Bcrypt password hashing
- **Input Sanitization**: XSS protection and data validation

### Advanced Features

- **Database-driven**: MySQL database with proper relationships
- **Audit Trail**: QR code scan logging
- **Email Integration**: Ready for email notifications
- **Admin Panel**: Role-based access control
- **API Ready**: Structured for future API development

## 📋 Requirements

- **PHP**: 7.4 or higher
- **MySQL**: 5.7 or higher (or MariaDB 10.2+)
- **Web Server**: Apache or Nginx
- **Extensions**: PDO, PDO_MySQL, GD (for QR codes)

## 🛠 Installation Guide

### Step 1: Database Setup

1. **Create Database in phpMyAdmin**:
   - Open phpMyAdmin in your browser
   - Click "New" to create a new database
   - Name it `meeting_room_booking`
   - Set collation to `utf8mb4_general_ci`

2. **Import Database Structure**:
   - Select the newly created database
   - Click "Import" tab
   - Choose the `database_setup.sql` file
   - Click "Go" to execute

### Step 2: File Setup

1. **Upload Files**:

   ```bash
   # Copy all project files to your web server directory
   # For XAMPP: Copy to C:\xampp\htdocs\meeting-room-booking\
   # For WAMP: Copy to C:\wamp64\www\meeting-room-booking\
   ```

2. **Set File Permissions**:

   ```bash
   # Make sure these directories are writable
   chmod 755 qr_codes/
   chmod 755 logs/
   ```

### Step 3: Configuration

1. **Database Configuration**:
   - Open `includes/config.php`
   - Update database credentials:

   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'meeting_room_booking');
   define('DB_USER', 'your_mysql_username');  // Usually 'root' for XAMPP
   define('DB_PASS', 'your_mysql_password');  // Usually empty for XAMPP
   ```

2. **Security Configuration**:
   - Change the encryption key in `includes/config.php`:

   ```php
   define('ENCRYPTION_KEY', 'your-unique-secret-key-here');
   ```

### Step 4: Default Admin Account

The system creates a default admin account:


- **Username**: `admin`
- **Email**: `admin@company.com`
- **Password**: `admin123`
- **Full Name**: `System Administrator`
- **Role**: `admin`

- **Username**: `demo`
- **Email**: `demo@company.com`
- **Password**: `demo123`
- **Full Name**: `Demo User`
- **Role**: `user`

**⚠️ Important**: Change the admin password after first login!

## 🎯 Usage Guide

### For Users

1. **Registration**:
   - Visit `auth.php`
   - Click "Sign Up" tab
   - Fill in your details and create account

2. **Booking a Room**:
   - Login and go to "Book Room"
   - Select date, time, and number of attendees
   - System automatically assigns the best available room
   - QR code is generated automatically

3. **Managing Bookings**:
   - View all bookings in "My Bookings"
   - Filter by status or date
   - Download QR codes
   - Cancel future bookings

4. **QR Code Scanner**:
   - Use "QR Scanner" to verify bookings
   - Supports camera scanning and manual input
   - Logs all scan activities

### For Administrators

1. **Room Management**:
   - Rooms are defined in the database
   - Add/edit rooms via SQL or create admin interface

2. **User Management**:
   - View user activities in the database
   - Monitor booking patterns
   - Generate reports

## 🏗 Project Structure

```
meeting-room-booking/
├── includes/
│   ├── config.php              # Database and security configuration
│   └── qr-generator.php        # QR code generation functionality
├── qr_codes/                   # Generated QR code files
├── logs/                       # System error logs
├── auth.php                    # Login and registration page
├── dashboard.php               # User dashboard
├── book-room.php              # Study slot booking interface
├── my-bookings.php            # User booking management
├── qr-scanner.php             # QR code scanner
├── database_setup.sql         # Database schema
└── README.md                  # This file
```

## 🔧 Customization

### Adding New Rooms

Execute in phpMyAdmin:

```sql
INSERT INTO rooms (room_id, room_name, capacity, location, description, amenities) 
VALUES ('A1.01', 'Conference Room Alpha', 8, 'Floor 1', 'Modern conference room', '["projector", "whiteboard", "wifi"]');
```

### Customizing Email Templates

The system is ready for email integration. Add SMTP configuration in `includes/config.php`:

```php
define('SMTP_HOST', 'your.smtp.server.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'your-email@company.com');
define('SMTP_PASSWORD', 'your-smtp-password');
```

### Modifying Business Hours

Update the time options in `book-room.php`:

```php
// Change the loop to adjust available hours
for ($hour = 6; $hour <= 24; $hour++) { // 6 AM to 12 AM
    // ... time generation code
}
```

## 🔐 Security Best Practices

1. **Change Default Credentials**: Always change the default admin password
2. **Use HTTPS**: Enable SSL/TLS for production
3. **Regular Updates**: Keep PHP and MySQL updated
4. **Backup Database**: Regular automated backups
5. **Monitor Logs**: Check error logs regularly
6. **Firewall**: Implement proper firewall rules

## 🐛 Troubleshooting

### Common Issues

1. **Database Connection Error**:
   - Check database credentials in `includes/config.php`
   - Ensure MySQL service is running
   - Verify database exists

2. **QR Codes Not Generating**:
   - Check if `qr_codes/` directory exists and is writable
   - Verify GD extension is installed
   - Check error logs

3. **Session Issues**:
   - Clear browser cookies
   - Check PHP session configuration
   - Verify server write permissions

4. **Camera Not Working (QR Scanner)**:
   - Use HTTPS for camera access
   - Check browser permissions
   - Try different browsers

### Debug Mode

Enable debug mode by adding to `includes/config.php`:

```php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
```

## 📊 Database Schema

### Main Tables

- **users**: User accounts and authentication
- **rooms**: Study room definitions
- **bookings**: Study slot reservations
- **qr_scans**: QR code scan audit trail
- **user_sessions**: Session management

### Views

- **booking_details**: Combined booking information for easy querying

## 🔮 Future Enhancements

- [ ] Email notifications for bookings
- [ ] SMS integration
- [ ] Calendar integration (Google Calendar, Outlook)
- [ ] Recurring bookings
- [ ] Equipment booking
- [ ] Advanced reporting dashboard
- [ ] Mobile app
- [ ] API endpoints for third-party integrations

## 📄 License

This project is licensed under the MIT License - see the LICENSE file for details.

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Test thoroughly
5. Submit a pull request

## 💬 Support

For support and questions:

- Check the troubleshooting section
- Review error logs in `logs/error.log`
- Create an issue in the repository

## 📸 Screenshots

The system includes:

- Modern login/registration interface
- Intuitive booking dashboard
- Responsive study slot booking form
- Comprehensive booking management
- Built-in QR code scanner
- Professional design throughout
 
-Website Live link :- http://studyslot.free.nf/

---

**Version**: 2.0  
**Last Updated**: September 2025  
**PHP Version**: 7.4+  
**Database**: MySQL 5.7+
