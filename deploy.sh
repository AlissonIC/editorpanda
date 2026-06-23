#!/usr/bin/env bash
# =====================================================================
# Atualiza o projeto Panda: git pull + composer + npm build + migrate.
#
# Uso (a partir da raiz do projeto):
#   ./update.sh                  # ambiente local (default)
#   ./update.sh --prod           # produção: --no-dev, com caches
#   ./update.sh --no-build       # pula npm build
#   ./update.sh --branch dev     # puxa de outro branch (default: main)
# =====================================================================

set -euo pipefail

REPO="https://github.com/AlissonIC/editorpanda.git"
PROD=0
NO_BUILD=0
BRANCH="main"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --prod)     PROD=1; shift ;;
        --no-build) NO_BUILD=1; shift ;;
        --branch)   BRANCH="$2"; shift 2 ;;
        *) echo "Opção desconhecida: $1"; exit 1 ;;
    esac
done

step() { printf "\n\033[36m==> %s\033[0m\n" "$1"; }
ok()   { printf "\n\033[32m%s\033[0m\n" "$1"; }
warn() { printf "\033[33m%s\033[0m\n" "$1"; }

# ---- 1. Git repo & remote ----
if [[ ! -d .git ]]; then
    step "Inicializando repositório git"
    git init
    git remote add origin "$REPO"
    git fetch origin
    git checkout -t "origin/$BRANCH"
else
    current=$(git remote get-url origin 2>/dev/null || true)
    if [[ -z "$current" ]]; then
        git remote add origin "$REPO"
    elif [[ "$current" != "$REPO" ]]; then
        warn "Aviso: remote 'origin' aponta para $current (esperado $REPO)."
    fi
fi

# ---- 2. Pull ----
step "git pull origin $BRANCH"
git pull --ff-only origin "$BRANCH"

# ---- 3. Composer ----
step "composer install"
if [[ $PROD -eq 1 ]]; then
    composer install --no-dev --optimize-autoloader --no-interaction
else
    composer install --no-interaction
fi

# ---- 4. NPM + build ----
if [[ $NO_BUILD -eq 0 ]]; then
    step "npm install + build"
    npm install --no-audit --no-fund
    npm run build
fi

# ---- 5. Migrate ----
step "php artisan migrate --force"
php artisan migrate --force

# ---- 6. Storage link ----
if [[ ! -e public/storage ]]; then
    step "php artisan storage:link"
    php artisan storage:link
fi

# ---- 7. Caches ----
if [[ $PROD -eq 1 ]]; then
    step "Caches de produção"
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
else
    step "Limpando caches (dev)"
    php artisan config:clear
    php artisan route:clear
    php artisan view:clear
fi

# ---- 8. Reinicia queue worker ----
step "php artisan queue:restart"
php artisan queue:restart

ok "OK — projeto atualizado."
