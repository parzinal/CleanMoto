<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/email_helper.php';

// Redirect if already logged in
if (isLoggedIn()) {
    $role = getUserRole();
    if (!empty($role)) {
        redirect("$role/pages/dashboard.php");
    } else {
        // Invalid session, logout
        session_destroy();
    }
}

// Session for storing email during verification
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';
$success = '';
$step = $_GET['step'] ?? 'email'; // email, verify, or reset

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step === 'email') {
        // Step 1: Enter email and send verification code
        $email = sanitize($_POST['email'] ?? '');

        if (empty($email)) {
            $error = 'Please enter your email address';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address';
        } else {
            try {
                $db = Database::getInstance()->getConnection();
                
                // Check if email exists
                $stmt = $db->prepare("SELECT id, name, email FROM users WHERE email = ? AND status = 'active' LIMIT 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if ($user) {
                    // Generate 6-digit verification code
                    $verificationCode = sprintf('%06d', random_int(0, 999999));
                    $token = bin2hex(random_bytes(32));
                    // Use database time to avoid timezone issues
                    $db = Database::getInstance()->getConnection();
                    $timeStmt = $db->query("SELECT DATE_ADD(NOW(), INTERVAL 15 MINUTE) as expiry");
                    $timeResult = $timeStmt->fetch();
                    $expiry = $timeResult['expiry'];

                    // Delete any existing reset requests for this user
                    $deleteStmt = $db->prepare("DELETE FROM password_resets WHERE user_id = ?");
                    $deleteStmt->execute([$user['id']]);

                    // Store reset token and verification code
                    $insertStmt = $db->prepare("INSERT INTO password_resets (user_id, token, verification_code, expires_at) VALUES (?, ?, ?, ?)");
                    $insertStmt->execute([$user['id'], $token, $verificationCode, $expiry]);

                    // Send verification code email
                    $emailHelper = new EmailHelper();
                    $emailSent = $emailHelper->sendVerificationCode($email, $user['name'], $verificationCode);
                    
                    if ($emailSent) {
                        // Store email in session for next step
                        $_SESSION['reset_email'] = $email;
                        $_SESSION['reset_token'] = $token;
                        
                        // Log activity
                        $logStmt = $db->prepare("INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
                        $logStmt->execute([
                            $user['id'],
                            'password_reset_request',
                            'Verification code sent to email',
                            $_SERVER['REMOTE_ADDR'] ?? '',
                            $_SERVER['HTTP_USER_AGENT'] ?? ''
                        ]);

                        // Redirect to verification step
                        header("Location: " . APP_URL . "/forgot-password.php?step=verify");
                        exit;
                    } else {
                        $error = 'Failed to send verification code. Please try again.';
                        error_log('Failed to send verification code to: ' . $email . ' - Error: ' . $emailHelper->getLastError());
                    }
                } else {
                    // Don't reveal if email exists or not for security (optional: can show error)
                    $error = 'No account found with this email address';
                }
            } catch (PDOException $e) {
                $error = 'An error occurred. Please try again.';
                error_log($e->getMessage());
            }
        }
    } elseif ($step === 'verify') {
        // Step 2: Verify the code
        $code = sanitize($_POST['code'] ?? '');
        $email = $_SESSION['reset_email'] ?? '';
        $token = $_SESSION['reset_token'] ?? '';

        if (empty($email) || empty($token)) {
            $error = 'Session expired. Please start over.';
            $step = 'email';
        } elseif (empty($code)) {
            $error = 'Please enter the verification code';
        } elseif (strlen($code) !== 6 || !ctype_digit($code)) {
            $error = 'Please enter a valid 6-digit code';
        } else {
            try {
                $db = Database::getInstance()->getConnection();
                
                // Debug: Log the values we're checking
                error_log("Verification attempt - Email: $email, Token: " . substr($token, 0, 10) . "..., Code: $code");
                
                // First check if a record exists at all for this token
                $debugStmt = $db->prepare("SELECT id, verification_code, expires_at, used FROM password_resets WHERE token = ? LIMIT 1");
                $debugStmt->execute([$token]);
                $debugRecord = $debugStmt->fetch();
                
                if ($debugRecord) {
                    error_log("Found reset record - DB Code: " . $debugRecord['verification_code'] . ", Input Code: $code, Expired: " . ($debugRecord['expires_at'] < date('Y-m-d H:i:s') ? 'YES' : 'NO') . ", Used: " . $debugRecord['used']);
                } else {
                    error_log("No password reset record found for token");
                }
                
                // Verify the code - simplified query
                $stmt = $db->prepare("
                    SELECT pr.id, pr.user_id 
                    FROM password_resets pr 
                    WHERE pr.token = ?
                    AND pr.verification_code = ?
                    AND pr.expires_at > NOW() 
                    AND pr.used = 0 
                    LIMIT 1
                ");
                $stmt->execute([$token, $code]);
                $resetRecord = $stmt->fetch();

                if ($resetRecord) {
                    // Code is valid, proceed to reset step
                    $_SESSION['reset_verified'] = true;
                    $_SESSION['reset_user_id'] = $resetRecord['user_id'];
                    $_SESSION['reset_id'] = $resetRecord['id'];
                    
                    header("Location: " . APP_URL . "/forgot-password.php?step=reset");
                    exit;
                } else {
                    $error = 'Invalid or expired verification code';
                }
            } catch (PDOException $e) {
                $error = 'An error occurred. Please try again.';
                error_log($e->getMessage());
            }
        }
    } elseif ($step === 'reset') {
        // Step 3: Reset password
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $userId = $_SESSION['reset_user_id'] ?? null;
        $resetId = $_SESSION['reset_id'] ?? null;
        $verified = $_SESSION['reset_verified'] ?? false;

        if (!$verified || !$userId || !$resetId) {
            $error = 'Session expired. Please start over.';
            $step = 'email';
        } elseif (empty($password) || empty($confirm_password)) {
            $error = 'Please fill in all fields';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters long';
        } elseif ($password !== $confirm_password) {
            $error = 'Passwords do not match';
        } else {
            try {
                $db = Database::getInstance()->getConnection();
                
                // Update password
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $updateStmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                $updateStmt->execute([$hashedPassword, $userId]);

                // Mark token as used
                $markUsedStmt = $db->prepare("UPDATE password_resets SET used = 1 WHERE id = ?");
                $markUsedStmt->execute([$resetId]);

                // Log activity
                $logStmt = $db->prepare("INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
                $logStmt->execute([
                    $userId,
                    'password_reset',
                    'Password was reset successfully',
                    $_SERVER['REMOTE_ADDR'] ?? '',
                    $_SERVER['HTTP_USER_AGENT'] ?? ''
                ]);

                // Clear session data
                unset($_SESSION['reset_email'], $_SESSION['reset_token'], $_SESSION['reset_verified'], $_SESSION['reset_user_id'], $_SESSION['reset_id']);

                $success = 'Password reset successful! Redirecting to login...';
                header("refresh:2;url=" . APP_URL . "/login.php");
            } catch (PDOException $e) {
                $error = 'An error occurred. Please try again.';
                error_log($e->getMessage());
            }
        }
    }
}

// Handle resend request
if (isset($_GET['resend']) && $_GET['resend'] == '1' && isset($_GET['email'])) {
    $_POST['email'] = urldecode($_GET['email']);
    $step = 'email';
    // Will be processed above if POST, but we need to trigger it via GET
}

// Verify session for verify and reset steps
if ($step === 'verify') {
    if (empty($_SESSION['reset_email']) || empty($_SESSION['reset_token'])) {
        header("Location: " . APP_URL . "/forgot-password.php?step=email");
        exit;
    }
}

if ($step === 'reset') {
    if (empty($_SESSION['reset_verified']) || empty($_SESSION['reset_user_id'])) {
        header("Location: " . APP_URL . "/forgot-password.php?step=email");
        exit;
    }
}

// Get masked email for display
$maskedEmail = '';
if (!empty($_SESSION['reset_email'])) {
    $email = $_SESSION['reset_email'];
    $parts = explode('@', $email);
    $name = $parts[0];
    $domain = $parts[1] ?? '';
    $maskedName = substr($name, 0, 2) . str_repeat('*', max(strlen($name) - 2, 3));
    $maskedEmail = $maskedName . '@' . $domain;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        .code-inputs {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin: 25px 0;
        }
        
        .code-input {
            width: 50px;
            height: 60px;
            text-align: center;
            font-size: 24px;
            font-weight: 600;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            background: var(--secondary-bg);
            color: var(--white);
            transition: all 0.2s ease;
        }
        
        .code-input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(230, 57, 70, 0.2);
        }
        
        .code-input.filled {
            border-color: var(--accent);
            background: rgba(230, 57, 70, 0.1);
        }
        
        .resend-section {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
        }
        
        .resend-text {
            color: var(--gray-text);
            font-size: 0.9rem;
        }
        
        .resend-btn {
            background: none;
            border: none;
            color: var(--accent);
            cursor: pointer;
            font-weight: 500;
            font-size: 0.9rem;
            text-decoration: underline;
        }
        
        .resend-btn:disabled {
            color: var(--gray-text);
            cursor: not-allowed;
            text-decoration: none;
        }
        
        .timer {
            color: var(--accent);
            font-weight: 600;
        }
        
        .steps-indicator {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-bottom: 25px;
        }
        
        .step-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--border-color);
            transition: all 0.3s ease;
        }
        
        .step-dot.active {
            background: var(--accent);
            transform: scale(1.2);
        }
        
        .step-dot.completed {
            background: #22c55e;
        }
        
        .email-icon {
            font-size: 48px;
            text-align: center;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <div class="animated-bg"></div>

    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-logo">
                <a href="<?php echo APP_URL; ?>">
                    <img src="<?php echo APP_URL; ?>/assets/image/CleanMoto_Logo.png" alt="CleanMoto Logo">
                </a>
            </div>

            <!-- Steps Indicator -->
            <div class="steps-indicator">
                <div class="step-dot <?php echo $step === 'email' ? 'active' : ($step !== 'email' ? 'completed' : ''); ?>"></div>
                <div class="step-dot <?php echo $step === 'verify' ? 'active' : ($step === 'reset' ? 'completed' : ''); ?>"></div>
                <div class="step-dot <?php echo $step === 'reset' ? 'active' : ''; ?>"></div>
            </div>

            <?php if ($step === 'email'): ?>
                <!-- Step 1: Enter Email -->
                <h1 class="auth-title">Forgot Password?</h1>
                <p class="auth-subtitle">Enter your email to receive a verification code</p>

                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <span>⚠️</span>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="?step=email" id="emailForm">
                    <div class="form-group">
                        <label for="email" class="form-label">Email Address</label>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            class="form-input" 
                            placeholder="your@email.com"
                            value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                            required
                            autocomplete="email"
                        >
                    </div>

                    <button type="submit" class="btn btn-primary btn-block">
                        Send Verification Code
                    </button>
                </form>

            <?php elseif ($step === 'verify'): ?>
                <!-- Step 2: Enter Verification Code -->
                <div class="email-icon">📧</div>
                <h1 class="auth-title">Check Your Email</h1>
                <p class="auth-subtitle">We've sent a 6-digit code to<br><strong><?php echo htmlspecialchars($maskedEmail); ?></strong></p>

                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <span>⚠️</span>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="?step=verify" id="verifyForm">
                    <input type="hidden" name="code" id="codeValue">
                    
                    <div class="code-inputs" onclick="document.getElementById('code0').focus()">
                        <input type="text" class="code-input" id="code0" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="one-time-code">
                        <input type="text" class="code-input" id="code1" maxlength="1" inputmode="numeric" pattern="[0-9]">
                        <input type="text" class="code-input" id="code2" maxlength="1" inputmode="numeric" pattern="[0-9]">
                        <input type="text" class="code-input" id="code3" maxlength="1" inputmode="numeric" pattern="[0-9]">
                        <input type="text" class="code-input" id="code4" maxlength="1" inputmode="numeric" pattern="[0-9]">
                        <input type="text" class="code-input" id="code5" maxlength="1" inputmode="numeric" pattern="[0-9]">
                    </div>

                    <button type="submit" class="btn btn-primary btn-block" id="verifyBtn" disabled>
                        Verify Code
                    </button>
                </form>

                <div class="resend-section">
                    <p class="resend-text">
                        Didn't receive the code? 
                        <button type="button" class="resend-btn" id="resendBtn" disabled>
                            Resend in <span class="timer" id="timer">60</span>s
                        </button>
                    </p>
                </div>

            <?php elseif ($step === 'reset'): ?>
                <!-- Step 3: Reset Password -->
                <h1 class="auth-title">Create New Password</h1>
                <p class="auth-subtitle">Your new password must be different from previous passwords</p>

                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <span>⚠️</span>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <span>✓</span>
                        <span><?php echo htmlspecialchars($success); ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!$success): ?>
                    <form method="POST" action="?step=reset" id="resetForm">
                        <div class="form-group">
                            <label for="password" class="form-label">New Password</label>
                            <input 
                                type="password" 
                                id="password" 
                                name="password" 
                                class="form-input" 
                                placeholder="Minimum 8 characters"
                                required
                                autocomplete="new-password"
                                minlength="8"
                            >
                            <small style="color: var(--gray-text); font-size: 0.85rem; display: block; margin-top: 0.5rem;">
                                Must be at least 8 characters long
                            </small>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password" class="form-label">Confirm New Password</label>
                            <input 
                                type="password" 
                                id="confirm_password" 
                                name="confirm_password" 
                                class="form-input" 
                                placeholder="Re-enter your password"
                                required
                                autocomplete="new-password"
                                minlength="8"
                            >
                        </div>

                        <button type="submit" class="btn btn-primary btn-block">
                            Reset Password
                        </button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

            <div class="divider">or</div>

            <div class="form-footer">
                Remember your password? <a href="<?php echo APP_URL; ?>/login.php">Sign in</a>
            </div>

            <?php if ($step !== 'email'): ?>
            <div class="form-footer mt-2" style="font-size: 0.85rem;">
                <a href="<?php echo APP_URL; ?>/forgot-password.php?step=email">← Start Over</a>
            </div>
            <?php else: ?>
            <div class="form-footer mt-2" style="font-size: 0.85rem;">
                <a href="<?php echo APP_URL; ?>">← Back to Home</a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        <?php if ($step === 'verify'): ?>
        // Code input handling
        const inputs = document.querySelectorAll('.code-input');
        const verifyBtn = document.getElementById('verifyBtn');
        const codeValue = document.getElementById('codeValue');
        const form = document.getElementById('verifyForm');
        
        function updateCodeValue() {
            let code = '';
            inputs.forEach(input => {
                code += input.value;
            });
            codeValue.value = code;
            
            // Enable/disable verify button
            if (code.length === 6) {
                verifyBtn.disabled = false;
            } else {
                verifyBtn.disabled = true;
            }
        }
        
        inputs.forEach((input, index) => {
            input.addEventListener('input', (e) => {
                const value = e.target.value;
                
                // Only allow numbers
                if (!/^\d*$/.test(value)) {
                    e.target.value = '';
                    return;
                }
                
                // Add filled class
                if (value) {
                    input.classList.add('filled');
                } else {
                    input.classList.remove('filled');
                }
                
                // Move to next input
                if (value && index < inputs.length - 1) {
                    inputs[index + 1].focus();
                }
                
                updateCodeValue();
            });
            
            input.addEventListener('keydown', (e) => {
                // Handle backspace
                if (e.key === 'Backspace' && !e.target.value && index > 0) {
                    inputs[index - 1].focus();
                    inputs[index - 1].value = '';
                    inputs[index - 1].classList.remove('filled');
                    updateCodeValue();
                }
                
                // Handle arrow keys
                if (e.key === 'ArrowLeft' && index > 0) {
                    inputs[index - 1].focus();
                }
                if (e.key === 'ArrowRight' && index < inputs.length - 1) {
                    inputs[index + 1].focus();
                }
            });
            
            // Handle paste
            input.addEventListener('paste', (e) => {
                e.preventDefault();
                const pastedData = (e.clipboardData || window.clipboardData).getData('text');
                const digits = pastedData.replace(/\D/g, '').slice(0, 6);
                
                digits.split('').forEach((digit, i) => {
                    if (inputs[i]) {
                        inputs[i].value = digit;
                        inputs[i].classList.add('filled');
                    }
                });
                
                updateCodeValue();
                
                // Focus appropriate input
                if (digits.length < 6) {
                    inputs[digits.length].focus();
                } else {
                    inputs[5].focus();
                }
            });
        });
        
        // Focus first input on load
        inputs[0].focus();
        
        // Resend timer
        let timeLeft = 60;
        const timerEl = document.getElementById('timer');
        const resendBtn = document.getElementById('resendBtn');
        
        const countdown = setInterval(() => {
            timeLeft--;
            timerEl.textContent = timeLeft;
            
            if (timeLeft <= 0) {
                clearInterval(countdown);
                resendBtn.disabled = false;
                resendBtn.innerHTML = 'Resend Code';
            }
        }, 1000);
        
        resendBtn.addEventListener('click', () => {
            // Create and submit a form to resend
            const resendForm = document.createElement('form');
            resendForm.method = 'POST';
            resendForm.action = '?step=email';
            
            const emailInput = document.createElement('input');
            emailInput.type = 'hidden';
            emailInput.name = 'email';
            emailInput.value = '<?php echo addslashes($_SESSION['reset_email'] ?? ''); ?>';
            
            resendForm.appendChild(emailInput);
            document.body.appendChild(resendForm);
            resendForm.submit();
        });

        // Form submission loading state
        form.addEventListener('submit', function(e) {
            if (codeValue.value.length === 6) {
                verifyBtn.innerHTML = '<div class="spinner" style="width: 20px; height: 20px; border-width: 2px;"></div>';
                verifyBtn.disabled = true;
            }
        });
        
        <?php elseif ($step === 'reset'): ?>
        const form = document.getElementById('resetForm');
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirm_password');

        if (form) {
            // Password match validation
            confirmPassword.addEventListener('input', function() {
                if (this.value !== password.value) {
                    this.setCustomValidity('Passwords do not match');
                } else {
                    this.setCustomValidity('');
                }
            });

            password.addEventListener('input', function() {
                if (confirmPassword.value && confirmPassword.value !== this.value) {
                    confirmPassword.setCustomValidity('Passwords do not match');
                } else {
                    confirmPassword.setCustomValidity('');
                }
            });

            // Add loading state to button on submit
            form.addEventListener('submit', function(e) {
                if (form.checkValidity()) {
                    const btn = this.querySelector('button[type="submit"]');
                    btn.innerHTML = '<div class="spinner" style="width: 20px; height: 20px; border-width: 2px;"></div>';
                    btn.disabled = true;
                }
            });
        }
        <?php else: ?>
        // Add loading state to button on submit
        document.getElementById('emailForm').addEventListener('submit', function(e) {
            const btn = this.querySelector('button[type="submit"]');
            btn.innerHTML = '<div class="spinner" style="width: 20px; height: 20px; border-width: 2px;"></div>';
            btn.disabled = true;
        });
        <?php endif; ?>
    </script>
</body>
</html>
