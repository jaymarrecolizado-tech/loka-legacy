<?php
/**
 * LOKA - Forgot Password Page
 *
 * Allows users to request a password reset link
 */

// Redirect if already logged in
if (isLoggedIn()) {
    redirect('/?page=dashboard');
}

$errors = [];
$success = false;
$successMessage = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $email = Security::getInstance()->sanitizeEmail(post('email'));

    // Validation
    if (empty($email)) {
        $errors[] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format';
    }

    if (empty($errors)) {
        $auth = new Auth();
        $result = $auth->requestPasswordReset($email);

        if ($result['success']) {
            $success = true;
            $successMessage = $result['message'];
        } else {
            $errors[] = $result['error'];
        }
    }
}

$pageTitle = 'Forgot Password';
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

        body.forgot-password-page {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            overflow-x: hidden;
        }

        /* Modern Gradient Background */
        .forgot-password-wrapper {
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
        .forgot-password-wrapper::before {
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

        /* Back Link */
        .back-link {
            display: inline-flex;
            align-items: center;
            color: #667eea;
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            transition: color 0.2s ease;
            margin-top: 1rem;
        }

        .back-link:hover {
            color: #764ba2;
            text-decoration: underline;
        }

        .back-link i {
            margin-right: 0.5rem;
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

            .forgot-password-wrapper {
                padding: 15px;
            }
        }

        /* Reduced Motion */
        @media (prefers-reduced-motion: reduce) {
            .forgot-password-wrapper,
            .glass-card {
                animation: none;
            }
        }
    </style>
</head>
<body class="forgot-password-page">
    <div class="forgot-password-wrapper">
        <!-- Forgot Password Card -->
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
                        <i class="bi bi-envelope-check"></i>
                    </div>
                    <h2 class="success-title">Check Your Email</h2>
                    <p class="success-message">
                        <?= e($successMessage) ?>
                    </p>
                    <a href="<?= APP_URL ?>/?page=login" class="submit-btn" style="display: inline-block; text-align: center; text-decoration: none;">
                        <i class="bi bi-arrow-left me-2"></i>Back to Login
                    </a>
                <?php else: ?>
                    <!-- Logo and Header -->
                    <div class="logo-container">
                        <div class="logo">
                            <i class="bi bi-key"></i>
                        </div>
                        <h1 class="app-name">Forgot Password?</h1>
                        <p class="page-subtitle">Reset your password securely</p>
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
                        Enter your email address below and we'll send you a link to reset your password. 
                        The link will expire in 1 hour for security.
                    </p>

                    <form method="POST" id="forgotPasswordForm">
                        <?= csrfField() ?>

                        <!-- Email -->
                        <div class="form-group">
                            <label for="email" class="form-label">Email Address</label>
                            <div style="position: relative;">
                                <i class="bi bi-envelope input-icon"></i>
                                <input type="email" 
                                       class="form-control" 
                                       id="email" 
                                       name="email"
                                       value="<?= e(post('email', '')) ?>"
                                       placeholder="Enter your email"
                                       required 
                                       autofocus>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <button type="submit" class="submit-btn" id="submitBtn">
                            <i class="bi bi-send me-2"></i>Send Reset Link
                        </button>
                    </form>

                    <!-- Back to Login -->
                    <div style="text-align: center;">
                        <a href="<?= APP_URL ?>/?page=login" class="back-link">
                            <i class="bi bi-arrow-left"></i>Back to Login
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function() {
            // Form Submit with Loading State
            const form = document.getElementById('forgotPasswordForm');
            const loadingOverlay = document.getElementById('loadingOverlay');
            const submitBtn = document.getElementById('submitBtn');

            if (form) {
                form.addEventListener('submit', function(e) {
                    // Show loading state
                    loadingOverlay.classList.add('active');
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Sending...';

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
                        submitBtn.innerHTML = '<i class="bi bi-send me-2"></i>Send Reset Link';
                    }
                }
            });
        })();
    </script>
</body>
</html>
