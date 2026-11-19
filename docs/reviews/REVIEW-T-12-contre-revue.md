# Contre-revue du code — T-12 (JD-17), apprenants

- **Date :** 19/11/2026.
- **Branche :** `feature/T-12-apprenants`, modifications non commitées.
- **Base :** `develop`, `d6cb56f66417bd882ef521f55c897347411768a7`.
- **Rapport initial :** `docs/reviews/REVIEW-T-12-apprenants.md`, conservé.
- **Verdict : À fusionner après corrections mineures.** Les cinq scénarios initiaux sont corrigés. Deux effets de bord mineurs restent à traiter, décrits comme constats 6 et 7 pour conserver la numérotation du suivi.

## Périmètre

Contre-revue limitée aux cinq corrections, à la décision « CNIE visible par l’administrateur, la CME et la Direction » et à leurs effets de bord. Lecture de `AGENTS.md`, `CLAUDE.md`, des fichiers concernés par le diff et non suivis, de `DECISIONS.md` et du paragraphe de correction T-12 de `PROGRESS.md`.

La nouvelle décision prime sur RG-APR-06 et l’ancienne décision 3 du plan : CNIE complète pour les trois rôles autorisés, recherche comprise, sans journalisation de chaque consultation ; entreprise exclue ; CNIE masquée dans l’audit ; téléphone réservé à l’administrateur. Ce choix n’est pas remis en cause.

Exclusions respectées : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`. Aucun réexamen complet de T-12 ni réouverture d’E-01 à E-04. Aucun fichier de code ou de test modifié, aucun commit ni push. Le présent rapport est le seul fichier créé pendant cette contre-revue.

## Rejeu des cinq constats

| # | Scénario initial rejoué | Résultat obtenu | Statut |
|---|---|---|---|
| 1 | Requête sans `ids` ni `filters`, avec les autres paramètres valides, sur le comptage et les deux actions | **422 pour les trois routes**, aucun compte désactivé, aucune association ni ligne d’audit ajoutée. `filters: {}` reste accepté : comptage 200, puis exécution explicite réussie ; synthèse avec `mode: filtre`. Contrôle désormais exécuté dans `LearnerController::selection()`, ligne 174. | **Corrigé** |
| 2 | Suppression logique validée par une seconde connexion après la lecture des candidats et avant le verrou du compte | **409, eligible: 0**, compte effectivement supprimé, aucune compétence ajoutée, aucun audit de masse. Les comptes sont maintenant verrouillés avant les fiches dans `bulkAddCompetence()`. Un autre cas de concurrence est signalé au constat 6. | **Corrigé pour le scénario initial** |
| 3 | Comptage retardé, puis Annuler ; variante changement de filtre et sélection de la nouvelle page avant l’ancienne réponse | Le dialogue reste fermé après réception de l’ancienne réponse ; aucun envoi d’exécution. Deux comptages reçus dans l’ordre inverse conservent également le résultat le plus récent. `bulkRequest` est invalidé par `closeBulk()` et les changements de sélection. | **Corrigé** |
| 4 | Recherche en erreur 503, puis nouvel essai | Alerte « Serveur indisponible », bouton « Réessayer », aucun faux « Aucun apprenant ». Après succès : résultats affichés et alerte supprimée. | **Corrigé** |
| 5 | CNIE valide suivie de LF ou de tabulation envoyée par HTTP | **422 dans les deux cas**, sans reprise de la valeur dans l’erreur. Le test ciblé couvre aussi le LF au milieu. Exclusion limitée à `cnie` dans `bootstrap/app.php:32`. | **Corrigé** |

Les rejeux backend ont utilisé MySQL ; les rejeux frontend ont utilisé le composant réel dans un DOM simulé, avec réponses différées.

## Vérification de la nouvelle décision CNIE

| Rôle | Liste et fiche | Recherche exacte normalisée | Téléphone / identifiant CME |
|---|---|---|---|
| Administrateur | CNIE complète, 200 | Autorisée | Champs présents |
| CME | CNIE complète, 200 | Autorisée | Champs absents |
| Direction | CNIE complète, 200 | Autorisée | Champs absents |
| Entreprise | Routes T-12 refusées, 403 | Route refusée, 403 | Aucune fiche renvoyée |
| Apprenant | Routes T-12 refusées, 403 | Route refusée, 403 | Aucune fiche renvoyée |

- `Role.php` accorde désormais `learners.view_cnie` à la CME et à la Direction, en plus de l’administrateur. La ligne correspondante de `PermissionMatrixTest` reflète ces trois droits.
- `LearnerResource` conditionne la CNIE à cette permission ; téléphone et identifiant CME restent conditionnés au rôle administrateur. Le frontend utilise aussi la permission pour la colonne et l’indication de recherche.
- `LearnerFilters` autorise la comparaison CNIE exacte aux détenteurs de la permission. Les tests ciblés ont exercé la recherche normalisée pour les trois rôles sur le schéma normal de test.
- `show()` ne crée plus d’événement de consultation. Dans le banc, liste et fiche n’ajoutent aucune ligne d’audit, pour chacun des trois rôles autorisés.
- Aucun appel ni cas d’enum `learner.cnie_viewed` / `LearnerCnieViewed` restant dans le code applicatif examiné. La catégorie CNIE reste proposée par `/audit-logs/filters`. La lecture des anciennes lignes de cette action pose toutefois le problème du constat 7.
- La CNIE reste cachée par défaut dans le modèle `Learner`. Avec les permissions actuelles, aucune exposition entreprise n’a été identifiée pour les futurs T-23/T-24 : refus des routes T-12 et omission par la ressource pour ce rôle. Ces futures routes devront conserver leur contrôle de périmètre et leur sérialisation autorisée.
- Les snapshots d’audit gardent la clé `cnie` traitée par `AuditLogger`. Le banc n’a trouvé aucune CNIE fictive en clair dans les valeurs d’audit ; le test ciblé correspondant réussit. Les synthèses conservent le mode et les descriptions sans recherche libre.
- Les chemins de validation et de conversion des collisions SQL ne réinjectent pas la valeur : le contrôle de `unique()` déjà reproduit pendant la revue initiale n’a pas changé. Les collisions SQL forcées ne sont pas rejouées dans cette contre-revue ; la lecture du code et les tests ciblés d’erreur complètent les résultats précédents.

## Effets de bord et qualité des tests

### Verrous

Le verrou **partagé** sur la compétence est compatible avec le contrôle de clé étrangère d’un ajout individuel. Le verrou du compte puis de la fiche s’aligne désormais avec `updateCompetences()`.

Le banc a vérifié deux situations distinctes :

- suppression du compte validée par l’autre connexion : relecture correcte, 409 et aucune association ;
- tentative de désactivation de la compétence pendant l’opération : la seconde connexion attend le verrou puis atteint son délai d’attente ; l’ajout peut terminer avec la compétence encore active. Le verrou partagé protège donc bien ce scénario.

En revanche, une association déjà créée par l’autre opération n’est pas toujours vue par le contrôle final du pivot : constat 6. Aucun interblocage n’a été observé dans ces scénarios ; cela ne prouve pas leur absence dans toutes les combinaisons.

### Réponses frontend et exclusion de `TrimStrings`

Les réponses de comptage périmées sont ignorées après annulation, changement de filtre/sélection et remplacement par une nouvelle demande. Le traitement d’erreur de la recherche permet de revenir à un état normal après un succès.

L’exception de nettoyage porte uniquement sur le champ nommé `cnie` ; elle ne désactive pas `TrimStrings` pour la requête entière. Aucun changement de normalisation des mots de passe, jetons ou autres champs n’est ajouté par cette correction. Les autres routes qui utiliseraient ce nom de champ devront laisser le contrat commun `Cnie` s’appliquer, comme prévu pour T-13.

### Nouveaux tests

- **Absence de sélection :** les assertions 422 sur les trois endpoints et les compteurs d’effets de bord détectent bien le défaut initial ; le cas `filters: {}` empêche de corriger en interdisant toute sélection globale.
- **Suppression entre les lectures :** le test ajoute l’état supprimé sur la même connexion. Il vérifie un résultat utile mais ne démontre pas les effets des instantanés entre deux transactions. Son déclenchement dépend aussi du texte des requêtes et du verrou partagé. Le rejeu de l’auditeur utilise une seconde connexion et confirme la correction du scénario initial ; il révèle séparément le constat 6.
- **Annulation et erreur de recherche :** les huit tests du fichier frontend réussissent. Claude indique avoir retiré temporairement les corrections et vu échouer les deux nouveaux tests : résultat communiqué, non reproduit par modification de fichiers pendant cette contre-revue. Les bancs différés de l’auditeur confirment les états observables, y compris changement de sélection et réponses dans l’ordre inverse.
- **CNIE :** les cas LF/tabulation sont désormais exercés par HTTP ; les tests de droits ont été adaptés à la décision nouvelle. Ils ne confondent plus l’ancien masquage administrateur avec le comportement attendu.

## Nouveaux constats

### 6. Mineur — Ajout individuel concurrent : erreur 500 au lieu du recomptage attendu

- **Fichier et lignes :** `backend/app/Services/Learners/LearnerService.php:383`, `:389` et `:401`.
- **Nature :** bug confirmé sur MySQL 8.0.46, isolation par défaut `REPEATABLE-READ`, avec une seconde application PHP utilisant une connexion indépendante.
- **Scénario reproductible :** commencer un ajout en masse avec un éligible attendu. Après la lecture des candidats et avant les verrous de compte, la seconde application exécute réellement `LearnerService::updateCompetences()` pour ajouter cette même compétence au même apprenant, puis valide sa transaction. Cet appel individuel réussit. Reprendre l’action en masse : elle tente encore `attach()`. Résultat observé : violation d’unicité du pivot, SQLSTATE **23000**, et **HTTP 500** sur `/learners/bulk-competences`, au lieu d’un 409 avec zéro éligible.
- **Cause et impact :** le verrou de compte ajouté corrige la suppression concurrente, mais ne suffit pas à rendre courante la lecture du pivot effectuée par la sous-requête `NOT EXISTS`. Dans ce scénario, celle-ci ne voit pas l’association validée après la première lecture. La transaction de masse est annulée ; l’association et l’audit légitimes de l’opération individuelle restent conservés. **Aucune corruption ou perte d’association n’a été observée**, mais le parcours normal de recomptage est remplacé par une erreur serveur.
- **Correctif minimal proposé :** après les verrous des comptes et fiches, déterminer les associations présentes avec une lecture courante/verrouillée du pivot, puis recalculer les éligibles et appliquer `confirmCount()` avant tout ajout. Ajouter le test à deux connexions « ajout individuel validé entre candidats et verrou final → 409, sans écriture de masse ».
- **Fréquence et suite du projet :** rare, lorsque deux opérations ajoutent simultanément la même compétence. Aucun blocage technique des tâches suivantes identifié ; le défaut reste circonscrit à une action de masse annulée. Un report dans T-49 est envisageable uniquement sur décision explicite du responsable technique.

### 7. Mineur — Les anciennes consultations CNIE rendent une page du journal illisible

- **Fichier et lignes :** retrait de l’action dans `backend/app/Enums/AuditAction.php:34` ; interaction avec le cast de `backend/app/Models/AuditLog.php:37` et la sérialisation de `backend/app/Http/Resources/AuditLogResource.php:28`.
- **Nature :** bug de compatibilité confirmé avec une ligne historique fictive, créée uniquement dans les tables de contrôle.
- **Scénario reproductible :** conserver une ligne `audit_logs.action = learner.cnie_viewed` émise par la version précédente de T-12, puis consulter une page du journal qui la contient avec le nouveau code. Dans le banc, `/audit-logs` retourne **200 avant**, puis **500 après** l’ajout de cette ligne historique. L’accès à son attribut `action` lève un **`ValueError`**, car la valeur n’existe plus dans `AuditAction`.
- **Impact :** une base ayant servi à utiliser l’ancienne version de T-12 peut conserver ces consultations ; la page du journal contenant une telle ligne ne peut plus être affichée. Une installation n’ayant jamais exécuté cette version n’est pas concernée. Le journal étant en ajout seul, supprimer ou réécrire silencieusement ces lignes n’est pas une correction appropriée.
- **Correctif minimal proposé :** tolérer la valeur historique lors de la lecture/sérialisation du journal, avec un libellé historique, tout en gardant l’absence de nouvelles écritures de consultation. Ajouter une régression avec une ancienne ligne suivie d’un `GET /audit-logs` réussi. Cette compatibilité de lecture ne réintroduit pas la journalisation supprimée par la nouvelle décision.
- **Fréquence et suite du projet :** condition particulière aux bases conservant l’historique de cette branche T-12 non encore livrée ; aucun nouveau traitement métier n’est bloqué sur une installation neuve. Sur une base concernée, le défaut persiste tant que l’ancienne ligne est sur la page consultée. Un éventuel report doit être explicite et tenir compte de ce besoin de lecture du journal.

## Validation et limites

### Exécuté pendant cette contre-revue

- **Backend : Docker, PHP 8.4.27, MySQL 8.0.46, `jobdating_test`, aucun SQLite.** `php artisan test --do-not-cache-result tests/Feature/Learners/LearnerTest.php` avec filtre ciblant droits par rôle, nouvelle visibilité CNIE, erreurs CNIE, audit masqué et les deux régressions backend : **17 tests réussis, 130 assertions**, environ 56 secondes. Journalisation redirigée et vues temporaires hors dépôt.
- **Frontend : Node 24, Vitest 5.1.0**, fichier `src/learners/learners.test.tsx` : **8 tests réussis**. Chargement natif de la configuration, cache de résultats désactivé, cache temporaire hors dépôt supprimé.
- **Bancs backend en mémoire :** schéma et contraintes copiés dans 31 tables préfixées, sans copier les données existantes. Application, contrôleurs et services réels, fixtures fictives. Deux connexions pour suppression et verrou de compétence ; second processus Laravel exécutant le véritable `updateCompetences()` pour le constat 6. Tables supprimées après chaque banc ; contrôle final `SHOW TABLES LIKE 'rv12_%'` vide.
- **Bancs frontend en mémoire :** composant réel, DOM JSDOM et réponses API différées. Annulation, changement de filtre puis sélection d’une nouvelle page, résultats de comptage reçus dans l’ordre inverse, erreur de recherche et nouvel essai.
- **`git diff --check develop` : réussi.** Rapport initial conservé, aucun fichier existant modifié par l’auditeur.

La recherche CNIE est validée par les tests ciblés sur le schéma normal de test ; les tables préfixées servent notamment au contrôle des ressources de liste/fiche sans recherche, des écritures et de la concurrence. Les reproductions historiques ne prouvent pas qu’une ligne ancienne existe dans la base de développement : cette base n’a pas été consultée. Elles démontrent le comportement si cette donnée émise par l’ancienne version est conservée.

Les bancs JSDOM ne valident pas le CSS ni un navigateur réel. Les entrelacements MySQL contrôlés ne constituent pas une campagne exhaustive de concurrence. Aucun contrôle de charge, lint, build, Pint ou PHPStan relancé pendant cette contre-revue ; aucune migration ni seeder sur les données de développement.

### Communiqué par Claude, non rejoué intégralement

- Pint et PHPStan sans erreur.
- Suite backend complète Docker : **410 tests réussis, 2 073 assertions**, environ 3 min 45, dont 37 dans `LearnerTest` ; **suite complète non relancée** par l’auditeur. Seul le sous-ensemble de 17 tests indiqué ci-dessus a été rejoué.
- Frontend : lint réussi, **80 tests réussis**, build réussi ; seuls les huit tests ciblés ont été rejoués.
- Les deux nouveaux tests frontend échouent sans les corrections : vérification communiquée par Claude.
- Mesure H2 : **12,7 secondes pour 2 000 comptes**, résultat de la revue précédente de l’auditeur repris dans `PROGRESS.md` ; **mesure non répétée** ici.

## Verdict

**À fusionner après corrections mineures.** Les cinq défauts initiaux sont corrigés et la nouvelle décision CNIE est correctement appliquée. Traiter le recomptage après un ajout individuel concurrent et la lecture des anciennes lignes d’audit, ou faire acter explicitement leur report si les conditions locales le justifient. Aucun nouveau défaut majeur, fuite de CNIE ou perte de données confirmé dans ce périmètre.
