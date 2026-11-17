# Prompt de contre-revue du plan Codex — T-05 (JD-12) : activation et mots de passe

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating. Tu as rendu `docs/reviews/REVIEW-PLAN-T-05.md` (verdict « Plan à revoir »). Claude a écrit la **version 2** du plan : tu fais la **contre-revue du plan**. Il n'y a toujours **pas de code**. **Tu ne modifies aucun fichier, tu ne commites rien, tu n'écris aucun code ni test.** Seul livrable : `docs/reviews/REVIEW-PLAN-T-05-contre-revue.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

## 1. Ce qui est à examiner

- Branche `feature/T-05-mot-de-passe` (base `develop`, `d0b92d4`), non commitée : fichiers non suivis.
- **`docs/tasks/PLAN-T-05.md`, version 2** : les changements sont marqués **[v2]**.

## 2. Décisions du responsable technique prises après ta revue (17/11)

À appliquer, ne pas remettre en cause (tableau « Décisions du responsable technique (17/11) » du plan) :
- **A** : mot de passe de **64 caractères maximum** et au plus **72 octets** ; bcrypt conservé ; aucune troncature silencieuse.
- **B** : lien ouvert alors qu'un **autre** compte est connecté → bandeau « Vous êtes connecté en tant que [B]. Pour continuer, vous serez déconnecté. » ; la validation déconnecte B, purge ses données côté client, puis termine le parcours.
- **C** (H1) : oubli sur compte non activé → nouveau lien d'activation.
- **D** (H3) : nouveau mot de passe différent de l'actuel ; pas d'historique.
- **E** : un changement de mot de passe annule les liens de réinitialisation en attente (contrat aussi pour la réinitialisation administrative de T-10 à T-12).
- **F** : « lien envoyé » journalisé à la **remise au serveur de messagerie** par la tâche de fond ; échec définitif journalisé ; aucune affirmation de réception.
- Les arbitrages 1 à 6 de la version 1 restent validés.

## 3. Ce qu'il faut vérifier

**A. Tes constats 1 à 6** : pour chacun, dire **traité / partiel / non traité** dans le plan v2, avec la section concernée :

| # | Ton constat | Réponse annoncée dans la v2 |
|---|---|---|
| 1 | Jeton hors de sa table | Jeton dans le **fragment** de l'adresse ; vérification et consommation en `POST` (jeton dans le corps) ; jeton **créé dans la tâche de fond** et envoyé aussitôt (`sendNow`), jamais dans la file ; tâches chiffrées (`ShouldBeEncrypted`) ; `Cache-Control: no-store` ; `dontFlash` ; frontend hors TanStack (sections 3, 11) |
| 2 | Énumération par la durée | Même travail pour toute adresse valide : validation, limiteurs, une tâche chiffrée, 202 ; aucune lecture de `users` dans la requête ; décision dans la tâche ; budget commun aux deux demandes, clé e-mail normalisée (`Str::ascii`, minuscules, espaces autour) (section 4) |
| 3 | Atomicité du cycle | Unicité `(user_id, type)` ; ordre de verrous compte puis jeton ; relecture sous verrou ; tout dans une transaction ; `afterCommit()` explicite pour le service T-10 ; `now >= expires_at` en horloge PHP (sections 2, 5) |
| 4 | Autre compte connecté | Décision B côté frontend **et** fermeture côté serveur ; deux protections des sessions (suppression des lignes + empreinte du mot de passe vérifiée par `AuthenticateSession` de Sanctum) ; heure de connexion conservée (section 8) |
| 5 | Limite de bcrypt | Décision A : 64 caractères et 72 octets, tests Unicode (section 1) |
| 6 | Auteur et observateur | Contexte d'origine transmis à la tâche ; libellé d'auteur explicite dans `AuditLogger` ; jamais B ; neutralisation limitée à l'opération avec `finally` et aux champs `password`, `activated_at`, `remember_token` ; `auth.login_succeeded` distinct après activation (section 9) |

**B. Les nouveaux choix de la v2 n'ouvrent-ils pas de problème ?** Notamment :
- **fragment + `POST`** : compatible avec Sanctum (`Origin` / `Referer`, CSRF sur des routes publiques), avec l'e-mail (clients de messagerie qui coupent ou réécrivent les fragments ?), avec `history.replaceState` et le rechargement de la page ;
- **jeton créé dans la tâche** : nouvelles tentatives de la tâche (un nouveau jeton à chaque tentative ? ancien lien déjà reçu invalidé ?) ; ordre « émission validée puis envoi » ; tâche du service T-10 mise en file après la transaction appelante ;
- **une tâche par demande valide**, même pour une adresse inconnue : volume dans `jobs` sous les limites (10 / min par IP) ; panne de la file ;
- **normalisation par `Str::ascii`** : équivalence réelle avec la collation `utf8mb4_unicode_ci` ; risque de partager le budget entre deux comptes différents ;
- **empreinte de Sanctum** : la protection contre une session réécrite après coup tient-elle réellement (ordre d'écriture de l'empreinte dans `AuthenticateSession`, session courante après changement) ? Sinon, quelle protection minimale ajouter ?
- **fermeture côté serveur de la session de B** sur une route publique : CSRF, nouveau jeton, et cohérence avec le frontend ;
- **`SessionOpener`** partagé avec la connexion : risque de régression sur T-04 (journal, limiteur, 12 h).

**C. Les tests [v2]** : couvrent-ils les constats et les décisions A à F ? Cas encore manquants importants ?

## 4. Contrôles

Revue documentaire du plan. Tu peux lire tout le code utile (Laravel, Sanctum, frontend) et exécuter des **contrôles en mémoire** si nécessaire, sans créer de fichier dans le dépôt. La base `jobdating_test` est libre. Ne prétends jamais avoir exécuté un contrôle que tu n'as pas exécuté.

## 5. Format du rapport `docs/reviews/REVIEW-PLAN-T-05-contre-revue.md`

Même structure que `docs/reviews/REVIEW-PLAN-T-02-contre-revue.md` : en-tête ; suivi des constats 1 à 6 (statut, section, commentaire) ; nouveaux constats éventuels (**Bloquant / À corriger avant développement / Suggestion**, référence, problème, impact, correction proposée **dans le plan**) ; avis sur les tests ; questions au responsable technique ; points validés ; **verdict** (« Plan validé », « Plan validé après corrections mineures » ou « Plan à revoir »).
