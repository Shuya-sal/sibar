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
               h.block, h.number, h.address, h.lane, h.status_huni
        FROM users u
        LEFT JOIN houses h ON h.id = u.house_id
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

/**
 * Role guard:
 * - super_admin: boleh semua
 * - warga: hanya dashboard sendiri
 * - satpam_siang / satpam_malam / sampah: monitoring peta rumah + status iuran terkait
 */
function requireAnyRole(array $roles): array {
    $user = requireAuth();
    if (!in_array($user['role'], $roles, true)) {
        header('Location: dashboard.php');
        exit;
    }
    return $user;
}

// Semua role kecuali warga boleh melihat peta monitoring
function canAccessMonitoring(array $user): bool {
    return in_array($user['role'], ['satpam_siang', 'satpam_malam', 'sampah', 'super_admin'], true);
}

// Fee type code yang dipantau oleh role (null = semua)
function monitoredFeeCode(string $role): ?string {
    return match ($role) {
        'satpam_siang' => 'jaga_siang',
        'satpam_malam' => 'jaga_malam',
        'sampah'       => 'sampah',
        default        => null, // super_admin: semua
    };
}

// Daftar fee type yang boleh ditampilkan untuk role ini (null = semua)
function allowedFeeCodes(string $role): ?array {
    return match ($role) {
        'satpam_siang' => ['jaga_siang'],
        'satpam_malam' => ['jaga_malam'],
        'sampah'       => ['sampah'],
        default        => null,
    };
}
