# TapeTrack

Suivi des tapes Commvault : quoi sortir de la TL, quoi imprimer, où ranger, quoi rentrer.

Stack : PHP 8.4, Symfony 7.4 LTS, Doctrine/MariaDB, Twig + Symfony UX (phases suivantes).

## État

- **Phase 0, découverte de l'API Commvault : terminée.** Voir [`docs/commvault-mapping.md`](docs/commvault-mapping.md).
- **Phase 2, modèle de données et synchronisation : terminée.** Voir [`docs/eject-rules.md`](docs/eject-rules.md) et la section « Synchronisation » ci-dessous.
- **Phase 3, tableau de bord, vue TL et vue armoires : terminée.**
- Prochaine étape : Phase 4, sessions de sortie et de retour avec journal d'audit.

## Aperçu de l'interface en une commande (Windows, WSL Containers)

```powershell
.\docker\wslc.ps1 build
.\docker\wslc.ps1 app          # données de démo -> http://localhost:8000
.\docker\wslc.ps1 app -Real    # données réelles du CommServe (docker\tapetrack.env + secret)
```

Pour cet aperçu, la base est un fichier SQLite dans `var\data\`, distinct pour la démo et pour les données réelles. La synchro tourne toutes les 15 min tant que l'aperçu est ouvert. La production utilise MariaDB, Nginx/PHP-FPM et le worker systemd.

Pages :
- **Maintenant** (`/`) : compteurs, alertes, sorties tiroir par tiroir, fiches concernées, retours, transit ;
- **Tape library** (`/tl`) : les tiroirs et leurs slots colorés, les lecteurs ;
- **Armoires** (`/armoires`) ;
- **Synchronisations** (`/synchronisations`).

## Phase 0 : découverte de l'API Commvault

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

## Synchronisation (Phase 2)

L'interface ne lit jamais Commvault en direct : `app:sync` (ou le worker) lit Commvault en **lecture seule** et met à jour MariaDB.

```bash
php bin/console doctrine:migrations:migrate     # schéma MariaDB
php bin/console app:sync                        # synchro immédiate (affiche les compteurs)
php bin/console messenger:consume scheduler_default async -vv   # worker : synchro planifiée + « Synchroniser maintenant »
```

Avec `COMMVAULT_CLIENT=fake`, la synchro lit les fixtures de démo `tests/Fixtures/commvault/demo/`. Elles reprennent le format exact du CommServe, la fiche réelle Online N et un job en cours, en date de référence du 14/10/2026.

### Planification : Symfony Scheduler + Messenger plutôt que cron

- Le bouton « Synchroniser maintenant » doit rendre la main immédiatement. Il envoie donc un message `SyncCommvault` dans la file Messenger (transport Doctrine, dans MariaDB, sans infrastructure supplémentaire). Le **même worker** exécute aussi la synchro périodique (`src/Schedule.php`, toutes les `TAPETRACK_SYNC_INTERVAL`) : un seul chemin d'exécution.
- Un verrou (`symfony/lock`) empêche deux synchros simultanées. Un run concurrent est enregistré comme « ignoré ».
- Le worker est un service systemd : [`deploy/systemd/tapetrack-worker.service`](deploy/systemd/tapetrack-worker.service). Il redémarre automatiquement et se recycle toutes les heures.
- Une entrée cron `app:sync` reste possible en secours, mais elle ne traiterait pas le bouton « Synchroniser maintenant ».

Chaque run est historisé dans `sync_run` : statut, durée, compteurs, avertissements et erreur. C'est ce que lira l'écran « dernière synchro ».

### Configuration (`.env.local`)

| Variable | Défaut | Rôle |
|---|---|---|
| `DATABASE_URL` | MariaDB locale | **En production : `secrets:set DATABASE_URL`** |
| `TAPETRACK_MANAGED_LIBRARIES` | `36` | IDs Commvault des bibliothèques gérées (36 = IBM VSP) |
| `TAPETRACK_DEFAULT_DRAWERS` | `36=1:1-20,2:21-40,3:41-60;26=4:1-20` | Tiroirs créés à la première synchro (ensuite modifiables dans l'admin) |
| `TAPETRACK_JOB_WINDOW_DAYS` | `730` | Profondeur de lecture des jobs par copie |
| `TAPETRACK_CYCLE_GROUPING` | `retention` | `retention` (copie + date de rétention) ou `full_to_full` |
| `TAPETRACK_SYNC_INTERVAL` | `15 minutes` | Période de la synchro automatique |
| `TAPETRACK_INVENTORY_GRACE_HOURS` | `72` | Délai sans alerte après une sortie confirmée. Les tapes sortent en ouvrant le tiroir, et Commvault les voit dans leur slot jusqu'au prochain inventaire de la TL. |

### Tests

```bash
vendor/bin/phpunit          # 88 tests : client Commvault, règles métier, synchro sur SQLite
```

Les migrations ont été validées sur MariaDB 11.4.

Le README complet (déploiement Ubuntu/Nginx, worker systemd, synchro) sera rédigé dans les phases suivantes.
