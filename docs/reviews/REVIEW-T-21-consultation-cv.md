# Revue du code T-21 — Consultation du CV par lien temporaire

Date : 27/11/2026 (Africa/Casablanca). Branche : `feature/T-21-consultation-cv`. Base et HEAD : `eab9845` (`develop`). Implémentation non commitée.

## Périmètre

Revue de `git diff develop` et des nouveaux fichiers non suivis, selon `AGENTS.md`, `CLAUDE.md`, le prompt de revue, le plan T-21 **v1.2** et les exigences CV/APP-03/UX-09 du CDC. Décisions 1 à 10 conservées. Contrôleurs, autorisation, signature, version/migration, journal, routes, isolation du disque dans les tests, visionneuse, intégrations, navigation, client binaire, CSS et tests examinés. Les maquettes demandées ont déjà été examinées pendant la revue du plan ; les décisions ultérieures pdf.js et plein écran interne priment sur l'ancien lecteur natif.

Aucun vrai CV ni fichier de Téléchargements ouvert. Les reproductions utilisent les réponses fictives des tests et un PDF généré par `FakePdf::classic()`. Les harnais supplémentaires restent dans `%TEMP%/jobdating-t21-code-review`, hors du dépôt.

## Constats

### 1. Mineur — Un téléchargement se déclenche encore après la fermeture de la visionneuse

**Fichier/ligne :** `frontend/src/cv/CvViewer.tsx:167` ; gestion de l'URL à `:175`.

**Bug confirmé par reproduction isolée :** demander un téléchargement, retenir la réponse du fichier, démonter la visionneuse, puis livrer la réponse. Le code crée encore le blob et déclenche le lien `CV-Yasmine-Fictive.pdf`. Le chargement de l'aperçu dispose d'un indicateur d'abandon ; le téléchargement n'en a pas. Les URL et timers de téléchargement ne sont pas non plus nettoyés au démontage, contrairement au plan qui prévoit leur libération à la fermeture.

**Impact :** une sauvegarde retardée se déclenche après fermeture, changement d'apprenant ou sortie de l'écran. Le composant continue aussi ses mises à jour d'état et conserve la ressource jusqu'au délai. Le fichier avait été demandé par l'utilisateur ; aucune lecture serveur non autorisée ni fuite à un autre compte n'a été démontrée.

**Correctif minimal :** associer le téléchargement à la durée de vie et à l'identité de la visionneuse ; annuler la requête si possible et, dans tous les cas, ignorer sa résolution après abandon avant de créer le blob. Suivre les URL/timers pour les nettoyer. Conserver la protection contre le double clic et le délai nécessaire à un téléchargement effectivement déclenché.

**Test manquant :** le test actuel du téléchargement ne ferme pas la visionneuse pendant la requête. Ajouter fermeture/changement de version avant la réponse, sans création de lien ni téléchargement ensuite.

**Rare / report :** demande un réseau lent ou une fermeture rapide ; fréquence non mesurée. Même défaut dans les futures intégrations T-24. À corriger maintenant ; pas de report recommandé comme défaut sans impact.

### 2. Mineur — Le focus sort du plein écran, puis Échap ne le ferme plus

**Fichier/ligne :** `frontend/src/cv/CvViewer.tsx:264`, `:267`.

**Bug confirmé dans Chrome headless 155.0.8059.85**, avec le composant réel, pdf.js 6.5.438 et un PDF fictif : ouvrir le plein écran. Le focus arrive bien sur « Quitter le plein écran ». Appuyer deux fois sur Tab : il passe sur « Télécharger », puis sur un bouton placé après la visionneuse dans la page sous-jacente. Appuyer sur Échap à cet endroit : le dialogue reste ouvert, car son gestionnaire ne reçoit plus l'événement.

**Impact :** le dialogue annoncé `aria-modal="true"` ne se comporte pas comme une fenêtre modale. Un utilisateur au clavier peut atteindre des contrôles masqués derrière lui, et la sortie par Échap devient inopérante. Les [pratiques WAI-ARIA pour les dialogues modaux](https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/) demandent de conserver la tabulation dans le dialogue et de rendre son arrière-plan inactif.

**Correctif minimal :** utiliser un dialogue modal natif adapté ou gérer la boucle Tab/Maj+Tab et l'inertie de l'arrière-plan ; conserver le focus initial et son retour. Tester Échap après plusieurs tabulations, pas seulement un événement envoyé directement au conteneur.

**Test insuffisant :** `frontend/src/cv/viewer.test.tsx:127` vérifie le focus initial/final puis envoie Échap au dialogue. Il reste vert malgré cette sortie du focus.

**Rare / report :** reproductible à chaque plein écran utilisé au clavier. Affecte UX-09 et T-24 ; correction à conserver dans T-21.

### 3. Mineur — Une panne du worker est présentée comme un document illisible, sans Réessayer

**Fichier/ligne :** `frontend/src/cv/CvViewer.tsx:96`.

**Bug confirmé par reproduction isolée :** laisser `loadPdfjs()` réussir, puis faire rejeter la promesse de `getDocument()` avec une erreur d'initialisation du worker. La visionneuse affiche « Ce CV ne peut pas être affiché ici. Téléchargez-le. », sans bouton Réessayer.

Ce chemin est réel dans la dépendance installée : `pdfjs-dist/build/pdf.mjs` rejette l'initialisation avec `Setting up fake worker failed` lorsque le worker et son repli ne peuvent pas être chargés. L'import `?url` fournit une URL ; sa résolution ne prouve pas que le script du worker a été chargé. Le `catch` actuel assimile toute erreur de `task.promise` à un PDF illisible, alors que le plan distingue la panne du worker, réessayable.

**Impact :** une panne transitoire d'infrastructure ne peut pas être retentée depuis la visionneuse ; le CV est présenté à tort comme non affichable. Le téléchargement reste disponible.

**Correctif minimal :** distinguer l'initialisation du worker des erreurs de document/rendu et proposer l'état `viewer` avec Réessayer dans le premier cas, en détruisant les tâches devenues inutiles.

**Test insuffisant :** `frontend/src/cv/viewer.test.tsx:198` simule la panne « worker » en rejetant `loadPdfjs()`. Il ne couvre pas le rejet ultérieur de `getDocument()` après import réussi. Le harnais de revue reproduit ce deuxième cas.

**Rare / report :** panne de worker probablement rare, fréquence non mesurée ; sans corruption ni défaut de droits. Report possible dans `docs/ERREURS_A_CORRIGER.md` si accepté, mais la branche sera réutilisée en T-24. Aucun report écrit ici.

### 4. Mineur — Un CV retiré avant la création du lien laisse le bandeau « disponible » affiché

**Fichier/ligne :** `frontend/src/cv/CvViewer.tsx:77` ; traduction du refus dans `frontend/src/cv/pdf.ts:96`.

**Bug confirmé par reproduction isolée :** `GET /me/cv` renvoie un CV existant ; il est retiré avant le `POST /me/cv/link`, qui renvoie 404 `no_cv`. La visionneuse affiche « CV non disponible. », mais le bandeau vert « CV disponible pour les entreprises · ajouté le 26/11/2026 » reste présent. Une seule lecture de `/me/cv` a lieu.

**Cause et impact :** le rappel de relecture est déclenché uniquement pour `outdated`. Or l'émission du lien détecte un retrait avec `no_cv`, avant même d'avoir une URL obsolète à servir. L'écran APP-03 annonce alors simultanément présence et absence du CV. Le serveur refuse correctement le fichier.

**Correctif minimal :** demander aussi la relecture de l'état sur `no_cv`, et appliquer la même logique si une action de téléchargement détecte retrait/version obsolète. Éviter les boucles de relecture sur une erreur persistante. Ajouter le cas « état présent, puis aucun CV à l'émission » au test de l'écran personnel.

**Rare / report :** course avec un retrait par l'équipe pendant la consultation, donc rare ; aucune fuite. Le mécanisme est partagé avec les autres écrans et T-24. Report possible si explicitement accepté ; correction locale préférable.

### 5. Suggestion — Renforcer les preuves de nettoyage et de changement de droits

**Fichiers :** `frontend/src/cv/viewer.test.tsx:241`, `backend/tests/Feature/Cv/CvConsultationTest.php`, `CvVersionMigrationTest.php`.

Le test de téléchargement vérifie que l'URL n'est pas révoquée immédiatement, mais ne fait pas avancer le délai et ne prouve jamais sa révocation. Il resterait vert en supprimant le `setTimeout`. Les mocks de destruction et d'annulation ne sont pas vérifiés non plus.

Compléter les scénarios utiles : expiration suivie d'un succès sur téléchargement, 401 et refus du fichier, droits retirés sans changement de rôle, lecteur désactivé/supprimé après émission, chemin `{cv}` modifié, signature invalide **et** date expirée. Ces derniers cas sont annoncés dans le plan mais ne sont pas tous exercés par les onze méthodes de consultation.

Pour la migration, le test prouve le remplissage de trois lignes et la conservation des chemins/historique ; il ne tente pas un doublon ou `NULL` et ne vérifie pas un retour arrière sur ces lignes remplies. Ajouter des assertions de contrainte et de retour arrière protège mieux les invariants. L'inspection du code de migration ne révèle pas de défaut de schéma confirmé.

**Rare / report :** lacunes de preuve, pas bugs supplémentaires établis. Peuvent être complétées avec les corrections ci-dessus ; aucune incidence de production démontrée en elles-mêmes.

## Points vérifiés sans remarque

- `CvAccess` contrôle rôle, permission et état du lecteur ; CME/Direction n'accèdent pas aux apprenants supprimés, l'administrateur conserve cet accès ; apprenant limité à son CV, entreprise refusée. Les routes restent sous session authentifiée et valide. Aucun contournement confirmé dans le périmètre examiné.
- Signature relative cohérente avec le chemin `/api/v1` derrière Vite ; intégrité vérifiée avant expiration ; `download` seul exclu, valeur `1` uniquement ; compte comparé et UUID de version contrôlé avant lecture.
- Version produite après le cas « inchangé » et renouvelée à chaque ajout/remplacement effectif. L'état administratif et personnel la transmet. Test A → B → A et retrait passé localement.
- Migration en trois étapes : nullable, UUID par ligne, puis `NOT NULL` et index unique ; méthode de retour arrière présente. Test local des lignes préexistantes passé. Déploiement sans écritures de l'ancien code toujours requis par le plan.
- Fichier servi en PDF, pièce jointe, nom ASCII neutralisé, CSP `sandbox`, `nosniff`, cache privé sans stockage. Absence ou déchiffrement impossible traités en 503. Journal écrit après lecture réussie et avant envoi ; une panne du journal empêche l'envoi. Pas de contenu, chemin, empreinte ou URL signée dans les valeurs enregistrées par ce contrôleur.
- pdf.js **6.5.438** figé, import dynamique et worker local de même version. Aucun gestionnaire de scripts, d'actions ou de formulaires branché ; XFA désactivé. L'option `isEvalSupported` n'est plus présente dans l'API installée : son absence de l'appel n'établit pas un écart de sécurité. Le rendu des annotations dans le canvas ne branche pas une couche interactive.
- Aperçu : réponses tardives ignorées, tâche de document détruite au nettoyage, rendu et texte annulés ; une page rendue à la fois, zoom limité. Clés apprenant/version présentes dans les intégrations. Réserve distincte pour le téléchargement, constat 1.
- Couche de texte réelle observée dans Chrome avec le PDF textuel fictif. Boutons page/zoom libellés, focus d'entrée et de retour du plein écran présents. Réserve sur la tabulation modale, constat 2 ; aucune certification globale d'accessibilité annoncée.
- « Mon CV » affiche source/date/état sans téléversement ; Direction en lecture seule. Les écrans n'ajoutent pas les accès entreprise reportés à T-24.
- L'isolation du disque `local` dans `tests/TestCase.php` évite que `imports:cleanup` opère sur le disque applicatif lors des tests. Les nouveaux tests CV utilisent le stockage fictif. Aucun fichier applicatif réel n'a été ouvert pour cette revue.

## Validation exécutée et limites

| Contrôle exécuté localement | Résultat |
|---|---|
| `docker compose run --rm php php artisan test tests/Feature/Cv` | **56 réussis, 344 assertions**, code 0, 233,91 s. `docker ps` vérifié avant lancement ; aucune autre suite backend visible. Une seule suite backend lancée. |
| `npm.cmd run test -- src/cv` dans `frontend` | **17 tests réussis**, 2 fichiers, code 0, 5,47 s. |
| Harnais Vitest supplémentaire hors dépôt, avec le Vitest 5.1.0 du projet | **3 reproductions confirmées** : téléchargement après démontage, état disponible après `no_cv`, panne du worker sans nouvel essai. Ces tests diagnostiques attendent les comportements défectueux observés ; leur succès n'est pas une validation des corrections. |
| Harnais navigateur temporaire, composant réel et `FakePdf::classic()` | Chrome headless **155.0.8059.85** : une page rendue, texte fictif présent dans la couche de texte, worker observé ; sortie du focus après deux Tab et Échap alors inopérant confirmés. |
| `git diff --check` | Code 0, aucune erreur d'espacement ; avertissements de conversion LF/CRLF. |

Les scripts de reproduction et l'application temporaire sont hors du dépôt. Un premier lancement du harnais via `npm exec` a échoué avant toute assertion, car il sélectionnait un Vitest de cache sans son environnement jsdom ; il a été repris avec le binaire installé du projet. Les trois résultats retenus proviennent de ce second lancement. Les tâtonnements de démarrage du serveur temporaire n'ont pas modifié le projet. Le serveur et le navigateur temporaires ont été arrêtés.

La recette Chrome utilise le composant réel, mais une API fictive et Vite en développement ; elle ne prouve pas une session réelle, le build de production, l'enregistrement d'un téléchargement, Safari/iOS/Android ou le comportement d'un lecteur d'écran. Aucun contrôle mémoire HTTP PHP 8.4 exécuté. Aucun test de nouvelle coupure réseau ni de concurrence lecture/remplacement pendant le service du fichier ; aucun bug de ces types n'est affirmé.

**Résultats de Claude communiqués dans `PROGRESS.md`, non rejoués intégralement :** Pint/PHPStan sans erreur ; frontend lint, **139 tests** et build réussis ; recette locale pdf.js/texte/plein écran/Échap. La dernière suite backend complète communiquée est **610 réussis et 1 échec**, dû au test T-20 créant un CV sans version ; ce test a été corrigé, puis le dossier CV est repassé à **56 réussis, 344 assertions**, résultat corroboré ici. Il n'y a pas de résultat de suite backend complète entièrement verte après cette correction dans l'entrée examinée. Une suite complète verte reste à communiquer avant fusion, notamment après la modification globale de `TestCase`.

## Verdict

**À fusionner après corrections mineures.**

Aucun défaut majeur d'autorisation ou de confidentialité serveur confirmé. Corriger les quatre enchaînements frontend documentés, compléter leurs tests et communiquer la régression backend complète après les corrections. La revue n'est pas une approbation inconditionnelle de l'état actuel.

Seul livrable ajouté : ce rapport. Aucun fichier de code modifié, aucun commit ni push, aucune écriture dans `ERREURS_A_CORRIGER.md`.
