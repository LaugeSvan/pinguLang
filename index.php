<?php
include 'config.php';

$output = "";
$search = "";
$is_new = false;
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
    } else {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $data = json_decode($response, true);

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
    $data = json_decode(curl_exec($ch), true);
    $res = $data['choices'][0]['message']['content'] ?? "error";
    return preg_match('/[a-z-]+/i', $res, $m) ? strtolower($m[0]) : "error";
}

// --- 3. HANDLE SEARCH ---
if (isset($_POST['search']) && !empty($_POST['search'])) {
    $search = strtolower(trim($_POST['search']));
    
    if (preg_match('/[0-9]/', $search)) {
        $status_message = "Error: Words cannot contain numbers.";
    } else {
        $api_data = getWordData($search);

        if (!$api_data) {
            $status_message = "Sorry pal, we couldn't find definitions for '$search'.";
        } else {
            $raw_api_word = strtolower($api_data['word']);
            $lookup_word = $raw_api_word;
            
            // 1. Identify Potential Roots
            $potential_roots = [$raw_api_word]; 
            
            if (str_ends_with($raw_api_word, 'ing')) {
                $base = substr($raw_api_word, 0, -3);
                $potential_roots[] = $base;        // "driv"
                $potential_roots[] = $base . "e";   // "drive" (Restores the 'e')
            } elseif (str_ends_with($raw_api_word, 'ed')) {
                $base = substr($raw_api_word, 0, -2);
                $potential_roots[] = $base;        // "walk"
                $potential_roots[] = $base . "e";   // "bake" (if search was baked)
            } elseif (str_ends_with($raw_api_word, 's') && !str_ends_with($raw_api_word, 'ss')) {
                $potential_roots[] = substr($raw_api_word, 0, -1);
            }

            // 2. Determine if it's a verb
            $is_verb = false;
            foreach ($api_data['meanings'] as $meaning) {
                if ($meaning['partOfSpeech'] === 'verb') $is_verb = true;
            }
            if (str_ends_with($search, 'ing')) $is_verb = true;
            $is_plural = str_ends_with($search, 's') && !str_ends_with($search, 'ss') && !str_ends_with($search, 'ing');

            // 3. Database Lookup (Check all potential versions of the root)
            $root_word = "";
            $placeholders = implode(',', array_fill(0, count($potential_roots), '?'));
            $types = str_repeat('s', count($potential_roots));
            
            $stmt = $conn->prepare("SELECT conlang_word, english_word FROM dictionary WHERE english_word IN ($placeholders) ORDER BY LENGTH(english_word) ASC LIMIT 1");
            $stmt->bind_param($types, ...$potential_roots);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($row = $res->fetch_assoc()) {
                $root_word = $row['conlang_word'];
                $lookup_word = $row['english_word']; 
            } else {
                // No match found? Use the most likely root for AI generation
                // If it ends in 'ing', use the 'e' version as the primary guess
                $lookup_word = (str_ends_with($raw_api_word, 'ing')) ? substr($raw_api_word, 0, -3) . "e" : $raw_api_word;
                
                $all_known = $conn->query("SELECT english_word, conlang_word FROM dictionary");
                $compounds_found = [];
                $all_words = [];
                while($rk = $all_known->fetch_assoc()) {
                    if (str_contains($lookup_word, $rk['english_word'])) {
                        $compounds_found[] = $rk['english_word'] . ":" . $rk['conlang_word'];
                    }
                    $all_words[] = $rk['conlang_word'];
                }
                
                $context = implode(", ", $compounds_found);
                $avoid = implode(", ", array_slice($all_words, -10));

                $root_word = callAi($lookup_word, $context, $avoid);

                if ($root_word && $root_word !== "error") {
                    $ins = $conn->prepare("INSERT INTO dictionary (english_word, conlang_word) VALUES (?, ?)");
                    $ins->bind_param("ss", $lookup_word, $root_word);
                    $ins->execute();
                }
            }

            if ($root_word) {
                $output = ($is_verb) ? "ki-" . $root_word : $root_word;
                if ($is_plural) $output .= "-lo";
            }
        }
    }
}

// --- 4. FETCH LISTS ---
$history = $conn->query("SELECT * FROM dictionary ORDER BY id DESC LIMIT 5");
$alphabetical = $conn->query("SELECT * FROM dictionary ORDER BY english_word ASC");

$letters = [];
$dict_data = [];
while($row = $alphabetical->fetch_assoc()) {
    $first_letter = strtoupper($row['english_word'][0]);
    $letters[] = $first_letter;
    $dict_data[] = $row;
}
$unique_letters = array_unique($letters);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>LingoGen | Pingulinian</title>
    <style>
        :root { --primary: #6366f1; --bg: #0f172a; --card: #1e293b; --error: #f87171; --success: #10b981; }
        body { font-family: 'Segoe UI', sans-serif; background: var(--bg); color: white; padding: 2rem; display: flex; flex-direction: column; align-items: center; scroll-behavior: smooth; }
        .container { background: var(--card); padding: 2rem; border-radius: 1rem; width: 100%; max-width: 450px; text-align: center; border: 1px solid #334155; margin-bottom: 2rem; }
        input { width: 75%; padding: 0.8rem; border-radius: 0.5rem; border: 1px solid #334155; background: #0f172a; color: white; margin-bottom: 10px; font-size: 1rem; }
        button { padding: 0.8rem 1.5rem; border-radius: 0.5rem; border: none; background: var(--primary); color: white; cursor: pointer; font-weight: bold; width: 100%; }
        .conlang { font-size: 2.2rem; font-weight: 800; color: #818cf8; display: block; }
        .history-section { width: 100%; max-width: 450px; }
        table { width: 100%; border-collapse: collapse; background: var(--card); border-radius: 0.5rem; overflow: hidden; margin-bottom: 2rem; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #334155; }
        .section-title { color: #94a3b8; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 2px; margin-bottom: 10px; border-left: 3px solid var(--primary); padding-left: 10px; }
        .status-msg { color: var(--error); font-size: 0.9rem; margin: 10px 0; padding: 10px; background: rgba(248, 113, 113, 0.1); border-radius: 5px; }
        .alphabet-bar { display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 1rem; justify-content: center; }
        .letter-link { background: rgba(255,255,255,0.05); color: #94a3b8; padding: 4px 8px; border-radius: 4px; text-decoration: none; font-size: 0.7rem; font-weight: bold; border: 1px solid #334155; }
        .letter-link:hover { background: var(--primary); color: white; }
        .letter-header { background: rgba(99, 102, 241, 0.1); color: var(--primary); padding: 5px 12px; font-weight: bold; border-radius: 4px; margin-top: 10px; display: inline-block; }
        .admin-button { position: fixed; top: 20px; right: 20px; padding: 0.4rem 0.8rem; font-size: 0.7rem; font-weight: 700; background: rgba(255,255,255,0.08); color: #94a3b8; border: 1px solid #334155; border-radius: 999px; text-decoration: none; }
    </style>
</head>
<body>
    <a href="admin.php" class="admin-button">Admin</a>

    <div class="container">
        <h1 style="letter-spacing: 5px; color: #94a3b8;">PINGULINIAN</h1>
        <form method="POST">
            <input type="text" name="search" placeholder="English word..." value="<?= htmlspecialchars($search) ?>" autocomplete="off" autofocus>
            <button type="submit">TRANSLATE</button>
        </form>

        <?php if ($status_message): ?>
            <div class="status-msg"><?= htmlspecialchars($status_message) ?></div>
        <?php endif; ?>

        <?php if ($output): ?>
            <div style="margin-top: 2rem; padding: 1.5rem; background: rgba(99, 102, 241, 0.1); border-radius: 0.5rem; border-left: 4px solid var(--primary);">
                <span class="conlang"><?= htmlspecialchars($output) ?></span>
                <small style="color: #64748b;">(Root: <?= htmlspecialchars($lookup_word) ?>)</small>
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

        <h3 class="section-title">Dictionary (A-Z)</h3>
        <div class="alphabet-bar">
            <?php foreach($unique_letters as $l): ?>
                <a href="#letter-<?= $l ?>" class="letter-link"><?= $l ?></a>
            <?php endforeach; ?>
        </div>

        <table>
            <tbody>
                <?php 
                $current_letter = "";
                foreach($dict_data as $row): 
                    $first = strtoupper($row['english_word'][0]);
                    if ($first !== $current_letter): 
                        $current_letter = $first;
                ?>
                    <tr id="letter-<?= $current_letter ?>">
                        <td colspan="2"><span class="letter-header"><?= $current_letter ?></span></td>
                    </tr>
                <?php endif; ?>
                    <tr>
                        <td style="color: #cbd5e1;"><?= htmlspecialchars($row['english_word']) ?></td>
                        <td style="color: #818cf8; font-weight: bold;"><?= htmlspecialchars($row['conlang_word']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>