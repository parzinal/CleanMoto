<?php
/**
 * Local Email Override Example
 *
 * Copy this file to config/email.local.php and update values.
 * This local file is ignored by git.
 */

define('SMTP_ENABLED', true);
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls');
define('SMTP_AUTH', true);
define('SMTP_USERNAME', 'your-email@example.com');
define('SMTP_PASSWORD', 'your-app-password');
define('SMTP_FROM_EMAIL', 'your-email@example.com');
define('SMTP_FROM_NAME', 'CleanMoto');

define('EMAIL_DEBUG', 0);
define('EMAIL_CHARSET', 'UTF-8');
