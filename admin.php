<?php
include 'config.php';
session_start();

// Configuration
$max_attempts = 3;
$lockout_time = 15 * 60; // 15 minutes in seconds
$user_ip = $_SERVER['REMOTE_ADDR'];
$error = "";
$is_locked = false;

// --- CHECK CURRENT LOCKOUT STATUS ---
$stmt = $conn->prepare("SELECT attempts, UNIX_TIMESTAMP(last_attempt) as last_time FROM login_attempts WHERE ip_address = ?");
$stmt->bind_param("s", $user_ip);
$stmt->execute();
$res = $stmt->get_result();
$attempt_data = $res->fetch_assoc();

if ($attempt_data) {
    $time_since_last = time() - $attempt_data['last_time'];
    
    if ($attempt_data['attempts'] >= $max_attempts) {
        if ($time_since_last < $lockout_time) {
            $is_locked = true;
            $wait_minutes = ceil(($lockout_time - $time_since_last) / 60);
            $error = "Too many attempts. Locked out. Try again in $wait_minutes minutes.";
        } else {
            // Lockout expired, reset for a fresh start
            $conn->query("DELETE FROM login_attempts WHERE ip_address = '$user_ip'");
            $is_locked = false;
        }
    }
}

// --- LOGOUT ---
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: admin.php");
    exit;
}

// --- LOGIN PROCESSING ---
if (isset($_POST['login_password']) && !$is_locked) {
    if ($_POST['login_password'] === ADMIN_PASSWORD) {
        // Success: Clear attempts and set session
        $_SESSION['is_admin'] = true;
        $conn->prepare("DELETE FROM login_attempts WHERE ip_address = ?")->execute([$user_ip]);
    } else {
        // Failure: Update or Insert attempt record
        if ($attempt_data) {
            $conn->prepare("UPDATE login_attempts SET attempts = attempts + 1, last_attempt = NOW() WHERE ip_address = ?")->execute([$user_ip]);
            $current_attempts = $attempt_data['attempts'] + 1;
        } else {
            $conn->prepare("INSERT INTO login_attempts (ip_address, attempts) VALUES (?, 1)")->execute([$user_ip]);
            $current_attempts = 1;
        }

        if ($current_attempts >= $max_attempts) {
            $is_locked = true;
            $error = "Incorrect password. You are now locked out for 15 minutes.";
        } else {
            $error = "Incorrect password. " . ($max_attempts - $current_attempts) . " attempts remaining.";
        }
    }
}

// --- PROTECTED ACTIONS ---
if (isset($_SESSION['is_admin'])) {
    if (isset($_GET['delete'])) {
        $id = (int)$_GET['delete'];
        $conn->query("DELETE FROM dictionary WHERE id = $id");
        header("Location: admin.php");
        exit;
    }

    if (isset($_POST['update_id'])) {
        $id = (int)$_POST['update_id'];
        $new_eng = strtolower(trim($_POST['edit_english']));
        $new_con = strtolower(trim($_POST['edit_conlang']));
        
        $upd = $conn->prepare("UPDATE dictionary SET english_word = ?, conlang_word = ? WHERE id = ?");
        $upd->bind_param("ssi", $new_eng, $new_con, $id);
        $upd->execute();
        header("Location: admin.php");
        exit;
    }
}

$all_words = $conn->query("SELECT * FROM dictionary ORDER BY id DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Pingulinian | Admin Panel</title>
    <style>
        :root { --primary: #6366f1; --bg: #0f172a; --card: #1e293b; --error: #f87171; --edit: #f59e0b; }
        body { font-family: 'Segoe UI', sans-serif; background: var(--bg); color: white; padding: 2rem; }
        .container { background: var(--card); padding: 2rem; border-radius: 1rem; width: 100%; max-width: 800px; margin: 0 auto; border: 1px solid #334155; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #334155; }
        input { padding: 0.5rem; border-radius: 0.3rem; border: 1px solid #334155; background: #0f172a; color: white; }
        .btn { padding: 0.5rem 1rem; border-radius: 0.4rem; border: none; cursor: pointer; text-decoration: none; font-weight: bold; font-size: 0.8rem; display: inline-block; }
        .btn-del { background: var(--error); color: white; }
        .btn-edit { background: var(--edit); color: white; }
        .btn-go { background: var(--primary); color: white; }
        .alert { padding: 1rem; background: rgba(248, 113, 113, 0.1); border: 1px solid var(--error); border-radius: 0.5rem; margin-top: 1rem; color: var(--error); }
    </style>
</head>
<body>

<div class="container">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <h1>Dictionary Admin</h1>
        <a href="/" style="color: #94a3b8; text-decoration: none;">← Translator</a>
    </div>

    <?php if (!isset($_SESSION['is_admin'])): ?>
        <?php if ($error): ?>
            <div class="alert"><?= $error ?></div>
        <?php endif; ?>

        <?php if (!$is_locked): ?>
            <form method="POST" style="margin-top: 2rem;">
                <input type="password" name="login_password" placeholder="Admin Password" autofocus>
                <button type="submit" class="btn btn-go">Unlock</button>
            </form>
        <?php endif; ?>

    <?php else: ?>
        <div style="text-align: right; margin-bottom: 1rem;">
            <a href="?logout=1" style="color: var(--error); font-size: 0.8rem;">Logout Session</a>
        </div>

        <table>
            <thead><tr><th>English</th><th>Pingulinian</th><th>Actions</th></tr></thead>
            <tbody>
                <?php while($row = $all_words->fetch_assoc()): ?>
                    <tr>
                        <form method="POST">
                            <td><input type="text" name="edit_english" value="<?= htmlspecialchars($row['english_word']) ?>"></td>
                            <td><input type="text" name="edit_conlang" value="<?= htmlspecialchars($row['conlang_word']) ?>"></td>
                            <td>
                                <input type="hidden" name="update_id" value="<?= $row['id'] ?>">
                                <button type="submit" class="btn btn-edit">Save</button>
                                <a href="?delete=<?= $row['id'] ?>" class="btn btn-del" onclick="return confirm('Really delete?')">✕</a>
                            </td>
                        </form>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

</body>
</html>