# Notification Integration Quick Reference

## All Notification Types

### System Notifications
| Type | Email? | User Pref | Used In | Admin Can Send |
|------|--------|-----------|---------|-----------------|
| `order` | ✅ | email_on_order | Checkout | ✅ |
| `shipment` | ✅ | email_on_shipment | Admin | ✅ |
| `delivery` | ✅ | email_on_delivery | Admin | ✅ |
| `support` | ✅ | email_on_support | Chat API | ✅ |
| `alert` | ✅ | - | Admin | ✅ |
| `info` | ✅ | - | Admin | ✅ |
| `promo` | ✅ | email_promotions | Admin | ✅ |
| `update` | ✅ | - | Admin | ✅ |
| `ai_prompt` | ✗ | - | System | ✗ |
| `product` | ✗ | - | System | ✗ |

## Email Templates Available

```php
// Registration
sendRegistrationEmail($email, $name, $username)

// Orders
sendOrderConfirmationEmail($email, $name, $orderId, $orderDetails)
sendOrderStatusEmail($email, $name, $orderId, $status, $message)

// Support
sendSupportReplyEmail($email, $name, $message, $threadLink)

// Account
sendPasswordResetEmail($email, $name, $resetLink)

// Admin
sendAdminNotificationEmail($adminEmail, $subject, $message, $actionLink)

// Generic
getEmailTemplate($title, $content, $ctaText, $ctaLink)
```

## Code Snippets for Common Tasks

### Task 1: Send Order Notification
```php
require_once 'products/includes/notifications.php';
$nm = new NotificationManager();
$nm->notifyOrderCreated($orderId, $userId, $userEmail, $userName);
```

### Task 2: Send Order Status Update
```php
$nm->notifyOrderShipped($orderId, $userId, $userEmail, $userName, 'TRACKING123');
$nm->notifyOrderDelivered($orderId, $userId, $userEmail, $userName);
```

### Task 3: Send Support Notification
```php
$nm->sendNotification(
    $userId,
    'support',
    'Support Reply Received',
    'We replied to your support message',
    $threadId,
    array(
        'email' => $userEmail,
        'subject' => 'Support Reply',
        'body' => sendSupportReplyEmail($userEmail, $userName, $message, $threadLink)
    )
);
```

### Task 4: Admin Send Promotion
```php
$nm->sendNotification(
    $userId,
    'promo',
    '50% Off - Limited Time',
    'Get 50% discount on all products this week',
    null,
    array(
        'email' => $userEmail,
        'subject' => '🎉 50% Off - Limited Time Offer',
        'body' => getEmailTemplate(
            '50% Off - Limited Time', 
            '<p>Get 50% off all products...</p>',
            'Shop Now',
            'http://accountsbazar.com/shop.php'
        )
    )
);
```

### Task 5: Get Notifications for User
```php
$notifications = $nm->getUserNotifications($userId, 10, false);
// Returns last 10 notifications (read + unread)

$unreadOnly = $nm->getUserNotifications($userId, 10, true);
// Returns only unread notifications
```

### Task 6: Mark Notification Read
```php
$nm->markAsRead($notificationId);
```

### Task 7: Check Unread Count
```php
$count = $nm->getUnreadCount($userId);
```

### Task 8: Update User Preferences
```php
$nm->setPreferences($userId, array(
    'email_on_order' => 1,
    'email_on_shipment' => 1,
    'email_on_delivery' => 1,
    'email_on_support' => 1,
    'email_promotions' => 0
));
```

### Task 9: Process Notification Queue Manually
```php
$processed = $nm->processQueuedNotifications();
echo "Processed $processed notifications";
```

### Task 10: Send Direct Email
```php
require_once 'products/includes/mailer.php';
$sent = smtpSendMail(
    'user@example.com',
    'Email Subject',
    getEmailTemplate('Title', '<p>Content</p>')
);
```

## API Usage Examples

### JavaScript - Get Notifications
```javascript
// Get list
fetch('/api-notifications.php?action=list&limit=20')
  .then(r => r.json())
  .then(data => console.log(data.notifications));

// Get unread count
fetch('/api-notifications.php?action=unread_count')
  .then(r => r.json())
  .then(data => console.log('Unread:', data.unread_count));

// Mark as read
const formData = new FormData();
formData.append('notification_id', 123);
fetch('/api-notifications.php?action=mark_read', {
  method: 'POST',
  body: formData
}).then(r => r.json()).then(data => console.log(data));

// Get preferences
fetch('/api-notifications.php?action=get_preferences')
  .then(r => r.json())
  .then(data => console.log(data.preferences));

// Update preferences
const prefs = new FormData();
prefs.append('email_on_order', 1);
prefs.append('email_on_shipment', 1);
prefs.append('email_promotions', 0);
fetch('/api-notifications.php?action=update_preferences', {
  method: 'POST',
  body: prefs
}).then(r => r.json()).then(data => console.log(data));
```

## Configuration Reference

### Mail Config (products/config/mail.php)

```php
// SMTP Settings
MAIL_SMTP_HOST = 'mail.accountsbazar.com'
MAIL_SMTP_PORT = 465
MAIL_SMTP_ENCRYPTION = 'ssl'

// Retry Settings
MAIL_RETRY_ATTEMPTS = 3
MAIL_SEND_TIMEOUT = 30

// Debug & Logging
MAIL_DEBUG_MODE = false
MAIL_LOG_ENABLED = true
```

### Log Location
```
/mail-logs/mail-YYYY-MM-DD.log
```

### Cron Command
```bash
*/5 * * * * curl -s http://accountsbazar.com/cron/process-notifications.php > /dev/null
```

## Dashboard URLs

| Page | URL | Purpose |
|------|-----|---------|
| User Notifications | `/notifications-feed.php` | View all notifications (API) |
| Preferences | `/notification-preferences.php` | Manage email settings |
| Admin Dashboard | `/admin/notifications.php` | Monitor queue status |
| Send Notification | `/admin/notifications-send.php` | Create & send notifications |
| User API | `/api-notifications.php` | Access user notifications |
| Admin API | `/admin/api/notifications.php` | Admin operations |
| Process Queue | `/cron/process-notifications.php` | Manual processing |

## Monitoring Checklist

- [ ] Check `/mail-logs/` daily for errors
- [ ] Monitor queue status at `/admin/notifications.php`
- [ ] Run cron job every 5 minutes
- [ ] Test new notification types with `/admin/notifications-send.php`
- [ ] Verify failed emails are being retried
- [ ] Check user preferences are being respected
- [ ] Monitor SMTP connection issues

---

**This is your quick reference guide for the notification system.**
