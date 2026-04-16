<?php
require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

/**
 * CMU Lost & Found — AI Suggestion Endpoint (Gemini-first)
 * POST /core/get_suggestions.php
 *
 * Receives: { title, category, report_type }
 * Returns:  { traits: string[], keywords: string[], source: "ai" | "vocabulary" | "empty" }
 *
 * Strategy:
 *   1. Try Gemini 2.5 Flash first — it is the PRIMARY suggestion source.
 *   2. If Gemini fails (no key, timeout, parse error, rate limit) fall back
 *      to returning standard traits + keywords from vocabulary.json.
 */

// Security 
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Request validation 
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

// Rate limiting (session-based, 100 calls per session)
$_SESSION['ai_suggestion_calls'] = ($_SESSION['ai_suggestion_calls'] ?? 0) + 1;
if ($_SESSION['ai_suggestion_calls'] > 100) {
    echo json_encode(vocabFallback($category) + ['source' => 'rate_limited']);
    exit;
}

// Load vocabulary.json (needed for context + fallback) 
$vocab_path = __DIR__ . '/../assets/data/vocabulary.json';
$vocab      = [];
if (file_exists($vocab_path)) {
    $vocab = json_decode(file_get_contents($vocab_path), true);
}

$standard_traits   = $vocab['categories'][$category]['traits']   ?? [];
$standard_keywords = $vocab['categories'][$category]['keywords'] ?? [];

// Helper: return vocabulary fallback payload 
function vocabFallback(string $category): array {
    global $vocab;
    $traits   = $vocab['categories'][$category]['traits']   ?? [];
    $keywords = $vocab['categories'][$category]['keywords'] ?? [];
    return [
        'traits'   => array_slice($traits,   0, 8),
        'keywords' => array_slice($keywords, 0, 8),
    ];
}

// Helper: sanitize a single suggestion string 
function sanitize_suggestion(string $s): string {
    return substr(strip_tags(trim($s)), 0, 60);
}

// Check for Gemini API key 
$api_key = $_ENV['GEMINI_API_KEY'];

if (empty($api_key)) {
    error_log('GEMINI_API_KEY is not set');
}

if (empty($api_key)) {
    // No key — fall back to vocabulary immediately
    $fallback = vocabFallback($category);
    echo json_encode($fallback + ['source' => 'vocabulary']);
    exit;
}

// Build Gemini prompt ─
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

// Call Gemini API 
// Using the generateContent REST endpoint for gemini-2.5-flash
$gemini_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . urlencode($api_key);

$gemini_payload = json_encode([
    'contents' => [
        [
            'parts' => [
                ['text' => $prompt]
            ]
        ]
    ],
    'generationConfig' => [
        'responseMimeType' => 'application/json',
        'temperature'      => 0.4,
        'maxOutputTokens'  => 2048,
        'thinkingConfig'   => [
            'thinkingBudget' => 0,  // Disable thinking for simple suggestion tasks
        ],
    ]
]);

$ch = curl_init($gemini_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $gemini_payload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
    ],
    CURLOPT_TIMEOUT        => 8,
]);

$response  = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err  = curl_error($ch);
curl_close($ch);


// Parse Gemini response 
if ($http_code !== 200 || !$response || $curl_err) {
    // Network/API failure → fall back to vocabulary
    $fallback = vocabFallback($category);
    echo json_encode($fallback + ['source' => 'vocabulary']);
    exit;
}

$gemini_data = json_decode($response, true);

// Extract text from Gemini's response structure:
// response.candidates[0].content.parts[0].text
$raw_text = $gemini_data['candidates'][0]['content']['parts'][0]['text'] ?? '';

if (empty($raw_text)) {
    $fallback = vocabFallback($category);
    echo json_encode($fallback + ['source' => 'vocabulary']);
    exit;
}

// Strip any accidental markdown fences (belt-and-suspenders)
$raw_text = preg_replace('/```(?:json)?|```/', '', $raw_text);
$raw_text = trim($raw_text);

$suggestions = json_decode($raw_text, true);

// Validate structure
if (
    !is_array($suggestions)
    || !isset($suggestions['traits'])
    || !isset($suggestions['keywords'])
) {
    $fallback = vocabFallback($category);
    echo json_encode($fallback + ['source' => 'vocabulary']);
    exit;
}

// Sanitize and limit output 
$traits   = array_map('sanitize_suggestion', array_slice((array)$suggestions['traits'],   0, 8));
$keywords = array_map('sanitize_suggestion', array_slice((array)$suggestions['keywords'], 0, 8));

$traits   = array_values(array_filter($traits));
$keywords = array_values(array_filter($keywords));

// If Gemini returned empty arrays, still fall back to vocabulary
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