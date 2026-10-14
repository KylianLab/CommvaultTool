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

Le README complet (déploiement Ubuntu/Nginx, worker systemd, synchro) sera rédigé dans les phases suivantes.
