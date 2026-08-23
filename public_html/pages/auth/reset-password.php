<?php
/**
 * LOKA - Reset Password Page
 *
 * Allows users to set a new password using a reset token
 */

// Redirect if already logged in
if (isLoggedIn()) {
    redirect('/?page=dashboard');
}

// Get token and email from URL
$token = get('token', '');
$email = Security::getInstance()->sanitizeEmail(get('email', ''));

$errors = [];
$success = false;
$successMessage = '';
$tokenValid = false;
$tokenError = '';

// Validate token on page load
if (empty($token) || empty($email)) {
    $tokenError = 'Invalid reset link. Please request a new password reset.';
} else {
    $auth = new Auth();
    $validation = $auth->validateResetToken($token, $email);
    
    if ($validation['success']) {
        $tokenValid = true;
    } else {
        $tokenError = $validation['error'];
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValid) {
    requireCsrf();

    $password = post('password');
    $confirmPassword = post('confirm_password');

    // Validation
    if (empty($password)) {
        $errors[] = 'Password is required';
    } else {
        // Validate password strength
        $passwordErrors = Security::getInstance()->validatePassword($password);
        if (!empty($passwordErrors)) {
            $errors = array_merge($errors, $passwordErrors);
        }
    }

    if (empty($confirmPassword)) {
        $errors[] = 'Please confirm your password';
    } elseif ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match';
    }

    if (empty($errors)) {
        $auth = new Auth();
        $result = $auth->resetPassword($token, $email, $password);

        if ($result['success']) {
            $success = true;
            $successMessage = $result['message'];
        } else {
            $errors[] = $result['error'];
        }
    }
}

$pageTitle = 'Reset Password';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= ASSETS_PATH ?>/css/style.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body.reset-password-page {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            overflow-x: hidden;
        }

        /* Modern Gradient Background */
        .reset-password-wrapper {
            position: relative;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
            background-size: 200% 200%;
            animation: gradientShift 15s ease infinite;
            padding: 20px;
            overflow: hidden;
        }

        @keyframes gradientShift {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }

        /* Subtle Background Pattern */
        .reset-password-wrapper::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: 
                radial-gradient(circle at 20% 50%, rgba(255, 255, 255, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(255, 255, 255, 0.1) 0%, transparent 50%);
            pointer-events: none;
        }

        /* Glassmorphism Card */
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 20px;
            box-shadow: 
                0 8px 32px rgba(0, 0, 0, 0.1),
                0 0 0 1px rgba(255, 255, 255, 0.5) inset;
            position: relative;
            z-index: 1;
            animation: fadeInUp 0.6s ease-out;
            width: 100%;
            max-width: 420px;
            overflow: hidden;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Logo Container */
        .logo-container {
            text-align: center;
            margin-bottom: 2rem;
        }

        .logo {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.25rem;
            font-size: 2rem;
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
            transition: transform 0.3s ease;
        }

        .logo:hover {
            transform: translateY(-3px) scale(1.05);
        }

        /* Typography */
        .app-name {
            font-size: 1.75rem;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 0.5rem;
            letter-spacing: -0.5px;
        }

        .page-subtitle {
            color: #718096;
            font-size: 0.95rem;
            margin-bottom: 0;
            font-weight: 400;
        }

        .info-text {
            color: #4a5568;
            font-size: 0.9rem;
            line-height: 1.6;
            margin-bottom: 1.5rem;
        }

        /* Form Styling */
        .form-group {
            margin-bottom: 1.5rem;
            position: relative;
        }

        .form-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 0.5rem;
        }

        .form-control {
            width: 100%;
            padding: 0.875rem 1rem 0.875rem 2.75rem;
            font-size: 0.95rem;
            color: #1a202c;
            background: #f7fafc;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            transition: all 0.2s ease;
            outline: none;
        }

        .form-control:focus {
            background: #ffffff;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-control::placeholder {
            color: #a0aec0;
        }

        .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #718096;
            font-size: 1.1rem;
            pointer-events: none;
            transition: color 0.2s ease;
        }

        .form-control:focus ~ .input-icon {
            color: #667eea;
        }

        .password-toggle {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            color: #718096;
            cursor: pointer;
            padding: 0.5rem;
            transition: color 0.2s ease;
            z-index: 2;
        }

        .password-toggle:hover {
            color: #667eea;
        }

        /* Password Strength Indicator */
        .password-strength {
            margin-top: 0.5rem;
            height: 4px;
            background: #e2e8f0;
            border-radius: 2px;
            overflow: hidden;
        }

        .password-strength-bar {
            height: 100%;
            width: 0;
            transition: all 0.3s ease;
            border-radius: 2px;
        }

        .password-strength-bar.weak {
            width: 33%;
            background: #fc8181;
        }

        .password-strength-bar.medium {
            width: 66%;
            background: #f6ad55;
        }

        .password-strength-bar.strong {
            width: 100%;
            background: #68d391;
        }

        .password-strength-text {
            font-size: 0.75rem;
            margin-top: 0.25rem;
            color: #718096;
        }

        /* Password Requirements */
        .password-requirements {
            background: #f7fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            font-size: 0.85rem;
        }

        .password-requirements h6 {
            font-size: 0.8rem;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 0.75rem;
        }

        .password-requirements ul {
            margin: 0;
            padding-left: 1.25rem;
            color: #718096;
        }

        .password-requirements li {
            margin-bottom: 0.25rem;
        }

        .password-requirements li.met {
            color: #48bb78;
        }

        .password-requirements li.met::marker {
            content: '✓ ';
        }

        /* Submit Button */
        .submit-btn {
            width: 100%;
            padding: 0.875rem;
            font-size: 1rem;
            font-weight: 600;
            color: white;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
            position: relative;
            overflow: hidden;
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        .submit-btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        /* Loading Spinner */
        .spinner-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .spinner-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 3px solid #e2e8f0;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Alerts */
        .alert-modern {
            background: #fff5f5;
            border: 1px solid #feb2b2;
            border-radius: 10px;
            color: #c53030;
            padding: 1rem;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
            animation: slideDown 0.3s ease-out;
        }

        .alert-modern.alert-success {
            background: #f0fff4;
            border-color: #9ae6b4;
            color: #22543d;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-danger-modern {
            background: #fff5f5;
            border-color: #feb2b2;
            color: #c53030;
        }

        /* Success State */
        .success-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: 2.5rem;
            color: white;
            box-shadow: 0 4px 15px rgba(72, 187, 120, 0.4);
        }

        .success-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #22543d;
            margin-bottom: 0.75rem;
            text-align: center;
        }

        .success-message {
            color: #4a5568;
            font-size: 0.95rem;
            line-height: 1.6;
            text-align: center;
            margin-bottom: 1.5rem;
        }

        /* Error State */
        .error-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #fc8181 0%, #e53e3e 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: 2.5rem;
            color: white;
            box-shadow: 0 4px 15px rgba(252, 129, 129, 0.4);
        }

        .error-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #c53030;
            margin-bottom: 0.75rem;
            text-align: center;
        }

        .error-message {
            color: #4a5568;
            font-size: 0.95rem;
            line-height: 1.6;
            text-align: center;
            margin-bottom: 1.5rem;
        }

        /* Card Body Padding */
        .card-body {
            padding: 2.5rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .card-body {
                padding: 2rem 1.5rem;
            }

            .app-name {
                font-size: 1.5rem;
            }

            .reset-password-wrapper {
                padding: 15px;
            }
        }

        /* Reduced Motion */
        @media (prefers-reduced-motion: reduce) {
            .reset-password-wrapper,
            .glass-card {
                animation: none;
            }
        }
    </style>
</head>
<body class="reset-password-page">
    <div class="reset-password-wrapper">
        <!-- Reset Password Card -->
        <div class="glass-card">
            <!-- Loading Overlay -->
            <div class="spinner-overlay" id="loadingOverlay">
                <div class="loading-spinner"></div>
            </div>

            <!-- Card Content -->
            <div class="card-body">
                <?php if ($success): ?>
                    <!-- Success State -->
                    <div class="success-icon">
                        <i class="bi bi-check-lg"></i>
                    </div>
                    <h2 class="success-title">Password Reset Complete</h2>
                    <p class="success-message">
                        <?= e($successMessage) ?>
                    </p>
                    <a href="<?= APP_URL ?>/?page=login" class="submit-btn" style="display: inline-block; text-align: center; text-decoration: none;">
                        <i class="bi bi-box-arrow-in-right me-2"></i>Go to Login
                    </a>
                <?php elseif (!empty($tokenError)): ?>
                    <!-- Token Error State -->
                    <div class="error-icon">
                        <i class="bi bi-exclamation-triangle"></i>
                    </div>
                    <h2 class="error-title">Invalid Link</h2>
                    <p class="error-message">
                        <?= e($tokenError) ?>
                    </p>
                    <a href="<?= APP_URL ?>/?page=forgot-password" class="submit-btn" style="display: inline-block; text-align: center; text-decoration: none;">
                        <i class="bi bi-arrow-left me-2"></i>Request New Reset Link
                    </a>
                <?php else: ?>
                    <!-- Logo and Header -->
                    <div class="logo-container">
                        <div class="logo">
                            <i class="bi bi-shield-lock"></i>
                        </div>
                        <h1 class="app-name">Reset Password</h1>
                        <p class="page-subtitle">Create a new secure password</p>
                    </div>

                    <?php if (!empty($errors)): ?>
                    <div class="alert alert-modern alert-danger-modern">
                        <div class="d-flex align-items-center mb-2">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <strong>Error</strong>
                        </div>
                        <ul class="mb-0 ps-4" style="list-style: none; padding-left: 1.5rem;">
                            <?php foreach ($errors as $error): ?>
                            <li style="list-style: disc;"><?= e($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <p class="info-text">
                        Please enter a new password below. Your password must meet the security requirements.
                    </p>

                    <!-- Password Requirements -->
                    <div class="password-requirements">
                        <h6><i class="bi bi-shield-check me-1"></i>Password Requirements</h6>
                        <ul>
                            <li id="req-length">At least 8 characters</li>
                            <li id="req-upper">At least one uppercase letter</li>
                            <li id="req-lower">At least one lowercase letter</li>
                            <li id="req-number">At least one number</li>
                        </ul>
                    </div>

                    <form method="POST" id="resetPasswordForm">
                        <?= csrfField() ?>

                        <!-- New Password -->
                        <div class="form-group">
                            <label for="password" class="form-label">New Password</label>
                            <div style="position: relative;">
                                <i class="bi bi-lock input-icon"></i>
                                <input type="password" 
                                       class="form-control" 
                                       id="password" 
                                       name="password"
                                       placeholder="Enter new password" 
                                       required
                                       autofocus>
                                <button type="button" 
                                        class="password-toggle" 
                                        id="togglePassword" 
                                        aria-label="Toggle password visibility">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <div class="password-strength">
                                <div class="password-strength-bar" id="strengthBar"></div>
                            </div>
                            <div class="password-strength-text" id="strengthText"></div>
                        </div>

                        <!-- Confirm Password -->
                        <div class="form-group">
                            <label for="confirm_password" class="form-label">Confirm Password</label>
                            <div style="position: relative;">
                                <i class="bi bi-lock-fill input-icon"></i>
                                <input type="password" 
                                       class="form-control" 
                                       id="confirm_password" 
                                       name="confirm_password"
                                       placeholder="Confirm your password" 
                                       required>
                                <button type="button" 
                                        class="password-toggle" 
                                        id="toggleConfirmPassword" 
                                        aria-label="Toggle password visibility">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <button type="submit" class="submit-btn" id="submitBtn">
                            <i class="bi bi-check-circle me-2"></i>Reset Password
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function() {
            // Toggle Password Visibility
            const togglePassword = document.getElementById('togglePassword');
            const passwordInput = document.getElementById('password');
            const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
            const confirmPasswordInput = document.getElementById('confirm_password');

            function toggleVisibility(toggleBtn, input) {
                const icon = toggleBtn.querySelector('i');
                if (input.type === 'password') {
                    input.type = 'text';
                    icon.classList.replace('bi-eye', 'bi-eye-slash');
                } else {
                    input.type = 'password';
                    icon.classList.replace('bi-eye-slash', 'bi-eye');
                }
            }

            if (togglePassword) {
                togglePassword.addEventListener('click', function() {
                    toggleVisibility(togglePassword, passwordInput);
                });
            }

            if (toggleConfirmPassword) {
                toggleConfirmPassword.addEventListener('click', function() {
                    toggleVisibility(toggleConfirmPassword, confirmPasswordInput);
                });
            }

            // Password Strength Checker
            const strengthBar = document.getElementById('strengthBar');
            const strengthText = document.getElementById('strengthText');
            const reqLength = document.getElementById('req-length');
            const reqUpper = document.getElementById('req-upper');
            const reqLower = document.getElementById('req-lower');
            const reqNumber = document.getElementById('req-number');

            function checkPasswordStrength(password) {
                let strength = 0;
                
                // Check length
                if (password.length >= 8) {
                    strength++;
                    reqLength?.classList.add('met');
                } else {
                    reqLength?.classList.remove('met');
                }
                
                // Check uppercase
                if (/[A-Z]/.test(password)) {
                    strength++;
                    reqUpper?.classList.add('met');
                } else {
                    reqUpper?.classList.remove('met');
                }
                
                // Check lowercase
                if (/[a-z]/.test(password)) {
                    strength++;
                    reqLower?.classList.add('met');
                } else {
                    reqLower?.classList.remove('met');
                }
                
                // Check number
                if (/[0-9]/.test(password)) {
                    strength++;
                    reqNumber?.classList.add('met');
                } else {
                    reqNumber?.classList.remove('met');
                }
                
                // Update strength bar
                if (strengthBar) {
                    strengthBar.className = 'password-strength-bar';
                    if (password.length === 0) {
                        strengthText.textContent = '';
                    } else if (strength <= 2) {
                        strengthBar.classList.add('weak');
                        strengthText.textContent = 'Weak password';
                        strengthText.style.color = '#fc8181';
                    } else if (strength === 3) {
                        strengthBar.classList.add('medium');
                        strengthText.textContent = 'Medium strength';
                        strengthText.style.color = '#f6ad55';
                    } else {
                        strengthBar.classList.add('strong');
                        strengthText.textContent = 'Strong password';
                        strengthText.style.color = '#68d391';
                    }
                }
                
                return strength;
            }

            if (passwordInput) {
                passwordInput.addEventListener('input', function() {
                    checkPasswordStrength(this.value);
                });
            }

            // Form Submit with Loading State
            const form = document.getElementById('resetPasswordForm');
            const loadingOverlay = document.getElementById('loadingOverlay');
            const submitBtn = document.getElementById('submitBtn');

            if (form) {
                form.addEventListener('submit', function(e) {
                    // Show loading state
                    loadingOverlay.classList.add('active');
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Resetting...';

                    // Allow form to submit normally
                    // The loading state will persist until page redirects
                });
            }

            // Remove loading overlay if page stays (error case)
            window.addEventListener('load', function() {
                if (document.querySelector('.alert-danger-modern')) {
                    loadingOverlay.classList.remove('active');
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>Reset Password';
                    }
                }
            });
        })();
    </script>
</body>
</html>
