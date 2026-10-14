# Règles « à sortir », « à rentrer » et fiches

Implémentation : `src/Domain/EjectPolicy.php`, `ReturnPolicy.php`, `CycleGrouper.php`, `MediaAlerts.php`.
Tests : `tests/Unit/Domain/`, `tests/Integration/SynchronizerTest.php`.

Ces règles sont recalculées à chaque synchronisation, uniquement pour les bibliothèques gérées (`TAPETRACK_MANAGED_LIBRARIES`).

## 1. Tape « à sortir »

Les conditions sont évaluées dans l'ordre ; la première qui s'applique décide.

| # | Condition | Résultat | Raison affichée |
|---|---|---|---|
| 1 | Commvault voit la tape hors de la TL (`isExported = true`) | reste | Hors de la TL selon Commvault |
| 2 | TapeTrack la considère déjà sortie (état confirmé ≠ « Dans la TL » / « Remise dans la TL ») | reste | Déjà sortie selon TapeTrack (une **alerte** de divergence est levée) |
| 3 | Statut sans données à garder : `Spare`, `Aged`, `Cleaning`, `Bad` | reste | Pas de données à conserver |
| 4 | Aucune storage policy copy associée | reste | Aucune storage policy copy associée |
| 5 | **Tape montée dans un lecteur** | **reste** | Montée dans le lecteur X |
| 6 | `Full Media` et règle `on_full` ou `both` | **à sortir** | Tape pleine |
| 7 | Règle `on_job_complete` ou `both`, et **aucun job en cours** sur la storage policy | **à sortir** | Job terminé (tape non pleine), ou Tape pleine |
| 8 | Règle `on_job_complete` ou `both`, et un job en cours sur la storage policy | reste | Un job est en cours sur la storage policy |
| 9 | Sinon (règle `on_full`, tape non pleine) | reste | Pas encore pleine |

Les tapes concernées par la règle de job terminé sont celles en statut `Full`, `Active` ou `Appendable`.

La règle se choisit **par storage policy copy** : `on_full`, `on_job_complete`, ou `both` (par défaut). La synchro ne la modifie jamais.

### Cas limites

- **« Une tape avec un job en cours n'est jamais à sortir. »** Cette règle est assurée deux fois :
  - par le lecteur : `DriveList` de `GET /Library/{id}` donne la tape montée et les jobs du lecteur ;
  - par la policy : `GET /Job?jobCategory=Active` donne les jobs en cours.
- **Une tape pleine non montée** est proposée à la sortie avec la règle `both`, même si un autre job tourne sur la même policy : ce job écrit sur une autre tape. Avec `on_job_complete` seul, elle attend la fin du job.
- **Jobs en cours illisibles** (erreur API) : par prudence, toutes les policies sont considérées comme occupées. Aucune tape non pleine n'est alors proposée, et un avertissement est enregistré dans la synchro.
- **Le statut `Active Media` n'est pas un indicateur d'écriture en cours.** Vu sur le CommServe : 33 tapes « Active » pour seulement 4 lecteurs. Seuls le lecteur et les jobs actifs comptent.
- **Une tape `Appendable` sortie** ne sera plus complétée : Commvault en prendra une autre. C'est la conséquence voulue de `on_job_complete` / `both`. Pour l'éviter sur une copie, choisir `on_full`.
- **Une tape « Remise dans la TL »** (`returned`) suit les mêmes règles qu'une tape « Dans la TL ».

## 2. Tape « à rentrer »

Une tape est à rentrer quand Commvault la voit **hors de la TL** et que son statut est **`Aged Media`**, c'est-à-dire que Commvault l'a recyclée.

- La rétention n'est **jamais calculée** par TapeTrack : seul le statut Commvault fait foi.
- Une tape hors TL dont la date de rétention est dépassée mais qui n'est pas encore `Aged` relève de l'information seulement (`ReturnPolicy::retentionPassedNotAged`). Elle n'est pas à rentrer.
- Le lieu où la récupérer est son emplacement confirmé : boîte ou site externe.

## 3. Fiches (« backups »)

Une fiche regroupe les tapes d'**une storage policy copy** qui partagent la **même date de rétention**. C'est le jour calendaire à Bruxelles de `retainDataUntil` (`TAPETRACK_CYCLE_GROUPING=retention`, réglage par défaut).

| Colonne de la fiche | Source |
|---|---|
| Retention | `retainDataUntil` des tapes (Commvault) |
| BackUp | début du premier job `Full` (ou `Synthetic Full`) de la copie dont `retainUntil` tombe le même jour |
| LastWrite | `lastWriteTime` de chaque tape ; les lignes sont triées sur cette valeur |

Les anomalies sont affichées, mais ne bloquent jamais :

- **Aucun Full avec cette date de rétention** : le Full le plus récent démarré avant la première écriture est proposé, avec la mention « à vérifier ».
- **Aucun Full du tout** : la date « BackUp » reste vide.
- **Tape écrite avant le Full du cycle.**
- **Mode `full_to_full`** : une tape appartient au dernier Full démarré avant sa dernière écriture. Les tapes d'un même cycle peuvent alors avoir des rétentions différentes ; c'est signalé, et les tapes écrites avant tout Full forment un cycle « indéterminé ».

### Relation job ↔ tape

Elle est **déduite**, car l'API REST de Commvault ne l'expose pas : un job de la copie dont la rétention tombe le jour de la fiche est relié aux tapes écrites après son démarrage. Ce lien est marqué `inferred` (`JobMedia`).

## 4. États physiques et divergences

États confirmés par une personne (jamais écrasés par la synchro) :

`in_library` → `ejected` → `stored` / `in_transit` → `offsite` → `returned`.

Une tape vue pour la première fois prend l'état `in_library` si Commvault la voit dans la TL, sinon `stored`, avec un emplacement à renseigner.

Alertes :

- Commvault voit la tape dans la TL alors qu'elle est marquée sortie, rangée ou hors site.
  - Les tapes sortent **en ouvrant le tiroir**, sans export Commvault : Commvault les voit encore dans leur slot jusqu'au prochain inventaire de la TL.
  - L'alerte n'est donc levée qu'après `TAPETRACK_INVENTORY_GRACE_HOURS` (72 h par défaut) suivant la sortie confirmée.
- Commvault la voit hors de la TL alors qu'elle est marquée « dans la TL » (sortie non confirmée), ou « remise » (inventaire à faire ?).
- `Bad Media`.
- Tape absente de la dernière synchronisation.
