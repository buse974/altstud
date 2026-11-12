<?php
/**
 * Rendu HTML d'un CV généré (même gabarit et même cv.css que les variantes
 * écrites à la main). Inclus par generer.php, jamais appelé directement.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(404);
    exit();
}

/** Échappe le texte puis transforme **gras** en <strong>. */
function cv_txt(string $s): string
{
    $s = htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $s);
}

function cv_esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * @param array $cv   données structurées renvoyées par Claude
 * @param string $base chemin relatif vers vd/ depuis la page (ex. "../")
 */
function cv_render(array $cv, string $base = '../'): string
{
    ob_start();
    ?>
<!doctype html>
<html lang="fr">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <meta name="robots" content="noindex" />
        <title>CV - Virginie Damour | <?= cv_esc($cv['role']) ?></title>
        <meta name="description" content="<?= cv_esc($cv['meta_description']) ?>" />
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
        <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
        <link rel="stylesheet" href="<?= $base ?>cv.css" />
    </head>
    <body>
        <div class="container">
            <header class="header">
                <div class="photo-placeholder">
                    <img src="<?= $base ?>vir.png" alt="Virginie Damour" />
                </div>
                <div class="header-info">
                    <h1>Virginie Damour</h1>
                    <p class="role"><?= cv_esc($cv['role']) ?></p>
                    <div class="contact-grid">
                        <a href="tel:+33615593101" class="contact-item">06 15 59 31 01</a>
                        <a href="mailto:virginie.damour@gmail.com" class="contact-item">virginie.damour@gmail.com</a>
                        <span class="contact-item">22 avenue de la Palmeraie, 06220 Golfe-Juan</span>
                    </div>
                </div>
            </header>

            <div class="profil">
                <p><?= cv_txt($cv['profil']) ?></p>
            </div>

            <section class="section">
                <h2 class="section-title">Compétences</h2>
                <div class="skills-grid">
<?php foreach ($cv['competences'] as $c): ?>
                    <div class="skill-item">
                        <strong><?= cv_esc($c['titre']) ?></strong>
                        <span><?= cv_txt($c['texte']) ?></span>
                    </div>
<?php endforeach; ?>
                </div>
            </section>

            <section class="section">
                <h2 class="section-title">Expériences professionnelles</h2>
<?php foreach ($cv['experiences'] as $e): ?>
                <div class="experience-item">
                    <div class="experience-header">
                        <h3><?= cv_esc($e['poste']) ?></h3>
                        <p class="company"><?= cv_esc($e['entreprise']) ?></p>
<?php if (trim($e['dates']) !== ''): ?>
                        <p class="date"><?= cv_esc($e['dates']) ?></p>
<?php endif; ?>
                    </div>
                    <ul class="experience-list">
<?php foreach ($e['points'] as $p): ?>
                        <li><?= cv_txt($p) ?></li>
<?php endforeach; ?>
                    </ul>
                </div>
<?php endforeach; ?>
            </section>

            <section class="section">
                <h2 class="section-title">Formation</h2>
                <div class="formation-grid">
<?php foreach ($cv['formations'] as $f): ?>
                    <div class="formation-item">
                        <h4><?= cv_esc($f['intitule']) ?></h4>
                        <p class="school"><?= cv_esc($f['etablissement']) ?></p>
<?php if (trim($f['annee']) !== ''): ?>
                        <p class="year"><?= cv_esc($f['annee']) ?></p>
<?php endif; ?>
                    </div>
<?php endforeach; ?>
                </div>
            </section>
        </div>

        <!-- Lettre de motivation -->
        <div class="container lettre-motivation" id="lettre">
            <h2 class="section-title" style="margin-top: 2rem">Lettre de motivation</h2>
            <div class="lettre-content">
                <div class="lettre-header">
                    <div class="expediteur">
                        <p><strong>Virginie Damour</strong></p>
                        <p>22 avenue de la Palmeraie</p>
                        <p>06220 Golfe-Juan</p>
                        <p>06 15 59 31 01</p>
                        <p>virginie.damour@gmail.com</p>
                    </div>
                    <div class="destinataire">
                        <p><strong><?= cv_esc($cv['lettre']['destinataire']) ?></strong></p>
                    </div>
                    <p class="date-lettre">Golfe-Juan</p>
                </div>

                <p class="objet"><strong>Objet : <?= cv_esc($cv['lettre']['objet']) ?></strong></p>

                <div class="corps-lettre">
                    <p>Madame, Monsieur,</p>
<?php foreach ($cv['lettre']['paragraphes'] as $p): ?>
                    <p><?= cv_txt($p) ?></p>
<?php endforeach; ?>
                    <p class="formule"><?= cv_txt($cv['lettre']['formule']) ?></p>
                    <p class="signature">Virginie Damour</p>
                </div>
            </div>
        </div>

        <div class="btn-container">
            <a href="<?= $base ?>index.html" class="print-btn back-btn">← Retour</a>
            <button class="print-btn" onclick="printCV()">Télécharger CV</button>
            <button class="print-btn print-lettre" onclick="printLettre()">Télécharger Lettre</button>
        </div>

        <script>
            function printCV() {
                document.getElementById("lettre").style.display = "none";
                window.print();
                document.getElementById("lettre").style.display = "block";
            }
            function printLettre() {
                const container = document.querySelector(".container:not(.lettre-motivation)");
                container.style.display = "none";
                window.print();
                container.style.display = "block";
            }
        </script>
    </body>
</html>
<?php
    return ob_get_clean();
}
