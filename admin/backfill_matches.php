<?php
/**
 * CMU Lost & Found — Matching Engine Backfill
 *
 * Runs runMatchingEngine() against every existing found_report that
 * does not yet have any entry in the matches table.
 *
 * Run this ONCE from your browser or CLI:
 *   http://localhost/CMULandF/admin/backfill_matches.php
 *
 * DELETE THIS FILE after it finishes successfully.
 */

session_start();

// ── Auth guard ────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    die("Access denied. Log in as admin first.");
}

require_once dirname(__FILE__) . '/../core/db_config.php';
require_once dirname(__FILE__) . '/../core/matching_engine.php';

// ── Fetch every found report that has no match entries yet ────────────────
$stmt = $pdo->query("
    SELECT f.found_id, f.title
    FROM   found_reports f
    WHERE  f.status NOT IN ('claimed', 'disposed')
      AND  NOT EXISTS (
               SELECT 1 FROM matches m WHERE m.found_id = f.found_id
           )
    ORDER  BY f.created_at DESC
");
$found_reports = $stmt->fetchAll();

$total   = count($found_reports);
$results = [];

foreach ($found_reports as $row) {
    $found_id = (int) $row['found_id'];

    try {
        $matches = runMatchingEngine($found_id, 0);
        $results[] = [
            'found_id'    => $found_id,
            'title'       => $row['title'],
            'match_count' => count($matches),
            'matches'     => array_map(fn($m) => [
                'lost_id'    => $m['lost_id'],
                'confidence' => $m['confidence'],
                'status'     => $m['status'],
            ], $matches),
            'error'       => null,
        ];
    } catch (Throwable $e) {
        $results[] = [
            'found_id'    => $found_id,
            'title'       => $row['title'],
            'match_count' => 0,
            'matches'     => [],
            'error'       => $e->getMessage(),
        ];
    }
}

// ── Summary counts ────────────────────────────────────────────────────────
$totalMatches  = array_sum(array_column($results, 'match_count'));
$withMatches   = count(array_filter($results, fn($r) => $r['match_count'] > 0));
$withErrors    = count(array_filter($results, fn($r) => $r['error'] !== null));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Backfill Results | CMU Lost & Found</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 p-8 font-sans">
<div class="max-w-4xl mx-auto space-y-6">

    <div class="bg-white rounded-2xl p-6 shadow-sm border border-slate-200">
        <h1 class="text-xl font-black text-slate-800 mb-1">Matching Engine Backfill</h1>
        <p class="text-sm text-slate-500">Ran against all found reports with no existing match entries.</p>
    </div>

    <!-- Summary -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-sm text-center">
            <p class="text-3xl font-black text-slate-800"><?php echo $total; ?></p>
            <p class="text-xs font-bold text-slate-400 uppercase mt-1">Found Reports Scanned</p>
        </div>
        <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-sm text-center">
            <p class="text-3xl font-black text-blue-600"><?php echo $totalMatches; ?></p>
            <p class="text-xs font-bold text-slate-400 uppercase mt-1">Total Matches Written</p>
        </div>
        <div class="bg-green-50 rounded-2xl p-5 border border-green-100 shadow-sm text-center">
            <p class="text-3xl font-black text-green-700"><?php echo $withMatches; ?></p>
            <p class="text-xs font-bold text-green-400 uppercase mt-1">Reports With Matches</p>
        </div>
        <div class="bg-<?php echo $withErrors > 0 ? 'red' : 'slate'; ?>-50 rounded-2xl p-5 border border-<?php echo $withErrors > 0 ? 'red' : 'slate'; ?>-100 shadow-sm text-center">
            <p class="text-3xl font-black text-<?php echo $withErrors > 0 ? 'red-600' : 'slate-400'; ?>"><?php echo $withErrors; ?></p>
            <p class="text-xs font-bold text-<?php echo $withErrors > 0 ? 'red' : 'slate'; ?>-400 uppercase mt-1">Errors</p>
        </div>
    </div>

    <?php if ($total === 0): ?>
        <div class="bg-amber-50 border border-amber-200 rounded-2xl p-6 text-center">
            <p class="text-amber-800 font-bold">No unmatched found reports found.</p>
            <p class="text-amber-600 text-sm mt-1">Either all found reports already have match entries, or the found_reports table is empty.</p>
        </div>
    <?php endif; ?>

    <!-- Per-report breakdown -->
    <?php if (!empty($results)): ?>
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h2 class="text-sm font-black text-slate-600 uppercase tracking-widest">Report Breakdown</h2>
        </div>
        <div class="divide-y divide-slate-50">
            <?php foreach ($results as $r): ?>
            <div class="px-6 py-4">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-sm font-bold text-slate-800">
                            #<?php echo $r['found_id']; ?> — <?php echo htmlspecialchars($r['title']); ?>
                        </p>

                        <?php if ($r['error']): ?>
                            <p class="text-xs text-red-600 mt-1 font-mono bg-red-50 px-2 py-1 rounded">
                                Error: <?php echo htmlspecialchars($r['error']); ?>
                            </p>
                        <?php elseif ($r['match_count'] === 0): ?>
                            <p class="text-xs text-slate-400 mt-1">No matching lost reports found.</p>
                        <?php else: ?>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <?php foreach ($r['matches'] as $m): ?>
                                    <?php
                                        $c = (int)$m['confidence'];
                                        $color = $c >= 90 ? 'green' : ($c >= 65 ? 'amber' : 'slate');
                                    ?>
                                    <span class="text-[11px] font-bold bg-<?php echo $color; ?>-100
                                                 text-<?php echo $color; ?>-700 px-2 py-0.5 rounded-full">
                                        Lost #<?php echo $m['lost_id']; ?> — <?php echo $c; ?>%
                                        <?php echo $m['status'] === 'confirmed' ? '✓ auto-notified' : ''; ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <span class="text-xs font-black px-2 py-1 rounded-full flex-shrink-0
                        <?php echo $r['error'] ? 'bg-red-100 text-red-600' :
                                   ($r['match_count'] > 0 ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-400'); ?>">
                        <?php echo $r['error'] ? 'ERROR' : $r['match_count'] . ' match' . ($r['match_count'] !== 1 ? 'es' : ''); ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Next steps -->
    <div class="bg-slate-800 text-white rounded-2xl p-6 space-y-3">
        <h3 class="font-black text-sm uppercase tracking-widest">Next Steps</h3>
        <ol class="text-sm text-slate-300 space-y-2 list-decimal list-inside">
            <li>Check the summary above — <strong class="text-white">Total Matches Written</strong> should be greater than 0.</li>
            <li>Go to the <a href="matching_portal.php" class="text-yellow-400 underline font-bold">Matching Portal</a> — the queue should now be populated.</li>
            <li><strong class="text-white">Delete this file</strong> from your server once confirmed. It should not remain accessible.</li>
        </ol>
        <?php if ($withErrors > 0): ?>
        <p class="text-red-300 text-xs mt-2">⚠ Some reports had errors. Check your PHP error log for details.</p>
        <?php endif; ?>
    </div>

</div>
</body>
</html>