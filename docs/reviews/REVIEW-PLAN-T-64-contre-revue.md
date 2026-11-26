# Contre-revue du plan T-64 — Import des entreprises par fichier

- **Date :** 25/11/2026, fuseau `Africa/Casablanca`.
- **Documents :** `docs/tasks/PLAN-T-64.md`, version 1.2, et `docs/tasks/CURRENT_TASK.md`.
- **État vérifié :** branche `develop`, HEAD `c3f0365bba108d0a75a7d0f7dc783fc0d587d073` ; documents non commités. La branche `feature/T-64-import-entreprises` reste à créer avant le développement.
- **Nature :** contre-revue du plan, avant écriture du code. Les contradictions documentaires sont constatées ; les conséquences à l'exécution sont des scénarios à tester, pas des bugs T-64 reproduits.

## Périmètre et références

Relecture limitée aux cinq constats de `REVIEW-PLAN-T-64.md`, aux corrections de la version 1.2 et à leurs effets de bord. Lecture du prompt, d'`AGENTS.md`, de `CLAUDE.md`, du plan, de la tâche courante et du diff documentaire avec `develop`, ainsi que des fichiers non suivis pertinents. Les décisions 1 à 10 sont appliquées sans être remises en cause ; les décisions 9 et 10 sont également inscrites dans `DECISIONS.md`.

Références ciblées : CDC ADM-04, RG-ENTR-03 à 07, ADM-07 et TRA-01 ; décisions du 25/11 ; code existant de `CompanyService`, `MyCompanyController`, `EventParticipationService`, suppression d'événement, connexion, ouverture de session et changement de mot de passe. Lecture ciblée du guard et du fournisseur d'utilisateurs Laravel installés pour vérifier le rehachage.

Seul ce rapport est créé. Aucun code, plan ou autre document modifié ; aucun commit ni push. Aucun classeur réel ouvert, aucun classeur fictif nécessaire à cette lecture. Exclusions respectées : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.

## Rejeu des cinq constats

Le « résultat » ci-dessous désigne la couverture du scénario dans le plan, sans exécution d'une implémentation T-64.

| Constat initial | Statut | Scénario et preuve dans la version 1.2 |
|---|---|---|
| **1. Concurrence sur le nom simplifié** | **Partiel** | Pour deux imports « AcmeServices » / « Acme Services », ou un import face à une création ou un renommage manuel, le §4 impose désormais un verrou exclusif commun pris en premier, et le §6 une revalidation sous ce verrou. Le §11 prévoit les trois essais à deux connexions MySQL avec preuve d'attente. Le mécanisme répond au doublon initial, mais l'ordre général compte → entreprise contredit les opérations existantes : nouveau constat 2. |
| **2. Changement de mot de passe non détecté par la version** | **Corrigé** | Après changement depuis le profil, le hachage enregistré sur le compte diffère de celui conservé chiffré par l'import : exclusion au §7, lignes 89–90. Le marquage réexamine cette condition sous verrous entreprise → compte, ligne 93. Le §11 reprend changement personnel, réinitialisation administrative et réinitialisation concurrente au marquage. La version seule n'est donc plus utilisée comme preuve de validité. |
| **3. Décodage des valeurs exclues par OpenSpout** | **Corrigé** | Le §3 remplace sa réutilisation par un lecteur XML dédié et définit les seules valeurs autorisées au décodage, avant matérialisation des cellules inutiles. Le §11 demande des sentinelles partagées, en ligne, dans l'autre feuille et dans une chaîne inutilisée, contrôlées au niveau du lecteur. L'architecture proposée répond au défaut initial ; sa réalisation devra prouver cette frontière. Voir la suggestion 3 sur les cas XML à préciser. |
| **4. Normalisation et réservation dans le lot** | **Corrigé** | §§4–5 : lettres et chiffres, rejet de la clé vide, réservation seulement après une ligne confirmée et valide, candidats de connexion réservés après troncature à 50 caractères, comptes supprimés compris. Le §11 reprend ces cas. Une nouvelle incohérence de longueur du stockage apparaît cependant : constat 1 ci-dessous. |
| **5. Emplacement absent des fiches réimprimées** | **Corrigé** | §7, ligne 91 : emplacement actuel de la participation à l'événement de l'import, message si absent, aucun remplacement par l'événement courant. Le §11 prévoit l'emplacement ajouté après l'import. Les décisions 9 et 10 définissent les cas supprimé et retiré. |

## Effets de bord examinés

### Verrous et durée

Le verrou global des noms, pris avant le verrou partagé d'événement, convient à la coordination des imports et des écritures manuelles du nom. L'index non unique préserve les homonymes manuels autorisés. Le hachage préalable hors transaction évite de conserver ce verrou pendant les calculs bcrypt des 1 000 comptes.

L'import conserve néanmoins le verrou pendant toutes ses écritures et celles du journal : les créations et renommages manuels attendront. Le §11 prévoit une mesure au plafond avec le coût réel ; y inclure la durée de détention du verrou, l'attente d'une création manuelle et le comportement lorsque l'attente dépasse le délai MySQL. Aucune durée acceptable n'est démontrée à ce stade. Les trois reprises ne constituent pas une mesure de capacité.

Pour les comptes et entreprises **nouvellement insérés**, compte puis entreprise correspond à `CompanyService::create`. Pour une entreprise **existante**, renommage, réinitialisation et marquage ne doivent pas inverser entreprise → compte : constat 2. T-14 verrouille l'événement avant modification des participations ; ne pas ajouter une acquisition du verrou des noms après celui d'événement dans ces chemins.

### Migration et écritures du nom

Le remplissage des entreprises existantes est explicitement prévu au §4 et doit employer la même fonction que création, renommage et planification. Il reste à résoudre sa représentation et sa longueur : constat 1. Les homonymes existants ne bloquent pas un index non unique.

`MyCompanyController::update`, lignes 25–40, n'autorise que description et site web : aucun chemin de renommage par l'entreprise n'a été identifié. Il n'est donc pas nécessaire de lui ajouter le verrou des noms.

### Hachage et connexion

Le risque de rehachage automatique a été confronté au chemin réellement utilisé. `LoginController`, lignes 44–47, fait `Hash::check`, puis `SessionOpener::open`, ligne 25, appelle `Auth::guard('web')->login($user)`. Il n'appelle pas `attempt()`, qui déclencherait le rehachage du fournisseur Laravel. **Changer le coût configuré ne modifie donc pas à lui seul le hachage d'un compte existant lors de cette connexion.** Aucun défaut actuel de ce type n'est retenu.

Si un rehachage technique est ajouté ultérieurement, l'égalité stricte des hachages invalidera aussi les fiches dont le secret reste identique. C'est une limite de ce témoin à documenter pour cette évolution, pas un motif pour rouvrir la décision métier ni imposer maintenant des centaines de vérifications bcrypt à chaque impression.

### Événement et fiches

Le §6 prévoit `event_id` avec `nullOnDelete` et le nom d'événement conservé. Déclarer effectivement la colonne nullable lors de l'implémentation. L'historique doit subsister, sans empêcher une suppression autorisée par T-14. Événement supprimé avant traitement : échec propre ; supprimé après traitement : fiches sans contexte d'événement, avec la mention décidée.

Les deux questions initiales sont closes : compte désactivé exclu et compté ; participation retirée conservée sans emplacement, avec avertissement. Attention à la réutilisation ciblée de T-14 : `EventParticipationService::credentialsContext`, lignes 271–275, refuse aujourd'hui une participation absente pour un événement explicite. Le nouveau parcours devra appliquer la branche « retirée » déjà prévue par le plan, sans reprendre ce refus tel quel.

## Nouveaux constats

### 1. Majeur — La clé normalisée peut dépasser les 64 caractères de stockage annoncés

**Référence :** `docs/tasks/PLAN-T-64.md:51`, `:53`, `:63` ; §4 « Noms » et §5 « Aperçu ». Référence existante : `backend/database/migrations/2026_11_19_100000_create_companies_tables.php:22`, nom sur 150 caractères ; `backend/app/Http/Controllers/CompanyController.php:236`, validation correspondante.

**Nature :** incohérence confirmée entre les dimensions du plan. L'échec SQL décrit est un risque futur, non exécuté ; aucun code de migration T-64 n'existe. Aucun condensat ni autre encodage de longueur fixe n'est défini.

**Scénario :** une entreprise existante ou une ligne confirmée porte un nom de 100 lettres ASCII. Ce nom satisfait la limite de 150 caractères ; sa clé, définie comme la chaîne simplifiée elle-même, fait encore 100 caractères. Une colonne de 64 caractères ne peut pas la conserver intégralement. Le problème concerne aussi le remplissage des données existantes par la migration. Tronquer la clé confondrait deux noms distincts ayant le même préfixe de 64 caractères.

**Impact :** échec possible de migration, de création ou du lot complet sur une valeur pourtant valide ; faux rapprochement si la longueur est corrigée par une troncature silencieuse.

**Correction minimale dans le plan :** définir explicitement la représentation persistée. Soit sa capacité couvre toute la normalisation des noms admis, translittérations comprises ; soit les 64 caractères désignent un condensat hexadécimal SHA-256 de la chaîne normalisée complète. Dans ce second cas, tester le vide **avant** le condensat et employer exactement cette représentation dans le remplissage initial, les écritures et les comparaisons. Garder l'index non unique. Ajouter les cas >64 caractères, limite 150, préfixe commun et remplissage d'entreprises existantes.

**Rare et sans impact sur les tâches suivantes ?** Non démontré. Un nom long est une entrée autorisée et le changement touche aussi le fonctionnement existant de T-11 et le déploiement de la migration. À corriger avant développement.

### 2. Majeur — L'ordre général des verrous inverse celui des entreprises existantes

**Référence :** `docs/tasks/PLAN-T-64.md:54`, §4, face à `:93`, §7. Code : `backend/app/Services/Companies/CompanyService.php:123`, `:138`, `:245`, `:246`.

**Nature :** contradiction confirmée du plan ; interblocage hypothétique si l'ordre annoncé est appliqué au renommage. Aucun essai concurrent T-64 exécuté.

**Scénario :** le §4 applique à la création **et au renommage manuel** l'ordre noms → événement → compte → entreprise. Un renommage conforme à cet ordre prend le compte puis attend l'entreprise. Au même instant, `resetPassword()` détient déjà l'entreprise et attend ce compte. Chaque transaction attend l'autre. Le même ordre inverse peut entrer en conflit avec le marquage groupé entreprise → compte explicitement conservé au §7. Le renommage existant, lui, verrouille d'abord l'entreprise puis met à jour le compte.

**Impact :** interblocages évitables entre opérations normales, reprises et, si elles sont épuisées, échec de l'opération. Aucune perte de données n'est démontrée : les reprises et transactions restent utiles, mais ne rendent pas cohérents deux ordres inverses.

**Correction minimale dans le plan :** distinguer les insertions de nouveaux objets des verrous sur des lignes existantes. Conserver compte puis entreprise pour les insertions ; pour le renommage, ajouter le verrou des noms **avant** le chemin existant entreprise → compte, sans l'inverser. Garder l'événement en amont lorsqu'il est nécessaire. Confirmer cette règle pour réinitialisation et impression ; compléter les essais à deux connexions avec renommage contre réinitialisation et contre marquage groupé. Aucun refactoring général des services n'est nécessaire.

**Rare et sans impact sur les tâches suivantes ?** Le chevauchement est occasionnel, mais concerne des actions administratives prévues et modifie un service existant. Il ne peut pas être classé comme rare et sans impact ; corriger le contrat avant développement.

### 3. Suggestion — Préciser les variantes XML dans les essais du lecteur sélectif

**Référence :** `docs/tasks/PLAN-T-64.md:43`, `:44`, `:45`, `:124`, `:125` ; §§3 et 11.

**Nature :** précision de conception et de couverture ; aucun défaut de lecteur confirmé, puisqu'il n'est pas implémenté.

**Scénario :** les en-têtes eux-mêmes sont des chaînes partagées. Il faut résoudre leurs indices pour connaître les deux colonnes utiles avant de parcourir leurs données. Un nom utile peut aussi être réparti dans plusieurs fragments de texte riche `<r>` ; une autre cellule peut contenir le même texte en chaîne en ligne.

**Impact potentiel :** un lecteur trop spécialisé pourrait manquer une colonne ou tronquer un nom ; un raccourci consistant à charger toutes les chaînes partagées réintroduirait le constat initial 3.

**Correction minimale dans le plan :** expliciter la résolution sélective des en-têtes avant les données, même si elle nécessite une passe supplémentaire. Ajouter des fixtures fictives avec en-têtes partagés, plusieurs fragments riches et chaînes en ligne équivalentes. Préciser la résolution des parties via les relations du classeur, les chemins internes sûrs et le refus des cibles externes ; conserver les bornes ZIP et les sentinelles. Ces cas ne nécessitent aucune dépendance supplémentaire.

**Rare et sans impact sur les tâches suivantes ?** Les chaînes partagées et riches sont des variantes normales d'Excel. Ce complément reste local au lecteur T-64 ; ce n'est pas un bug à reporter dans `ERREURS_A_CORRIGER.md`.

## Validation et limites

- **Réalisé :** lecture statique ciblée, comparaison avec la revue initiale, contrôle de branche/HEAD, diff documentaire et inventaire des fichiers non suivis ; vérification des chemins de verrouillage et de connexion dans le code installé.
- **Non exécuté :** tests applicatifs, bancs MySQL, classeurs fictifs, mesures de mémoire ou de durée, migrations, lint et analyse statique. Aucun code T-64 n'existe ; aucun résultat d'exécution T-64 n'est fourni par ce rapport. Les tests énumérés dans le plan restent à écrire et exécuter.
- Les contrôles proposés couvrent désormais concurrence réelle, rollback du journal, idempotence, nettoyage, confidentialité et cycle des fiches. Leur présence dans le plan ne prouve pas leur réussite. Cette revue ne constitue aucune approbation du code ni garantie de délai au 25/12.

## Verdict

**Plan à revoir.** Les cinq constats initiaux ont reçu une réponse, dont quatre sont corrigés au niveau du plan. Avant développement, résoudre la représentation de `name_key` et l'ordre des verrous sur les entreprises existantes. Les décisions 9 et 10 n'appellent pas de nouvelle question au responsable technique.
