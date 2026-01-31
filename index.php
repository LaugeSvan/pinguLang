<?php
include 'config.php';

$output = "";
$search = "";
$status_message = ""; 
$is_single_word = false;
$word_details = null;

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

    if ($http_code === 200 && is_array($data)) {
        return $data[0];
    }
    return null;
}

// --- 2. HELPER: AI GENERATION ---
function callAi($word) {
    $url = "https://api.groq.com/openai/v1/chat/completions";
    $payload = [
        "model" => "llama-3.3-70b-versatile",
        "messages" => [
            ["role" => "system", "content" => "You are the creator of the Pingulinian language. Phonology: m, r, p, k, s, l, f, n, t, a, e, i, o, u. Output ONLY the new word in lowercase."],
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

// --- 3. HANDLE TRANSLATION ---
if (isset($_POST['search']) && !empty($_POST['search'])) {
    $search = trim($_POST['search']);
    $raw_words = explode(' ', $search);
    $translated_parts = [];
    $is_single_word = (count($raw_words) === 1);

    foreach ($raw_words as $raw_word) {
        $clean_word = strtolower(preg_replace('/[^\w]/', '', $raw_word));
        $punctuation = preg_replace('/[\w]/', '', $raw_word);

        if (empty($clean_word)) {
            $translated_parts[] = $raw_word;
            continue;
        }

        // Check Database
        $stmt = $conn->prepare("SELECT conlang_word FROM dictionary WHERE english_word = ? OR conlang_word = ? LIMIT 1");
        $stmt->bind_param("ss", $clean_word, $clean_word);
        $stmt->execute();
        $db_res = $stmt->get_result();

        if ($row = $db_res->fetch_assoc()) {
            $translated_parts[] = $row['conlang_word'] . $punctuation;
            if ($is_single_word) $word_details = getWordData($clean_word);
        } else {
            // New Word logic
            $api_data = getWordData($clean_word);
            $lookup_target = ($api_data) ? strtolower($api_data['word']) : $clean_word;
            if ($is_single_word) $word_details = $api_data;

            $new_word = callAi($lookup_target);
            if ($new_word && $new_word !== "error") {
                $ins = $conn->prepare("INSERT INTO dictionary (english_word, conlang_word) VALUES (?, ?)");
                $ins->bind_param("ss", $lookup_target, $new_word);
                $ins->execute();
                $translated_parts[] = $new_word . $punctuation;
            } else {
                $translated_parts[] = $raw_word;
            }
        }
    }
    $output = implode(' ', $translated_parts);
}

// --- 4. FETCH DATA & WORD OF THE DAY ---
$history = $conn->query("SELECT * FROM dictionary ORDER BY id DESC LIMIT 5");
$all_words_query = $conn->query("SELECT * FROM dictionary ORDER BY english_word ASC");
$dict_data = $all_words_query->fetch_all(MYSQLI_ASSOC);

// Word of the Day Logic (Changes every 24 hours)
$wotd = null;
if (!empty($dict_data)) {
    $seed = (int)date('Ymd');
    srand($seed);
    $wotd = $dict_data[array_rand($dict_data)];
    srand(); // reset seed
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>LingoGen | Pingulinian</title>
    <style>
        :root { --primary: #6366f1; --bg: #0f172a; --card: #1e293b; --error: #f87171; --accent: #818cf8; }
        body { font-family: 'Segoe UI', sans-serif; background: var(--bg); color: white; padding: 2rem; display: flex; flex-direction: column; align-items: center; }
        .container { background: var(--card); padding: 2rem; border-radius: 1rem; width: 100%; max-width: 600px; border: 1px solid #334155; margin-bottom: 2rem; }
        textarea { width: 100%; height: 80px; padding: 1rem; border-radius: 0.5rem; border: 1px solid #334155; background: #0f172a; color: white; font-size: 1.1rem; resize: none; box-sizing: border-box; margin-bottom: 1rem; font-family: inherit; }
        button { padding: 1rem; border-radius: 0.5rem; border: none; background: var(--primary); color: white; cursor: pointer; font-weight: bold; width: 100%; font-size: 1rem; }
        .conlang-result { margin-top: 1.5rem; padding: 1.5rem; background: rgba(99, 102, 241, 0.1); border-radius: 0.5rem; border-left: 4px solid var(--primary); }
        .conlang-text { font-size: 1.8rem; font-weight: 700; color: var(--accent); }
        .wotd-card { background: linear-gradient(135deg, #1e293b 0%, #312e81 100%); padding: 1.5rem; border-radius: 1rem; width: 100%; max-width: 600px; margin-bottom: 2rem; border: 1px solid #4338ca; text-align: center; }
        .section-title { color: #94a3b8; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 2px; margin: 20px 0 10px; border-left: 3px solid var(--primary); padding-left: 10px; align-self: flex-start; }
        .history-section { width: 100%; max-width: 600px; }
        table { width: 100%; border-collapse: collapse; background: var(--card); border-radius: 0.5rem; overflow: hidden; }
        td { padding: 12px; border-bottom: 1px solid #334155; }
        .dual-search { display: flex; gap: 10px; margin-bottom: 10px; }
        .dual-search input { flex: 1; padding: 0.6rem; border-radius: 4px; border: 1px solid #334155; background: #0f172a; color: white; }
        .admin-btn { position: fixed; top: 20px; right: 20px; padding: 0.5rem 1rem; background: #1e293b; color: #94a3b8; border: 1px solid #334155; border-radius: 20px; text-decoration: none; font-size: 0.8rem; }
    </style>
</head>
<body>
    <a href="admin.php" class="admin-btn">Admin</a>

    <?php if ($wotd): ?>
    <div class="wotd-card">
        <div style="font-size: 0.7rem; letter-spacing: 2px; color: #c7d2fe; margin-bottom: 5px;">WORD OF THE DAY</div>
        <div style="font-size: 2rem; font-weight: 900; color: white;"><?= htmlspecialchars($wotd['conlang_word']) ?></div>
        <div style="color: #a5b4fc;">means <strong>"<?= htmlspecialchars($wotd['english_word']) ?>"</strong></div>
    </div>
    <?php endif; ?>

    <div class="container">
        <form method="POST">
            <textarea name="search" placeholder="Enter a word or a full sentence..."><?= htmlspecialchars($search) ?></textarea>
            <button type="submit">TRANSLATE</button>
        </form>

        <?php if ($output): ?>
            <div class="conlang-result">
                <div style="color: #64748b; font-size: 0.7rem; margin-bottom: 8px;">RESULT</div>
                <div class="conlang-text"><?= htmlspecialchars($output) ?></div>
                <?php if ($is_single_word && $word_details): ?>
                    <div style="margin-top: 10px; color: #94a3b8; font-size: 0.85rem; border-top: 1px solid rgba(255,255,255,0.1); padding-top: 10px;">
                        <strong>Definition:</strong> <?= htmlspecialchars($word_details['meanings'][0]['definitions'][0]['definition'] ?? 'N/A') ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="history-section">
        <h3 class="section-title">Recent Translations</h3>
        <table>
            <?php while($row = $history->fetch_assoc()): ?>
                <tr><td><?= htmlspecialchars($row['english_word']) ?></td><td style="color: var(--accent); font-weight: bold; text-align: right;"><?= htmlspecialchars($row['conlang_word']) ?></td></tr>
            <?php endwhile; ?>
        </table>

        <h3 class="section-title">Dictionary</h3>
        <div class="dual-search">
            <input type="text" id="engFilter" placeholder="Filter English...">
            <input type="text" id="pinFilter" placeholder="Filter Pingulinian...">
        </div>
        <table id="dictTable">
            <?php foreach($dict_data as $row): ?>
                <tr class="dict-row">
                    <td class="cell-eng"><?= htmlspecialchars($row['english_word']) ?></td>
                    <td class="cell-pin" style="color: var(--accent); font-weight: bold; text-align: right;"><?= htmlspecialchars($row['conlang_word']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <script>
        const engInput = document.getElementById('engFilter');
        const pinInput = document.getElementById('pinFilter');
        const rows = document.querySelectorAll('.dict-row');
        function filter() {
            const engQ = engInput.value.toLowerCase();
            const pinQ = pinInput.value.toLowerCase();
            rows.forEach(r => {
                const eText = r.querySelector('.cell-eng').textContent.toLowerCase();
                const pText = r.querySelector('.cell-pin').textContent.toLowerCase();
                r.style.display = (eText.includes(engQ) && pText.includes(pinQ)) ? "" : "none";
            });
        }
        engInput.addEventListener('input', filter);
        pinInput.addEventListener('input', filter);

        //remove this if you make pinguLang work again
        alert("pinguLang is shut down, and will not come up. To keep using it, go to https://github.com/LaugeSvan/pinguLang")
    </script>
</body>
</html>
