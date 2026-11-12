// Bouton « Modifier » sur chaque page de CV : envoie une consigne libre
// à generer.php (action « modifier »). Un CV généré est réécrit sur place ;
// une variante écrite à la main donne une nouvelle page dans gen/.
(function () {
    const bar = document.querySelector(".btn-container");
    if (!bar) return;

    const parts = location.pathname.split("/");
    const file = parts.pop();
    const inGen = parts[parts.length - 1] === "gen";
    const base = inGen ? "../" : "";
    const source = (inGen ? "gen/" : "") + file;

    let savedCode = "";
    try {
        savedCode = localStorage.getItem("cv-code") || "";
    } catch (e) {}

    const panel = document.createElement("form");
    panel.className = "edit-panel";
    panel.hidden = true;
    panel.innerHTML = `
        <h2>Modifier ce CV</h2>
        <p>${
            inGen
                ? "La page sera réécrite avec tes changements (la version précédente est gardée)."
                : "Une nouvelle version sera créée dans les CV générés ; celle-ci ne change pas."
        }</p>
        <textarea name="consigne" required placeholder="Ex. : ajoute qu'elle a le permis B, raccourcis la lettre, mets Carrefour en premier…"></textarea>
        <div class="gen-row">
            <input type="password" name="code" required placeholder="Code d'accès" autocomplete="current-password" />
            <button type="submit">Appliquer</button>
            <button type="button" class="edit-cancel">Fermer</button>
        </div>
        <div class="gen-status" aria-live="polite"></div>`;
    document.body.appendChild(panel);

    const statut = panel.querySelector(".gen-status");
    panel.code.value = savedCode;
    if (savedCode) panel.code.closest(".gen-row").classList.add("code-ok");

    const btn = document.createElement("button");
    btn.className = "print-btn edit-btn";
    btn.type = "button";
    btn.textContent = "Modifier";
    bar.insertBefore(btn, bar.children[1] || null);

    btn.addEventListener("click", () => {
        panel.hidden = !panel.hidden;
        if (!panel.hidden) panel.consigne.focus();
    });
    panel.querySelector(".edit-cancel").addEventListener("click", () => {
        panel.hidden = true;
    });

    panel.addEventListener("submit", async (ev) => {
        ev.preventDefault();
        const submit = panel.querySelector("button[type=submit]");
        submit.disabled = true;
        statut.className = "gen-status";
        statut.textContent = "Modification en cours… (30 secondes à 1 minute)";

        try {
            const r = await fetch(base + "generer.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    action: "modifier",
                    source,
                    consigne: panel.consigne.value,
                    code: panel.code.value,
                }),
            });
            const data = await r.json().catch(() => ({
                success: false,
                message: "Réponse inattendue du serveur (" + r.status + ")",
            }));
            if (!data.success) throw new Error(data.message);

            try {
                localStorage.setItem("cv-code", panel.code.value);
            } catch (e) {}

            statut.textContent = (data.notes || "Modifié.") + " Rechargement…";
            setTimeout(() => {
                location.href =
                    base + data.cv.file + "?v=" + Date.now();
            }, 1500);
        } catch (e) {
            statut.className = "gen-status erreur";
            statut.textContent = e.message || "Erreur réseau";
            submit.disabled = false;
            if (/code/i.test(e.message)) {
                panel.code.closest(".gen-row").classList.remove("code-ok");
                panel.code.value = "";
                panel.code.focus();
            }
        }
    });
})();
