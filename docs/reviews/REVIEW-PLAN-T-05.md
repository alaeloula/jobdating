# Revue indépendante du plan T-05 — Activation et mots de passe

**Date :** 17/11/2026, Africa/Casablanca. **Branche :** `feature/T-05-mot-de-passe`.

Base : `develop`, `d0b92d4fa65c915f230493ff5834758b010afd9f` ; `HEAD` identique. Aucun diff de fichier suivi. Le plan v1 et le prompt sont non suivis ; aucun développement T-05 n'est présent.

## 1. Références et méthode

Documents examinés : `AGENTS.md`, `CLAUDE.md`, `docs/tasks/PLAN-T-05.md` (lignes 1 à 161), décisions des 14 et 16 novembre, CDC AUTH-01/02/03, RG-ENTR-06/07, matrice 3.2, TRA-01 et exigences de sécurité ; architecture, liste locale de mots de passe, file `database` et définition de « terminé ».

Les **six arbitrages du 17 novembre sont considérés comme validés**, même si leur report dans `DECISIONS.md` reste à faire. Ils ne sont pas remis en cause. Les décisions validées priment sur les passages antérieurs du CDC.

Code existant lu pour les intégrations : connexion et limiteur, `LoginRequest`, `User`, validité de session, observateur et service d'audit, actions, migrations des sessions et files, configuration de queue/Sanctum, routes et gardes React, connexion, hooks d'authentification et client API. Lecture ciblée des composants Laravel installés : notifications en queue, hachage, exclusion des mots de passe du nettoyage des espaces et authentification de session Sanctum.

Les trois maquettes demandées ont été ouvertes et examinées. Une vérification documentaire supplémentaire concerne la limite bcrypt : [manuel PHP de `password_hash`](https://www.php.net/manual/en/function.password-hash.php).

**Limites :** revue documentaire uniquement. Branche, état et diff Git vérifiés ; `git diff --check develop` sans erreur. Aucun test, banc applicatif, migration ou commande sur une base exécuté. Les scénarios ci-dessous désignent des risques à prévenir dans le plan, pas des bugs reproduits. Seul ce rapport est créé.

## 2. Constats, par priorité

### 1. À corriger avant développement — Protéger le jeton au-delà de sa table de hachage

**Références :** plan, lignes 50–54, 80, 114 et 121–128 ; CDC EXI-LOG-02, EXI-SEC-09. Code utile : migration des files (`jobs.payload`, `failed_jobs.payload`), `SendQueuedNotifications.php:66–90`.

**Problème :** « le jeton en clair n'est jamais stocké » n'est pas garanti par le seul SHA-256 dans `password_tokens`. Une notification mise en queue doit transporter le lien ; sans chiffrement, son jeton peut se retrouver dans le contenu sérialisé de `jobs`, puis de `failed_jobs`. Le chemin des pages et des GET API contient aussi le jeton. Le plan ne prévoit pas les protections des journaux d'accès, du Referer ou des caches HTTP.

**Scénario :** un e-mail reste en attente ou échoue ; le contenu du job contient l'URL d'activation. Autre cas : une page `/activation/<jeton>` charge une ressource ou appelle l'API et son URL complète est conservée dans un journal technique ou transmise comme Referer. Le hachage de la table métier n'empêche pas ces copies.

**Impact :** exposition d'un secret permettant l'activation ou la réinitialisation. Lacune du contrat confirmée ; aucune fuite actuelle constatée.

**Correction minimale dans le plan :** prévoir le chiffrement des jobs/notifications contenant un jeton — le Laravel installé prend en charge `ShouldBeEncrypted` — et l'absence de secret dans les exceptions et journaux de worker. Préciser la suppression ou le masquage des chemins secrets dans les journaux HTTP applicatifs et proxy, une politique `Referrer-Policy: no-referrer` sur ces pages et `Cache-Control: no-store` sur leurs réponses sensibles. La remise par e-mail reste nécessaire ; la garantie doit viser les copies persistantes contrôlées par l'application.

Pour TanStack, `gcTime: 0` ne signifie pas « jamais dans le cache » lorsqu'une entrée est encore observée. Définir une vérification de lien sans jeton dans une clé de query partagée, sans cache persistant, et le nettoyage des variables de mutation et mots de passe à la fin du parcours, y compris après erreur ou abandon. Ajouter des contrôles ciblés sur les contenus de queue et les en-têtes.

### 2. À corriger avant développement — Ne pas assimiler e-mail asynchrone et absence d'énumération

**Références :** plan, lignes 70, 76–81 et 159 ; RG-AUTH-09/10 ; arbitrage 4.

**Problème :** `ShouldQueue` retire l'envoi SMTP de la requête, mais pas nécessairement la recherche du compte, la génération et le remplacement du jeton, l'audit et l'insertion du job. Ces opérations ne sont effectuées que pour certaines adresses dans le traitement décrit. Le même 202 et le même corps ne garantissent donc pas une durée comparable.

**Scénario :** comparer plusieurs demandes sur des adresses syntaxiquement valides, certaines activables et d'autres inconnues ou désactivées, en respectant les limiteurs. Le chemin connu écrit plusieurs lignes tandis que le chemin inconnu n'en écrit pas. Une panne de queue sur le seul chemin connu pourrait aussi distinguer les réponses.

**Impact :** canal temporel ou différence d'erreur permettant de distinguer des catégories de comptes. Risque de conception, non mesuré ici.

**Correction minimale dans le plan :** mettre en queue un même travail de traitement pour toute demande valide, puis décider dans le worker de l'éligibilité et de l'envoi ; ou décrire un autre mécanisme assurant un travail comparable. Appliquer le même principe au renvoi d'activation. Les refus d'éligibilité du service ne doivent pas remonter publiquement sous forme d'exception distincte.

Préciser aussi les clés des limiteurs : même normalisation pour adresses connues et inconnues, sans reprendre aveuglément `loginTarget()` qui utilise un identifiant de compte existant. La collation MySQL ignore casse et accents ; les variantes qu'elle considère équivalentes doivent partager le budget sans révéler l'existence du compte. Définir les espaces comme ceux de début/fin, sans rendre valide une adresse comportant des espaces internes. Conserver les seuils validés **1/min par e-mail et 10/min par IP**, consommés indépendamment de l'éligibilité et sans 429 réservé aux comptes existants.

Les tests doivent comparer connu, inconnu, désactivé, entreprise, non activé et supprimé, ainsi que les variantes d'adresse et le renvoi d'activation. Un 410 uniforme après présentation d'un jeton imprévisible ne constitue pas, à lui seul, une énumération par e-mail.

### 3. À corriger avant développement — Définir l'atomicité de tout le cycle du jeton

**Références :** plan, lignes 43–60, 65–69, 83 et 147–159 ; RG-AUTH-06/12. Configuration existante : `backend/config/queue.php:44`, `after_commit` à faux.

**Problème :** seule l'unicité de `token_hash` est indiquée dans le schéma. Le verrou de consommation protège un jeton existant ; il ne suffit pas à garantir « un seul jeton par compte et par type » lors de deux émissions simultanées, ni à coordonner un renvoi avec une consommation. La notification après validation de la transaction n'est pas définie.

**Scénario :** deux émissions constatent l'absence de jeton, suppriment l'ancien et insèrent chacune leur ligne. Ou un worker traite un e-mail avant que le jeton ou le compte créé par T-10 soit définitivement enregistré.

**Impact :** plusieurs liens valables alors que le nouveau doit invalider l'ancien, ou lien envoyé mais inutilisable. Une consommation doit également éviter toute mise à jour partielle du mot de passe, de l'état et du journal.

**Correction minimale dans le plan :**

- Ajouter l'unicité SQL **`(user_id, type)`** et une transaction d'émission avec verrou stable du compte, même lorsqu'aucune ligne de jeton n'existe encore.
- Définir un ordre de verrou commun à émission et consommation ; relire le jeton et l'éligibilité du compte sous les verrous, notamment après désactivation, suppression ou changement de rôle.
- Rendre atomiques consommation, mot de passe, activation éventuelle, révocation des sessions et audit ; une validation ou un audit échoué ne consomme pas le lien.
- Expédier les notifications après commit, y compris lorsque le service est appelé depuis la transaction de création de T-10. Ne pas dépendre du réglage `after_commit` actuel.
- Définir l'expiration à la borne : **`now >= expires_at` signifie expiré**, avec la même référence temporelle pour vérification et consommation.

Remplacer « concurrence simple » par des cas précis : deux émissions sans ligne préalable, deux consommations, renvoi contre consommation, et rollback sur échec. Aucun choix de rôle ou de durée validé n'est modifié par ces précisions.

### 4. À corriger avant développement — Résoudre le cas H4 avec deux comptes différents

**Références :** plan, lignes 68, 83, 92, 127 et 139–142 ; RG-AUTH-12 ; `frontend/src/auth/guards.tsx:98–106`, hooks d'authentification.

**Problème :** H4 autorise l'ouverture du lien avec une session déjà active, sans définir la sortie du parcours si le lien concerne un autre compte. H2 prévoit un retour à `/login`, mais `GuestOnly` redirige immédiatement un utilisateur encore connecté vers son accueil.

**Scénario :** B est connecté et ouvre le lien de réinitialisation de A. Le plan ferme les sessions de A, pas celle de B, puis navigue vers `/login` : la garde peut renvoyer vers l'accueil de B. À l'activation de A, l'ouverture automatique d'une session A remplace l'identité du navigateur ; les données et caches de B ne doivent pas rester associés à la nouvelle session.

**Impact :** résultat trompeur, changement de compte mal compris et risque de données du précédent utilisateur conservées dans l'interface. Incohérence de parcours confirmée dans les contrats ; aucune fuite exécutée.

**Correction minimale dans le plan :** décrire séparément visiteur, utilisateur cible déjà connecté et autre compte connecté. Choisir explicitement, pour ce dernier, entre conservation de sa session avec succès affiché sur la page publique, ou déconnexion/changement de compte annoncé avant validation. Lors d'un changement d'identité, purger les caches privés et remplacer `me`. Lors d'une réinitialisation de son propre compte, invalider aussi son état client et sa session courante, conformément au résultat prévu.

Pour le changement depuis le profil, conserver l'heure initiale de connexion et l'empreinte de mot de passe de la session courante attendue par Sanctum, tout en régénérant son identifiant. Prévoir des tests avec deux clients entreprise, un cookie ancien, et une requête de connexion/session en cours pendant la réinitialisation : supprimer des lignes ne doit pas laisser une ancienne session redevenir valable après son écriture tardive. Le middleware Sanctum existant est un point d'appui à vérifier, pas une garantie à supposer.

### 5. À corriger avant développement — Aligner la limite de 255 caractères avec le hachage

**Références :** plan, lignes 35–36 et 157 ; `backend/vendor/laravel/framework/config/hashing.php:18–34`, `BcryptHasher` ; RG-AUTH-07.

**Problème :** le pilote fourni par défaut est bcrypt, tandis que le plan accepte jusqu'à 255 caractères. Le manuel PHP documente une prise en compte limitée à **72 octets** pour bcrypt. Une limite en caractères ne résout pas cette différence, notamment avec Unicode. [Source : manuel PHP](https://www.php.net/manual/en/function.password-hash.php).

**Scénario :** deux mots de passe longs partagent les 72 premiers octets et diffèrent ensuite ; le contrat de validation accepte leur différence, alors que bcrypt ne la conserve pas. H3 et le comportement de connexion deviennent ambigus.

**Impact :** une partie du mot de passe choisi peut être ignorée. Risque conditionnel au maintien de bcrypt ; la configuration effective de l'environnement n'a pas été exécutée ni contrôlée ici.

**Correction minimale dans le plan :** fixer une limite compatible avec le pilote retenu. Si bcrypt est conservé, contrôler les octets avant hachage et préciser les tests Unicode, sans troncature silencieuse. Si les 255 caractères doivent être réellement pris en compte, faire valider le choix de hachage et sa compatibilité avec les comptes déjà hachés. La longueur de la colonne SQL qui contient le hash n'est pas une limite du mot de passe en entrée.

### 6. Suggestion — Préciser l'auteur des événements asynchrones et la neutralisation de l'observateur

**Références :** plan, lignes 98–110 et 161 ; TRA-01 ; `AuditLogger.php:47–69`, `UserAuditObserver.php:28–60`.

**Problème :** un worker sans requête produit actuellement l'auteur « Système ». À l'inverse, une requête publique déjà accompagnée de la session B fait inférer B lorsque `actor` reste nul. Ces deux comportements diffèrent du « Visiteur non connecté » prévu pour les envois publics. L'activation modifie aussi `activated_at`, pas seulement `password`, et peut donc déclencher un doublon de l'observateur.

**Scénario et impact :** l'envoi public ou celui de l'administrateur T-10 est audité par le worker avec le mauvais auteur ou sans l'origine prévue ; une activation produit à la fois une action dédiée et une modification générique.

**Précision proposée dans le plan :** transmettre un contexte d'origine explicite au travail asynchrone, sans jeton ni mot de passe, et définir comment le journal représente un visiteur sans inférer automatiquement la session existante. La neutralisation doit couvrir les changements concernés et être limitée à l'opération, avec restauration même en cas d'exception. Définir séparément l'événement d'activation et la connexion automatique éventuellement journalisée : « une ligne par opération » ne doit pas faire disparaître une connexion exigée par la décision du 16/11.

## 3. Avis sur H1 à H4

| Hypothèse | Avis argumenté |
|---|---|
| **H1 — Oubli sur compte non activé : nouveau lien d'activation** | **Favorable, à confirmer.** Cohérent avec AUTH-02 et évite qu'une réinitialisation contourne l'activation. Réutiliser le service d'activation et son éligibilité ; garder le même 202 et les mêmes limites quel que soit le compte. Le renvoi invalide l'ancien lien selon le contrat prévu. |
| **H2 — Connexion automatique après activation, pas après reset** | **Favorable.** AUTH-02 prévoit l'accès à l'espace après choix du mot de passe. AUTH-03 n'impose pas une connexion automatique après réinitialisation ; le retour à la connexion est cohérent avec la fermeture des sessions. Il faut régler le cas B connecté/A cible du constat 4. |
| **H3 — Nouveau mot de passe différent de l'actuel** | **Option métier supplémentaire, à confirmer.** Le CDC ne l'exige pas et ne l'interdit pas. Cela évite un changement sans effet, mais ne constitue pas une obligation de sécurité issue des références. Vérifier sans conserver de secret en clair et ne pas étendre cette hypothèse à un historique de mots de passe hors périmètre. |
| **H4 — Lien accessible même connecté** | **Favorable pour consulter le lien, incomplète pour le consommer.** Elle évite la perte du parcours par redirection immédiate. Elle exige le choix explicite du comportement entre deux comptes, ainsi que le nettoyage d'identité et de cache ; ne pas la considérer entièrement validée avant cet arbitrage. |

## 4. Questions au responsable technique

1. Confirmer H1 et H3 ; pour H4, quel comportement retenir si B est connecté et que le lien vise A ? La conservation de B avec un succès public ou un changement de compte explicite doivent être distingués.
2. Un changement depuis le profil ou une réinitialisation administrative doit-il invalider les liens de réinitialisation encore en attente ? **Proposition : oui.** Le préciser dans le contrat partagé avec T-10/T-11/T-12, sans développer leurs écrans ici.
3. L'action « lien envoyé » signifie-t-elle demande mise en queue ou remise effective au service de messagerie ? Fixer le moment d'audit, la gestion des échecs et l'auteur à conserver, sans prétendre que la présence d'un job garantit la réception d'un e-mail.

## 5. Points validés et compléments de tests

### Points validés sans remise en cause

- Périmètre des rôles : activation par lien réservée aux comptes CME, Direction et administrateur ; pas d'activation ni d'oubli par e-mail pour l'entreprise. Changement depuis son profil pour les cinq rôles. Le mot de passe généré de l'apprenant ne bloque pas sa première connexion, conformément à la décision du 14/11.
- Création des comptes et réinitialisation administrative dans T-10 à T-12 ; T-05 fournit le service d'envoi à T-10. Aucun compte initial n'est ajouté ici. Le contrat de création devra appeler le service après attribution du rôle et rendre les comptes sans activation par lien utilisables selon leurs règles.
- SHA-256 d'un jeton aléatoire de 64 caractères, lien sans e-mail ni identifiant, séparation activation/reset, GET sans consommation et POST à usage unique : principes sains. Un `hash_equals` n'est pas indispensable si le digest est recherché directement par l'index SQL ; il faut l'utiliser si une comparaison de secrets/digests est effectuée en PHP. La sécurité ne doit pas reposer sur une comparaison ordinaire de chaînes secrètes.
- Durées 48 h et 60 min, réponse 410 commune aux liens inconnus, utilisés ou expirés, purge quotidienne et conservation de la table Laravel inutilisée : cohérents.
- Seuils d'oubli validés : respectés. La limite par IP peut toucher les utilisateurs du Wi-Fi partagé ; c'est un effet du choix validé, pas un motif pour changer le seuil dans cette revue. Les limites supplémentaires des POST de consommation et du changement sont justifiables contre l'abus ; préciser leur budget et des 429 indépendants de l'existence du compte.
- Liste locale de 10 000 entrées SecLists, sans appel externe à chaque validation, versionnée et chargée une fois par requête : conforme à l'arbitrage. Comparaison en minuscules après retrait des espaces périphériques pour la seule recherche dans la liste ; secret enregistré inchangé. Définir précisément le fichier/version, l'exclusion des lignes d'en-tête et le comportement si la ressource manque ; ne pas désactiver silencieusement la règle.
- Réutilisation des contrôles d'ouverture de session : bonne direction. Prévoir un composant commun plutôt qu'une simulation de POST `/login` avec un mot de passe inconnu. Il doit préserver contrôle du rôle/état, régénération, heure de connexion et journalisation, sans fermer les autres sessions lors d'une connexion entreprise normale.
- Suppression des autres sessions et renouvellement de `remember_token` après changement, toutes les sessions après reset : conformes. Le compte entreprise partagé reste partagé ; seule une modification de mot de passe déclenche ici la révocation.
- Maquettes : lien d'oubli repris ; modèle visuel de choix du mot de passe réutilisable sans l'obligation apprenant abandonnée ; deux saisies du nouveau mot de passe conformes au CDC malgré l'unique champ de la maquette entreprise. Profil minimal et contrôle à 375 px conformes à l'arbitrage.
- Journal : actions dédiées dans « Comptes », aucun secret dans les valeurs, aucun événement pour une adresse inconnue. Les précisions du constat 6 concernent l'intégration, pas le bien-fondé de cette liste.

### Tests à rendre explicites dans le plan

En plus des tests annoncés, inscrire les cas nécessaires aux constats :

- Expiration juste avant, exactement à et juste après 48 h/60 min ; mauvais type de jeton, jeton mal formé, double usage et deux consommations MySQL concurrentes.
- Deux émissions concurrentes, émission contre consommation, état/rôle du compte modifié entre GET et POST, mot de passe invalide ou échec d'audit sans perte du jeton.
- Cinq rôles, compte sans rôle, supprimé ou désactivé ; mêmes statuts/corps/limites des deux demandes publiques et traitement comparable avant envoi. Les deux champs de confirmation et H1 doivent être couverts.
- Réinitialisation avec deux clients, changement conservant uniquement la session courante, cookie ancien et requête en cours ; maintien de la limite de 12 h ; contexte de compte différent prévu par H4.
- Longueurs 9/10, maximum retenu et dépassement, Unicode, espaces conservés, mot courant avec casse/espaces périphériques ; ressource absente et non-chargement répété de la liste.
- Audit sans doublon ni secret, auteur public/admin/utilisateur et contexte worker ; jeton absent des payloads persistants non chiffrés et des caches partagés ; erreurs et succès frontend sous les gardes existantes.

Aucun de ces tests n'est écrit ou exécuté dans cette revue de plan. Les contrôles finaux prévus couvrent le pipeline et le navigateur ; la définition de « terminé » exige ensuite leurs résultats locaux, pas une approbation documentaire seule.

## 6. Verdict

**Plan à revoir.**

Les parcours et les six arbitrages validés sont bien repris. Avant développement, compléter les garanties de confidentialité et d'uniformité des demandes publiques, l'atomicité du cycle des jetons, le comportement avec une autre session connectée et la compatibilité de la longueur du mot de passe avec le hachage retenu. Ces corrections portent sur le plan et ses tests prévus, sans anticiper les fonctionnalités de T-10 à T-13.
