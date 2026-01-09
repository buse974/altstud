# Déployer un nouveau projet sur le VPS

Crée et configure automatiquement le déploiement Docker + CI/CD + Traefik pour un nouveau projet.

## Arguments
- $ARGUMENTS : nom du projet (ex: monsite), domaine (ex: monsite.fr), chemin local (ex: /home/christopherobert/workspace/monsite)

## Instructions

Lis d'abord le fichier SETUP-CICD.md pour comprendre la procédure complète.

Ensuite, exécute les étapes suivantes automatiquement :

1. **Analyser le projet** : Détecte le type de projet (site statique, Node.js, PHP, etc.) dans le chemin fourni

2. **Créer le Dockerfile** adapté au type de projet

3. **Créer nginx.conf** si nécessaire

4. **Créer .github/workflows/deploy.yml** avec le nom du projet

5. **Initialiser git** si pas déjà fait

6. **Créer le repo GitHub** : `gh repo create buse974/<projet> --public`

7. **Configurer les secrets GitHub** :
   - VPS_HOST: 51.77.223.61
   - VPS_USER: fedora  
   - VPS_SSH_KEY: récupérer depuis `ssh alt "cat ~/.ssh/github_deploy"`

8. **Créer le docker-compose sur le VPS** dans `~/infra/<projet>/`
   - Configurer Traefik avec le domaine fourni + URL temporaire nip.io

9. **Push initial** sur GitHub

10. **Vérifier le déploiement** : attendre que le workflow passe et tester l'URL

## Exemple d'utilisation
```
/deploy-new-project monprojet monprojet.fr /home/christopherobert/workspace/monprojet
```

## Informations VPS
- IP: 51.77.223.61
- User: fedora
- SSH: `ssh alt`
- Infra: ~/infra/
- Réseau Docker: traefik-public
