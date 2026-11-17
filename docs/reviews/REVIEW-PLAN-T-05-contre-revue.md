# Contre-revue indépendante du plan T-05 — Version 2

**Date :** 17/11/2026, Africa/Casablanca. **Branche :** `feature/T-05-mot-de-passe`.

Base et HEAD : `d0b92d4fa65c915f230493ff5834758b010afd9f`. Aucun diff de fichier suivi, aucun développement T-05 présent. Le rapport initial `REVIEW-PLAN-T-05.md` est conservé, conformément à la clarification de l'utilisateur.

## 1. Références et méthode

Documents examinés : `AGENTS.md`, `CLAUDE.md`, plan v2 (lignes 1 à 230), prompt de contre-revue, rapport initial et références AUTH-01/02/03, RG-AUTH-06 à 12, TRA-01 et décisions déjà examinées.

Les arbitrages 1 à 6 et les décisions **A à F** sont appliqués sans remise en cause : bcrypt conservé, limites 64 caractères/72 octets, déconnexion annoncée de l'autre compte, H1 et nouvelle différence du mot de passe validées, révocation des liens et audit à la remise au serveur de messagerie.

Lecture ciblée du code installé : `AuthenticateSession` et middleware stateful Sanctum, `SessionGuard::login()`, gestion des sessions Laravel, client API React. Un contrôle en mémoire a comparé la normalisation `Str::ascii`, la validation RFC et la collation MySQL sur des adresses fictives. Il n'a effectué que des SELECT dans `jobdating_test` ; aucune donnée ni table créée ou modifiée.

Cette revue porte sur un plan : aucun code T-05, test applicatif, migration ou pipeline exécuté. Les points non exécutés sont distingués des résultats du contrôle documentaire. `git diff --check develop` est sans erreur.

## 2. Suivi des six constats initiaux

| Nº | Statut | Section du plan v2 et commentaire |
|---|---|---|
| 1 — Jeton hors de sa table | **Traité** | §3, lignes 78–84 : fragment retiré de l'adresse, API POST, appels hors TanStack, absence de jeton dans les jobs, tâches chiffrées, no-store et protection des erreurs. Le nettoyage après toute erreur introduit toutefois le problème fonctionnel nº 2 ci-dessous. |
| 2 — Énumération par le traitement public | **Partiel** | §4, lignes 88–101 : même validation, mêmes limiteurs et une tâche pour toute adresse valide ; aucune recherche de compte avant le 202. Le principe est corrigé. L'adresse de recherche ne doit cependant pas être remplacée par la clé translittérée du compteur : voir constat nº 1. |
| 3 — Atomicité du cycle du jeton | **Traité** | §2 et §5 : unicité compte/type, verrou stable du compte puis du jeton, contrôles sous verrou, transaction complète, borne d'expiration explicite et `afterCommit()` pour T-10. Les reprises de la tâche d'envoi restent à préciser : constat nº 3. |
| 4 — Autre compte et sessions | **Traité dans le contrat** | Décision B, §8 : avertissement, fermeture côté client et serveur, purge des caches, identité cible explicite ; suppression des sessions et empreinte de mot de passe. La lecture du Laravel installé confirme un mécanisme utilisable, avec les précautions de tests exposées plus bas. |
| 5 — Longueur et bcrypt | **Traité** | Décision A et §1 : 10 à 64 caractères Unicode, au plus 72 octets, secret conservé tel quel, tests des bornes et d'Unicode. Aucun changement d'algorithme implicite. |
| 6 — Auteur et observateur | **Traité** | Décision F et §9 : contexte d'origine explicite, visiteur représentable dans le worker, titulaire pour la consommation, restauration de l'observateur dans un finally et connexion automatique auditée séparément. |

« Traité » signifie que l'exigence figure maintenant dans le plan ; aucune implémentation correspondante n'est encore validée.

## 3. Nouveaux constats

### 1. À corriger avant développement — Séparer l'adresse recherchée de la clé du limiteur

**Références :** plan, lignes 90–98 et 215 ; RG-AUTH-09/10. La tâche reçoit actuellement un « e-mail normalisé », dont la normalisation annoncée comprend `Str::ascii`.

**Constat confirmé par contrôle ciblé :** `Str::ascii` n'est pas une représentation exacte des équivalences de `utf8mb4_unicode_ci`. Il fusionne aussi des adresses que la collation distingue.

| Adresses fictives | Résultat de `Str::ascii` | Égales pour MySQL ? |
|---|---|---|
| `jose@example.test` et `josé@example.test` | `jose@example.test` pour les deux | Oui |
| `a@example.test` et `а@example.test` — second « а » cyrillique, U+0430 | `a@example.test` pour les deux | **Non** |
| `s@example.test` et `σ@example.test` — sigma grec | `s@example.test` pour les deux | **Non** |

Les six adresses passent également le validateur RFC installé. Le contrôle a utilisé MySQL **8.0.46**, avec comparaison explicite sous `utf8mb4_unicode_ci`.

**Scénario :** deux comptes peuvent avoir les adresses latine et cyrillique, distinctes pour l'unicité SQL. Si la demande cyrillique transmet seulement son équivalent ASCII au worker, celui-ci peut retrouver le compte latin. Le lien part alors vers ce voisin, tandis que le demandeur légitime ne reçoit rien. Si la translittération sert uniquement au limiteur, les deux comptes partagent au moins un budget qui était annoncé « par e-mail ».

**Impact :** mauvaise sélection de compte ou limitation croisée. La collision de normalisation est exécutée et confirmée ; le mauvais envoi reste un risque de l'implémentation décrite, pas une fuite constatée. Rien ne prouve qu'un lien serait envoyé à un tiers arbitraire : l'envoi doit toujours utiliser l'adresse du compte retrouvé.

**Correction minimale dans le plan :** conserver séparément l'adresse validée, avec espaces périphériques retirés et sans translittération destructive, pour la recherche du compte ; ne jamais utiliser une clé de compteur comme adresse. Définir une clé de limite compatible avec la collation, sans lecture de `users`, ou documenter explicitement ses collisions conservatrices. Ajouter le test de deux adresses distinctes qui deviennent identiques sous `Str::ascii`, en vérifiant l'absence de sélection du voisin.

### 2. À corriger avant développement — Conserver le jeton pendant une erreur récupérable

**Références :** plan, lignes 80, 84, 111, 119, 211 et 230.

**Incohérence documentaire confirmée :** le fragment est supprimé de l'adresse, aucun stockage navigateur n'est autorisé, et le jeton local doit être effacé « après […] erreur ». Cela supprime le seul exemplaire disponible au formulaire, y compris après un 422 que l'utilisateur doit pouvoir corriger.

**Scénario :** ouvrir le lien, retirer son fragment, envoyer un mot de passe trop faible, recevoir 422 puis corriger le champ. Le serveur a bien conservé le jeton, mais le composant l'a effacé : il ne peut plus réessayer sans rouvrir le lien reçu par e-mail. Même difficulté après un 429 ou une erreur réseau.

**Impact :** formulaire impossible à corriger normalement, malgré un lien encore valable. Ce n'est pas une régression exécutée : le code frontend n'existe pas encore.

**Correction minimale dans le plan :** distinguer les erreurs récupérables des sorties définitives. Garder le jeton **uniquement dans l'état local du composant** pendant un 422, un 429 ou une erreur réseau ; le supprimer au succès, au 410 définitif ou au départ de la page. Les mots de passe peuvent être effacés selon le cas, indépendamment du jeton. Cela conserve l'exclusion des caches partagés et du stockage navigateur.

Préciser aussi le rechargement volontaire après retrait du fragment : le jeton sera perdu, ce qui est une conséquence cohérente de l'absence de persistance. Afficher alors une invitation à rouvrir le lien de l'e-mail, sans lancer automatiquement une nouvelle émission. Tester correction après 422, attente après 429, retour après erreur réseau et rechargement.

### 3. À corriger avant développement — Distinguer reprise d'envoi et reprise d'audit

**Références :** plan, lignes 70, 82, 91, 151–165 ; décision F ; AUTH-02 et TRA-01.

**Lacune du contrat ; risque hypothétique :** le worker émet un jeton, appelle `sendNow`, puis journalise, avec trois tentatives annoncées. Il n'est pas précisé quelles étapes sont rejouées lorsqu'une tentative échoue.

**Scénario :** le serveur de messagerie accepte le premier e-mail, puis l'audit échoue. Si toute la tâche recommence, `issue()` remplace le premier jeton et renvoie un autre lien. Le premier e-mail, potentiellement déjà reçu ou livré plus tard, contient désormais un lien invalide. Après trois échecs d'audit, un callback générique pourrait aussi journaliser à tort un « échec définitif de remise » alors que la messagerie a accepté les e-mails.

**Impact :** liens invalidés sans nouvelle demande utilisateur et journal ne distinguant plus l'échec d'envoi de l'échec d'audit. Une réponse SMTP incertaine peut également provoquer une reprise ; aucun mécanisme ne doit promettre une remise exactement une fois.

**Correction minimale dans le plan :** définir les étapes et la politique de reprise. Ne pas réémettre un e-mail uniquement pour réessayer un audit après remise confirmée. Définir comment une reprise d'envoi conserve un lien cohérent pour la même demande — état reprenable protégé si nécessaire — ou documenter explicitement le comportement retenu si un nouveau jeton est émis. Distinguer les échecs réels de remise des erreurs postérieures. Le choix « jeton jamais dans la file » doit rester cohérent avec cette stratégie ; le chiffrement seul ne définit pas la reprise.

Ajouter les scénarios : remise réussie puis audit échoué, transport en échec puis reprise, et livraison de deux tentatives dans un ordre différent.

## 4. Avis sur les tests v2

La couverture est nettement améliorée : bornes exactes, deux connexions MySQL concurrentes, unicité, état modifié entre vérification et consommation, rollback, cinq rôles, annulation des liens et contexte d'audit sont désormais explicités.

Les ajouts nécessaires sont ciblés :

- Normalisation : distinguer équivalence de quota et adresse recherchée ; contrôler deux comptes distincts malgré une même translittération ASCII.
- Frontend : réessai avec le même lien après 422/429/erreur réseau, rechargement sans fragment, navigation retour ; préserver le fonctionnement du routeur lors du remplacement de l'historique.
- Queue : étapes de reprise et échec d'audit après remise, pas seulement échec définitif du transport.
- Sessions : après changement, effectuer une **nouvelle requête authentifiée** avec le client courant et avec l'autre client. Une ligne conservée dans `sessions` ne prouve pas à elle seule que le premier client reste connecté.
- `SessionOpener` : conserver les régressions T-04 sur limiteur, journal, 12 heures et connexions entreprise simultanées. Pour une connexion chevauchant un reset, vérifier que l'empreinte correspond au mot de passe effectivement vérifié lors de cette connexion.
- E-mails : contrôler le lien fragmenté dans Mailpit et dans le client de messagerie réellement utilisé en recette. La conservation du fragment lors d'une éventuelle réécriture de lien est à vérifier ; aucun échec d'un client particulier n'est affirmé ici.

Ces tests sont des compléments au plan. Aucun fichier de test n'a été écrit et aucune suite n'a été exécutée.

## 5. Questions au responsable technique

Les hypothèses métier précédentes sont tranchées par A à F ; elles ne sont pas rouvertes.

Deux choix techniques doivent être fixés dans le plan : quelle reprise après une remise SMTP réussie suivie d'un échec d'audit, et quel message présenter après un rechargement sans jeton ? Proposition : reprise d'audit sans nouvel envoi pour le premier cas ; invitation à rouvrir le lien reçu pour le second. La correction des adresses et la possibilité de corriger un 422 ne nécessitent pas un nouvel arbitrage métier.

## 6. Points validés sans remarque

### Fragment, POST et Sanctum

Le fragment évite d'inscrire le jeton dans les chemins HTTP et le Referer. Le transfert dans un corps POST avec `no-store` est cohérent ; aucun besoin d'introduire le jeton dans une query TanStack.

Une route publique peut rester protégée par CSRF. Le middleware stateful Sanctum installé inclut session et validation CSRF. Le client `apiRequest` obtient le cookie XSRF, transmet son en-tête et réessaie une fois après un 419. La décision de ne pas imposer globalement `no-referrer` est cohérente avec la reconnaissance du frontend par Sanctum. Les tests devront conserver les en-têtes d'origine attendus et vérifier le nouveau cookie après déconnexion de B.

### Empreinte de session : mécanisme réellement présent

La lecture du code installé confirme deux éléments complémentaires :

- `SessionGuard::login()` (`backend/vendor/laravel/framework/src/Illuminate/Auth/SessionGuard.php:567–575`) écrit déjà `password_hash_web`, avec le HMAC attendu. L'initialisation n'attend donc pas la première requête protégée, même si `AuthenticateSession` laisse passer le début d'une connexion sans utilisateur.
- `AuthenticateSession.php:42–57` compare l'empreinte existante au mot de passe du compte et déconnecte en cas d'écart. Une session réinsérée avec son ancienne empreinte sera refusée si la requête traverse ce middleware.

Attention à son fonctionnement exact : le middleware **réécrit aussi l'empreinte après le contrôleur**, lignes 60–63 et 90–98. Après un changement volontaire de mot de passe, le modèle de l'utilisateur porté par le garde doit refléter la nouvelle valeur, afin de ne pas réécrire l'ancienne empreinte. Le test de la requête suivante avec le client courant doit vérifier cela. La conservation de l'heure initiale est correctement prévue.

Cette analyse est statique ; elle ne vaut pas test exécuté de concurrence ni validation du futur `SessionOpener`.

### Autres points

- Travail public uniforme, une tâche chiffrée par adresse valide, absence de recherche de compte avant le 202 et budget partagé : bons principes contre l'énumération. Le volume de jobs pour les inconnus est un coût assumé de ce mécanisme, borné par les limiteurs ; aucun besoin nouveau de dépendance identifié.
- Unicité compte/type, ordre de verrous stable, relecture sous verrou, expiration sur une seule horloge et `afterCommit()` explicite : répondent au constat d'atomicité.
- Jeton créé dans le worker, envoi immédiat et absence de secret dans son contenu persistant : architecture cohérente, sous réserve de la politique de reprise du constat 3.
- B est annoncé puis déconnecté côté client et serveur ; reset de son propre compte ferme ses sessions ; activation ouvre l'identité cible et purge l'ancien cache. La décision B est correctement reprise.
- Limites 64 caractères et 72 octets, espaces conservés, ressource de mots courants absente refusée, générateur futur compatible : décision A correctement intégrée.
- Différence du mot de passe étendue au reset, révocation des liens en attente, contrats pour T-10 à T-12 : décisions D et E reprises.
- Origine d'audit explicite, auteur indépendant de B, observateur restauré, connexion automatique auditée distinctement : répondent au constat 6. Le moment de remise prévu par F reste pertinent ; sa qualification en reprise est à compléter.

## 7. Validation et verdict

Contrôle exécuté : script PHP en mémoire, autoload des bibliothèques existantes, validateur RFC installé, comparaisons SQL en lecture seule sur **`jobdating_test`, MySQL 8.0.46**. Toutes les adresses utilisées sont fictives. Aucune lecture de comptes réels, aucune écriture de données, aucune table temporaire et aucun fichier de banc créés. Aucun test de navigateur, d'e-mail ou de concurrence exécuté. La revue ne valide pas une implémentation applicative.

**Plan à revoir.**

La v2 traite l'essentiel des six constats initiaux et respecte les décisions A à F. Avant développement, dissocier l'adresse de recherche de la clé du limiteur, conserver le jeton lors des erreurs récupérables et définir les reprises d'envoi/audit. Le rapport initial est conservé ; seul ce nouveau rapport est créé.
