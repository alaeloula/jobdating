# Revue indépendante — T-11 (JD-16) : entreprises

- **Date :** 19/11/2026, Africa/Casablanca.
- **Branche :** `feature/T-11-entreprises`.
- **Base et HEAD :** `develop`, `bde6a2a70078930be9bb5038f807fd95ec54ce2d`.
- **État :** développement non commité ; diff avec `develop` et fichiers non suivis examinés.
- **Livrable :** ce rapport uniquement. Aucun code ni test modifié ou créé dans le dépôt, aucun commit ni push.

## 1. Références et périmètre

Consignes appliquées : `AGENTS.md`, `CLAUDE.md` et demande de revue. Contrat : `PLAN-T-11.md`, **version 2**, décisions validées des 14/11, 16/11, 17/11 et 19/11, entrée T-11 de `PROGRESS.md`, CDC ADM-02, ADM-07, ENT-08, matrice 3.2, RG-ACC et TRA-01. La présentation V2 et les quatre maquettes ont déjà été examinées lors de la revue du plan ; les changements [v2] sont vérifiés ici dans le code, sans exiger une nouvelle revue du plan.

Examen : migration et modèles, services d'entreprises et d'identifiants, contrôleurs et ressources, routes, permissions et matrice, audit, seeder de démonstration ; écran et composants Entreprises, présentation du profil, `FormData`, styles d'impression, tests backend et frontend concernés.

Les reports validés à T-13/T-14/T-23/T-31/T-40/T-61 ne sont pas signalés comme manques. E-01 à E-03 ne sont pas rouverts. Exclusions respectées : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.

## 2. Constats

### 1 — Le nettoyage des logos ne suit pas la transaction englobante

- **Sévérité : Majeur.**
- **Fichiers et lignes :** `backend/app/Services/Companies/CompanyService.php:57–95`, `:157–170` et `:178–189`.
- **Qualification : bug confirmé au niveau du service**, sur MySQL avec stockage simulé en mémoire. Les routes actuelles, sans transaction englobante, ne reproduisent pas à elles seules ce scénario.
- **Référence :** plan v2, section 5 : ancien fichier supprimé par `DB::afterCommit`, nouveau fichier nettoyé si la transaction échoue ; atomicité demandée pour la création.

**Scénario reproductible :**

1. Créer une entreprise avec un logo A.
2. Ouvrir une transaction, appeler `replaceLogo()` avec B, puis annuler la transaction englobante.
3. Relire la ligne et vérifier les opérations effectuées sur les fichiers.

**Résultat obtenu :** A est supprimé **avant** le commit englobant. Après rollback, la ligne pointe à nouveau vers A, qui n'existe plus ; B reste orphelin. Le service appelle `discardLogo()` dès le retour de sa transaction interne, sans callback de commit. `removeLogo()` présente le même ordre de suppression.

Un second rejeu, avec `create()` dans une transaction englobante annulée, laisse le fichier du nouveau logo alors que le compte et la fiche ont bien disparu. Le `catch` du service ne s'exécute pas lorsque son appel réussit puis que l'appelant annule ensuite.

**Impact :** perte du fichier encore référencé en base et fichiers orphelins. La compensation est correcte pour un échec interne immédiat, mais pas pour la composition transactionnelle du service.

**Correctif minimal :** différer la suppression de l'ancien fichier jusqu'au commit effectif ; enregistrer le nettoyage du nouveau sur rollback, y compris celui d'un appelant. La version installée de Laravel propose `afterCommit()` et `afterRollBack()`. Ajouter les tests d'une transaction englobante annulée pour création, remplacement et suppression.

**Report :** déclenchement conditionné à un appel englobant, donc moins fréquent aujourd'hui ; ce n'est pas un cas à déclarer sans impact sur les tâches suivantes, car il casse le contrat transactionnel d'un service destiné à être réutilisé, notamment lors de parcours d'import. Le rattachement exact des imports futurs reste à implémenter dans T-13.

### 2 — Une nouvelle fiche réutilise l'état « impression confirmée » de la précédente

- **Sévérité : Mineur.**
- **Fichiers et lignes :** `frontend/src/companies/CredentialsSheet.tsx:25–35` et `frontend/src/pages/CompaniesPage.tsx:247–254`.
- **Qualification : bug confirmé dans le composant et la page réels**, avec React, TanStack Query et API simulée en mémoire.
- **Référence :** décision 8 et contrat v2 : confirmation de **cette version** de la fiche.

**Scénario :** réinitialiser une entreprise, confirmer l'impression, puis réinitialiser à nouveau sans fermer la fiche affichée. Le même problème peut concerner une autre entreprise réinitialisée alors que la première fiche reste ouverte.

**Résultat obtenu :** la fiche passe de la version 2 à la version 3 et affiche le nouveau mot de passe. Le serveur simulé indique bien une nouvelle fiche non imprimée, mais « Impression confirmée. » reste affiché et « J’ai imprimé la fiche » reste désactivé. Un seul appel de confirmation a été effectué, pour la version précédente.

`CredentialsSheet` conserve son état local lorsque ses propriétés changent ; le parent ne lui donne pas de clé liée à l'entreprise et à la version.

**Impact :** information fausse et impossibilité de confirmer la nouvelle impression depuis cette fiche. La protection serveur des versions reste correcte : ce défaut ne marque pas la nouvelle fiche comme imprimée en base.

**Correctif minimal :** remonter le composant avec une clé associant identifiant d'entreprise et version des identifiants. Cela isole aussi une confirmation précédente encore en cours. Tester deux émissions successives sans fermeture intermédiaire.

**Report :** défaut limité à ce parcours d'interface, sans dépendance bloquante identifiée pour les tâches suivantes. Le déclenchement est reproductible à chaque séquence décrite, pas une course exceptionnelle ; un report éventuel nécessite une décision explicite.

### 3 — Les profils fournis à la création sont ignorés

- **Sévérité : Mineur.**
- **Fichiers et lignes :** `backend/app/Http/Controllers/CompanyController.php:70–76`, `:209–217` ; `backend/app/Services/Companies/CompanyService.php:76–87`.
- **Qualification : écart confirmé entre API et plan v2**, par requête HTTP sur MySQL.
- **Référence :** plan v2, section 3 : création dans une transaction unique incluant les profils recherchés.

**Scénario :** l'administrateur crée une entreprise en transmettant `filiere_ids` et `competence_ids` contenant chacun un identifiant actif existant.

**Résultat obtenu :** **201**, mais les deux collections retournées sont vides et aucune association n'est créée. Ces champs ne sont ni validés ni transmis au service. Le formulaire actuel ne propose pas leur saisie à la création ; la modification séparée des profils fonctionne.

**Impact :** un client suivant le contrat décrit reçoit un succès malgré l'abandon de ses profils initiaux. Il doit effectuer une seconde opération, contrairement au contrat transactionnel annoncé.

**Correctif minimal :** accepter et valider les listes initiales facultatives puis les appliquer dans la transaction de création. Si le choix produit est désormais une saisie exclusivement après création, faire acter et documenter cet écart au contrat au lieu de le considérer implicitement couvert.

**Report :** ce n'est pas un cas rare : tout envoi de profils initiaux est ignoré. L'interface permet une saisie ultérieure, mais le contrat destiné aux autres clients/services doit être clarifié avant sa réutilisation.

## 3. Vérification des points [v2]

| Point issu de la revue du plan | Résultat |
|---|---|
| Version des fiches | **Traité côté serveur.** Création à 1 ; reset à 2 et statut non imprimé ; version 1 ensuite refusée en **409**, code `stale_credentials`. |
| Confirmation explicite et idempotente | Bouton distinct de `window.print()` ; deux confirmations de la même version donnent **200/200**, avec **un seul audit**. État frontend incomplet lors du remplacement de la fiche : constat 2. |
| Atomicité du logo | Validation avant stockage ; erreur interne d'audit : compte, fiche, audit et nouveau fichier annulés. Échec de stockage : aucune création. **Partiel** pour les transactions englobantes : constat 1. |
| Proposition d'identifiant toujours valide | Repli court, troncature et place réservée au suffixe présents. Bancs `X`, `HP`, ponctuation seule et nom de 150 caractères : format et longueur valides. Recherche avec comptes supprimés dans le code ; test fourni couvrant les suffixes à plusieurs chiffres. |
| Collision après validation | Une seconde connexion MySQL insère le même identifiant juste avant l'insertion principale : réponse **422** sur `username`, nouvelle proposition `review-race-2`, aucune fiche partielle. |
| Associations inactives | Maintien d'une compétence inactive en ajoutant une active : **200**. Nouvelle inactive : **422**, sans perte des associations précédentes. Retrait volontaire : **200**. |
| Secteur devenu inactif | Maintenu lors d'une modification, y compris identifiant reçu sous forme de chaîne numérique : **200**. Le code vérifie l'activité lorsqu'on choisit un nouveau secteur. |
| Identité `users` et audit | Nom d'entreprise de 150 caractères conservé ; `first_name` limité à 100, `last_name` vide, compte activé, e-mail nul, rôle Entreprise. Sujet d'audit « Entreprise : [nom] » vérifié. Le renommage synchronise l'identité du compte. |
| Passage de relais T-14 | Aucune dépendance à un événement dans la création ; relations et clés stables pour `event_companies`. Le plan et `PROGRESS.md` indiquent bien que la participation reste différée. |
| Points de vigilance | `closeAll()` utilisé au reset ; aucune réutilisation de l'éligibilité par e-mail ni de la notification T-10. Périmètre propre et liste blanche vérifiés. Matrice indépendante mise à jour avec les deux lignes attendues. |

## 4. Points vérifiés sans remarque

**Droits.** Bancs sur douze routes : liste, fiche, suggestion, création, modification, ajout/suppression de logo, désactivation, réactivation, reset, confirmation et profils. Sans session : **401** partout. CME : **200** en liste, fiche et profils ; **403** sur les neuf autres opérations. Direction, Entreprise et Apprenant : **403** sur les douze routes de gestion. L'administrateur traverse les parcours de création, modification, profils, confirmation, reset et désactivation testés. La route de lecture du logo, distincte, est examinée ci-dessous.

**Périmètre propre.** Avec deux entreprises, `PUT /me/company` contenant l'identifiant de B et des champs interdits modifie seulement la description de A. B est inchangée ; nom et version des identifiants de A restent inchangés. La sélection du compte est faite côté serveur, pas à partir du corps reçu.

**Reset et sessions.** Deux clients connectés avec le même compte Entreprise reçoivent **401/401** après réinitialisation administrative. Le nouveau hash correspond au secret retourné ; les réponses de création/reset portent **`no-store, private`**. Aucune notification envoyée dans le banc ; les deux secrets contrôlés sont absents des audits. Après désactivation : aucune session restante et reset refusé en **422**.

**Matrice.** Les deux nouvelles lignes sont littérales et indépendantes du code : gestion pour l'administrateur seul, profils pour administrateur et CME. Les autres lignes ne changent pas. La matrice passe ainsi à 27 permissions et 135 cellules ; ces tests complets n'ont pas été relancés par l'auditeur.

**Journal et données.** Catégorie Entreprises correctement reliée aux quatre actions ; identité du sujet via `HasAuditLabel`. Les ressources de gestion n'exposent ni hash, ni mot de passe, ni chemin interne de logo. Migration cohérente : compte unique, clés étrangères, pivots sans doublons, secteur facultatif, recruteurs prêts pour T-31. Aucun événement ou emplacement ajouté prématurément.

**Frontend.** Le secret passe par des fonctions directes et un état local, pas par les mutations TanStack. Confirmation liée à la version et fermeture effaçant la fiche présentes. `FormData` est transmis sans sérialisation JSON ni `Content-Type` multipart fabriqué à la main. Garde de route administrateur/CME et lien d'en-tête cohérents. Bandeau des profils fidèle ; références associées inactives affichées et conservées tant qu'elles ne sont pas décochées. Les styles d'impression ciblent la fiche ; aucune nouvelle recette d'impression réelle n'a été effectuée ici.

**Démonstration.** Le seeder crée une fiche pour le compte Entreprise de démonstration sans événement, seulement si elle manque. Sa protection `APP_ENV=local` demeure ; aucun compte de démonstration n'est ajouté au seeder de production.

### Écart annoncé : logo sur disque privé

Le choix est **cohérent et acceptable** : il supprime la dépendance au lien public et sert uniquement le fichier d'image, sans fiche ni coordonnées. La validation impose raster, contenu reconnu comme image, taille et dimensions ; le chemin stocké est généré par le serveur. Le contrôleur vérifie existence du fichier et droits, ajoute `nosniff` et un cache privé de cinq minutes. SVG est exclu.

Il faut toutefois qualifier cette protection : `companies.view` est attribuée aux cinq rôles ; cette route n'applique pas de filtre d'entreprise ou d'événement. Elle protège donc contre l'accès anonyme, pas contre la lecture d'un autre logo par un utilisateur connecté. Le plan prévoyait des **logos publics** : aucune confidentialité de ces images n'était promise, contrairement aux contacts et recruteurs. Cela ne constitue pas ici un nouveau constat de fuite. Les futurs écrans doivent appliquer leurs propres périmètres aux données d'entreprise. Reporter le choix du disque privé dans le plan/documentation pour éviter une divergence durable.

## 5. Qualité des tests

- Les tests de version et de double confirmation vérifient de vrais changements de statut et le nombre d'audits. Ils détecteraient le défaut de version signalé pendant la revue du plan.
- Le test d'échec d'audit après stockage vérifie correctement la compensation d'une erreur **interne** à `create()`. Il ne couvre pas une opération réussie suivie d'un rollback de l'appelant. Le test des remplacements successifs vérifie la suppression immédiate, sans contrôler le commit englobant : il passe malgré le constat 1.
- Le test frontend confirme une fiche puis la ferme. Il ne présente jamais une deuxième version au même composant encore monté ; il passe malgré le constat 2. Le test 409 complète utilement la protection serveur, sans couvrir cette réutilisation d'état local.
- Les tests de création n'envoient pas de profils initiaux ; ils ne révèlent pas le constat 3. Les tests du `PUT /profiles` vérifient bien maintien d'inactifs et retrait volontaire.
- La suppression des deux lignes de session artificielles est testée dans la suite fournie ; le banc indépendant a complété cela avec deux clients à cookies et leurs requêtes suivantes.
- Aucune reproduction de collision tardive ou d'échec de stockage n'apparaît dans les tests fournis ; ces deux chemins ont été contrôlés ici. Les contrôles de refus de formats d'image sont ceux des tests communiqués, non une nouvelle batterie exécutée par l'auditeur.

## 6. Questions au responsable technique

Le sens de « Fiche imprimée » est désormais tranché et appliqué : aucune question à rouvrir. Le constat 3 demande soit l'alignement de la création sur le plan, soit la validation explicite d'une saisie des profils après création. L'atomicité des logos et la remise à zéro de l'état frontend ne nécessitent pas de nouvel arbitrage métier.

## 7. Validation et limites

### Contrôles exécutés par cet auditeur

**PHP 8.4.27 dans Docker**, **MySQL 8.0.46**, base **`jobdating_test`**, collation de connexion **`utf8mb4_unicode_ci`** ; **Node 24.21.0** pour le banc frontend. Aucun SQLite.

Les bancs PHP sont exécutés en mémoire par `docker compose run --rm -T php php -r …`. Les données fictives utilisent des copies de tables préfixées `review_t11_<identifiant>_`, nettoyées en `finally`. Les copies conservent colonnes et index, pas les clés étrangères ni les déclencheurs ; aucune preuve supplémentaire sur ceux-ci n'est déduite de ces bancs.

Les opérations de stockage sont remplacées par un disque en mémoire : le constat 1 prouve l'ordre des suppressions et l'état SQL, sans créer ni supprimer de fichier réel. Les scénarios d'erreur de stockage/audit utilisent la même limite. Les parcours à cookies passent par le noyau HTTP Laravel. La collision tardive utilise une connexion MySQL indépendante, intercalée avant l'insertion ; elle ne constitue pas un test de charge.

Le banc frontend charge la page et les composants réels avec React/TanStack dans un DOM simulé ; seule l'API est remplacée par des réponses conformes au contrat. Aucun fichier de test ni de compilation n'est écrit dans le dépôt. Pas de nouvelle recette visuelle à 375 px, de lecture réelle d'image servie ou d'impression physique.

`git diff --check develop` réussit. La recherche finale des tables préfixées `review_t11_` est vide : toutes les tables de contrôle ont été supprimées. Aucun code T-11 corrigé par l'auditeur.

### Résultats communiqués par Claude, non rejoués par cet auditeur

Résultats du **19/11/2026** :

- Pint réussi ; PHPStan sans erreur.
- Companies, Authorization et Audit : **200 tests réussis**, dont **23** pour T-11, dans Docker avec PHP 8.4.
- Suite complète : **364 tests réussis, 1719 assertions**, environ cinq minutes, code de sortie 0.
- `DemoAccountsSeederTest` : **2 tests réussis**, relancé seul après la dernière modification du seeder ; la suite complète n'est donc pas présentée comme relancée après cette dernière modification.
- Frontend : lint réussi, **56 tests réussis**, build réussi.
- Navigateur : création d'une entreprise fictive, proposition d'identifiant, affichage de la fiche et confirmation d'impression.

Aucune de ces suites ou commandes n'a été relancée. Les exécutions indépendantes sont exclusivement les bancs ciblés décrits ci-dessus.

## 8. Verdict

**À corriger avant fusion.**

La plupart des points v2 sont traités et les droits contrôlés sont corrects. L'atomicité des logos reste incomplète pour une transaction englobante. Il faut aussi corriger l'état de la fiche remplacée et aligner, ou faire acter, le contrat des profils initiaux.
