# TapeTrack

Suivi des tapes Commvault : quoi sortir de la TL, quoi imprimer, où ranger, quoi rentrer.

Stack : PHP 8.4, Symfony 7.4 LTS, Doctrine/MariaDB, Twig + Symfony UX (phases suivantes).

## État : Phase 0 (découverte de l'API Commvault)

- `src/Commvault/` : client REST **lecture seule**
  - `HttpCommvaultClient` : Symfony HttpClient, TLS toujours vérifié, CA interne via `COMMVAULT_CA_BUNDLE`, retry, ré-authentification sur 401, journalisation dans `var/log/commvault-*.log` sans secrets ;
  - `FixtureCommvaultClient` : client fake basé sur des fixtures JSON ;
  - `ReadOnlyGuard` : refuse toute requête susceptible de modifier Commvault.
- `php bin/console app:commvault:discover` : appelle les endpoints candidats et dumpe les réponses dans `var/discovery/`.
- [`docs/commvault-mapping.md`](docs/commvault-mapping.md) : mapping données ↔ endpoints, procédure et questions ouvertes.

## Installation (dev)

```bash
composer install
cp .env .env.local      # adapter COMMVAULT_*
php bin/console secrets:set COMMVAULT_PASSWORD
vendor/bin/phpunit
```

Avec `COMMVAULT_CLIENT=fake` (valeur par défaut), aucune connexion au CommServe n'est faite.

## En container (Docker Desktop / Podman Desktop sous WSL)

Pas besoin de PHP sur la machine : tout tourne dans une image PHP 8.4.

```bash
cp docker/tapetrack.env.dist docker/tapetrack.env      # URL du CommServe, mode d'auth
printf '%s' 'mot-de-passe-api_sa' > docker/secrets/commvault_password
cp /chemin/ca-interne.pem docker/certs/ca.pem           # CA AD CS (sinon vider COMMVAULT_CA_BUNDLE)
mkdir -p var/discovery var/log

docker compose build
docker compose run --rm test                  # tests, sans réseau
docker compose run --rm discover --list       # ce qui sera appelé
docker compose run --rm discover -vv          # découverte -> ./var/discovery/
```

- Le mot de passe est lu comme *secret* compose (`/run/secrets/commvault_password`) et n'apparaît ni dans l'image, ni dans `docker inspect`. `docker/secrets/`, `docker/certs/` et `docker/tapetrack.env` sont ignorés par git.
- Le container tourne avec l'UID 1000 (utilisateur WSL par défaut) : les dumps de `var/discovery` vous appartiennent. Autre UID : `docker compose build --build-arg UID=$(id -u)`.
- La paire d'access tokens renouvelée est conservée dans le volume `commvault-state` (les refresh tokens sont à usage unique).
- Le CommServe doit être joignable depuis WSL : vérifier avec `curl -v https://<commserve>/commandcenter/api/` dans WSL. Derrière un VPN, le mode réseau *mirrored* de WSL (`networkingMode=mirrored` dans `.wslconfig`) aide souvent.
- Avec Podman : `podman compose` à la place de `docker compose`.
- Avec **WSL Containers** (`wslc`, sans compose) : `.\docker\wslc.ps1 build`, `.\docker\wslc.ps1 test`, `.\docker\wslc.ps1 discover --list`, puis `.\docker\wslc.ps1 discover -vv`.

Depuis **PowerShell** (Windows), les équivalents des commandes de préparation :

```powershell
Copy-Item docker\tapetrack.env.dist docker\tapetrack.env
$pw = Read-Host 'Mot de passe api_sa' -AsSecureString
[IO.File]::WriteAllText("$PWD\docker\secrets\commvault_password", [Net.NetworkCredential]::new('', $pw).Password)
New-Item -ItemType Directory -Force var\discovery, var\log | Out-Null

# CA interne (AD CS) au format PEM, extraite du magasin Windows :
.\docker\export-ca.ps1 -CommServe commserve.domaine.local
```

Le README complet (déploiement Ubuntu/Nginx, worker systemd, synchro) sera rédigé dans les phases suivantes.
