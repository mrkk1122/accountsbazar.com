# Notification System - Deployment Checklist ✅

## Pre-Deployment Verification

### Phase 1: Configuration
- [ ] Update `products/config/mail.php` with MAIL_SUPPORT_PASSWORD
- [ ] Verify SMTP credentials are correct
- [ ] Set MAIL_DEBUG_MODE to false (production)
- [ ] Confirm MAIL_LOG_ENABLED is true

### Phase 2: Database
- [ ] Verify `notifications` table exists
- [ ] Verify `notification_preferences` table exists
- [ ] Verify `notification_queue` table exists
- [ ] Run queries to confirm tables are populated

Check with:
```php
<?php
require_once 'products/includes/db.php';
$db = new Database();
$conn = $db->getConnection();
$tables = ['notifications', 'notification_preferences', 'notification_queue'];
foreach ($tables as $t) {
    $check = $conn->query("SHOW TABLES LIKE '$t'");
    echo $t . ': ' . ($check->num_rows > 0 ? '✓' : '✗') . "\n";
}
?>
```

### Phase 3: File Permissions
- [ ] Create `/mail-logs/` directory with 755 permissions
- [ ] Create `/cron/logs/` directory with 755 permissions
- [ ] Verify write permissions: `chmod 777 mail-logs/`

## Feature Verification

### Phase 4: Test Email System
1. [ ] Navigate to `/admin/notifications-send.php`
2. [ ] Send test notification to yourself
3. [ ] Check email inbox for message
4. [ ] Verify HTML formatting looks good
5. [ ] Check `/mail-logs/mail-DATE.log` for success entry

### Phase 5: Test User Registration
1. [ ] Register a new test account
2. [ ] Verify welcome email is received
3. [ ] Check email has HTML template
4. [ ] Verify user can login after registration

### Phase 6: Test Order Notifications
1. [ ] Place a test order
2. [ ] Verify order confirmation email
3. [ ] Check in-app notification is created
4. [ ] Monitor queue status at `/admin/notifications.php`

### Phase 7: Test Admin Interface
1. [ ] Navigate to `/admin/notifications.php`
2. [ ] [ ] Verify dashboard shows notification stats
3. [ ] [ ] Check "Process Pending Notifications" button works
4. [ ] [ ] Verify recent notifications table displays

### Phase 8: Test User Preferences
1. [ ] Login as test user
2. [ ] Navigate to `/notification-preferences.php`
3. [ ] Change preferences
4. [ ] Save and verify update success message
5. [ ] Logout and login to verify persistence

## API Testing

### Phase 9: Test User APIs
- [ ] GET `/api-notifications.php?action=list` returns 200
- [ ] GET `/api-notifications.php?action=unread_count` returns count
- [ ] POST `/api-notifications.php?action=mark_read` works
- [ ] GET `/api-notifications.php?action=get_preferences` returns prefs
- [ ] POST `/api-notifications.php?action=update_preferences` works

Test with:
```bash
curl -b "PHPSESSID=YOUR_SESSION" http://localhost/api-notifications.php?action=list
```

### Phase 10: Test Admin APIs
- [ ] POST `/admin/api/notifications.php?action=send_to_user` works
- [ ] POST `/admin/api/notifications.php?action=send_bulk` works
- [ ] POST `/admin/api/notifications.php?action=send_all` works
- [ ] GET `/admin/api/notifications.php?action=queue_status` returns stats

## Production Setup

### Phase 11: Set Up Cron Job
1. [ ] Access cPanel → Cron Jobs
2. [ ] Add new cron job with:
   - **Email:** Your email
   - **Minute:** */5
   - **Hour:** * (any)
   - **Day:** * (any)
   - **Month:** * (any)
   - **Weekday:** * (any)
   - **Command:** `curl -s http://accountsbazar.com/cron/process-notifications.php > /dev/null`
3. [ ] Save cron job
4. [ ] Verify it runs: Check `/cron/logs/notifications-cron.log`

### Phase 12: Email Sending Verification
1. [ ] Wait 5 minutes after placing first order
2. [ ] Check `/admin/notifications.php` - queue should be empty
3. [ ] Check email inbox - confirmation should be received
4. [ ] Check `/mail-logs/` - entries should show SUCCESS

### Phase 13: Production Monitoring
- [ ] Create daily check for `/mail-logs/` errors
- [ ] Monitor `/admin/notifications.php` queue size
- [ ] Check cron job output: `tail -f /cron/logs/notifications-cron.log`
- [ ] Set up alerts if queue grows > 50 items

## Integration Checklist

### Phase 14: Support Chat Integration
- [ ] Update `support-chat-api.php` to send notifications on reply
- [ ] Test support notification email
- [ ] Verify in-app notification appears

### Phase 15: Admin Order Management Integration  
- [ ] Add notification on order status change
- [ ] Test shipment notification email
- [ ] Test delivery notification email
- [ ] Verify tracking number is included

### Phase 16: Password Reset Integration
- [ ] Add notification for password reset request
- [ ] Verify reset email is sent
- [ ] Verify reset link works

## Performance Optimization

### Phase 17: Performance Review
- [ ] Database indexes created (auto-done)
- [ ] Monitor query performance on notifications table
- [ ] Check response time of API endpoints
- [ ] Verify queue processing completes in < 30 seconds

## Security Verification

### Phase 18: Security Checks
- [ ] Verify user can only access own notifications
- [ ] Verify admin auth required for admin APIs
- [ ] Test CSRF protection on preferences
- [ ] Verify no sensitive data in logs
- [ ] Check password not visible in database

## Documentation

### Phase 19: Documentation Review
- [ ] Read `NOTIFICATION_SYSTEM.md` for reference
- [ ] Review `NOTIFICATION_SETUP.md` for quick start
- [ ] Check `NOTIFICATION_QUICK_REFERENCE.md` for code samples
- [ ] Share docs with team members

## Rollback Plan

If issues occur:

1. **Database:**
   - Keep backup of `notifications` table
   - Can safely delete all records - they're non-critical

2. **Disable Notifications:**
   ```php
   // Temporarily disable in config
   define('MAIL_LOG_ENABLED', false);
   define('MAIL_SEND_TIMEOUT', 5); // Fast fail
   ```

3. **Clear Queue:**
   ```sql
   DELETE FROM notification_queue WHERE status = 'pending';
   ```

4. **Stop Cron:**
   - Remove cron job from cPanel

## Success Criteria

✅ **Email System Working:**
- [ ] Emails received within 5 minutes of event
- [ ] HTML formatting displays correctly
- [ ] No emails bouncing or failing
- [ ] Retry mechanism working for failures

✅ **Admin Interface Working:**
- [ ] Dashboard shows accurate stats
- [ ] Can send notifications to users
- [ ] Queue status displays correctly
- [ ] Process button clears pending items

✅ **User Features Working:**
- [ ] Users see notifications in feed
- [ ] Users can manage preferences
- [ ] Email opt-out is respected
- [ ] Unread count is accurate

✅ **Integration Complete:**
- [ ] Registration emails sent
- [ ] Order confirmations sent
- [ ] Status updates available
- [ ] Support integration working

## Sign-Off

**System Ready for Production:** [ ] YES [ ] NO

**Date Deployed:** ___________

**Tested By:** ___________

**Issues Found:** ___________

**Notes:** ___________

---

## Support Contact

For issues or questions:
1. Check `/mail-logs/` for error messages
2. Review `NOTIFICATION_SYSTEM.md` documentation
3. Test with `/admin/notifications-send.php`
4. Check queue status at `/admin/notifications.php`

**Everything is ready to deploy!** ✅
