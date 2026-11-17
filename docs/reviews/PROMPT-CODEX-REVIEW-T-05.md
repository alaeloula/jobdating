# Prompt de revue Codex — T-05 (JD-12) : activation, oubli et changement du mot de passe

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating. Tu as revu le **plan** de T-05 deux fois (`docs/reviews/REVIEW-PLAN-T-05.md`, `REVIEW-PLAN-T-05-contre-revue.md`). Le code est maintenant écrit : tu fais la **revue du code**. **Tu ne modifies aucun fichier, tu ne commites rien, tu ne pousses rien, tu ne crées aucun fichier dans le dépôt** (bancs de contrôle en mémoire ou dans des tables préfixées supprimées ensuite). Seul livrable : `docs/reviews/REVIEW-T-05-mot-de-passe.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

## 1. Ce qui est à revoir

- Branche `feature/T-05-mot-de-passe`, base `develop` (`d0b92d4`), **non commitée** : `git diff develop` **et** fichiers non suivis.
- Exclusions : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.
- MySQL : `docker compose up -d mysql`. Tests sur `jobdating_test`, jamais SQLite.

## 2. Références

1. **`docs/tasks/PLAN-T-05.md`, version 3** : contrat de référence. Décision du responsable technique : **pas de troisième revue du plan** ; vérifie **dans le code** les trois points de ta contre-revue intégrés en v3 (marqués **[v3]**) :
   - adresse recherchée séparée de la clé du limiteur (`WEIGHT_STRING`, sans `Str::ascii`) ;
   - jeton conservé dans la page après une erreur récupérable, effacé au succès / 410 / départ ; rechargement → « Ouvrez à nouveau le lien… » ;
   - étapes et reprises de la tâche d'envoi (nouveau jeton seulement après un échec de remise ; jamais de second envoi après une remise réussie ; journal par `RecordLinkSent`).
2. `docs/DECISIONS.md` : décision du **17/11/2026** « Mots de passe (T-05) » et décisions des 14/11 et 16/11 sur les comptes et les sessions. Elles priment sur le CDC ; ne les remets pas en cause.
3. CDC V2 : AUTH-01 (exceptions), AUTH-02 (RG-AUTH-06 à 08), AUTH-03 (RG-AUTH-09 à 12), RG-ENTR-06 / 07, TRA-01.
4. Maquettes : `C:\Users\Youcode\Desktop\jobdating\maquettes\00-connexion.png`, `00-premiere-connexion-apprenant.png`, `entreprise-08-profil.png`.
5. `docs/PROGRESS.md` (entrée « 2026-11-17 — T-05 ») et `docs/tasks/CURRENT_TASK.md` (contrats pour T-10 à T-12).

Hors périmètre (ne pas signaler comme oubli) : création des comptes et bouton « Renvoyer le lien » (T-10), réinitialisation par l'administrateur (T-10 à T-12), générateur de mots de passe (T-11 / T-12), reste du profil (T-11), mise à jour du CDC.

## 3. Points à vérifier en priorité

- **Jetons** (`app/Services/Passwords/PasswordTokenService.php`, migration `2026_11_17_090000_…`) : hachage seul, unicité `(user_id, type)`, ordre des verrous (compte puis lien), relecture sous verrou, tout ou rien (le lien reste utilisable après une erreur), bornes d'expiration, mauvais type, mal formé, double usage, compte devenu inéligible.
- **Confidentialité** : jeton jamais dans une adresse (fragment côté frontend, corps des `POST`), jamais dans la file (`SendPasswordLink` chiffrée et jeton créé dans la tâche), jamais dans le journal ni les messages d'exception, `Cache-Control: no-store`, `dontFlash` ; frontend : `src/passwords/useLinkToken.ts`, pages `ActivationPage`, `ResetPasswordPage`, `ProfilePage` (rien dans le cache TanStack).
- **Énumération** (`PasswordLinkRequestController`, `PasswordEmailThrottle`) : même statut, même corps, même travail (aucune lecture de `users`) pour tout compte ; limites communes ; 429 identique ; clé d'adresse selon la collation.
- **Tâche d'envoi** (`app/Jobs/SendPasswordLink.php`, `RecordLinkSent.php`, `LinkAudit.php`) : éligibilité (décision C comprise), reprises, `failed()`, auteur explicite (visiteur / administrateur), `afterCommit()` du service T-10 (`AccountActivationService`).
- **Sessions** (`SessionRevoker`, `SessionOpener` partagé avec `LoginController`, `PasswordChangeController`, `PasswordResetController`, `HandlesPasswordLinks::closeForeignSession`) : toutes les sessions fermées à la réinitialisation, autres sessions au changement, session courante conservée (heure de connexion, empreinte du mot de passe), session réécrite après coup refusée, autre compte connecté (décision B) ; pas de régression de T-04.
- **Règle de mot de passe** (`app/Rules/StrongPassword.php`) : bornes 10 / 64 caractères et 72 octets, espaces conservés, liste (en-tête ignoré, fichier absent → erreur).
- **Journal** : 6 nouvelles actions, aucune ligne `account.updated` en double (`User::withoutAccountAudit`, restauré après exception), jamais de jeton ni de mot de passe.
- **Frontend** : liens de la connexion, 4 écrans, bandeau « autre compte », purge du cache au changement d'identité (`src/auth/session.ts`, aussi utilisé par la déconnexion), routes publiques et lien « Mon profil » pour les 5 rôles, affichage à 375 px.
- **Tests** : faux positifs éventuels, cas manquants importants.

## 4. Contrôles

**Ne relance pas la suite complète** (environ 25 minutes) : Claude vient de l'exécuter. Utilise ses résultats et cite-les comme **communiqués par Claude, non rejoués**. Tu restes libre d'exécuter tous les contrôles ciblés que tu juges utiles ; évite seulement de relancer ce qui figure ci-dessous.

Résultats de Claude (17/11/2026) :
- `vendor/bin/pint --test` : réussi ;
- `vendor/bin/phpstan analyse` : aucune erreur ;
- tests T-05 : `tests/Unit/StrongPasswordTest.php` et `tests/Feature/Passwords` : **67 tests réussis** (règle 16, jetons 13, demandes et envoi 15, activation et réinitialisation 11, changement 12) ;
- non-régression : `tests/Feature/Auth` et `tests/Feature/Audit` : 63 tests réussis ;
- `php artisan test` (suite complète) : **304 tests réussis, 1272 assertions** (environ 29 min, code de sortie 0). Une première exécution s'était arrêtée sur une erreur fatale : le faux journal du test T-02 `ReferentialManagementTest` (ligne 398) n'avait pas le nouveau paramètre `bool $visitor = false` de `AuditLogger::record` ; signature du test corrigée, puis suite complète relancée en entier ;
- frontend : `npm.cmd run lint` réussi, `npm.cmd run test` **43 tests réussis**, `npm.cmd run build` réussi (vérification TypeScript comprise) ;
- vérifié dans le navigateur avec le vrai backend, Mailpit et `queue:work` (comptes fictifs) : oubli, e-mail, réinitialisation (mot de passe courant refusé puis accepté avec le même lien), connexion, « Mon profil » à 375 px, changement, activation avec un autre compte connecté, journal en base.

Limite connue, signalée dans `PROGRESS.md` : pas de test de simultanéité réelle (deux connexions MySQL en parallèle) ; les verrous et l'unicité sont testés de façon séquentielle.

Environnement : PHP 8.5.12 (ENV-01 ouvert), MySQL 8.0, Node 24. La suite de Claude est **terminée** : la base `jobdating_test` est libre. Ne prétends jamais avoir exécuté un contrôle que tu n'as pas exécuté.

## 5. Format du rapport `docs/reviews/REVIEW-T-05-mot-de-passe.md`

Même structure que `docs/reviews/REVIEW-T-02-referentiels.md` : en-tête ; références et périmètre ; constats numérotés du plus grave au moins grave (Bloquant / Majeur / Mineur / Suggestion, fichier et ligne, bug confirmé ou risque hypothétique, scénario reproductible, impact, correctif minimal sans hors périmètre) ; vérification des 3 points [v3] ; points vérifiés sans remarque ; questions au responsable technique ; validation et limites ; **verdict** (« À fusionner », « À fusionner après corrections mineures », « À corriger avant fusion »).
