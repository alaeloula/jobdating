# Contre-revue du plan T-13 — Import des apprenants

- **Date :** 20/11/2026.
- **Branche :** `feature/T-13-import`, non commitée.
- **Base et HEAD vérifiés :** `develop`, `81f9d028a15bb1f15c589b2344cf1fbffa94b97e`.
- **Plan examiné :** `docs/tasks/PLAN-T-13.md`, version 2 du 20/11.
- **Rapport initial conservé :** `docs/reviews/REVIEW-PLAN-T-13.md`.
- **Livrable unique de cette intervention :** le présent rapport. Aucun changement de code, de plan ou de décision ; aucun commit ni push.

## Périmètre

Contre-revue limitée aux corrections **[v2]**, à la classe choisie avant l'import et à leurs effets de bord, conformément à `PROMPT-CODEX-CONTRE-REVUE-PLAN-T-13.md`, `AGENTS.md` et `CLAUDE.md`. Lecture du plan, de `CURRENT_TASK.md`, du diff documentaire par rapport à `develop`, de la décision T-13 dans `DECISIONS.md` et des parties utiles du rapport initial.

Les décisions 2, 12 et 13 sont appliquées : classe active imposée, limites de 1 000 lignes/5 Mo/50 Mo décompressés/50 000 chaînes partagées, durée maximale de dix minutes et permission unique `learners.import`. Les décisions inchangées ne sont pas rouvertes. L'usage de temporaires privés avec nettoyage, et le risque résiduel accepté en cas d'arrêt brutal, remplacent pour cette revue l'exigence initiale d'absence complète d'écriture temporaire sur disque.

Le code existant sert seulement à vérifier les contrats : services apprenants/référentiels, `ClassUsage`, schéma des comptes, configuration de file et sources du worker Laravel installé. Aucune nouvelle revue générale de T-13, aucune recherche du fichier réel et aucune réouverture d'E-01 à E-04.

## Rejeu des huit constats

Il s'agit d'un rejeu documentaire des scénarios : les statuts ci-dessous évaluent le **plan**, pas une correction applicative exécutée.

| Constat initial | Statut | Preuve dans la version 2 et réserve éventuelle |
|---|---|---|
| **1 — Création incompatible avec préparation des hachages et comptes inactifs** | **Partiel** | §4, lignes 95–97 : préparation/écriture séparées, hachage reçu tel quel, état initial, e-mail réservé aux actifs et création manuelle conservée. Le scénario « créer puis désactiver tout en laissant un e-mail programmé » est traité. Toutefois, §5 ligne 108 ne prépare les hachages que pour les nouveaux comptes **actifs** : le chemin des nouveaux comptes inactifs reste contradictoire avec §4. Voir C3. |
| **2 — Délais, reprise et finalisation du job** | **Partiel** | §3, lignes 88–91 ; §5, lignes 101–116 : clé unique, publication après commit, connexion dédiée, prise en charge conditionnelle, finalisation atomique, identifiant seul en file, erreurs expurgées et purge des abandons. Ces ajouts traitent l'essentiel. La commande de worker ne sélectionne toutefois pas la connexion annoncée ; le passage par `failed()` après un commit n'est pas rendu explicitement compatible avec l'immutabilité d'un succès. Voir C1 et C2. |
| **3 — Empreinte limitée aux compteurs** | **Corrigé** | §2 ligne 83 et tests ligne 165 : décision, motif et cibles par numéro de ligne, paramètres et fichier inclus dans une représentation déterministe. A/B échangeant leurs statuts valide/rejetée à compteurs constants changent désormais l'empreinte. La classe choisie étant un paramètre, son changement est aussi détecté. |
| **4 — Verrous et reprises dans la transaction englobante** | **Corrigé dans le plan** | §4 ligne 97 ; §5 lignes 108–112 : écriture partagée sans transaction/verrous internes supplémentaires, lecture fraîche hors transaction, classes puis comptes puis fiches triés, déplacement détecté sous verrou, rollback et reprise complète bornée. Les tests à deux connexions MySQL sont repris ligne 168. La preuve effective dépendra de leur exécution après développement. |
| **5 — Ligne invalide acceptée puis erreur globale** | **Corrigé dans le plan** | §1 ligne 53, §2 lignes 63–77 et tests lignes 163–164 : limites T-12, types des cellules et rejet des formules. Le prénom de 101 caractères ou le téléphone de 31 caractères est rejeté avant traitement ; le fichier mêlant une ligne invalide et une valide figure dans les tests. |
| **6 — Copies temporaires sensibles d'OpenSpout** | **Corrigé selon le risque accepté, avec précision mineure** | §1 ligne 57, §6 lignes 120–122 et tests lignes 163/170 : temporaires reconnus, dossier privé unique, nettoyage `finally`, purge après interruption, ressource JSON à liste blanche et champs sensibles masqués. L'écriture temporaire en clair est désormais assumée ; elle n'est donc pas à signaler à nouveau comme interdite. La borne annoncée d'une heure nécessite encore la précision C4. |
| **7 — Correspondance de classes ambiguë** | **Sans objet** | Décision 2, §2 lignes 61/74/82 : aucune recherche automatique de classe à partir du nom ni aucun alias ; l'identifiant de la classe est choisi par l'administrateur. La normalisation sert seulement à contrôler la colonne. Le scénario de deux noms normalisés identiques ne sélectionne plus arbitrairement une cible. |
| **8 — Présentation des prérequis d'OpenSpout** | **Corrigé** | §10 lignes 146–147 : version 5.15.3, extensions et notices MIT/Apache-2.0 explicitées, vérification des environnements prévue à l'ajout. Aucun changement de bibliothèque à demander. |

Les trois questions initiales sont également traitées : H1 est tranchée par la décision 12 ; H5 disparaît avec les alias ; §8 et la décision 13 désignent clairement `learners.import` comme unique permission de l'import.

## Effets de bord examinés

### Classe choisie et filière

La décision est cohérente avec le modèle existant : la classe choisie fournit son identifiant et sa filière, sans déduire une autre cible du fichier. Une colonne `Classe` vide ou composée d'espaces ne correspond pas au nom non vide de la classe choisie : la ligne est rejetée. Prévoir ce test explicitement, en plus du test « autre classe ».

Pour un apprenant connu dans une classe devenue inactive, il n'existe plus d'exception H3 : si le fichier indique la nouvelle classe active choisie, la fiche peut être déplacée ; s'il indique l'ancienne classe différente, la ligne est rejetée. Il ne faut ni conserver implicitement l'ancienne classe ni contourner le contrôle pour une CNIE connue. Cela applique la nouvelle décision sans la remettre en cause.

Les verrous de §5 couvrent bien la classe choisie et les classes quittées. Ils permettent de partager le protocole de `ReferentialService::update()` et de `LearnerClassUsage` pour le changement de filière. La revalidation devra conserver l'exigence de références actives au moment du traitement, notamment si la classe est désactivée pendant l'attente en file ; ajouter ce scénario aux tests. La filière demeure lue via la classe, sans copie dans la fiche.

### Budget de traitement et hachages

Au coût **indicatif du plan initial**, 1 000 hachages représentent environ **250 secondes**, avant les écritures et la journalisation. Le budget de 600 secondes est donc plausible, mais pas démontré. La mesure annoncée doit intégrer l'import entier, la mémoire maximale et la mise en file des notifications après commit.

§5 prévoit de reprendre les étapes 1 à 3 : si chaque reprise refait les 1 000 hachages, trois préparations représenteraient déjà environ 750 secondes. La limite globale de 600 secondes doit rester applicable à l'ensemble des reprises. Conserver les identifiants préparés réutilisables en mémoire pendant une tentative d'import évite ce travail répété ; les lectures et objets métiers doivent, eux, être renouvelés. Aucun temps ni pic mémoire n'a été mesuré dans cette contre-revue.

### `tries = 1`, relivraison et nettoyage à vingt minutes

Le principe d'une seule tentative de file, avec reprises transactionnelles internes bornées, est cohérent. Il signifie qu'après une interruption avant commit, une relivraison pourra terminer l'import en échec au lieu de le reprendre. Ce comportement est bien annoncé ; il faut distinguer ce cas d'une interruption **après** le commit, qui doit préserver le succès : C2.

Vingt minutes dépassent les dix minutes maximales de traitement, à condition que cette dernière borne soit réellement appliquée : C1. Pour la purge, expliciter l'âge de chaque état : attente depuis la confirmation, traitement depuis `started_at`. Un import ayant attendu 19 minutes peut encore bénéficier de ses dix minutes de traitement ; son âge total depuis `created_at` ne doit pas le faire purger pendant cette exécution légitime. La purge et le job doivent partager des transitions conditionnelles : un job démarrant après la purge ne doit pas recréer un import annulé. Ajouter ces cas au test « import abandonné nettoyé » déjà prévu.

### Temporaires privés

Le compromis accepté est désormais décrit honnêtement : le dossier peut contenir temporairement des données ignorées ou des mots de passe en clair ; la copie durable en base reste chiffrée. Le dossier privé unique, les droits restreints et le nettoyage normal sont adaptés à ce compromis. L'arrêt brutal est identifié. La fréquence de purge doit toutefois rendre crédible la durée maximale annoncée : C4. Les tests d'absence de sentinelles **après** traitement prouvent le nettoyage, pas l'absence d'écriture temporaire, qui n'est plus la garantie retenue.

## Constats restant à traiter dans le plan

### C1. Majeur — La commande ne sélectionne pas la connexion dédiée et son environnement n'impose pas le timeout

**Références :** `docs/tasks/PLAN-T-13.md:103`, `docs/tasks/CURRENT_TASK.md:27` et `:29`. Code lu : `backend/config/queue.php:16` et `:43` ; `backend/vendor/laravel/framework/src/Illuminate/Queue/Console/WorkCommand.php:118` ; `backend/vendor/laravel/framework/src/Illuminate/Queue/Worker.php:310` et `:839`.

**Nature :** incohérence documentaire confirmée et absence de `pcntl` confirmée sur le PHP Windows local ; aucun incident de file exécuté.

**Scénario :** le plan configure `database-imports` avec `retry_after = 900`, puis lance `queue:work --queue=imports,default --timeout=660`. Sans argument de connexion, Laravel sélectionne `queue.default`, qui vaut `database` dans la configuration d'exemple, avec un délai de relivraison de 90 secondes. `--queue` choisit les noms de files, pas la connexion. Si les deux connexions utilisent la même table `jobs`, ce worker peut reprendre un import après 90 secondes alors qu'un autre processus travaille encore. Si les tables sont séparées, la commande peut simplement ne pas consommer les imports.

En outre, la commande est documentée pour PHP sous Windows. Le contrôle local a trouvé PHP 8.5.12 sans `pcntl`. Le worker installé utilise cette extension pour ses alarmes : définir 600/660 secondes ne suffit donc pas à interrompre une tâche sur cet environnement. Il ne s'agit pas de rouvrir ENV-01, mais de vérifier le mécanisme nécessaire à la nouvelle limite.

**Impact :** la marge 600 < 900 n'est pas garantie en pratique : absence de consommation, relivraison prématurée ou traitement dépassant dix minutes.

**Correction minimale dans le plan :** nommer explicitement la connexion au dispatch et dans la commande, par exemple `php artisan queue:work database-imports --queue=imports --timeout=660`, avec le timeout du job fixé à 600. Documenter séparément le worker de la file `default` et empêcher qu'un worker utilisant la connexion à 90 secondes consomme `imports`. Définir l'environnement assurant l'interruption effective — worker avec `pcntl` ou mécanisme externe équivalent — puis vérifier ses prérequis. Aligner `CURRENT_TASK.md` sur ces commandes. Aucun changement de limite métier n'est demandé.

### C2. Majeur — `failed()` doit préserver un import déjà terminé, même sans passage par le traitement

**Référence :** `docs/tasks/PLAN-T-13.md:103`, `:105`, `:111`. Code lu : `backend/vendor/laravel/framework/src/Illuminate/Queue/Worker.php:499`, `:592` ; `backend/vendor/laravel/framework/src/Illuminate/Queue/Jobs/Job.php:217` ; `backend/vendor/laravel/framework/src/Illuminate/Queue/CallQueuedHandler.php:359`.

**Nature :** contradiction non levée entre le contrat de `failed()` et l'immutabilité d'un succès ; chemin Laravel confirmé par lecture, défaut applicatif encore hypothétique.

**Scénario :** la première exécution valide les apprenants, le rapport et le fichier chiffré puis s'arrête avant l'acquittement de la tâche. Après 900 secondes, une seconde réservation porte le nombre de tentatives à deux. Avec `tries = 1`, Laravel rejette la tâche **avant** d'appeler son traitement et appelle `failed()`. La garde de prise en charge ne s'exécute donc pas. Or §5 décrit `failed()` comme marquant l'import en échec et effaçant le `payload`, sans y préciser la protection d'un statut déjà terminé.

**Impact :** une application littérale de ce chemin peut transformer un import réussi en échec et produire une trace trompeuse, malgré la finalisation atomique. La règle générale « terminé jamais réécrit » est bonne, mais doit explicitement s'appliquer ici aussi.

**Correction minimale dans le plan :** imposer à `failed()` une transition atomique conditionnelle ne modifiant que les imports non terminaux concernés. Un import déjà terminé conserve statut, compteurs, rapport, mots de passe et journal de succès ; un import déjà en échec ne produit pas un second échec. Étendre cette règle à la purge. Ajouter le test précis « commit effectué, pas d'acquittement, seconde réservation dépassant les tentatives » ; le test doit parcourir le contrôle du worker, pas seulement rappeler directement le traitement du job.

### C3. Majeur — La préparation des identifiants oublie les nouveaux comptes inactifs

**Référence :** `docs/tasks/PLAN-T-13.md:96` et `:108`, décision 7. Schéma existant : `backend/database/migrations/0001_01_01_000000_create_users_table.php:19` (`password` non nullable).

**Nature :** incohérence confirmée entre deux sections du plan ; aucun compte T-13 créé pour la reproduire.

**Scénario :** un fichier contient une nouvelle CNIE dont le statut est « Inactif ». §4 exige un mot de passe et son hachage préparés pour l'écriture du nouveau compte, mais §5 étape 1 ne prépare les hachages que pour les nouveaux comptes actifs. Aucun autre chemin d'initialisation du champ obligatoire `users.password` n'est prévu pour cette ligne.

**Impact :** le chemin inactif peut échouer et annuler tout l'import, ou réintroduire un hachage pendant l'écriture alors que le plan l'interdit. Le compte inactif doit bien exister, même sans e-mail ni entrée dans le fichier des mots de passe.

**Correction minimale dans le plan :** préparer hors transaction les identifiants de **tous les nouveaux comptes**, actifs et inactifs. Réserver aux seuls actifs la notification et l'ajout dans la copie chiffrée des mots de passe. Ajouter un test de création effective d'un nouveau compte inactif, avec un mot de passe correctement initialisé, aucune notification, aucune entrée dans le fichier et aucun hachage sous les verrous. Cela applique la décision 7 sans la modifier.

### C4. Mineur — La purge décrite ne borne pas encore les temporaires à une heure

**Référence :** `docs/tasks/PLAN-T-13.md:57`.

**Nature :** borne documentaire incomplète, confirmée ; dépassement potentiel dépendant de la fréquence choisie.

**Scénario :** une commande supprimant seulement les dossiers de plus d'une heure s'exécute toutes les heures. Un dossier créé juste après son passage peut rester presque deux heures. Même avec une exécution chaque minute, le maximum n'est pas strictement une heure. Le plan ne fixe pas cette cadence.

**Impact :** la durée de conservation en clair peut dépasser le risque résiduel présenté comme accepté. Le nettoyage normal en `finally` ne traite pas ce scénario d'arrêt brutal.

**Correction minimale dans le plan :** préciser la fréquence, l'horodatage de référence et un seuil compatible avec la borne acceptée en fonctionnement normal du planificateur ; par exemple, seuil de 55 minutes et passage toutes les cinq minutes. Décrire également la limite de cette garantie si le planificateur ne fonctionne pas. Tester le pire positionnement entre deux passages. Aucun stockage en mémoire ni nouvelle bibliothèque n'est exigé.

## Questions au responsable technique

Les trois questions du rapport initial sont closes. Deux précisions techniques restent utiles pour finaliser le plan :

1. Quel environnement exécutera réellement les imports avec une interruption à 600 secondes, puisque la commande actuelle vise PHP Windows sans `pcntl` ? Le choix doit être documenté avec C1.
2. Confirmer le cycle de vie de la clé de confirmation : même clé pour rejouer **la même demande** après une erreur réseau ; nouvelle clé après changement de fichier/choix, nouvel aperçu confirmé ou action « Recommencer ». Prévoir un refus explicite si une clé déjà utilisée est présentée avec un contenu différent, plutôt que de retourner silencieusement un ancien import. Ce complément précise §3/§9 sans changer leur idempotence.

## Validation et limites

**Réalisé :** lecture du prompt et des consignes, du plan v2 et du diff documentaire ; comparaison ciblée avec la revue initiale et les sources Laravel installées ; vérification de la branche/base ; diagnostics CLI `php -v` et `php --ri pcntl` sur Windows. Résultats : **PHP 8.5.12**, extension **`pcntl` absente**. Ces diagnostics ne sont ni un test d'import ni un test de timeout.

**Non réalisé :** aucun test applicatif, banc concurrent MySQL, exécution de worker, installation d'OpenSpout, mesure des 1 000 comptes, build, Pint ou Larastan. Aucun comportement de T-13 n'est présenté comme reproduit à l'exécution : son code n'existe pas. Les tests à deux connexions, de relivraison et de nettoyage sont des contrôles à écrire et lancer après développement. Aucun résultat nouveau ne permet d'approuver un code applicatif.

Le rapport initial a été conservé ; seul le présent fichier a été créé. Aucun fichier réel d'apprenants n'a été consulté.

## Verdict

**Plan à revoir.**

Les corrections de validation, d'empreinte, de verrous et de droits sont satisfaisantes dans le plan. Avant développement, corriger les trois points majeurs : connexion/environnement du worker, préservation du succès dans `failed()` et préparation des nouveaux comptes inactifs. La cadence de purge nécessite aussi la précision mineure C4.
