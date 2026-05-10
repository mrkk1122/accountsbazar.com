# Email & OTP System Guide

## Overview

The Accounts Bazar email system includes:
- **OTP-based Password Reset** (forgot-password.php)
- **Order Confirmation Emails** (checkout.php)
- **Multi-tier Delivery Strategy** (SMTP → PHP mail → Queue)
- **Email Queue Persistence** (database backup)
- **Admin Debugging Tools** (email-queue-debug.php)

---

## How OTP Email Delivery Works

### Step 1: OTP Request
User enters email on forgot-password.php → System generates 6-digit OTP → Stored in `password_resets` table (15 min expiry)

### Step 2: Send Attempts (in priority order)
1. **SMTP Direct** (Port 465 SSL or 587 TLS) - Immediate delivery if mail server reachable
2. **PHP mail()** - Fallback if SMTP fails, uses local system mail
3. **Email Queue** - Stores email in `email_queue` table as backup

### Step 3: Queue Processing
- Auto-processes 3 emails immediately after queuing (for instant delivery)
- Can be run manually: `php process-mail-queue.php`
- Cron job processes remaining emails every 5 minutes

### Result
OTP always stored in database → User proceeds to verify OTP → If email arrives, user sees code in inbox → If email fails, email queued for retry

---

## Configuration

### Mail Server Settings
File: `products/config/mail.php`

```php
define('MAIL_SMTP_HOST', 'mail.accountsbazar.com');
define('MAIL_SMTP_PORT', 465);                    // Primary: SSL
define('MAIL_SMTP_ALT_PORT', 587);                // Fallback: TLS
define('MAIL_SMTP_USERNAME', 'otp@accountsbazar.com');
define('MAIL_SMTP_PASSWORD', '1410689273KK@#');
define('MAIL_DEBUG_MODE', true);                  // Enable for troubleshooting
define('MAIL_LOG_ENABLED', true);                 // Log all mail activity
```

### Environment Variables (Optional Override)
```bash
export MAIL_SMTP_USERNAME="user@example.com"
export MAIL_SMTP_PASSWORD="password"
```

---

## Testing OTP System

### Method 1: Via Web Browser
1. Visit: `https://yoursite.com/forgot-password.php`
2. Enter any email address (doesn't need to be real during dev)
3. Check:
   - Browser shows "OTP sent" or "Email queued" message
   - Mail logs at: `mail-logs/mail-YYYY-MM-DD.log`

### Method 2: Manual Queue Processing
```bash
cd /path/to/accountsbazar.com
php process-mail-queue.php 10    # Process 10 pending emails
```

Output will show:
```
[INFO] Processing 3 pending email(s)...
[PROCESSING] ID=1 TO=user@example.com (Attempt 1/5)
  ✓ SENT via SMTP
[SUMMARY] Sent: 1 | Failed: 2
```

### Method 3: View Email Queue Status
- **Admin Panel**: `http://localhost/admin/email-queue-debug.php`
- **Or with debug token**: `http://yoursite.com/admin/email-queue-debug.php?debug=accounts_bazar_debug_2026`
- **Or localhost access**: `http://127.0.0.1/admin/email-queue-debug.php`

Shows:
- Pending/Sending/Sent/Failed email counts
- List of all queued emails with status
- Retry/Delete buttons for manual control

---

## Mail Logs

Location: `mail-logs/mail-YYYY-MM-DD.log`

### Log Entry Format
```
[2026-05-10 14:48:15] TO: user@example.com | STATUS: FAILED | SUBJECT: ... | ERROR: MAIL FROM/RCPT TO failed
```

### Common Status Values
- **SUCCESS** - Email sent successfully
- **FAILED** - All delivery methods failed (will be retried via queue)

### Troubleshooting Log Errors
- `MAIL FROM/RCPT TO failed` → Mail server rejected SMTP commands
- `Connection failed` → Cannot reach mail server (firewall/DNS issue)
- `Missing SMTP credentials` → MAIL_SMTP_USERNAME or PASSWORD not set
- All mail methods failed → Queue will retry

---

## Cron Job Setup (Production)

### Add to crontab:
```bash
# Every 5 minutes, process email queue
*/5 * * * * php /var/www/html/accountsbazar.com/process-mail-queue.php >> /var/log/email-queue.log 2>&1
```

### Or every 1 minute (if high volume):
```bash
* * * * * php /var/www/html/accountsbazar.com/process-mail-queue.php 50 >> /var/log/email-queue.log 2>&1
```

### Verify cron is running:
```bash
grep CRON /var/log/syslog | tail -20    # On Ubuntu/Debian
```

---

## Database Tables

### password_resets
Stores OTP codes for forgot-password flow:
```sql
CREATE TABLE password_resets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(255),
    otp_code VARCHAR(6),
    expires_at DATETIME,
    created_at TIMESTAMP
);
```

### email_queue
Stores emails that need to be sent:
```sql
CREATE TABLE email_queue (
    id INT PRIMARY KEY AUTO_INCREMENT,
    to_email VARCHAR(255),
    subject VARCHAR(255),
    body MEDIUMTEXT,
    status ENUM('pending', 'sending', 'sent', 'failed'),
    attempts INT,
    last_error TEXT,
    created_at TIMESTAMP,
    sent_at TIMESTAMP
);
```

---

## Troubleshooting

### Problem: OTP not arriving in inbox

**Check 1: Is OTP database entry created?**
```sql
SELECT * FROM password_resets WHERE email = 'test@example.com' ORDER BY id DESC LIMIT 1;
```

**Check 2: View mail logs**
```bash
tail -20 mail-logs/mail-$(date +%Y-%m-%d).log
```

**Check 3: Check email queue**
Visit: `http://localhost/admin/email-queue-debug.php`

**Check 4: Manually process queue**
```bash
php process-mail-queue.php
```

---

### Problem: "MAIL FROM/RCPT TO failed" error

**Cause**: Mail server rejected SMTP authentication or sender address

**Solutions**:
1. Verify credentials in `mail.php`:
   - Username: `otp@accountsbazar.com`
   - Password: Check with hosting provider
   - Host/Port: `mail.accountsbazar.com:465` (SSL) or `:587` (TLS)

2. Test SMTP connection manually (if telnet available):
   ```bash
   openssl s_client -connect mail.accountsbazar.com:465
   ```

3. Check if sender email (`MAIL_FROM_ADDRESS`) is authorized to send

---

### Problem: PHP mail() fallback not working

**Cause**: Container/server doesn't have local MTA (Postfix/Sendmail)

**Solution**: 
- In production with proper mail server, use SMTP (configured above)
- In development, rely on email queue + manual processing
- Or install Postfix: `apt-get install postfix` (not recommended in containers)

---

## Code Examples

### Sending OTP Email (in forgot-password.php)
```php
$otp = (string)random_int(100000, 999999);

// Try SMTP first
$smtpSent = smtpSendMail($email, 'OTP Email', $htmlBody);

// Queue as backup
$mailQueued = enqueueEmail($conn, $email, 'OTP Email', $htmlBody);

// Try PHP mail() as final fallback
if (!$smtpSent && !$mailQueued) {
    $phpMailSent = @mail($email, 'OTP Email', $htmlBody, $headers);
}

// Process queue immediately (3 emails)
if ($mailQueued) {
    $result = processEmailQueue($conn, 3);
}
```

### Processing Email Queue (CLI)
```php
// Via command line
php process-mail-queue.php 20

// Programmatically
require_once 'products/includes/mailer.php';
$result = processEmailQueue($conn, 20);
echo "Sent: " . $result['sent'] . ", Queued: " . $result['queued'];
```

---

## Security Considerations

1. **OTP Expiry**: 15 minutes (configurable in forgot-password.php)
2. **Rate Limiting**: Max 3 OTP requests per email per 10 minutes
3. **Mail Credentials**: Store in environment variables (not in code)
4. **Debug Mode**: Disable in production (`MAIL_DEBUG_MODE = false`)
5. **Queue Access**: Admin-only or localhost only (email-queue-debug.php)

---

## Files Modified/Created

1. **forgot-password.php** - Enhanced OTP delivery with fallbacks
2. **products/includes/mailer.php** - Multi-port SMTP, PHP mail() fallback
3. **products/config/mail.php** - Mail server configuration
4. **process-mail-queue.php** (NEW) - CLI queue processor
5. **admin/email-queue-debug.php** (NEW) - Admin debugging UI

---

## Support

For issues:
1. Check mail logs: `mail-logs/mail-*.log`
2. View email queue: `/admin/email-queue-debug.php?debug=accounts_bazar_debug_2026`
3. Run manual queue: `php process-mail-queue.php`
4. Check database entries directly: `SELECT * FROM email_queue LIMIT 10;`
