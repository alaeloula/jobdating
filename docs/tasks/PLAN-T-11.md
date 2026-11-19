# Plan – T-11 (JD-16) : entreprises

Branche : `feature/T-11-entreprises` (base `develop`, `bde6a2a`).

**Version 2** (19/11/2026), après la revue du plan par Codex (`docs/reviews/REVIEW-PLAN-T-11.md`, « Plan à revoir », 3 corrections et 2 suggestions) : changements marqués **[v2]**. Décision du responsable technique : **pas de contre-revue du plan** ; les points [v2] seront vérifiés par Codex pendant la revue du code.

## Références

1. CDC V2 : **ADM-02** (RG-ENTR-01 à 07 et critères d'acceptation), RG-ENTR-06 / 07, PC-05 (un compte partagé par entreprise), RG-ACC-01 à 06, matrice 3.2, modèle 8.2 (`companies`), TRA-01.
2. Présentation V2 et `docs/DECISIONS.md` :
   - 16/11 : **profils recherchés** (bandeau d'avertissement), **compétences recherchées** (même avertissement), recruteurs (évolution 1) ;
   - 17/11 : changement du mot de passe d'une entreprise sans déconnexion des autres recruteurs ; réinitialisation : toutes les sessions fermées ;
   - 14/11 : entreprises sans e-mail ni lien d'activation ; mot de passe généré affiché une fois ;
   - **19/11 : RG-ENTR-08 remplace PC-14** (profils recherchés saisis par l'administrateur et la CME, facultatifs).
3. Maquettes : `admin-03-entreprises-participantes.png`, `admin-04-nouvelle-entreprise.png`, `admin-05-fiche-de-connexion.png`, `entreprise-08-profil.png`.
4. Réutilisé : `PasswordGenerator`, `SessionRevoker`, `PasswordTokenService::revokeAll`, `StrongPassword` (T-05, T-10) ; référentiels `sectors`, `filieres`, `competences` (T-02).

## Décisions du responsable technique (19/11)

| # | Question | Réponse |
|---|---|---|
| 1 | Événement et emplacement | **T-11 sans événement ni emplacement.** T-14 ajoutera sur cet écran : choix de l'événement à la création, emplacement **facultatif** (RG-PAR-02, RG-ENTR-03 « à ce moment ou plus tard »), filtres « Sans emplacement », compteur de passages, « Retirer », éditions précédentes. |
| 2 | Accès de la CME | La CME voit la liste et la fiche ; elle modifie les **profils recherchés** (T-11) et, dans T-14, les **emplacements** (ADM-07). Tout le reste : administrateur. |
| 3 | Logo | Inclus : PNG, JPG ou WebP, 1 Mo au plus (pas de SVG). |
| 4 | Import par fichier ; export | Import → **T-13** ; export CSV / Excel → **T-61**. |
| 5 | Désactiver / réactiver | Inclus ; pas de suppression (historique des passages). |
| 6 | Profil de l'entreprise (ENT-08) | Inclus : l'entreprise modifie sa description et son site web depuis « Mon profil ». |
| 7 | PC-14 | Remplacé par RG-ENTR-08 (décision du 19/11). |
| 8 **[v2]** | Sens de « Fiche imprimée » | **Confirmation par l'administrateur** après impression (« J'ai imprimé la fiche »), pas le simple lancement de l'impression du navigateur. |

## Périmètre

### 1. Modèle de données (migrations)

| Table | Colonnes | Contraintes |
|---|---|---|
| `companies` | `id`, `user_id`, `name`, `sector_id`, `description`, `website`, `logo_path`, `contact_name`, `contact_email`, `credentials_version` **[v2]**, `credentials_printed_at`, timestamps | `user_id` unique, clé vers `users` (`restrictOnDelete`) ; `sector_id` clé vers `sectors` (nullable, `restrictOnDelete`) ; index `name` |
| `company_filiere` | `company_id`, `filiere_id` | clé primaire composée ; clés vers `companies` (`cascadeOnDelete`) et `filieres` (`restrictOnDelete`) **[v2]** |
| `company_competence` | `company_id`, `competence_id` | idem, vers `competences` **[v2]** |
| `company_recruiters` | `id`, `company_id`, `name`, `email`, `phone`, `job_title` (nullable), timestamps | clé vers `companies` (`cascadeOnDelete`) **[v2]** ; rempli par T-31 ; **lecture seule** dans T-11 |

- **Identité du compte [v2]** (constat 5) : `users.first_name` = nom de l'entreprise (tronqué à 100 caractères), `users.last_name` = chaîne vide ; l'identité affichée (`/me`, en-tête) et le libellé d'audit du compte utilisent donc le nom de l'entreprise et le nom d'utilisateur ; le contact n'est jamais un compte. Le libellé d'audit des actions `company.*` est « Entreprise : [nom] ». Cas testé : nom de 150 caractères.

- Le compte de connexion reste dans `users` : rôle `entreprise`, `username` (déjà unique), `email` nul, **`activated_at` renseigné à la création** (sinon connexion refusée, note de T-04), mot de passe généré.
- `name` : 150 caractères ; `description` : texte, 2 000 caractères au plus ; `website` : URL `http` ou `https`, 255 ; `contact_name` : 150 ; `contact_email` : e-mail RFC, 255, **jamais utilisé pour la connexion ni pour l'envoi** (RG-ENTR-06).
- Secteur **facultatif à la création** (RG-ENTR-01 n'impose que le nom) mais choisi dans le référentiel (RG-ENTR-02), **valeur active uniquement** à la saisie ; une valeur devenue inactive reste affichée.

### 2. Nom d'utilisateur (RG-ENTR-04)

- Proposition : nom simplifié (minuscules, sans accent, mots séparés par un tiret : `Société Générale` → `societe-generale`), via `Str::slug` ; chiffre ajouté en cas de doublon (`societe-generale-2`, `-3`…), en comptant **tous** les comptes (supprimés compris) ; nom sans lettre ni chiffre → `entreprise`.
- **Toujours valide [v2]** (constat 3) : base de moins de 3 caractères → complétée par `-entreprise` (`HP` → `hp-entreprise`) ; base tronquée à `50 − longueur du suffixe` caractères, sans tiret final (`…-2`, `…-10`, `…-100` tiennent dans 50) ; toute proposition respecte le format et la longueur. Aucune réservation : une collision au dernier moment reste gérée par la contrainte SQL (ci-dessous). Tests : noms de 1 et 2 caractères, nom de 150 caractères, suffixes à plusieurs chiffres.
- `GET /api/v1/companies/username-suggestion?name=…` (administrateur) : proposition affichée dans le formulaire.
- Modifiable avant validation ; contrôlé à l'enregistrement : `^[a-z0-9]+(-[a-z0-9]+)*$`, 3 à 50 caractères, **unique** (comptes supprimés compris). Une collision concurrente (contrainte SQL) donne 422 « Ce nom d'utilisateur vient d'être pris : … » avec une nouvelle proposition.
- **Non modifiable après création** (hypothèse H1 : il figure sur la fiche imprimée ; un changement exigerait une nouvelle fiche).

### 3. Création (administrateur) et fiche de connexion (RG-ENTR-05, 07)

- `POST /api/v1/companies` : transaction unique : compte `users` (rôle entreprise, activé, mot de passe `PasswordGenerator`), ligne `companies`, logo. **[revue du code]** Les profils recherchés ne font **pas** partie de la création : ils se saisissent ensuite par « Profils recherchés » (administrateur et CME, section 6) ; envoyés à la création, `filiere_ids` / `competence_ids` sont **refusés** (422) plutôt qu'ignorés (décision du 19/11, constat 3 de la revue du code). Réponse **201** avec la fiche et `credentials: { username, password }` **une seule fois** (`Cache-Control: no-store`). Aucun e-mail.
- Fiche de connexion (maquette admin-05) affichée dans la page : nom de l'entreprise, adresse de l'application (`FRONTEND_URL`), nom d'utilisateur, mot de passe, phrase « En cas d'oubli, adressez-vous à l'équipe d'organisation… » ; bouton **« Imprimer la fiche »** (impression du navigateur, feuille de style d'impression limitée à la fiche).
- **Version de la fiche [v2]** (constat 1) : chaque émission d'identifiants (création, réinitialisation) incrémente `credentials_version` et remet `credentials_printed_at` à nul ; la réponse renvoie `credentials: { username, password, version }`.
- **Confirmation [v2]** (décision 8) : après impression, l'administrateur clique « J'ai imprimé la fiche » → `POST /api/v1/companies/{id}/credentials-printed { version }`. Sous verrou de la ligne, la version doit être la version **courante**, sinon 409 « Cette fiche n'est plus valable : un nouveau mot de passe a été généré. Imprimez la nouvelle fiche. » ; si oui, `credentials_printed_at` renseigné et journal **« Fiche de connexion imprimée »** (RG-ENTR-07). Pas de mot de passe dans cette requête. Tests : ancienne fiche confirmée après réinitialisation → 409 ; confirmation retardée ; double confirmation (idempotente, une seule ligne de journal).
- Après fermeture de la page, le mot de passe n'est plus jamais affiché ni relisible (RG-ENTR-05).

### 4. Réinitialisation (RG-ENTR-07, décision du 17/11)

- `POST /api/v1/companies/{id}/reset-password` (administrateur) : nouveau mot de passe généré, renvoyé une fois (`no-store`) avec une nouvelle fiche à imprimer ; liens annulés ; **toutes les sessions** de l'entreprise fermées ; **aucun e-mail** ; journal « Mot de passe réinitialisé » (auteur : l'administrateur, note « Réinitialisé par l'administrateur »). `credentials_printed_at` remis à nul (nouvelle fiche à imprimer).
- Compte désactivé : refusé (« réactivez-le d'abord »).

### 5. Modification, désactivation (administrateur)

- `PATCH /api/v1/companies/{id}` : nom, secteur, description, site web, contact ; le nom d'utilisateur ne change pas (H1).
- Logo : PNG / JPG / WebP, 1 Mo, contrôle du contenu réel par `image` + `mimes`, dimensions 2 000 px au plus. **[revue du code]** Stockage disque **privé** (`local`), dossier `logos/`, nom aléatoire, servi par `GET /api/v1/companies/{id}/logo` (utilisateurs connectés autorisés, `nosniff`) au lieu du disque public : aucun lien public à créer ; les logos ne sont pas confidentiels, la route protège seulement de l'accès anonyme.
- **Contrat et atomicité [v2]** (constat 2) :
  - le logo **initial** est envoyé **avec la création** (`POST /api/v1/companies` en multipart) ; le remplacement passe par `POST /api/v1/companies/{id}/logo`, la suppression par `DELETE …/logo` ;
  - ordre : validation complète (dont le fichier) **avant** tout effet durable → stockage du nouveau fichier → transaction SQL (fiche verrouillée et relue pour un remplacement) ;
  - si la transaction échoue (collision de nom d'utilisateur, journal…), le **nouveau** fichier est supprimé ; si le stockage échoue, rien n'est écrit en base (erreur claire) ;
  - l'**ancien** fichier n'est supprimé qu'**après** la validation de la transaction (`DB::afterCommit`) ;
  - tests : échec SQL / journal après stockage (aucun fichier orphelin, aucun compte, fiche, pivot ni journal partiel) ; échec de stockage ; remplacement réussi (ancien fichier supprimé) ; deux remplacements successifs.
- `POST …/deactivate` et `…/reactivate` : `deactivated_at` du compte ; désactivation → toutes les sessions fermées et liens annulés (RG-ACC-05). Pas de suppression (H2 : un compte entreprise ne se supprime pas, historique des passages).

### 6. Profils recherchés (RG-ENTR-08, décisions du 16/11 et du 19/11)

- `PUT /api/v1/companies/{id}/profiles` `{ filiere_ids: [], competence_ids: [] }` : **administrateur et CME** ; listes facultatives (vides permises), sans doublon.
- **Valeurs inactives [v2]** (constat 4) : la liste envoyée est la sélection **complète** ; une valeur **nouvelle** doit être active ; une valeur **déjà associée** peut rester même si elle est devenue inactive (aucun rejet, aucune perte) ; seule une valeur absente de la liste envoyée est retirée (retrait volontaire). Le frontend affiche les valeurs associées inactives (« inactive ») et les renvoie tant qu'elles ne sont pas décochées. Même règle pour le **secteur** : un secteur devenu inactif peut être conservé lors d'une modification ; un **nouveau** secteur doit être actif. Tests : association inactive conservée en ajoutant une autre valeur ; retrait volontaire ; nouvelle valeur inactive refusée ; secteur inactif conservé, nouveau secteur inactif refusé.
- Bandeau affiché au-dessus du choix, texte exact de la décision du 16/11 : « **À renseigner uniquement avec l'accord de l'entreprise.** Ne renseignez les profils recherchés que si l'entreprise vous les a communiqués elle-même. Sans information de sa part, laissez ce champ vide : les apprenants pourront toujours la rencontrer. »
- Journal : « Profils recherchés modifiés » (avant / après, libellés).
- Utilisation (suggestions aux apprenants) : **T-40**.

### 7. Recruteurs (évolution 1 V2)

- Table créée ici ; la fiche affiche la liste (nom, e-mail, téléphone, fonction) **en consultation** pour l'administrateur et la CME ; vide tant que T-31 n'existe pas (« Aucun recruteur enregistré pour l'instant »). Jamais visible par un apprenant.

### 8. Profil de l'entreprise (ENT-08)

- `PUT /api/v1/me/company` (rôle entreprise, sa **propre** fiche seulement, RG-ACC-02) : description et site web. Journal « Fiche entreprise modifiée » (auteur : l'entreprise).
- « Mon profil » (rôle entreprise) : bloc « Présentation » au-dessus du changement de mot de passe (maquette entreprise-08), avec nom d'utilisateur en lecture seule.

### 9. Droits (matrice 3.2)

- Deux nouvelles permissions :
  - `companies.manage` : créer, modifier, logo, désactiver / réactiver, réinitialiser, journaliser l'impression — **administrateur** ;
  - `companies.profiles.manage` : profils recherchés — **administrateur et CME**.
- Liste et fiche de gestion (`GET /api/v1/companies`, `GET /api/v1/companies/{id}`) : `companies.manage` **ou** `companies.profiles.manage` (administrateur, CME). La Direction, l'entreprise et l'apprenant consultent les entreprises par leurs écrans à venir (`companies.view`, T-23 / T-40), pas par cet écran.
- Le test de la matrice (`PermissionMatrixTest`, T-03) est mis à jour pour les deux nouvelles permissions.
- `{id}` = identifiant de l'entreprise ; une entreprise inexistante → 404.

### 10. Liste (maquette admin-03, sans les éléments d'événement)

- Colonnes : entreprise (nom, secteur), nom d'utilisateur, statut (Actif / Désactivé), fiche imprimée (oui / non) ; recherche (nom, nom d'utilisateur), filtres « Désactivées » et « Fiche non imprimée », pagination (25).
- Boutons selon le rôle : administrateur (Modifier, Réinitialiser, Désactiver / Réactiver) ; CME (Profils recherchés).

### 11. Journal d'audit

- Nouvelle catégorie **« Entreprises »** (filtre de l'écran du journal : 8 catégories) et actions : `company.created`, `company.updated` (avant / après, sans logo binaire : « logo modifié »), `company.profiles_updated`, `company.credentials_printed`.
- Le compte (`users`) garde ses actions existantes (création, rôle, désactivation, réactivation, réinitialisation). Jamais de mot de passe ni de nom de fichier interne dans les valeurs.

### 12. Frontend

- `/entreprises` : liste ; panneau « Nouvelle entreprise » / « Modifier » (maquette admin-04, **sans** événement ni emplacement) ; fiche de connexion après création / réinitialisation (admin-05) avec « Imprimer la fiche » et « Copier » ; mot de passe hors du cache TanStack (même principe que T-10) ; confirmation avant réinitialisation et désactivation.
- Fiche « Profils recherchés » (CME et administrateur) avec le bandeau, choix multiples des filières et compétences actives, recruteurs en consultation.
- Lien « Entreprises » dans l'en-tête pour administrateur et CME. Garde de route : administrateur et CME.
- « Mon profil » entreprise : bloc « Présentation ».

### Hors périmètre

Événement, emplacement, « Retirer », éditions précédentes, compteur de passages (**T-14**) ; import par fichier (**T-13**) ; export (**T-61**) ; création des recruteurs et rubrique 6 (**T-31**) ; suggestions aux apprenants (**T-40**) ; écrans de consultation des entreprises pour la Direction, l'entreprise et l'apprenant (T-23, T-40).

## Hypothèses (avis favorable de Codex, [v2])

- **H1** : le nom d'utilisateur n'est plus modifiable après la création (renommer l'entreprise ne le change pas).
- **H2** : tranchée par la décision 5 (désactivation, pas de suppression).
- **H3** : le secteur est facultatif ; affiché « Non renseigné » ; les futurs filtres et statistiques par secteur devront traiter ce cas (pas de secteur fictif).
- **H4** : la CME voit les noms d'utilisateur ; les ressources lues par la CME n'incluent **jamais** `credentials` ; elle ne réinitialise pas, ne confirme pas d'impression et ne modifie pas le compte.

## Passage de relais à T-14 [v2]

Les entreprises créées par T-11 (dont celles de démonstration) n'ont aucune participation : T-14 doit permettre de les ajouter à leur **première** participation, même sans édition précédente. Jusqu'à T-14, le critère ADM-02 « apparaît parmi les participants de l'événement » est **différé**, pas satisfait. La création d'une entreprise ne dépend d'aucun événement fictif.

## Points de vigilance [v2] (revue du plan)

- Réinitialisation administrative : `SessionRevoker::closeAll()` (jamais `afterPasswordChange()`, qui conserve les sessions entreprise lors d'un changement personnel) ; ne pas réutiliser `PasswordAccess::canReset()` ni `GeneratedPasswordNotification` (pensés pour les comptes avec e-mail).
- `PUT /me/company` : l'entreprise est celle du compte authentifié ; tout `company_id` reçu est ignoré ; liste blanche description / site web.
- Matrice (`PermissionMatrixTest`, ordre `[Admin, CME, Entreprise, Apprenant, Direction]`) : `companies.manage` `[oui, non, non, non, non]`, `companies.profiles.manage` `[oui, oui, non, non, non]`, lignes écrites indépendamment de `Role::permissions()`.

## Plan

1. Migrations, modèles (`Company`, relations), permissions et matrice, catégorie et actions du journal.
2. `CompanyService` (création, modification, logo, désactivation, réinitialisation, impression, profils) et `UsernameSuggester`.
3. API (`CompanyController`, `CompanyProfileController`, `MyCompanyController`), validations, ressources.
4. Tests backend : droits des 5 rôles par route, RG-ENTR-01 à 08 et critères ADM-02, nom d'utilisateur (accents, doublons, supprimés, concurrence), logo (format, taille, contenu réel), réinitialisation (sessions, aucun e-mail), journal.
5. Frontend : liste, formulaire, fiche imprimable, profils recherchés, « Mon profil » entreprise ; tests Vitest.
6. Contrôles (Docker, PHPStan, lint, build), navigateur (création, impression, connexion avec la fiche, réinitialisation), documentation, revue du code par Codex.

## Tests (résumé)

- Critères ADM-02 : une entreprise se connecte avec la fiche, sans changement imposé ; après réinitialisation l'ancien mot de passe est refusé ; deux entreprises de même nom reçoivent deux noms d'utilisateur différents ; un logo ou un site web invalide est refusé.
- Droits : chaque route pour les 5 rôles et sans session ; la CME ne peut que lire et modifier les profils ; une entreprise ne modifie que sa propre fiche, et seulement description / site web.
- Nom d'utilisateur : accents, ponctuation, nom vide de lettres, doublons (y compris compte supprimé), format refusé, collision au dernier moment.
- Profils : valeurs inactives refusées à la saisie, conservées si déjà associées ; listes vides ; journal avant / après.
- Mot de passe jamais dans le journal, les réponses de liste ou le cache TanStack ; `no-store` sur les réponses qui le portent ; aucun e-mail envoyé à une entreprise.
- **[v2]** Version des fiches (ancienne fiche, confirmation retardée, double confirmation) ; logo et rollback (aucun fichier orphelin ni écriture partielle) ; propositions d'identifiant (1, 2 et 150 caractères, suffixes) ; valeurs inactives conservées ; nom de 150 caractères dans `users` ; réinitialisation avec deux clients entreprise (tous deux déconnectés) ; entreprise A qui tente de modifier B ou d'envoyer des champs hors liste blanche ; secret absent des caches de requête **et** de mutation, et après fermeture de la fiche.
