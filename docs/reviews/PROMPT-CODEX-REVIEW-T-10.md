# Prompt de revue Codex — T-10 (JD-15) : comptes de l'équipe

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating. Tu fais la **revue du code** de T-10. Il n'y a **pas eu de revue du plan** (choix du responsable technique pour cette tâche simple) : le contrat de référence est décrit ci-dessous et dans `docs/PROGRESS.md` (entrée « 2026-11-18 — T-10 »). **Tu ne modifies aucun fichier, tu ne commites rien, tu ne pousses rien, tu ne crées aucun fichier dans le dépôt** (bancs en mémoire ou tables préfixées supprimées ensuite). Seul livrable : `docs/reviews/REVIEW-T-10-utilisateurs.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

## 1. Ce qui est à revoir

- Branche `feature/T-10-utilisateurs`, base `develop` (`27217ae`), **non commitée** : `git diff develop` **et** fichiers non suivis.
- Exclusions : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.
- **Tests dans Docker (PHP 8.4)** : `docker compose run --rm php php artisan test …` (≈ 1 s par test, au lieu de 5 à 7 s sous Windows). MySQL sur `jobdating_test`, jamais SQLite.

## 2. Références

1. CDC V2 : **ADM-01** (RG-USR-01 à 05), RG-ACC-01 à 06, matrice 3.2 (`users.manage` : administrateur seul), TRA-01.
2. `docs/DECISIONS.md` : 14/11 (réinitialisation par l'administrateur pour tous les comptes, mot de passe généré affiché une fois et envoyé par e-mail, création des comptes CME / Direction / administrateur par lien d'activation), 17/11 (T-05 : règle de mot de passe, liens, sessions). Elles priment sur le CDC.
3. **Réponses du responsable technique du 18/11** (contrat de T-10, ne pas remettre en cause) :
   - écran limité aux comptes CME, Direction et administrateur (entreprises T-11, apprenants T-12) ;
   - suppression logique et restauration incluses, bien qu'absentes de la maquette ;
   - interdiction de supprimer, désactiver ou changer le rôle de **son propre compte** ; il reste **toujours au moins un administrateur actif** ;
   - réinitialisation : mot de passe généré affiché une seule fois (avec « Copier ») et envoyé par e-mail ; photo hors périmètre.
4. Maquette : `C:\Users\Youcode\Desktop\jobdating\maquettes\admin-10-comptes-cme-direction.png`.
5. `docs/ERREURS_A_CORRIGER.md` (E-01 à E-03, reportés à T-49) : ne pas les signaler à nouveau.

## 3. Points à vérifier en priorité

- **Droits** (`routes/api.php`, `StaffAccountController`) : chaque route réservée à `users.manage` ; 401 sans session ; un compte entreprise ou apprenant est traité comme inexistant (404, RG-ACC-02) ; aucune donnée sensible renvoyée (`StaffAccountResource`).
- **Règles** (`app/Services/Accounts/StaffAccountService.php`) : e-mail unique y compris compte supprimé ; rôle unique parmi les 3 ; propre compte ; dernier administrateur actif (verrou des administrateurs, relecture sous verrou, cas concurrents) ; actions sur un compte supprimé ; désactivation et suppression → sessions fermées et liens annulés (RG-ACC-05).
- **Création** : compte non activé, mot de passe inutilisable, lien d'activation par `AccountActivationService` après validation de la transaction ; journal (création, rôle).
- **Réinitialisation** : `PasswordGenerator` (aléa, alphabet, conformité à `StrongPassword`) ; refus pour un compte non activé ou désactivé ; liens annulés ; sessions fermées (son propre compte : autres sessions seulement, session courante conservée) ; journal avec l'administrateur comme auteur, jamais le mot de passe ; `GeneratedPasswordNotification` chiffrée dans la file et envoyée après validation ; `Cache-Control: no-store` ; mot de passe jamais relisible.
- **Liste** : recherche (échappement des jokers `LIKE`), filtres, pagination, comptes supprimés exclus par défaut.
- **Frontend** (`src/pages/AccountsPage.tsx`, `src/accounts/api.ts`) : boutons selon le statut et `is_self`, confirmations, affichage unique du mot de passe hors cache TanStack, erreurs du serveur, lien d'en-tête, garde de route administrateur.
- **Tests** (`tests/Feature/Accounts/StaffAccountTest.php`, `src/accounts/accounts.test.tsx`) : faux positifs, cas manquants importants.

## 4. Contrôles

**Ne relance pas la suite complète** : Claude vient de l'exécuter. Cite ses résultats comme **communiqués par Claude, non rejoués**. Tu restes libre d'exécuter les contrôles ciblés que tu juges utiles.

Résultats de Claude (18/11/2026) :
- `vendor/bin/pint --test` : réussi ; `vendor/bin/phpstan analyse` : aucune erreur ;
- `tests/Feature/Accounts/StaffAccountTest.php` : **20 tests réussis** (Docker, PHP 8.4) ;
- suite complète dans Docker : **329 tests réussis, 1503 assertions** (≈ 4 min, code de sortie 0) ;
- frontend : lint réussi, **50 tests réussis**, build réussi ;
- navigateur (compte administrateur de démonstration) : liste, création d'un compte fictif (e-mail d'activation dans Mailpit), réinitialisation d'un compte fictif (mot de passe affiché une fois, e-mail reçu).

Environnement : PHP 8.4 dans Docker pour les tests (8.5.12 sous Windows pour le site), MySQL 8.0, Node 24. La suite de Claude est **terminée** : `jobdating_test` est libre. Ne prétends jamais avoir exécuté un contrôle que tu n'as pas exécuté.

## 5. Format du rapport `docs/reviews/REVIEW-T-10-utilisateurs.md`

En-tête ; références et périmètre ; constats numérotés du plus grave au moins grave (Bloquant / Majeur / Mineur / Suggestion, fichier et ligne, bug confirmé ou risque hypothétique, scénario reproductible, impact, correctif minimal) ; pour chaque constat rare et sans impact sur les tâches suivantes, indique-le (il pourra être reporté dans `ERREURS_A_CORRIGER.md`) ; points vérifiés sans remarque ; questions au responsable technique ; validation et limites ; **verdict** (« À fusionner », « À fusionner après corrections mineures », « À corriger avant fusion »).
