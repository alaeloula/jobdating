# Prompt de contre-revue Codex — T-05 (JD-12) : activation et mots de passe

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating. Tu as rendu `docs/reviews/REVIEW-T-05-mot-de-passe.md` (verdict « À corriger avant fusion », 5 constats). Claude a traité ce rapport : tu fais une **contre-revue limitée**. **Tu ne modifies aucun fichier de code, tu ne commites rien, tu ne pousses rien, tu ne crées aucun fichier dans le dépôt** (bancs de contrôle en mémoire ou dans des tables préfixées supprimées ensuite). Seul livrable : `docs/reviews/REVIEW-T-05-contre-revue.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

## 1. Ce qui est à revoir

- Branche `feature/T-05-mot-de-passe`, base `develop` (`d0b92d4`), **toujours non commitée** : `git diff develop` **et** fichiers non suivis.
- Exclusions : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.
- Référence des corrections : `docs/PROGRESS.md`, entrée « 2026-11-17 — T-05 », paragraphe « Revue du code par Codex… ».
- **Périmètre** : uniquement les points ci-dessous et leurs effets de bord. Le reste de T-05 a déjà été revu.

## 2. Décision du responsable technique (17/11)

À appliquer, ne pas remettre en cause : les défauts **rares et sans impact sur les développements suivants** sont reportés dans **`docs/ERREURS_A_CORRIGER.md`**, traités par la tâche **T-49** avant la recette (`docs/BACKLOG.md`, semaine 6). Les constats **2, 4 et 5** y figurent (E-01, E-02, E-03) : **ne les signale pas comme non corrigés**. Les constats **1 et 3** ont été jugés à impact (code repris par T-10 à T-12 et contrat « tout ou rien ») et sont corrigés.

**Nouvelle décision du 17/11 après ta revue** (`DECISIONS.md`, « Compte entreprise : changement du mot de passe sans déconnexion des autres recruteurs ») : pour un compte **entreprise**, un changement depuis « Mon profil » **ne ferme plus** les autres sessions ; leur empreinte de mot de passe est mise à jour. Les autres rôles gardent RG-AUTH-12. La réinitialisation ferme toujours toutes les sessions, entreprise comprise. Risque accepté par le responsable technique : ne pas le remettre en cause, mais vérifie l'implémentation (partie 3.D).

## 3. Ce qu'il faut vérifier

**A. Rejouer tes constats 1 et 3** avec les mêmes scénarios, et dire : corrigé / partiel / non corrigé.

| # | Correction annoncée | Fichiers |
|---|---|---|
| 1 | Vérifications du mot de passe actuel et du nouveau mot de passe sur la ligne relue **sous verrou**, dans la transaction ; compte désactivé → 401 ; écriture sur cette ligne ; le modèle du garde reçoit les valeurs enregistrées (`setRawAttributes`) avant `migrate(true)` | `backend/app/Http/Controllers/Auth/PasswordChangeController.php` ; test `PasswordChangeTest::test_une_reinitialisation_intercalee_n_est_pas_ecrasee_avec_l_ancien_mot_de_passe` |
| 3 | Activation, journal d'activation **et** ouverture de session (`SessionOpener`, avec son journal de connexion) dans le rappel de `consume()`, donc dans la même transaction ; en cas d'exception : déconnexion du garde, invalidation de la session, nouveau jeton CSRF, puis exception relancée | `backend/app/Http/Controllers/Auth/ActivationController.php` ; test `ActivationAndResetTest::test_echec_du_journal_de_connexion_annule_l_activation_et_garde_le_lien` |

**B. Effets de bord** :
- constat 1 : l'empreinte de session de la session courante après un changement (requête suivante acceptée, autre client refusé) ; aucune régression des 5 rôles, de l'heure de connexion, de la limite de 5 tentatives ;
- constat 3 : ouverture de session (régénération de l'identifiant, `last_login_at`) dans une transaction : effet sur la session côté navigateur en cas de succès et d'échec ; autre compte déjà déconnecté par `closeForeignSession` avant l'échec (comportement attendu par la décision B ?) ; aucune régression de la connexion normale (`LoginController` utilise toujours `SessionOpener` hors transaction) ;
- **`docs/ERREURS_A_CORRIGER.md`** : les trois entrées décrivent-elles fidèlement tes constats 2, 4 et 5 (scénario, fichier, correction prévue) ? Le classement « sans impact sur les développements suivants » te paraît-il justifié ? Signale seulement un désaccord argumenté.

**C. Qualité des deux nouveaux tests** : échoueraient-ils sur l'ancien code ?

**D. Décision « compte entreprise »** (`backend/app/Services/Auth/SessionRevoker.php`, `afterPasswordChange()` et `refreshOtherFingerprints()` ; `PasswordChangeController` ; `frontend/src/pages/ProfilePage.tsx`) :
- la mise à jour de la clé `password_hash_web` dans le contenu des autres sessions (format `base64(serialize(...))`, pilote `database`, sessions non chiffrées) est-elle correcte et robuste (session sans empreinte, contenu illisible, `allowed_classes => false`) ? Les autres recruteurs restent-ils bien connectés au-delà de la première requête ?
- n'ouvre-t-elle pas d'autre chemin : rôles non entreprise toujours fermés, réinitialisation (`closeAll`) toujours complète pour une entreprise, une requête concurrente d'un autre recruteur qui réécrit sa session avec l'ancienne empreinte (déconnexion de ce recruteur seulement : limite notée dans `PROGRESS.md`) ;
- tests : `PasswordChangeTest::test_autre_session_fermee_la_courante_conservee`, `test_compte_entreprise_les_autres_recruteurs_restent_connectes`, `test_la_fermeture_complete_reste_valable_pour_une_entreprise`.

## 4. Contrôles

**Ne relance pas la suite complète** (environ 30 minutes) : Claude vient de l'exécuter. Utilise ses résultats et cite-les comme **communiqués par Claude, non rejoués**. Tu restes libre d'exécuter tous les contrôles ciblés que tu juges utiles ; évite seulement de relancer ce qui figure ci-dessous.

Résultats de Claude (17/11/2026, après les corrections) :
- `vendor/bin/pint --test` : réussi ; `vendor/bin/phpstan analyse` : aucune erreur ;
- `php artisan test tests/Feature/Passwords/PasswordChangeTest.php` : 15 tests réussis ; `tests/Feature/Passwords/ActivationAndResetTest.php` : 12 tests réussis ;
- `php artisan test` (suite complète) : **308 tests réussis, 1302 assertions** (environ 29 min, code de sortie 0) ;
- frontend (seul `ProfilePage.tsx` et son test ont changé : message après changement pour une entreprise) : lint réussi, 43 tests réussis, build réussi.

Environnement : PHP 8.5.12 (ENV-01 ouvert), MySQL 8.0, Node 24. La suite de Claude est **terminée** : la base `jobdating_test` est libre. Ne prétends jamais avoir exécuté un contrôle que tu n'as pas exécuté.

## 5. Format du rapport `docs/reviews/REVIEW-T-05-contre-revue.md`

Même structure que `docs/reviews/REVIEW-T-02-contre-revue.md` : en-tête ; rejeu des constats 1 et 3 (scénario, résultat, statut) ; mention des constats 2, 4 et 5 reportés (E-01 à E-03) et avis sur leur description ; nouveaux constats éventuels (sévérité, fichier et ligne, confirmé ou hypothétique, scénario, impact, correctif minimal) ; qualité des tests ; points vérifiés sans remarque ; validation et limites ; **verdict** (« À fusionner », « À fusionner après corrections mineures », « À corriger avant fusion »).
