<?php
/**
 * CMU Lost & Found — Matching Engine Diagnostics
 * Shows exactly what the engine sees and scores for every found × lost pair.
 * DELETE THIS FILE after debugging.
 */

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    die("Access denied. Log in as admin first.");
}

require_once dirname(__FILE__) . '/../core/db_config.php';

// ── Pull raw data directly from DB ────────────────────────────────────────

$found_rows = $pdo->query("
    SELECT f.found_id, f.title, f.category_id, f.location_id,
           f.date_found, f.status, f.private_description,
           img.image_path
    FROM   found_reports f
    LEFT JOIN item_images img ON img.report_id = f.found_id AND img.report_type = 'found'
    WHERE  f.status NOT IN ('claimed', 'disposed')
    GROUP  BY f.found_id
    ORDER  BY f.created_at DESC
")->fetchAll();

$lost_rows = $pdo->query("
    SELECT l.lost_id, l.title, l.category_id, l.location_id,
           l.date_lost, l.status, l.private_description,
           u.full_name, u.phone_number
    FROM   lost_reports l
    JOIN   users u ON u.user_id = l.user_id
    WHERE  l.status IN ('open', 'matched')
    ORDER  BY l.created_at DESC
")->fetchAll();

$existing_matches = $pdo->query("
    SELECT match_id, found_id, lost_id, confidence_score, status
    FROM   matches
    ORDER  BY matched_at DESC
")->fetchAll();

// ── Inline scoring functions (mirrors matching_engine.php exactly) ─────────

function diag_parsePrivateDescription(string $text): array
{
    $result   = ['colors' => '', 'traits' => '', 'keywords' => '', 'exact_spot' => ''];
    $segments = array_map('trim', explode('|', $text));
    foreach ($segments as $segment) {
        if (strpos($segment, ':') === false) continue;
        [$label, $value] = array_map('trim', explode(':', $segment, 2));
        $key = strtolower($label);
        if (str_contains($key, 'color'))                                     $result['colors']    = $value;
        elseif (str_contains($key, 'trait'))                                 $result['traits']    = $value;
        elseif (str_contains($key, 'key'))                                   $result['keywords']  = $value;
        elseif (str_contains($key, 'spot') || str_contains($key, 'exact'))   $result['exact_spot']= $value;
    }
    return $result;
}

function diag_buildScoringText(array $row): string
{
    $p = diag_parsePrivateDescription($row['private_description'] ?? '');
    return implode(' ', array_filter([
        $row['title']      ?? '',
        $p['keywords'], $p['keywords'],   // double weight
        $p['traits'],
        $p['colors'],
        $p['exact_spot'],
    ]));
}

function diag_tokenize(string $text): array
{
    static $stopWords = [
        'the','and','for','with','that','this','was','are','have','has',
        'not','but','from','they','been','their','what','when','which',
        'your','found','lost','item','report','left','think','near',
        'inside','some','very','just','there','can','will','would',
        'it','my','a','an','is','in','on','at','to','of','or','its','i',
        'ang','mga','ng','sa','na','ay','ko','mo','ito','ako',
        'siya','niya','namin','natin','nila','kami','kayo','sila',
        'aking','inyong','kanyang','yung','nung','pero','kasi','lang',
        'colors','color','traits','trait','keywords','keyword',
        'exact','spot','description',
    ];
    $clean  = preg_replace('/[|:,\/\\\\]+/', ' ', mb_strtolower($text));
    $clean  = preg_replace('/[^a-z0-9\s\-]/', ' ', $clean);
    $words  = preg_split('/\s+/', trim($clean), -1, PREG_SPLIT_NO_EMPTY);
    $tokens = array_filter($words, fn($w) => strlen($w) >= 3 && !in_array($w, $stopWords, true));
    return array_values(array_unique(array_values($tokens)));
}

function diag_scoreKeywords(string $a, string $b): array
{
    $ta = diag_tokenize($a);
    $tb = diag_tokenize($b);
    if (empty($ta) || empty($tb)) return ['score' => 0, 'tokens_a' => $ta, 'tokens_b' => $tb, 'intersection' => [], 'union_count' => 0];
    $inter = array_intersect($ta, $tb);
    $union = count(array_unique(array_merge($ta, $tb)));
    $score = $union > 0 ? (int)round((count($inter) / $union) * 30) : 0;
    return ['score' => $score, 'tokens_a' => $ta, 'tokens_b' => $tb, 'intersection' => array_values($inter), 'union_count' => $union];
}

function diag_scoreDate(string $df, string $dl): array
{
    if ($df === '' || $dl === '') return ['score' => 0, 'diff_days' => 'N/A'];
    try {
        $diff = abs((new DateTime($df))->diff(new DateTime($dl))->days);
    } catch (Throwable) {
        return ['score' => 0, 'diff_days' => 'parse error'];
    }
    $score = 0;
    if ($diff === 0) $score = 15;
    elseif ($diff <= 3) $score = 10;
    elseif ($diff <= 7) $score = 5;
    return ['score' => $score, 'diff_days' => $diff];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Matching Diagnostics | CMU</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .token { display:inline-block; padding:1px 6px; border-radius:99px; font-size:10px; font-weight:700; margin:1px; }
        .tok-hit  { background:#dcfce7; color:#166534; }
        .tok-miss { background:#f1f5f9; color:#64748b; }
        details > summary { cursor:pointer; }
    </style>
</head>
<body class="bg-slate-100 p-6 font-sans text-slate-800">
<div class="max-w-6xl mx-auto space-y-6">

    <div class="bg-white rounded-2xl p-6 shadow-sm border border-slate-200">
        <h1 class="text-xl font-black text-slate-800">Matching Engine Diagnostics</h1>
        <p class="text-sm text-slate-500 mt-1">Shows exactly what the engine sees and scores. Delete this file after debugging.</p>
    </div>

    <!-- ── Section 1: Raw DB snapshot ──────────────────────────────────── -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-6 py-3 bg-slate-50 border-b border-slate-200">
            <h2 class="font-black text-sm text-slate-600 uppercase tracking-widest">
                1. Raw DB Snapshot
            </h2>
        </div>
        <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-6">

            <!-- Found reports -->
            <div>
                <p class="text-xs font-black text-slate-400 uppercase tracking-widest mb-3">
                    Found Reports (<?php echo count($found_rows); ?> active)
                </p>
                <?php if (empty($found_rows)): ?>
                    <p class="text-sm text-red-500 font-bold">⚠ No active found reports. Nothing for the engine to match.</p>
                <?php else: ?>
                    <?php foreach ($found_rows as $f): ?>
                    <div class="mb-3 p-3 bg-slate-50 rounded-xl border border-slate-100 text-xs">
                        <p class="font-bold text-slate-800">#<?php echo $f['found_id']; ?> — <?php echo htmlspecialchars($f['title']); ?></p>
                        <p class="text-slate-500 mt-0.5">
                            category_id: <strong><?php echo $f['category_id'] ?? '<span class="text-red-500">NULL</span>'; ?></strong> &nbsp;|&nbsp;
                            location_id: <strong><?php echo $f['location_id'] ?? '<span class="text-red-500">NULL</span>'; ?></strong> &nbsp;|&nbsp;
                            date: <strong><?php echo $f['date_found']; ?></strong> &nbsp;|&nbsp;
                            status: <strong><?php echo $f['status']; ?></strong>
                        </p>
                        <p class="text-slate-400 mt-1 font-mono break-all">
                            private_description: "<?php echo htmlspecialchars($f['private_description'] ?? '(empty)'); ?>"
                        </p>
                        <?php $parsed = diag_parsePrivateDescription($f['private_description'] ?? ''); ?>
                        <p class="mt-1">
                            Parsed →
                            keywords: <strong class="text-blue-600">"<?php echo htmlspecialchars($parsed['keywords']); ?>"</strong>
                            | traits: <strong>"<?php echo htmlspecialchars($parsed['traits']); ?>"</strong>
                            | exact_spot: <strong>"<?php echo htmlspecialchars($parsed['exact_spot']); ?>"</strong>
                        </p>
                        <p class="mt-1">
                            Scoring text tokens:
                            <?php foreach (diag_tokenize(diag_buildScoringText($f)) as $tok): ?>
                                <span class="token tok-hit"><?php echo htmlspecialchars($tok); ?></span>
                            <?php endforeach; ?>
                        </p>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Lost reports -->
            <div>
                <p class="text-xs font-black text-slate-400 uppercase tracking-widest mb-3">
                    Open Lost Reports (<?php echo count($lost_rows); ?>)
                </p>
                <?php if (empty($lost_rows)): ?>
                    <p class="text-sm text-red-500 font-bold">⚠ No open lost reports. The engine has nothing to match against.</p>
                <?php else: ?>
                    <?php foreach ($lost_rows as $l): ?>
                    <div class="mb-3 p-3 bg-slate-50 rounded-xl border border-slate-100 text-xs">
                        <p class="font-bold text-slate-800">#<?php echo $l['lost_id']; ?> — <?php echo htmlspecialchars($l['title']); ?></p>
                        <p class="text-slate-500 mt-0.5">
                            category_id: <strong><?php echo $l['category_id'] ?? '<span class="text-red-500">NULL</span>'; ?></strong> &nbsp;|&nbsp;
                            location_id: <strong><?php echo $l['location_id'] ?? '<span class="text-red-500">NULL</span>'; ?></strong> &nbsp;|&nbsp;
                            date: <strong><?php echo $l['date_lost']; ?></strong> &nbsp;|&nbsp;
                            status: <strong><?php echo $l['status']; ?></strong>
                        </p>
                        <p class="text-slate-400 mt-1 font-mono break-all">
                            private_description: "<?php echo htmlspecialchars($l['private_description'] ?? '(empty)'); ?>"
                        </p>
                        <?php $parsed = diag_parsePrivateDescription($l['private_description'] ?? ''); ?>
                        <p class="mt-1">
                            Parsed →
                            keywords: <strong class="text-blue-600">"<?php echo htmlspecialchars($parsed['keywords']); ?>"</strong>
                            | traits: <strong>"<?php echo htmlspecialchars($parsed['traits']); ?>"</strong>
                            | exact_spot: <strong>"<?php echo htmlspecialchars($parsed['exact_spot']); ?>"</strong>
                        </p>
                        <p class="mt-1">
                            Scoring text tokens:
                            <?php foreach (diag_tokenize(diag_buildScoringText($l)) as $tok): ?>
                                <span class="token tok-hit"><?php echo htmlspecialchars($tok); ?></span>
                            <?php endforeach; ?>
                        </p>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Section 2: Existing matches table ────────────────────────────── -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-6 py-3 bg-slate-50 border-b border-slate-200">
            <h2 class="font-black text-sm text-slate-600 uppercase tracking-widest">
                2. Current matches Table (<?php echo count($existing_matches); ?> rows)
            </h2>
        </div>
        <div class="p-6">
            <?php if (empty($existing_matches)): ?>
                <p class="text-sm text-amber-600 font-bold">matches table is empty — backfill has not run or all scores were 0.</p>
            <?php else: ?>
                <table class="w-full text-xs">
                    <thead><tr class="text-slate-400 uppercase font-black border-b border-slate-100">
                        <th class="text-left py-2 pr-4">match_id</th>
                        <th class="text-left py-2 pr-4">found_id</th>
                        <th class="text-left py-2 pr-4">lost_id</th>
                        <th class="text-left py-2 pr-4">confidence</th>
                        <th class="text-left py-2">status</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($existing_matches as $m): ?>
                        <tr class="border-b border-slate-50">
                            <td class="py-2 pr-4 font-mono"><?php echo $m['match_id']; ?></td>
                            <td class="py-2 pr-4"><?php echo $m['found_id']; ?></td>
                            <td class="py-2 pr-4"><?php echo $m['lost_id']; ?></td>
                            <td class="py-2 pr-4 font-black <?php echo $m['confidence_score'] >= 90 ? 'text-green-600' : ($m['confidence_score'] >= 65 ? 'text-amber-600' : 'text-slate-500'); ?>">
                                <?php echo $m['confidence_score']; ?>%
                            </td>
                            <td class="py-2"><?php echo $m['status']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Section 3: Pair-by-pair scoring breakdown ─────────────────────── -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-6 py-3 bg-slate-50 border-b border-slate-200">
            <h2 class="font-black text-sm text-slate-600 uppercase tracking-widest">
                3. Pair-by-Pair Score Breakdown
            </h2>
            <p class="text-xs text-slate-400 mt-0.5">Every found × lost combination. Expand a row to see token detail.</p>
        </div>
        <div class="p-6 space-y-3">

        <?php if (empty($found_rows) || empty($lost_rows)): ?>
            <p class="text-sm text-red-500 font-bold">Cannot score — need at least one found report AND one lost report.</p>
        <?php else: ?>

        <?php foreach ($found_rows as $f): ?>
            <?php foreach ($lost_rows as $l): ?>
            <?php
                // --- Score this pair ---
                $cat_score  = ((int)($f['category_id'] ?? 0) === (int)($l['category_id'] ?? 0) && (int)($f['category_id'] ?? 0) > 0) ? 30 : 0;

                $loc_f = (int)($f['location_id'] ?? 0);
                $loc_l = (int)($l['location_id'] ?? 0);
                $loc_score = 0;
                if ($loc_f > 0 && $loc_f === $loc_l) $loc_score = 20;
                elseif ($loc_f === 1 || $loc_l === 1) $loc_score = 5;

                $kw_result  = diag_scoreKeywords(diag_buildScoringText($f), diag_buildScoringText($l));
                $kw_score   = $kw_result['score'];
                $date_result= diag_scoreDate($f['date_found'] ?? '', $l['date_lost'] ?? '');
                $date_score = $date_result['score'];
                $total      = min(100, $cat_score + $loc_score + $kw_score + $date_score);
                $skipped    = $total <= 0;
                $color      = $total >= 90 ? 'green' : ($total >= 65 ? 'amber' : ($total > 0 ? 'blue' : 'slate'));
            ?>
            <details class="border border-slate-200 rounded-xl overflow-hidden <?php echo $skipped ? 'opacity-40' : ''; ?>">
                <summary class="flex items-center justify-between px-5 py-3 bg-slate-50 hover:bg-slate-100 transition">
                    <div class="flex items-center gap-3">
                        <span class="text-xs font-black px-2 py-0.5 rounded-full bg-<?php echo $color; ?>-100 text-<?php echo $color; ?>-700">
                            <?php echo $total; ?>%
                        </span>
                        <span class="text-sm font-bold text-slate-700">
                            Found #<?php echo $f['found_id']; ?> "<?php echo htmlspecialchars($f['title']); ?>"
                            &nbsp;↔&nbsp;
                            Lost #<?php echo $l['lost_id']; ?> "<?php echo htmlspecialchars($l['title']); ?>"
                        </span>
                    </div>
                    <span class="text-xs text-slate-400">
                        <?php if ($skipped): ?>SCORE=0 — would be skipped<?php else: ?>click to expand<?php endif; ?>
                    </span>
                </summary>

                <div class="px-5 py-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-xs border-t border-slate-100">
                    <!-- Category -->
                    <div class="p-3 rounded-xl <?php echo $cat_score > 0 ? 'bg-green-50 border border-green-100' : 'bg-slate-50 border border-slate-100'; ?>">
                        <p class="font-black text-slate-500 uppercase mb-1">Category</p>
                        <p class="text-lg font-black <?php echo $cat_score > 0 ? 'text-green-600' : 'text-slate-400'; ?>"><?php echo $cat_score; ?>/30</p>
                        <p class="text-slate-400 mt-1">
                            Found: <?php echo $f['category_id'] ?? 'NULL'; ?><br>
                            Lost:  <?php echo $l['category_id'] ?? 'NULL'; ?>
                            <?php if (!$f['category_id'] || !$l['category_id']): ?>
                                <br><span class="text-red-500 font-bold">⚠ NULL category — rows may not have been inserted correctly</span>
                            <?php endif; ?>
                        </p>
                    </div>

                    <!-- Location -->
                    <div class="p-3 rounded-xl <?php echo $loc_score > 0 ? 'bg-green-50 border border-green-100' : 'bg-slate-50 border border-slate-100'; ?>">
                        <p class="font-black text-slate-500 uppercase mb-1">Location</p>
                        <p class="text-lg font-black <?php echo $loc_score > 0 ? 'text-green-600' : 'text-slate-400'; ?>"><?php echo $loc_score; ?>/25</p>
                        <p class="text-slate-400 mt-1">
                            Found: <?php echo $loc_f ?: 'NULL'; ?><br>
                            Lost:  <?php echo $loc_l ?: 'NULL'; ?>
                        </p>
                    </div>

                    <!-- Keywords -->
                    <div class="p-3 rounded-xl <?php echo $kw_score > 0 ? 'bg-green-50 border border-green-100' : 'bg-slate-50 border border-slate-100'; ?>">
                        <p class="font-black text-slate-500 uppercase mb-1">Keywords</p>
                        <p class="text-lg font-black <?php echo $kw_score > 0 ? 'text-green-600' : 'text-slate-400'; ?>"><?php echo $kw_score; ?>/30</p>
                        <p class="text-slate-400 mt-1">
                            Intersect: <?php echo count($kw_result['intersection']); ?> token(s)<br>
                            Union: <?php echo $kw_result['union_count']; ?>
                        </p>
                        <div class="mt-2">
                            <p class="text-slate-400 mb-1">Found tokens:</p>
                            <?php foreach ($kw_result['tokens_a'] as $t): ?>
                                <span class="token <?php echo in_array($t, $kw_result['intersection']) ? 'tok-hit' : 'tok-miss'; ?>">
                                    <?php echo htmlspecialchars($t); ?>
                                </span>
                            <?php endforeach; ?>
                            <p class="text-slate-400 mt-2 mb-1">Lost tokens:</p>
                            <?php foreach ($kw_result['tokens_b'] as $t): ?>
                                <span class="token <?php echo in_array($t, $kw_result['intersection']) ? 'tok-hit' : 'tok-miss'; ?>">
                                    <?php echo htmlspecialchars($t); ?>
                                </span>
                            <?php endforeach; ?>
                            <?php if (!empty($kw_result['intersection'])): ?>
                                <p class="text-green-600 font-bold mt-2">Matched: <?php echo implode(', ', array_map('htmlspecialchars', $kw_result['intersection'])); ?></p>
                            <?php else: ?>
                                <p class="text-red-500 font-bold mt-2">No token overlap</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Date -->
                    <div class="p-3 rounded-xl <?php echo $date_score > 0 ? 'bg-green-50 border border-green-100' : 'bg-slate-50 border border-slate-100'; ?>">
                        <p class="font-black text-slate-500 uppercase mb-1">Date</p>
                        <p class="text-lg font-black <?php echo $date_score > 0 ? 'text-green-600' : 'text-slate-400'; ?>"><?php echo $date_score; ?>/15</p>
                        <p class="text-slate-400 mt-1">
                            Found: <?php echo $f['date_found'] ?? 'NULL'; ?><br>
                            Lost:  <?php echo $l['date_lost']  ?? 'NULL'; ?><br>
                            Diff:  <?php echo $date_result['diff_days']; ?> day(s)
                        </p>
                    </div>
                </div>

                <div class="px-5 py-3 bg-slate-50 border-t border-slate-100 flex items-center gap-3">
                    <span class="text-xs font-black text-slate-500">TOTAL: <?php echo $total; ?>/100</span>
                    <?php if ($total <= 0): ?>
                        <span class="text-xs text-red-500 font-bold">Would be SKIPPED (score = 0) — NOT written to matches table</span>
                    <?php elseif ($total >= 90): ?>
                        <span class="text-xs text-green-600 font-bold">Would be AUTO-CONFIRMED + SMS sent</span>
                    <?php else: ?>
                        <span class="text-xs text-blue-600 font-bold">Would appear in admin review queue</span>
                    <?php endif; ?>
                </div>
            </details>
            <?php endforeach; ?>
        <?php endforeach; ?>
        <?php endif; ?>

        </div>
    </div>

    <div class="bg-slate-800 text-white rounded-2xl p-5 text-sm">
        <p class="font-black uppercase tracking-widest text-xs mb-2">What to look for</p>
        <ul class="text-slate-300 space-y-1 list-disc list-inside">
            <li><strong class="text-white">NULL category_id or location_id</strong> → the INSERT in process_report.php failed to map the value — check your category/location name exactly matches the map array.</li>
            <li><strong class="text-white">No token overlap</strong> → the private_description format doesn't match what the parser expects. Look at the raw "private_description" string above.</li>
            <li><strong class="text-white">Total = 0 on all pairs</strong> → category IDs are all NULL or mismatched, AND keywords produce no overlap.</li>
            <li><strong class="text-white">Score > 0 but matches table still empty</strong> → upsertMatch() is throwing — check your PHP error log.</li>
        </ul>
        <p class="text-red-300 text-xs mt-4 font-bold">⚠ Delete this file from your server after debugging.</p>
    </div>

</div>
</body>
</html>