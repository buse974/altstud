# Procédure CI/CD - Déploiement Docker + GitHub Actions + Traefik

## Prérequis

### Sur le VPS (ssh alt)
- Docker + Docker Compose installés
- Traefik configuré avec réseau `traefik-public`
- Clé SSH de déploiement : `~/.ssh/github_deploy` (privée) + `~/.ssh/authorized_keys` (publique)

### Structure VPS
```
~/infra/
├── traefik/
├── <projet>/
│   └── docker-compose.yml
```

---

## Étape 1 : Créer le Dockerfile

```dockerfile
FROM nginx:alpine

# Copier les fichiers du site
COPY index.html /usr/share/nginx/html/
COPY styles.css /usr/share/nginx/html/
COPY script.js /usr/share/nginx/html/
COPY img/ /usr/share/nginx/html/img/

# Config nginx
COPY nginx.conf /etc/nginx/conf.d/default.conf

EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]
```

## Étape 2 : Créer nginx.conf

```nginx
server {
    listen 80;
    server_name localhost;
    root /usr/share/nginx/html;
    index index.html;

    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_types text/plain text/css text/xml text/javascript application/javascript application/json;

    location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    location / {
        try_files $uri $uri/ /index.html;
    }
}
```

## Étape 3 : Créer le workflow GitHub Actions

Créer `.github/workflows/deploy.yml` :

```yaml
name: Deploy

on:
  push:
    branches: [main]

env:
  REGISTRY: ghcr.io
  IMAGE_NAME: ${{ github.repository }}

jobs:
  build-and-deploy:
    runs-on: ubuntu-latest
    permissions:
      contents: read
      packages: write

    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Log in to Container Registry
        uses: docker/login-action@v3
        with:
          registry: ${{ env.REGISTRY }}
          username: ${{ github.actor }}
          password: ${{ secrets.GITHUB_TOKEN }}

      - name: Extract metadata
        id: meta
        uses: docker/metadata-action@v5
        with:
          images: ${{ env.REGISTRY }}/${{ env.IMAGE_NAME }}
          tags: |
            type=raw,value=latest
            type=sha,prefix=

      - name: Build and push Docker image
        uses: docker/build-push-action@v5
        with:
          context: .
          push: true
          tags: ${{ steps.meta.outputs.tags }}
          labels: ${{ steps.meta.outputs.labels }}

      - name: Deploy to VPS
        uses: appleboy/ssh-action@v1.0.3
        with:
          host: ${{ secrets.VPS_HOST }}
          username: ${{ secrets.VPS_USER }}
          key: ${{ secrets.VPS_SSH_KEY }}
          script: |
            cd ~/infra/<PROJET>
            docker compose pull
            docker compose up -d
            docker image prune -f
```

## Étape 4 : Créer le docker-compose sur le VPS

```bash
ssh alt "mkdir -p ~/infra/<PROJET>"
```

Créer `~/infra/<PROJET>/docker-compose.yml` :

```yaml
services:
  <projet>:
    image: ghcr.io/buse974/<projet>:latest
    container_name: <projet>
    restart: unless-stopped
    networks:
      - traefik-public
    labels:
      - "traefik.enable=true"
      - "traefik.http.routers.<projet>.rule=Host(`<domaine>`) || Host(`www.<domaine>`) || Host(`<projet>.51.77.223.61.nip.io`)"
      - "traefik.http.routers.<projet>.entrypoints=websecure"
      - "traefik.http.routers.<projet>.tls=true"
      - "traefik.http.routers.<projet>.tls.certresolver=letsencrypt"
      - "traefik.http.services.<projet>.loadbalancer.server.port=80"

networks:
  traefik-public:
    external: true
```

## Étape 5 : Configurer les secrets GitHub

**IMPORTANT** : Récupérer la clé SSH depuis le VPS, pas en local !

```bash
# Créer le repo GitHub
gh repo create buse974/<projet> --public --description "<description>"

# Configurer les secrets
gh secret set VPS_HOST --repo buse974/<projet> --body "51.77.223.61"
gh secret set VPS_USER --repo buse974/<projet> --body "fedora"

# IMPORTANT: La clé SSH est sur le VPS !
ssh alt "cat ~/.ssh/github_deploy" | gh secret set VPS_SSH_KEY --repo buse974/<projet>
```

## Étape 6 : Push initial

```bash
cd /chemin/vers/projet
git init
git branch -M main
git remote add origin git@github.com:buse974/<projet>.git
git add -A
git commit -m "Initial commit"
git push -u origin main
```

## Étape 7 : Vérifier le déploiement

```bash
# Vérifier le status du workflow
gh run list --repo buse974/<projet> --limit 1

# Voir les logs en cas d'erreur
gh run view <RUN_ID> --repo buse974/<projet> --log-failed

# Vérifier sur le VPS
ssh alt "docker ps | grep <projet>"

# Tester l'URL temporaire
curl -I https://<projet>.51.77.223.61.nip.io
```

## Étape 8 : Rediriger le domaine (quand prêt)

1. Aller dans la zone DNS du domaine
2. Ajouter un enregistrement A : `@` → `51.77.223.61`
3. Ajouter un enregistrement A : `www` → `51.77.223.61`
4. Attendre propagation DNS (quelques minutes à 24h)

---

## Checklist rapide

- [ ] Dockerfile créé
- [ ] nginx.conf créé
- [ ] .github/workflows/deploy.yml créé
- [ ] Repo GitHub créé (`gh repo create`)
- [ ] Secrets configurés (VPS_HOST, VPS_USER, VPS_SSH_KEY depuis le VPS)
- [ ] docker-compose.yml sur le VPS (`~/infra/<projet>/`)
- [ ] Push initial effectué
- [ ] Workflow GitHub Actions passé ✅
- [ ] Container running sur le VPS
- [ ] URL temporaire fonctionnelle (*.51.77.223.61.nip.io)
- [ ] DNS redirigé (quand prêt)

---

## Commandes utiles

```bash
# Logs du container
ssh alt "docker logs -f <projet>"

# Restart manuel
ssh alt "cd ~/infra/<projet> && docker compose restart"

# Rebuild complet
ssh alt "cd ~/infra/<projet> && docker compose pull && docker compose up -d"

# Status de tous les containers
ssh alt "docker ps"
```

---

## Erreurs courantes

### "Error: missing server host"
→ Secret `VPS_HOST` non configuré

### "Error: can't connect without a private SSH key"
→ Secret `VPS_SSH_KEY` non configuré ou mauvaise clé
→ **Solution** : `ssh alt "cat ~/.ssh/github_deploy" | gh secret set VPS_SSH_KEY --repo buse974/<projet>`

### "Repository not found"
→ Le repo GitHub n'existe pas encore
→ **Solution** : `gh repo create buse974/<projet> --public`

### Container ne démarre pas
→ Vérifier les logs : `ssh alt "docker logs <projet>"`
→ Vérifier que le réseau traefik-public existe : `ssh alt "docker network ls | grep traefik"`
