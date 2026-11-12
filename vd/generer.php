<?php
/**
 * Générateur de CV ciblé pour Virginie Damour.
 *
 *   POST { action: "generer",  offre, code }            → nouveau CV + lettre pour une offre
 *   POST { action: "modifier", source, consigne, code } → applique une consigne à un CV existant
 *   GET  ?liste                                         → liste des CV générés
 *
 * L'offre peut être un lien (Claude va lire la page) ou le texte collé.
 * Le parcours de Virginie vient des variantes écrites à la main (vd/*.md) :
 * Claude oriente et reformule, il n'invente rien.
 *
 * Modifier un CV généré (gen/…) le réécrit sur place (l'ancienne version est
 * gardée en .prev). Modifier une variante écrite à la main crée une nouvelle
 * page dans gen/ : les variantes du dépôt ne changent qu'avec un commit.
 *
 * La rédaction passe par Claude Code en mode non interactif (`claude -p`),
 * sur l'abonnement de Christophe : usage personnel, protégé par un code.
 *
 * Variables d'environnement (jamais dans le dépôt) :
 *   CLAUDE_CODE_OAUTH_TOKEN  jeton créé par `claude setup-token`
 *   CV_ACCESS_CODE           code demandé dans les formulaires
 *   CV_GEN_DIR               dossier des CV générés (défaut : vd/gen, à monter en volume)
 */

require __DIR__ . '/cv-render.php';

const CV_MODEL = 'opus';
const CV_MAX_INPUT = 30000;
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

// --- Liste des CV générés ---------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['liste'])) {
    $list = is_file($manifest) ? json_decode((string) file_get_contents($manifest), true) : [];
    reply(['success' => true, 'cvs' => array_reverse(is_array($list) ? $list : [])]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Méthode non autorisée', 405);
}

// --- Contrôles communs -------------------------------------------------------
$token = getenv('CLAUDE_CODE_OAUTH_TOKEN');
$accessCode = getenv('CV_ACCESS_CODE');
if (!$token || !$accessCode) {
    fail('Générateur non configuré (jeton Claude ou code d\'accès manquant)', 503);
}

$input = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST;
if (!hash_equals($accessCode, (string) ($input['code'] ?? ''))) {
    usleep(800000); // ralentit les essais au hasard
    fail('Code d\'accès incorrect', 403);
}

set_time_limit(CV_TIMEOUT + 30);
ignore_user_abort(true);

$action = (string) ($input['action'] ?? 'generer');

try {
    if ($action === 'modifier') {
        action_modifier($input, $genDir, $manifest);
    }
    action_generer($input, $genDir, $manifest);
} catch (RuntimeException $e) {
    fail($e->getMessage(), 502);
}

// =============================================================================
// Actions

function action_generer(array $input, string $genDir, string $manifest): never
{
    $offre = trim((string) ($input['offre'] ?? ''));
    check_length($offre, 'Colle le lien ou le texte de l\'offre');
    $isUrl = (bool) preg_match('~^https?://\S+$~i', $offre);

    $prompt = $isUrl
        ? "Rédige le CV et la lettre pour l'offre d'emploi publiée ici : $offre\n\n"
          . "Lis l'offre avec WebFetch. Si la page est inaccessible ou ne contient pas d'offre d'emploi, "
          . "mets `offre_lisible` à false et remplis les autres champs au plus court."
        : "Rédige le CV et la lettre pour cette offre d'emploi :\n\n<offre>\n$offre\n</offre>";

    $cv = run_claude($prompt, $isUrl);
    if (!$cv['offre_lisible']) {
        fail('Impossible de lire cette page (site protégé ou offre expirée). Copie-colle le texte de l\'offre à la place.', 422);
    }

    $entry = save_new($cv, $genDir, $manifest, $isUrl ? $offre : null);
    reply(['success' => true, 'cv' => $entry, 'notes' => $cv['notes']]);
}

function action_modifier(array $input, string $genDir, string $manifest): never
{
    $consigne = trim((string) ($input['consigne'] ?? ''));
    check_length($consigne, 'Écris ce qu\'il faut changer', 3);

    // Source : une variante écrite à la main (x.html) ou un CV généré (gen/x.html).
    $source = (string) ($input['source'] ?? '');
    if (!preg_match('~^(gen/)?([a-z0-9][a-z0-9-]*)\.html$~', $source, $m) || $m[2] === 'index') {
        fail('CV source invalide');
    }
    $isGen = $m[1] !== '';

    if ($isGen) {
        $jsonFile = "$genDir/{$m[2]}.json";
        $current = is_file($jsonFile) ? (string) file_get_contents($jsonFile) : '';
        if ($current === '') {
            fail('Ce CV ne peut pas être modifié (données d\'origine absentes)', 404);
        }
        $actuel = "Version actuelle du CV (JSON) :\n<cv_actuel>\n$current\n</cv_actuel>";
    } else {
        $md = __DIR__ . "/{$m[2]}.md";
        if (!is_file($md)) {
            fail('CV introuvable', 404);
        }
        $actuel = "Version actuelle du CV (variante « {$m[2]} ») :\n<cv_actuel>\n"
            . trim((string) file_get_contents($md)) . "\n</cv_actuel>";
    }

    $prompt = "$actuel\n\nChristophe demande cette modification :\n<consigne>\n$consigne\n</consigne>\n\n"
        . "Renvoie le CV complet avec la modification appliquée. Garde tel quel tout ce que la consigne "
        . "ne demande pas de changer (formulations, ordre, lettre). La consigne peut t'apprendre un fait "
        . "nouveau sur Virginie (permis, disponibilité, expérience oubliée…) : tu peux alors l'utiliser. "
        . "Mets `offre_lisible` à true. Dans `notes`, résume en une ou deux phrases ce que tu as changé.";

    $cv = run_claude($prompt, false);

    if (!$isGen) {
        $entry = save_new($cv, $genDir, $manifest, null);
        reply(['success' => true, 'cv' => $entry, 'notes' => $cv['notes'], 'nouveau' => true]);
    }

    // CV généré : réécrit sur place, version précédente gardée en .prev.
    $base = "$genDir/{$m[2]}";
    @copy("$base.html", "$base.html.prev");
    @copy("$base.json", "$base.json.prev");
    write_cv($cv, $base);

    $entry = update_manifest($manifest, function (array $list) use ($source, $cv) {
        foreach ($list as &$e) {
            if ($e['file'] === $source) {
                $e['titre'] = $cv['titre_carte'];
                $e['description'] = $cv['description_carte'];
                $e['modifie'] = date('c');
            }
        }
        return $list;
    }, $source);
    reply(['success' => true, 'cv' => $entry, 'notes' => $cv['notes'], 'nouveau' => false]);
}

// =============================================================================
// Fichiers

function check_length(string $text, string $emptyMessage, int $min = 15): void
{
    if (mb_strlen($text) < $min) {
        fail($emptyMessage);
    }
    if (mb_strlen($text) > CV_MAX_INPUT) {
        fail('Texte trop long (' . CV_MAX_INPUT . ' caractères max)');
    }
}

/** Écrit la page HTML et ses données JSON (pour les modifications suivantes). */
function write_cv(array $cv, string $base): void
{
    $html = file_put_contents("$base.html", cv_render($cv, '../'));
    $json = file_put_contents("$base.json", json_encode($cv, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if ($html === false || $json === false) {
        throw new RuntimeException('Impossible d\'enregistrer le CV');
    }
}

function save_new(array $cv, string $genDir, string $manifest, ?string $sourceUrl): array
{
    if (!is_dir($genDir) && !@mkdir($genDir, 0775, true)) {
        throw new RuntimeException('Dossier des CV générés non accessible en écriture');
    }
    $slug = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $cv['slug']) ?: 'cv');
    $slug = substr(trim(preg_replace('/[^a-z0-9]+/', '-', $slug), '-') ?: 'cv', 0, 60);
    $name = $slug . '-' . date('Ymd-His');
    write_cv($cv, "$genDir/$name");

    $entry = [
        'file' => "gen/$name.html",
        'titre' => $cv['titre_carte'],
        'description' => $cv['description_carte'],
        'source' => $sourceUrl,
        'date' => date('c'),
    ];
    update_manifest($manifest, fn (array $list) => [...$list, $entry]);
    return $entry;
}

/** Modifie la liste des CV sous verrou ; renvoie l'entrée $file si demandée. */
function update_manifest(string $manifest, callable $change, ?string $file = null): ?array
{
    $fp = fopen($manifest, 'c+');
    if (!$fp) {
        return null;
    }
    flock($fp, LOCK_EX);
    $list = json_decode(stream_get_contents($fp) ?: '[]', true) ?: [];
    $list = $change($list);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    flock($fp, LOCK_UN);
    fclose($fp);

    foreach ($file ? $list : [] as $e) {
        if ($e['file'] === $file) {
            return $e;
        }
    }
    return $file ? ['file' => $file, 'titre' => '', 'description' => ''] : null;
}

// =============================================================================
// Claude

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

function system_prompt(): string
{
    return <<<TXT
Tu rédiges le CV et la lettre de motivation de Virginie Damour, en français, pour un poste précis.

Son parcours est décrit ci-dessous par plusieurs versions de son CV, chacune orientée vers un métier différent. Ces versions sont ta source sur elle : elles disent ce qu'elle a fait, où, quand, et quels diplômes elle a.

Règles :
- N'invente rien : pas d'employeur, de poste, de date, de diplôme, de certification, de logiciel ou de compétence technique qui ne figure pas dans ses versions (ou dans une consigne de Christophe). Tu choisis, ordonnes et reformules ses expériences réelles pour qu'elles parlent au recruteur, comme les versions existantes le font pour leur métier.
- Si l'offre exige quelque chose qu'elle n'a pas (diplôme, permis, expérience précise), ne le prétends pas : mets en avant ce qui s'en rapproche le plus, et signale le manque dans `notes`.
- Garde le ton des versions existantes : simple, concret, chaleureux, sans jargon RH ni superlatifs creux. Profil en un paragraphe, à la première personne.
- 5 ou 6 compétences, chacune reliée à une expérience réelle. Expériences dans l'ordre le plus parlant pour le poste ; tu peux en omettre qui n'apportent rien. 3 ou 4 points par expérience.
- Si une date n'est pas connue, laisse le champ vide.
- Lettre : 3 ou 4 paragraphes adressés à l'employeur (nomme l'entreprise et le poste quand tu les connais), sans la formule d'appel « Madame, Monsieur, » ni la signature, qui sont ajoutées automatiquement.
- Mets en **gras** (double astérisque) quelques mots-clés, avec parcimonie, comme dans les versions existantes. Aucun autre balisage.
- `slug` : 2 à 4 mots en minuscules séparés par des tirets, décrivant le poste (et l'entreprise si connue), ex. « vendeuse-boulangerie-paul ».
- `notes` : 2 à 4 phrases pour Christophe : ce que tu as mis en avant, et les points qu'elle ne couvre pas ou à vérifier avec elle.

Ses versions de CV :
TXT . connaissances();
}

function cv_schema(): array
{
    $str = ['type' => 'string'];
    $obj = fn (array $props) => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => array_keys($props), 'properties' => $props,
    ];

    return $obj([
        'offre_lisible' => ['type' => 'boolean'],
        'slug' => $str,
        'titre_carte' => ['type' => 'string', 'description' => 'Titre court pour la liste des CV, ex. « Vendeuse – Boulangerie Paul »'],
        'description_carte' => ['type' => 'string', 'description' => 'Une ou deux phrases décrivant le poste visé et l\'angle du CV'],
        'role' => ['type' => 'string', 'description' => 'Sous-titre sous son nom : le poste visé'],
        'meta_description' => $str,
        'profil' => $str,
        'competences' => ['type' => 'array', 'items' => $obj(['titre' => $str, 'texte' => $str])],
        'experiences' => ['type' => 'array', 'items' => $obj([
            'poste' => $str, 'entreprise' => $str, 'dates' => $str,
            'points' => ['type' => 'array', 'items' => $str],
        ])],
        'formations' => ['type' => 'array', 'items' => $obj(['intitule' => $str, 'etablissement' => $str, 'annee' => $str])],
        'lettre' => $obj([
            'destinataire' => ['type' => 'string', 'description' => 'Ex. « À l\'attention du service recrutement – Carrefour Antibes »'],
            'objet' => ['type' => 'string', 'description' => 'Sans le préfixe « Objet : »'],
            'paragraphes' => ['type' => 'array', 'items' => $str],
            'formule' => ['type' => 'string', 'description' => 'Formule de politesse finale complète'],
        ]),
        'notes' => $str,
    ]);
}

/** Lance `claude -p` et renvoie le CV structuré. */
function run_claude(string $prompt, bool $webFetch): array
{
    // Claude Code a besoin d'un HOME inscriptible pour sa configuration.
    $home = sys_get_temp_dir() . '/claude-home';
    @mkdir($home, 0700, true);
    $sysFile = tempnam(sys_get_temp_dir(), 'cv-sys');
    file_put_contents($sysFile, system_prompt());

    $cmd = [
        'claude', '-p',
        '--model', CV_MODEL,
        '--effort', 'medium',
        '--output-format', 'json',
        '--json-schema', json_encode(cv_schema(), JSON_UNESCAPED_UNICODE),
        '--system-prompt-file', $sysFile,
        '--tools', $webFetch ? 'WebFetch' : '',
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

    try {
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $home, $env);
        if (!is_resource($proc)) {
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
                    $chunk = (string) fread($r, 65536);
                    if ($r === $pipes[1]) {
                        $out .= $chunk;
                    } else {
                        $err .= $chunk;
                    }
                }
            }
            if (!proc_get_status($proc)['running']) {
                $out .= stream_get_contents($pipes[1]);
                $err .= stream_get_contents($pipes[2]);
                break;
            }
            if (time() > $deadline) {
                proc_terminate($proc);
                throw new RuntimeException('La rédaction a pris trop de temps, réessaie');
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    } finally {
        @unlink($sysFile);
    }

    $res = json_decode($out, true);
    if (!is_array($res)) {
        error_log('[cv] claude: ' . substr($err ?: $out, 0, 2000));
        throw new RuntimeException('Claude Code n\'a pas répondu correctement');
    }
    if (!empty($res['is_error']) || !is_array($res['structured_output'] ?? null)) {
        error_log('[cv] claude: ' . substr($out, 0, 2000));
        if (stripos((string) ($res['result'] ?? ''), 'limit') !== false) {
            throw new RuntimeException('Limite d\'usage Claude atteinte, réessaie plus tard');
        }
        throw new RuntimeException('Échec de la rédaction, réessaie');
    }
    return $res['structured_output'];
}
