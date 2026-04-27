<?php
// markdown.php — Markdown for Agents content negotiation handler
// Serves a .md representation when the client requests Accept: text/markdown
declare(strict_types=1);

$root = __DIR__;
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rawurldecode($path);

function resolveMarkdown(string $path, string $root): ?string {
    if ($path === '' || $path === '/') {
        $f = $root . '/index.md';
        return is_file($f) ? $f : null;
    }
    $clean = ltrim($path, '/');
    if (preg_match('#^(.+?)\.html?$#', $clean, $m)) {
        $f = $root . '/' . $m[1] . '.md';
        return is_file($f) ? $f : null;
    }
    if (substr($clean, -1) === '/') {
        $f = $root . '/' . $clean . 'index.md';
        return is_file($f) ? $f : null;
    }
    foreach ([$root . '/' . $clean . '.md', $root . '/' . $clean . '/index.md'] as $f) {
        if (is_file($f)) return $f;
    }
    return null;
}

$file = resolveMarkdown($path, $root);

if ($file === null) {
    http_response_code(406);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Markdown representation not available for this resource.\n";
    exit;
}

$realFile = realpath($file);
$realRoot = realpath($root);
if ($realFile === false || $realRoot === false || strncmp($realFile, $realRoot, strlen($realRoot)) !== 0) {
    http_response_code(403);
    exit;
}

$content = file_get_contents($realFile);
$tokens = (int) max(1, ceil(strlen($content) / 4));

header('Content-Type: text/markdown; charset=utf-8');
header('X-Markdown-Tokens: ' . $tokens);
header('Content-Signal: ai-train=no, search=yes, ai-input=yes');
header('Cache-Control: public, max-age=3600');
header('Vary: Accept');
echo $content;
