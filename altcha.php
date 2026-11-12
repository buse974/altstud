<?php
/**
 * Captcha ALTCHA (preuve de travail, auto-hébergé, sans service tiers).
 *
 * Protocole ALTCHA v2 :
 *   défi      = sha256(sel . nombre)          — le navigateur cherche `nombre`
 *   signature = hmac_sha256(défi, clé)        — prouve que le défi vient de nous
 * Le sel porte une date d'expiration (`?expires=`). Chaque défi résolu n'est
 * accepté qu'une fois (liste des signatures utilisées, fichier temporaire).
 *
 * Clé : variable d'environnement ALTCHA_HMAC_KEY (jamais dans le dépôt).
 */

const ALTCHA_MAX_NUMBER = 100000;   // ~0,5 à 2 s de calcul côté navigateur
const ALTCHA_TTL = 900;             // un défi est valable 15 min

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(404);
    exit();
}

function altcha_key(): ?string
{
    $key = getenv('ALTCHA_HMAC_KEY');
    return ($key !== false && strlen($key) >= 32) ? $key : null;
}

function altcha_create_challenge(string $key): array
{
    $salt = bin2hex(random_bytes(12)) . '?expires=' . (time() + ALTCHA_TTL);
    $number = random_int(1000, ALTCHA_MAX_NUMBER);
    $challenge = hash('sha256', $salt . $number);

    return [
        'algorithm' => 'SHA-256',
        'challenge' => $challenge,
        'maxnumber' => ALTCHA_MAX_NUMBER,
        'salt' => $salt,
        'signature' => hash_hmac('sha256', $challenge, $key),
    ];
}

/** Vérifie la charge utile envoyée par le widget (champ `altcha`, base64 JSON). */
function altcha_verify(?string $payload, string $key): bool
{
    if (!$payload || strlen($payload) > 2048) {
        return false;
    }
    $data = json_decode(base64_decode($payload, true) ?: '', true);
    if (!is_array($data)) {
        return false;
    }
    foreach (['algorithm', 'challenge', 'number', 'salt', 'signature'] as $field) {
        if (!isset($data[$field])) {
            return false;
        }
    }
    if ($data['algorithm'] !== 'SHA-256' || !is_int($data['number'])) {
        return false;
    }

    // Expiration portée par le sel.
    if (!preg_match('/[?&]expires=(\d+)/', (string) $data['salt'], $m)) {
        return false;
    }
    $expires = (int) $m[1];
    if ($expires < time()) {
        return false;
    }

    $challenge = hash('sha256', $data['salt'] . $data['number']);
    if (!hash_equals($challenge, (string) $data['challenge'])) {
        return false;
    }
    $signature = hash_hmac('sha256', $challenge, $key);
    if (!hash_equals($signature, (string) $data['signature'])) {
        return false;
    }

    return altcha_mark_used($signature, $expires);
}

/** Refuse un défi déjà utilisé. Retourne false si la signature a déjà servi. */
function altcha_mark_used(string $signature, int $expires): bool
{
    $file = sys_get_temp_dir() . '/altcha-used.json';
    $fp = fopen($file, 'c+');
    if (!$fp) {
        return true; // pas de stockage : on n'empêche pas l'envoi pour autant
    }
    flock($fp, LOCK_EX);
    $used = json_decode(stream_get_contents($fp) ?: '[]', true) ?: [];
    $now = time();
    $used = array_filter($used, fn ($exp) => $exp >= $now);

    $fresh = !isset($used[$signature]);
    if ($fresh) {
        $used[$signature] = $expires;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($used));
    }
    flock($fp, LOCK_UN);
    fclose($fp);

    return $fresh;
}
