<?php
// index.php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

if (getCurrentUser()) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;
