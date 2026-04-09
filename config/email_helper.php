<?php
/**
 * Email Helper Class
 * 
 * Handles sending emails via SMTP using PHPMailer
 */

require_once __DIR__ . '/../config/email.php';

// Include Composer autoloader if available
$composerAutoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

class EmailHelper
{
    private $mailer;
    private $lastError = '';
    
    public function __construct()
    {
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            $this->lastError = 'PHPMailer is not installed. Run "composer install" in the project root.';
            error_log($this->lastError);
            return;
        }
        
        $this->mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
        $this->configure();
    }
    
    /**
     * Configure SMTP settings
     */
    private function configure()
    {
        if (!$this->mailer) {
            return;
        }
        
        try {
            // Server settings
            $this->mailer->SMTPDebug = EMAIL_DEBUG;
            $this->mailer->isSMTP();
            $this->mailer->Host = SMTP_HOST;
            $this->mailer->SMTPAuth = SMTP_AUTH;
            $this->mailer->Username = SMTP_USERNAME;
            $this->mailer->Password = SMTP_PASSWORD;
            $this->mailer->SMTPSecure = SMTP_SECURE === 'ssl' ? constant('PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS') : constant('PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS');
            $this->mailer->Port = SMTP_PORT;
            $this->mailer->CharSet = EMAIL_CHARSET;
            
            // Default sender
            $this->mailer->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            error_log('Email configuration error: ' . $e->getMessage());
        }
    }
    
    /**
     * Send a plain text email
     * 
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $body Email body (plain text)
     * @param string|null $toName Recipient name (optional)
     * @return bool Success status
     */
    public function send($to, $subject, $body, $toName = null)
    {
        if (!$this->mailer) {
            error_log('PHPMailer not available. Email not sent to: ' . $to);
            return false;
        }
        
        if (!SMTP_ENABLED) {
            error_log('Email sending is disabled. Would have sent to: ' . $to);
            return true; // Return true to not break the flow
        }
        
        try {
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($to, $toName ?? '');
            $this->mailer->isHTML(false);
            $this->mailer->Subject = $subject;
            $this->mailer->Body = $body;
            
            return $this->mailer->send();
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            error_log('Email sending error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send an HTML email
     * 
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $htmlBody HTML email body
     * @param string|null $plainBody Plain text alternative (optional)
     * @param string|null $toName Recipient name (optional)
     * @return bool Success status
     */
    public function sendHtml($to, $subject, $htmlBody, $plainBody = null, $toName = null)
    {
        if (!$this->mailer) {
            error_log('PHPMailer not available. Email not sent to: ' . $to);
            return false;
        }
        
        if (!SMTP_ENABLED) {
            error_log('Email sending is disabled. Would have sent to: ' . $to);
            return true;
        }
        
        try {
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($to, $toName ?? '');
            $this->mailer->isHTML(true);
            $this->mailer->Subject = $subject;
            $this->mailer->Body = $htmlBody;
            
            if ($plainBody) {
                $this->mailer->AltBody = $plainBody;
            } else {
                $this->mailer->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));
            }
            
            return $this->mailer->send();
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            error_log('Email sending error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send password reset email with verification code
     * 
     * @param string $to Recipient email
     * @param string $name Recipient name
     * @param string $resetLink Password reset link
     * @param string $code Verification code (optional)
     * @return bool Success status
     */
    public function sendPasswordResetEmail($to, $name, $resetLink, $code = null)
    {
        $subject = 'Password Reset Request - ' . APP_NAME;
        
        $htmlBody = $this->getPasswordResetTemplate($name, $resetLink, $code);
        
        $plainBody = "Hello {$name},\n\n";
        $plainBody .= "You have requested to reset your password for your " . APP_NAME . " account.\n\n";
        if ($code) {
            $plainBody .= "Your verification code is: {$code}\n\n";
        }
        $plainBody .= "Click the link below to reset your password:\n";
        $plainBody .= "{$resetLink}\n\n";
        $plainBody .= "This link will expire in 1 hour.\n\n";
        $plainBody .= "If you did not request this, please ignore this email.\n\n";
        $plainBody .= "Best regards,\n" . APP_NAME . " Team";
        
        return $this->sendHtml($to, $subject, $htmlBody, $plainBody, $name);
    }
    
    /**
     * Send verification code email
     * 
     * @param string $to Recipient email
     * @param string $name Recipient name
     * @param string $code Verification code
     * @return bool Success status
     */
    public function sendVerificationCode($to, $name, $code)
    {
        $subject = 'Your Verification Code - ' . APP_NAME;
        
        $htmlBody = $this->getVerificationCodeTemplate($name, $code);
        
        $plainBody = "Hello {$name},\n\n";
        $plainBody .= "Your verification code for " . APP_NAME . " is:\n\n";
        $plainBody .= "{$code}\n\n";
        $plainBody .= "This code will expire in 10 minutes.\n\n";
        $plainBody .= "If you did not request this code, please ignore this email.\n\n";
        $plainBody .= "Best regards,\n" . APP_NAME . " Team";
        
        return $this->sendHtml($to, $subject, $htmlBody, $plainBody, $name);
    }
    
    /**
     * Get the last error message
     * 
     * @return string
     */
    public function getLastError()
    {
        return $this->lastError;
    }
    
    /**
     * Generate password reset email template
     */
    private function getPasswordResetTemplate($name, $resetLink, $code = null)
    {
        $codeSection = '';
        if ($code) {
            $codeSection = '
                <div style="background: #1A1A1A; border-radius: 12px; padding: 20px; text-align: center; margin: 20px 0;">
                    <p style="color: #A8A8A8; font-size: 14px; margin: 0 0 10px 0;">Your Verification Code</p>
                    <p style="font-size: 32px; font-weight: bold; color: #E63946; letter-spacing: 8px; margin: 0;">' . $code . '</p>
                </div>
            ';
        }
        
        return '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; font-family: \'Inter\', -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; background-color: #0D0D0D;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #0D0D0D; padding: 40px 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color: #1A1A1A; border-radius: 16px; overflow: hidden; border: 1px solid #333333;">
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #E63946 0%, #C72736 100%); padding: 30px; text-align: center;">
                            <h1 style="color: #FFFFFF; margin: 0; font-size: 24px;">' . APP_NAME . '</h1>
                        </td>
                    </tr>
                    
                    <!-- Body -->
                    <tr>
                        <td style="padding: 40px 30px;">
                            <h2 style="color: #F1FAEE; font-size: 22px; margin: 0 0 20px 0;">Password Reset Request</h2>
                            <p style="color: #A8A8A8; font-size: 16px; line-height: 1.6; margin: 0 0 20px 0;">
                                Hello <strong style="color: #F1FAEE;">' . htmlspecialchars($name) . '</strong>,
                            </p>
                            <p style="color: #A8A8A8; font-size: 16px; line-height: 1.6; margin: 0 0 20px 0;">
                                You have requested to reset your password. Click the button below to proceed:
                            </p>
                            
                            ' . $codeSection . '
                            
                            <!-- Button -->
                            <table width="100%" cellpadding="0" cellspacing="0" style="margin: 30px 0;">
                                <tr>
                                    <td align="center">
                                        <a href="' . htmlspecialchars($resetLink) . '" style="display: inline-block; background: linear-gradient(135deg, #E63946 0%, #C72736 100%); color: #FFFFFF; text-decoration: none; padding: 15px 40px; border-radius: 8px; font-weight: 600; font-size: 16px;">Reset Password</a>
                                    </td>
                                </tr>
                            </table>
                            
                            <p style="color: #A8A8A8; font-size: 14px; line-height: 1.6; margin: 20px 0 0 0;">
                                This link will expire in <strong style="color: #F1FAEE;">1 hour</strong>.
                            </p>
                            <p style="color: #A8A8A8; font-size: 14px; line-height: 1.6; margin: 10px 0 0 0;">
                                If you did not request this password reset, please ignore this email or contact support if you have concerns.
                            </p>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #0D0D0D; padding: 20px 30px; border-top: 1px solid #333333;">
                            <p style="color: #666666; font-size: 12px; margin: 0; text-align: center;">
                                © ' . date('Y') . ' ' . APP_NAME . '. All rights reserved.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }
    
    /**
     * Generate verification code email template
     */
    private function getVerificationCodeTemplate($name, $code)
    {
        return '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; font-family: \'Inter\', -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; background-color: #0D0D0D;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #0D0D0D; padding: 40px 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color: #1A1A1A; border-radius: 16px; overflow: hidden; border: 1px solid #333333;">
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #E63946 0%, #C72736 100%); padding: 30px; text-align: center;">
                            <h1 style="color: #FFFFFF; margin: 0; font-size: 24px;">' . APP_NAME . '</h1>
                        </td>
                    </tr>
                    
                    <!-- Body -->
                    <tr>
                        <td style="padding: 40px 30px; text-align: center;">
                            <h2 style="color: #F1FAEE; font-size: 22px; margin: 0 0 20px 0;">Verification Code</h2>
                            <p style="color: #A8A8A8; font-size: 16px; line-height: 1.6; margin: 0 0 30px 0;">
                                Hello <strong style="color: #F1FAEE;">' . htmlspecialchars($name) . '</strong>,<br>
                                Use the code below to verify your request:
                            </p>
                            
                            <!-- Code Box -->
                            <div style="background: #0D0D0D; border-radius: 12px; padding: 30px; margin: 20px 0; border: 2px solid #E63946;">
                                <p style="font-size: 40px; font-weight: bold; color: #E63946; letter-spacing: 12px; margin: 0;">' . $code . '</p>
                            </div>
                            
                            <p style="color: #A8A8A8; font-size: 14px; line-height: 1.6; margin: 20px 0 0 0;">
                                This code will expire in <strong style="color: #F1FAEE;">10 minutes</strong>.
                            </p>
                            <p style="color: #A8A8A8; font-size: 14px; line-height: 1.6; margin: 10px 0 0 0;">
                                If you did not request this code, please ignore this email.
                            </p>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #0D0D0D; padding: 20px 30px; border-top: 1px solid #333333;">
                            <p style="color: #666666; font-size: 12px; margin: 0; text-align: center;">
                                © ' . date('Y') . ' ' . APP_NAME . '. All rights reserved.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }
}

/**
 * Helper function to send email
 * 
 * @param string $to Recipient email
 * @param string $subject Email subject
 * @param string $body Email body
 * @param bool $isHtml Whether the body is HTML
 * @return bool Success status
 */
function sendEmail($to, $subject, $body, $isHtml = false)
{
    try {
        $emailHelper = new EmailHelper();
        if ($isHtml) {
            return $emailHelper->sendHtml($to, $subject, $body);
        }
        return $emailHelper->send($to, $subject, $body);
    } catch (\Exception $e) {
        error_log('Failed to send email: ' . $e->getMessage());
        return false;
    }
}

/**
 * Send password reset email
 * 
 * @param string $email Recipient email
 * @param string $name Recipient name
 * @param string $resetLink Reset link URL
 * @param string|null $code Verification code
 * @return bool Success status
 */
function sendPasswordResetEmail($email, $name, $resetLink, $code = null)
{
    try {
        $emailHelper = new EmailHelper();
        return $emailHelper->sendPasswordResetEmail($email, $name, $resetLink, $code);
    } catch (\Exception $e) {
        error_log('Failed to send password reset email: ' . $e->getMessage());
        return false;
    }
}

/**
 * Send verification code email
 * 
 * @param string $email Recipient email
 * @param string $name Recipient name
 * @param string $code Verification code
 * @return bool Success status
 */
function sendVerificationCodeEmail($email, $name, $code)
{
    try {
        $emailHelper = new EmailHelper();
        return $emailHelper->sendVerificationCode($email, $name, $code);
    } catch (\Exception $e) {
        error_log('Failed to send verification code email: ' . $e->getMessage());
        return false;
    }
}
