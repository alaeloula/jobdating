# Contre-revue du plan T-02 — Référentiels, version 2

**Date :** 16/11/2026. **Branche :** `feature/T-02-referentiels`.

`HEAD` et `develop` : `799d49af57db4b16c2a51f144b95fb82afd34847`. Le plan et les modifications documentaires sont non commités. Aucun développement T-02 n'est présent dans le diff examiné.

## 1. Périmètre examiné

- `AGENTS.md`, `CLAUDE.md` et la première revue `docs/reviews/REVIEW-PLAN-T-02.md`.
- `docs/tasks/PLAN-T-02.md`, version 2, lignes 1 à 92.
- `git diff develop` : `docs/DECISIONS.md` et `docs/BACKLOG.md` ; fichiers non suivis recensés. Les configurations locales et `mysql.session.sql` ne font pas partie de la validation fonctionnelle du plan.
- Décisions du 16 novembre, notamment `DECISIONS.md:28–31`, et point ouvert COMP-01, ligne 64.
- CDC V2 : ADM-09/RG-REF-01 à 03, modèle 8.2, ENT-05/PC-23, ADM-04, TRA-01 et matrice 3.2. Les nouvelles décisions remplacent les règles antérieures concernées, comme demandé.
- Code existant utile : permissions et rôles, routes API, `DatabaseSeeder`, `AuditLogger`, énumérations du journal, contrôleur de ses filtres, test des six catégories et alimentation du filtre frontend.

**Méthode :** lecture des documents et du code existant, inspection Git. Aucun test, migration, build, lint ou contrôle applicatif exécuté, conformément à la demande. Les scénarios ci-dessous sont des analyses de conception, pas des reproductions exécutées. Cette contre-revue ne valide aucune implémentation PHP/MySQL.

## 2. Rejeu des cinq constats précédents

| Nº | Constat | État | Preuve dans le plan v2 |
|---|---|---|---|
| 1 | Collision du nom `evaluation_tags` | **Traité** | Lignes 20, 25 et 28 : `classes` avec modèle `SchoolClass`, catalogue `evaluation_tags_ref`, association `evaluation_tags` réservée à T-31. |
| 2 | Identité des valeurs initiales après modification | **Partiel** | Lignes 59, 70 et 91 : `seed_key` fixe, unique et facultative ; conservation après renommage, désactivation et réordonnancement ; relance sans nouvelle entrée d'audit. Le cas nominal des lignes déjà munies d'une clé est traité. L'identification durable d'un homonyme manuel reste à définir, voir constat 1 ci-dessous. |
| 3 | Lecture des valeurs désactivées | **Traité** | Lignes 39–43, 65, 73 et 89 : options actives pour la saisie, restitution de la valeur liée même inactive, options historiques avec état ; e-mail de campus exclu. Les tâches consommatrices portent explicitement leurs tests d'affichage. |
| 4 | Intégration au journal T-06 | **Traité** | Lignes 47–51, 74, 90–91 : actions et catégorie explicites, libellé du sujet, transaction commune, réordonnancement, auteur Système et absence de journalisation lors d'une relance sans création, passage de six à sept catégories et contrôle du filtre. |
| 5 | Invariants et précision des tests | **Traité** | Lignes 34–36 et 82–92 : code définitif, clé classe → filière obligatoire et existante, points d'extension simulables, droits par opération, identifiants limités au référentiel, ordre par groupe, doublons, atomicité et tri stable. La nouvelle règle de confirmation remplace légitimement l'ancien refus absolu. |

« Traité » signifie que le contrat figure maintenant dans le plan ; cela ne préjuge pas du résultat des futurs tests.

## 3. Constats restant à traiter

### 1. À corriger avant développement — Mémoriser le traitement d'un homonyme manuel

**Références :** `PLAN-T-02.md:24–25`, `59`, `70` et `91` ; suite du constat nº 2 de la première revue.

**Nature :** contrat incomplet confirmé ; réapparition d'une valeur hypothétique, sans code à exécuter.

**Problème :** une ligne saisie manuellement peut avoir une `seed_key` nulle. Le plan demande de la laisser inchangée si son libellé correspond à une valeur initiale, mais ne dit pas comment mémoriser que la clé initiale a déjà été traitée. La clé reste manquante si le seeder se contente de passer cette ligne.

**Scénario :**

1. Saisir manuellement « Curieux », de type point fort, sans clé d'initialisation.
2. Lancer le seeder : l'homonyme est ignoré, conformément au plan.
3. Renommer et désactiver cette ligne à l'écran.
4. Relancer le seeder : ni `tag.curieux` ni le libellé initial ne permettent désormais de retrouver cette ligne. Une recherche des clés manquantes suivie d'une recherche par libellé peut recréer « Curieux » actif.

Le test annoncé vérifie l'homonyme et la modification d'une valeur initiale séparément ; il ne précise pas leur enchaînement. Par ailleurs, pour les étiquettes, l'identité métier est **type + libellé** : un « Curieux » manuel dans les points à améliorer ne doit pas empêcher l'initialisation du point fort homonyme.

**Impact :** conservation des corrections non garantie pour le cas manuel ; initialisation potentiellement incomplète si le rapprochement ignore le type.

**Correction minimale dans le plan :** définir le rapprochement des homonymes selon l'unicité de chaque catalogue et leur rattachement durable à l'identité initiale. Une option est d'autoriser l'affectation technique de `seed_key` une seule fois à l'homonyme, sans changer ses champs métier ; une autre consiste à attribuer cette clé dès sa création manuelle lorsqu'il correspond à une valeur initiale connue. Si « ne modifie jamais » interdit également tout rattachement technique ultérieur, préciser un autre mécanisme persistant de mémorisation. Réserver la clé au serveur et la rendre immuable après attribution.

**Test à ajouter au plan :** homonyme manuel → premier seeder → renommage/désactivation/réordonnancement → nouvelle relance, sans réintroduction de l'ancien libellé. Ajouter le cas d'un libellé identique dans l'autre type d'étiquette. Le rattachement technique éventuel ne doit pas être journalisé comme une création de valeur.

### 2. À corriger avant développement — Rendre atomiques les contrôles d'utilisation et la mutation

**Références :** `PLAN-T-02.md:35–36`, `49`, `71` et `86–87` ; `DECISIONS.md:30–31`. Branchement futur dans T-12/T-13 et T-31.

**Nature :** protection concurrente non définie dans le plan ; risque hypothétique, pas de défaut MySQL reproduit.

**Problème :** la transaction commune à la mutation et à l'audit est bien prévue. Le plan ne précise cependant pas comment le résultat du point d'extension reste valable jusqu'à la mutation, face à la première utilisation concurrente d'une classe ou d'une étiquette.

**Scénario classe :** une requête sans confirmation compte zéro apprenant. Une création ou un import affecte ensuite un apprenant à cette classe avant l'écriture de sa nouvelle filière. La première requête peut alors changer la filière d'un apprenant sans avoir demandé de confirmation.

**Scénario étiquette :** le contrôle constate qu'elle n'est pas utilisée ; T-31 l'associe à une évaluation avant que son type soit modifié. La règle « type modifiable seulement si aucune évaluation ne l'utilise » est alors contournée.

**Impact :** non-respect des deux décisions malgré des tests séquentiels réussis. Une transaction seule ne définit pas la coordination avec ces écritures concurrentes.

**Correction minimale dans le plan :** prévoir une coordination commune entre contrôle, mutation et première affectation : par exemple verrouiller la ligne de classe ou d'étiquette, refaire le contrôle sous ce verrou et conserver celui-ci jusqu'à la fin de la transaction. T-12/T-13 et T-31 devront respecter le même protocole lorsqu'ils créent les associations. Définir cette obligation dans le contrat des points d'extension ; le verrou d'un côté seulement ne suffit pas.

**Vérification future à prévoir :** scénarios concurrents sur MySQL lors du branchement des vraies relations, notamment première affectation d'un apprenant pendant une correction de filière et première utilisation d'une étiquette pendant un changement de type. Aucun test MySQL n'est demandé ni exécuté dans cette contre-revue documentaire.

### 3. Suggestion — Expliciter les cas négatifs du champ de confirmation

**Références :** `PLAN-T-02.md:35`, `86` et `92`.

**Nature :** précision du contrat, sans bug démontré.

**Scénario :** un client envoie une confirmation absente, fausse ou mal formée ; ou le PATCH contient aussi un autre champ alors que le changement de filière doit être refusé.

**Impact potentiel :** une conversion permissive pourrait considérer une chaîne comme un consentement, ou une écriture partielle pourrait précéder le 409.

**Ajout minimal dans le plan :** nommer le champ, définir la valeur valant confirmation explicite et prévoir les cas absent/faux/invalide. Un 409 doit laisser tous les champs inchangés et ne produire aucune entrée de changement dans le journal. Le serveur recalcule le nombre d'apprenants ; il ne fait pas confiance à un nombre envoyé par le client. Une confirmation explicite peut être envoyée directement : la décision n'exige pas un premier échange 409 obligatoire ni un mécanisme de jeton.

## 4. Questions au responsable technique

Les trois questions métier de la première revue sont **tranchées** : correction de filière avec confirmation, compétence inactive exclue des suggestions, changement de type d'étiquette limité aux valeurs inutilisées. Le code de filière définitif est également explicite.

Il ne reste pas de nouvel arbitrage métier identifié. Le choix du rattachement technique des homonymes doit être fixé dans le plan, sans modifier leurs données métier. COMP-01 demeure ouvert pour la saisie en masse et une éventuelle source CME ; il ne bloque pas le catalogue T-02.

## 5. Points validés sans remarque

- **Confirmation de filière :** le principe 409 avec nombre d'apprenants, consentement explicite puis modification journalisée est cohérent et testable. La lecture de la filière via la classe évite une copie contradictoire dans chaque apprenant. L'ancienne interdiction n'est plus une exigence à faire appliquer.
- **Points d'extension :** retour zéro avant l'existence des apprenants, contrôle simulable des étiquettes, absence de table factice et branchements obligatoires T-12/T-31 constituent un découpage sain. Les obligations de concurrence du constat 2 complètent ce contrat.
- **Lecture historique :** `?inactives=1` est acceptable pour tous les comptes connectés : il expose des valeurs de catalogue avec leur état, sans associations individuelles ni contenu d'évaluation. Les permissions et périmètres des ressources filtrées restent nécessaires. Ce paramètre ne permet pas de réaffecter une valeur inactive dans un formulaire.
- **Faisabilité des clés :** une clé technique facultative et unique dans chacun des deux catalogues est compatible avec MySQL et ne demande aucune dépendance supplémentaire. Plusieurs valeurs manuelles sans clé restent possibles. Une fois attribuée, la clé permet de conserver les corrections de libellé, type éventuel, état et ordre indépendamment de l'identité initiale ; seul le traitement des homonymes reste à compléter.
- **Journal :** le service et les filtres existants permettent l'extension prévue. Les catégories proviennent déjà de l'énumération côté API et alimentent le frontend. L'adaptation du libellé de sujet et du test à sept catégories est maintenant explicitement couverte.
- **Données initiales :** cinq opportunités et huit étiquettes conformes à ENT-05/PC-23 ; autres listes vides ; absence de compte créé ; relance sans écrasement des données métier et sans fausse création auditée.
- **Droits et compétences :** gestion réservée à l'administrateur et à la CME via la permission existante. Catalogue dans T-02, associations apprenant sans niveau et permission dédiée dans T-12, profils recherchés avec avertissement dans T-11, rapprochement filière **ou** compétence active dans T-40 : cohérents avec les décisions, qui priment sur ENT-03 et le modèle antérieur.
- **Périmètre :** aucune suppression, aucun développement des ressources consommatrices anticipé, ajout des classes inconnues conservé dans T-13, association des étiquettes réservée à T-31. Les opportunités pourront aussi servir au type final du suivi V2. Le critère global d'apparition des filières dans tous les formulaires et filtres reste à vérifier à la livraison de ces écrans, comme le plan le précise.

## 6. Verdict

**Plan à revoir.**

Quatre constats précédents sont traités ; celui du seeder reste partiel pour les homonymes manuels. Deux compléments ciblés sont nécessaires avant développement : mémoriser durablement leur identité initiale et définir la coordination des contrôles d'utilisation avec les affectations concurrentes. Les nouvelles décisions métier sont correctement reprises et ne sont pas remises en cause.

Seul ce rapport est créé. Aucun autre fichier modifié, aucun code ou test écrit, aucun commit ni push effectué.
