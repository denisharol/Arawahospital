<?php

require_once '../config/database.php';
require_once '../config/session.php';

destroySession($pdo);

header('Location: /auth/login.php');
exit;