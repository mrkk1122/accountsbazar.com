<?php
/**
 * Web Push (VAPID) configuration
 *
 * Use environment variables in production when possible:
 * - APP_WEBPUSH_PUBLIC_KEY
 * - APP_WEBPUSH_PRIVATE_KEY
 * - APP_WEBPUSH_SUBJECT (mailto:...)
 */

if (!defined('WEBPUSH_PUBLIC_KEY')) {
    $envPublic = getenv('APP_WEBPUSH_PUBLIC_KEY');
    define('WEBPUSH_PUBLIC_KEY', $envPublic !== false && $envPublic !== ''
        ? $envPublic
        : 'BLEXDIXQsG4a-7CMboPGuRR1c1aByjx7G-6QIggg7sUKwNBhg3tzhZ_gLl5kWw2v2AH-D3qFtqJKrdIIpVpCOLA');
}

if (!defined('WEBPUSH_PRIVATE_KEY')) {
    $envPrivate = getenv('APP_WEBPUSH_PRIVATE_KEY');
    define('WEBPUSH_PRIVATE_KEY', $envPrivate !== false && $envPrivate !== ''
        ? $envPrivate
        : 'uDOhNer6_g2rgAZVInHdkJJRsKDnaGyI7NKmEkccpaY');
}

if (!defined('WEBPUSH_SUBJECT')) {
    $envSubject = getenv('APP_WEBPUSH_SUBJECT');
    define('WEBPUSH_SUBJECT', $envSubject !== false && $envSubject !== ''
        ? $envSubject
        : 'mailto:order@accountsbazar.com');
}
