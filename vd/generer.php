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
 * Variables d'environnement (jamais dans le dépôt) :
 *   ANTHROPIC_API_KEY  clé de l'API Claude
 *   CV_ACCESS_CODE     code demandé dans le formulaire (évite qu'un inconnu consomme la clé)
 *   CV_GEN_DIR         dossier des CV générés (défaut : vd/gen, à monter en volume)
 */

require __DIR__ . '/../php-vendor/autoload.php';
require __DIR__ . '/cv-render.php';

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\OverloadedException;
use Anthropic\Core\Exceptions\RateLimitException;

const CV_MODEL = 'claude-opus-5-5';
const CV_MAX_OFFRE = 30000;

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
$apiKey = getenv('ANTHROPIC_API_KEY');
$accessCode = getenv('CV_ACCESS_CODE');
if (!$apiKey || !$accessCode) {
    fail('Générateur non configuré (clé API ou code d\'accès manquant)', 503);
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

set_time_limit(600);
ignore_user_abort(true);

$client = new Client(apiKey: $apiKey);
$isUrl = (bool) preg_match('~^https?://\S+$~i', $offre);

try {
    // --- 1. Si c'est un lien : Claude lit la page de l'offre -----------------
    $texteOffre = $isUrl ? fetch_offre($client, $offre) : $offre;
    if ($texteOffre === null) {
        fail('Impossible de lire cette page (site protégé ou offre expirée). Copie-colle le texte de l\'offre à la place.', 422);
    }

    // --- 2. Génération du CV structuré ---------------------------------------
    $cv = generate_cv($client, $texteOffre, $isUrl ? $offre : null);
} catch (AuthenticationException) {
    fail('Clé API Claude refusée', 502);
} catch (RateLimitException | OverloadedException) {
    fail('Claude est surchargé, réessaie dans une minute', 503);
} catch (APIStatusException $e) {
    error_log('[cv] API ' . $e->getMessage());
    fail('Erreur de l\'API Claude', 502);
} catch (APIConnectionException $e) {
    error_log('[cv] connexion ' . $e->getMessage());
    fail('Impossible de joindre l\'API Claude', 502);
} catch (RuntimeException $e) {
    fail($e->getMessage(), 502);
}

// --- 3. Enregistrement de la page -------------------------------------------
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

/** Lit l'offre derrière un lien avec l'outil web_fetch de Claude. null si illisible. */
function fetch_offre(Client $client, string $url): ?string
{
    $messages = [[
        'role' => 'user',
        'content' => "Lis l'offre d'emploi à cette adresse : $url\n\n"
            . "Restitue fidèlement son contenu en texte brut : intitulé du poste, entreprise, lieu, "
            . "type de contrat, horaires, salaire, missions, profil recherché, et tout ce qui décrit "
            . "l'employeur. Ne résume pas, ne commente pas. Si la page est inaccessible ou ne contient "
            . "pas d'offre d'emploi, réponds uniquement : INACCESSIBLE",
    ]];

    for ($i = 0; $i < 4; $i++) {
        $res = $client->messages->create(
            model: CV_MODEL,
            maxTokens: 16000,
            outputConfig: ['effort' => 'low'],
            tools: [['type' => 'web_fetch_20260209', 'name' => 'web_fetch', 'maxUses' => 3]],
            messages: $messages,
        );
        if ($res->stopReason !== 'pause_turn') {
            break;
        }
        $messages[] = ['role' => 'assistant', 'content' => $res->content];
    }

    if ($res->stopReason === 'refusal') {
        return null;
    }
    $text = '';
    foreach ($res->content as $block) {
        if ($block->type === 'text') {
            $text .= $block->text;
        }
    }
    $text = trim($text);
    return ($text === '' || str_contains($text, 'INACCESSIBLE')) ? null : $text;
}

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

function generate_cv(Client $client, string $offre, ?string $url): array
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

Ses versions de CV :
TXT . connaissances();

    $str = ['type' => 'string'];
    $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['slug', 'titre_carte', 'description_carte', 'role', 'meta_description', 'profil', 'competences', 'experiences', 'formations', 'lettre', 'notes'],
        'properties' => [
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

    $intro = $url ? "Offre d'emploi (lue sur $url) :" : "Offre d'emploi :";

    $res = $client->beta->messages->create(
        model: CV_MODEL,
        maxTokens: 16000,
        system: [['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']]],
        messages: [['role' => 'user', 'content' => "$intro\n\n<offre>\n$offre\n</offre>"]],
        outputConfig: [
            'effort' => 'medium',
            'format' => ['type' => 'json_schema', 'schema' => $schema],
        ],
        fallbacks: 'default',
        betas: ['server-side-fallback-2026-07-01'],
    );

    if ($res->stopReason === 'refusal') {
        throw new RuntimeException('Claude a refusé de traiter cette offre');
    }
    if ($res->stopReason === 'max_tokens') {
        throw new RuntimeException('Réponse coupée (trop longue), réessaie');
    }

    foreach ($res->content as $block) {
        if ($block->type === 'text') {
            $cv = json_decode($block->text, true);
            if (is_array($cv)) {
                return $cv;
            }
        }
    }
    throw new RuntimeException('Réponse de Claude illisible, réessaie');
}
