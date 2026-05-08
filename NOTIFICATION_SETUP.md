# Notification System - Quick Setup Guide

## ✅ What's Been Done

The notification system has been completely built and integrated into your project with the following components:

### Core Files Created/Updated:
1. ✅ **products/config/mail.php** - Enhanced mail configuration with retry settings
2. ✅ **products/includes/mailer.php** - Advanced SMTP mailer with retry logic and logging
3. ✅ **products/includes/notifications.php** - NotificationManager class
4. ✅ **api-notifications.php** - User notification API endpoints
5. ✅ **notification-preferences.php** - User preference management UI
6. ✅ **admin/api/notifications.php** - Admin notification API
7. ✅ **admin/notifications.php** - Admin dashboard
8. ✅ **admin/notifications-send.php** - Admin send notification interface
9. ✅ **notifications-feed.php** - Enhanced notification feed (updated)
10. ✅ **checkout.php** - Integrated order notifications (updated)
11. ✅ **register.php** - Integrated registration email (updated)
12. ✅ **cron/process-notifications.php** - Email queue processor

### Database Tables Created Automatically:
- `notifications` - In-app user notifications
- `notification_preferences` - User email preferences
- `notification_queue` - Email queue for retry

## 🔧 Next Steps

### Step 1: Add Support Email Password
Edit `products/config/mail.php` and add the password for support email:
```php
define('MAIL_SUPPORT_PASSWORD', 'YOUR_PASSWORD_HERE');
```

### Step 2: Test Email Configuration
Visit: `admin/notifications.php`
This will show you the current notification queue status.

### Step 3: Send Test Email
1. Go to: `admin/notifications-send.php`
2. Select a user (or all users)
3. Add a test title and message
4. Check "Also send as email"
5. Click "Send Notification"

### Step 4: Set Up Cron Job
Add this to your hosting cPanel Cron Jobs (or similar):

**Command:**
```
curl -s http://accountsbazar.com/cron/process-notifications.php > /dev/null
```

**Run every:** 5 minutes

This will automatically process pending emails every 5 minutes.

### Step 5: Check Email Logs
Email logs are stored in: `/mail-logs/mail-YYYY-MM-DD.log`

View the latest entries to verify emails are being sent:
```
cat /mail-logs/mail-2026-05-08.log
```

## 📧 Email Types Integrated

The following emails are now automated:

### 1. Registration Email
- **Trigger:** When user registers
- **File:** `register.php`
- **Function:** `sendRegistrationEmail()`
- **Features:** HTML template, user details

### 2. Order Confirmation
- **Trigger:** When order is placed
- **File:** `checkout.php`
- **Function:** `sendOrderConfirmationEmail()`
- **Features:** Order details, product info, payment status

### 3. Order Status Updates (Manual)
- **Function:** `sendOrderStatusEmail()`
- **Statuses:** processing, shipped, delivered, cancelled, refunded
- **Admin:** Can trigger from order management

### 4. Support Replies
- **Function:** `sendSupportReplyEmail()`
- **Integration:** In support-chat-api.php (needs update)

### 5. Password Reset
- **Function:** `sendPasswordResetEmail()`
- **Integration:** In login/forgot-password (needs update)

## 🎯 Using the Notification System

### Send Notification from Your Code

```php
require_once 'products/includes/notifications.php';
$notificationManager = new NotificationManager();

// Option 1: Simple notification only
$notificationManager->createNotification(
    $userId,
    'info',
    'New Feature Available',
    'Check out our new product search feature'
);

// Option 2: Notification with email
$notificationManager->sendNotification(
    $userId,
    'alert',
    'Important Update',
    'Your account settings have changed',
    null,
    array(
        'email' => 'user@example.com',
        'subject' => 'Account Update',
        'body' => getEmailTemplate('Account Update', '<p>Your details have changed</p>')
    )
);
```

### Admin Send Notifications

1. **Dashboard:** `/admin/notifications.php` - View queue status
2. **Send Email:** `/admin/notifications-send.php` - Create and send
3. **API Call:**
```bash
curl -X POST http://accountsbazar.com/admin/api/notifications.php?action=send_all \
  -d "type=promo&title=Special Offer&message=50% off all products&send_email=1"
```

## 🔍 Monitoring

### Check Queue Status
```
GET /admin/api/notifications.php?action=queue_status
```

### View Email Logs
Location: `/mail-logs/` directory

### Check for Errors
1. Enable debug mode in `products/config/mail.php`:
   ```php
   define('MAIL_DEBUG_MODE', true);
   ```
2. Check `/mail-logs/` for details
3. Test from `/admin/notifications-send.php`

## 📋 User Features

### Users Can:
1. View all their notifications at `/notifications-feed.php` (API endpoint)
2. Manage preferences at `/notification-preferences.php`
3. Control which emails they receive:
   - Order confirmations
   - Shipment updates
   - Delivery notifications
   - Support replies
   - Promotional emails

### JavaScript for Notification UI

```javascript
// Get notifications
fetch('/api-notifications.php?action=list&limit=10')
  .then(r => r.json())
  .then(data => console.log(data.notifications));

// Get unread count
fetch('/api-notifications.php?action=unread_count')
  .then(r => r.json())
  .then(data => console.log(data.unread_count));

// Mark as read
fetch('/api-notifications.php?action=mark_read', {
  method: 'POST',
  body: new FormData({notification_id: 123})
});
```

## 🚀 Quick Fixes Needed

These files might need additional integration:

1. **support-chat-api.php** - Add notification on support reply:
   ```php
   $notificationManager->sendNotification(
       $userId, 'support', 'Support Reply',
       $message, $threadId
   );
   ```

2. **admin/orders.php** - Add notification when status changes:
   ```php
   $notificationManager->notifyOrderShipped($orderId, $userId, $email, $name);
   ```

3. **Forgot Password** - Send reset email automatically

## 🆘 Troubleshooting

### Email Logs Location
```
/mail-logs/mail-YYYY-MM-DD.log
```

### Check if Notifications Table Exists
```php
<?php
require_once 'products/includes/db.php';
$db = new Database();
$conn = $db->getConnection();
$check = $conn->query("SHOW TABLES LIKE 'notifications'");
echo $check->num_rows > 0 ? "✓ Tables exist" : "✗ Tables missing";
?>
```

### Test SMTP Connection
```php
<?php
require_once 'products/config/mail.php';
require_once 'products/includes/mailer.php';

$result = smtpSendMail(
    'test@example.com',
    'Test Subject',
    getEmailTemplate('Test', '<p>Testing email system</p>')
);

echo $result ? "✓ Email sent" : "✗ Failed to send";
?>
```

## 📞 Support

For detailed documentation, see: `NOTIFICATION_SYSTEM.md`

### Key Resources:
- Admin Dashboard: `/admin/notifications.php`
- User Preferences: `/notification-preferences.php`
- API Docs: `NOTIFICATION_SYSTEM.md`
- Email Logs: `/mail-logs/`

---

**Everything is ready!** Follow the setup steps above to get notifications working on your live site.
