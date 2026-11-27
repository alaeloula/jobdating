# Contre-revue du plan T-21 — Consultation du CV par lien temporaire

Date : 27/11/2026 (Africa/Casablanca). Branche : `feature/T-21-consultation-cv`. Base `develop` : `eab9845a4c506370550bef47cc5b93891ba42181`.

Plan examiné : **v1.1**, non commité, avant implémentation. Contre-revue limitée au prompt demandé, aux quatre constats précédents, à leurs précisions et aux conséquences de pdf.js. Les décisions **1 à 10 sont acquises**, notamment pdf.js, la version UUID et la consultation des apprenants désactivés par les trois rôles concernés.

## Périmètre et validation

Lus : `AGENTS.md`, `CLAUDE.md`, plan v1.1, `CURRENT_TASK.md` et son diff avec la base, précédent rapport de plan ; extraits pertinents du CDC, notamment UX-09 (§ 6.3), et du code T-20 : modèle de stockage/version à compléter, réponses d'état CV, types frontend, invalidation après mutation, reconnaissance structurelle des PDF. Configuration Vite/Vitest et dépendances frontend également vérifiées.

Les constats ci-dessous sont des omissions ou contradictions du **plan**. Aucun bug d'une implémentation T-21 inexistante n'est présenté comme reproduit. Aucun vrai CV ni fichier de Téléchargements ouvert.

## Constats précédents rejoués

| Constat initial | État | Résultat de la contre-revue |
|---|---|---|
| 1 — Isolement du document | **Corrigé au niveau du plan** | Rendu des octets par pdf.js dans le site, y compris le plein écran ; aucun lecteur natif ni onglet blob. Réponse PDF toujours en `attachment`, avec `sandbox`, `nosniff` et `no-store`. Les protections HTTP ne sont plus présentées comme transférées à un blob affiché. L'absence de scripts et de formulaires est explicitée. La validation réelle dépendra du code et de la version retenue. |
| 2 — Date de « Mon CV » sans source | **Corrigé** | `GET /me/cv` fournit source et date, ou `null`, sans fiche administrative. Tests de présence, d'absence et de date prévus. |
| 3 — Ancien lien réactivé après A → B → A | **Corrigé** | UUID propre à chaque ajout/remplacement effectif ; version conservée pour un fichier inchangé. Test A → B → A prévu. La migration des lignes existantes est annoncée ; sa séquence doit être sécurisée, voir ci-dessous. |
| 4 — Cycle de vie et remplacement pour le même apprenant | **Partiel** | Annulation, destruction du document pdf.js, réponses tardives ignorées et plein écran interne sont bien définis. L'identité apprenant + version est annoncée, mais le contrat qui fournit cette version au composant reste absent : N2. La révocation du lien de téléchargement est prévue trop tôt : N3. |

## Précisions de la première revue

| Point | Appréciation |
|---|---|
| Signature et `download` | Repris : `download` seul exclu de la validation, valeur `1` uniquement ; paramètres sensibles protégés et tests de modification prévus. Dans le code, transmettre des paramètres nommés avec leurs valeurs à `temporarySignedRoute`, la liste du plan étant une notation abrégée. |
| Expiration et client binaire | Repris : 410 `link_expired`, renouvellement unique, seconde expiration sans boucle ; chargement hors du client JSON ; 401 et refus distincts. Tester aussi le renouvellement déclenché par un téléchargement et le refus après renouvellement. |
| Droits relus au service | Repris : `CvAccess` commun et matrice explicite. Tests de rôle/permission perdus, lecteur désactivé/supprimé et cible supprimée ; entreprise refusée malgré `cv.view`. Appliquer aussi les contrôles appropriés à `GET /me/cv`. |
| Journal | Repris : une ligne par service autorisé et préparé ; aucune sur refus ou lecture impossible ; panne du journal empêchant le service ; limite des copies locales explicitée. Un échec ultérieur du rendu pdf.js n'annule pas rétroactivement le service HTTP journalisé. |
| Mémoire | Estimation CLI reprise avec sa limite ; mesure HTTP PHP 8.4 planifiée et configuration serveur restant à confirmer. Ajouter les surfaces canvas à la mesure navigateur : les octets du PDF ne représentent pas toute sa mémoire. |
| Navigateurs | Recette multipage desktop/mobile prévue. Elle doit porter sur le build de production, le téléchargement réellement enregistré sur iOS, les erreurs du worker et l'accessibilité, au-delà d'un test avec pdf.js simulé. |

Pour distinguer 410 et signature invalide sans ambiguïté, valider l'intégrité de la signature indépendamment de sa date, puis l'expiration avec l'horloge serveur. Une signature invalide ne doit pas être transformée en expiration renouvelable. Les 401 doivent emprunter le mécanisme de fin de session existant sans transformer chaque erreur locale de rendu en perte de session.

## Effets de bord de pdf.js

**Contenu actif.** Le circuit bibliothèque `getDocument({data})` puis rendu, sans couche de scripts/formulaires, est cohérent avec la décision 8. `isEvalSupported: false` concerne les évaluations internes de la bibliothèque, pas à lui seul l'activation du JavaScript du document. Ne pas brancher de `PDFScriptingManager`, de traitement d'actions PDF ou de formulaires interactifs ; si un composant viewer est utilisé pour le texte ou l'accessibilité, conserver explicitement ces fonctions désactivées. Vérifier les options réellement reconnues par la version figée : la documentation courante n'est pas un contrat pour une version encore non choisie. L'[exemple officiel Mozilla](https://github.com/mozilla/pdf.js/blob/master/examples/components/singlepageviewer.mjs) distingue notamment l'ajout du gestionnaire de scripts du simple chargement/rendu.

**Worker, Vite et Vitest.** Chargement à la demande et essai préalable sont bien prévus. Garder bibliothèque et worker dans la même version et servis localement, sans CDN ; importer les ressources du worker selon la convention Vite plutôt qu'une URL de développement codée en dur. Identifier aussi les ressources nécessaires à la version retenue (polices standard, CMaps, éventuels modules de décodage) et leurs chemins dans le build. Les [exemples Mozilla](https://mozilla.github.io/pdf.js/examples/) et la [documentation Vite sur les ressources](https://vite.dev/guide/assets.html#importing-asset-as-url) justifient un essai réel du build. Vitest peut simuler chargement/document/rendu et leurs erreurs ; cette simulation ne prouve pas le fonctionnement du worker dans un navigateur. Vérifier qu'aucun import statique d'intégration ne ramène pdf.js dans le chargement initial.

**Ressources et fermeture.** Annuler les tâches de rendu en cours, détruire le chargement ou le document selon l'étape atteinte, et libérer les surfaces canvas devenues inutiles. Le rendu séquentiel limite la concurrence mais pas l'accumulation de toutes les pages déjà rendues. Borner les dimensions de rendu et le zoom, avec pagination ou recyclage des surfaces si nécessaire ; ne pas ajouter une nouvelle limite métier arbitraire sur les CV sans décision. Les données typées remises à pdf.js peuvent être transférées au worker : ne pas compter sur leur réutilisation ensuite pour télécharger. Le nouveau `fetch` de téléchargement prévu évite ce problème. Voir l'[API Mozilla, paramètre `data`](https://mozilla.github.io/pdf.js/api/draft/module-pdfjsLib.html).

**Migration de version.** L'objectif « UUID unique, rempli pour l'existant » est correct. Expliciter une séquence compatible avec plusieurs CV existants : ajout nullable, remplissage avec un UUID différent par ligne, puis contrainte finale `NOT NULL` et unicité, sans valeur par défaut commune. Empêcher des écritures concurrentes de l'ancien code pendant cette migration, par exemple par le mode de déploiement retenu. Prévoir un test avec au moins deux lignes préexistantes : contenu, chemin et historique conservés, versions non nulles et distinctes, puis envois/remplacements toujours fonctionnels. Une collision UUID n'est pas un incident observé ; la contrainte d'unicité protège cet invariant. Aucun changement des tables d'historique n'est nécessaire pour cette migration.

## Nouveaux constats

### N1 — Majeur : le rendu uniquement en canvas ne couvre pas l'accessibilité demandée par le CDC

**Localisation :** `docs/tasks/PLAN-T-21.md:72`, § 4 ; tests § 5. Référence : CDC § 6.3, UX-09, navigation clavier et cible WCAG 2.1 AA.

**Omission confirmée du plan :** seules les pages dessinées en canvas sont prévues. Aucune restitution du texte, commande de zoom, navigation accessible entre pages ou gestion du focus en plein écran n'est définie. Un nom accessible « Aperçu du CV » ne donne pas accès au contenu textuel de ces images.

**Scénario et impact :** un utilisateur de lecteur d'écran ouvre un CV fictif contenant du texte sélectionnable, ou un utilisateur sur mobile doit agrandir une page. Le circuit décrit ne propose que le bitmap ; il perd l'accès au texte et les commandes auparavant déléguées au lecteur natif. Il s'agit d'une conséquence non traitée du choix pdf.js, pas d'une remise en cause de ce choix. Aucune recette de lecteur d'écran n'a été exécutée ici.

**Correction minimale :** prévoir une couche de texte ou une alternative textuelle accessible issue de pdf.js pour les PDF qui en contiennent, sans scripts ni formulaires ; commandes de zoom/page accessibles au clavier, libellés, focus et sortie du plein écran. Ajouter un test sur un PDF fictif textuel et une recette clavier/lecteur d'écran. Un PDF scanné n'acquiert pas de texte par cette méthode : signaler la limite, sans ajouter un projet d'OCR à T-21. Une couche de texte seule ne garantit pas non plus un ordre de lecture correct : le vérifier.

**Rare / report :** conséquence systématique du rendu canvas seul, touchant particulièrement les utilisateurs de technologies d'assistance. Affecte T-21 et la future réutilisation T-24 ; ne pas reporter comme anomalie sans impact.

### N2 — Mineur : l'identité « apprenant + version » n'a pas de contrat de données complet

**Localisation :** `docs/tasks/PLAN-T-21.md:54` et `:74`, § 3/4. Références : `CvController::state()` et type frontend `CvState` existants.

**Omission confirmée :** `GET /me/cv` renvoie seulement `{source, added_at}` ; l'état CV de la liste/fiche et la réponse d'envoi de T-20 ne contiennent pas non plus de version. Les réponses de création de lien restent `{url, expires_at}`. L'UUID est certes présent dans l'URL signée, mais le plan ne dit pas si le frontend doit l'en extraire ni comment il repère une nouvelle version avant de recharger cette URL.

**Scénario et impact :** après remplacement pour le même apprenant, la liste est invalidée comme en T-20, mais l'objet d'état ne fournit toujours aucun UUID utilisable pour comparer l'identité de chargement. L'implémenteur doit inventer un signal ou analyser l'URL ; la correction du constat initial 4 reste incomplète au niveau du contrat. Aucun aperçu périmé n'est prétendu reproduit dans T-21.

**Correction minimale :** exposer l'UUID de version dans les états CV utiles et dans le type `CvState`, ainsi que dans l'état personnel, ou définir explicitement un signal de renouvellement équivalent. Définir aussi le passage de l'identité personnelle au composant (`me` stable ou identifiant autorisé), puis tester le remplacement sans changement d'apprenant, le retrait et une réponse tardive de l'ancienne version. L'UUID n'est pas un droit d'accès et ne dispense pas de signer ni d'autoriser.

**Rare / report :** enchaînement normal des écrans de gestion ; impact possible sur T-24. À préciser avant implémentation, sans report recommandé.

### N3 — Mineur : révoquer « aussitôt » l'URL de téléchargement crée une course avec le navigateur

**Localisation :** `docs/tasks/PLAN-T-21.md:74`, § 4.

**Risque de compatibilité, non reproduit sur navigateur :** la durée de vie prévue de l'URL blob se termine immédiatement après le déclenchement du lien `download`. Cela ne prouve pas que le navigateur a déjà acquis la ressource. La spécification File API impose qu'une référence révoquée échoue lors d'une résolution ultérieure ; l'algorithme HTML du téléchargement comporte des étapes asynchrones. Voir [File API, révocation](https://www.w3.org/TR/FileAPI/#dfn-revokeObjectURL) et [HTML, téléchargement](https://html.spec.whatwg.org/multipage/links.html#downloading-resources).

**Scénario et impact :** après le `fetch`, le code déclenche le lien puis appelle immédiatement `revokeObjectURL`. Un navigateur qui résout l'URL ensuite peut échouer à enregistrer le CV alors que le service serveur a déjà été journalisé. Un test Vitest qui vérifie seulement l'appel du lien ne détecte pas ce cas. Aucun échec spécifique de Safari/iOS n'est affirmé comme constaté.

**Correction minimale :** remplacer « aussitôt » par un cycle de vie qui laisse au navigateur le temps de prendre en charge la ressource, avec révocation différée et nettoyage borné, validé sur les navigateurs cibles. Renseigner explicitement le nom dans l'attribut `download`, puisque le blob ne reprend pas automatiquement le `Content-Disposition` HTTP. Vérifier sur iOS le fichier réellement enregistré et son nom après le `fetch` asynchrone ; prévoir un échec visible et réessayable, sans ouvrir le PDF dans un lecteur natif du site.

**Rare / report :** fréquence dépendante du navigateur, non établie ; impact direct sur le téléchargement mobile et T-24. À lever pendant l'essai initial ou la recette T-21, sans report comme anomalie sans impact.

### N4 — Mineur : les erreurs de chargement/rendu pdf.js ne sont pas traitées

**Localisation :** `docs/tasks/PLAN-T-21.md:73` et `:92`, § 4/5.

**Omission confirmée :** le plan définit les erreurs HTTP/réseau, mais pas l'échec de l'import dynamique, du worker, de `getDocument()` ou du rendu d'une page. Ces étapes peuvent échouer après une réponse HTTP 200 ; le contrôle structurel de T-20 n'est volontairement pas une preuve de lisibilité complète par pdf.js.

**Scénario et impact :** le serveur fournit un PDF dont pdf.js demande un mot de passe, ou le chargement du worker échoue. Sans état prévu, la visionneuse risque de rester vide ou en chargement. Ce résultat d'interface est hypothétique en l'absence de code ; le chemin d'erreur manquant est établi.

**Correction minimale :** prévoir un état français d'échec du rendu, vider l'aperçu partiel, nettoyer les tâches et distinguer une panne transitoire réessayable d'un document non affichable. Ne pas simuler une erreur serveur 503 ni annuler le journal d'un service HTTP réussi. Tester avec pdf.js simulé les rejets de chargement et de rendu, et vérifier une panne de worker dans l'essai réel. Aucun formulaire de mot de passe PDF n'est requis par cette correction.

**Rare / report :** probablement rare pour les CV ordinaires ; aucune fréquence mesurée. Le cas sera partagé avec T-24. Correction petite et à inclure dans T-21 ; report possible seulement si une gestion générique couvre déjà ces exceptions dans l'implémentation finale.

## Tests, limites et verdict

Exécutés localement pendant cette contre-revue : vérification de branche/base et du statut Git, examen des contrats et références, `git diff --check` sans erreur d'espacement (avertissement LF/CRLF sur `CURRENT_TASK.md`). Documentation primaire Mozilla, Vite, HTML et File API consultée pour les nouveaux effets de bord. Aucun paquet installé et aucun fichier de code modifié.

Aucun test T-21, build avec pdf.js, essai de worker, test de migration ni recette Safari/iOS/lecteur d'écran n'a été exécuté : le code et la dépendance ne sont pas encore présents. Les résultats locaux de base **communiqués dans `PROGRESS.md`, non rejoués ici**, restent : 44 tests CV et 599 tests backend réussis, 3144 assertions ; Pint/PHPStan sans erreur ; frontend lint, 129 tests et build réussis. Ils ne valident pas les nouveaux circuits T-21.

**Verdict : Plan à revoir.**

Les corrections de sécurité, de métadonnées et de version répondent aux premiers constats au niveau du plan. Compléter l'accessibilité du rendu pdf.js (N1), puis le contrat de version et les cas de téléchargement/rendu (N2 à N4). Les décisions 1 à 10 restent inchangées. Seul livrable ajouté : ce rapport ; aucun commit ni push, aucun report écrit dans `ERREURS_A_CORRIGER.md`.
