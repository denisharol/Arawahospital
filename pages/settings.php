<?php
// pages/settings.php
require_once '../config/database.php';
require_once '../config/session.php';

requireLogin();
if (!validateSession($pdo)) {
    destroySession($pdo);
    header('Location: /auth/login.php');
    exit;
}

$pageTitle = 'Settings - Arawa Hospital';
$userId = $_SESSION['user_id'];

// Fetch user profile
$stmt = $pdo->prepare("SELECT * FROM staff_users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $fullName = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    
    try {
        $stmt = $pdo->prepare("UPDATE staff_users SET full_name = ?, email = ? WHERE id = ?");
        $stmt->execute([$fullName, $email, $userId]);
        $_SESSION['success_message'] = 'Profile updated successfully';
        $_SESSION['user_name'] = $fullName;
        header('Location: settings.php');
        exit;
    } catch (PDOException $e) {
        $error = 'Failed to update profile';
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $currentPassword = $_POST['current_password'];
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];
    
    // Verify current password (plain text comparison)
    if ($currentPassword !== $user['password_hash']) {
        $_SESSION['error_message'] = 'Current password is incorrect';
        header('Location: settings.php');
        exit;
    }
    
    // Validate new password
    if (strlen($newPassword) < 6) {
        $_SESSION['error_message'] = 'New password must be at least 6 characters';
        header('Location: settings.php');
        exit;
    }
    
    if ($newPassword !== $confirmPassword) {
        $_SESSION['error_message'] = 'Passwords do not match';
        header('Location: settings.php');
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("UPDATE staff_users SET password_hash = ? WHERE id = ?");
        $stmt->execute([$newPassword, $userId]);
        $_SESSION['success_message'] = 'Password changed successfully';
        header('Location: settings.php');
        exit;
    } catch (PDOException $e) {
        $_SESSION['error_message'] = 'Failed to change password';
        header('Location: settings.php');
        exit;
    }
}

$successMessage = $_SESSION['success_message'] ?? '';
$errorMessage = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/sidebar.css">
    <link rel="stylesheet" href="../assets/css/settings.css">
</head>
<body>

<?php include_once '../templates/sidebar.php'; ?>

<div class="main-content">
    <!-- Header -->
    <div class="settings-header">
        <h1>Settings</h1>
        <p>Manage your profile, security, and system preferences.</p>
    </div>

    <?php if ($successMessage): ?>
    <div class="patients-alert-success" style="margin-bottom: 24px;">
        <?php echo htmlspecialchars($successMessage); ?>
    </div>
    <?php endif; ?>

    <?php if ($errorMessage): ?>
    <div style="margin-bottom: 24px; padding: 12px 16px; background: #fee2e2; color: #991b1b; border-radius: 8px; border: 1px solid #fecaca;">
        <?php echo htmlspecialchars($errorMessage); ?>
    </div>
    <?php endif; ?>

    <div class="settings-container">
        <!-- Profile Information -->
        <div class="settings-section">
            <div class="settings-section-header" onclick="toggleSection('profile')">
                <div class="settings-section-left">
                    <div class="settings-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0ea5e9" stroke-width="2">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                        </svg>
                    </div>
                    <div class="settings-section-info">
                        <h3>Profile Information</h3>
                        <p>Manage your admin profile details</p>
                    </div>
                </div>
                <svg class="settings-chevron" id="chevron-profile" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="6 9 12 15 18 9"></polyline>
                </svg>
            </div>
            <div class="settings-section-content" id="content-profile">
                <form method="POST">
                    <div class="settings-form-group">
                        <label class="settings-form-label">Full Name</label>
                        <input type="text" name="full_name" class="settings-form-input" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                    </div>
                    
                    <div class="settings-form-group">
                        <label class="settings-form-label">Email Address</label>
                        <input type="email" name="email" class="settings-form-input" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                    </div>
                    
                    <div class="settings-form-group">
                        <label class="settings-form-label">Role</label>
                        <input type="text" class="settings-form-input" value="<?php echo ucfirst($user['role']); ?>" disabled>
                    </div>
                    
                    <button type="submit" name="update_profile" class="settings-btn-primary">
                        Save Changes
                    </button>
                </form>
            </div>
        </div>

        <!-- Security Settings -->
        <div class="settings-section">
            <div class="settings-section-header" onclick="toggleSection('security')">
                <div class="settings-section-left">
                    <div class="settings-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0ea5e9" stroke-width="2">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        </svg>
                    </div>
                    <div class="settings-section-info">
                        <h3>Security Settings</h3>
                        <p>Update password and security preferences</p>
                    </div>
                </div>
                <svg class="settings-chevron" id="chevron-security" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="6 9 12 15 18 9"></polyline>
                </svg>
            </div>
            <div class="settings-section-content" id="content-security">
                <form method="POST">
                    <div class="settings-form-group">
                        <label class="settings-form-label">Current Password</label>
                        <div class="settings-password-wrapper">
                            <input type="password" name="current_password" id="currentPassword" class="settings-form-input" required>
                            <button type="button" class="settings-password-toggle" onclick="togglePassword('currentPassword', 'currentIcon')">
                                <svg id="currentIcon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </button>
                        </div>
                    </div>
                    
                    <div class="settings-form-group">
                        <label class="settings-form-label">New Password</label>
                        <div class="settings-password-wrapper">
                            <input type="password" name="new_password" id="newPassword" class="settings-form-input" required>
                            <button type="button" class="settings-password-toggle" onclick="togglePassword('newPassword', 'newIcon')">
                                <svg id="newIcon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </button>
                        </div>
                    </div>
                    
                    <div class="settings-form-group">
                        <label class="settings-form-label">Confirm New Password</label>
                        <div class="settings-password-wrapper">
                            <input type="password" name="confirm_password" id="confirmPassword" class="settings-form-input" required>
                            <button type="button" class="settings-password-toggle" onclick="togglePassword('confirmPassword', 'confirmIcon')">
                                <svg id="confirmIcon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </button>
                        </div>
                    </div>
                    
                    <button type="submit" name="change_password" class="settings-btn-primary">
                        Change Password
                    </button>
                </form>
            </div>
        </div>

        <!-- Appearance -->
        <div class="settings-section">
            <div class="settings-section-header" onclick="toggleSection('appearance')">
                <div class="settings-section-left">
                    <div class="settings-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0ea5e9" stroke-width="2">
                            <circle cx="13.5" cy="6.5" r=".5"></circle>
                            <circle cx="17.5" cy="10.5" r=".5"></circle>
                            <circle cx="8.5" cy="7.5" r=".5"></circle>
                            <circle cx="6.5" cy="12.5" r=".5"></circle>
                            <path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"></path>
                        </svg>
                    </div>
                    <div class="settings-section-info">
                        <h3>Appearance</h3>
                        <p>Customize the look and feel</p>
                    </div>
                </div>
                <svg class="settings-chevron" id="chevron-appearance" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="6 9 12 15 18 9"></polyline>
                </svg>
            </div>
            <div class="settings-section-content" id="content-appearance">
                <div class="settings-form-group">
                    <label class="settings-form-label">Theme Mode</label>
                    <div class="settings-theme-options">
                        <div class="settings-theme-option" id="theme-light" onclick="setThemeMode('light')">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="5"></circle>
                                <line x1="12" y1="1" x2="12" y2="3"></line>
                                <line x1="12" y1="21" x2="12" y2="23"></line>
                                <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
                                <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
                                <line x1="1" y1="12" x2="3" y2="12"></line>
                                <line x1="21" y1="12" x2="23" y2="12"></line>
                                <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
                                <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
                            </svg>
                            <span>Light</span>
                        </div>
                        <div class="settings-theme-option" id="theme-dark" onclick="setThemeMode('dark')">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                            </svg>
                            <span>Dark</span>
                        </div>
                        <div class="settings-theme-option" id="theme-system" onclick="setThemeMode('system')">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                                <line x1="8" y1="21" x2="16" y2="21"></line>
                                <line x1="12" y1="17" x2="12" y2="21"></line>
                            </svg>
                            <span>System</span>
                        </div>
                    </div>
                </div>
                <div class="settings-info-box">
                    Your theme preference is automatically saved and will persist across sessions.
                </div>
            </div>
        </div>

        <!-- Data Management -->
        <div class="settings-section">
            <div class="settings-section-header" onclick="toggleSection('data')">
                <div class="settings-section-left">
                    <div class="settings-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0ea5e9" stroke-width="2">
                            <ellipse cx="12" cy="5" rx="9" ry="3"></ellipse>
                            <path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"></path>
                            <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"></path>
                        </svg>
                    </div>
                    <div class="settings-section-info">
                        <h3>Data Management</h3>
                        <p>System data and backup options</p>
                    </div>
                </div>
                <svg class="settings-chevron" id="chevron-data" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="6 9 12 15 18 9"></polyline>
                </svg>
            </div>
            <div class="settings-section-content" id="content-data">
                <div class="settings-form-group">
                    <label class="settings-form-label">Database Information</label>
                    <div style="padding: 12px; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 8px; margin-top: 8px;">
                        <p style="color: var(--text-primary); font-size: 14px; margin: 0 0 8px 0; font-weight: 500;">
                            Database: arawadb
                        </p>
                        <p style="color: var(--text-secondary); font-size: 13px; margin: 0;">
                            Server: localhost<br>
                            Type: MySQL<br>
                            Character Set: utf8mb4
                        </p>
                    </div>
                </div>

                <div class="settings-form-group">
                    <label class="settings-form-label">Cache Management</label>
                    <button type="button" class="settings-btn-secondary" style="width: 100%;" onclick="clearCache()">
                        <svg style="display: inline; margin-right: 8px;" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"></path>
                        </svg>
                        Clear Browser Cache
                    </button>
                    <p style="color: var(--text-secondary); font-size: 12px; margin: 8px 0 0 0;">
                        Clear temporary files and cached data to improve performance
                    </p>
                </div>

                <div class="settings-warning-box">
                    ⚠️ <strong>Database Backup:</strong> Regular backups are essential. Use phpMyAdmin or MySQL command line tools to export the 'arawadb' database.
                </div>

                <div class="settings-form-group">
                    <label class="settings-form-label">Export Data (CSV)</label>
                    <div class="settings-actions-grid">
                        <button type="button" class="settings-btn-secondary" onclick="exportData('patients')">
                            Export Patients
                        </button>
                        <button type="button" class="settings-btn-secondary" onclick="exportData('admissions')">
                            Export Admissions
                        </button>
                        <button type="button" class="settings-btn-secondary" onclick="exportData('inventory')">
                            Export Inventory
                        </button>
                        <button type="button" class="settings-btn-secondary" onclick="exportData('departments')">
                            Export Departments
                        </button>
                    </div>
                    <p style="color: var(--text-secondary); font-size: 12px; margin: 8px 0 0 0;">
                        Download data as CSV files for backup or analysis
                    </p>
                </div>

                <div class="settings-danger-box">
                    🔒 <strong>Security Notice:</strong> Exported data contains sensitive patient information. Handle with care and store securely.
                </div>
            </div>
        </div>
    </div>
</div>

<script src="../assets/js/theme.js"></script>
<script src="../assets/js/sidebar.js"></script>
<script>
function toggleSection(sectionId) {
    const content = document.getElementById('content-' + sectionId);
    const chevron = document.getElementById('chevron-' + sectionId);
    
    // Close all other sections
    document.querySelectorAll('.settings-section-content').forEach(el => {
        if (el.id !== 'content-' + sectionId) {
            el.classList.remove('expanded');
        }
    });
    
    document.querySelectorAll('.settings-chevron').forEach(el => {
        if (el.id !== 'chevron-' + sectionId) {
            el.classList.remove('expanded');
        }
    });
    
    // Toggle current section
    content.classList.toggle('expanded');
    chevron.classList.toggle('expanded');
}

function togglePassword(inputId, iconId) {
    const input = document.getElementById(inputId);
    const icon = document.getElementById(iconId);
    
    if (input.type === 'password') {
        input.type = 'text';
        icon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
    } else {
        input.type = 'password';
        icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
    }
}

// Theme Management
function setThemeMode(mode) {
    // Update localStorage
    localStorage.setItem('theme', mode);
    
    // Update UI
    document.querySelectorAll('.settings-theme-option').forEach(el => {
        el.classList.remove('active');
    });
    document.getElementById('theme-' + mode).classList.add('active');
    
    // Apply theme
    if (mode === 'system') {
        const systemTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        document.documentElement.setAttribute('data-theme', systemTheme);
    } else {
        document.documentElement.setAttribute('data-theme', mode);
    }
    
    // Show success message
    showNotification('Theme updated to ' + mode + ' mode');
}

// Initialize theme on load
function initializeTheme() {
    const savedTheme = localStorage.getItem('theme') || 'system';
    document.getElementById('theme-' + savedTheme).classList.add('active');
}

// Clear cache function
function clearCache() {
    if (confirm('This will clear browser cache and session data. Continue?')) {
        // Clear session storage
        sessionStorage.clear();
        
        // Clear browser cache if supported
        if ('caches' in window) {
            caches.keys().then(function(names) {
                for (let name of names) {
                    caches.delete(name);
                }
            });
        }
        
        showNotification('Cache cleared successfully!');
    }
}

// Export data function
function exportData(dataType) {
    // Create a form to submit the export request
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '../export/export.php';
    form.style.display = 'none';
    
    const typeInput = document.createElement('input');
    typeInput.type = 'hidden';
    typeInput.name = 'export_type';
    typeInput.value = dataType;
    
    form.appendChild(typeInput);
    document.body.appendChild(form);
    form.submit();
    
    showNotification('Exporting ' + dataType + ' data...');
    
    // Remove form after submission
    setTimeout(() => {
        document.body.removeChild(form);
    }, 100);
}

// Show notification
function showNotification(message) {
    // Create notification element
    const notification = document.createElement('div');
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: #0ea5e9;
        color: white;
        padding: 12px 20px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        z-index: 1000;
        animation: slideIn 0.3s ease-out;
    `;
    notification.textContent = message;
    
    // Add animation
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideIn {
            from {
                transform: translateX(400px);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
    `;
    document.head.appendChild(style);
    
    document.body.appendChild(notification);
    
    // Remove after 3 seconds
    setTimeout(() => {
        notification.style.animation = 'slideIn 0.3s ease-out reverse';
        setTimeout(() => {
            document.body.removeChild(notification);
        }, 300);
    }, 3000);
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', initializeTheme);
</script>
</body>
</html>