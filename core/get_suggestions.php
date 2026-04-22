<?php
require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

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
$report_type = trim($input['report_type'] ?? 'lost');

if (strlen($title) < 3 || empty($category)) {
    echo json_encode(['traits' => [], 'keywords' => [], 'source' => 'skipped']);
    exit;
}

$_SESSION['ai_suggestion_calls'] = ($_SESSION['ai_suggestion_calls'] ?? 0) + 1;
if ($_SESSION['ai_suggestion_calls'] > 100) {
    echo json_encode(vocabFallback($category) + ['source' => 'rate_limited']);
    exit;
}

$vocab_path = __DIR__ . '/../assets/data/vocabulary.json';
$vocab      = [];
if (file_exists($vocab_path)) {
    $vocab = json_decode(file_get_contents($vocab_path), true);
}

$standard_traits   = $vocab['categories'][$category]['traits']   ?? [];
$standard_keywords = $vocab['categories'][$category]['keywords'] ?? [];

function vocabFallback(string $category): array {
    global $vocab;
    $traits   = $vocab['categories'][$category]['traits']   ?? [];
    $keywords = $vocab['categories'][$category]['keywords'] ?? [];
    return [
        'traits'   => array_slice($traits,   0, 8),
        'keywords' => array_slice($keywords, 0, 8),
    ];
}

function sanitize_suggestion(string $s): string {
    return substr(strip_tags(trim($s)), 0, 60);
}

// ── Check for Mistral API key ─────────────────────────────────────────────
$api_key = $_ENV['MISTRAL_API_KEY'] ?? '';

if (empty($api_key)) {
    error_log('MISTRAL_API_KEY is not set');
    $fallback = vocabFallback($category);
    echo json_encode($fallback + ['source' => 'vocabulary']);
    exit;
}

// ── Build prompt ──────────────────────────────────────────────────────────
$role_context = $report_type === 'found'
    ? "A university student FOUND this item and is describing what they observed."
    : "A university student LOST this item and is describing it from memory.";

$standard_traits_str   = implode(', ', $standard_traits);
$standard_keywords_str = implode(', ', $standard_keywords);

$prompt = <<<PROMPT
You are assisting a lost-and-found system at a Philippine university (City of Malabon University).

{$role_context}

Item title: "{$title}"
Category selected: "{$category}"

Your job: suggest specific TRAITS and KEYWORDS that would help identify THIS particular item.

Standard traits already available for this category (do NOT repeat these): {$standard_traits_str}
Standard keywords already available for this category (do NOT repeat these): {$standard_keywords_str}

Rules:
- Return ONLY traits and keywords NOT already in the standard lists above.
- Traits = observable physical characteristics (e.g. "scratch on left lens", "UV400 tint", "nose pad missing").
- Keywords = specific identifiable words (brand, model, material, name, distinctive feature).
- Be SPECIFIC to the item title given. "Ray-Ban sunglasses" should produce different results than "cheap plastic sunglasses".
- Keep each trait/keyword SHORT (5–10 words max).
- Return between 5 and 10 traits, and 5 and 10 keywords.
- If the title is too generic (e.g. just "phone") return empty arrays.
- Respond ONLY with valid JSON. No explanation. No markdown fences. No extra text.

Required JSON format:
{"traits": ["trait one", "trait two"], "keywords": ["keyword one", "keyword two"]}
PROMPT;

// ── Call Mistral API ──────────────────────────────────────────────────────
$mistral_url = 'https://api.mistral.ai/v1/chat/completions';

$mistral_payload = json_encode([
    'model'       => 'mistral-medium-latest',
    'messages'    => [
        [
            'role'    => 'user',
            'content' => $prompt,
        ]
    ],
    'temperature' => 0.4,
    'max_tokens'  => 512,
    'top_p'       => 1,
]);

$ch = curl_init($mistral_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $mistral_payload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key,
    ],
    CURLOPT_TIMEOUT        => 10,
]);

$response  = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err  = curl_error($ch);
curl_close($ch);

// ── Parse Mistral response ────────────────────────────────────────────────
if ($http_code !== 200 || !$response || $curl_err) {
    error_log('[Mistral] HTTP ' . $http_code . ' | cURL: ' . $curl_err);
    $fallback = vocabFallback($category);
    echo json_encode($fallback + ['source' => 'vocabulary']);
    exit;
}

$mistral_data = json_decode($response, true);

// Mistral response structure: choices[0].message.content
$raw_text = $mistral_data['choices'][0]['message']['content'] ?? '';

if (empty($raw_text)) {
    $fallback = vocabFallback($category);
    echo json_encode($fallback + ['source' => 'vocabulary']);
    exit;
}

// Strip any accidental markdown fences
$raw_text = preg_replace('/```(?:json)?|```/', '', $raw_text);
$raw_text = trim($raw_text);

$suggestions = json_decode($raw_text, true);

if (
    !is_array($suggestions)
    || !isset($suggestions['traits'])
    || !isset($suggestions['keywords'])
) {
    $fallback = vocabFallback($category);
    echo json_encode($fallback + ['source' => 'vocabulary']);
    exit;
}

$traits   = array_map('sanitize_suggestion', array_slice((array)$suggestions['traits'],   0, 8));
$keywords = array_map('sanitize_suggestion', array_slice((array)$suggestions['keywords'], 0, 8));

$traits   = array_values(array_filter($traits));
$keywords = array_values(array_filter($keywords));

if (empty($traits) && empty($keywords)) {
    $fallback = vocabFallback($category);
    echo json_encode($fallback + ['source' => 'vocabulary']);
    exit;
}

echo json_encode([
    'traits'   => $traits,
    'keywords' => $keywords,
    'source'   => 'ai',
]);