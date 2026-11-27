# Revue du code T-20 — Téléversement, remplacement et retrait du CV

- **Date :** 27/11/2026.
- **Branche :** `feature/T-20-cv` ; base `develop`, `2f8a9d8` ; changements non commités.
- **Références :** `AGENTS.md`, `CLAUDE.md`, plan T-20 v1.2 et décisions 1 à 11, décision du 26/11 sur le retrait, entrée T-20 de `PROGRESS.md`, CDC CV-01/CV-04, sections 7, 8.7, 11.6, ADM-03 et matrice 3.2 ; maquette `cme-01-cv-apprenants.png`.

## Périmètre

Examen du diff avec `develop` et des fichiers T-20 non suivis : services CV, contrôleur, modèles et migration, intégration aux apprenants et à leur recherche, routes, traitement des tailles, nettoyage, audit, Dockerfile PHP, composants et écrans React, navigation et tests associés. Les exclusions du prompt ont été respectées.

Les décisions validées sont conservées, notamment le contrôle PDF simple sans dépendance, la limite acceptée sur les flux compressés, l'absence de consultation du fichier avant T-21 et le report de l'import CME à T-22. Aucun vrai CV ni fichier du dossier Téléchargements n'a été ouvert. Seul ce rapport est ajouté au dépôt ; aucun code modifié, commit ou push.

## Constats

### 1. Majeur — Des octets d'image désynchronisent le scanner et masquent une action JavaScript en clair

**Fichier/ligne :** `backend/app/Services/Cv/PdfInspector.php:132`, avec `skipLiteral()` à la ligne 101. **Référence :** plan §4, décision 9, refus des noms actifs hors des flux compressés.

**Nature : bug confirmé**, d'abord en mémoire puis par une requête au noyau HTTP Laravel sur MySQL.

**Scénario reproductible :** construire un PDF fictif d'une page, avec `/OpenAction 7 0 R` dans le catalogue. Un objet image RGB de quatre pixels contient exactement douze octets, annoncés par `/Length 12` : `endstream (` suivi d'un octet nul. Après la véritable fin de ce flux, l'objet 7 contient `/S /JavaScript /JS (app.alert\(1\))`. Les références et décalages sont calculés par le générateur de test.

`skipStream()` s'arrête à la première sous-chaîne `endstream`, ici dans les pixels, sans utiliser la longueur du flux. Le scanner interprète ensuite la parenthèse des pixels comme le début d'une chaîne et ignore le dictionnaire actif qui suit. Le banc obtient `PdfInspector::problem() === null`, puis **HTTP 201**, alors que l'attente de refus 422 échoue. En remplaçant les seuls pixels par douze lettres ordinaires, l'inspecteur renvoie `actif:JavaScript`.

Les noms interdits sont **en clair, hors du flux** : ce défaut dépasse la limite acceptée sur les contenus compressés. La longueur annoncée délimite les données binaires d'un flux ; chercher sa première occurrence textuelle de `endstream` ne suffit pas. Voir la [référence Adobe PDF 1.6, §3.2.7, « Stream Extent »](https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/pdfreference1.6.pdf#page=59).

**Impact :** un PDF actif est enregistré malgré le contrôle annoncé ; le même inspecteur doit être réutilisé par T-22 et le document sera consommé par T-21. Aucun JavaScript n'a été exécuté pendant la revue ; une exploitation dans un lecteur n'a pas été démontrée.

**Correctif minimal :** délimiter les flux à partir de leur longueur et vérifier leur terminaison, avec un refus explicite si cette délimitation n'est pas maîtrisée. Ajouter le cas d'image ci-dessus, en conservant les tests de chaînes, commentaires, noms échappés et PDF à objets compressés. Une recherche textuelle plus restrictive de `endstream` ne constitue pas une garantie suffisante.

**Rare et sans impact aval ?** Fichier volontairement construit, fréquence non mesurée ; impact sur T-21/T-22, donc pas un défaut sans conséquence aval à reporter.

### 2. Majeur — Le nettoyage peut supprimer une préparation après son contrôle d'existence, avant son adoption

**Fichiers/lignes :** `backend/app/Services/Cv/CvService.php:76` et `backend/app/Services/Cv/CvStorage.php:75`. **Référence :** plan §5, cycle préparé/adopté/abandonné et garantie de ne pas référencer un fichier disparu.

**Nature : défaut confirmé par un entrelacement déterministe**, avec ancienneté simulée ; pas une course reproduite entre deux processus réels.

**Scénario reproductible :** une préparation existe mais devient éligible au nettoyage après une attente exceptionnelle de plus d'une heure. L'envoi constate son existence ; avant l'insertion de `cv_documents`, le nettoyeur constate l'absence de référence et supprime le fichier ; l'envoi poursuit et valide sa transaction.

Le banc injecte uniquement un double de `CvStorage::exists()` : il conserve le résultat vrai du contrôle réel, vieillit le fichier à deux heures, exécute le véritable `sweepOrphans()`, puis rend ce résultat. **Un fichier est supprimé ; la réponse est 201 ; la ligne courante existe, mais son fichier est absent.** L'assertion de présence après succès échoue. Le nettoyeur peut aussi travailler à partir d'une liste de références devenue périmée avant la suppression effective.

**Impact :** CV annoncé comme présent mais inutilisable par T-21 ; en remplacement, l'ancien fichier peut ensuite être supprimé normalement. Le contrôle d'existence traite une suppression antérieure au contrôle, mais ne protège pas la fenêtre suivante.

**Correctif minimal :** coordonner adoption et nettoyage par un verrou partagé par chemin, ou un mécanisme équivalent de préparation active. Le nettoyeur doit reprendre une vérification de référence fraîche sous cette protection ; l'envoi doit garder la protection du contrôle d'existence jusqu'au commit. Ajouter l'entrelacement ci-dessus au test existant de préparation disparue. Un second `exists()` seul déplacerait la fenêtre.

**Rare et sans impact aval ?** **Très rare** : suppose une préparation vieille de plus de 60 minutes, par exemple après suspension prolongée du processus ; les envois ordinaires restent protégés par le délai. L'impact sur le document courant de T-21/T-22 interdit néanmoins de le qualifier de défaut sans effet aval.

### 3. Mineur — Le panneau CV conserve un état périmé lorsque son apprenant sort de la liste

**Fichier/ligne :** `frontend/src/pages/LearnersPage.tsx:576`, avec `frontend/src/cv/useCvOperations.ts:53`. **Référence :** plan §7, état à jour après succès et réponse tardive après changement de filtre.

**Nature : bug confirmé** avec les composants réels, API simulée et réponse d'envoi différée.

**Scénario reproductible :** dans `/apprenants`, ouvrir le panneau CV de A, sans CV ; lancer un envoi ; changer la recherche pour ne garder que B ; terminer l'envoi de A avec une réponse 201 contenant son nouvel état. La relecture de la liste ne contient toujours que B.

Le panneau de A affiche **« CV ajouté. » et « Aucun CV »**, garde « Téléverser (PDF) » et ne propose pas « Retirer ». `rows.find(...)` échoue et réutilise la copie initiale `panel.learner`. Le nouvel état reçu par `uploadCv()` est ignoré. Le test supplémentaire échoue sur l'attente de disparition de « Aucun CV ».

**Impact :** information et actions incohérentes dans le panneau ; le CV est bien enregistré. Le même mécanisme peut conserver un état périmé après retrait.

**Correctif minimal :** fermer le panneau CV au changement de filtre/page, ou maintenir son état indépendamment de la page de résultats en appliquant la réponse de mutation. Tester cet enchaînement dans `/apprenants`, en plus du test existant sur les lignes de `/cv`.

**Rare et sans impact aval ?** Enchaînement occasionnel pendant un envoi ; défaut d'interface sans atteinte aux données ni dépendance bloquante identifiée pour les tâches suivantes.

### 4. Mineur — Une suppression concurrente fait répondre 409 à la CME au lieu du 404 décidé

**Fichiers/lignes :** `backend/app/Http/Controllers/CvController.php:45`, `:111` et `backend/app/Services/Cv/CvService.php:167`. **Référence :** décision 10 et plan §6.

**Nature : bug confirmé par un entrelacement déterministe** entre la recherche initiale et la relecture sous verrou.

**Scénario reproductible :** la CME commence un téléversement sur une fiche non supprimée ; après `CvController::find()`, le compte apprenant est supprimé logiquement ; `lockAccount()` voit cette suppression et lève `learnerDeleted()`. Le contrôleur transmet alors le statut de l'exception sans tenir compte du rôle.

Le banc effectue cette suppression pendant la préparation du fichier, après la recherche initiale, puis poursuit le véritable service : **409 `learner_deleted`**, alors que l'assertion 404 échoue. Le contrôle initial donne bien 404 si le compte était déjà supprimé avant la requête.

**Impact :** contrat de visibilité incohérent selon le moment de la suppression. L'opération reste refusée ; aucun accès au contenu du CV n'est ouvert par ce défaut.

**Correctif minimal :** dans la traduction HTTP du refus `learner_deleted`, appliquer aussi la distinction administrateur → 409, CME → 404, y compris lorsque le refus vient de la relecture sous verrou. Ajouter ce scénario au test des droits.

**Rare et sans impact aval ?** Rare concurrence entre suppression et action CV ; effet limité au statut/message, sans dépendance bloquante identifiée pour les tâches suivantes.

## Points vérifiés sans remarque

- Permissions serveur conservées : ajout/retrait et historique réservés à l'administrateur et à la CME ; Direction limitée à l'état du CV. Routes React et navigation cohérentes avec ces droits. Les réponses exposent les métadonnées prévues, sans chemin, empreinte ni nom d'origine.
- Stockage privé chiffré, noms aléatoires, vérification de l'écriture et déchiffrement avec une ancienne clé ; aucun lien de lecture public ajouté dans T-20.
- Un seul document courant par apprenant. Remplacement, même empreinte sans mutation ni nouveau journal/historique, retrait confirmé côté interface, apprenant désactivé accepté, CV conservé après suppression logique.
- Verrous compte → fiche → CV, état supprimé relu sous verrou, une tentative SQL, erreurs de concurrence traduites en 409 `retry`, garde contre une transaction englobante. Les tests MySQL à deux connexions avec preuve d'attente passent ; cela ne démontre pas l'absence de tout interblocage possible.
- Historique et audit dans la transaction ; rollback en cas d'échec ; suppression de l'ancien fichier après validation et reprise par nettoyage si elle échoue. Réserve du constat 2 pour la coordination avec les préparations anciennes.
- Filtre CV et compteur sans CV appliqués avant pagination, compteur calculé sans le filtre CV lui-même ; intégration au contrat des filtres de sélection en masse de T-12.
- Nouvel essai avec le même fichier, double déclenchement et exclusion envoi/retrait par apprenant ; messages attachés au bon apprenant. Bancs supplémentaires réussis : erreur réseau, 409 `retry`, `no_cv`, `learner_deleted`, puis nouvel essai ; historique en erreur 503 puis « Réessayer ». Les réponses de ces bancs frontend sont simulées.
- Catégorie d'audit réutilisée et libellée pour les ajouts/retraits ; conservation métier de `cv_history` ; absence de nouvelle dépendance.

## Qualité des tests

Les tests fournis passent mais ne couvrent pas les quatre constats : leurs flux PDF ne désynchronisent pas le scanner ; la disparition de préparation est simulée avant le contrôle d'existence ; le test de réponse tardive porte sur les lignes de `/cv`, pas sur le panneau persistant de `/apprenants` ; les cas de fiche supprimée côté CME testent une suppression déjà visible au début.

Deux limites complémentaires :

- `backend/tests/Support/FakePdf.php:31` déclare `/L 1000` alors que la fixture mesure **563 octets**. Son acceptation ne prouve donc pas celle d'un véritable PDF linéarisé : la spécification prévoit de traiter un fichier dont `/L` ne correspond pas à sa taille comme un PDF ordinaire. Remplacer cette fixture par un export fictif réellement linéarisé renforcerait la couverture annoncée. Voir la [référence Adobe, annexe F, table F.1](https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/pdfreference1.6.pdf#page=969).
- Le test des exports bureautiques est explicitement sauté faute de fixtures. Les dépassements PHP sont simulés avec un `UploadedFile` en erreur et un `CONTENT_LENGTH` surdimensionné ; ils vérifient la traduction Laravel, pas le passage multipart réel par PHP. Sans session, le test explicite porte sur POST ; les gardes communes des deux autres routes ont été lues.

## Validation et limites

### Contrôles exécutés localement

Backend dans Docker, **PHP 8.4.28**, **MySQL 8.0.46**, base **`jobdating_test`**, jamais SQLite. Absence d'autre suite vérifiée avec `docker ps` avant chaque lancement ; lancements backend successifs.

| Contrôle | Résultat observé |
|---|---|
| `docker compose run --rm -T -e DB_DATABASE=jobdating_test -e APP_ENV=testing php php artisan test tests/Feature/Cv` | **36 réussis, 1 sauté, 197 assertions**, code 0 ; environ 238 s. |
| `npm.cmd exec -- vitest run src/cv/cv.test.tsx`, dans `frontend/` | **6 tests réussis**, code 0. Node **24.21.0**, Vitest **5.1.0**. |
| Bancs backend externes, filtrés, utilisant les fixtures fictives et le véritable noyau Laravel | Trois attentes de sécurité/cohérence **échouent**, soit 6 assertions sur deux lancements : 201 au lieu de 422 ; fichier adopté absent ; 409 au lieu de 404. Ils reproduisent les constats 1, 2 et 4, sans ajouter de test au dépôt. |
| Banc frontend supplémentaire, injecté en mémoire par une configuration Vite temporaire externe | Six scénarios ajoutés : **5 réussis, 1 échoué** (constat 3). Les 6 tests existants passent aussi dans ce lancement, soit 11 réussis et 1 échoué. |
| Coût de `PdfInspector` en mémoire sous PHP Windows **8.5.12** | PDF fictif de 5 242 880 octets : environ **34 ms** ; fichier synthétique de 5 242 504 octets contenant de très nombreux noms : environ **601 ms**. Mesures ponctuelles, pas un benchmark de requête complète. |
| `git diff --check` | Aucun défaut d'espacement ; avertissements Git de conversion LF/CRLF. |

Les bancs supplémentaires utilisent des fichiers temporaires externes au dépôt et un stockage isolé dans le conteneur éphémère. Les entrelacements des constats 2 et 4 sont pilotés dans un seul processus : ils complètent les tests à deux connexions, sans prétendre reproduire ces deux courses entre processus réels.

**Limite de l'environnement observée :** l'image PHP utilisée conserve `upload_max_filesize=2M`, avec `post_max_size=8M`. Le Dockerfile modifié prévoit bien 6M/8M et `PROGRESS.md` indique déjà que l'image doit être reconstruite. Les tests applicatifs acceptant 5 Mio ne valident pas cette limite native. Aucun essai multipart sur un serveur HTTP réel, reconstruction d'image, contrôle navigateur ou mesure de mémoire d'une requête complète effectué.

### Résultats communiqués par Claude, non rejoués

Entrée T-20 de `docs/PROGRESS.md`, contrôles du 27/11 : **Pint et PHPStan sans erreur** ; suite backend complète **591 tests réussis, 1 sauté, 3 119 assertions**, code 0 ; frontend lint, **128 tests** et build réussis. La suite complète, les analyses statiques, le lint et le build n'ont pas été relancés par cette revue.

## Verdict

**À corriger avant fusion.** Le refus des PDF actifs est contournable et la coordination entre nettoyage et adoption reste incomplète. Deux défauts mineurs concernent l'état du panneau CV et le statut retourné à la CME lors d'une suppression concurrente.
