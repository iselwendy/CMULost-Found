<?php
/**
 * CMU Lost & Found — Matching Engine
 *
 * Scoring weights (total = 100 pts):
 *   Category exact match   30 pts
 *   Location match         25 pts   (DB location_id + Exact Spot text field)
 *   Keyword overlap        30 pts   (title + Keywords: field + Traits: field)
 *   Date proximity         15 pts
 *
 * Confidence → action:
 *   ≥ 90  →  auto-notify via SMS, status = 'confirmed'
 *   < 90  →  queued for admin review, status = 'pending'
 *
 * private_description format:
 *   "Colors: Red | Traits: faded, oversized | Keywords: jacket, paint | Exact Spot: Main library, table 3"
 */

require_once __DIR__ . '/db_config.php';

// ---------------------------------------------------------------------------
// Public API
// ---------------------------------------------------------------------------

function runMatchingEngine(int $found_id, int $admin_id = 1): array
{
    $pdo     = getDB();
    $matches = [];

    $found = fetchFoundReport($pdo, $found_id);
    if (!$found) {
        error_log("[MatchingEngine] found_id=$found_id not found in DB.");
        return [];
    }

    $candidates = fetchOpenLostReports($pdo);

    foreach ($candidates as $lost) {
        $score   = scoreMatch($found, $lost);
        $signals = buildSignals($found, $lost);

        if ($score <= 0) {
            continue;
        }

        $matchType = ($admin_id === 1) ? 'auto' : 'manual';
        $status    = ($score >= 90)    ? 'confirmed' : 'pending';

        $match_id = upsertMatch(
            $pdo,
            (int)$lost['lost_id'],
            $found_id,
            $admin_id,
            $matchType,
            $status,
            $score,
            buildSignalNote($signals)
        );

        if ($match_id === 0) {
            continue;
        }

        if ($score >= 90) {
            sendMatchSms($pdo, (int)$lost['user_id'], $found, $lost, $score);
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

    usort($matches, fn($a, $b) => $b['confidence'] <=> $a['confidence']);

    return $matches;
}

function realtimeDuplicateCheck(
    string $title,
    int    $category_id = 0,
    string $report_type = 'lost',
    int    $limit       = 5
): array {
    $pdo = getDB();

    $table     = $report_type === 'found' ? 'found_reports' : 'lost_reports';
    $id_col    = $report_type === 'found' ? 'found_id'      : 'lost_id';
    $bad_stati = $report_type === 'found' ? "('claimed','disposed')" : "('resolved','closed')";

    $keywords = tokenize($title);
    if (empty($keywords)) {
        return [];
    }

    $likeClauses = [];
    $params      = [];
    foreach ($keywords as $kw) {
        $likeClauses[] = "title LIKE ?";
        $params[]      = '%' . $kw . '%';
    }

    if ($category_id > 0) {
        $params[] = $category_id;
    }

    $sql = "SELECT $id_col AS report_id, title, category_id, created_at
            FROM   $table
            WHERE  (" . implode(' OR ', $likeClauses) . ")
              AND  status NOT IN $bad_stati"
         . ($category_id > 0 ? " AND category_id = ?" : "")
         . " ORDER BY created_at DESC LIMIT $limit";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------------
// private_description parser
// ---------------------------------------------------------------------------

/**
 * Parses: "Colors: Red | Traits: faded, oversized | Keywords: jacket | Exact Spot: Library"
 * Returns: ['colors'=>'Red', 'traits'=>'faded, oversized', 'keywords'=>'jacket', 'exact_spot'=>'Library']
 */
function parsePrivateDescription(string $text): array
{
    $result   = ['colors' => '', 'traits' => '', 'keywords' => '', 'exact_spot' => ''];
    $segments = array_map('trim', explode('|', $text));

    foreach ($segments as $segment) {
        if (strpos($segment, ':') === false) {
            continue;
        }
        [$label, $value] = array_map('trim', explode(':', $segment, 2));
        $key = strtolower($label);

        if (str_contains($key, 'color'))                            $result['colors']    = $value;
        elseif (str_contains($key, 'trait'))                        $result['traits']    = $value;
        elseif (str_contains($key, 'key'))                          $result['keywords']  = $value;
        elseif (str_contains($key, 'spot') || str_contains($key, 'exact')) $result['exact_spot'] = $value;
    }

    return $result;
}

/**
 * Builds the scoring text blob for a report row.
 * Keywords: and Traits: are included twice to boost their weight in Jaccard scoring.
 */
function buildScoringText(array $row): string
{
    $parts = parsePrivateDescription($row['private_description'] ?? '');

    return implode(' ', array_filter([
        $row['title']      ?? '',
        $parts['keywords'],
        $parts['keywords'], // double weight
        $parts['traits'],
        $parts['colors'],
        $parts['exact_spot'],
    ]));
}

// ---------------------------------------------------------------------------
// Scoring
// ---------------------------------------------------------------------------

function scoreMatch(array $found, array $lost): int
{
    $score = 0;

    // Category (30 pts)
    if ((int)($found['category_id'] ?? 0) === (int)($lost['category_id'] ?? 0)
        && (int)($found['category_id'] ?? 0) > 0) {
        $score += 30;
    }

    // Location (25 pts)
    $score += scoreLocation($found, $lost);

    // Keywords (30 pts)
    $score += scoreKeywords(buildScoringText($found), buildScoringText($lost));

    // Date proximity (15 pts)
    $score += scoreDate($found['date_found'] ?? '', $lost['date_lost'] ?? '');

    return min(100, $score);
}

function scoreLocation(array $found, array $lost): int
{
    $pts = 0;

    $foundLoc = (int)($found['location_id'] ?? 0);
    $lostLoc  = (int)($lost['location_id']  ?? 0);

    if ($foundLoc > 0 && $foundLoc === $lostLoc) {
        $pts += 20;
    } elseif ($foundLoc === 1 || $lostLoc === 1) {
        $pts += 5; // "Other" partial credit
    }

    // Exact Spot text bonus (up to 5 pts)
    $foundSpot = parsePrivateDescription($found['private_description'] ?? '')['exact_spot'];
    $lostSpot  = parsePrivateDescription($lost['private_description']  ?? '')['exact_spot'];

    if ($foundSpot !== '' && $lostSpot !== '') {
        $spotScore = scoreKeywords($foundSpot, $lostSpot);
        $pts += (int)round($spotScore / 6); // scale 0-30 → 0-5
    }

    return min(25, $pts);
}

function scoreKeywords(string $textA, string $textB): int
{
    $tokensA = tokenize($textA);
    $tokensB = tokenize($textB);

    if (empty($tokensA) || empty($tokensB)) {
        return 0;
    }

    $intersection = count(array_intersect($tokensA, $tokensB));
    $union        = count(array_unique(array_merge($tokensA, $tokensB)));

    return ($union > 0) ? (int)round(($intersection / $union) * 30) : 0;
}

function scoreDate(string $dateFound, string $dateLost): int
{
    if ($dateFound === '' || $dateLost === '') {
        return 0;
    }
    try {
        $diff = abs((new DateTime($dateFound))->diff(new DateTime($dateLost))->days);
    } catch (Throwable) {
        return 0;
    }

    if ($diff === 0) return 15;
    if ($diff <= 3)  return 10;
    if ($diff <= 7)  return 5;
    return 0;
}

// ---------------------------------------------------------------------------
// Tokeniser
// ---------------------------------------------------------------------------

function tokenize(string $text): array
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
        // strip private_description label words
        'colors','color','traits','trait','keywords','keyword',
        'exact','spot','description',
    ];

    // Replace pipe, colon, comma separators with spaces first
    $clean = preg_replace('/[|:,\/\\\\]+/', ' ', mb_strtolower($text));
    // Remove all remaining non-alphanumeric chars except spaces and hyphens
    $clean = preg_replace('/[^a-z0-9\s\-]/', ' ', $clean);
    $words = preg_split('/\s+/', trim($clean), -1, PREG_SPLIT_NO_EMPTY);

    $tokens = array_filter(
        $words,
        fn($w) => strlen($w) >= 3 && !in_array($w, $stopWords, true)
    );

    $stemmed = array_map('stemWord', array_values($tokens));

    return array_values(array_unique($stemmed));
}

function stemWord(string $word): string
{
    static $rules = [
        '/tion$/' => '',
        '/ing$/'  => '',
        '/ness$/' => '',
        '/ed$/'   => '',
        '/er$/'   => '',
        '/est$/'  => '',
        '/s$/'    => '',
    ];
    foreach ($rules as $pattern => $replace) {
        $candidate = preg_replace($pattern, $replace, $word);
        if (strlen($candidate) >= 3 && $candidate !== $word) {
            return $candidate;
        }
    }
    return $word;
}

// ---------------------------------------------------------------------------
// Signals for portal UI
// ---------------------------------------------------------------------------

function buildSignals(array $found, array $lost): array
{
    $kwScore   = scoreKeywords(buildScoringText($found), buildScoringText($lost));
    $foundSpot = parsePrivateDescription($found['private_description'] ?? '')['exact_spot'];
    $lostSpot  = parsePrivateDescription($lost['private_description']  ?? '')['exact_spot'];
    $spotMatch = ($foundSpot !== '' && $lostSpot !== '')
               ? scoreKeywords($foundSpot, $lostSpot) > 0
               : false;

    return [
        'Category match'  => (int)($found['category_id'] ?? 0) === (int)($lost['category_id'] ?? 0),
        'Location match'  => (int)($found['location_id'] ?? 0) === (int)($lost['location_id'] ?? 0),
        'Keyword match'   => $kwScore > 0,
        'Exact spot hint' => $spotMatch,
        'Photo provided'  => !empty($found['image_path']),
    ];
}

function buildSignalNote(array $signals): string
{
    return 'Matched on: ' . implode(', ', array_keys(array_filter($signals)));
}

// ---------------------------------------------------------------------------
// DB helpers
// ---------------------------------------------------------------------------

function fetchFoundReport(PDO $pdo, int $found_id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM found_reports WHERE found_id = ? LIMIT 1"
    );
    $stmt->execute([$found_id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    // Fetch image separately — avoids the correlated-subquery LIMIT bug
    $img = $pdo->prepare(
        "SELECT image_path FROM item_images
         WHERE  report_type = 'found' AND report_id = ?
         ORDER  BY uploaded_at DESC LIMIT 1"
    );
    $img->execute([$found_id]);
    $row['image_path'] = $img->fetchColumn() ?: null;

    return $row;
}

function fetchOpenLostReports(PDO $pdo): array
{
    $stmt = $pdo->prepare(
        "SELECT l.*, u.phone_number, u.full_name
         FROM   lost_reports l
         JOIN   users u ON u.user_id = l.user_id
         WHERE  l.status IN ('open', 'matched')
         ORDER  BY l.created_at DESC"
    );
    $stmt->execute();
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
    try {
        $check = $pdo->prepare(
            "SELECT match_id FROM matches WHERE lost_id = ? AND found_id = ? LIMIT 1"
        );
        $check->execute([$lost_id, $found_id]);
        $existing = $check->fetchColumn();

        if ($existing) {
            $pdo->prepare(
                "UPDATE matches
                 SET confidence_score = ?, status = ?, notes = ?, matched_at = NOW()
                 WHERE match_id = ?"
            )->execute([$score, $status, $notes, $existing]);
            return (int)$existing;
        }

        // Use NULL for system-triggered matches (admin_id = 0)
        $matched_by = ($admin_id > 1) ? $admin_id : null;

        $pdo->prepare(
            "INSERT INTO matches (lost_id, found_id, matched_by, match_type, status, confidence_score, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([$lost_id, $found_id, $matched_by, $match_type, $status, $score, $notes]);

        return (int)$pdo->lastInsertId();

    } catch (Throwable $e) {
        error_log("[MatchingEngine] upsertMatch failed (lost=$lost_id, found=$found_id): " . $e->getMessage());
        return 0;
    }
}

// ---------------------------------------------------------------------------
// SMS
// ---------------------------------------------------------------------------

function sendMatchSms(PDO $pdo, int $user_id, array $found, array $lost, int $score): void
{
    $phone = $lost['phone_number'] ?? null;

    if (!$phone) {
        $s = $pdo->prepare("SELECT phone_number FROM users WHERE user_id = ? LIMIT 1");
        $s->execute([$user_id]);
        $phone = $s->fetchColumn() ?: null;
    }

    if (!$phone) {
        return;
    }

    $ownerName = $lost['full_name'] ?? 'Student';
    $lostTitle = $lost['title']     ?? 'your item';

    $message = "Hi {$ownerName}, a potential match ({$score}% confidence) for your "
             . "lost \"{$lostTitle}\" has been found at the Office of Student Affairs. "
             . "Please visit OSA with a valid ID to verify and claim your item. "
             . "- CMU Lost & Found";

    if (function_exists('sendSms')) {
        sendSms($phone, $message);
    }
}