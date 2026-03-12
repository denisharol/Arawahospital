<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['session_token']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: /auth/login.php');
        exit;
    }
}

function validateSession($pdo) {
    if (!isset($_SESSION['session_token'])) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT user_id 
        FROM staff_sessions 
        WHERE session_token = ? AND expires_at > NOW()
    ");
    $stmt->execute([$_SESSION['session_token']]);
    
    return $stmt->fetch() !== false;
}

function destroySession($pdo) {
    if (isset($_SESSION['session_token'])) {
        $stmt = $pdo->prepare("DELETE FROM staff_sessions WHERE session_token = ?");
        $stmt->execute([$_SESSION['session_token']]);
    }
    
    session_unset();
    session_destroy();
}