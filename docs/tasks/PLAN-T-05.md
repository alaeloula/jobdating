# Plan – T-05 (JD-12) : activation, oubli et changement du mot de passe

Branche : `feature/T-05-mot-de-passe` (base `develop`, `d0b92d4`).

**Version 3** (17/11/2026). Version 2 après la revue du plan par Codex (`docs/reviews/REVIEW-PLAN-T-05.md`) et les décisions du responsable technique du 17/11 : changements signalés par **[v2]**. Version 3 après la contre-revue (`docs/reviews/REVIEW-PLAN-T-05-contre-revue.md`, 3 nouveaux constats) : changements signalés par **[v3]**. Décision du responsable technique : **pas de troisième revue du plan** ; ces trois points seront vérifiés par Codex pendant la revue du code.

## Références

1. CDC V2 : **AUTH-02** (RG-AUTH-06, 07, 08), **AUTH-03** (RG-AUTH-09 à 12), AUTH-01 (exceptions « compte non activé », « session expirée »), RG-ENTR-06 / 07, matrice 3.2, TRA-01, EXI-LOG-02, EXI-SEC-09.
2. `docs/DECISIONS.md` :
   - 14/11 : première connexion de l'apprenant **non bloquante** (écart avec RG-AUTH-13) ;
   - 14/11 : réinitialisation par l'administrateur pour tous les comptes (faite dans T-10 / T-11 / T-12, pas ici) ;
   - 14/11 : entreprises sans e-mail ni lien d'activation ;
   - 16/11 : compte entreprise partagé (plusieurs sessions) ; fermeture des autres sessions **réservée** au changement et à la réinitialisation du mot de passe ; connexions journalisées.
3. `docs/reference/ARCHITECTURE.md` : liste locale de mots de passe courants, file d'attente `database`, règles de travail (section 11).
4. Maquettes : `00-connexion.png` (lien « Mot de passe oublié ? »), `00-premiere-connexion-apprenant.png` (modèle visuel des écrans « choisir un mot de passe » ; l'obligation qu'elle montre est annulée par la décision du 14/11), `entreprise-08-profil.png` (bloc « Changer le mot de passe (facultatif) »).

## Décisions du responsable technique (17/11)

| # | Question | Réponse |
|---|---|---|
| 1 | Renvoi du lien d'activation par l'administrateur | T-05 fournit le **service** d'envoi ; le bouton « Renvoyer le lien » est ajouté par T-10. |
| 2 | Réinitialisation par l'administrateur | Faite dans T-10 / T-11 / T-12 avec les écrans de chaque type de compte. |
| 3 | Liste des mots de passe courants | Les **10 000** premiers d'une liste publique reconnue (**SecLists**), fichier texte versionné, aucune dépendance. |
| 4 | Limite des demandes « mot de passe oublié » | **1 par minute par e-mail** et **10 par minute par adresse IP**. |
| 5 | Page profil | Page **minimale** « Mon profil » : nom, identifiant, rôle, « Changer mon mot de passe ». |
| 6 | Revue du plan | Revue Codex avant le code. |
| A **[v2]** | Longueur maximale | **64 caractères**, et au plus **72 octets** (limite de bcrypt) ; aucune troncature silencieuse ; bcrypt conservé. |
| B **[v2]** | Lien ouvert alors qu'un **autre** compte est connecté | La page l'annonce : « Vous êtes connecté en tant que [B]. Pour continuer, vous serez déconnecté. » La validation déconnecte B, efface ses données de l'écran, puis termine le parcours. |
| C **[v2]** | H1 : oubli sur un compte non activé | **Oui** : un nouveau lien d'activation est envoyé. |
| D **[v2]** | H3 : nouveau mot de passe différent de l'actuel | **Oui** (changement depuis le profil et réinitialisation). Pas d'historique des anciens mots de passe. |
| E **[v2]** | Un changement de mot de passe annule les liens de réinitialisation en attente | **Oui** (profil, réinitialisation ; contrat pour la réinitialisation par l'administrateur, T-10 à T-12). |
| F **[v2]** | Moment du journal « lien envoyé » | Quand la tâche de fond **remet l'e-mail au serveur de messagerie** ; un échec définitif est aussi journalisé. Le journal ne prétend jamais que l'e-mail a été reçu. |
| H2, H4 | Connexion automatique ; lien ouvert connecté | Validés par la revue : connexion automatique après activation, retour à l'écran de connexion après réinitialisation ; cas « autre compte connecté » réglé par B. |

Ces décisions seront ajoutées à `DECISIONS.md` (texte montré au responsable technique avant écriture).

## Périmètre

### 1. Règle de mot de passe commune (RG-AUTH-07)

- Règle unique `StrongPassword` utilisée **partout** (activation, réinitialisation, changement ; T-10 à T-13 la réutiliseront).
- Contrôles, dans cet ordre :
  1. au moins **10 caractères** (comptés en caractères Unicode, `mb_strlen`) ;
  2. au plus **64 caractères** et au plus **72 octets** en UTF-8 **[v2]** (message : « Le mot de passe ne doit pas dépasser 64 caractères. » ; si les 64 caractères dépassent 72 octets à cause de caractères accentués : « Ce mot de passe est trop long. ») ;
  3. absent de la liste des mots de passe courants.
- Le mot de passe saisi est enregistré **tel quel** : espaces compris, aucune troncature. Laravel n'applique pas `TrimStrings` aux champs `password*` ; un test le vérifie **[v2]**.
- Recherche dans la liste : en minuscules (`mb_strtolower`), après retrait des espaces de début et de fin, **uniquement pour la comparaison**.
- Fichier `backend/resources/security/common-passwords.txt` : un mot par ligne ; lignes vides et lignes commençant par `#` ignorées (en-tête : source SecLists, nom exact du fichier source, version / commit, date). Chargé une fois par processus (propriété statique), transformé en tableau indexé. **Fichier absent ou vide : exception** (erreur 500), la règle n'est **jamais** désactivée silencieusement **[v2]**.
- Messages : « 10 caractères minimum. » ; « Ce mot de passe est trop courant. Choisissez-en un autre. »
- Les mots de passe **générés** (apprenants, entreprises) ne passent pas par cette règle : ils sont aléatoires (RG-AUTH-08) ; leur générateur sera défini dans T-11 / T-12 et respectera la limite de 72 octets.

### 2. Jetons à usage unique (activation et réinitialisation)

Table `password_tokens` (nouvelle migration) :

| Colonne | Rôle |
|---|---|
| `id` | |
| `user_id` | clé vers `users`, suppression en cascade |
| `type` | `activation` ou `reset` |
| `token_hash` | SHA-256 du jeton (64 caractères hexadécimaux), **unique** ; le jeton en clair n'est jamais stocké |
| `expires_at` | création + 48 h (activation, RG-AUTH-06) ou + 60 min (réinitialisation), calculé en PHP |
| `created_at` | |

- Unicité SQL **`(user_id, type)`** **[v2]** : un seul jeton par compte et par type, garanti par la base.
- Jeton : 64 caractères aléatoires (`Str::random(64)`). Recherche par `token_hash` via l'index SQL (pas de comparaison de secrets en PHP ; si une comparaison PHP devient nécessaire : `hash_equals`).
- **Expiration [v2]** : expiré si `now() >= expires_at`, avec l'horloge PHP pour l'écriture et la vérification (pas `NOW()` MySQL).
- **Ordre des verrous commun [v2]** : toujours la ligne `users` (`FOR UPDATE`) **puis** la ligne `password_tokens`.
- **Émission** (`issue(User, type)`) **[v2]** : transaction ; verrou du compte (stable même sans jeton existant) ; relecture de l'éligibilité sous verrou ; suppression de l'ancien jeton du même type puis insertion ; renvoie le jeton en clair à l'appelant **sans l'écrire nulle part ailleurs**.
- **Consommation** (`consume(token, type, callable)`) **[v2]** : lecture du `user_id` par le hachage (sans verrou) ; transaction ; verrou du compte ; relecture du jeton par hachage sous verrou ; contrôle de l'expiration et de l'éligibilité du compte (désactivé, supprimé, rôle changé entre l'ouverture de la page et la validation → lien invalide) ; puis, **dans la même transaction** : mot de passe, activation éventuelle, suppression du jeton, suppression des jetons de réinitialisation restants (décision E), fermeture des sessions, journal. **Toute erreur annule tout** : le lien reste utilisable (validation échouée, journal en échec).
- `revokeAll(User, type)` : utilisé après un changement de mot de passe (décision E) et, en contrat, par la réinitialisation administrative (T-10 à T-12).
- Jetons expirés : supprimés par une purge quotidienne (planificateur), sans journal.
- La table Laravel par défaut `password_reset_tokens` n'est pas utilisée (laissée en place).

### 3. Confidentialité des jetons [v2] (constat 1)

- **Jeton dans le fragment de l'adresse**, jamais dans le chemin : `{FRONTEND_URL}/activation#<jeton>` et `{FRONTEND_URL}/reinitialiser#<jeton>`. Le fragment n'est envoyé ni au serveur, ni dans le Referer, ni dans les journaux d'accès (Laravel, Vite, proxy).
- **Aucune adresse d'API ne contient le jeton** : vérification et consommation en `POST` avec le jeton dans le corps (`POST /api/v1/activation/check`, `POST /api/v1/password/reset/check`).
- Le frontend lit le fragment, le garde en mémoire du composant, puis le retire de la barre d'adresse et de l'historique (`history.replaceState`).
- Réponses des routes à jeton : `Cache-Control: no-store`. Pas de `Referrer-Policy: no-referrer` global : Sanctum utilise `Origin` / `Referer` pour reconnaître le frontend ; le fragment suffit à protéger le jeton.
- **Pas de jeton dans la file d'attente** : le jeton est créé **dans la tâche de fond** qui envoie aussitôt l'e-mail (`sendNow`), voir section 4. Les tâches mises en file ne contiennent que l'identifiant du compte ou l'e-mail demandé et le contexte d'auteur ; elles sont **chiffrées** (`ShouldBeEncrypted`, contenu de `jobs` et `failed_jobs` illisible sans la clé de l'application).
- Exceptions et journaux techniques : aucun jeton ni mot de passe dans les messages d'exception, le contexte des journaux Laravel ou les valeurs d'audit ; `password`, `password_confirmation`, `current_password`, `token` ajoutés à la liste des champs jamais renvoyés dans les erreurs (`dontFlash`).
- Frontend : jeton et mots de passe **hors de TanStack Query** (ni clé de requête, ni variables de mutation conservées) : appels directs à `apiRequest` depuis le composant ; aucun stockage navigateur.
- **Durée de vie du jeton dans la page [v3]** (contre-revue, constat 2) : le jeton reste **uniquement dans l'état local du composant** :
  - **conservé** après une erreur récupérable (422 mot de passe non conforme, 429, erreur réseau) pour permettre de corriger et réessayer ; les champs de mot de passe peuvent être vidés selon le cas ;
  - **effacé** au succès, après un 410 (lien invalide, définitif) et au départ de la page ;
  - **rechargement** de la page après retrait du fragment : le jeton est perdu (aucune persistance, voulu) ; la page affiche « Ouvrez à nouveau le lien reçu par e-mail. », sans nouvel envoi automatique.

### 4. Demandes publiques sans révéler l'existence d'un compte [v2] (constat 2)

Deux demandes publiques : `POST /api/v1/password/forgot` `{ email }` et `POST /api/v1/activation/resend` `{ email }`.

- **Deux valeurs distinctes [v3]** (contre-revue, constat 1) :
  - **adresse recherchée** : l'adresse validée, espaces de début et de fin retirés, **sans autre transformation** ; c'est elle que reçoit la tâche, qui cherche le compte comme la connexion (comparaison par la collation MySQL de la colonne `email`) ; l'e-mail part toujours à l'adresse **du compte retrouvé** ;
  - **clé du limiteur** : jamais utilisée comme adresse ; calculée par MySQL avec la collation de la colonne `email`, **sans lire `users`** : `SELECT SHA2(WEIGHT_STRING(? COLLATE utf8mb4_unicode_ci), 256)` (même requête pour toute adresse). Deux adresses partagent le budget si et seulement si MySQL les juge identiques (`jose` / `josé` : oui ; `a` latin / `а` cyrillique : non). `Str::ascii` n'est **pas** utilisé.
- **Même travail pour toute adresse valide** : validation du format (422 si invalide, indépendamment de l'existence du compte), calcul de la clé, passage des limiteurs, mise en file d'**une** tâche chiffrée `ProcessPasswordEmailRequest(adresse recherchée, type demandé, contexte)`, réponse **202** avec le même message : « Si un compte correspond à cette adresse, un e-mail vient d'être envoyé. » Aucune lecture de la table `users` dans la requête.
- **La tâche de fond décide** : recherche du compte, éligibilité, émission du jeton, envoi immédiat de l'e-mail, journal :
  - oubli, compte actif et activé (apprenant, CME, Direction, administrateur) → lien de réinitialisation (60 min) ;
  - oubli ou renvoi, compte CME / Direction / administrateur **non activé** et non désactivé → lien d'activation (48 h) (décision C) ;
  - renvoi sur un compte déjà activé → rien ;
  - compte inconnu, désactivé, supprimé, entreprise, sans rôle → rien, sans journal.
  Les refus d'éligibilité ne remontent jamais à la requête publique.
- **Limiteurs** : un budget **commun** aux deux demandes, consommé **avant** toute décision et quel que soit le compte :
  - 1 / min par clé d'e-mail (calculée ci-dessus) ; une adresse avec des espaces internes est refusée par la validation du format ;
  - 10 / min par adresse IP ;
  - au-delà, 429 avec le délai, identique pour toutes les adresses. La clé du limiteur ne réutilise pas `loginTarget()` (qui dépend du compte trouvé).
- Panne de la file d'attente : l'insertion de la tâche est la même pour toutes les adresses, donc une panne donne la même erreur pour toutes.

### 5. Activation (AUTH-02) – comptes CME, Direction, administrateur

- **Compte non activé** : `activated_at` nul. À la création (T-10), son mot de passe est un hachage aléatoire inutilisable. T-05 ne crée pas de comptes.
- **Service pour T-10** `AccountActivationService::sendLink(User, actor)` **[v2]** : contrôle l'éligibilité (rôle CME / Direction / administrateur, non activé, non désactivé, e-mail présent ; sinon exception métier, utilisée seulement par l'administrateur) et met en file la tâche d'envoi **après la validation de la transaction** de l'appelant (`afterCommit()` explicite sur la tâche, sans dépendre du réglage `after_commit` actuel). Appelé par T-10 **après** l'attribution du rôle.
- `POST /api/v1/activation/check` `{ token }` (public) : 200 avec le prénom si le jeton est valable ; sinon **410** `code: link_invalid` (expiré, utilisé, inconnu, compte devenu inéligible : même réponse).
- `POST /api/v1/activation` `{ token, password, password_confirmation }` (public) :
  - consommation (section 2) : mot de passe haché, `activated_at` renseigné, jeton supprimé ;
  - **ouverture de session** par un composant commun `SessionOpener` **[v2]**, partagé avec `LoginController` (contrôles d'état et de rôle, régénération, heure de connexion, `last_login_at`, journal `auth.login_succeeded`), sans fermer les autres sessions ;
  - réponse = utilisateur courant ; 410 si le lien est invalide ; 422 si le mot de passe n'est pas conforme.
- Écran de connexion : après la réponse `account_not_activated`, lien « Recevoir un nouveau lien d'activation » ; sur la page d'un lien invalide, formulaire de nouveau lien.

### 6. Mot de passe oublié (AUTH-03, oubli)

- Acteurs : apprenant, CME, Direction, administrateur. Une entreprise n'a pas d'e-mail : elle s'adresse à l'administrateur (RG-ENTR-07) ; un texte le rappelle sur la page.
- Demande : section 4.
- `POST /api/v1/password/reset/check` `{ token }` : 200 si valable, sinon 410 `link_invalid`.
- `POST /api/v1/password/reset` `{ token, password, password_confirmation }` : consommation (section 2) ; nouveau mot de passe différent de l'actuel (décision D, sinon 422) ; **toutes** les sessions du compte fermées (RG-AUTH-12) ; `remember_token` renouvelé ; **pas de connexion automatique** : retour à l'écran de connexion avec « Mot de passe modifié. Vous pouvez vous connecter. »
- Limite sur les routes de vérification et de consommation des jetons : 10 / min par IP (contre l'abus ; un jeton de 64 caractères ne se devine pas).

### 7. Changement depuis le profil (AUTH-03, changement)

- Tous les rôles, y compris entreprise (RG-ENTR-06).
- `PUT /api/v1/me/password` (connecté) `{ current_password, password, password_confirmation }` :
  - limite : 5 tentatives / min par compte (toutes tentatives), puis 429 ;
  - mot de passe actuel faux → 422 sur `current_password` (RG-AUTH-11) ;
  - nouveau identique à l'actuel → 422 (décision D) ;
  - succès, dans une transaction avec verrou du compte : mot de passe, suppression des jetons de réinitialisation (décision E), suppression des **autres** sessions du compte (table `sessions`), `remember_token` renouvelé, journal ; puis identifiant de la session courante régénéré, **heure de connexion conservée** (limite de 12 h inchangée) ; 204.
- Pour le compte entreprise partagé, le changement ferme les sessions des autres recruteurs (décision du 16/11).
- Deux champs pour le nouveau mot de passe (CDC), malgré l'unique champ de la maquette `entreprise-08-profil`.

### 8. Sessions [v2] (constat 4)

- **Deux protections** contre le retour d'une ancienne session :
  1. suppression des lignes de la table `sessions` ;
  2. empreinte du mot de passe dans chaque session : le middleware Sanctum `AuthenticateSession` (actif avec `statefulApi`) déconnecte toute session dont l'empreinte ne correspond plus au mot de passe. Une session supprimée puis réécrite par une requête en cours garde l'ancienne empreinte et est donc refusée à la requête suivante. La session courante, après un changement, reçoit la nouvelle empreinte.
  Les tests vérifient les deux protections (la seconde avec une session réécrite après coup).
- **Autre compte connecté (décision B)** :
  - frontend : sur les pages d'activation et de réinitialisation, si `/me` répond avec un utilisateur B, bandeau « Vous êtes connecté en tant que [prénom nom de B]. Pour continuer, vous serez déconnecté. » ; à la validation : déconnexion de B, **purge complète du cache** TanStack (requêtes et mutations) et de l'état d'authentification, puis envoi du formulaire ;
  - serveur (garantie indépendante du frontend) : si la requête d'activation ou de réinitialisation arrive avec une session d'un autre utilisateur, cette session est fermée (déconnexion, invalidation, nouveau jeton CSRF) avant la consommation ; le journal de cette opération ne prend **jamais** B comme auteur.
- **Même compte connecté** qui réinitialise son propre mot de passe : toutes ses sessions sont fermées, le frontend efface son état et affiche l'écran de connexion avec le message.
- Activation réussie : nouvelle session A ; le frontend remplace `/me` et purge le cache avant d'afficher l'accueil.

### 9. Journal d'audit (TRA-01, intégration T-06)

Nouvelles actions, catégorie « Comptes » :

| Action | Moment | Valeurs |
|---|---|---|
| `account.activation_link_sent` | e-mail d'activation **remis au serveur de messagerie** (décision F) | origine : `demande publique` / `administrateur` |
| `account.password_reset_link_sent` | e-mail de réinitialisation remis au serveur de messagerie | origine |
| `account.link_email_failed` **[v2]** | échec définitif de la remise (après les nouvelles tentatives de la tâche) | type de lien, origine ; jamais le message d'erreur brut s'il peut contenir le lien |
| `account.activated` | activation réussie | — |
| `account.password_reset` | réinitialisation réussie | note « Sessions fermées » |
| `account.password_changed` | changement depuis le profil | note « Autres sessions fermées » |

- Plus `auth.login_succeeded` pour la connexion automatique après activation (ligne distincte, décision du 16/11 sur les connexions).
- **Auteur explicite [v2]** : la tâche de fond reçoit un contexte d'origine (`visiteur` ou identifiant de l'administrateur), sans jeton ni mot de passe ; `AuditLogger` accepte un libellé d'auteur explicite pour noter « Visiteur non connecté » dans une tâche de fond (au lieu de « Système ») et ne déduit jamais l'auteur de la session présente sur une route publique. Activation, réinitialisation, changement : auteur = le titulaire du compte.
- Demandes pour un compte inexistant ou inéligible : rien n'est écrit.
- **Observateur [v2]** : `UserAuditObserver` est neutralisé **pendant l'opération seulement** (indicateur restauré dans un `finally`, même en cas d'exception) et pour tous les champs qu'elle modifie (`password`, `activated_at`, `remember_token`), pour éviter une ligne `account.updated` en double.

### 10. E-mails

Deux notifications en français, envoyées par la tâche de fond (`sendNow`) : « Activez votre compte JobDating » (lien, validité 48 h) et « Réinitialisation de votre mot de passe JobDating » (lien, validité 60 min, « si vous n'êtes pas à l'origine de cette demande, ignorez cet e-mail »). Nouvelle variable `FRONTEND_URL` (`.env.example`, défaut `http://localhost:5173`). En local, `php artisan queue:work` doit tourner pour recevoir les e-mails dans Mailpit (ajouté au démarrage du projet et à `PROGRESS.md`).

**Étapes et reprises de la tâche d'envoi [v3]** (contre-revue, constat 3) :

| Étape | En cas d'échec |
|---|---|
| 1. Décision d'éligibilité, émission du jeton (transaction validée) | la tâche est reprise (3 tentatives au total) ; rien n'a été envoyé |
| 2. Remise de l'e-mail au serveur de messagerie (`sendNow`) | **échec de transport** : la tâche est reprise avec un **nouveau** jeton (le précédent n'a été remis à personne) ; seul le dernier e-mail remis contient un lien valable. Après la 3ᵉ tentative : `account.link_email_failed` (seul cas où cette action est écrite) |
| 3. Journal « lien envoyé » | **jamais** de nouvel envoi : après une remise réussie, la tâche se termine avec succès et confie le journal à une petite tâche séparée `RecordLinkSent` (sans jeton : compte, type de lien, origine), elle-même reprise si l'écriture échoue |

- Limite assumée et documentée : une réponse SMTP incertaine (délai dépassé alors que le serveur avait accepté) peut provoquer une reprise ; l'utilisateur peut alors recevoir deux e-mails, dont seul le plus récent est valable. Aucune garantie de remise « exactement une fois » n'est affirmée.
- Les e-mails remis ne sont jamais réémis pour réessayer un journal.

### 11. Frontend

| Écran | Adresse | Contenu |
|---|---|---|
| Connexion (existant) | `/login` | lien « Mot de passe oublié ? » ; après `account_not_activated`, lien « Recevoir un nouveau lien d'activation » |
| Activer mon compte | `/activation#<jeton>` | vérification du lien ; « Choisissez votre mot de passe » (2 champs, « 10 caractères minimum. ») ; bandeau « autre compte connecté » ; succès → accueil du rôle ; lien invalide → message + formulaire de nouveau lien |
| Mot de passe oublié | `/mot-de-passe-oublie` | e-mail ; message générique après envoi ; mention entreprise (« contactez l'équipe d'organisation ») |
| Nouveau mot de passe | `/reinitialiser#<jeton>` | 2 champs ; bandeau « autre compte connecté » ; succès → `/login` avec message ; lien invalide → message + lien vers « Mot de passe oublié » |
| Mon profil | `/profil` | nom, identifiant, rôle ; bloc « Changer mon mot de passe » (actuel, nouveau, confirmation) |

- Lien « Mon profil » dans l'en-tête pour **tous les rôles**.
- Pages d'activation et de réinitialisation accessibles avec ou sans session (pas de redirection `GuestOnly`), avec le bandeau de la décision B.
- Affichage vérifié à 375 px.

### Hors périmètre

- Création des comptes, bouton « Renvoyer le lien » (T-10) ; réinitialisation par l'administrateur (T-10 / T-11 / T-12, qui devront appeler `revokeAll`) ; générateur de mots de passe aléatoires (T-11 / T-12) ; fichier des mots de passe d'import (T-13).
- Reste du profil (photo, description d'entreprise : T-11).
- Masquage des chemins dans les journaux du proxy de production (aucun jeton n'y passe grâce au fragment ; à vérifier avec T-01).
- Mise à jour du CDC (plus tard, avec accord).

## Plan

1. Règle `StrongPassword` + fichier de la liste (téléchargement de SecLists annoncé avant de le faire : nom, source, taille) + tests.
2. Migration `password_tokens`, `PasswordTokenService` (émission, consommation, révocation, purge planifiée) + tests de bornes et de concurrence.
3. Tâche chiffrée `ProcessPasswordEmailRequest`, notifications, limiteurs communs, `AuditLogger` (auteur explicite) + tests d'uniformité.
4. Activation : service pour T-10, routes, `SessionOpener` partagé avec la connexion + tests.
5. Réinitialisation et changement : routes, fermeture des sessions, autre compte connecté + tests.
6. Journal : actions, catégorie, neutralisation de l'observateur + tests.
7. Frontend : 4 écrans, lien de connexion, lien d'en-tête, bandeau, purge du cache, tests Vitest ; vérification navigateur avec Mailpit (worker lancé) et à 375 px.
8. Contrôles : Pint, PHPStan, suite complète, lint, tests et build frontend ; `PROGRESS.md`, `CURRENT_TASK.md`, `DECISIONS.md` (décisions du 17/11, avec accord).

## Tests [v2]

**Règle de mot de passe**
- 9 caractères refusés, 10 acceptés ; 64 acceptés, 65 refusés ; 64 caractères accentués dépassant 72 octets refusés ; emoji / Unicode comptés en caractères.
- Mot courant refusé quelle que soit la casse et avec espaces autour ; espaces de début et de fin **conservés** dans le mot de passe enregistré (connexion ensuite avec les espaces).
- Fichier absent → erreur, pas de validation silencieuse ; liste chargée une seule fois.

**Jetons**
- Expiration juste avant, exactement à, et juste après 48 h et 60 min (`Carbon::setTestNow`).
- Mauvais type (jeton d'activation sur la réinitialisation et inversement), jeton mal formé, inconnu, déjà utilisé → 410 identique.
- Deux émissions concurrentes sans ligne préalable → un seul jeton valable ; deux consommations concurrentes (deux connexions MySQL) → une seule réussit ; émission pendant une consommation.
- Compte désactivé, supprimé ou rôle changé entre la vérification et la validation → 410.
- Mot de passe invalide ou échec du journal → rien n'est modifié et le lien reste utilisable.

**Demandes publiques**
- Même statut, même corps, même nombre de tâches mises en file et aucune lecture de `users` dans la requête, pour : compte connu, inconnu, désactivé, supprimé, entreprise, sans rôle, non activé ; pour l'oubli et pour le renvoi d'activation.
- Variantes d'adresse que MySQL juge identiques (casse, accents) et espaces autour : même budget de limite ; 429 identique pour toutes les adresses ; budget commun aux deux demandes.
- Tâche de fond : bon e-mail au bon compte seulement (décision C comprise), aucun e-mail sinon ; contenu des tables `jobs` / `failed_jobs` sans jeton ni e-mail lisible (chiffrement).
- Réponses des routes à jeton avec `Cache-Control: no-store`.

**Activation**
- Lien valable une seule fois ; second lien invalide le premier ; compte entreprise, apprenant, désactivé, déjà activé, sans rôle refusé ; session ouverte, accueil du rôle, journal `auth.login_succeeded` ; un compte créé ne peut pas se connecter avant activation (critère AUTH-02) ; service T-10 : tâche mise en file seulement après la validation de la transaction appelante.

**Réinitialisation et changement**
- Après réinitialisation, toutes les anciennes sessions sont invalides, y compris une session réécrite après coup (critère AUTH-03) ; deux clients entreprise : le changement ferme l'autre, garde le sien ; heure de connexion conservée (limite de 12 h inchangée) ; changement refusé sans le bon mot de passe actuel (critère AUTH-03) ; nouveau = actuel refusé ; liens de réinitialisation en attente annulés (décision E) ; changement disponible pour les 5 rôles ; 401 sans session ; 429 au-delà de 5 tentatives.
- Autre compte connecté : la session de B est fermée par le serveur, le journal ne cite pas B.

**Journal**
- Une ligne par opération, aucune ligne `account.updated` en double, observateur rétabli après une exception ; auteur : visiteur (demande publique dans la tâche de fond), administrateur (service T-10), titulaire (activation, réinitialisation, changement) ; échec de remise journalisé ; jamais de jeton ni de mot de passe dans les valeurs.

**Frontend**
- Lien « Mot de passe oublié ? » ; formulaires et messages (succès, 410, 422, 429) ; jeton retiré de l'adresse ; aucun jeton ni mot de passe dans le cache TanStack après succès, erreur ou départ ; bandeau et purge du cache avec un autre compte connecté ; lien « Mon profil » pour les 5 rôles ; gardes existantes respectées.

**Ajouts [v3]** (contre-revue)
- Adresses : deux comptes `a@…` (latin) et `а@…` (cyrillique) : la demande de l'un ne sélectionne jamais l'autre et leurs budgets sont distincts ; `jose@…` / `josé@…` : même budget.
- Frontend : réessai avec le même lien après 422, après 429 et après une erreur réseau ; rechargement sans fragment → message « Ouvrez à nouveau le lien… » ; navigation retour ; le routeur continue de fonctionner après `history.replaceState`.
- Tâche d'envoi : remise réussie puis journal en échec → aucun second e-mail, journal écrit par la reprise de `RecordLinkSent` ; transport en échec puis reprise → nouveau jeton, l'ancien invalide ; 3 échecs de transport → `account.link_email_failed` ; deux tentatives traitées dans un ordre différent → un seul lien valable.
- Sessions : après un changement, **nouvelle requête authentifiée** avec le client courant (accepté) et avec l'autre client (refusé) ; empreinte de la session courante mise à jour (non réécrite avec l'ancienne par `AuthenticateSession`).
- `SessionOpener` : tests de T-04 conservés (limiteur, journal, 12 h, sessions entreprise simultanées) ; connexion chevauchant une réinitialisation : l'empreinte correspond au mot de passe vérifié.
- E-mails : lien avec fragment vérifié dans Mailpit (recette navigateur) ; contrôle à refaire avec le client de messagerie utilisé en recette.
