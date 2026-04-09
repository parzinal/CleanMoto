<?php
/**
 * Test Email Sending
 * 
 * Run this file to test if email sending is working correctly
 * Delete this file after testing!
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/email_helper.php';

echo "<h2>Email Test</h2>";

// Check if PHPMailer is loaded
if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    echo "<p style='color: green;'>✓ PHPMailer is loaded correctly</p>";
} else {
    echo "<p style='color: red;'>✗ PHPMailer is NOT loaded</p>";
    exit;
}

// Test email helper
$emailHelper = new EmailHelper();

if ($emailHelper->getLastError()) {
    echo "<p style='color: red;'>Error: " . htmlspecialchars($emailHelper->getLastError()) . "</p>";
} else {
    echo "<p style='color: green;'>✓ EmailHelper initialized successfully</p>";
}

// Test sending a verification code
$testEmail = SMTP_FROM_EMAIL; // Send to yourself
$testName = "Test User";
$testCode = sprintf('%06d', random_int(0, 999999));

echo "<p>Attempting to send verification code <strong>{$testCode}</strong> to <strong>{$testEmail}</strong>...</p>";

$result = $emailHelper->sendVerificationCode($testEmail, $testName, $testCode);

if ($result) {
    echo "<p style='color: green; font-size: 1.2em;'>✓ Email sent successfully! Check your inbox.</p>";
} else {
    echo "<p style='color: red;'>✗ Failed to send email</p>";
    echo "<p>Error: " . htmlspecialchars($emailHelper->getLastError()) . "</p>";
}

echo "<hr>";
echo "<p><strong>SMTP Configuration:</strong></p>";
echo "<ul>";
echo "<li>Host: " . SMTP_HOST . "</li>";
echo "<li>Port: " . SMTP_PORT . "</li>";
echo "<li>Secure: " . SMTP_SECURE . "</li>";
echo "<li>Username: " . SMTP_USERNAME . "</li>";
echo "<li>From: " . SMTP_FROM_EMAIL . "</li>";
echo "</ul>";

echo "<hr>";
echo "<p style='color: orange;'><strong>⚠️ Delete this file after testing!</strong></p>";
