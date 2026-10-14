# Mapping API REST Commvault → données TapeTrack (Phase 0)

> **Statut : hypothèses à valider sur dumps réels.**
> Version cible : Commvault 11.40 (FR40 / SP40). Accès strictement **lecture seule**.

## 0. Sources et niveau de confiance

Depuis l'environnement de développement, **documentation.commvault.com, api.commvault.com et le Swagger du Command Center n'étaient pas joignables**. Le mapping s'appuie sur :

| Code | Source |
|---|---|
| **SPEC** | SDK généré par Commvault depuis sa spec OpenAPI3 (`Commvault/CVPowershellSDKV2` : `OpenAPI3.yaml`, code et DLL générés, févr. 2026) |
| **SDK** | `Commvault/cvpysdk` (SDK Python officiel, août 2026), `Commvault/commvault-mcp-server`, `CVPowershellSDK` v1 |
| **DOC** | pages officielles 11.40 / SP40, vues uniquement via des extraits de moteur de recherche |
| **?** | non vérifié (supposition) |

Rien de ce document n'a encore été vérifié sur votre CommServe. C'est l'objet de `app:commvault:discover`, qui appelle chaque endpoint candidat. Chaque ligne « Probe » ci-dessous correspond à une clé de la commande.

---

## 1. Authentification

| Mode | Requête | Utilisation | Confiance |
|---|---|---|---|
| `login` (défaut) | `POST /Login` `{"username":"api_sa","password":"<base64>"}` → `token` (déjà préfixé `QSDK …`) | En-tête `Authtoken: <token>`. Sur HTTP 401, nouveau login une seule fois. `POST /Logout` en fin de découverte. | SDK |
| `access_token` | Jeton créé dans Command Center : *Manage › Security › Users › api_sa › Access tokens* | En-tête `Authorization: Bearer <accessToken>`. Renouvellement par `POST /V4/AccessToken/Renew` `{"accessToken","refreshToken"}` → nouvelle paire. | DOC + SDK |

Points d'attention :

- **Les access tokens existent en 11.38 et 11.40** : le mode `access_token` est donc disponible. Je le recommande, car il évite de stocker le mot de passe d'`api_sa`.
- **Durée de vie** : 30 min selon la doc 11.40. Le renouvellement reste possible 14 jours après expiration, et une chaîne de renouvellements dure 90 jours maximum (extensible à 365).
- **Le refresh token est à usage unique.** Réutiliser une ancienne paire invalide toute la chaîne. L'application :
  - persiste la dernière paire dans `var/commvault/access-token.json` (mode 0600) ;
  - sérialise les renouvellements avec un verrou de fichier, partagé par le worker, cron et le web.
- Commvault signale un échec de login par **HTTP 200 + `errList[0].errLogMessage`**. Ce cas est géré.
- Commvault répond en **XML** sans `Accept: application/json`. Cet en-tête est toujours envoyé.
- `GET /WhoAmI` (probe `auth.whoami`) sert de test de validité du jeton.

**Base URL** : `https://<cs>/commandcenter/api/` (celle du SDK) ou `https://<cs>/webconsole/api/` (legacy). Ce sont les mêmes chemins sous les deux. Le choix se fait via `COMMVAULT_BASE_URL`.

---

## 2. Storage policies et copies

| Donnée | Endpoint (probe) | Chemin JSON | Confiance |
|---|---|---|---|
| Liste des SP | `GET /StoragePolicy?getAll=TRUE` (`sp.list`) | `policies[].storagePolicyId`, `policies[].storagePolicyName` | SDK |
| idem (V2) | `GET /V2/StoragePolicy` (`sp.list_v2`) | `policies[].storagePolicy.{storagePolicyId,storagePolicyName}` | SDK |
| Copies d'une SP | `GET /StoragePolicy/{id}` (`sp.detail`) | `copy[].StoragePolicyCopy.{copyId,copyName}`, `copy[].copyType`, `copy[].isDefault`, `copy[].copyPrecedence`, `copy[].active`, `copy[].library.libraryName` | SDK |
| Primaire / auxiliaire | idem | primaire si `isDefault == 1` ou `copyPrecedence == 1`. `copyType` : 1 synchrone, 2 sélective, 100 primaire… | SDK |
| Détail d'une copie | `GET /V2/StoragePolicy/{spId}/Copy/{copyId}` (`sp.copy`) | `copy.library.{libraryId,libraryName}`, `copy.retentionRules.retainBackupDataForDays` / `…ForCycles`, `copy.retentionRules.extendedRetentionRuleOne…`, `copy.selectiveCopyRules.selectiveRule` | SDK + DOC |
| Copies vues comme « plans » | `GET /V4/Plan/BackupDestinations` (`plan.destinations`) | à découvrir | SPEC |
| **Copie partant sur site externe** | ❌ aucun champ fiable | à configurer dans l'admin TapeTrack. Indice possible : `library.export_locations` / Vault Tracker. | — |

---

## 3. Jobs par storage policy copy

| Donnée | Endpoint (probe) | Chemin JSON | Confiance |
|---|---|---|---|
| **Jobs d'une copie** (recommandé) | `GET /V4/Plan/BackupDestination/{copyId}/Jobs?view=custom&startTime=…&endTime=…&agedData=2` (`jobs.on_copy`) | `jobs[].jobId`, `jobs[].status`, `jobs[].startTime`, `jobs[].endTime` (epoch), `jobs[].server{id,name}` (client), `jobs[].subClient{id,name}`, `jobs[].backupSet`, **`jobs[].retainUntil`** (epoch), `jobs[].isAged`, `jobs[].retentionReason`, `jobs[].copiedTime` | SPEC + SDK |
| Filtre « Full » sur cette copie | même endpoint + `backupLvl=1` **ou** `backupLevel=1` (les deux orthographes sont testées) | valeurs : 1 Full, 2 Incr, 4 Diff, 64 Synth Full | SPEC / SDK (orthographe incertaine) |
| Type de job (Full/Incr/Diff/Synth) | `GET /Job?completedJobLookupTime=…&jobCategory=All` (`jobs.list`), `POST /Jobs` (`jobs.search`) | `jobs[].jobSummary.backupLevelName` ou `localizedBackupLevelName`, `jobSummary.subclient.{clientName,subclientName}`, `jobSummary.jobStartTime` / `jobEndTime`, `jobSummary.status`, `jobSummary.storagePolicy.{storagePolicyId,storagePolicyName}` | SDK + DOC |
| Un job | `GET /Job/{jobId}` (`jobs.summary`), `POST /JobDetails` (`jobs.details`) | `jobs[0].jobSummary…`, `job.jobDetail.{generalInfo,detailInfo,progressInfo}` | SDK |
| Rétention d'un job par copie | `GET /Job/{jobId}/AdvancedDetails?infoType=1` (`jobs.retention`) | `jobRetention.storagePolicyRetentionInfoList[].copyRetentionInfoList[].{storagePolicyCopyId,retentionDays,status,manuallyPinned}`. Date absolue ? | DOC + SDK |
| Jobs d'une copie (repli) | QCommand `qoperation execscript -sn QS_JobsinSPCopy -si @i_policyName='…' -si @i_copyName='…'` (`qcmd.jobs_in_copy`) | `ExecScriptOutput.FieldValue[]` ; colonnes inconnues | SDK |

⚠️ La réponse de `jobs.on_copy` **ne contient pas le niveau de backup**. Deux façons de l'obtenir :

- appeler le même endpoint filtré sur `backupLvl` / `backupLevel` ;
- joindre sur `jobId` avec `GET /Job`.

---

## 4. Médias (tapes)

| Donnée | Endpoint (probe) | Chemin JSON | Confiance |
|---|---|---|---|
| Bibliothèques à bandes | `GET /V4/Storage/Tape` (`library.tape_list`) | `tapeList[].{id,name,storageType,status}` | SPEC |
| Détail TL, lecteurs | `GET /V4/Storage/Tape/{libraryId}` (`library.tape_detail`) | `drives[].{name,barCode,mountStatus}` (tape montée) | SPEC |
| **Tapes dans la TL** | `GET /V4/Library/{libraryId}/Media?filterMediaType=ALL&isExported=false` (`media.in_library`) | `mediaDetailsList[]` (détail ci-dessous) | DOC + SPEC + SDK |
| **Tapes hors TL** | même endpoint avec `isExported=true` (`media.exported`) | idem | idem |
| Tapes aged | `filterMediaType=AGED` (`media.aged`) | idem | idem |
| Slots | `GET /V4/Library/{libraryId}/Slot?slotType=REGULAR_SLOT` (`media.slots`), `MAIL_SLOT` (`media.mail_slots`) | `slotList[].{slot{id,name},slotType,mediaId,mediaDetails}` | DOC + SPEC |
| Vault Tracker | `GET /V4/VaultTracker/Media` (`media.vault_tracker`) | `vaultTrackerMediaDetailsList[].{barcode,isInLibrary,currentLocation,destLocation,dueBackForReuseOn}` | DOC + SPEC |
| Lieux d'export | `GET /V4/Storage/Tape/Locations` (`library.export_locations`) | `locations[].{id,name,isTransit}` | SPEC |

Champs de `mediaDetailsList[]` (types issus du SDK) :

| Donnée TapeTrack | Champ |
|---|---|
| Identifiant Commvault (clé d'upsert) | `mediaId` |
| Barcode | `barcode` |
| Statut | `status` : chaîne, par ex. `"Appendable Media"`. **Liste des valeurs inconnue.** Voir aussi `filterMediaType`, `spareGroupType`, `isInMediaGroup`. |
| Library | `libraryId`, `libraryName` |
| Dans / hors TL | `isExported` |
| **Slot** | `slot.{id,name}`. Pas de champ « element address » explicite : le nom du slot est le meilleur candidat. |
| En lecteur | `drive.{id,name}` |
| Conteneur (export) | `container.{id,name}` |
| SP / copie | `storagePolicy.{id,name}`, `storagePolicyCopy.{id,name}` |
| **LastWrite** | `lastWriteTime` (epoch) |
| **Rétention / aging** | `retainDataUntil` (epoch) |
| Volume écrit | `storedDataBytes` |

Il n'existe pas d'endpoint « toutes les tapes du CommCell » : il faut boucler sur les bibliothèques. Il faut aussi deux appels par bibliothèque, un pour `isExported=false` et un pour `isExported=true`.

---

## 5. Relation job ↔ médias

**Aucun endpoint REST trouvé**, ni dans le sens job → tapes, ni dans le sens tape → jobs. `JobDetails` ne semble pas lister les médias ; la probe `jobs.details` le vérifiera.

Seule piste en lecture seule : les **QCommands `qlist`** via `POST /ExecuteQCommand`.

| Besoin | QCommand (probe) | Sortie |
|---|---|---|
| Tapes d'un job | `qlist media -j <jobId>` (`qcmd.media_of_job`) | une ligne par média : barcode, location, library, status… (texte) |
| Tapes d'une copie | `qlist media -sp "<SP>" -spc "<copie>"` (`qcmd.media_of_copy`) | idem |
| Tapes d'une library | `qlist media -l "<library>"` (`qcmd.media_of_library`) | idem |
| Détail d'une tape | `qinfo media -b <barcode>` (`qcmd.media_info`) | syntaxe exacte non vérifiée |

`ExecuteQCommand` peut exécuter n'importe quelle commande, y compris des commandes qui modifient. `ReadOnlyGuard` n'autorise donc que :

- les commandes qui commencent par `qlist media`, `qlist quickmedia` ou `qinfo media` ;
- le script de reporting `QS_JobsinSPCopy` ;
- une seule commande à la fois : pas de `;`, `|`, `&`, `<`, `>`, backtick ni retour à la ligne.

Tout le reste est refusé **avant** l'envoi, et c'est couvert par les tests.

Si les QCommands sont refusées ou inexploitables, voici le repli prévu :

- tape → copie, via `mediaDetailsList[].storagePolicyCopy` ;
- copie → jobs, via `jobs.on_copy` ;
- la relation exacte est alors approximée par la fenêtre temporelle `lastWriteTime` de chaque tape, et signalée comme telle dans l'UI.

---

## 6. Données introuvables via l'API (gérées par TapeTrack)

| Donnée | Pourquoi | Traitement prévu |
|---|---|---|
| Tiroir (magazine) d'un slot | Commvault ne connaît que les slots | mapping plages de slots → tiroir dans l'admin |
| Element address SCSI | seulement `slot.name` / `slot.id` | à confirmer avec les dumps (`media.slots`) |
| Armoire / boîte de rangement | physique | référentiel TapeTrack |
| Copie « site externe » | pas d'attribut dédié | case à cocher par copie dans l'admin (avec pré-remplissage via Vault Tracker si utilisé) |
| États `ejected` / `stored` / `offsite` / `returned` | physique | cycle de vie TapeTrack + audit |
| Cycle (Full → Full) | notion métier | calculé à partir des jobs Full de la copie (voir questions) |
| Fiches imprimées | — | `CycleSheet` versionnée |

---

## 7. Comment lancer la découverte

```bash
# .env.local (sur une machine du réseau interne)
COMMVAULT_CLIENT=http
COMMVAULT_BASE_URL=https://<commserve>/commandcenter/api
COMMVAULT_AUTH_MODE=login            # ou access_token
COMMVAULT_CA_BUNDLE=/etc/ssl/certs/ca-interne.pem

php bin/console secrets:set COMMVAULT_PASSWORD   # (ou COMMVAULT_ACCESS_TOKEN + COMMVAULT_REFRESH_TOKEN)

php bin/console app:commvault:discover --list    # ce qui sera appelé, sans réseau
php bin/console app:commvault:discover -vv       # tout
php bin/console app:commvault:discover --only=media --library-id=3   # ciblé
```

Options utiles :

- `--days=120` : fenêtre des jobs ;
- `--samples=2` : nombre d'IDs essayés par endpoint dépendant ;
- `--job-id`, `--storage-policy-id`, `--copy-id`, `--barcode`… : forcent un ID, par exemple un job dont on sait qu'il est sur bande.

Les résultats arrivent dans `var/discovery/<horodatage>/` :

- un fichier JSON par appel, avec le même nom que les fixtures du client fake ;
- `_report.md` et `_report.json`, qui résument statut HTTP, taille et clés de premier niveau.

Les champs qui ressemblent à des secrets (`token`, `password`…) sont masqués automatiquement. Les noms de clients, de serveurs et d'utilisateurs **ne le sont pas** : anonymisez-les avant de me les transmettre, en gardant les barcodes et les dates.

---

## 8. Questions ouvertes (à trancher avant la Phase 2)

1. **Statuts média** : quelles valeurs de `status` correspondent à « pleine », « appendable », « aged », « scratch » ? Les dumps `media.*` donneront la liste réelle.
2. **Rétention de la fiche** : quelle source prendre ?
   - `retainDataUntil` de la tape ;
   - `retainUntil` du Full sur la copie.
   Proposition : la tape, avec une alerte si les deux divergent.
3. **Fin de cycle** : sur une copie « Online N », les Full de tous les clients/subclients tombent-ils le même week-end, ou faut-il un cycle par subclient ? Le premier Full rencontré ouvre-t-il le cycle ?
4. **QCommands** : `api_sa` a-t-il le droit d'appeler `ExecuteQCommand`, et l'usage de `qlist media` (lecture seule) est-il acceptable chez vous ?
5. **Site externe / Vault Tracker** : des export locations ou des politiques Vault Tracker sont-elles configurées dans Commvault ?
6. **TL IBM** : quel modèle (TS4300 ?), combien de slots par tiroir, et les slots sont-ils nommés comme l'element address ?
