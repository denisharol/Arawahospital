<?php

require_once '../config/database.php';
require_once '../config/session.php';

requireLogin();

if (!validateSession($pdo)) {
    destroySession($pdo);
    header('Location: /auth/login.php');
    exit;
}

$pageTitle = 'Dashboard - Arawa Hospital';

// Fetch statistics
$totalPatients = 1;
$todayAppointments = 0;
$monthlyRevenue = 0.00;
$activeCases = 0;
$lowStockAlerts = 0;
$pendingBills = 0;
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
    <link rel="stylesheet" href="../assets/css/pages.css">
</head>
<body>

<?php include_once '../templates/sidebar.php'; ?>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title">Admin Dashboard</h1>
        <div class="search-container">
            <svg class="search-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8"></circle>
                <path d="m21 21-4.35-4.35"></path>
            </svg>
            <input type="text" class="search-input" placeholder="Search patients, appointments..." />
        </div>
    </div>

    <!-- Statistics Grid -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon blue">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                    <circle cx="9" cy="7" r="4"></circle>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                </svg>
            </div>
            <div class="stat-content">
                <p class="stat-label">Total Patients</p>
                <h2 class="stat-value"><?php echo $totalPatients; ?></h2>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon green">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                    <line x1="16" y1="2" x2="16" y2="6"></line>
                    <line x1="8" y1="2" x2="8" y2="6"></line>
                    <line x1="3" y1="10" x2="21" y2="10"></line>
                </svg>
            </div>
            <div class="stat-content">
                <p class="stat-label">Today's Appointments</p>
                <h2 class="stat-value"><?php echo $todayAppointments; ?></h2>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon orange">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                    <line x1="12" y1="1" x2="12" y2="23"></line>
                    <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                </svg>
            </div>
            <div class="stat-content">
                <p class="stat-label">Monthly Revenue</p>
                <h2 class="stat-value">KSh <?php echo number_format($monthlyRevenue, 2); ?></h2>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon purple">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                    <path d="M22 12h-4l-3 9L9 3l-3 9H2"></path>
                </svg>
            </div>
            <div class="stat-content">
                <p class="stat-label">Active Cases</p>
                <h2 class="stat-value"><?php echo $activeCases; ?></h2>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon red">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
            </div>
            <div class="stat-content">
                <p class="stat-label">Low Stock Alerts</p>
                <h2 class="stat-value"><?php echo $lowStockAlerts; ?></h2>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon blue">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polyline points="12 6 12 12 16 14"></polyline>
                </svg>
            </div>
            <div class="stat-content">
                <p class="stat-label">Pending Bills</p>
                <h2 class="stat-value"><?php echo $pendingBills; ?></h2>
            </div>
        </div>
    </div>

    <!-- Alerts Section -->
    <div class="alerts-section">
        <div class="section-header">
            <h3 class="section-title">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                </svg>
                Alerts
            </h3>
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
            </svg>
        </div>
        <div class="empty-state">
            No alerts at this time
        </div>
    </div>

    <!-- Dashboard Grid -->
    <div class="dashboard-grid">
        <!-- Recent Patients -->
        <div class="patients-section">
            <div class="section-header">
                <h3 class="section-title">Recent Patients</h3>
                <button class="view-all-btn" onclick="window.location.href='patients.php'">View All</button>
            </div>
            <div class="empty-state">
                No patients found
            </div>
        </div>

        <!-- Right Column -->
        <div style="display: flex; flex-direction: column; gap: 24px;">
            <!-- Recent Admissions -->
            <div class="patients-section">
                <div class="section-header">
                    <h3 class="section-title">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                            <line x1="16" y1="2" x2="16" y2="6"></line>
                            <line x1="8" y1="2" x2="8" y2="6"></line>
                            <line x1="3" y1="10" x2="21" y2="10"></line>
                            <path d="M8 14h.01"></path>
                            <path d="M12 14h.01"></path>
                            <path d="M16 14h.01"></path>
                        </svg>
                        Recent Admissions
                    </h3>
                </div>
                <div class="empty-state">
                    No active admissions
                </div>
            </div>

            <!-- Today's Appointments -->
            <div class="patients-section">
                <div class="section-header">
                    <h3 class="section-title">Today's Appointments</h3>
                    <button class="view-all-btn" onclick="window.location.href='appointments.php'">View All</button>
                </div>
                <div class="empty-state">
                    No appointments today
                </div>
            </div>
        </div>
    </div>
</div>

<script src="../assets/js/theme.js"></script>
<script src="../assets/js/sidebar.js"></script>
<script src="../assets/js/main.js"></script>
</body>
</html>