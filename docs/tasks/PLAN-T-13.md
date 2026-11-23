# Plan – T-13 (JD-18) : import des apprenants (fichier YouCode School Admin)

Branche : `feature/T-13-import` (base `develop`, `81f9d02`).

**Version 2** (20/11/2026), après la revue du plan par Codex (`docs/reviews/REVIEW-PLAN-T-13.md`, « Plan à revoir », 6 constats majeurs, 1 mineur, 1 suggestion, 3 questions) et la décision du responsable technique de **choisir la classe avant l'import** : changements marqués **[v2]**. Contre-revue (`docs/reviews/REVIEW-PLAN-T-13-contre-revue.md`, « Plan à revoir », 3 points majeurs et 1 mineur) : **version 2.1**, précisions marquées **[v2.1]**, appliquées sans nouvelle contre-revue (décision du responsable technique du 20/11) ; Codex les vérifiera dans la revue du code.

**Version 2.2** (20/11/2026), pendant la contre-revue du code : l'export réel « Étudiants » filtré par campus et promotion contient **plusieurs classes** ; décision du responsable technique : **la classe vient de la colonne `Classe` de chaque ligne**, seuls le campus et la promotion sont choisis. Changements marqués **[v2.2]** ; ils remplacent la décision 2 de la version 2.

## Références

1. CDC V2 : **ADM-04** (import des apprenants, RG-IMP-01 à 07, critères d'acceptation, exceptions), ADM-03 et RG-APR-01 à 07, ADM-09 (classes), TRA-01, EXI-DATA-03 (date de naissance, ville, e-mail personnel jamais enregistrés), PC-22.
2. `docs/DECISIONS.md` :
   - 14/11 : mot de passe généré ; pour un import, **fichier des mots de passe** (Excel : Prénom, Nom, E-mail, Classe, Mot de passe) téléchargeable **plusieurs fois** jusqu'à sa suppression manuelle, conservé **chiffré**, chaque téléchargement et la suppression journalisés ; première connexion **sans changement obligatoire** ;
   - 16/11 : filière d'une classe, contrat `ClassUsage` ;
   - 19/11 : « Apprenants (T-12) » (règle CNIE), « CNIE visible par l'administrateur, la CME et la Direction » ;
   - 20/11 : « Import des apprenants (T-13) », règle sur la classe modifiée en **[v2.2]** (classe lue sur chaque ligne).
3. Maquettes : `admin-07-import-apprenants.png` (aperçu), `admin-08-import-termine.png` (rapport). Non repris : « Téléchargeable une seule fois », « Ils devront les changer à leur première connexion » (décisions du 14/11), « Les apprenants actifs sont inscrits à [l'événement] » (T-14), la liste « Classes inconnues » **[v2]**.
4. **Fichier réel** fourni par le responsable technique : format observé ci-dessous. **Il contient de vraies données : il n'est ni copié ni utilisé** ; tests et démonstration utilisent des fichiers **fictifs** au même format.
5. Passages de relais de T-11 et T-12 (`PROGRESS.md`) : règle `App\Support\Cnie` ; `LearnerService` ; ordre de verrouillage ; liens annulés si l'e-mail change ; e-mails seulement à la validation finale.
6. Architecture §7.5 (imports, OpenSpout, file d'attente).

## Format observé du fichier réel

- **CSV** UTF-8, séparateur virgule, valeurs entre guillemets ; **en-têtes en ligne 1**, sans ligne de titre (le CDC annonçait un fichier Excel avec un titre en ligne 1).
- En-têtes : `ID`, `Prénom`, `Nom`, `Nom Complet`, `E-mail Personnel`, `E-mail Professionnel`, `Classe`, `Téléphone`, `Ville`, `Date de Naissance`, `CNIE`, `Statut`.
- Le fichier peut contenir **plusieurs classes** ; certaines sont écrites autrement que dans le référentiel (autre alphabet, espaces entre les lettres, casse).
- E-mail professionnel identique à l'e-mail personnel ; CNIE parfois en minuscules ; téléphones de formats irréguliers ; ville et date de naissance parfois vides.

## Décisions du responsable technique (20/11)

| # | Question | Réponse |
|---|---|---|
| 1 | Format accepté | **CSV et Excel (.xlsx)**. En-têtes en **ligne 1**, ou en **ligne 2** si la ligne 1 est un titre. Lecture avec **OpenSpout** (dépendance acceptée). |
| 2 **[v2.2]** | Classe | **La classe de chaque apprenant vient de la colonne `Classe` de sa ligne** (CDC ADM-04). Seuls le campus et la promotion sont choisis avant l'import. La valeur est comparée aux noms des classes **actives** des référentiels **sans tenir compte de la casse ni des espaces** ; la filière suit la classe. Classe vide → « Classe manquante » ; classe inconnue ou désactivée → « Classe inconnue ou désactivée : vérifiez les référentiels » ; deux classes actives de même nom une fois la casse et les espaces ignorés → « Classe ambiguë dans les référentiels ». Pas d'alias ; **aucune classe n'est ajoutée depuis l'import** (ajout dans les référentiels, puis nouvel aperçu). Remplace la classe choisie et imposée de la version 2. |
| 3 | E-mail professionnel identique au personnel | **Accepté tel quel** ; seule la colonne `E-mail Professionnel` est lue. |
| 4 | Données de test | Fichiers **CSV et .xlsx fictifs** au même format (avec les difficultés observées) ; jamais de données réelles. |
| 5 | Import des entreprises | **Tâche séparée**, après T-13. |
| 6 | Entre l'aperçu et la confirmation | **Le fichier est renvoyé à la confirmation** ; le serveur le relit et refait l'analyse ; si le résultat diffère de l'aperçu confirmé → **409**. Rien n'est stocké entre les deux étapes. |
| 7 | Nouvel apprenant au statut autre que « Actif » | Compte **créé désactivé**, **sans e-mail**, hors du fichier des mots de passe. |
| 8 | CNIE connue, fiche supprimée | Ligne **rejetée** : « Fiche supprimée : restaurez-la d'abord ». |
| 9 | Inscription à l'événement (RG-IMP-07) | **Reportée à T-14**. |
| 10 | Temps de traitement | **Confirmation traitée en file d'attente** ; lignes utiles conservées **chiffrées** pendant le traitement, puis effacées. |
| 11 | Fichier des mots de passe | Fichier Excel **téléchargé en clair** ; **copie chiffrée** sur le serveur, déchiffrée à chaque téléchargement. |
| 12 **[v2]** | Taille d'un fichier (H1, question 1 de Codex) | **1 000 lignes de données et 5 Mo** au plus ; un fichier XLSX dont le contenu décompressé dépasse **50 Mo** ou qui contient plus de **50 000 chaînes partagées** est refusé. Délai de traitement : **10 minutes** au plus. |
| 13 **[v2]** | Permission (question 3 de Codex) | Nouvelle permission **`learners.import`** (administrateur) : **seule** permission contrôlée, côté serveur, sur **toutes** les routes de l'import (aperçu, confirmation, historique, rapport, mots de passe). |

La question H5 de Codex (correction d'un alias) **disparaît** avec la décision 2.

## Périmètre

### 1. Lecture du fichier (`App\Services\Imports\YouCodeFileReader`)

- Accepte `.csv` (UTF-8 avec ou sans BOM ; séparateur `,` ou `;` détecté sur la ligne d'en-têtes) et `.xlsx` (première feuille). Limites de la décision 12, vérifiées **avant** la lecture des lignes pour le XLSX (taille décompressée des entrées du ZIP, nombre de chaînes partagées) **[v2]**.
- En-têtes comparés sans casse, sans accents ni espaces superflus ; obligatoires : `Prénom`, `Nom`, `E-mail Professionnel`, `Classe`, `CNIE`, `Statut` ; `Téléphone` facultatif ; `ID`, `Nom Complet`, `E-mail Personnel`, `Ville`, `Date de Naissance` **reconnus et ignorés** : leurs valeurs ne sont copiées dans **aucune** structure conservée, ni journalisées, ni renvoyées (EXI-DATA-03).
- **Types de cellules XLSX [v2]** : chaque valeur utile est convertie en **texte** (entier → chiffres sans séparateur ni exposant, zéros initiaux perdus par Excel non reconstitués ; décimal non entier ou date dans une colonne utile → ligne rejetée « Valeur non admise »). Une **formule** est refusée (« Valeur non admise »), jamais évaluée.
- Exceptions (refus explicite, rien n'est importé) : fichier vide, format illisible, en-têtes obligatoires manquants (liste), limites dépassées.
- Valeurs : espaces retirés en début et fin ; lignes entièrement vides ignorées ; numéro de ligne du fichier conservé.

**Fichiers temporaires d'OpenSpout [v2] (constat 6)** : OpenSpout écrit des fichiers temporaires (cache des chaînes partagées à la lecture, feuille XML à l'écriture). Chaque lecture et chaque génération utilisent un **dossier temporaire privé et unique** (`storage/app/private/tmp/<identifiant>`, droits restreints), configuré dans les options d'OpenSpout, **supprimé dans un bloc `finally`** ; une commande planifiée **toutes les 5 minutes** supprime les dossiers dont la date de création dépasse **55 minutes** (arrêt brutal) **[v2.1]** ; si le planificateur ne tourne pas, cette borne n'est plus garantie (à surveiller en production). Le fichier déposé n'est **jamais sauvegardé** entre les requêtes (fichier téléversé temporaire de PHP seulement). Risque résiduel accepté et documenté : un arrêt brutal entre l'écriture et le nettoyage laisse un dossier privé au plus une heure.

### 2. Analyse et aperçu (`App\Services\Imports\LearnerImportPlanner`)

Paramètres : **campus et promotion [v2.2]**, tous deux **actifs** ; la classe vient de chaque ligne.

**Validation par ligne [v2] (constat 5)**, avec les limites de T-12 et de la base :

| Contrôle | Motif de rejet |
|---|---|
| CNIE vide | « CNIE manquante » (RG-IMP-02) |
| CNIE invalide selon `App\Support\Cnie` | « CNIE invalide » (sans la valeur) |
| CNIE présente deux fois dans le fichier | les **deux** lignes : « CNIE en double » (RG-IMP-02) |
| Prénom ou nom vide, ou plus de 100 caractères | « Prénom ou nom manquant ou trop long » |
| E-mail absent, invalide (RFC) ou plus de 255 caractères | « E-mail invalide » (RG-IMP-03) |
| Téléphone de plus de 30 caractères | « Téléphone trop long » |
| Valeur non texte, formule (XLSX) | « Valeur non admise » |
| Colonne `Classe` vide **[v2.2]** | « Classe manquante » |
| Classe inconnue ou désactivée **[v2.2]** | « Classe inconnue ou désactivée : vérifiez les référentiels » |
| Deux classes actives de même nom (casse et espaces ignorés) **[v2.2]** | « Classe ambiguë dans les référentiels » |
| E-mail utilisé par **un autre compte** (autre CNIE, ou compte non apprenant, supprimés compris), comparaison **sans casse** comme l'unicité MySQL | « E-mail déjà utilisé » (RG-IMP-03) |
| Même e-mail (sans casse) sur deux lignes de CNIE différentes | les **deux** lignes : « E-mail en double » (H2) |
| CNIE connue, fiche supprimée | « Fiche supprimée : restaurez-la d'abord » |

Lignes valides : **à créer** (CNIE nouvelle), **à mettre à jour** (CNIE connue, au moins un changement), **inchangées** (CNIE connue, aucun changement de prénom, nom, e-mail, téléphone, campus, promotion, classe **ni statut** ; H4).

- **Statut** : « Actif » (casse et espaces ignorés) → actif ; toute autre valeur, vide comprise → désactivé (RG-IMP-06).
- Campus et promotion choisis appliqués à **toutes** les lignes, et classe de la ligne **[v2.2]**, y compris aux apprenants connus. Pour un apprenant connu, ses valeurs actuelles devenues inactives sont **remplacées** par les valeurs de l'import (actives).
- **Empreinte [v2] (constat 3)** : hachage SHA-256 d'une représentation **déterministe** comprenant le hachage du fichier, le campus, la promotion et, **pour chaque ligne** : numéro, décision (créer, mettre à jour, inchangée, rejet), motif, **classe retenue [v2.2]** et identifiants des cibles (compte et fiche des apprenants connus). Elle est **opaque** (aucune CNIE ni e-mail lisible) et n'est pas stockée.
- Réponse de l'aperçu : choix, lignes lues, comptes **à créer / à mettre à jour / inchangées / rejetées**, **comptes par classe [v2.2]** (lignes valides seulement), détail des rejets (numéro de ligne et motif, **sans CNIE ni e-mail**), empreinte. Si aucune ligne n'est à créer ni à mettre à jour, l'écran l'explique et la confirmation est impossible.

### 3. Confirmation (`POST /api/v1/learner-imports`)

- Corps : fichier, campus, promotion **[v2.2]**, **empreinte** de l'aperçu confirmé et **clé de confirmation** (identifiant unique généré par l'écran) **[v2]**.
- **Confirmation idempotente [v2] (constat 2)** : la clé est unique ; une confirmation renvoyée avec la même clé **et la même empreinte** (réponse perdue, double clic) renvoie **le même import**, sans en créer un second ni relancer le traitement. **[v2.1]** La même clé avec une **empreinte différente** est **refusée** (422 « Cette confirmation a déjà servi à un autre import »). L'écran prend une **nouvelle clé** après tout changement de fichier, de campus ou de promotion **[v2.2]**, tout nouvel aperçu et « Recommencer ».
- Le serveur relit le fichier et refait l'analyse ; empreinte différente → **409** « L'aperçu a changé : vérifiez-le et confirmez à nouveau », rien n'est écrit.
- Dans **une transaction** : création de l'import (statut « en attente », lignes utiles valides **chiffrées** dans `payload`), journal de confirmation ; la mise en file a lieu **après la validation** de cette transaction (`afterCommit`). Une mise en file impossible marque l'import « échec » et efface `payload`.

### 4. Création et mise à jour : adaptation de `LearnerService` [v2] (constat 1)

- La création est séparée en deux : la **préparation** (contrôles) et l'**écriture** sous verrous déjà pris. La création à la main de T-12 garde exactement son comportement (mot de passe généré, compte actif, e-mail de création).
- Pour l'import, l'écriture reçoit des **identifiants préparés** avant la transaction : mot de passe en clair (pour la copie chiffrée) **et son hachage** (enregistré tel quel, sans nouveau hachage) ; l'**état initial** actif ou désactivé ; l'e-mail de création **n'est programmé que pour un compte actif**. Le même mot de passe correspond donc au hachage enregistré, à l'e-mail et au fichier des mots de passe.
- Mise à jour d'un apprenant connu : même écriture que `update` (prénom, nom, e-mail, téléphone, campus, promotion, classe), **sans** sa propre transaction ni ses propres verrous (pris par l'import) ; liens de réinitialisation annulés si l'e-mail change ; désactivation (sessions fermées, liens annulés) ou **réactivation** selon le statut ; mot de passe, passages, évaluations, CV et compétences inchangés.

### 5. Traitement en file d'attente (`App\Jobs\ProcessLearnerImport`) [v2] (constats 2 et 4)

**Message de file** : **uniquement l'identifiant** de l'import (ni lignes, ni fichier, ni mot de passe).

**Délais et reprises** : file `imports` sur une **connexion dédiée** `database-imports` (même table `jobs`, `retry_after` = **900 s**), nommée **au moment de la mise en file** ; délai de la tâche **600 s** (décision 12) ; **une seule tentative** de la tâche (`tries = 1`) : une tâche interrompue puis relivrée après `retry_after` est marquée en échec par Laravel.

**Workers [v2.1] (C1)** : deux commandes distinctes, chacune sur sa connexion, pour qu'aucun worker à 90 s ne consomme la file `imports` :
- imports : `php artisan queue:work database-imports --queue=imports --timeout=660` ;
- e-mails et autres tâches : `php artisan queue:work database --queue=default`.
Le délai de 600 s n'est **appliqué** que si PHP a l'extension **`pcntl`** : le worker des imports tourne donc **dans Docker** (extension `pcntl` ajoutée à l'image PHP) en local et en CI, et sur le **serveur Linux** en production. Sur le PHP Windows sans Docker, le délai n'est pas appliqué : accepté pour le développement seulement.

**Prise en charge exclusive** : la tâche passe l'import de « en attente » à « en cours » par une mise à jour **conditionnelle** (une seule tâche gagne) ; un import déjà « terminé » ou « échec » n'est jamais retraité ni réécrit.

**Déroulement** :
1. Hors transaction : déchiffrement du `payload` ; **mot de passe et hachage préparés pour TOUS les nouveaux comptes**, actifs **et** inactifs **[v2.1] (C3)** — seuls les actifs reçoivent l'e-mail et figurent dans le fichier des mots de passe ; ces identifiants préparés sont **gardés en mémoire et réutilisés** par les reprises de l'étape 5 (jamais rehachés) ; lecture **fraîche** des fiches connues et de leurs classes actuelles.
2. **Transaction unique**, verrous dans un **ordre déterministe** : (a) **classes** : classes des lignes **[v2.2]** et classes actuelles des apprenants connus, par identifiant croissant ; une classe des lignes devenue inactive → échec propre ; (b) **comptes** : comptes des apprenants connus et comptes portant les e-mails du fichier, par identifiant croissant ; (c) **fiches** des CNIE du fichier, par identifiant croissant.
3. **Revalidation sous les verrous** de chaque ligne (CNIE, e-mail, fiche supprimée, classe actuelle). Si la classe actuelle d'un apprenant n'est plus celle lue à l'étape 1 (déplacement entre-temps), la transaction est **annulée** et **toute** l'opération recommence (étapes 1 à 3), sans prendre de verrou hors ordre. Si une ligne est devenue invalide, l'import échoue avec un rapport clair et **rien n'est écrit**.
4. Écritures (§4), puis **dans la même transaction** : comptes définitifs, rapport, copie **chiffrée** des mots de passe, journal de succès, statut « terminé », **effacement** de `payload`. Un arrêt après ce commit ne peut donc pas refaire l'import.
5. **Interblocage** ou déplacement de classe : l'opération entière est reprise au plus **3 fois**, avec des objets relus ; les e-mails programmés par une tentative annulée sont abandonnés (`afterCommit`).

**Erreurs expurgées** : toute exception est convertie en un **message générique** (« Une CNIE ou un e-mail a été pris entre-temps : vérifiez l'aperçu et recommencez », « Erreur technique : recommencez l'import ») avant d'être enregistrée dans l'import, le journal technique ou `failed_jobs` ; le message d'origine (qui peut contenir des valeurs SQL) n'est jamais conservé.

**Imports abandonnés [v2.1]** : une commande planifiée (toutes les 5 minutes) marque « échec » et efface le `payload` des imports « **en attente** » depuis plus de **20 minutes** (depuis la confirmation) et des imports « **en cours** » depuis plus de **20 minutes** comptées depuis `started_at` (et non depuis la confirmation).

**États terminaux protégés [v2.1] (C2)** : `failed()`, la purge et la tâche passent par des **changements d'état conditionnels** (`… where status in (en attente, en cours)`) ; un import « terminé » garde son statut, ses comptes, son rapport, ses mots de passe et son journal ; un import déjà « échec » ne produit pas un second échec ; une tâche qui démarre après la purge ne retraite pas un import annulé.

### 6. Historique et fichier des mots de passe

- Table `learner_imports` : `id`, `user_id`, `campus_id`, `promotion_id`, `class_id` **[v2]**, `filename`, `confirmation_key` (unique) **[v2]**, `status` (en attente, en cours, terminé, échec), `created_count`, `updated_count`, `unchanged_count`, `rejected_count`, `rejections` (numéro de ligne et motif), `payload` (chiffré, effacé), `passwords` (chiffré), `passwords_deleted_at`, `error` (message générique), `started_at`, `finished_at`, timestamps. `payload` et `passwords` sont **masqués** de toute sérialisation (ressource dédiée à liste blanche) **[v2]**.
- `GET /api/v1/learner-imports`, `GET /api/v1/learner-imports/{id}` : historique et rapport.
- `GET /api/v1/learner-imports/{id}/passwords` : Excel généré à partir de la copie chiffrée (dossier temporaire privé du §1), **en clair dans le fichier téléchargé**, `no-store` ; **plusieurs téléchargements** tant qu'il n'est pas supprimé ; chaque téléchargement journalisé ; valeurs **neutralisées** contre les formules.
- `DELETE /api/v1/learner-imports/{id}/passwords` : suppression définitive ; journalisée.

### 7. Journal (RG-IMP-05)

- Catégorie « Imports et exports » : `learner_import.confirmed` (auteur, fichier, campus, promotion, classes **[v2.2]**, comptes prévus), `learner_import.completed` (comptes définitifs), `learner_import.failed` (message générique), `learner_import.passwords_downloaded`, `learner_import.passwords_deleted`.
- Créations et mises à jour d'apprenants journalisées une ligne par apprenant, comme une saisie à la main ; un **échec du journal** annule tout l'import (même transaction).
- Jamais de mot de passe, de date de naissance, de ville, d'e-mail personnel ni de CNIE en clair.

### 8. Droits

Décision 13 : `learners.import` (administrateur) sur toutes les routes de l'import ; matrice et tests pour les 5 rôles.

### 9. Frontend

- Écran **« Import des apprenants »** (`/apprenants/import`, permission `learners.import` ; lien après « Apprenants », ordre de la maquette `admin-01`) :
  1. **campus et promotion [v2.2]** (valeurs actives), **fichier** (.csv ou .xlsx) ;
  2. **aperçu** (maquette `admin-07` sans la liste des classes inconnues) : comptes, **comptes par classe [v2.2]**, lignes rejetées (ligne et motif), colonnes non enregistrées ; « Confirmer l'import (N apprenants) » ;
  3. **traitement** : « Import en cours… » (état interrogé toutes les 3 secondes), puis **rapport** (maquette `admin-08` adaptée) : comptes définitifs, « Télécharger le fichier des mots de passe » (« téléchargeable jusqu'à sa suppression · chaque téléchargement est tracé »), « Supprimer le fichier des mots de passe » avec confirmation ; en cas d'échec, message générique et « Recommencer ».
- **Historique** des imports, avec accès au rapport et au fichier des mots de passe tant qu'il existe.
- Changer de fichier, de campus ou de promotion **efface l'aperçu** ; une réponse arrivée après un changement est ignorée ; 409 → nouvel aperçu et nouvelle confirmation ; la **clé de confirmation** est conservée pour renvoyer la même confirmation après une erreur réseau.

### 10. Dépendance [v2] (suggestion 8)

- **`openspout/openspout` 5.15.3** (lecture CSV et XLSX, écriture XLSX), PHP 8.4 accepté. Extensions requises : `dom`, `filter`, `libxml`, `xmlreader`, `zip` — `zip` est déjà installée dans l'image Docker et la CI ; les autres sont présentes par défaut dans les images PHP officielles et `setup-php` : **à vérifier** au moment de l'ajout (Docker, CI, PHP Windows).
- Licence : MIT pour le code récent, **Apache-2.0** pour le code hérité (projet Box/Spout) : notices conservées dans `vendor`.

## Hors périmètre

Import des entreprises (**tâche séparée**) ; inscription à l'événement (**T-14**) ; CV (**T-20 à T-22**) ; import des compétences ; ajout de classe depuis l'import **[v2]** (se fait dans les référentiels avant l'import).

## Hypothèses

- **H1** : tranchée (décision 12) ; **mesure** d'un import fictif de 1 000 nouveaux comptes sur PHP 8.4 / MySQL 8 après implémentation.
- **H2** : un même e-mail (sans casse) sur deux CNIE différentes fait rejeter les deux lignes (avis favorable de Codex).
- **H3** **[v2.2]** : une ligne dont la classe est inconnue ou désactivée est rejetée ; les autres lignes restent importables.
- **H4** : apprenants connus sans aucun changement, statut compris, comptés « inchangés » ; un second import identique n'écrit rien, n'envoie rien et ne génère aucun mot de passe (avis favorable).
- **H5** : sans objet (plus d'alias).

## Tests (résumé)

- **Lecture** : CSV (virgule, point-virgule, BOM), XLSX, titre en ligne 1 ou non, en-têtes manquants, fichier vide, illisible, limites (lignes, taille, contenu décompressé, chaînes partagées) ; types de cellules et formules ; colonnes ignorées **jamais** retrouvées dans la base, le journal, `jobs`, `failed_jobs`, les journaux techniques ni le dossier temporaire après traitement (valeurs sentinelles fictives).
- **Analyse** : chaque motif de rejet, bornes de longueur, fichier mélangeant une ligne invalide et une valide (seule la valide est importée) ; **[v2.2]** fichier à plusieurs classes, classe inconnue, désactivée ou vide rejetée, classe en casse ou espacement différents acceptée ; statut ; campus ou promotion inactifs refusés.
- **Empreinte** : 409 quand les lignes acceptées et rejetées changent **à comptes constants** ; 409 quand le fichier, le campus ou la promotion changent, ou quand une classe est désactivée entre l'aperçu et la confirmation.
- **Confirmation** : même clé renvoyée → même import ; mise en file impossible → échec propre.
- **Traitement** : tout ou rien ; **nouveau compte inactif créé avec un mot de passe initialisé, sans e-mail ni entrée dans le fichier [v2.1]** ; **commit effectué puis tâche relivrée au-delà des tentatives : l'import reste « terminé » [v2.1]** (via le contrôle du worker) ; purge et `failed()` sans effet sur un import terminé ; reprise sans nouveau hachage ; classe désactivée pendant l'attente en file → échec propre ; colonne `Classe` vide → ligne rejetée ; e-mails seulement après la validation finale, seulement pour les actifs créés ; même mot de passe dans le hachage, l'e-mail et le fichier ; aucun e-mail pour un nouveau compte inactif ni après une annulation ; hachage hors transaction ; liens annulés au changement d'e-mail ; désactivation et réactivation ; compétences et mot de passe des apprenants connus inchangés ; `payload` effacé après succès et après échec ; double livraison de la tâche, arrêt avant et après commit ; import abandonné nettoyé ; échec du journal → rien d'écrit ; erreurs expurgées.
- **Concurrence (MySQL, deux connexions)** : import contre changement de filière de la classe, import contre désactivation en masse, deux imports simultanés.
- **Second import du même fichier** : aucun doublon, aucun mot de passe ni e-mail, tout « inchangé ».
- **Fichier des mots de passe** : contenu (actifs créés seulement), plusieurs téléchargements, suppression puis 404, journal, `no-store`, neutralisation des formules, dossier temporaire vide après génération.
- **Droits** : chaque route pour les 5 rôles (`learners.import`).
- **Frontend** : étapes, aperçu effacé au changement, réponse tardive ignorée, erreurs d'aperçu, de confirmation, de suivi et de téléchargement suivies d'un nouvel essai, confirmation renvoyée après erreur réseau (même import), 409, rapport, téléchargement et suppression.
- **Mesure** : import fictif de 1 000 nouveaux comptes (durée, mémoire).
