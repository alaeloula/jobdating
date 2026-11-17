# Prompt de deuxième contre-revue Codex — T-05 (JD-12) : constat 6 seulement

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating. Tu as rendu `docs/reviews/REVIEW-T-05-contre-revue.md` (verdict « À corriger avant fusion », **un seul nouveau constat : le 6**, sessions entreprise). Claude l'a corrigé : tu fais une **deuxième contre-revue limitée au constat 6**. **Tu ne modifies aucun fichier de code, tu ne commites rien, tu ne pousses rien, tu ne crées aucun fichier dans le dépôt** (bancs de contrôle en mémoire ou dans des tables préfixées supprimées ensuite). Seul livrable : `docs/reviews/REVIEW-T-05-contre-revue-2.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

## 1. Ce qui est à revoir

- Branche `feature/T-05-mot-de-passe`, base `develop` (`d0b92d4`), **toujours non commitée**.
- Exclusions : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.
- Référence de la correction : `docs/PROGRESS.md`, paragraphe « Contre-revue Codex (`docs/reviews/REVIEW-T-05-contre-revue.md` …) ».
- **Périmètre** : uniquement le constat 6 et ses effets de bord. Les constats 1 et 3 sont validés ; E-01 à E-03 sont reportés (T-49) : ne les rouvre pas.

## 2. Correction annoncée

- `backend/app/Http/Controllers/Auth/PasswordChangeController.php` : le hachage du mot de passe **avant** le changement est lu sur la ligne verrouillée et passé à `SessionRevoker::afterPasswordChange()` (paramètre `#[\SensitiveParameter]`, jamais journalisé).
- `backend/app/Services/Auth/SessionRevoker.php`, `refreshOtherFingerprints()` : pour un compte entreprise, seules les sessions dont `password_hash_web` correspond à l'ancien mot de passe (format HMAC de `hashPasswordForCookie()` **ou** hachage brut, comparaison `hash_equals`) reçoivent la nouvelle empreinte ; les sessions à l'empreinte périmée, sans empreinte ou illisibles sont **supprimées**.
- Test : `tests/Feature/Passwords/PasswordChangeTest.php::test_entreprise_une_session_revoquee_ne_redevient_pas_valable` (ta chaîne : connexion → réinitialisation et `closeAll()` → réécriture tardive → deux recruteurs connectés avec le nouveau mot de passe → changement par l'un → ancien client 401 deux fois, ligne supprimée, collègue et recruteur 200).

## 3. Ce qu'il faut vérifier

**A. Rejouer ton scénario du constat 6** : corrigé / partiel / non corrigé.

**B. Effets de bord** :
- les recruteurs réellement valides restent connectés au-delà de la première requête ; les autres rôles ferment toujours leurs autres sessions ; la réinitialisation (`closeAll`) reste complète ;
- formats d'empreinte reconnus par la version installée du middleware Sanctum `AuthenticateSession` : la comparaison HMAC / brut correspond-elle exactement à sa vérification ?
- suppression d'une session sans empreinte ou illisible : risque de déconnecter à tort un recruteur légitime dans un cas réaliste ?
- aucune fuite de l'ancien hachage (journal, exception, trace).

**C. Qualité du nouveau test** : échouerait-il sur le code de ta contre-revue précédente ?

## 4. Contrôles

**Ne relance pas la suite complète** (environ 30 minutes) : Claude vient de l'exécuter. Cite ses résultats comme **communiqués par Claude, non rejoués**. Tu restes libre d'exécuter les contrôles ciblés que tu juges utiles.

Résultats de Claude (17/11/2026, après la correction) :
- `vendor/bin/pint --test` : réussi ; `vendor/bin/phpstan analyse` : aucune erreur ;
- `php artisan test tests/Feature/Passwords/PasswordChangeTest.php` : **16 tests réussis** ;
- `php artisan test` (suite complète) : **309 tests réussis, 1312 assertions** (environ 29 min, code de sortie 0) ;
- frontend : aucun fichier modifié depuis ta contre-revue.

Environnement : PHP 8.5.12 (ENV-01 ouvert), MySQL 8.0, Node 24. La suite de Claude est **terminée** : la base `jobdating_test` est libre. Ne prétends jamais avoir exécuté un contrôle que tu n'as pas exécuté.

## 5. Format du rapport `docs/reviews/REVIEW-T-05-contre-revue-2.md`

En-tête ; rejeu du constat 6 (scénario, résultat, statut) ; nouveaux constats éventuels (sévérité, fichier et ligne, confirmé ou hypothétique, scénario, impact, correctif minimal) ; qualité du test ; points vérifiés sans remarque ; validation et limites ; **verdict** (« À fusionner », « À fusionner après corrections mineures », « À corriger avant fusion »).
