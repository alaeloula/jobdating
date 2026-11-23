# Revue du code T-13 — Import des apprenants

- **Date :** 20/11/2026.
- **Branche vérifiée :** `feature/T-13-import`, modifications non commitées.
- **Base et HEAD :** `develop`, `81f9d028a15bb1f15c589b2344cf1fbffa94b97e`.
- **Contrat :** `docs/tasks/PLAN-T-13.md`, version 2.1, décisions du 20/11 et CDC V2 après application des décisions.
- **Verdict : À corriger avant fusion.** Les contrôles ciblés passent, mais neuf écarts ont été reproduits : quatre majeurs et cinq mineurs.

## Références et périmètre

Revue effectuée selon `PROMPT-CODEX-REVIEW-T-13.md`, `AGENTS.md` et `CLAUDE.md` : diff par rapport à `develop` **et fichiers non suivis** de T-13. Les exclusions demandées n'ont pas été examinées. Aucun fichier réel d'apprenants n'a été recherché ou utilisé.

Références : plan 2.1, rapports de revue du plan, décision « Import des apprenants (T-13) », ADM-04/RG-IMP-01 à 07, ADM-03, TRA-01, EXI-DATA-03 et entrée T-13 de `PROGRESS.md`, notamment ses limites. Les décisions sur la classe choisie, les comptes inactifs, les téléchargements répétés et les reports entreprises/événement sont appliquées. E-01 à E-04 ne sont pas rouverts.

Fichiers examinés : lecteur et temporaires, analyse/confirmation/traitement d'import, job, modèle/migration/ressource/contrôleur, export des mots de passe, purge, modifications de `LearnerService`, permissions/audit/routes/configuration de file, Docker/CI, page/API/navigation frontend et tests concernés. Aucun correctif n'a été appliqué. Les deux rapports précédents sont conservés.

## Constats

### 1. Majeur — Une CNIE en double peut être importée si l'autre ligne présente déjà une erreur

**Fichier et ligne :** `backend/app/Services/Imports/LearnerImportPlanner.php:185` (`rejectDuplicates`).

**Nature : bug confirmé**, reproduit avec le service d'aperçu sur MySQL 8.

**Scénario :** fournir deux lignes avec la même CNIE fictive `ZZ900101`. La première est valide ; la seconde contient `pas-un-mail` comme e-mail. Résultat obtenu : **une création et un rejet**, avec les décisions `create`/`reject`. Le contrôle des doublons ignore les lignes ayant déjà un motif de rejet. La première ligne reste donc importable.

**Référence et impact :** RG-IMP-02 et le plan §2 exigent le rejet des **deux** lignes d'une CNIE en double. Le résultat dépend ici de la validité des autres champs, alors que le fichier présente une identité ambiguë. Le même filtrage fragilise le contrôle H2 des e-mails en double.

**Correctif minimal :** rechercher les doublons sur toutes les identités normalisées admissibles, indépendamment des autres erreurs de ligne, puis marquer toutes les occurrences concernées. Ajouter le cas « doublon + autre champ invalide » pour la CNIE et l'e-mail. Ce cas ne se limite pas à une concurrence rare et touche directement la fiabilité des comptes importés.

### 2. Majeur — La limite de 50 000 chaînes partagées XLSX est contournable

**Fichier et ligne :** `backend/app/Services/Imports/YouCodeFileReader.php:258`.

**Nature : bug confirmé**, reproduit avec un XLSX fictif lu par OpenSpout dans Docker.

**Scénario :** produire un classeur contenant **50 001 entrées** de chaînes partagées, sans attribut `count`/`uniqueCount` dans `sharedStrings.xml`. Le fichier de contrôle faisait **136 014 octets**, avec une seule ligne d'apprenant. Résultat obtenu : **lecture acceptée**. Le code ne contrôle qu'un attribut trouvé dans les 4 096 premiers octets du XML ; l'absence ou la falsification du compteur évite le refus.

**Référence et impact :** décision 12 et plan §1 : au-delà de 50 000 chaînes, le classeur doit être refusé avant la lecture des lignes. Ce plafond n'est pas réellement appliqué. La borne distincte de 50 Mo décompressés reste présente ; aucune saturation de mémoire ou de processeur n'a été reproduite.

**Correctif minimal :** compter les entrées réelles en lecture XML progressive, en s'arrêtant dès la 50 001e, avant de confier le classeur à OpenSpout. Un compteur déclaré peut servir d'indice, pas de preuve. Tester un compteur absent, sous-déclaré et placé après les premiers 4 096 octets.

### 3. Majeur — Changer un choix pendant l'aperçu bloque définitivement le bouton

**Fichier et ligne :** `frontend/src/pages/LearnerImportPage.tsx:58` et `:80`.

**Nature : bug confirmé**, reproduit sur le composant réel avec React/jsdom et une promesse d'aperçu différée.

**Scénario :** lancer « Voir l'aperçu », changer la classe pendant la requête, puis sélectionner à nouveau une classe valide. L'ancienne réponse est correctement ignorée. Cependant, `change()` invalide son numéro de demande sans réinitialiser `pending`, et son `finally` n'a ensuite plus le droit de le remettre à `false`.

Résultat obtenu : choix tous valides, aucun aperçu périmé affiché, mais **bouton toujours désactivé** ; le clic de nouvel essai ne déclenche aucune deuxième requête.

**Impact :** le parcours reste bloqué jusqu'au rechargement de la page. Le test existant de réponse tardive passe car il ne vérifie que la disparition de l'aperçu, sans essayer un nouvel aperçu.

**Correctif minimal :** libérer l'état d'attente lorsque la demande courante est invalidée, tout en conservant la protection contre les réponses périmées. Appliquer le même contrat à « Annuler/Recommencer » et vérifier qu'une ancienne requête ne libère pas une nouvelle requête en cours. Étendre le test jusqu'au nouvel essai réussi.

### 4. Majeur — L'historique ne permet pas d'ouvrir le rapport ni de reprendre le suivi

**Fichier et ligne :** `frontend/src/pages/LearnerImportPage.tsx:295` ; `frontend/src/learners/importApi.ts:80`.

**Nature : bug confirmé**, par lecture et rendu de l'historique d'un import échoué.

**Scénario :** confirmer un import en attente, quitter la page comme le propose le message, puis revenir. `current` est perdu. L'historique affiche le fichier, le statut et quelques nombres, mais aucune action ne charge `GET /learner-imports/{id}` pour ouvrir le rapport ou suivre l'import. Pour un ancien import échoué, le banc affiche **zéro lien/bouton d'accès au rapport**, aucun motif de rejet et aucune région de résultat.

Le rapport courant n'affiche pas non plus sa liste de rejets après perte de l'aperçu. Enfin, l'API d'historique est paginée à 20 éléments, mais le frontend ne conserve que `data`, sans navigation vers les pages suivantes.

**Référence et impact :** ADM-04 prévoit un rapport final disponible ; plan §6/§9 : historique avec accès au rapport. Après une navigation ou un rechargement, l'administrateur ne peut plus consulter les détails d'un import passé ni rejoindre son suivi. Les imports au-delà des 20 plus récents sont également inaccessibles dans cet écran.

**Correctif minimal :** ajouter un accès explicite à chaque rapport, charger son détail et afficher ses rejets/son erreur ; réutiliser le suivi pour un import encore ouvert. Conserver les informations de pagination et permettre l'accès aux autres pages. Aucun changement d'API métier n'est nécessaire : les routes de détail existent.

### 5. Mineur — Un CSV au point-virgule avec titre est refusé

**Fichier et ligne :** `backend/app/Services/Imports/YouCodeFileReader.php:231`.

**Nature : bug confirmé**, reproduit avec le lecteur dans Docker.

**Scénario :** ligne 1 `Export YouCode School Admin`, en-têtes complets séparés par `;` en ligne 2, puis une ligne valide. Résultat : exception `ImportFileException`, indiquant que tous les en-têtes sont manquants. Le séparateur est déterminé depuis la première ligne, qui est ici un titre sans séparateur : le code choisit la virgule.

**Référence et impact :** plan §1 : séparateur détecté sur la ligne d'en-têtes, en ligne 1 ou 2. Cette combinaison de formats autorisés est inutilisable. Le CSV à virgule et le CSV au point-virgule sans titre restent utilisables ; aucun impact direct sur les tâches suivantes.

**Correctif minimal :** identifier la ligne d'en-têtes parmi les deux premières en essayant les séparateurs admis, puis employer le séparateur correspondant. Ajouter le cas combiné ; les tests actuels couvrent les deux propriétés séparément.

### 6. Mineur — La purge peut annuler un import qui vient de démarrer

**Fichier et ligne :** `backend/app/Console/Commands/CleanupImports.php:29` et `:32` ; `backend/app/Services/Imports/LearnerImportRunner.php:112`.

**Nature : bug de concurrence confirmé**, reproduit avec deux processus et deux connexions MySQL.

**Scénario :** la purge sélectionne un import resté en attente 25 minutes. Avant son appel à `markFailed()`, le worker le prend en charge et renseigne `started_at`. La purge ne recontrôle alors que l'appartenance aux états ouverts, et non l'ancienneté correspondant au nouvel état.

Résultat obtenu : import pris en charge depuis **environ 0,59 seconde**, pourtant passé en **échec**, `payload` effacé ; le worker ne peut plus le terminer.

**Référence et impact :** plan §5 [v2.1] : un traitement légitime dispose de sa durée à partir de `started_at`. Ce cas est rare, à l'intersection du démarrage et de la purge. L'import doit être relancé ; aucune création partielle n'a été observée et il n'y a pas d'impact direct sur les données des tâches suivantes.

**Correctif minimal :** inclure statut **et ancienneté correspondante** dans la transition atomique de purge, ou les recontrôler sous verrou dans la transaction qui effectue cette transition. Conserver la protection des états terminaux. Ajouter ce scénario concurrent au test de nettoyage.

### 7. Mineur — Les lignes « inchangées » ne sont jamais revalidées par le job

**Fichier et ligne :** `backend/app/Services/Imports/LearnerImportService.php:74` ; `backend/app/Services/Imports/LearnerImportRunner.php:130`.

**Nature : écart de contrat confirmé**, reproduit sur MySQL.

**Scénario :** confirmer un fichier avec A « inchangé » et B à créer. Supprimer logiquement le compte A avant le traitement. Les lignes inchangées sont exclues du `payload` ; le job ne peut donc plus vérifier leur état. Résultat obtenu : import **terminé**, B créé et compteur « inchangé » toujours à 1, malgré la suppression de A.

**Référence et impact :** plan §5 : revalidation de chaque ligne sous les verrous, notamment des fiches supprimées, et échec global si une ligne confirmée est devenue invalide. Le rapport final peut ici présenter un état non revalidé. Le compte supprimé n'est pas ressuscité : le défaut concerne le contrat de confirmation et le rapport, sans corruption observée de l'historique.

**Correctif minimal :** conserver les données utiles minimales permettant aussi de revalider les lignes inchangées, sous forme chiffrée comme les autres lignes valides ; les verrouiller et les vérifier, sans les réécrire. Garder le refus de confirmer lorsque le fichier entier est inchangé. Ajouter le cas de suppression après confirmation dans un fichier mixte.

### 8. Mineur — L'erreur de suivi reste affichée après la reprise du suivi

**Fichier et ligne :** `frontend/src/pages/LearnerImportPage.tsx:111` et `:114`.

**Nature : bug confirmé**, reproduit avec deux réponses successives du suivi sur le composant réel.

**Scénario :** le premier suivi échoue, puis le suivant renvoie un import terminé. Résultat obtenu : le succès « Import terminé » apparaît, mais l'alerte « Le suivi de l'import est momentanément indisponible ; nouvel essai dans quelques secondes » reste présente. La réponse valide ne retire pas l'erreur précédente.

**Impact :** retour visuel contradictoire après une erreur réseau temporaire ; aucun effet sur l'import en base ni sur les tâches suivantes.

**Correctif minimal :** gérer séparément l'erreur du suivi et l'effacer à sa première réponse valide, sans masquer une éventuelle erreur de téléchargement. Ajouter l'enchaînement échec du suivi → succès du suivi → absence d'ancienne alerte.

### 9. Mineur — Les lignes vides décalent les numéros du rapport

**Fichier et ligne :** `backend/app/Services/Imports/YouCodeFileReader.php:103`.

**Nature : bug confirmé**, reproduit avec un CSV fictif.

**Scénario :** en-têtes en ligne 1, ligne 2 vide, apprenant en ligne 3. Résultat retourné par le lecteur : **ligne 2**. OpenSpout saute les lignes vides avec ses options actuelles ; le compteur local compte les lignes retournées, pas les positions d'origine. Le même principe concerne la lecture XLSX.

**Référence et impact :** plan §1 : ignorer les lignes vides mais conserver le numéro de ligne du fichier. Un motif de rejet peut désigner une autre ligne que celle à corriger. Le banc a reproduit le décalage CSV ; le comportement XLSX découle des options et du même compteur, sans rejeu spécifique de ce décalage. Aucun effet sur les données des tâches suivantes.

**Correctif minimal :** préserver les lignes vides pendant l'itération puis les ignorer dans l'analyse, ou employer une position physique fiable fournie par le lecteur. Tester les positions après des lignes vides, en CSV et XLSX.

## Vérification des points [v2] et [v2.1]

| Point | Conclusion |
|---|---|
| Classe active choisie, contrôle sans casse/espaces, absence d'alias et d'ajout de classe | Conforme à la nouvelle décision. Les références actives sont revérifiées par le runner ; la filière reste dérivée de la classe. La limite connue sur les lettres d'un autre alphabet est documentée et n'est pas rouverte. |
| Limites 1 000 lignes/5 Mo/50 Mo décompressés/50 000 chaînes | Constantes et contrôles présents ; plafond des chaînes incomplet : constat 2. Les tests actuels ne couvrent pas toutes ces limites. |
| Validation par ligne, types XLSX, formules | Contrôles présents et refus de formule exercé ; réserves sur les doublons et les variantes de lecture : constats 1, 5 et 9. |
| Empreinte par ligne, fichier et choix | Présente ; changement à compteurs constants couvert par le test relancé. Pas de reprise des alias supprimés. |
| Clé idempotente, autre empreinte refusée, rien à importer → 422 | Présent et couvert par les tests ciblés. Le frontend conserve la clé pour le nouvel essai réseau et la renouvelle lors des changements/nouveaux aperçus. |
| Création et écriture partagées ; tous les nouveaux comptes préparés ; état initial et notification | Présent. Le test actif/inactif passe ; le hachage est préparé avant la transaction d'écriture et réutilisé par la boucle de reprises. |
| Connexion dédiée, `retry_after = 900`, timeout 600, `tries = 1`, workers distincts, `pcntl` | Configurations présentes ; Docker a effectivement `pcntl`. Les commandes de `CURRENT_TASK.md` nomment maintenant les connexions. |
| Prise en charge exclusive, finalisation transactionnelle, succès protégé dans `failed()` | Présent ; relivraisons rejouées à travers le vrai worker. Après succès, statut/fichier/journal conservés ; avant succès, échec et effacement du payload. |
| Classes → comptes → fiches ; reprises complètes bornées | Ordre présent. Trois scénarios bloquants à deux connexions ont terminé correctement. Cela ne prouve pas toutes les formes d'interblocage ou de déplacement de classe. Lignes inchangées exclues : constat 7. |
| Journal et erreurs expurgées | Auteur contrôlé sur un import exécuté : actions rattachées à l'administrateur attendu. Panne du journal au deuxième apprenant : rollback, aucun nouvel e-mail en file, erreur générique, sentinelle de l'exception absente des supports contrôlés. |
| Temporaires privés, nettoyage `finally`, purge cinq minutes/seuil 55 minutes | Présents. Le test de nettoyage passe. Le risque d'arrêt brutal reste celui accepté dans le plan ; aucune exigence de stockage intégral en mémoire n'est réintroduite. |
| Purge des imports selon `created_at`/`started_at` | Bon choix des horloges et protection des états terminaux ; contrôle d'ancienneté non atomique : constat 6. |
| Fichier des mots de passe et minimisation | Copie chiffrée, ressource à liste blanche, champs masqués, téléchargement `no-store`, plusieurs téléchargements, neutralisation et suppression : contrôles ciblés réussis. |
| Permission unique `learners.import` | Groupe commun couvrant les sept routes ; attribuée à l'administrateur via la matrice et le seeder existant. Les tests relancés refusent les quatre autres rôles sur les trois routes qu'ils exercent. |
| Frontend : réponses périmées, erreurs et historique | Parcours nominal et nouvel essai de confirmation réseau testés ; réserves confirmées aux constats 3, 4 et 8. |

## Points vérifiés sans remarque

- La refactorisation de `LearnerService::create()`/`update()` conserve les validations, verrous et comportements manuels existants ; `insert()`/`apply()` reprennent les écritures partagées. Aucun changement métier manuel non prévu n'a été identifié par comparaison du diff.
- La création inactive dispose maintenant d'un hachage et ne produit ni notification ni entrée dans la copie des mots de passe. Le mot de passe de l'actif correspond au hachage enregistré dans le test ciblé.
- Les apprenants connus conservent mot de passe et compétences ; changement effectif d'e-mail, révocation des liens, désactivation et réactivation sont exercés par les tests relancés.
- La transaction annule les écritures et les notifications différées en cas d'échec du journal. Les scénarios contrôlés ne laissent pas de comptes partiellement importés.
- L'ordre de verrouillage sérialise correctement les scénarios testés contre la filière, la désactivation en masse et un autre import. Une modification de filière utilisée sans confirmation reste refusée.
- Le message de file transporte l'identifiant de l'import, pas les lignes ni les mots de passe. Les champs sensibles de l'import sont chiffrés et exclus des réponses JSON. Les rejets de l'aperçu ne révèlent pas les valeurs de CNIE/e-mail.
- Le téléchargement est limité côté serveur, journalisé et non mis en cache. Sa suppression protège les téléchargements ultérieurs. Les temporaires sont supprimés après les opérations testées.
- OpenSpout **5.15.3** est fixé dans le manifeste et le verrouillage. Docker compile `pcntl`, et les lectures/écritures XLSX locales fonctionnent. La CI déclare déjà `zip` ; aucune exécution d'une nouvelle CI distante n'est revendiquée.
- Aucun code d'import des entreprises ou d'inscription à l'événement n'a été ajouté ; les reports validés restent respectés.

## Qualité des tests

Les tests apportent une couverture utile, mais leur succès ne suffit pas à valider les neuf cas ci-dessus :

- le doublon CNIE testé possède deux lignes autrement valides ; il ne détecte pas le constat 1 ;
- le CSV avec titre et le séparateur point-virgule sont testés séparément ;
- aucun test d'import actuel ne construit 50 001 chaînes partagées sans compteur fiable ni ne vérifie le numéro après une ligne vide ;
- le test frontend de réponse tardive vérifie la disparition de l'aperçu, mais pas la possibilité de recommencer ;
- les cinq tests frontend ne couvrent pas le retour au rapport depuis l'historique ni l'échec du suivi suivi d'un succès ;
- l'appel direct à `failed()` ne prouvait pas le chemin de dépassement des tentatives du worker. Ce chemin a été vérifié ici, avec conservation du succès ;
- le nettoyage séquentiel ne couvre pas une prise en charge entre sélection et annulation. Le banc à deux connexions a révélé ce cas ;
- les scénarios de suppression après confirmation doivent aussi inclure une ligne initialement inchangée ;
- les tests fournis ne couvrent pas chaque route pour chaque rôle, ni tous les cas de panne et de limite énumérés dans le plan. Il faut compléter les cas pertinents, sans réécrire les tests déjà utiles.

## Questions au responsable technique

Aucune nouvelle décision métier n'est nécessaire pour corriger ces constats. Les écarts concernent des contrats déjà écrits. Le caractère rare et l'absence d'impact direct sur les tâches suivantes du constat 6 sont explicités pour permettre son arbitrage ; aucun report automatique à T-49 n'est supposé.

## Validation et limites

### Résultats exécutés pour cette revue

Environnement des contrôles backend : **Docker, PHP 8.4.28 avec `pcntl`, MySQL 8.0.46, base `jobdating_test`**. Frontend : **Node 24.21.0**, React/jsdom pour les bancs de composant. Aucune utilisation de SQLite.

| Contrôle | Résultat observé |
|---|---|
| `docker compose run --rm -T php php artisan test tests/Feature/Learners/LearnerImportTest.php` | **16 tests réussis, 141 assertions**, environ 101 s. |
| `npm.cmd run test -- src/learners/import.test.tsx` dans `frontend/` | **5 tests réussis**, environ 13 s. |
| Bancs lecteur CSV/XLSX fictifs | Refus incorrect du point-virgule avec titre ; numéro 2 au lieu de 3 après une ligne vide ; acceptation de 50 001 chaînes partagées sans compteur. |
| Banc React sur le composant réel, API/hooks simulés, transformation en mémoire | Bouton bloqué après réponse périmée ; alerte conservée après reprise du suivi ; aucun accès au rapport dans l'historique. Aucune modification du composant. |
| Banc d'analyse/traitement MySQL | Doublon avec autre erreur accepté partiellement ; ligne inchangée supprimée après confirmation non revalidée. |
| Vrai worker, réservation avec tentative antérieure et délai expiré | Après commit : `termine`, mêmes mots de passe chiffrés, une seule ligne de succès, aucune ligne d'échec métier. Avant commit : `echec`, payload effacé, aucun apprenant créé. L'échec technique de la réservation est bien enregistré par Laravel. |
| Deux processus/connexions, import contre filière | Attente réelle du verrou de classe ; confirmation de filière exigée ; import terminé après libération. |
| Deux processus/connexions, import contre désactivation en masse | Attente réelle du compte ; désactivation en masse exécutée ; import terminé après libération. |
| Deux imports concurrents | Sérialisation constatée sur la classe commune ; les deux imports terminent. |
| Purge contre prise en charge concurrente | Import annulé moins d'une seconde après son démarrage : constat 6. |
| Panne simulée du journal au deuxième apprenant, queue réelle | Import en échec ; **0 apprenant supplémentaire et 0 e-mail supplémentaire en file**, payload effacé ; message générique. Sentinelle sensible de l'exception absente des données/journaux/messages contrôlés. |
| Identité d'audit d'un import exécuté | Actions contrôlées attribuées à l'administrateur attendu. |
| Import fictif de **1 000 comptes actifs**, bcrypt **coût 12**, consommation par le worker et programmation réelle des notifications | **301,896 s** pour le traitement ; admission séparée **2,542 s** ; pic mémoire PHP **26 Mio** ; import terminé, 1 000 fiches, 1 000 entrées de mots de passe, 1 000 notifications dans `default`, payload effacé. Coût 12 vérifié sur le hachage enregistré. Aucun de ces e-mails n'a été envoyé à un serveur SMTP par ce banc. |

**Isolation des bancs MySQL :** copies de tables sous des préfixes `cx13_*` dans `jobdating_test`, supprimées en fin de chaque banc. `CREATE TABLE LIKE` recopie les index, mais **pas les clés étrangères ni les déclencheurs** : les résultats de concurrence et de charge sont donc ciblés et indicatifs, sans remplacer une validation complète avec tous les effets du schéma d'origine. Les 16 tests d'import relancés utilisent, eux, le schéma normal de test. Une vérification finale a trouvé **zéro table `cx13_*` restante**.

Les bancs n'ont écrit aucun fichier de contrôle dans le dépôt : scripts transmis en mémoire, fichiers fictifs et stockage de travail dans `/tmp` des conteneurs, nettoyage effectué. Le banc frontend n'est pas un essai navigateur/CSS. Les essais concurrents démontrent les enchaînements décrits, pas l'absence de tout interblocage. Le cas précis de déplacement de classe pendant l'acquisition et l'épuisement des trois reprises n'ont pas été forcés. La mesure de charge utilise `queue:work --once` : elle mesure le traitement et la programmation des notifications, **pas le déclenchement d'une alarme à 600 secondes**.

### Résultats communiqués par Claude, non rejoués ici

- Pint et PHPStan sans erreur.
- Suite backend complète Docker : **433 tests réussis, 2 230 assertions**, environ 5 min 30, code de sortie 0 ; puis deux corrections d'annotations et `tests/Feature/Learners` relancé : **55 tests réussis**, dont 16 d'import.
- Frontend : lint réussi, **85 tests réussis**, build réussi.

**La suite complète n'a pas été relancée.** Le sous-ensemble de 16 tests backend et les 5 tests frontend indiqués plus haut ont bien été exécutés indépendamment pour cette revue ; ils ne sont pas confondus avec les résultats communiqués.

## Verdict

**À corriger avant fusion.**

Les garanties principales du plan 2.1 sont désormais implémentées et plusieurs contrôles manquants ont pu être exécutés. Les doublons conditionnés par les autres erreurs, la limite XLSX contournable, le blocage de l'aperçu et l'absence d'accès aux rapports historiques nécessitent des corrections avant fusion. Les cinq constats mineurs sont également documentés avec leur scénario et leur correction minimale.

Seul ce rapport a été créé. Aucun code, plan ou rapport précédent n'a été modifié ; aucun commit ni push.
