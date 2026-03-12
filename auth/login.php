<?php
require_once '../config/database.php';
require_once '../config/session.php';

if (isLoggedIn()) {
    header('Location: /pages/dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password';
    } else {
        $stmt = $pdo->prepare("SELECT id, password_hash, full_name FROM staff_users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $sessionToken = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
            $ipAddress = $_SERVER['REMOTE_ADDR'];

            $stmt = $pdo->prepare("
                INSERT INTO staff_sessions (user_id, session_token, ip_address, expires_at) 
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$user['id'], $sessionToken, $ipAddress, $expiresAt]);

            $stmt = $pdo->prepare("UPDATE staff_users SET last_login = NOW() WHERE id = ?");
            $stmt->execute([$user['id']]);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['session_token'] = $sessionToken;
            $_SESSION['user_name'] = $user['full_name'];

            header('Location: /pages/dashboard.php');
            exit;
        } else {
            $error = 'Invalid email or password';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Arawa Hospital</title>
    <link rel="stylesheet" href="/assets/css/login.css">
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <h1 class="logo-text">Arawa Hospital</h1>
            </div>

            <div class="welcome-section">
                <h2>Welcome Back</h2>
                <p>Please enter your credentials to access your account</p>
            </div>

            <?php if ($error): ?>
                <div class="error-message">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" class="login-form">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <div class="input-wrapper">
                        <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="4" width="20" height="16" rx="2"></rect>
                            <path d="m2 7 10 6 10-6"></path>
                        </svg>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            placeholder="Enter your email address"
                            value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                            required
                            autofocus
                        >
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrapper">
                        <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        </svg>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            placeholder="Enter your password"
                            required
                        >
                        <button type="button" class="password-toggle" onclick="togglePassword()">
                            <svg class="eye-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="form-options">
                    <label class="remember-me">
                        <input type="checkbox" name="remember">
                        <span>Remember me</span>
                    </label>
                </div>

                <button type="submit" class="login-button">
                    <span>Log In</span>
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                        <polyline points="12 5 19 12 12 19"></polyline>
                    </svg>
                </button>
            </form>

            <p class="signup-text">
                Don't have an account? 
                <a href="javascript:void(0)" class="signup-link">Contact Administrator</a>
            </p>
        </div>
    </div>

    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const eyeIcon = document.querySelector('.eye-icon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                eyeIcon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
            } else {
                passwordInput.type = 'password';
                eyeIcon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
            }
        }
    </script>
</body>
</html>