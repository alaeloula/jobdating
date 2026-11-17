# Prompt de validation du plan Codex — T-05 (JD-12) : activation, oubli et changement du mot de passe

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating. Il n'y a **pas de code à revoir** : tu valides le **plan** de la tâche T-05 **avant** le développement. **Tu ne modifies aucun fichier, tu ne commites rien, tu n'écris aucun code ni test.** Ton seul livrable est `docs/reviews/REVIEW-PLAN-T-05.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

## 1. Ce qui est à examiner

- Branche `feature/T-05-mot-de-passe` (créée depuis `develop`, `d0b92d4`), non commitée : fichiers non suivis.
- Le plan : **`docs/tasks/PLAN-T-05.md`** (version 1), dont la section « Arbitrages du responsable technique (17/11) » et les hypothèses **H1 à H4**.

## 2. Références

1. CDC V2 (`docs/reference/CAHIER_DES_CHARGES_V2.md`) : **AUTH-01** (exceptions), **AUTH-02** (RG-AUTH-06 à 08, 13), **AUTH-03** (RG-AUTH-09 à 12), RG-ENTR-06 / 07, matrice **3.2**, **TRA-01**, CDC 9 (sécurité).
2. `docs/DECISIONS.md` : les décisions du responsable technique **priment** sur le CDC (14/11 : première connexion de l'apprenant non bloquante, mot de passe généré, réinitialisation par l'administrateur pour tous les comptes, entreprises sans e-mail ; 16/11 : compte entreprise partagé et fermeture des autres sessions). Les arbitrages 1 à 6 du plan (17/11) sont **validés**. Ne les remets pas en cause ; signale seulement une incohérence entre eux ou avec le CDC.
3. `docs/reference/ARCHITECTURE.md` : liste locale de mots de passe courants, file d'attente `database`, section 11 (définition de « terminé »).
4. Maquettes : `C:\Users\Youcode\Desktop\jobdating\maquettes\00-connexion.png`, `00-premiere-connexion-apprenant.png`, `entreprise-08-profil.png`.
5. Code existant : connexion (`app/Http/Controllers/Auth/LoginController.php`, limiteur `login` dans `app/Providers/AppServiceProvider.php`), validité de session (`app/Http/Middleware/EnsureSessionIsValid.php`), `app/Models/User.php` (`activated_at`, `deactivated_at`), journal (`app/Services/AuditLogger.php`, `app/Enums/AuditAction.php`, `app/Observers/UserAuditObserver.php`), migrations par défaut (`sessions`, `password_reset_tokens`), frontend (`src/AppRoutes.tsx`, `src/pages/LoginPage.tsx`, `src/lib/api.ts`).

## 3. Ce qu'il faut vérifier

- **Couverture** : AUTH-02 et AUTH-03 et leurs critères d'acceptation (« un compte créé ne peut pas se connecter avant activation », « un lien d'activation ne fonctionne qu'une fois », « un changement sans le bon mot de passe actuel est refusé », « après réinitialisation, les sessions précédentes ne sont plus valides ») sont-ils tous couverts par le plan et ses tests ?
- **Sécurité des jetons** : table `password_tokens` (hachage SHA-256, jeton seul dans le lien, un jeton par compte et par type, consommation sous verrou, expiration, purge) : sain ? Risque de fuite (journal, cache frontend, adresse, en-tête Referer) ? Faut-il comparer en temps constant ?
- **Énumération des comptes** (RG-AUTH-09) : réponses identiques (statut, corps, durée grâce à la file d'attente) pour un e-mail connu, inconnu, désactivé, entreprise, non activé ; et pour le renvoi du lien d'activation. Reste-t-il un canal qui révèle l'existence d'un compte (codes 410, limiteur par e-mail, message après activation…) ?
- **Limites** (RG-AUTH-10 et arbitrage 4) : clés, normalisation de l'e-mail, comportement avec le Wi-Fi partagé de l'événement, limites ajoutées par le plan (consommation des jetons, changement depuis le profil) : justifiées ?
- **Sessions** (RG-AUTH-12, décision du 16/11) : suppression des sessions dans la table `sessions` ; conservation de la session courante lors d'un changement ; `remember_token` ; interaction avec `EnsureSessionIsValid` et Sanctum (cookies) ; compte entreprise partagé.
- **Activation** : conditions (rôle CME / Direction / administrateur, non désactivé, non activé), connexion automatique (réutilisation sans duplication des contrôles de connexion), service prévu pour T-10 : contrat suffisant ?
- **Hypothèses H1 à H4** : avis argumenté pour chacune (conforme au CDC ? risque ?).
- **Règle de mot de passe** : liste de 10 000 mots de SecLists, comparaison insensible à la casse, espaces, limite de 255, chargement ; réutilisation par T-10 à T-13.
- **Journal** : nouvelles actions et catégorie, neutralisation de l'observateur, auteur des envois publics ; aucun jeton ni mot de passe journalisé.
- **Frontend** : écrans, routes publiques, jeton hors du cache, comportement d'un utilisateur déjà connecté, conformité aux maquettes (le plan suit le CDC — deux champs — contre la maquette `entreprise-08-profil`).
- **Tests prévus** : suffisants ? Cas manquants importants (droits des 5 rôles, concurrence, expiration aux bornes).
- **Hors périmètre** : bien délimité, sans dépendance bloquante oubliée pour T-10 à T-13.

## 4. Format du rapport `docs/reviews/REVIEW-PLAN-T-05.md`

1. En-tête : date, branche, fichiers examinés.
2. Constats numérotés, du plus important au moins important : **Bloquant / À corriger avant développement / Suggestion**, avec la référence (CDC, décision, plan), le problème, l'impact et la correction proposée **dans le plan** (pas de code).
3. Avis sur les hypothèses H1 à H4.
4. Questions au responsable technique (points que les références ne tranchent pas).
5. Points du plan validés sans remarque.
6. **Verdict** : « Plan validé », « Plan validé après corrections mineures » ou « Plan à revoir ».
