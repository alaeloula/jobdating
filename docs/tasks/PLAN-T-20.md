# Plan – T-20 (JD-22) : téléversement, remplacement et retrait du CV

Branche : `feature/T-20-cv` (base `develop`, `2f8a9d8`).

**Version 1.3** (27/11/2026) : décision 9 révisée par le responsable technique après la contre-revue du code (contrôle de structure seulement ; point 3 « contenu actif » du § 4 retiré).

Version 1.2 (26/11/2026) : corrections de la contre-revue Codex (`docs/reviews/REVIEW-PLAN-T-20-contre-revue.md`, « Plan à revoir » : N1 fichier préparé pendant les reprises et opérations longues, N2 PDF à objets compressés, N3 deux limites PHP).

Version 1.1 : corrections de la revue Codex (`docs/reviews/REVIEW-PLAN-T-20.md`, « Plan à revoir » : verrou du compte, cycle de vie des fichiers, contrôle du PDF, dépassement de taille) ; décisions 5 à 8 confirmées et décisions 9 à 11 prises par le responsable technique le 26/11. **Aucun code n'est écrit.**

## Références

1. CDC V2 : CV-01, CV-04 (4.6) ; section 7 (7.1, 7.2, 7.5, 7.6) ; 8.7 ; 11.6 ; ADM-03 (étape 6, RG-APR-03, RG-APR-05, critère « état du CV ») ; matrice 3.2 (lignes « Téléverser ou importer le CV », « Consulter l'historique des ajouts de CV ») ; TRA-01.
2. `docs/DECISIONS.md` : **26/11 « Retrait d'un CV (T-20) »** (retrait possible, taille maximale 5 Mo) ; 19/11 (apprenants : suppression logique et restauration).
3. ARCHITECTURE : 3 (tableau « CV »), 7 (stockage privé, contenu chiffré avec la clé de l'application), 8 (tables `cv_documents`, `cv_import_logs`, `cv_access_logs`).
4. Maquette `cme-01-cv-apprenants` (écran « CV des apprenants ») ; `admin-06-apprenants` (liste des apprenants).
5. Code existant : `LearnerController`, `LearnerService`, `LearnerResource`, recherche `POST /learners/search`, `LearnersPage.tsx` (panneaux), `CompanyService` (logo : disque et suppression de fichier), `AuditAction` / `AuditCategory`, commande `imports:cleanup`.

## 1. Décisions

| # | Sujet | Décision | Source |
|---|---|---|---|
| 1 | Taille maximale | **5 Mo** | DECISIONS 26/11 |
| 2 | « Historique » | **Journal des ajouts** (CV-04) ; une seule version du fichier conservée | CDC 8.7, réponse du 26/11 |
| 3 | Retrait | **« Retirer le CV »** : administrateur et CME, confirmation, fichier supprimé définitivement, inscrit à l'historique et au journal | DECISIONS 26/11 |
| 4 | Affichage | État du CV sans aperçu dans T-20 ; ouverture du PDF par lien temporaire, écran apprenant et côté entreprise à partir de T-21 | réponse du 26/11 |
| 5 | Même fichier téléversé à nouveau (même empreinte, comparée sous verrou) | **Rien ne change** : source, auteur et date actuels conservés ; réponse « CV identique au CV actuel » ; pas de ligne d'historique ni d'audit ; le fichier préparé est supprimé. T-22 journalisera quand même son propre résultat « inchangé » | confirmé le 26/11 |
| 6 | Apprenant **désactivé** | Téléversement et retrait **autorisés** ; cela ne réactive ni le compte ni sa participation et n'ouvre aucun accès entreprise | confirmé le 26/11 |
| 7 | Apprenant **supprimé** (logiquement) | Téléversement et retrait **refusés** ; son CV est **conservé** pour une restauration. Visibilité (décision 10) | confirmé le 26/11 |
| 8 | Table de l'historique | **`cv_history`**, commune aux téléversements, remplacements, retraits et résultats d'import de T-22 (remplace `cv_import_logs` d'ARCHITECTURE 8 ; aucune seconde table) | confirmé le 26/11 |
| 9 | Contrôle du contenu du PDF | **Révisée le 27/11 : contrôle de STRUCTURE seulement, sans dépendance** (§ 4, partie « structure ») ; le contenu actif n'est **plus recherché** (la recherche faite main restait contournable et refusait des PDF valides : contre-revue du code, N1 et N2). Les CV ne sont déposés que par l'administrateur et l'équipe CME ; **T-21 servira le PDF isolé**, jamais exécuté par JobDating. Aucune garantie antivirus annoncée ; qpdf reste possible plus tard | 26/11, révisée le 27/11 par le responsable technique |
| 10 | Visibilité d'un apprenant supprimé | CME et Direction : **404**, comme aujourd'hui ; administrateur : **409** `learner_deleted` (« restaurez-le d'abord ») ; l'historique CV d'un apprenant supprimé est réservé à l'administrateur | 26/11 |
| 11 | Durée de `cv_history` | **Donnée métier**, conservée tant que l'apprenant existe (comme ses passages) ; jamais purgée avec les journaux techniques ; aucun ancien PDF conservé | 26/11 |

## 2. Périmètre

**Inclus** : téléversement et remplacement (CV-01), retrait (décision 3), historique des ajouts par apprenant (CV-04), état du CV dans la liste et la fiche des apprenants (ADM-03), écran « CV des apprenants » (maquette `cme-01`, sans l'import), journal d'audit, nettoyage des fichiers orphelins.

**Exclus** : lien temporaire, aperçu, téléchargement et journal des consultations (`cv_access_logs`) → **T-21** ; import depuis la plateforme CME, cases à cocher et boutons « Importer (CME) » de la maquette, bandeau « Dernier import » → **T-22** ; écran « Mon CV » de l'apprenant (`apprenant-04`) → T-21 ; affichage côté entreprise → T-23, T-24.

## 3. Modèle de données (migration `2026_11_26_100000_create_cv_tables.php`)

- **`cv_documents`** : `id`, `learner_id` (**unique**, clé étrangère, `restrictOnDelete`), `source` (`televerse` | `plateforme_cme`), `added_by` (utilisateur, `nullOnDelete`), `added_by_name` (nom au moment de l'ajout, pour l'affichage si le compte disparaît), `path`, `sha256` CHAR(64), `size` (octets), `mime`, `cme_reference` (nullable, T-22), `created_at`, `updated_at`.
- **`cv_history`** (ajout seul) : `id`, `learner_id`, `action` (`televerse` | `remplace` | `retire` ; T-22 ajoutera `importe` | `inchange` | `echec`), `source`, `user_id` (`nullOnDelete`), `user_name`, `sha256` (nullable), `size` (nullable), `reason` (nullable, T-22), `created_at`. Index (`learner_id`, `created_at`).
- Modèles `CvDocument`, `CvHistoryEntry` ; relation `Learner::cv()` (hasOne).

## 4. Stockage et contrôles du fichier (`App\Services\Cv\CvStorage`)

- Disque **`local`** (privé, `storage/app/private`), dossier `cvs/`, nom aléatoire (`Str::uuid()` + `.bin`) : ni nom d'apprenant ni nom d'origine dans le chemin.
- Contenu **chiffré** avec `Crypt::encryptString` (clé de l'application) ; `sha256` et `size` calculés sur le PDF **en clair** ; le nom d'origine du fichier n'est pas conservé.
- Contrôles (RG-CV-05), dans cet ordre, sur la **taille physique reçue** (pas celle annoncée par le client) :
  1. **reconnaissance** : fichier présent et non vide ; **≤ 5 Mo** (`max:5120`) ; extension `.pdf` ; type réel `application/pdf` (`finfo`) ; signature `%PDF-1.` dans les 1 024 premiers octets ;
  2. **structure** (`PdfInspector`, sans dépendance, lecture bornée au fichier de 5 Mo au plus) : marqueur `%%EOF` dans les 1 024 derniers octets ; `startxref` suivi d'un nombre ; table `xref` **ou** flux de références (`/Type /XRef`) ; au moins un `obj … endobj` ;
     - **page** (contre-revue, N2) : si le fichier contient des **objets compressés** (`/ObjStm`), la page n'est pas lisible en clair et **n'est pas exigée** (les autres contrôles suffisent) ; sinon, au moins un objet `/Type /Page` (hors `/Pages`) ou un `/Count n` (n ≥ 1) dans un objet `/Pages` est exigé ;
     - le faux PDF de 67 octets de la revue et un PDF tronqué sont refusés ; un PDF à objets compressés, un PDF à table classique et un PDF **linéarisé** valides sont acceptés ;
  3. **contenu actif** : refus si un **nom PDF** (jeton commençant par `/`, hors chaînes `( … )` et commentaires `%`) vaut `/JavaScript`, `/JS`, `/Launch`, `/EmbeddedFile`, `/RichMedia` ou `/XFA`, y compris sous forme échappée en hexadécimal (`#4A` …) dans les noms. **Limite assumée** : un contenu caché dans un flux compressé n'est pas détecté ; le PDF n'est jamais exécuté côté serveur, et T-21 le servira en pièce isolée (en-têtes `Content-Security-Policy: sandbox`, `X-Content-Type-Options: nosniff`) ;
  - message unique et clair : « Le CV doit être un fichier PDF valide de 5 Mo au plus. » ; motif précis seulement dans les tests, jamais le contenu dans les journaux.
- **Limites PHP** : `upload_max_filesize` vaut **2 Mo** dans l'image Docker (et par défaut dans Herd) → passer à **6 Mo** dans `docker/php/Dockerfile` (`post_max_size` 8 Mo inchangé) ; à reporter sur le serveur (ARCH-01) et dans Herd en local.
- **Dépassement** (revue Codex, suggestion 4 ; contre-revue, N3) — trois cas distincts, tous avec le message du CV **seulement pour `POST /api/v1/learners/{id}/cv`** (test sur la méthode et le chemin de la requête, la route pouvant ne pas être résolue) ; les autres routes (imports XLSX, logos) gardent leur message actuel :
  1. **plus de 5 Mo et au plus 6 Mo** : fichier reçu, refusé par la règle `max:5120` → 422 ;
  2. **plus de 6 Mo, corps de la requête au plus 8 Mo** : PHP refuse le **fichier seul** (`UPLOAD_ERR_INI_SIZE`), la requête arrive → règle `uploaded` → 422, message du CV par la surcharge du message de validation ;
  3. **corps de la requête de plus de 8 Mo** : `ValidatePostSize` lève `PostTooLargeException` (413) → rendu en 422 avec le message du CV pour cette route uniquement.
- Rotation de `APP_KEY` : les CV deviennent illisibles sans `APP_PREVIOUS_KEYS` → **risque noté** dans PROGRESS et ARCH-01.

## 5. Service (`App\Services\Cv\CvService`)

**Ordre des verrous** (revue Codex, constat 1) : **compte (`users`, suppression logique incluse) → fiche (`learners`) → `cv_documents`**. L'état « supprimé » est porté par `users.deleted_at` : il est **relu sous le verrou du compte**, jamais pris dans une relation déjà chargée. C'est l'ordre de T-12/T-13 (`LearnerService::destroy` et `restore` verrouillent le compte) et la fin de celui de T-14 (événement → classes → comptes → fiches). Aucun verrou de classe ni d'événement : le CV n'en dépend pas. Résultat selon l'ordre de validation : opération CV validée avant la suppression → conservée (le CV reste attaché à l'apprenant supprimé) ; suppression validée avant le contrôle → refus (décisions 7 et 10).

**Cycle de vie d'un fichier** (revue Codex, constat 2) — trois états :
- **préparé** : nouveau fichier chiffré écrit **hors transaction** ; l'écriture est **vérifiée** (retour de `put` et existence), le disque `local` ayant `throw = false` ; échec → 500 « Le CV n'a pas pu être enregistré, réessayez », rien en base ;
- **adopté** : la transaction qui le référence dans `cv_documents` est **validée** ; à partir de là, il n'est plus jamais supprimé par ce traitement ;
- **abandonné** : seulement à la **fin définitive** du traitement — échec (exception, interblocage), même empreinte (décision 5) ou apprenant supprimé → le fichier préparé est supprimé par le bloc `try/finally` du service, **après** la sortie de la transaction (pas par un rappel `afterRollBack`).
- **Pas de nouvel essai automatique** (contre-revue, N1.a) : la transaction est lancée avec **une seule tentative** ; un interblocage fait échouer l'opération (409 `retry` « Opération concurrente, réessayez »), le fichier est abandonné et l'utilisateur réessaie. Une reprise ne peut donc jamais adopter un fichier déjà supprimé.
- **Fichier toujours présent avant l'adoption** (contre-revue, N1.b) : dans la transaction, sous les verrous et juste avant d'écrire `cv_documents`, le service vérifie que le fichier préparé **existe encore** ; sinon échec (500 « réessayez »), rien en base. Le nettoyage n'efface que des fichiers de plus d'une heure, durée très supérieure à celle d'une requête (limite d'exécution PHP et attente de verrou MySQL de 50 s) ; s'il passe quand même, l'adoption est refusée au lieu de référencer un fichier disparu.
- **Garde de transaction** : le refus d'un appel dans une transaction ouverte est vérifié **avant** toute préparation de fichier ; si l'ouverture de la transaction échoue, le `finally` supprime le fichier préparé.
- **Ancien fichier** : supprimé **après validation** (`DB::afterCommit`, rattaché à la transaction la plus externe) ; un échec de suppression est **journalisé** (journal technique, sans contenu) et ne touche **jamais** au nouveau fichier ; le fichier non référencé est repris par le nettoyage.
- **Transactions englobantes** : `CvService` **n'accepte pas** d'être appelé dans une transaction ouverte (`DB::transactionLevel() > 0` → `LogicException`) ; T-22 l'appellera hors transaction, un apprenant à la fois. Contrat écrit pour T-22.

**Téléverser** `upload(Learner, UploadedFile, User $actor)` : contrôles (§ 4) → préparé → transaction (verrous, refus si supprimé, comparaison d'empreinte, création ou remplacement avec `added_by`, `added_by_name` et date **mis à jour**, `cv_history` `televerse` ou `remplace`, audit) → adopté → ancien fichier supprimé.

**Retirer** `remove(Learner, User $actor)` : mêmes verrous ; aucun CV → 409 `no_cv` ; suppression de la ligne, `cv_history` (`retire`), audit ; fichier supprimé après validation.

**Orphelins** : la commande existante `imports:cleanup` supprime aussi les fichiers de `cvs/` **absents de `cv_documents` et plus vieux d'une heure** (préparés abandonnés après un crash, anciens fichiers non supprimés) ; un fichier récent non référencé est conservé (envoi en cours). L'âge seul ne suffit pas à garantir l'abandon : c'est la vérification d'existence avant l'adoption (ci-dessus) qui empêche toute référence à un fichier effacé.

## 6. API (`CvController`, routes sous le groupe apprenants : rôles administrateur | CME | Direction + `learners.view`)

| Méthode et route | Permission | Réponse |
|---|---|---|
| `POST /learners/{id}/cv` (multipart `file`) | `cv.upload` | 201 (ajout), 200 (remplacement ou inchangé, avec `unchanged: true`), 422 (fichier), 409 (`learner_deleted`, administrateur) |
| `DELETE /learners/{id}/cv` | `cv.upload` | 200 ; 409 (`no_cv` ; `learner_deleted`, administrateur) |
| `GET /learners/{id}/cv/history` | `cv.history` | liste paginée (20) : date, action, source, auteur, taille |

Apprenant supprimé (décision 10) : la recherche de la fiche réutilise la règle actuelle du contrôleur (**404 pour la CME et la Direction**) ; seul l'administrateur atteint le 409 et l'historique.

- **`LearnerResource`** : champ `cv` (`null` ou `{source, added_at, added_by_name, size}`) pour administrateur, CME et Direction (`cv.view`) ; jamais le chemin ni l'empreinte.
- **Recherche** `POST /learners/search` : filtre `cv` (`with` | `without`) et compteur `meta.without_cv` (mêmes filtres, sans le filtre `cv`), pour le bouton « Sans CV (n) ».
- Entreprise et apprenant : **403** sur ces routes (groupe de rôles) ; Direction : 403 sur téléversement, retrait et historique.
- Audit (`AuditCategory::Cv`, libellé « Consultations de CV » → **« CV »**) : `cv.uploaded`, `cv.replaced`, `cv.removed` avec apprenant, source, taille ; jamais le contenu ni le nom du fichier d'origine.

## 7. Frontend

- **Écran « CV des apprenants »** (`/cv`, administrateur et CME ; lien de navigation après « Apprenants », maquette `cme-01`) :
  - titre et phrase « Le dernier CV ajouté remplace le précédent. Seule la dernière version est conservée. » ;
  - filtres : **« Sans CV (n) »**, campus, filière, classe, recherche par nom ; pagination ;
  - colonnes : apprenant (campus · classe), filière, CV (« Aucun CV » en orange ; « Téléversé le [date] » ou « Plateforme CME · [date] » en vert) ;
  - actions par ligne : **« Téléverser (PDF) »** (« Remplacer (PDF) » si un CV existe), **« Retirer »** (si un CV existe, confirmation « Retirer définitivement le CV de [Prénom Nom] ? »), **« Historique »** (panneau).
  - Les cases à cocher et « Importer (CME) » arrivent avec T-22.
- **Liste « Apprenants »** : colonne « CV » (même libellé) pour les trois rôles ; dans le panneau d'un apprenant, mêmes actions pour administrateur et CME (composant commun `CvActions`).
- **Enchaînements** : bouton désactivé et « Envoi… » pendant l'envoi ; **un envoi à la fois par apprenant** (double clic ignoré) ; erreur 422 affichée sur la ligne, nouvel essai possible ; erreur réseau → message et nouvel essai ; 409 → message puis liste rechargée ; réponse arrivée après un changement de page ou de filtre → ignorée pour l'affichage, liste invalidée ; succès → message (« CV ajouté », « CV remplacé », « CV identique : rien n'a changé », « CV retiré ») et requêtes apprenants invalidées.
- Aucun contenu de fichier dans le cache TanStack.

## 8. Tests

**Backend** (`tests/Feature/Cv/CvUploadTest.php`, PDF fictifs générés par les tests) :
- droits des 5 rôles et sans session sur les 3 routes ; Direction voit l'état mais ne peut pas téléverser ; ressource sans chemin, empreinte ni nom d'origine ;
- PDF valide (fictif, une page) accepté ; refusés sans fichier écrit : fichier vide, PNG renommé en `.pdf`, **faux PDF de 67 octets** (`%PDF-1.7` seul), PDF tronqué (sans `%%EOF`), PDF sans page, PDF avec `/JavaScript`, `/Launch`, `/EmbeddedFile` (y compris nom échappé `/J#61vaScript`), fichier > 5 Mo ;
- **HTTP réel** (suggestion 4, N3) : 5 Mo accepté ; 5 Mo + 1 octet refusé ; fichier de 6,5 Mo (corps < 8 Mo, `UPLOAD_ERR_INI_SIZE`) refusé ; corps de plus de 8 Mo (413) refusé ; **même message du CV dans les trois cas** ; un import XLSX trop gros garde son message ;
- **PDF valides variés** (N2) : objets compressés (`/ObjStm` + `/Type /XRef`, page non lisible en clair), table classique, PDF linéarisé, tous fictifs et générés par les tests ; **exports fictifs** Word, LibreOffice, Canva et « Imprimer en PDF » d'un navigateur, fournis par le responsable technique (aucune donnée réelle) dans `tests/Fixtures/cv/` ; un PDF fictif dont le **texte** mentionne `/JavaScript` ou une adresse contenant `/JS` est accepté (pas de faux positif) ;
- fichier stocké **chiffré** (ne commence pas par `%PDF-`) et déchiffré égal à l'original ; lecture avec une **ancienne clé** déclarée dans `APP_PREVIOUS_KEYS` ; chemin sans nom d'apprenant ;
- remplacement : une seule ligne, `added_at` et auteur **mis à jour**, ancien fichier supprimé, historique `remplace` ; même fichier → inchangé, aucun historique, fichier préparé supprimé ;
- retrait : ligne et fichier supprimés, historique `retire`, second retrait 409 `no_cv` ;
- apprenant désactivé accepté ; supprimé : 409 pour l'administrateur, **404 pour la CME**, CV conservé et visible après restauration ; historique d'un supprimé réservé à l'administrateur ;
- **cycle de vie** (constat 2, N1) : interblocage simulé → échec 409 `retry`, aucune ligne, fichier préparé supprimé, ancien intact ; fichier préparé effacé (nettoyage simulé) avant l'adoption → échec, aucune ligne référençant un fichier absent ; nettoyage d'un fichier préparé marqué ancien pendant une opération en cours → l'opération échoue proprement ; écriture refusée par le disque → aucune ligne ; échec de l'historique ou de l'audit → annulation, nouveau fichier supprimé, ancien intact ; échec de suppression de l'ancien après validation → nouveau CV lisible, ancien repris par le nettoyage ; appel dans une transaction ouverte → refusé ;
- audit des trois actions, sans nom de fichier d'origine ;
- recherche : filtre `cv` et compteur `without_cv` (mêmes filtres et droits, avant pagination) ;
- nettoyage : fichier ancien non référencé supprimé, fichier **récent** non référencé et fichier référencé conservés ;
- **concurrence** (trait `ForksDatabaseConnections`, preuve d'attente par `performance_schema`) : deux téléversements simultanés → une ligne, un seul fichier restant, deux lignes d'historique cohérentes ; retrait pendant un téléversement ; **suppression et restauration du compte face au téléversement et au retrait** (attente sur le verrou du compte, aucun interblocage).

**Frontend** (`src/cv/cv.test.tsx`) : envoi réussi ; erreur 422 puis **nouveau choix du même fichier** et nouvel essai ; double clic → un seul envoi ; verrou d'action commun par apprenant entre la ligne et le panneau (envoi face à retrait) ; retrait avec confirmation ; réponse tardive après changement de page ou de filtre puis retour (rien réinstallé sur une autre ligne) ; après succès, liste, compteur et historique ouvert à jour ; Direction sans boutons.

## 9. Risques et points ouverts

- Limite d'envoi PHP (2 Mo) à relever en local, dans Docker et sur le serveur (ARCH-01).
- Clé de l'application : sauvegarde et rotation (`APP_PREVIOUS_KEYS`).
- `cv_history` remplace `cv_import_logs` d'ARCHITECTURE 8 (décision 8) : ARCHITECTURE à mettre à jour avec le code.
- Contrôle du PDF (décision 9) : heuristique, sans analyse des flux compressés ; pas de garantie antivirus.
- Mémoire : 5 Mo chiffrés ≈ 8,9 Mo (mesure Codex) ; mesure d'une requête réelle à faire pendant le développement.
- Sauvegarde du stockage des CV (EXI-FIA-02) : hors de cette tâche.

## 10. Découpage

1. Migration, modèles, `CvStorage`, `PdfInspector`, `CvService` (verrous et cycle de vie), nettoyage, tests du service et de concurrence — **avant les écrans**.
2. Routes, contrôleur, `LearnerResource`, recherche, tests des droits.
3. Écran « CV des apprenants », colonne et panneau dans « Apprenants », tests frontend.
4. Contrôles complets (suite backend **seule**), PROGRESS.
