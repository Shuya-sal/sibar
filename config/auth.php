<?php
// config/auth.php
require_once __DIR__ . '/database.php';

function getCurrentUser(): ?array {
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    static $currentUser = null;
    if ($currentUser !== null) {
        return $currentUser;
    }

    $db = getDB();
    $stmt = $db->prepare("
        SELECT u.id, u.username, u.full_name, u.phone, u.role, u.house_id,
               h.block, h.number, h.address, h.status_huni
        FROM users u
        JOIN houses h ON h.id = u.house_id
        WHERE u.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if ($user) {
        $currentUser = $user;
        return $user;
    }

    // Invalid session
    unset($_SESSION['user_id']);
    return null;
}

function requireAuth(): array {
    $user = getCurrentUser();
    if (!$user) {
        header('Location: login.php');
        exit;
    }
    return $user;
}
