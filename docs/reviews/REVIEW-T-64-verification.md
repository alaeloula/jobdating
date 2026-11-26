# Vérification T-64 — Constats N1 et N2

- **Date :** 26/11/2026, fuseau `Africa/Casablanca`.
- **Branche :** `feature/T-64-import-entreprises`.
- **Base et HEAD :** `develop`, `c3f0365bba108d0a75a7d0f7dc783fc0d587d073` ; modifications non commitées.
- **Périmètre :** corrections N1/N2 et effets de bord dans `CompanyImportPage.tsx` et `companyImport.test.tsx`. Références : prompt de vérification, `AGENTS.md`, `CLAUDE.md`, contre-revue précédente et `PROGRESS.md:511`. Diff avec `develop` et fichiers non suivis concernés examinés ; les décisions métier restent inchangées.

Aucun fichier de code modifié, aucun commit ni push. Seul ce rapport est créé. Les bancs complémentaires utilisent des données fictives et une configuration temporaire hors dépôt. Aucun document réel ouvert ; exclusions précédentes respectées. **Aucun test backend lancé**, conformément au prompt ; aucune intervention sur les bases.

## Rejeu de N1 et N2

| Constat | Statut | Preuve et résultat |
|---|---|---|
| **N1 — Secrets réaffichés après réouverture pendant la suppression** | **Corrigé** | `deletingIds` conserve l'état par identifiant d'import malgré le changement de vue. `showSheets()` refuse une nouvelle demande pour cet import et son contrôle `fresh()` inclut cet état. Le test du dépôt vérifie la réouverture pendant le DELETE : bouton des fiches désactivé, aucun GET de fiches supplémentaire, aucun secret après succès. Un banc complémentaire conserve aussi un ancien GET de fiches en vol avant la suppression, rouvre le même import, termine le DELETE puis livre l'ancienne réponse : aucun secret réaffiché. |
| **N2 — Double suppression produisant un faux échec** | **Corrigé** | La garde synchrone `deletingIds.current.has(item.id)` précède la confirmation et la demande ; l'identifiant est réservé avant le premier `await`. Le bouton de suppression est désactivé pendant l'opération. Le test rejoué observe **un seul DELETE** après deux clics et aucune alerte erronée. Le retour après un véritable échec est également vérifié ci-dessous. |

Les contrôles d'affichage, les gardes des fonctions et la génération des demandes se complètent : la correction ne repose pas uniquement sur l'attribut `disabled` du bouton.

## Effets de bord vérifiés

Cinq scénarios complémentaires passent, en plus des sept tests du dépôt :

- **Ancienne réponse de fiches :** demande avant suppression, réouverture du même import, succès du DELETE puis arrivée des anciennes fiches ; absence du secret.
- **Suppression en échec puis nouvel essai :** réponse 503 dans la vue d'origine, message d'erreur visible, boutons réactivés ; seconde tentative réussie, erreur effacée et actions retirées après actualisation du rapport.
- **Passage de A vers B, suppression de A réussie :** les fiches de B restent affichées et aucun message de A ne pollue B.
- **Passage de A vers B, suppression de A en échec :** l'erreur de A n'est pas affichée dans B ; ses fiches restent disponibles.
- **Relecture tardive :** le DELETE de A réussit, sa relecture est retardée, puis B est ouvert ; l'arrivée du rapport de A ne remplace pas B.

`currentIdRef` permet de limiter l'effacement de fin de suppression à l'import alors affiché. Le contrôle de génération sur la relecture protège le changement ultérieur de rapport. Aucun défaut de confidentialité supplémentaire n'a été reproduit dans ces scénarios.

Un sixième banc complémentaire révèle toutefois le défaut mineur suivant.

## Nouveau constat

### V1. Mineur — Les actions restent proposées si la relecture échoue après une suppression réussie

**Fichier et lignes :** `frontend/src/pages/CompanyImportPage.tsx:253`, `:256`, `:260`, `:267`, `:433`, `:437`.

**Nature : bug confirmé par Vitest/jsdom à réponses HTTP simulées.** L'assertion demandant l'absence du bouton « Afficher les fiches » échoue après un DELETE 204 et une relecture 503.

**Scénario reproductible :**

1. Ouvrir un import terminé dont `has_credentials` vaut `true` et supprimer ses mots de passe.
2. Faire réussir le DELETE : le message « Mots de passe de l'import supprimés » s'affiche.
3. Retarder, puis faire échouer la relecture du rapport, par exemple avec une réponse 503.
4. Les actions « Afficher les fiches » et « Supprimer les mots de passe » restent proposées sur le rapport courant : son état local n'a pas été modifié au succès du DELETE.

**Cause :** la relecture est lancée sans être attendue ; son échec est ignoré par `.catch(() => undefined)`. Le `finally` libère immédiatement l'état de suppression, alors que `current.has_credentials` reste vrai jusqu'à une relecture réussie. Le rafraîchissement de l'historique ne met pas à jour ce rapport sélectionné.

**Impact :** interface contradictoire après succès ; une nouvelle demande peut être refusée par le serveur puisque les identifiants ont déjà été supprimés. L'état persiste jusqu'à une réouverture ou un rechargement du rapport si la relecture échoue. **Aucun réaffichage de secret ni défaut de suppression en base n'est établi** par ce constat.

**Correctif minimal proposé, sans l'appliquer :** dès le succès du DELETE, mettre localement `has_credentials` à `false` et `credentials_deleted` à `true` pour l'import concerné, avant de libérer l'état de suppression. Conserver la relecture comme rafraîchissement complémentaire, sans en faire la condition de retrait des actions. Ajouter le scénario « DELETE 204, relecture 503 » au test de régression.

**Rare et sans impact sur les tâches suivantes ?** Occasionnel, lors d'une panne ou d'une latence de relecture après succès du DELETE. Impact limité à cette interface, sans dépendance technique aval ni atteinte à la confidentialité identifiée. Un report explicite dans `ERREURS_A_CORRIGER.md` est possible sur décision du responsable technique ; aucun report n'est appliqué ici.

## Validation et limites

**Environnement :** Node **24.21.0**, Vitest **5.1.0**, DOM simulé jsdom. Les données des bancs sont fictives ; aucun contrôle navigateur ou réseau réel n'est revendiqué.

| Contrôle exécuté | Résultat |
|---|---|
| `npm.cmd exec -- vitest run src/companies/companyImport.test.tsx`, dans `frontend/` | **7 tests réussis**, code de sortie 0. |
| Même fichier, avec six scénarios injectés en mémoire par une configuration Vite temporaire hors dépôt | **12 réussites, 1 échec** : les 7 tests existants et les 5 scénarios complémentaires décrits ci-dessus passent ; le scénario V1 échoue sur l'absence attendue des anciennes actions. Sources et tests du dépôt inchangés. |

**Communiqués par Claude, non rejoués :** frontend lint, **121 tests** et build réussis ; nouveau test vérifié rouge sans le correctif, puis correctif rétabli. Référence : `PROGRESS.md:514` et `:515`. Aucune modification temporaire du code ni vérification sans le correctif effectuée par moi.

La suite frontend complète, lint et build n'ont pas été relancés. Aucun test backend, nouvelle revue globale de T-64, contrôle d'impression ou mesure de performance effectué dans cette vérification limitée.

## Verdict

**À fusionner après corrections mineures.** N1 et N2 sont corrigés et leurs scénarios passent. Reste à actualiser immédiatement l'état local après succès de la suppression pour que l'échec de sa relecture ne laisse pas les anciennes actions disponibles.
