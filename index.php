<?php
include 'config.php';

$output = "";
$search = "";
$status_message = ""; 
$lookup_word = "";

// --- 1. HELPER: API LOOKUP ---
function getWordData($word) {
    $clean_word = trim(str_ireplace('to ', '', $word));
    $url = "https://api.dictionaryapi.dev/api/v2/entries/en/" . urlencode($clean_word);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_USERAGENT, 'PingulinianApp/1.0');

    $is_local = ($_SERVER['REMOTE_ADDR'] === '127.0.0.1' || $_SERVER['REMOTE_ADDR'] === '::1');
    if ($is_local) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $data = json_decode($response, true);
    // curl_close removed (Deprecated in PHP 8.5)

    if ($http_code === 200 && is_array($data)) {
        return $data[0];
    }
    return null;
}

// --- 2. HELPER: AI GENERATION ---
function callAi($word, $context = "", $avoid = "") {
    $url = "https://api.groq.com/openai/v1/chat/completions";
    $payload = [
        "model" => "llama-3.3-70b-versatile",
        "messages" => [
            ["role" => "system", "content" => "You are the creator of the Pingulinian language. Phonology: m, r, p, k, s, l, f, n, t, a, e, i, o, u. Blend if context exists: [$context]. Avoid: [$avoid]. Output ONLY word."],
            ["role" => "user", "content" => "New word for '$word':"]
        ],
        "temperature" => 0.7
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . GROQ_API_KEY]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $data = json_decode($response, true);
    
    $res = $data['choices'][0]['message']['content'] ?? "error";
    return preg_match('/[a-z-]+/i', $res, $m) ? strtolower($m[0]) : "error";
}

// --- 3. HANDLE TRANSLATION FORM ---
if (isset($_POST['search']) && !empty($_POST['search'])) {
    $search = strtolower(trim($_POST['search']));
    
    if (preg_match('/[0-9]/', $search)) {
        $status_message = "Error: Words cannot contain numbers.";
    } else {
        // Check if it exists in DB as English OR Pingulinian
        $stmt = $conn->prepare("SELECT conlang_word, english_word FROM dictionary WHERE english_word = ? OR conlang_word = ? LIMIT 1");
        $stmt->bind_param("ss", $search, $search);
        $stmt->execute();
        $db_res = $stmt->get_result();

        if ($row = $db_res->fetch_assoc()) {
            $output = $row['conlang_word'];
            $lookup_word = $row['english_word'];
        } else {
            // Not in DB, try English API
            $api_data = getWordData($search);

            if (!$api_data) {
                $status_message = "Not found in dictionary or English API.";
            } else {
                $raw_api_word = strtolower($api_data['word']);
                $lookup_word = $raw_api_word;
                
                // Root check (ing/ed/s logic)
                $potential_roots = [$raw_api_word]; 
                if (str_ends_with($raw_api_word, 'ing')) {
                    $base = substr($raw_api_word, 0, -3);
                    $potential_roots[] = $base; $potential_roots[] = $base . "e";
                } elseif (str_ends_with($raw_api_word, 'ed')) {
                    $base = substr($raw_api_word, 0, -2);
                    $potential_roots[] = $base; $potential_roots[] = $base . "e";
                }

                $placeholders = implode(',', array_fill(0, count($potential_roots), '?'));
                $types = str_repeat('s', count($potential_roots));
                $stmt = $conn->prepare("SELECT conlang_word, english_word FROM dictionary WHERE english_word IN ($placeholders) ORDER BY LENGTH(english_word) ASC LIMIT 1");
                $stmt->bind_param($types, ...$potential_roots);
                $stmt->execute();
                $root_res = $stmt->get_result();

                if ($root_row = $root_res->fetch_assoc()) {
                    $root_word = $root_row['conlang_word'];
                    $lookup_word = $root_row['english_word']; 
                } else {
                    // AI Generate New Word
                    $root_word = callAi($lookup_word);
                    if ($root_word && $root_word !== "error") {
                        $ins = $conn->prepare("INSERT INTO dictionary (english_word, conlang_word) VALUES (?, ?)");
                        $ins->bind_param("ss", $lookup_word, $root_word);
                        $ins->execute();
                    }
                }

                // Grammar prefixing
                if ($root_word) {
                    $is_verb = false;
                    foreach ($api_data['meanings'] as $m) { if ($m['partOfSpeech'] === 'verb') $is_verb = true; }
                    $output = ($is_verb) ? "ki-" . $root_word : $root_word;
                    if (str_ends_with($search, 's') && !str_ends_with($search, 'ss')) $output .= "-lo";
                }
            }
        }
    }
}

// --- 4. FETCH DATA (After potential inserts) ---
$history = $conn->query("SELECT * FROM dictionary ORDER BY id DESC LIMIT 5");
$alphabetical = $conn->query("SELECT * FROM dictionary ORDER BY english_word ASC");
$dict_data = $alphabetical->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>LingoGen | Pingulinian</title>
    <style>
        :root { --primary: #6366f1; --bg: #0f172a; --card: #1e293b; --error: #f87171; --success: #10b981; }
        body { font-family: 'Segoe UI', sans-serif; background: var(--bg); color: white; padding: 2rem; display: flex; flex-direction: column; align-items: center; }
        .container { background: var(--card); padding: 2rem; border-radius: 1rem; width: 100%; max-width: 450px; text-align: center; border: 1px solid #334155; margin-bottom: 2rem; }
        input { padding: 0.8rem; border-radius: 0.5rem; border: 1px solid #334155; background: #0f172a; color: white; margin-bottom: 10px; font-size: 1rem; }
        .main-search { width: 75%; }
        button { padding: 0.8rem; border-radius: 0.5rem; border: none; background: var(--primary); color: white; cursor: pointer; font-weight: bold; width: 100%; }
        .conlang { font-size: 2.2rem; font-weight: 800; color: #818cf8; display: block; }
        .history-section { width: 100%; max-width: 450px; }
        table { width: 100%; border-collapse: collapse; background: var(--card); border-radius: 0.5rem; overflow: hidden; margin-top: 10px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #334155; }
        .section-title { color: #94a3b8; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 2px; margin: 20px 0 10px 0; border-left: 3px solid var(--primary); padding-left: 10px; }
        .dual-search-bar { display: flex; gap: 10px; margin-bottom: 10px; }
        .dual-search-bar input { width: 50%; margin-bottom: 0; font-size: 0.8rem; }
        .admin-button { position: fixed; top: 20px; right: 20px; padding: 0.4rem 0.8rem; font-size: 0.7rem; background: rgba(255,255,255,0.08); color: #94a3b8; border: 1px solid #334155; border-radius: 999px; text-decoration: none; }
    </style>
</head>
<body>
    <a href="admin.php" class="admin-button">Admin</a>

    <div class="container">
        <h1 style="letter-spacing: 5px; color: #94a3b8;">PINGULINIAN</h1>
        <form method="POST">
            <input type="text" name="search" class="main-search" placeholder="Translate new word..." value="<?= htmlspecialchars($search) ?>" autocomplete="off">
            <button type="submit">TRANSLATE</button>
        </form>

        <?php if ($status_message): ?>
            <div style="color: var(--error); margin-top:10px;"><?= $status_message ?></div>
        <?php endif; ?>

        <?php if ($output): ?>
            <div style="margin-top: 2rem; padding: 1.5rem; background: rgba(99, 102, 241, 0.1); border-radius: 0.5rem; border-left: 4px solid var(--primary);">
                <span class="conlang"><?= htmlspecialchars($output) ?></span>
                <small style="color: #64748b;">English: <?= htmlspecialchars($lookup_word) ?></small>
            </div>
        <?php endif; ?>
    </div>

    <div class="history-section">
        <h3 class="section-title">Recently Translated</h3>
        <table>
            <?php while($row = $history->fetch_assoc()): ?>
                <tr><td><?= htmlspecialchars($row['english_word']) ?></td><td style="color: #818cf8; font-weight: bold;"><?= htmlspecialchars($row['conlang_word']) ?></td></tr>
            <?php endwhile; ?>
        </table>

        <h3 class="section-title">Dictionary Lookup</h3>
        <div class="dual-search-bar">
            <input type="text" id="engInput" placeholder="Filter English...">
            <input type="text" id="pinInput" placeholder="Filter Pingulinian...">
        </div>

        <table id="dictTable">
            <thead>
                <tr style="background: rgba(255,255,255,0.02);">
                    <th>English</th>
                    <th>Pingulinian</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($dict_data as $row): ?>
                    <tr class="dict-row">
                        <td class="cell-eng"><?= htmlspecialchars($row['english_word']) ?></td>
                        <td class="cell-pin" style="color: #818cf8; font-weight: bold;"><?= htmlspecialchars($row['conlang_word']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <script>
        const engInput = document.getElementById('engInput');
        const pinInput = document.getElementById('pinInput');
        const rows = document.querySelectorAll('.dict-row');

        function filterTable() {
            const engVal = engInput.value.toLowerCase();
            const pinVal = pinInput.value.toLowerCase();

            rows.forEach(row => {
                const engText = row.querySelector('.cell-eng').textContent.toLowerCase();
                const pinText = row.querySelector('.cell-pin').textContent.toLowerCase();
                
                if (engText.includes(engVal) && pinText.includes(pinVal)) {
                    row.style.display = "";
                } else {
                    row.style.display = "none";
                }
            });
        }

        engInput.addEventListener('input', filterTable);
        pinInput.addEventListener('input', filterTable);
    </script>
</body>
</html>