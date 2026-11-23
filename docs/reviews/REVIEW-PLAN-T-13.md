# Revue du plan T-13 — Import des apprenants

- **Date :** 20/11/2026.
- **Branche vérifiée :** `feature/T-13-import`.
- **Base et HEAD vérifiés :** `develop`, `81f9d028a15bb1f15c589b2344cf1fbffa94b97e`.
- **Document examiné :** `docs/tasks/PLAN-T-13.md`, version 1 du 20/11, non suivi par Git au début de la revue.
- **Nature de la revue :** validation du plan avant développement. Aucun code de T-13 à tester. Les corrections proposées ci-dessous concernent le plan ; elles n'ont pas été appliquées.

## Références et périmètre

Lecture d'`AGENTS.md`, `CLAUDE.md`, du plan et de `CURRENT_TASK.md` ; examen de `git diff develop` et des fichiers non suivis pertinents. Références : CDC V2, ADM-04/RG-IMP-01 à 07, ADM-03, ADM-09, TRA-01, EXI-DATA-03 et PC-22 ; décisions des 14, 16, 19 et 20/11 ; passages de relais T-11/T-12 dans `PROGRESS.md` ; architecture, notamment §7.5 « Imports » ; maquettes `admin-07` et `admin-08`.

Code existant consulté pour vérifier les contrats réutilisés : `LearnerService`, `LearnerController`, `LearnerFilters`, `LearnerClassUsage`, `Cnie`, `ReferentialService`, modèles et migration des référentiels, modèle `User`, `GeneratedPasswordNotification`, audit, permissions, routes et configuration de file. Lecture également du comportement des transactions et du worker dans Laravel installé. Vérification des prérequis et du fonctionnement d'OpenSpout dans ses sources officielles, sans installation.

Les onze décisions du 20/11 sont appliquées. Le téléchargement répété jusqu'à suppression, l'absence de changement obligatoire du mot de passe et les reports entreprises/événement ne sont pas remis en cause. Les écarts correspondants avec le CDC, l'architecture ou les maquettes sont donc acceptés. E-01 à E-04 sont exclus. Les fichiers locaux exclus de la demande n'ont pas été examinés. Le fichier réel d'apprenants n'a été ni recherché ni ouvert.

## Constats

### 1. Majeur — Le contrat de création réutilisé ne permet pas les garanties annoncées

**Référence :** plan §5, lignes 92 à 94 ; décisions 7 et 10. Code de référence : `backend/app/Services/Learners/LearnerService.php:67`, `:85`, `:103` ; `backend/app/Models/User.php:94`.

**Nature :** incompatibilité confirmée par lecture du service existant ; régression applicative potentielle, sans exécution de T-13.

**Problème et scénario :** le plan prévoit de hacher les mots de passe avant la transaction globale et de réutiliser `LearnerService::create()`. Or ce service génère lui-même un autre mot de passe, affecte sa valeur en clair au modèle dont le cast effectue le hachage pendant l'écriture, et programme systématiquement un e-mail de création. Aucun paramètre ne permet de fournir le couple mot de passe/hachage préparé ni d'interdire cet e-mail pour un nouvel apprenant inactif. Appeler `create()`, puis désactiver le compte ne retire pas la notification déjà programmée : `GeneratedPasswordNotification` attend le commit, mais ne filtre pas les comptes désactivés.

**Impact :** la réutilisation telle que décrite maintient le hachage sous les verrous et envoie un mot de passe aux nouveaux comptes inactifs, contrairement à la décision 7. Préparer un autre mot de passe sans adapter le service peut aussi désynchroniser le compte, l'e-mail et le fichier remis à l'administrateur.

**Correction minimale dans le plan :** prévoir explicitement l'adaptation du chemin de création partagé : identifiants préparés avant la transaction, état initial actif/désactivé et notification conditionnelle. Conserver les valeurs par défaut de la création manuelle T-12. Le même mot de passe doit correspondre au hachage enregistré, à l'e-mail et à la copie chiffrée ; aucune notification ne doit être programmée pour un nouveau compte inactif. Ajouter ces tests, ainsi qu'une vérification du placement du hachage hors de la transaction globale et de l'absence d'e-mail après rollback.

### 2. Majeur — Délais, reprise et finalisation du traitement en file restent indéfinis

**Référence :** plan §4 à §6, lignes 86 à 99, §9 ligne 123 ; décision 10. Références existantes : `backend/config/queue.php:43`, `:44` ; `backend/vendor/laravel/framework/src/Illuminate/Queue/Console/WorkCommand.php:49` ; commande `queue:work` de `CURRENT_TASK.md`.

**Nature :** lacune confirmée du plan et valeurs par défaut confirmées dans le code installé ; les incidents décrits restent des risques avant développement.

**Problème et scénarios :**

- Au coût annoncé de 0,25 seconde par hachage, 300 créations représentent déjà 75 secondes et 2 000 créations environ 500 secondes, avant les écritures. Le worker installé prévoit par défaut un délai de 60 secondes, effectif lorsque son mécanisme de timeout est disponible ; la connexion `database` prévoit une remise à disposition après 90 secondes. Le plan n'annonce aucun réglage. Relever uniquement le timeout peut permettre à un second worker de reprendre la tâche encore exécutée par le premier. L'exécution Windows ne valide pas le comportement du timeout d'un worker Linux.
- Une confirmation dont la réponse HTTP est perdue peut être renvoyée ; une tâche peut être livrée deux fois ; un worker peut s'arrêter après le commit et avant son acquittement. Le plan ne définit ni identité de confirmation, ni garde contre le double traitement, ni résultat à restituer après un succès déjà enregistré.
- Un arrêt brutal ne passe pas nécessairement par le traitement normal d'une exception. Rien ne garantit alors la sortie de l'état « en cours » et l'effacement du `payload`. La création de l'import, sa mise en file et la finalisation du rapport/fichier ne disposent pas non plus d'un contrat atomique explicite.

**Impact :** imports interrompus ou concurrents, résultat final écrasé lors d'une reprise, fichier des mots de passe perdu, attente sans fin et conservation des données au-delà du traitement prévu.

**Correction minimale dans le plan :** définir les délais du job et du worker, un `retry_after` supérieur à la durée maximale autorisée avec marge, et des tentatives bornées. Définir une confirmation idempotente, la prise en charge exclusive d'un import et le retour sans réécriture d'un import déjà terminé. Rendre cohérents enregistrement et mise en file, puis valider dans la transaction globale les apprenants, le rapport définitif, la copie chiffrée des mots de passe, le journal de succès et l'effacement du `payload`. Un arrêt après ce commit ne doit pas refaire l'import. Prévoir le traitement des tâches abandonnées et l'effacement après échec définitif, sans supprimer prématurément les données d'une tentative encore récupérable.

Préciser aussi que le message de file ne transporte que l'identifiant de l'import : pas de lignes, de fichier brut ni de mots de passe. Définir des erreurs expurgées pour `error`, les journaux techniques et `failed_jobs`, notamment face aux exceptions SQL. Ajouter les tests de double livraison, double confirmation, arrêt avant/après commit et échec de mise en file ; mesurer un import fictif à la limite H1. Cela complète les tests actuels de succès/échec ordinaires sans changer le choix validé de la file d'attente.

### 3. Majeur — L'empreinte des seuls compteurs ne détecte pas un résultat d'aperçu différent

**Référence :** plan §2 ligne 73, §4 ligne 84 et test de confirmation ligne 147 ; décision 6.

**Nature :** insuffisance confirmée de la définition de l'empreinte ; scénario établi par raisonnement, non exécuté.

**Scénario :** deux lignes fictives A et B portent des CNIE nouvelles et des e-mails distincts. À l'aperçu, l'e-mail A est occupé : A est rejetée et B est à créer. Avant confirmation, une modification libère l'e-mail A et une autre réserve l'e-mail B. Le même fichier contient maintenant A à créer et B rejetée. Le fichier, le campus, la promotion et les nombres « une création, un rejet » sont identiques : l'empreinte décrite reste identique alors que le détail confirmé a changé. Une modification de correspondance de classe peut également changer la cible sans changer les compteurs.

**Impact :** l'import peut accepter des lignes différentes de celles présentées et confirmées, sans le 409 exigé.

**Correction minimale dans le plan :** calculer l'empreinte sur une représentation déterministe du résultat utile de l'analyse, comprenant les numéros de ligne, décisions création/modification/inchangée/rejet, motifs et identifiants des cibles résolues, notamment comptes et classes. Garder le hachage du fichier et les choix campus/promotion. Cette empreinte opaque peut être renvoyée au client sans stocker l'aperçu ni exposer de CNIE. Ajouter un test où les lignes acceptées/rejetées changent à compteurs constants. Distinguer ce contrôle avant admission de la revalidation sous verrous pendant le job.

### 4. Majeur — L'ordre des verrous et les reprises doivent couvrir la transaction englobante

**Référence :** plan §5 ligne 91, relais T-12. Code : `backend/app/Services/Learners/LearnerService.php:124`, `:129`, `:177`, `:338`, `:385` ; `backend/vendor/laravel/framework/src/Illuminate/Database/Concerns/ManagesTransactions.php:90`.

**Nature :** garanties incomplètes dans le plan, confirmées par lecture des mécanismes réutilisés ; concurrence non rejouée.

**Problème et scénarios :** le tri des classes est prévu, mais pas celui de tous les comptes et fiches de l'import. Si les comptes sont verrouillés dans l'ordre des lignes du fichier, un import prenant le compte 20 puis 10 peut croiser une action en masse T-12 prenant 10 puis 20. Les deux attendent alors un verrou détenu par l'autre.

De plus, `LearnerService::update()` relit la classe courante et recommence localement en cas de `ClassMovedException`. Sous une transaction MySQL englobante en lecture répétable, une simple relecture peut conserver l'ancien instantané. Acquérir tardivement une classe nouvellement découverte peut aussi casser l'ordre global. Enfin, Laravel propage un interblocage rencontré dans une transaction imbriquée : le nombre de tentatives du service ne suffit pas à recommencer l'import entier.

**Impact :** échecs évitables, reprises sur un état périmé ou acquisition de verrous dans un ordre incompatible avec les services existants. L'atomicité ne suffit pas à garantir une reprise correcte.

**Correction minimale dans le plan :** imposer un ordre déterministe pour l'ensemble de l'import : classes par identifiant, comptes par identifiant, puis fiches par identifiant, avant leurs modifications. Décrire la découverte et la revalidation des classes actuelles. Si une classe manquante est découverte après cette phase, annuler puis reconstruire l'ensemble dans une nouvelle transaction ; ne pas ajouter un verrou hors ordre. Prévoir au niveau englobant les reprises bornées après déplacement de classe/interblocage, avec des objets et lectures reconstruits et sans notifications issues d'une tentative annulée. Préciser l'adaptation nécessaire de la reprise locale du service lorsqu'il est appelé dans ce contexte.

Ajouter des tests MySQL à deux connexions : import contre modification de classe/filière, import contre action en masse et deux imports. Un test de l'ordre des requêtes seul ne prouve pas ces scénarios.

### 5. Majeur — Des lignes invalides peuvent passer l'analyse puis annuler tout l'import

**Référence :** plan §1/§2 lignes 49 à 68, §5 ligne 91 ; CDC RG-IMP-01. Contrat existant : `backend/app/Http/Controllers/LearnerController.php:276`.

**Nature :** omission confirmée des contrôles annoncés ; défaillance potentielle à l'implémentation.

**Scénario :** un fichier contient une ligne correcte et une ligne dont le prénom contient 101 caractères ou le téléphone 31 caractères. Le plan contrôle les noms vides et le format de l'e-mail, mais ne prévoit pas les longueurs et types du contrat T-12 : noms limités à 100, e-mail à 255 et téléphone à 30. Ces validations sont dans le contrôleur T-12, pas automatiquement exécutées par les services appelés depuis le job. La ligne peut donc être annoncée valide puis provoquer une erreur SQL en MySQL strict, annulant aussi la ligne correcte.

**Impact :** violation de RG-IMP-01, qui exige le rejet d'une ligne invalide sans interrompre les autres. Une exception SQL non expurgée peut en outre transporter des valeurs personnelles.

**Correction minimale dans le plan :** définir une validation par ligne de tous les champs utiles, compatible avec les contraintes de T-12 et de la base, avant le classement de l'aperçu. Définir la conversion des types de cellules XLSX, sans perdre les identifiants textuels ni inventer de normalisation métier. Refuser explicitement les valeurs/formules non admissibles. Les défauts déjà présents dans le fichier produisent des rejets de ligne ; une collision apparue après confirmation conserve le traitement global d'échec prévu. Tester les bornes, les types et un fichier mélangeant une ligne invalide et une ligne valide ; vérifier que seule la seconde est importée.

### 6. Majeur — OpenSpout peut conserver des données ignorées et des mots de passe dans des fichiers temporaires

**Référence :** plan §1 ligne 47, §6 lignes 99 à 103, tests lignes 144 et 149 ; EXI-DATA-03 et décisions 6, 10 et 11.

**Nature :** fonctionnement confirmé dans les sources officielles de la version candidate ; aucun essai local d'OpenSpout, aucune fuite constatée dans un code T-13 inexistant.

**Problème :** « généré à la volée » ne garantit pas « jamais en clair sur le serveur ». Le writer XLSX ouvre un fichier de feuille et y écrit les cellules XML, y compris les mots de passe, avant l'assemblage du classeur. Voir [WorksheetManager v5.15.3](https://raw.githubusercontent.com/openspout/openspout/v5.15.3/src/Writer/XLSX/Manager/WorksheetManager.php).

À la lecture, les chaînes partagées du classeur sont traitées avant le filtrage applicatif des colonnes. Un cache fichier peut être sélectionné, notamment si leur nombre n'est pas renseigné. Les valeurs de ville, d'e-mail personnel ou de date de naissance peuvent donc être écrites dans ce cache même si le code métier les ignore. Voir [SharedStringsManager](https://raw.githubusercontent.com/openspout/openspout/v5.15.3/src/Reader/XLSX/Manager/SharedStringsManager.php), [choix du cache](https://raw.githubusercontent.com/openspout/openspout/v5.15.3/src/Reader/XLSX/Manager/SharedStringsCaching/CachingStrategyFactory.php) et [écriture du cache](https://raw.githubusercontent.com/openspout/openspout/v5.15.3/src/Reader/XLSX/Manager/SharedStringsCaching/FileBasedStrategy.php).

**Scénario :** un XLSX fictif utilise des chaînes partagées sans compteur et contient une valeur sentinelle dans « Ville ». Le reader peut l'écrire dans un cache temporaire avant l'analyse des lignes. Lors d'un téléchargement, le writer produit de même une feuille temporaire contenant un mot de passe fictif. Une exception ou un arrêt du processus peut laisser ces fichiers sur disque. Les tests proposés ne recherchent les colonnes ignorées que dans la base et le journal.

**Impact :** la copie chiffrée en base ne protège pas ces autres copies ; les garanties de minimisation et d'absence de persistance en clair ne sont pas établies.

**Correction minimale dans le plan :** conserver OpenSpout, dont l'ajout est validé, mais préciser la configuration de ses temporaires et caches. Éviter leur écriture sur un disque persistant, par exemple avec un espace temporaire privé en mémoire, borné et nettoyé, et traiter également les interruptions. Ne pas sauvegarder le fichier source entre les requêtes. Prévoir une sortie JSON explicitement limitée pour l'historique/rapport, excluant `payload` et `passwords`, même si leurs casts les déchiffrent. Étendre les tests de confidentialité aux temporaires et aux erreurs, en plus de la base, du journal, de `jobs` et `failed_jobs`. Un simple nettoyage après succès ne suffit pas à démontrer l'absence d'écriture en clair.

### 7. Mineur — La correspondance des classes ne traite pas les noms ambigus après normalisation

**Référence :** plan §2 ligne 70 et §3 lignes 77 à 80 ; décision 2. Référentiel existant : `backend/database/migrations/2026_11_16_170000_create_referentials_tables.php:31`, `backend/app/Enums/ReferentialType.php:103`.

**Nature :** cas non défini confirmé ; risque conditionnel à la présence ou à la création de tels noms.

**Scénario :** les classes `DEV 1` et `DE V1` peuvent avoir des noms distincts dans le référentiel mais la même valeur lorsque tous les espaces sont retirés. Elles peuvent appartenir à des filières différentes. Le plan ne définit pas le résultat de cette recherche multiple. Un nouveau nom canonique peut aussi prendre priorité sur un alias existant, puisque la recherche par nom précède les alias.

**Impact :** une sélection implicite de la première correspondance peut affecter une mauvaise classe/filière. Le cas est peu fréquent, mais concerne directement les données produites par T-13 et les tâches suivantes.

**Correction minimale dans le plan :** définir les conflits nom normalisé/alias et refuser toute résolution ambiguë avec un message permettant une décision explicite. Tester plusieurs noms normalisés identiques et la création d'un nom entrant en conflit avec un alias. Ne pas imposer silencieusement une nouvelle règle globale aux référentiels existants.

### 8. Suggestion — Corriger la présentation des prérequis d'OpenSpout

**Référence :** plan §10 ligne 128.

**Nature :** imprécision documentaire confirmée, sans blocage identifié du choix validé.

**Problème et impact :** « sans dépendance native » peut faire oublier les extensions nécessaires sur Windows, dans Docker et en CI. La licence mérite aussi une présentation complète lors de l'ajout.

**Correction proposée :** indiquer la version choisie lors de l'implémentation et les prérequis réellement vérifiés. La version candidate **5.15.3** accepte PHP 8.4 et requiert `dom`, `filter`, `libxml`, `xmlreader` et `zip`. [Composer officiel de cette version](https://raw.githubusercontent.com/openspout/openspout/v5.15.3/composer.json). La publication du 19/11/2026 donne un signe de maintenance récente, sans constituer une garantie de sécurité. [Publication du paquet](https://packagist.org/packages/openspout/openspout). Le manifeste annonce MIT ; le README distingue le code hérité sous Apache-2.0 et les contributions ultérieures sous MIT : conserver les notices correspondantes. [Licences décrites par le projet](https://raw.githubusercontent.com/openspout/openspout/v5.15.3/README.md). Aucun changement de bibliothèque n'est demandé.

## Avis sur les hypothèses H1 à H5

| Hypothèse | Avis |
|---|---|
| **H1 — 2 000 lignes / 5 Mo** | Limites acceptables comme proposition, à confirmer selon les volumes attendus et à mesurer sur PHP 8.4/MySQL 8. Au coût annoncé, le hachage de 2 000 créations représente déjà environ 8 min 20 s : la durée et la configuration du worker doivent être cohérentes. Les 5 Mo d'un ZIP XLSX et le nombre de lignes ne bornent pas à eux seuls les chaînes partagées ni les volumes décompressés traités avant les lignes ; prévoir des bornes de lecture et des essais de fichiers fortement compressés, sans attendre l'épuisement mémoire. |
| **H2 — Même e-mail, deux CNIE : rejet des deux** | Favorable. Cette règle évite de choisir arbitrairement le compte créé. Comparer les e-mails selon une règle cohérente avec l'unicité MySQL, notamment pour la casse ; tester aussi le cas avec un compte supprimé ou non apprenant. Ne pas confondre cette collision avec la présence de la même adresse dans les colonnes professionnelle/personnelle, expressément acceptée. |
| **H3 — Classe inactive** | Favorable à la distinction « valeur actuelle conservée / nouvelle affectation interdite », conforme à T-02/T-12. Décliner la même exception après résolution d'un alias et dans la revalidation du job. |
| **H4 — Compteur inchangés** | Favorable. Comparer toutes les données effectivement importées, statut compris. Un second import identique doit rester sans nouvelle écriture métier, mot de passe ni notification. Le refus de confirmer quand tout est inchangé est cohérent avec §4 ; l'expliquer dans l'écran. |
| **H5 — Pas d'édition des alias** | L'absence d'écran d'édition est acceptable dans ce périmètre. Réserve sur « corriger en base » sans procédure : un alias erroné influence les imports suivants. Définir une correction technique contrôlée et traçable, puis un nouvel aperçu, sans ajouter une interface complète à T-13. |

## Points vérifiés sans remarque

- Le parcours nominal d'ADM-04 est représenté : choix campus/promotion, lecture, aperçu, classes inconnues, confirmation, rapport et historique. Les exceptions de format et d'en-têtes sont prévues.
- Les décisions sur CSV/XLSX, titre facultatif, e-mail professionnel et fichier fictif sont reprises. La règle CNIE commune `Cnie` est réutilisée ; aucune demande de fichier réel n'est nécessaire.
- Les doublons de CNIE rejettent les deux lignes, les fiches supprimées sont refusées et la filière est dérivée de la classe. Le rattachement aux référentiels existants est conservé.
- Les apprenants connus gardent leur mot de passe, compétences, CV et historique. La révocation des liens au changement réel d'e-mail et la fermeture des sessions lors d'une désactivation reposent sur les services existants. Ces intentions sont cohérentes ; leur composition dans le job reste à tester.
- Verrouiller toutes les classes avant les comptes est le bon principe pour partager le protocole `ClassUsage` avec `ReferentialService`. Le constat 4 demande de compléter ce protocole, pas de changer la règle de filière validée.
- L'import des entreprises et l'inscription à l'événement sont explicitement reportés. T-13 n'a pas à reprendre ici le nettoyage des logos de T-11 ni à réaliser RG-IMP-07 avant T-14. Le message de succès ne devra pas reprendre la phrase de la maquette affirmant une inscription déjà effectuée.
- L'accès administrateur côté serveur est cohérent avec la matrice. L'API d'ajout de classe existante et la catégorie d'audit « Imports et exports » sont réutilisées. Le téléchargement répété et la suppression journalisés respectent les décisions remplaçant le téléchargement unique du CDC.
- Le frontend prévoit l'effacement d'un aperçu périmé, l'ignorance d'une réponse tardive, le 409 suivi d'une nouvelle confirmation et le suivi du traitement. Ajouter aux tests les erreurs d'aperçu, de confirmation, de suivi et de téléchargement suivies d'un nouvel essai ; une réponse de confirmation perdue doit retrouver le même import, conformément au constat 2.
- Les tests prévus couvrent les règles nominales importantes. Les compléments des constats doivent inclure les cas concurrents réels, les échecs du journal dans la transaction globale et la recherche de valeurs fictives sensibles dans les supports techniques. Un test de second import séquentiel ne prouve pas la résistance à une double livraison de job.

## Questions au responsable technique

1. **H1 :** les volumes attendus confirment-ils le plafond de 2 000 lignes et 5 Mo ? La mesure prévue devra fixer un délai réaliste et le réglage du worker, sans promettre une durée déjà dépassée par le seul calcul des hachages.
2. **H5 :** quelle procédure technique et quelle trace d'audit retenir pour corriger une association de classe erronée, si l'édition reste hors écran ?
3. **Permission :** §8 indique d'une part `learners.update` + rôle administrateur pour toutes les routes, d'autre part une nouvelle permission `learners.import`. Confirmer que celle-ci sera effectivement contrôlée côté serveur pour l'aperçu, la confirmation, l'historique et les mots de passe, puis aligner routes et tests. La restriction administrateur annoncée empêche déjà un accès CME/Direction ; il s'agit de rendre le contrat de la nouvelle permission explicite.

Les autres constats appellent des précisions techniques du plan, sans remettre en question les décisions métier du 20/11.

## Validation et limites

Contrôles réalisés : lecture des documents, du diff et de l'état Git ; comparaison statique avec les contrats existants ; inspection des deux maquettes ; consultation des sources officielles d'OpenSpout. Les scénarios du rapport sont des cas à couvrir lors de l'implémentation, **pas des reproductions exécutées sur T-13**.

**Aucun test applicatif, banc MySQL, build, installation Composer ni contrôle Pint/Larastan n'a été lancé pour cette revue de plan.** Aucun résultat de suite T-13 n'est disponible : le code n'existe pas encore. Les commandes de `CURRENT_TASK.md` sont des contrôles futurs, pas des résultats obtenus. Aucun fichier de code, plan ou décision n'a été modifié ; seul ce rapport a été créé, sans commit ni push.

## Verdict

**Plan à revoir.**

Le périmètre fonctionnel est bien délimité. Les six constats majeurs doivent être traités dans le plan avant développement : contrat du service de création, exécution/reprise en file, empreinte du résultat, transaction et verrous, validation par ligne et gestion des copies temporaires sensibles.
