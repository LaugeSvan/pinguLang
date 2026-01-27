<?php
include 'config.php';
session_start();

// --- LOGOUT ---
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: admin.php");
    exit;
}

// --- LOGIN ---
$error = "";
if (isset($_POST['login_password'])) {
    if ($_POST['login_password'] === ADMIN_PASSWORD) {
        $_SESSION['is_admin'] = true;
    } else {
        $error = "Incorrect password.";
    }
}

// --- PROTECTED ACTIONS ---
if (isset($_SESSION['is_admin'])) {
    // Delete Word
    if (isset($_GET['delete'])) {
        $id = (int)$_GET['delete'];
        $conn->query("DELETE FROM dictionary WHERE id = $id");
        header("Location: admin.php");
        exit;
    }

    // Update Word (Edit)
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
    </style>
</head>
<body>

<div class="container">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <h1>Dictionary Admin</h1>
        <a href="index.php" style="color: #94a3b8; text-decoration: none;">← Translator</a>
    </div>

    <?php if (!isset($_SESSION['is_admin'])): ?>
        <form method="POST" style="margin-top: 2rem;">
            <input type="password" name="login_password" placeholder="Admin Password" autofocus>
            <button type="submit" class="btn btn-go">Unlock</button>
            <?php if ($error): ?><p style="color: var(--error);"><?= $error ?></p><?php endif; ?>
        </form>
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
                            <td>
                                <input type="text" name="edit_english" value="<?= htmlspecialchars($row['english_word']) ?>">
                            </td>
                            <td>
                                <input type="text" name="edit_conlang" value="<?= htmlspecialchars($row['conlang_word']) ?>">
                            </td>
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