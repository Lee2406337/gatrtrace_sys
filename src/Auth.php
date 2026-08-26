<?php
namespace App;

final class Auth
{
    public const MAX_FAILED_ATTEMPTS = 5;
    public const LOCKOUT_MINUTES = 15; // 達 MAX_FAILED_ATTEMPTS 後鎖定的分鐘數，逾時自動解鎖（避免被拿已知工號清單打全員永久鎖定）

    // 固定的假 bcrypt hash，僅用來讓「查無此工號／帳號非在職／帳號已鎖定」這幾個提早
    // return false 的分支也跑一次 password_verify()，補齊跟「帳號存在、未鎖、驗密碼」分支
    // 一致的計算耗時，避免用回應時間差枚舉有效工號／偵測鎖定狀態。刻意寫死常數，不要
    // 每次請求現算 password_hash()（現算本身也慢，會失去補時間差的意義）。
    private const DUMMY_HASH = '$2y$10$Y3Yv1KvpJrcJBHTYFbdAW.iMjim11Es5LWyq/Fw.gLYId6NBebOTG';

    public static function attempt(\PDO $pdo, string $staffId, string $password): bool
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '-';
        $audit = new \App\Repository\ChangeLogRepository($pdo);
        $users = new \App\Repository\UsersRepository($pdo);
        $summary = "工號 {$staffId}（IP {$ip}）";

        $u = $users->findByStaffId($staffId);

        // 稽核 actor 一律存「姓名」以與其他頁面一致；查無使用者時存固定標籤（工號仍在 summary）
        if (!$u || $u['employment_status'] !== '在職') {
            $actor = $u ? (string) $u['name'] : '登入嘗試';
            password_verify($password, self::DUMMY_HASH); // 補時間差；結果丟棄不用
            $audit->insert('登入', '登入失敗', $summary, $actor, null);
            return false;
        }
        $uid = (int) $u['id'];
        $name = (string) $u['name'];

        if ($u['locked_until'] !== null && $u['locked_until'] > date('Y-m-d H:i:s')) {
            password_verify($password, self::DUMMY_HASH); // 補時間差；結果丟棄不用
            $audit->insert('登入', '登入失敗', $summary, $name, null);
            return false;
        }

        if (empty($u['password_hash']) || !password_verify($password, $u['password_hash'])) {
            // 原子遞增＋鎖定判斷：避免並行請求各自讀到同一個舊 failed_attempts、都各自寫回
            // 「+1」，稀釋掉鎖定門檻（實際的原子 UPDATE 在 UsersRepository::recordFailedLogin()）
            $lockedUntil = date('Y-m-d H:i:s', time() + self::LOCKOUT_MINUTES * 60);
            $justLocked = $users->recordFailedLogin($uid, self::MAX_FAILED_ATTEMPTS, $lockedUntil);
            if ($justLocked) {
                $audit->insert('登入', '鎖定', $summary, $name, null);
            }
            $audit->insert('登入', '登入失敗', $summary, $name, null);
            return false;
        }

        // 密碼正確：rehash（如需要）、歸零、regenerate、寫 session、稽核
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            $users->rehashPassword($uid, password_hash($password, PASSWORD_DEFAULT));
        }
        $users->recordSuccessfulLogin($uid);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['uid'] = $uid;
        $_SESSION['uname'] = $u['name'];
        $_SESSION['is_admin'] = ((int) $u['is_admin']) === 1;
        $_SESSION['last_activity'] = time();

        $audit->insert('登入', '登入成功', $summary, $name, $uid);
        return true;
    }

    public static function check(): bool
    {
        return !empty($_SESSION['uid']);
    }

    /**
     * 每次請求都對照 DB 重驗 is_admin／在職狀態：登入當下寫進 session 的權限快照，
     * 後台把某人降權或停用後，若不重驗，對方既有登入在 session 過期前都還持有舊權限。
     * 停用/查無此人：直接登出（後續 requireLogin() 會擋下）。
     * 仍在職：把 is_admin 更新成當下的值（可能被升級或降級）。
     */
    public static function revalidate(\PDO $pdo): void
    {
        if (!self::check()) {
            return;
        }
        $u = (new \App\Repository\UsersRepository($pdo))->authSnapshot((int) $_SESSION['uid']);
        if (!$u || $u['employment_status'] !== '在職') {
            self::logout();
            return;
        }
        $_SESSION['is_admin'] = ((int) $u['is_admin']) === 1;
    }

    /** 是否為系統管理員（users.is_admin=1）——後台（admin/*）存取權限，跟部門主管職位無關 */
    public static function isAdmin(): bool
    {
        return !empty($_SESSION['is_admin']);
    }

    public static function user(): ?array
    {
        return self::check() ? ['id' => $_SESSION['uid'], 'name' => $_SESSION['uname']] : null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        // 一併清除 session cookie，避免僅清空陣列而 cookie 仍在
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: index.php?r=login');
            exit;
        }
    }
}
