# TapeTrack: contexte pour Claude Code

Application de suivi des tapes Commvault de la KBR. Le cahier des charges complet a été fourni au début du projet ; ce fichier en garde l'essentiel.

## État

- **Phase 0 (découverte de l'API) : terminée.** Le mapping confirmé sur le CommServe de production et les décisions métier sont dans `docs/commvault-mapping.md`, sections 8 à 11. **À lire avant toute modification touchant à Commvault.**
- **Phase 2 (modèle + synchro) : terminée.**
  - Entités : `src/Entity`. Règles métier : `src/Domain`, documentées dans `docs/eject-rules.md`.
  - Synchro : `src/Sync/CommvaultSynchronizer.php`, commande `app:sync`, worker Messenger avec Scheduler.
  - Fixtures de démo : `tests/Fixtures/commvault/demo` (générées par `generate.py`, date de référence 14/10/2026).
- Prochaine étape : **Phase 3**, tableau de bord, vue TL et vue armoires. Viendront ensuite :
  - sessions de sortie et de retour ;
  - impression des fiches ;
  - admin et authentification ;
  - notifications.

## Règles du projet

- Stack : PHP 8.4, Symfony 7.4 LTS, Doctrine/MariaDB, Twig + Symfony UX, AssetMapper, Tailwind (symfonycasts). Déploiement sur Ubuntu avec Nginx et PHP-FPM.
- Code en anglais, interface en français. Dates au format `dd/mm/yyyy HH:mm`, fuseau Europe/Brussels, stockage en UTC.
- **Commvault en lecture seule, strictement.**
  - Tout passe par `CommvaultClientInterface` ; `ReadOnlyGuard` refuse tout le reste.
  - Ne jamais ajouter d'endpoint d'écriture.
  - Ne jamais désactiver la vérification TLS.
- L'interface ne lit que MariaDB. Une synchronisation périodique alimente la base.
- Secrets : Symfony secrets, ou secrets docker. Ne jamais logger de token ni de mot de passe.
- Ne pas inventer de comportement Commvault : poser la question à l'utilisateur.
- Commits atomiques. Pour chaque phase : résumé et hypothèses prises.

## Périmètre KBR

- CommServe : `bck-srv01`, base `/commandcenter/api`, compte de service `api_sa` avec le rôle View sur la CommCell.
- Une TL physique IBM 3573-TL de 80 slots, en 4 tiroirs, découpée en deux bibliothèques logiques :
  - **IBM VSP (36)** : seule gérée pour l'instant ;
  - IBM INFRA (26).
- Storage policies gérées : `SP_VSP_*`, par exemple `SP_VSP_Online-N` (138), dont la copie sur bande est `Master` (167). `PowerScale_*` correspond à l'ancien stockage.
- Les emplacements Commvault et le Vault Tracker ne sont pas utilisés. Le référentiel physique vit dans TapeTrack : site › local › armoire › étagère. Site externe : Perron-LesBinches.

## Commandes

```bash
vendor/bin/phpunit                                   # tests (client fake, SQLite, sans réseau) - PHP 8.4 requis
php bin/console doctrine:migrations:migrate          # schéma MariaDB
php bin/console app:sync                             # synchro Commvault -> MariaDB
php bin/console app:commvault:discover --list        # appels de découverte
php bin/console app:commvault:discover -vv           # découverte -> var/discovery/
php bin/console app:commvault:discovery-summary      # structure des dumps -> var/discovery/_schema.md
```

Sous Windows avec WSL Containers : `.\docker\wslc.ps1 build|test|discover|summary`.

`var/discovery/` contient des données réelles (noms de clients) : ne jamais le committer (il est déjà dans `.gitignore`, via `var/`).
