<?php
/**
 * Email/SMTP Configuration
 *
 * Use one of the following methods:
 * 1) Create config/email.local.php (recommended for local/dev)
 * 2) Set environment variables (recommended for production)
 */

// Local override (ignored by git)
$localEmailConfig = __DIR__ . '/email.local.php';
if (file_exists($localEmailConfig)) {
	require_once $localEmailConfig;
}

function env_value(string $key, $default = null)
{
	$value = getenv($key);
	return $value === false ? $default : $value;
}

function env_bool(string $key, bool $default): bool
{
	$value = getenv($key);
	if ($value === false) {
		return $default;
	}

	$parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
	return $parsed === null ? $default : $parsed;
}

// SMTP Configuration
if (!defined('SMTP_ENABLED')) {
	define('SMTP_ENABLED', env_bool('SMTP_ENABLED', false)); // Disabled by default for safety
}
if (!defined('SMTP_HOST')) {
	define('SMTP_HOST', env_value('SMTP_HOST', 'smtp.gmail.com'));
}
if (!defined('SMTP_PORT')) {
	define('SMTP_PORT', (int) env_value('SMTP_PORT', 587));
}
if (!defined('SMTP_SECURE')) {
	define('SMTP_SECURE', env_value('SMTP_SECURE', 'tls'));
}
if (!defined('SMTP_AUTH')) {
	define('SMTP_AUTH', env_bool('SMTP_AUTH', true));
}
if (!defined('SMTP_USERNAME')) {
	define('SMTP_USERNAME', env_value('SMTP_USERNAME', ''));
}
if (!defined('SMTP_PASSWORD')) {
	define('SMTP_PASSWORD', env_value('SMTP_PASSWORD', ''));
}
if (!defined('SMTP_FROM_EMAIL')) {
	define('SMTP_FROM_EMAIL', env_value('SMTP_FROM_EMAIL', SMTP_USERNAME));
}
if (!defined('SMTP_FROM_NAME')) {
	define('SMTP_FROM_NAME', env_value('SMTP_FROM_NAME', 'CleanMoto'));
}

// Email Settings
if (!defined('EMAIL_DEBUG')) {
	define('EMAIL_DEBUG', (int) env_value('EMAIL_DEBUG', 0)); // 0 = off, 1 = client, 2 = client + server messages
}
if (!defined('EMAIL_CHARSET')) {
	define('EMAIL_CHARSET', env_value('EMAIL_CHARSET', 'UTF-8'));
}

/**
 * Gmail App Password Instructions:
 * 1. Go to Google Account > Security
 * 2. Enable 2-Step Verification if not already enabled
 * 3. Go to "App passwords" (under 2-Step Verification)
 * 4. Generate a new app password for "Mail"
 * 5. Use that 16-character password as SMTP_PASSWORD
 * 
 * Outlook/Office365:
 * - Host: smtp.office365.com
 * - Port: 587
 * - Secure: tls
 * 
 * Yahoo Mail:
 * - Host: smtp.mail.yahoo.com
 * - Port: 587
 * - Secure: tls
 */
