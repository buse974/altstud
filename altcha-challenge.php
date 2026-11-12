<?php
// Fournit un défi ALTCHA au widget du formulaire de contact (voir altcha.php).
require __DIR__ . '/altcha.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$key = altcha_key();
if (!$key) {
    http_response_code(503);
    echo json_encode(['error' => 'captcha non configuré']);
    exit();
}

echo json_encode(altcha_create_challenge($key));
