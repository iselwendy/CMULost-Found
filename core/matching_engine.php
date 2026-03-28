<?php
/**
 * CMU Lost & Found — Matching Engine
 *
 * Scores a found_report against every open lost_report using a
 * weighted, multi-signal algorithm and writes the result to the
 * `matches` table.
 *
 * Scoring weights (total = 100 pts):
 *   Category exact match   30 pts
 *   Location match         25 pts
 *   Keyword overlap        30 pts
 *   Date proximity         15 pts
 *
 * Confidence → action mapping:
 *   ≥ 90  →  auto-notify via SMS, status = 'confirmed'
 *   < 90  →  queued for admin review, status = 'pending'
 */

require_once __DIR__ . '/db_config.php';

// ---------------------------------------------------------------------------
// Public API
// ---------------------------------------------------------------------------

/**
 * Run the full matching pass for a single found report.
 *
 * Called automatically after a found report is saved (process_found.php)
 * and also available for the admin "Re-run matching" button.
 *
 * @param  int  $found_id   ID of the found_report to match against.
 * @param  int  $admin_id   User ID triggering the run (0 = system).
 * @return array            Array of matches that were inserted/updated.
 */
function runMatchingEngine(int $found_id, int $admin_id = 0): array
{
    $pdo     = getDB();
    $matches = [];

    // ── 1. Load the found report ─────────────────────────────────────────
    $found = fetchFoundReport($pdo, $found_id);
    if (!$found) {
        return [];
    }

    // ── 2. Load every open lost report in the same category (or all) ────
    $candidates = fetchOpenLostReports($pdo, $found['category_id']);

    // ── 3. Score each candidate ──────────────────────────────────────────
    foreach ($candidates as $lost) {
        $score   = scoreMatch($found, $lost);
        $signals = buildSignals($found, $lost);

        // Skip if score is 0 (completely unrelated)
        if ($score === 0) {
            continue;
        }

        // ── 4. Upsert into matches table ─────────────────────────────────
        $matchType = $admin_id === 0 ? 'auto' : 'manual';
        $status    = $score >= 90 ? 'confirmed' : 'pending';

        $match_id = upsertMatch(
            $pdo,
            $lost['lost_id'],
            $found_id,
            $admin_id,
            $matchType,
            $status,
            $score,
            buildSignalNote($signals)
        );

        // ── 5. Auto-send SMS when confidence ≥ 90 ────────────────────────
        if ($score >= 90 && $status === 'confirmed') {
            sendMatchSms($pdo, $lost['user_id'], $found, $lost, $score);
        }

        $matches[] = [
            'match_id'   => $match_id,
            'lost_id'    => $lost['lost_id'],
            'found_id'   => $found_id,
            'confidence' => $score,
            'status'     => $status,
            'signals'    => $signals,
        ];
    }

    // Sort descending by confidence for the caller
    usort($matches, fn($a, $b) => $b['confidence'] <=> $a['confidence']);

    return $matches;
}

/**
 * Real-time duplicate check: returns the top-N similar reports already in
 * the system (used while the user is still typing a report form).
 *
 * @param  string $title       Item title typed so far.
 * @param  int    $category_id Selected category (0 = any).
 * @param  string $report_type 'lost' | 'found'
 * @param  int    $limit       Max results to return.
 * @return array
 */
function realtimeDuplicateCheck(
    string $title,
    int    $category_id  = 0,
    string $report_type  = 'lost',
    int    $limit        = 5
): array {
    $pdo = getDB();

    $table  = $report_type === 'found' ? 'found_reports' : 'lost_reports';
    $idCol  = $report_type === 'found' ? 'found_id'      : 'lost_id';
    $status = $report_type === 'found' ? "status NOT IN ('claimed','disposed')"
                                       : "status NOT IN ('resolved','closed')";

    $keywords = tokenize($title);
    if (empty($keywords)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($keywords), '?'));

    $catFilter = $category_id > 0 ? 'AND category_id = ?' : '';
    $params    = $keywords;
    if ($category_id > 0) {
        $params[] = $category_id;
    }

    // Simple LIKE-based search that returns candidates; the caller
    // can refine further with a JS similarity check on the front-end.
    $likeClauses = array_map(
        fn($kw) => "title LIKE " . $pdo->quote('%' . $kw . '%'),
        $keywords
    );
    $likeSQL = implode(' OR ', $likeClauses);

    $sql = "SELECT $idCol AS report_id, title, category_id,
                   created_at
            FROM   $table
            WHERE  ($likeSQL)
              AND  $status
              $catFilter
            ORDER  BY created_at DESC
            LIMIT  $limit";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------------
// Scoring helpers
// ---------------------------------------------------------------------------

/**
 * Primary scoring function — returns 0-100 integer confidence score.
 */
function scoreMatch(array $found, array $lost): int
{
    $score = 0;

    // ── Category match (30 pts) ──────────────────────────────────────────
    if ((int)$found['category_id'] === (int)$lost['category_id']) {
        $score += 30;
    }

    // ── Location match (25 pts) ──────────────────────────────────────────
    $score += scoreLocation($found['location_id'], $lost['location_id']);

    // ── Keyword overlap (30 pts) ─────────────────────────────────────────
    $score += scoreKeywords(
        $found['title'] . ' ' . $found['private_description'],
        $lost['title']  . ' ' . $lost['private_description']
    );

    // ── Date proximity (15 pts) ──────────────────────────────────────────
    $score += scoreDate($found['date_found'], $lost['date_lost']);

    return min(100, $score);
}

/**
 * Location scoring: exact = 25 pts, same building prefix = 12 pts.
 */
function scoreLocation($foundLocId, $lostLocId): int
{
    // Identical location IDs → full credit
    if ((int)$foundLocId === (int)$lostLocId) {
        return 25;
    }
    // "Other" location (id=1) gives partial credit when the other location
    // is specific — student might have vague memory of where they lost it.
    if ((int)$lostLocId === 1 || (int)$foundLocId === 1) {
        return 8;
    }
    return 0;
}

/**
 * Keyword scoring using Jaccard similarity on stemmed token sets.
 * Returns 0–30 points.
 */
function scoreKeywords(string $textA, string $textB): int
{
    $tokensA = tokenize($textA);
    $tokensB = tokenize($textB);

    if (empty($tokensA) || empty($tokensB)) {
        return 0;
    }

    $intersection = count(array_intersect($tokensA, $tokensB));
    $union        = count(array_unique(array_merge($tokensA, $tokensB)));

    $jaccard = $union > 0 ? $intersection / $union : 0;

    return (int)round($jaccard * 30);
}

/**
 * Date proximity scoring: same day = 15, within 3 days = 10,
 * within 7 days = 5, else 0.
 */
function scoreDate(string $dateFound, string $dateLost): int
{
    try {
        $f    = new DateTime($dateFound);
        $l    = new DateTime($dateLost);
        $diff = abs($f->diff($l)->days);
    } catch (Exception) {
        return 0;
    }

    if ($diff === 0) return 15;
    if ($diff <= 3)  return 10;
    if ($diff <= 7)  return 5;
    return 0;
}

/**
 * Tokenise text into a lowercase array of meaningful words (3+ chars),
 * removing Filipino/English stop-words for better signal.
 */
function tokenize(string $text): array
{
    static $stopWords = [
        'the','and','for','with','that','this','was','are','have','has',
        'not','but','from','they','been','their','what','when','which',
        'your','ang','mga','ng','sa','na','ay','ko','mo','ito','ako',
        'siya','niya','namin','natin','nila','kami','kayo','sila',
        'aking','inyong','kanyang','found','lost','item','report','it',
        'my','a','an','is','in','on','at','to','of','or','its','i',
        'left','think','near','inside','some','very','just','there',
    ];

    // Strip punctuation, lower-case, split on whitespace
    $clean  = preg_replace('/[^a-z0-9\s]/i', ' ', mb_strtolower($text));
    $words  = preg_split('/\s+/', trim($clean), -1, PREG_SPLIT_NO_EMPTY);

    // Filter stop-words and short tokens
    $tokens = array_filter(
        $words,
        fn($w) => strlen($w) >= 3 && !in_array($w, $stopWords, true)
    );

    // Primitive suffix stemming: strip common Philippine / English endings
    $stemmed = array_map('stemWord', $tokens);

    return array_values(array_unique($stemmed));
}

/**
 * Naive suffix stemmer (handles -ing, -ed, -s, -er, -tion).
 */
function stemWord(string $word): string
{
    $rules = [
        '/tion$/'  => '',
        '/ing$/'   => '',
        '/ed$/'    => '',
        '/er$/'    => '',
        '/s$/'     => '',
    ];
    foreach ($rules as $pattern => $replace) {
        $candidate = preg_replace($pattern, $replace, $word);
        // Only apply if resulting word is still ≥ 3 chars
        if (strlen($candidate) >= 3 && $candidate !== $word) {
            return $candidate;
        }
    }
    return $word;
}

// ---------------------------------------------------------------------------
// Signal breakdown (for the Matching Portal UI)
// ---------------------------------------------------------------------------

function buildSignals(array $found, array $lost): array
{
    return [
        'Category match'  => (int)$found['category_id'] === (int)$lost['category_id'],
        'Location match'  => (int)$found['location_id'] === (int)$lost['location_id'],
        'Keyword match'   => scoreKeywords(
                                 $found['title'] . ' ' . $found['private_description'],
                                 $lost['title']  . ' ' . $lost['private_description']
                             ) > 0,
        'Photo provided'  => !empty($found['image_path']),
    ];
}

function buildSignalNote(array $signals): string
{
    $hits = array_keys(array_filter($signals));
    return 'Matched on: ' . implode(', ', $hits);
}

// ---------------------------------------------------------------------------
// DB helpers
// ---------------------------------------------------------------------------

function fetchFoundReport(PDO $pdo, int $found_id): ?array
{
    $stmt = $pdo->prepare("
        SELECT f.*, i.image_path
        FROM   found_reports f
        LEFT JOIN (
            SELECT report_id, image_path
            FROM   item_images
            WHERE  report_type = 'found'
            ORDER  BY uploaded_at DESC
            LIMIT  1
        ) i ON i.report_id = f.found_id
        WHERE  f.found_id = ?
        LIMIT  1
    ");
    $stmt->execute([$found_id]);
    return $stmt->fetch() ?: null;
}

function fetchOpenLostReports(PDO $pdo, int $category_id): array
{
    // Fetch exact-category matches first, then open all-category fallback
    $stmt = $pdo->prepare("
        SELECT l.*, u.phone_number, u.full_name
        FROM   lost_reports l
        JOIN   users u ON u.user_id = l.user_id
        WHERE  l.status IN ('open', 'matched')
        ORDER  BY (l.category_id = ?) DESC, l.created_at DESC
    ");
    $stmt->execute([$category_id]);
    return $stmt->fetchAll();
}

function upsertMatch(
    PDO    $pdo,
    int    $lost_id,
    int    $found_id,
    int    $admin_id,
    string $match_type,
    string $status,
    int    $score,
    string $notes
): int {
    // Check for existing match between same lost & found pair
    $check = $pdo->prepare("
        SELECT match_id FROM matches
        WHERE  lost_id = ? AND found_id = ?
        LIMIT  1
    ");
    $check->execute([$lost_id, $found_id]);
    $existing = $check->fetchColumn();

    if ($existing) {
        // Update confidence score and status if the pair already exists
        $upd = $pdo->prepare("
            UPDATE matches
            SET    confidence_score = ?,
                   status           = ?,
                   notes            = ?,
                   matched_at       = CURRENT_TIMESTAMP
            WHERE  match_id = ?
        ");
        $upd->execute([$score, $status, $notes, $existing]);
        return (int)$existing;
    }

    $ins = $pdo->prepare("
        INSERT INTO matches
               (lost_id, found_id, matched_by, match_type, status, confidence_score, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([
        $lost_id,
        $found_id,
        $admin_id ?: null,
        $match_type,
        $status,
        $score,
        $notes,
    ]);
    return (int)$pdo->lastInsertId();
}

// ---------------------------------------------------------------------------
// SMS helper (wraps sms_gateway.php)
// ---------------------------------------------------------------------------

function sendMatchSms(PDO $pdo, int $user_id, array $found, array $lost, int $score): void
{
    // Fetch the phone number if it wasn't loaded with the lost report
    $phone = $lost['phone_number'] ?? null;

    if (!$phone) {
        $stmt = $pdo->prepare("SELECT phone_number FROM users WHERE user_id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $phone = $stmt->fetchColumn();
    }

    if (!$phone) {
        return;
    }

    $ownerName = $lost['full_name'] ?? 'Student';
    $itemTitle = $found['title']    ?? 'your item';

    $message = "Hi {$ownerName}, a potential match ({$score}% confidence) for your "
             . "lost \"{$lost['title']}\" has been found at the Office of Student Affairs. "
             . "Please visit OSA with a valid ID to verify and claim your item. "
             . "- CMU Lost & Found";

    // Delegate to sms_gateway.php
    if (function_exists('sendSms')) {
        sendSms($phone, $message);
    }
}