<?php

require_once 'config/database.php';
require_once 'config/session.php';

if (isLoggedIn() && validateSession($pdo)) {
    header('Location: /pages/dashboard.php');
} else {
    header('Location: /auth/login.php');
}
exit;