<?php
include 'config.php';

$output = "";
$search = "";
$is_new = false;
$status_message = ""; 

// --- 1. HANDLE SEARCH ---
if (isset($_POST['search']) && !empty($_POST['search'])) {
    $search = strtolower(trim($_POST['search']));
    
    if (preg_match('/[0-9]/', $search)) {
        $status_message = "Error: Words cannot contain numbers.";
    }
    else {
        // --- A. Grammar Stemming ---
        $is_verb = false; 
        $is_plural = str_ends_with($search, 's') && !str_ends_with($search, 'ss');

        if (str_starts_with($search, 'to ')) {
            $is_verb = true;
            $lookup_word = substr($search, 3);
        } else {
            $lookup_word = $search;
        }

        if (str_ends_with($lookup_word, 'ing')) {
            $is_verb = true;
            $lookup_word = substr($lookup_word, 0, -3); 
            $last_two = substr($lookup_word, -2);
            if (strlen($lookup_word) > 2 && $last_two[0] === $last_two[1]) {
                $lookup_word = substr($lookup_word, 0, -1);
            }
        }

        if ($is_plural && !$is_verb) {
            $lookup_word = substr($lookup_word, 0, -1);
        }

        // --- B. Database / Smart Compound / AI Lookup ---
        $root_word = "";
        $found_via = ""; 

        $stmt = $conn->prepare("SELECT conlang_word FROM dictionary WHERE english_word = ?");
        $stmt->bind_param("s", $lookup_word);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($row = $res->fetch_assoc()) {
            $root_word = $row['conlang_word'];
        } else {
            $all_known = $conn->query("SELECT english_word, conlang_word FROM dictionary");
            $compounds_found = [];
            while($rk = $all_known->fetch_assoc()) {
                if (str_contains($lookup_word, $rk['english_word'])) {
                    $compounds_found[] = $rk['english_word'] . ":" . $rk['conlang_word'];
                }
            }
            $context = implode(", ", $compounds_found);

            if (empty($compounds_found) && strlen($lookup_word) > 4) {
                $all_known->data_seek(0); 
                while ($row = $all_known->fetch_assoc()) {
                    if (levenshtein($lookup_word, $row['english_word']) === 1) {
                        $root_word = $row['conlang_word'];
                        $status_message = "Typo detected: Using root for '" . $row['english_word'] . "'.";
                        $found_via = "fuzzy";
                        break;
                    }
                }
            }

            if (!$root_word) {
                $recent_avoid = $conn->query("SELECT conlang_word FROM dictionary ORDER BY id DESC LIMIT 10");
                $avoid_list = [];
                while($r = $recent_avoid->fetch_assoc()) { $avoid_list[] = $r['conlang_word']; }

                $root_word = callAi($lookup_word, $context, implode(", ", $avoid_list));
                $found_via = "ai";

                if ($root_word && $root_word !== "error") {
                    if (!empty($compounds_found)) {
                        $formatted_context = str_replace([':', ','], [' (', '),'], $context) . ')';
                        $status_message = "Blended compound using: " . $formatted_context;
                    }
                    
                    $ins = $conn->prepare("INSERT INTO dictionary (english_word, conlang_word) VALUES (?, ?)");
                    $ins->bind_param("ss", $lookup_word, $root_word);
                    $ins->execute();
                    $is_new = true;
                }
            }
        }

        if ($root_word) {
            $output = ($is_verb) ? "ki-" . $root_word : $root_word;
            if ($is_plural) $output .= "-lo";
        }
    }
}

// --- 2. FETCH LISTS ---
$history = $conn->query("SELECT * FROM dictionary ORDER BY id DESC LIMIT 5"); // Just last 5
$alphabetical = $conn->query("SELECT * FROM dictionary ORDER BY english_word ASC");

function callAi($word, $context = "", $avoid = "") {
    $url = "https://api.groq.com/openai/v1/chat/completions";
    $payload = [
        "model" => "llama-3.3-70b-versatile",
        "messages" => [
            ["role" => "system", "content" => "You are the creator of the Pingulinian language. Phonology: m, r, p, k, s, l, f, n, t, a, e, i, o, u. Keep words short. Blend if context exists. Context: [$context]. Avoid: [$avoid]. Output ONLY word."],
            ["role" => "user", "content" => "New word for '$word':"]
        ],
        "temperature" => 0.7
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . GROQ_API_KEY]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $data = json_decode(curl_exec($ch), true);
    $res = $data['choices'][0]['message']['content'] ?? "error";
    return preg_match('/[a-z-]+/i', $res, $m) ? strtolower($m[0]) : "error";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>LingoGen | Pingulinian</title>
    <style>
        :root { --primary: #6366f1; --bg: #0f172a; --card: #1e293b; --error: #f87171; --success: #10b981; }
        body { font-family: 'Segoe UI', sans-serif; background: var(--bg); color: white; padding: 2rem; display: flex; flex-direction: column; align-items: center; }
        .container { background: var(--card); padding: 2rem; border-radius: 1rem; width: 100%; max-width: 450px; text-align: center; border: 1px solid #334155; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5); margin-bottom: 2rem; }
        input { width: 75%; padding: 0.8rem; border-radius: 0.5rem; border: 1px solid #334155; background: #0f172a; color: white; margin-bottom: 10px; font-size: 1rem; }
        button { padding: 0.8rem 1.5rem; border-radius: 0.5rem; border: none; background: var(--primary); color: white; cursor: pointer; font-weight: bold; width: 100%; }
        .result-box { margin-top: 2rem; padding: 1.5rem; background: rgba(99, 102, 241, 0.1); border-radius: 0.5rem; border-left: 4px solid var(--primary); animation: fadeIn 0.4s ease-out; }
        .conlang { font-size: 2.2rem; font-weight: 800; color: #818cf8; display: block; margin-top: 5px; }
        .history-section { width: 100%; max-width: 450px; margin-bottom: 3rem; }
        table { width: 100%; border-collapse: collapse; background: var(--card); border-radius: 0.5rem; overflow: hidden; margin-bottom: 2rem; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #334155; }
        th { background: rgba(255,255,255,0.05); color: #94a3b8; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1px; }
        .section-title { color: #94a3b8; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 2px; margin-bottom: 10px; border-left: 3px solid var(--primary); padding-left: 10px; }
        .admin-button { position: fixed; top: 20px; right: 20px; padding: 0.4rem 0.8rem; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; background: rgba(255,255,255,0.08); color: #94a3b8; border: 1px solid #334155; border-radius: 999px; text-decoration: none; z-index: 1000; }
        .admin-button:hover { background: var(--primary); color: white; border-color: var(--primary); }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>
    <a href="admin.php" class="admin-button">Admin</a>

    <div class="container">
        <h1 style="letter-spacing: 5px; color: #94a3b8; margin-bottom: 20px; font-size: 1.5rem;">PINGULINIAN</h1>
        <form method="POST">
            <input type="text" name="search" placeholder="Enter English word..." value="<?= htmlspecialchars($search) ?>" autocomplete="off" autofocus>
            <button type="submit">TRANSLATE</button>
        </form>

        <?php if ($status_message): ?>
            <p style="color: #94a3b8; font-size: 0.8rem; margin-top: 15px; font-style: italic;">Note: <?= $status_message ?></p>
        <?php endif; ?>

        <?php if ($output): ?>
            <div class="result-box">
                <span style="color: #64748b; font-size: 0.75rem; font-weight: bold; text-transform: uppercase;">
                    Translation <?= $is_new ? '<span class="new-badge" style="font-size: 0.6rem; background: var(--success); color: white; padding: 2px 6px; border-radius: 10px; vertical-align: middle; margin-left: 5px;">NEW ENTRY</span>' : '' ?>
                </span>
                <span class="conlang"><?= htmlspecialchars($output) ?></span>
            </div>
        <?php endif; ?>
    </div>

    <div class="history-section">
        <h3 class="section-title">Recently Translated</h3>
        <table>
            <thead><tr><th>English</th><th>Pingulinian</th></tr></thead>
            <tbody>
                <?php while($row = $history->fetch_assoc()): ?>
                    <tr>
                        <td style="color: #cbd5e1;"><?= htmlspecialchars($row['english_word']) ?></td>
                        <td style="color: #818cf8; font-weight: bold;"><?= htmlspecialchars($row['conlang_word']) ?></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>

        <h3 class="section-title">Full Dictionary (A-Z)</h3>
        <table>
            <thead><tr><th>English</th><th>Pingulinian</th></tr></thead>
            <tbody>
                <?php while($row = $alphabetical->fetch_assoc()): ?>
                    <tr>
                        <td style="color: #cbd5e1;"><?= htmlspecialchars($row['english_word']) ?></td>
                        <td style="color: #818cf8; font-weight: bold;"><?= htmlspecialchars($row['conlang_word']) ?></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</body>
</html>