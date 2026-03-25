<?php
/**
 * CMU Lost & Found — AI Suggestion Endpoint
 * POST /core/get_suggestions.php
 *
 * Receives: { title, category, report_type }
 * Returns:  { traits: string[], keywords: string[], source: "ai" }
 *
 * Called by smart_tag_input.js when the user has entered a title
 * that may not be well-covered by the standard vocabulary.json.
 * Only fires if the title is specific enough (>= 3 chars after debounce).
 */

// ── Security ───────────────────────────────────────────────────
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ── Request validation ─────────────────────────────────────────
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);

$title       = trim($input['title']       ?? '');
$category    = trim($input['category']    ?? '');
$report_type = trim($input['report_type'] ?? 'lost'); // 'lost' | 'found'

if (strlen($title) < 3 || empty($category)) {
    echo json_encode(['traits' => [], 'keywords' => [], 'source' => 'skipped']);
    exit;
}

// ── Rate limiting (simple session-based, 10 calls per session) ─
$_SESSION['ai_suggestion_calls'] = ($_SESSION['ai_suggestion_calls'] ?? 0) + 1;
if ($_SESSION['ai_suggestion_calls'] > 10) {
    // Silently return empty so the form still works
    echo json_encode(['traits' => [], 'keywords' => [], 'source' => 'rate_limited']);
    exit;
}

// ── Load standard vocabulary to give AI context ────────────────
$vocab_path = __DIR__ . '/../assets/data/vocabulary.json';
$vocab      = [];
if (file_exists($vocab_path)) {
    $vocab = json_decode(file_get_contents($vocab_path), true);
}

$standard_traits   = $vocab['categories'][$category]['traits']   ?? [];
$standard_keywords = $vocab['categories'][$category]['keywords'] ?? [];

// ── Build the prompt ───────────────────────────────────────────
$role_context = $report_type === 'found'
    ? "A university student FOUND this item and is describing what they observed."
    : "A university student LOST this item and is describing it from memory.";

$prompt = <<<PROMPT
You are assisting a lost-and-found system at a Philippine university (City of Malabon University).

{$role_context}

Item title: "{$title}"
Category selected: "{$category}"

Your job: suggest specific TRAITS and KEYWORDS that would help identify THIS particular item.

Standard traits already available for this category: {$standard_traits_str}
Standard keywords already available for this category: {$standard_keywords_str}

Rules:
- Return ONLY traits and keywords NOT already in the standard lists above (fill gaps).
- Traits = observable physical characteristics (e.g. "scratch on left lens", "UV400 tint", "nose pad missing").
- Keywords = specific identifiable words (brand, model, material, name, distinctive feature).
- Be SPECIFIC to the item title given. "Ray-Ban sunglasses" should produce different results than "cheap plastic sunglasses".
- Keep each trait/keyword SHORT (2–5 words max).
- Return between 3 and 8 traits, and 3 and 8 keywords.
- If the title is too generic (e.g. just "phone") return empty arrays.
- Respond ONLY with valid JSON. No explanation. No markdown. No extra text.

Required JSON format:
{"traits": ["trait one", "trait two"], "keywords": ["keyword one", "keyword two"]}
PROMPT;

// Inject the standard lists as strings into the prompt
$standard_traits_str   = implode(', ', $standard_traits);
$standard_keywords_str = implode(', ', $standard_keywords);

// Re-build prompt with actual values substituted
$prompt = str_replace(
    ['{$standard_traits_str}', '{$standard_keywords_str}'],
    [$standard_traits_str, $standard_keywords_str],
    $prompt
);

// ── Call Anthropic API ─────────────────────────────────────────
$api_key = getenv('ANTHROPIC_API_KEY'); // Set in your server environment / .env

if (empty($api_key)) {
    // Graceful fallback — don't crash the form if key is missing
    echo json_encode(['traits' => [], 'keywords' => [], 'source' => 'no_api_key']);
    exit;
}

$payload = json_encode([
    'model'      => 'claude-haiku-4-5-20251001', // Fast + cheap for short suggestions
    'max_tokens' => 300,
    'messages'   => [
        ['role' => 'user', 'content' => $prompt]
    ]
]);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . $api_key,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_TIMEOUT        => 8, // Don't hang the form for more than 8s
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200 || !$response) {
    echo json_encode(['traits' => [], 'keywords' => [], 'source' => 'api_error']);
    exit;
}

// ── Parse AI response ──────────────────────────────────────────
$data    = json_decode($response, true);
$raw_text = $data['content'][0]['text'] ?? '';

// Strip any accidental markdown fences
$raw_text = preg_replace('/```(?:json)?|```/', '', $raw_text);
$raw_text = trim($raw_text);

$suggestions = json_decode($raw_text, true);

if (!isset($suggestions['traits']) || !isset($suggestions['keywords'])) {
    echo json_encode(['traits' => [], 'keywords' => [], 'source' => 'parse_error']);
    exit;
}

// ── Sanitize output ────────────────────────────────────────────
function sanitize_suggestion(string $s): string {
    return substr(strip_tags(trim($s)), 0, 60);
}

$traits   = array_map('sanitize_suggestion', array_slice((array)$suggestions['traits'],   0, 8));
$keywords = array_map('sanitize_suggestion', array_slice((array)$suggestions['keywords'], 0, 8));

// Remove empty strings
$traits   = array_values(array_filter($traits));
$keywords = array_values(array_filter($keywords));

echo json_encode([
    'traits'   => $traits,
    'keywords' => $keywords,
    'source'   => 'ai',
]);