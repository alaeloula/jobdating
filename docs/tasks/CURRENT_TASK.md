# Tâche courante – T-05 (JD-12) : activation, oubli et changement du mot de passe

Branche : `feature/T-05-mot-de-passe`.

Plan validé : `docs/tasks/PLAN-T-05.md` (version 3, après la revue et la contre-revue du plan par Codex ; pas de troisième revue, les trois derniers points sont à vérifier dans la revue du code). Décisions : `docs/DECISIONS.md`, ligne du 17/11/2026 « Mots de passe (T-05) ».

Périmètre livré :
- règle commune `StrongPassword` : 10 à 64 caractères, au plus 72 octets, refus des 10 000 mots de passe les plus courants (`backend/resources/security/common-passwords.txt`, SecLists) ;
- liens à usage unique (`password_tokens`, SHA-256 seul, un lien par compte et par type, verrous compte puis lien, expiration 48 h / 60 min, purge quotidienne) ;
- activation des comptes CME, Direction, administrateur (connexion automatique) ; service `AccountActivationService` pour T-10 ;
- mot de passe oublié (apprenant, CME, Direction, administrateur ; nouveau lien d'activation pour un compte non activé), même réponse et même travail quel que soit le compte, tâche de fond chiffrée qui décide et envoie ;
- réinitialisation (toutes les sessions fermées, retour à la connexion) et changement depuis « Mon profil » (5 rôles, autres sessions fermées sauf pour un compte entreprise — décision du 17/11 —, liens de réinitialisation annulés) ;
- journal : 6 nouvelles actions (catégorie « Comptes »), auteur explicite (« Visiteur non connecté » pour les demandes publiques) ;
- frontend : « Mot de passe oublié ? » sur la connexion, pages « Activer mon compte », « Mot de passe oublié », « Nouveau mot de passe », « Mon profil » (lien d'en-tête pour tous les rôles).

Contrats pour les tâches suivantes :
- **T-10** : après la création d'un compte CME / Direction / administrateur (et pour le bouton « Renvoyer le lien »), appeler `AccountActivationService::sendLink($user, $admin)` après l'attribution du rôle ;
- **T-10 / T-11 / T-12** (réinitialisation par l'administrateur) : appeler `PasswordTokenService::revokeAll($user, PasswordTokenType::Reset)` et `SessionRevoker::closeAll($user)` ; les mots de passe générés doivent respecter 72 octets ;
- **toute saisie de mot de passe** : règle `App\Rules\StrongPassword`.

Contrôles locaux (MySQL Docker démarré) :

```powershell
# racine du dépôt : tests et style dans Docker, PHP 8.4 (ENV-01, ≈ 5 min au lieu de 29)
docker compose run --rm php php artisan test
docker compose run --rm php vendor/bin/pint --test

# backend/ (PHP sous Windows)
vendor/bin/phpstan analyse
php artisan migrate
php artisan queue:work   # pour recevoir les e-mails dans Mailpit (http://localhost:8025)

# frontend/
npm.cmd run lint
npm.cmd run test
npm.cmd run build
```

Tâche suivante : à choisir dans `docs/BACKLOG.md` (semaine 2 : T-10).
