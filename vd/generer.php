<?php
/**
 * Générateur de CV ciblé pour Virginie Damour.
 *
 *   POST  { offre, code }  → génère un CV + lettre adaptés à l'offre
 *   GET   ?liste           → liste des CV déjà générés
 *
 * L'offre peut être un lien (Claude va lire la page) ou le texte collé.
 * Le parcours de Virginie vient des variantes écrites à la main (vd/*.md) :
 * Claude oriente et reformule, il n'invente rien.
 *
 * La rédaction passe par Claude Code en mode non interactif (`claude -p`),
 * sur l'abonnement de Christophe : usage personnel, protégé par un code.
 *
 * Variables d'environnement (jamais dans le dépôt) :
 *   CLAUDE_CODE_OAUTH_TOKEN  jeton créé par `claude setup-token`
 *   CV_ACCESS_CODE           code demandé dans le formulaire
 *   CV_GEN_DIR               dossier des CV générés (défaut : vd/gen, à monter en volume)
 */

require __DIR__ . '/cv-render.php';

const CV_MODEL = 'opus';
const CV_MAX_OFFRE = 30000;
const CV_TIMEOUT = 420; // secondes

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

function fail(string $message, int $code = 400): never
{
    reply(['success' => false, 'message' => $message], $code);
}

$genDir = rtrim(getenv('CV_GEN_DIR') ?: __DIR__ . '/gen', '/');
$manifest = $genDir . '/index.json';

function read_manifest(string $file): array
{
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    return is_array($data) ? $data : [];
}

// --- Liste des CV générés ---------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['liste'])) {
    reply(['success' => true, 'cvs' => array_reverse(read_manifest($manifest))]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Méthode non autorisée', 405);
}

// --- Contrôles ---------------------------------------------------------------
$token = getenv('CLAUDE_CODE_OAUTH_TOKEN');
$accessCode = getenv('CV_ACCESS_CODE');
if (!$token || !$accessCode) {
    fail('Générateur non configuré (jeton Claude ou code d\'accès manquant)', 503);
}

$input = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST;
$code = (string) ($input['code'] ?? '');
$offre = trim((string) ($input['offre'] ?? ''));

if (!hash_equals($accessCode, $code)) {
    usleep(800000); // ralentit les essais au hasard
    fail('Code d\'accès incorrect', 403);
}
if (mb_strlen($offre) < 15) {
    fail('Colle le lien ou le texte de l\'offre');
}
if (mb_strlen($offre) > CV_MAX_OFFRE) {
    fail('Offre trop longue (' . CV_MAX_OFFRE . ' caractères max)');
}

set_time_limit(CV_TIMEOUT + 30);
ignore_user_abort(true);

$isUrl = (bool) preg_match('~^https?://\S+$~i', $offre);

try {
    $cv = generate_cv($offre, $isUrl);
} catch (RuntimeException $e) {
    fail($e->getMessage(), 502);
}
if (!$cv['offre_lisible']) {
    fail('Impossible de lire cette page (site protégé ou offre expirée). Copie-colle le texte de l\'offre à la place.', 422);
}

// --- Enregistrement de la page -------------------------------------------
if (!is_dir($genDir) && !@mkdir($genDir, 0775, true)) {
    fail('Dossier des CV générés non accessible en écriture', 500);
}

$slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $cv['slug']) ?: 'cv')), '-') ?: 'cv';
$file = substr($slug, 0, 60) . '-' . date('Ymd-His') . '.html';

if (file_put_contents("$genDir/$file", cv_render($cv, '../')) === false) {
    fail('Impossible d\'enregistrer le CV', 500);
}

$entry = [
    'file' => "gen/$file",
    'titre' => $cv['titre_carte'],
    'description' => $cv['description_carte'],
    'source' => $isUrl ? $offre : null,
    'date' => date('c'),
];
$fp = fopen($manifest, 'c+');
if ($fp) {
    flock($fp, LOCK_EX);
    $list = json_decode(stream_get_contents($fp) ?: '[]', true) ?: [];
    $list[] = $entry;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    flock($fp, LOCK_UN);
    fclose($fp);
}

reply(['success' => true, 'cv' => $entry, 'notes' => $cv['notes']]);

// =============================================================================

/** Parcours de Virginie : toutes les variantes écrites à la main. */
function connaissances(): string
{
    $out = '';
    foreach (glob(__DIR__ . '/*.md') as $md) {
        if (basename($md) === 'index.md') {
            continue;
        }
        $out .= "\n\n<variante fichier=\"" . basename($md) . "\">\n"
            . trim((string) file_get_contents($md)) . "\n</variante>";
    }
    return $out;
}

function generate_cv(string $offre, bool $isUrl): array
{
    $system = <<<TXT
Tu rédiges le CV et la lettre de motivation de Virginie Damour, en français, pour une offre d'emploi précise.

Son parcours est décrit ci-dessous par plusieurs versions de son CV, chacune orientée vers un métier différent. Ces versions sont ta seule source sur elle : elles disent ce qu'elle a fait, où, quand, et quels diplômes elle a.

Règles :
- N'invente rien : pas d'employeur, de poste, de date, de diplôme, de certification, de logiciel ou de compétence technique qui ne figure pas dans ses versions. Tu choisis, ordonnes et reformules ses expériences réelles pour qu'elles parlent au recruteur de cette offre, comme les versions existantes le font pour leur métier.
- Si l'offre exige quelque chose qu'elle n'a pas (diplôme, permis, expérience précise), ne le prétends pas : mets en avant ce qui s'en rapproche le plus, et signale le manque dans `notes`.
- Garde le ton des versions existantes : simple, concret, chaleureux, sans jargon RH ni superlatifs creux. Profil en un paragraphe, à la première personne.
- 5 ou 6 compétences, chacune reliée à une expérience réelle. Expériences dans l'ordre le plus parlant pour cette offre ; tu peux en omettre qui n'apportent rien. 3 ou 4 points par expérience.
- Si une date n'est pas connue, laisse le champ vide.
- Lettre : 3 ou 4 paragraphes adressés à l'employeur de l'offre (nomme l'entreprise et le poste quand l'offre les donne), sans la formule d'appel « Madame, Monsieur, » ni la signature, qui sont ajoutées automatiquement.
- Mets en **gras** (double astérisque) quelques mots-clés, avec parcimonie, comme dans les versions existantes. Aucun autre balisage.
- `slug` : 2 à 4 mots en minuscules séparés par des tirets, décrivant le poste (et l'entreprise si connue), ex. « vendeuse-boulangerie-paul ».
- `notes` : 2 à 4 phrases pour Christophe : ce que tu as mis en avant, et les points de l'offre qu'elle ne couvre pas ou à vérifier avec elle.
- Si on te donne un lien, lis l'offre avec WebFetch. Si la page est inaccessible ou ne contient pas d'offre d'emploi, mets `offre_lisible` à false et remplis les autres champs au plus court ; sinon `offre_lisible` vaut true.

Ses versions de CV :
TXT . connaissances();

    $str = ['type' => 'string'];
    $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['offre_lisible', 'slug', 'titre_carte', 'description_carte', 'role', 'meta_description', 'profil', 'competences', 'experiences', 'formations', 'lettre', 'notes'],
        'properties' => [
            'offre_lisible' => ['type' => 'boolean'],
            'slug' => $str,
            'titre_carte' => ['type' => 'string', 'description' => 'Titre court pour la liste des CV, ex. « Vendeuse – Boulangerie Paul »'],
            'description_carte' => ['type' => 'string', 'description' => 'Une ou deux phrases décrivant le poste visé et l\'angle du CV'],
            'role' => ['type' => 'string', 'description' => 'Sous-titre sous son nom : le poste visé'],
            'meta_description' => $str,
            'profil' => $str,
            'competences' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['titre', 'texte'],
                'properties' => ['titre' => $str, 'texte' => $str],
            ]],
            'experiences' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['poste', 'entreprise', 'dates', 'points'],
                'properties' => ['poste' => $str, 'entreprise' => $str, 'dates' => $str, 'points' => ['type' => 'array', 'items' => $str]],
            ]],
            'formations' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['intitule', 'etablissement', 'annee'],
                'properties' => ['intitule' => $str, 'etablissement' => $str, 'annee' => $str],
            ]],
            'lettre' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['destinataire', 'objet', 'paragraphes', 'formule'],
                'properties' => [
                    'destinataire' => ['type' => 'string', 'description' => 'Ex. « À l\'attention du service recrutement – Carrefour Antibes »'],
                    'objet' => ['type' => 'string', 'description' => 'Sans le préfixe « Objet : »'],
                    'paragraphes' => ['type' => 'array', 'items' => $str],
                    'formule' => ['type' => 'string', 'description' => 'Formule de politesse finale complète'],
                ],
            ],
            'notes' => $str,
        ],
    ];

    $prompt = $isUrl
        ? "Rédige le CV et la lettre pour l'offre d'emploi publiée ici : $offre"
        : "Rédige le CV et la lettre pour cette offre d'emploi :\n\n<offre>\n$offre\n</offre>";

    // Claude Code a besoin d'un HOME inscriptible pour sa configuration.
    $home = sys_get_temp_dir() . '/claude-home';
    @mkdir($home, 0700, true);
    $sysFile = tempnam(sys_get_temp_dir(), 'cv-sys');
    file_put_contents($sysFile, $system);

    $cmd = [
        'claude', '-p',
        '--model', CV_MODEL,
        '--effort', 'medium',
        '--output-format', 'json',
        '--json-schema', json_encode($schema, JSON_UNESCAPED_UNICODE),
        '--system-prompt-file', $sysFile,
        '--tools', $isUrl ? 'WebFetch' : '',
        '--allowedTools', 'WebFetch',
        '--setting-sources', '',
        '--no-session-persistence',
    ];
    $env = [
        'HOME' => $home,
        'PATH' => '/usr/local/bin:/usr/bin:/bin',
        'CLAUDE_CODE_OAUTH_TOKEN' => getenv('CLAUDE_CODE_OAUTH_TOKEN'),
        'DISABLE_AUTOUPDATER' => '1',
    ];

    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $home, $env);
    if (!is_resource($proc)) {
        @unlink($sysFile);
        throw new RuntimeException('Impossible de lancer Claude Code');
    }
    fwrite($pipes[0], $prompt);
    fclose($pipes[0]);

    $out = $err = '';
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $deadline = time() + CV_TIMEOUT;
    while (true) {
        $read = [$pipes[1], $pipes[2]];
        $w = $e = null;
        if (stream_select($read, $w, $e, 5) > 0) {
            foreach ($read as $r) {
                $chunk = fread($r, 65536);
                if ($r === $pipes[1]) { $out .= $chunk; } else { $err .= $chunk; }
            }
        }
        if (!proc_get_status($proc)['running']) {
            $out .= stream_get_contents($pipes[1]);
            $err .= stream_get_contents($pipes[2]);
            break;
        }
        if (time() > $deadline) {
            proc_terminate($proc);
            @unlink($sysFile);
            throw new RuntimeException('La rédaction a pris trop de temps, réessaie');
        }
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    @unlink($sysFile);

    $res = json_decode($out, true);
    if (!is_array($res)) {
        error_log('[cv] claude: ' . substr($err ?: $out, 0, 2000));
        throw new RuntimeException('Claude Code n\'a pas répondu correctement');
    }
    if (!empty($res['is_error']) || !is_array($res['structured_output'] ?? null)) {
        error_log('[cv] claude: ' . substr($out, 0, 2000));
        $msg = (string) ($res['result'] ?? '');
        if (stripos($msg, 'limit') !== false) {
            throw new RuntimeException('Limite d\'usage Claude atteinte, réessaie plus tard');
        }
        throw new RuntimeException('Échec de la rédaction, réessaie');
    }
    return $res['structured_output'];
}
