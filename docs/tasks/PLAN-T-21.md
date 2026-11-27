# Plan – T-21 (JD-23) : consultation du CV par lien temporaire, journalisée

Branche : `feature/T-21-consultation-cv` (base `develop`, `eab9845`).

**Version 1.2** (27/11/2026) : corrections de la contre-revue Codex (`docs/reviews/REVIEW-PLAN-T-21-contre-revue.md`, « Plan à revoir » : N1 accessibilité, N2 version transmise à l'écran, N3 téléchargement, N4 erreurs de pdf.js) ; développement autorisé par le responsable technique (27/11), la revue du code vérifiera ces corrections.

Version 1.1 : corrections de la revue Codex (`docs/reviews/REVIEW-PLAN-T-21.md`, « Plan à revoir ») et décisions 8 à 10 prises par le responsable technique le 27/11. **Aucun code n'est écrit.**

## Références

1. CDC V2 : CV-03 ; ENT-03 (RG-CV-01 à 03, exceptions « CV non disponible », « fichier momentanément inaccessible → Réessayer ») ; APP-03 ; 7.5 ; 7.6 ; 11.6 ; matrice 3.2 (« Consulter et télécharger un CV validé » : administrateur, CME, Direction ✅ ; apprenant 🔸 le sien ; entreprise 🔸 → T-24).
2. `docs/DECISIONS.md` ; plan T-20 v1.3, **décision 9 révisée** (contrôle de structure seulement ; protection à la consultation).
3. ARCHITECTURE 5.2 (404 hors périmètre) et 5.3 (lien signé de courte durée, consultation journalisée).
4. Maquettes : `apprenant-04-mon-cv`, `entreprise-03-fiche-apprenant`, `cme-01-cv-apprenants`.
5. Code T-20 : `CvStorage::read`, `CvService`, `CvDocument`, `CvController::find`, `LearnerResource`, `AuditLogger`, `src/cv/*`, `CvPage`, panneau CV de `LearnersPage`, `lib/api.ts` (`apiRequest`, événements 401/403).

## 1. Décisions

| # | Sujet | Décision | Source |
|---|---|---|---|
| 1 | Affichage | Dans le site | réponse du 27/11 |
| 2 | Entreprise | Hors T-21 : accès, périmètre RG-ENT-01 et écran en **T-24** | réponse du 27/11 |
| 3 | Journal | **Journal d'audit** (catégorie « CV »), actions `cv.viewed`, `cv.downloaded` ; pas de table `cv_access_logs` (écart de nom avec CDC 8) | réponse du 27/11 |
| 4 | Durée du lien | **15 minutes**, vérifiée avec l'heure du serveur | réponse du 27/11 |
| 5 | Lien lié au compte | L'identifiant du compte est signé et comparé à l'utilisateur **authentifié** (session obligatoire, `session.valid`) : un lien copié n'ouvre rien pour un autre compte. Une autre session du même compte est acceptée | validé (revue) |
| 6 | Version | Lien lié à un **numéro de version** (décision 9) | validé (revue), précisé |
| 7 | Droits relus au service | Autorisation **commune** (`CvAccess`) appelée à l'émission du lien et au service du fichier ; `cv.view` seule ne suffit pas (l'entreprise l'a) | validé (revue) |
| 8 | Rendu | **pdf.js** (`pdfjs-dist`, dépendance frontend **acceptée le 27/11**) : le PDF est dessiné page par page dans le site, **sans jamais exécuter son contenu** ; le lecteur PDF du navigateur n'est **jamais** utilisé (revue, constat 1) | 27/11 |
| 9 | Numéro de version | Colonne `cv_documents.version` (UUID), **renouvelée à chaque ajout ou remplacement effectif** ; « même fichier → inchangé » ne la change pas ; A → B → A donne trois versions (revue, constat 3) | 27/11 |
| 10 | Apprenant désactivé | Son CV reste consultable par l'administrateur, la CME et la Direction (comme sa fiche) ; lui-même ne peut plus se connecter | 27/11 |

**Qui peut lire quel CV** (contrôlé par `CvAccess`, à l'émission et au service) :

| Lecteur | Apprenant actif | Apprenant désactivé | Apprenant supprimé |
|---|---|---|---|
| Administrateur | oui | oui | oui |
| CME, Direction | oui | oui | non (404) |
| Apprenant | son CV seulement | — (pas de session) | — |
| Entreprise | non en T-21 (T-24) | non | non |

## 2. Périmètre

**Inclus** : `version` sur `cv_documents`, autorisation commune, liens signés, service du fichier, journal, état personnel « Mon CV », visionneuse pdf.js (aperçu, plein écran dans le site, téléchargement), intégrations : « CV des apprenants », panneau CV de « Apprenants », bouton « Voir le CV » de la Direction, écran « Mon CV » de l'apprenant.

**Exclus** : entreprise (T-24), import CME (T-22), recherche de contenu actif (décision 9 de T-20).

## 3. Backend

**Migration** `2026_11_27_100000_add_version_to_cv_documents.php`, en trois étapes : (1) colonne `version` CHAR(36) **nullable** ; (2) un UUID **différent par ligne** existante ; (3) `NOT NULL` + index **unique**, sans valeur par défaut commune. Déploiement sans écriture concurrente de l'ancien code (migration avant la mise en service). Test avec deux lignes existantes : contenu, chemin et historique conservés, versions non nulles et distinctes, envois toujours fonctionnels.

**État du CV avec la version** (contre-revue, N2) : `version` ajoutée à l'état du CV partout où il est renvoyé (`CvController::state` : liste, fiche, réponse d'envoi ; `GET /me/cv`) et au type `CvState` du frontend. La version sert à repérer un remplacement ; ce n'est **pas** un droit d'accès (le lien reste signé et autorisé). `CvService::upload` donne une nouvelle version à chaque ajout ou remplacement effectif.

**API**

| Méthode et route | Qui | Réponse |
|---|---|---|
| `POST /learners/{id}/cv/link` | administrateur, CME, Direction (groupe apprenants + `CvAccess`) | `{url, expires_at}` ; 404 `no_cv` |
| `GET /me/cv` | apprenant | `{data: {source, added_at} | null}` (état personnel, sans données de la fiche administrative) |
| `POST /me/cv/link` | apprenant | `{url, expires_at}` ; 404 `no_cv` |
| `GET /cv-files/{cv}?user=…&version=…&expires=…&signature=…[&download=1]` | session obligatoire | PDF déchiffré |

- Lien : `URL::temporarySignedRoute('cv.file', now()->addMinutes(15), ['cv', 'user', 'version'])`. `download` est le **seul** paramètre exclu de la signature (`hasValidSignature($request, ignoreQuery: ['download'])`), valeur acceptée `1` uniquement ; `cv`, `user`, `version`, `expires` restent protégés.
- **Ordre des contrôles** (contre-revue, précisions) : d'abord l'**intégrité** de la signature, indépendamment de la date (`hasValidSignatureWhileIgnoring` / `hasCorrectSignature`) → sinon 403 `invalid_link`, jamais renouvelable ; **ensuite** l'expiration avec l'heure du serveur (`signatureHasNotExpired`) → 410 `link_expired`. Paramètres nommés avec leurs valeurs à `temporarySignedRoute`.
- **Codes d'erreur** du service du fichier (JSON) :
  - lien expiré → **410** `link_expired` ;
  - signature invalide, compte différent → **403** `invalid_link` ;
  - droits perdus (`CvAccess`) ou apprenant hors périmètre → **404** `not_found` ;
  - CV retiré ou version obsolète → **404** `cv_outdated` ;
  - fichier absent ou déchiffrement impossible → **503** `cv_unavailable` (journal technique sans contenu) ;
  - sans session → **401**.
- **En-têtes** : `Content-Type: application/pdf` ; `Content-Disposition: attachment` **toujours** (construit par le framework, nom ASCII `CV-Prenom-Nom.pdf`, séparateurs et caractères de contrôle neutralisés, nom vide → `CV.pdf`) : une URL collée dans un onglet télécharge le fichier au lieu de l'ouvrir dans le lecteur du navigateur ; `Content-Security-Policy: sandbox; default-src 'none'` ; `X-Content-Type-Options: nosniff` ; `Cache-Control: no-store, private` ; `Referrer-Policy: no-referrer`.
- **Journal** : exactement **une** ligne par service autorisé et préparé (`cv.viewed` sans `download`, `cv.downloaded` avec) : sujet apprenant, valeurs `version`, `source` ; auteur et IP par `AuditLogger` ; **aucune** ligne sur refus, CV absent ou fichier illisible ; jamais d'URL signée, de chemin, de nom d'origine ni de contenu. Libellés et filtre « CV » du journal d'audit. **Limite** : une copie que l'utilisateur ferait ensuite sur son poste n'est pas tracée.
- **Mémoire** : un PDF de 5 Mo déchiffré coûte environ 22 Mio de plus en pointe (mesure Codex en CLI, pic ≈ 46 Mio) ; à mesurer en requête HTTP dans Docker (PHP 8.4) pendant le développement ; `memory_limit` actuel : 1 Go en local, à confirmer sur le serveur (ARCH-01).

## 4. Frontend

- **Dépendance** `pdfjs-dist` (version **figée**, bibliothèque et worker de la **même version**, servis **localement** par Vite, sans service externe ; worker importé par la convention Vite `?url` ; polices standard ou autres ressources nécessaires à la version retenue identifiées pendant l'essai ; module **chargé seulement à l'ouverture d'un CV**, aucun import statique dans le chargement initial, vérifié sur le build). Options vérifiées **sur la version retenue** : `isEvalSupported: false`, `enableXfa: false` ; **aucun** gestionnaire de scripts (`PDFScriptingManager`), d'actions PDF ni de formulaires interactifs. Rendu en `canvas`, une page après l'autre, avec une **taille de rendu bornée** (largeur du conteneur × échelle de l'écran, zoom limité) ; les tâches de rendu en cours sont annulées et les surfaces inutiles libérées à la fermeture ou au changement.
- **Accessibilité** (CDC UX-09, WCAG 2.1 AA ; contre-revue, N1) : **couche de texte** de pdf.js posée sur chaque page (texte lisible par un lecteur d'écran et sélectionnable), toujours sans scripts ni formulaires ; boutons **Page précédente / suivante**, **Zoom + / −**, « Plein écran » et « Fermer » utilisables au **clavier**, avec libellés ; focus placé dans la visionneuse à l'ouverture du plein écran et rendu au bouton à la sortie (**Échap**) ; indication « Page n sur N ». **Limite signalée** : un CV scanné (image seule) n'a pas de texte ; pas de reconnaissance de texte en T-21. Ordre de lecture de la couche de texte vérifié en recette.
- **Erreurs de pdf.js** (contre-revue, N4) : échec de l'import du module ou du worker → « La visionneuse n'a pas pu se charger » + **Réessayer** ; `getDocument` refusé (PDF protégé par mot de passe, illisible) ou rendu d'une page impossible → « Ce CV ne peut pas être affiché ici. Téléchargez-le. » (bouton Télécharger conservé) ; l'aperçu partiel est vidé et les tâches arrêtées. Ces erreurs ne touchent ni le journal (le service HTTP a bien eu lieu) ni la session.
- **Chargement des octets** (`fetchCvPdf`, hors `apiRequest` qui lit du JSON et redirige sur tout 403) : `fetch` de l'URL signée avec la session → `ArrayBuffer` remis à pdf.js. Traitement : 410 `link_expired` → **un seul** renouvellement du lien puis nouvel essai (pas de boucle) ; 401 → fin de session (événement existant) ; 403 `invalid_link` / 404 `not_found` → message « Accès au CV refusé » ; 404 `cv_outdated` → aperçu vidé et état relu ; 503 ou réseau → « CV momentanément inaccessible » + **Réessayer**.
- **Composant `CvViewer`** : identité de chargement = **apprenant + version** ; tout changement (fermeture, autre apprenant, nouvelle version après « Remplacer » ou « Retirer » depuis `useCvOperations`) annule le chargement en cours, détruit le document pdf.js et ignore toute réponse tardive. « **Plein écran** » : affichage pdf.js en plein écran **dans le site** (pas d'onglet ni de `blob:` ouvert). « **Télécharger** » : `fetch` avec `download=1` (journalisé), puis enregistrement par un lien `download` temporaire portant **explicitement le nom** `CV-Prenom-Nom.pdf` ; l'URL temporaire est **révoquée après un court délai** (pour laisser le navigateur prendre le fichier), et toujours à la fermeture de la visionneuse (contre-revue, N3) ; échec → message et **Réessayer** ; double clic ignoré ; un lien expiré pendant un téléchargement est renouvelé une seule fois, comme pour l'aperçu.
- **Intégrations** :
  - « CV des apprenants » (administrateur, CME) : bouton **« Voir »** sur les lignes avec CV → panneau `CvViewer` ;
  - « Apprenants » : visionneuse dans le panneau CV (administrateur, CME) ; **Direction** : bouton « Voir le CV » (lecture seule) ;
  - **« Mon CV »** (`/mon-cv`, onglet en bas, maquette `apprenant-04`) : état par `GET /me/cv` → « CV disponible pour les entreprises · ajouté le [date] » ou « Aucun CV validé pour l'instant » ; visionneuse ; texte de mise à jour par la plateforme CME ; **aucun téléversement**.

## 5. Tests

**Backend** (`tests/Feature/Cv/CvConsultationTest.php`, PDF fictifs) :
- droits par la matrice du § 1 sur les 4 routes, statuts **précis** ; entreprise : 403 sur l'émission (groupe de rôles), 404 sur la route fichier ; CV d'un **autre** apprenant via la route fichier ;
- droits **changés après l'émission** : perte de rôle ou de permission, désactivation ou suppression du lecteur, suppression de l'apprenant cible (CME : 404 ; administrateur : servi) ;
- lien expiré (voyage dans le temps) → 410 ; signature modifiée → 403 ; `user`, `cv`, `version`, `expires` modifiés → 403 ; `download=1` accepté hors signature, autre valeur refusée ; autre compte → 403 ;
- CV retiré → 404 `cv_outdated` ; remplacement → ancien lien 404 ; **A → B → A** → premier lien 404 ; même fichier → version inchangée ;
- contenu servi égal au PDF ; en-têtes exacts (dont `attachment`, `sandbox`, nom neutralisé) ;
- journal : une ligne `cv.viewed` ou `cv.downloaded` par service réussi, IP et version, aucune sur échec, panne d'écriture du journal → pas de PDF servi ;
- fichier absent ou indéchiffrable → 503 sans journal d'audit ;
- `GET /me/cv` avec et sans CV ; apprenant désactivé : session refusée.

**Frontend** (`src/cv/viewer.test.tsx`, pdf.js simulé) : affichage, couche de texte présente, commandes clavier (page, zoom, Échap) ; échec du module, de `getDocument` et du rendu d'une page ; remplacement du CV pour le même apprenant (nouvelle version → rechargement) ; CV absent ; 503 puis Réessayer ; lien expiré renouvelé **une seule fois** (deuxième expiration → message, pas de boucle) ; 401 ; réponse tardive après fermeture, changement d'apprenant ou remplacement ignorée ; téléchargement et double clic ; « Mon CV » (date, absence, aucun téléversement) ; Direction en lecture seule.

**Recette navigateur** (manuelle, PDF fictif de plusieurs pages) : Chrome, Edge, Firefox, Safari, Android, iOS : aperçu, plein écran, téléchargement et nom du fichier, fermeture, erreurs.

## 6. Risques

- Poids de pdf.js (≈ 1 à 2 Mo, chargé seulement à l'ouverture d'un CV) et rendu sur téléphones anciens ; mémoire du navigateur (octets du PDF et surfaces des pages rendues, à mesurer en recette).
- Les tests Vitest simulent pdf.js : seuls l'essai et la recette sur le **build de production** prouvent le worker, la couche de texte et le téléchargement (iOS compris).
- Mémoire serveur (§ 3) et navigateur (octets du PDF tenus par la visionneuse jusqu'à sa fermeture).
- Écart de nom : journal d'audit au lieu de `cv_access_logs` (décision 3).

## 7. Découpage

0. **Essai court du rendu pdf.js** (worker local, chargement à la demande, couche de texte, PDF fictifs de T-20, build de production) avant le reste.
1. Version, `CvAccess`, liens, service du fichier, journal, `GET /me/cv`, tests backend.
2. `fetchCvPdf`, `CvViewer`, intégrations, tests frontend.
3. Contrôles complets (suite backend **seule**), recette navigateur, PROGRESS.
