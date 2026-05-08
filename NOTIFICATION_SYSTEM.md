# Advanced Notification System - Documentation

## Overview
This advanced notification system handles both in-app and email notifications for the Accounts Bazar platform. It includes retry logic, user preferences, notification queuing, and HTML email templates.

## Key Features

✅ **In-App Notifications** - Real-time notifications displayed in the app
✅ **Email Notifications** - Beautiful HTML emails with automatic retry
✅ **User Preferences** - Users can control what notifications they receive
✅ **Queue System** - Failed emails are automatically retried up to 3 times
✅ **Admin Dashboard** - Complete notification management interface
✅ **Email Templates** - Professional HTML templates for all notification types
✅ **Logging** - All email activity is logged for debugging

## Configuration

### Mail Settings
File: `products/config/mail.php`

**Primary Account (Orders):**
```php
define('MAIL_SMTP_USERNAME', 'order@accountsbazar.com');
define('MAIL_SMTP_PASSWORD', '1410689273KK');
```

**Support Account (Notifications):**
```php
define('MAIL_SUPPORT_USERNAME', 'needhelp@accountsbazar.com');
define('MAIL_SUPPORT_PASSWORD', ''); // Set this
```

**Important Settings:**
```php
define('MAIL_RETRY_ATTEMPTS', 3);        // Retry failed emails 3 times
define('MAIL_DEBUG_MODE', false);        // Enable for debugging
define('MAIL_LOG_ENABLED', true);        // Enable email logging
```

## Database Tables

### notifications
Stores in-app notifications for users
```sql
- id (Primary Key)
- user_id (Foreign Key to users)
- type (order, shipment, delivery, support, alert, promo, etc.)
- title (Notification title)
- message (Notification content)
- related_id (Link to related order/entity)
- is_read (Boolean)
- created_at (Timestamp)
- expires_at (Optional expiration)
```

### notification_preferences
User preferences for email notifications
```sql
- user_id (Unique, Foreign Key)
- email_on_order (Boolean)
- email_on_shipment (Boolean)
- email_on_delivery (Boolean)
- email_on_support (Boolean)
- email_promotions (Boolean)
```

### notification_queue
Queue for pending and failed notifications
```sql
- id (Primary Key)
- user_id (Foreign Key)
- email (Email address)
- notification_type (Type of notification)
- subject (Email subject)
- body (Email body)
- status (pending, sent, failed)
- retry_count (Number of retries)
- created_at (Timestamp)
- sent_at (When sent)
```

## API Endpoints

### User Notifications API
**File:** `api-notifications.php`

#### List Notifications
```php
GET /api-notifications.php?action=list&limit=10&unread_only=false
Response: { success: true, count: n, notifications: [...] }
```

#### Get Unread Count
```php
GET /api-notifications.php?action=unread_count
Response: { success: true, unread_count: n }
```

#### Mark as Read
```php
POST /api-notifications.php?action=mark_read
Body: notification_id=123
```

#### Get Preferences
```php
GET /api-notifications.php?action=get_preferences
```

#### Update Preferences
```php
POST /api-notifications.php?action=update_preferences
Body: email_on_order=1&email_on_shipment=1&...
```

### Admin Notifications API
**File:** `admin/api/notifications.php`

#### Send to User
```php
POST /admin/api/notifications.php?action=send_to_user
Body: user_id=123&type=alert&title=...&message=...&send_email=1
```

#### Send Bulk
```php
POST /admin/api/notifications.php?action=send_bulk
Body: user_ids[]=1&user_ids[]=2&type=alert&...
```

#### Send to All
```php
POST /admin/api/notifications.php?action=send_all
Body: type=alert&title=...&message=...&send_email=1
```

#### Queue Status
```php
GET /admin/api/notifications.php?action=queue_status
```

#### Process Queue
```php
POST /admin/api/notifications.php?action=process_queue
```

## Using the NotificationManager Class

### Include the Class
```php
require_once 'products/includes/notifications.php';
$notificationManager = new NotificationManager();
```

### Create Simple Notification
```php
$notificationManager->createNotification(
    $userId,
    'order',
    'Order Received',
    'Your order #ORD-123 has been received'
);
```

### Send Notification with Email
```php
$notificationManager->sendNotification(
    $userId,
    'order',
    'Order Confirmation',
    'Your order has been placed',
    'ORD-123',
    array(
        'email' => 'user@example.com',
        'subject' => 'Order Confirmation',
        'body' => sendOrderConfirmationEmail($email, $name, $orderId, $orderDetails)
    )
);
```

### Order Notifications
```php
// Order created
$notificationManager->notifyOrderCreated($orderId, $userId, $email, $name);

// Order shipped
$notificationManager->notifyOrderShipped($orderId, $userId, $email, $name, 'TRACK123');

// Order delivered
$notificationManager->notifyOrderDelivered($orderId, $userId, $email, $name);
```

### Process Queue (Run Periodically)
```php
$processed = $notificationManager->processQueuedNotifications();
echo "Processed $processed notifications";
```

## Email Templates

### sendRegistrationEmail()
```php
sendRegistrationEmail($email, $name, $username);
```

### sendOrderConfirmationEmail()
```php
sendOrderConfirmationEmail($email, $name, $orderId, $orderDetails);
```

### sendOrderStatusEmail()
```php
sendOrderStatusEmail($email, $name, $orderId, $status, $message);
// Status: processing, shipped, delivered, cancelled, refunded
```

### sendPasswordResetEmail()
```php
sendPasswordResetEmail($email, $name, $resetLink);
```

### sendSupportReplyEmail()
```php
sendSupportReplyEmail($email, $name, $message, $threadLink);
```

### sendAdminNotificationEmail()
```php
sendAdminNotificationEmail($adminEmail, $subject, $message, $actionLink);
```

## Admin Interface

### Notification Management Dashboard
**URL:** `/admin/notifications.php`

Shows:
- Total notifications sent
- Pending notifications count
- Successfully sent count
- Failed notifications count
- Recent notifications table
- Button to process pending queue

### Send Notification Page
**URL:** `/admin/notifications-send.php`

Features:
- Send to specific user or all users
- Choose notification type
- Set title and content
- Option to send email copy
- Real-time feedback

## User Interface

### Notification Preferences
**URL:** `/notification-preferences.php`

Users can control:
- Order confirmation emails
- Shipment update emails
- Delivery confirmation emails
- Support reply emails
- Promotional emails

### Notification Feed API
**URL:** `/notifications-feed.php`

Returns JSON with:
- Latest 20 notifications
- Mix of system and personal notifications
- Proper categorization

## Setting Up Cron Job for Email Processing

To automatically process pending notifications every 5 minutes, add to crontab:

```bash
*/5 * * * * curl -s http://accountsbazar.com/cron/process-notifications.php > /dev/null
```

Create file: `cron/process-notifications.php`
```php
<?php
require_once '../products/includes/notifications.php';

$notificationManager = new NotificationManager();
$processed = $notificationManager->processQueuedNotifications();

// Log result
file_put_contents(
    __DIR__ . '/notifications.log',
    date('Y-m-d H:i:s') . " - Processed: $processed\n",
    FILE_APPEND
);
?>
```

## Mail Logs

Email activity is logged to: `mail-logs/mail-YYYY-MM-DD.log`

Each entry contains:
- Timestamp
- Recipient email
- Status (SUCCESS/FAILED)
- Email subject
- Error message (if any)

## Troubleshooting

### Emails Not Sending
1. Check `mail-logs/` folder for errors
2. Enable `MAIL_DEBUG_MODE = true` in config
3. Verify SMTP credentials
4. Check if admin has processed the queue

### High Retry Count
- Indicates email service issues
- Check SMTP server status
- Verify firewall/network connectivity
- Check email address validity

### Missing Notifications
- Verify user is logged in
- Check database for `notifications` table
- Verify user preferences allow notifications
- Check notification type matches user's settings

## Integration Examples

### In Checkout
```php
require_once 'products/includes/notifications.php';
$notificationManager = new NotificationManager();
$notificationManager->notifyOrderCreated($orderId, $userId, $email, $name);
```

### In Order Status Update
```php
$notificationManager->notifyOrderShipped($orderId, $userId, $email, $name);
```

### In Support Chat
```php
$notificationManager->sendNotification(
    $userId,
    'support',
    'New Support Reply',
    'We replied to your support message'
);
```

## Security Notes

✅ All emails are validated before sending
✅ Passwords in logs are never exposed
✅ Admin access required for admin endpoints
✅ User can only access their own notifications
✅ CSRF protection on preference updates
✅ SQL injection prevention with prepared statements

## Performance Optimization

- Notifications are queued and processed asynchronously
- Failed emails automatically retry with exponential backoff
- Database indexes on commonly queried columns
- Notifications expire automatically if set
- Queue processing happens outside request cycle

---

**Last Updated:** 2026-05-08
**Version:** 2.0
