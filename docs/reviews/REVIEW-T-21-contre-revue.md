# Contre-revue du code T-21 — Consultation du CV par lien temporaire

Date : 27/11/2026 (Africa/Casablanca). Branche : `feature/T-21-consultation-cv`. Base : `develop`, `eab9845`. Modifications non commitées.

Contre-revue limitée aux quatre constats et à la suggestion du rapport `REVIEW-T-21-consultation-cv.md`, aux corrections annoncées dans `PROGRESS.md` et à leurs effets de bord. Application d'`AGENTS.md` et de `CLAUDE.md` ; décisions 1 à 10 du plan T-21 v1.2 conservées. Aucun vrai CV ni fichier de Téléchargements ouvert. Les essais navigateur utilisent exclusivement un PDF généré par `FakePdf::classic()` et une API fictive.

## Constats rejoués

| # | État | Résultat |
|---|---|---|
| 1 — Téléchargement après fermeture | **Corrigé pour le scénario signalé** | `alive`, `downloadAbort` et le contrôle après réception empêchent la création du blob/lien après démontage. Le signal arrive au `fetch` du fichier. Test de réponse retenue puis fermeture passé ; expiration pendant téléchargement et libération différée également testées. La demande de lien n'est pas annulée : précision distincte ci-dessous. |
| 2 — Plein écran non modal | **Corrigé** | Portail directement dans `body`, arrière-plan inerte, boucle Tab/Maj+Tab et Échap global. Tests ciblés passés. Dans Chrome réel : huit Tab restent dans le dialogue, Échap rend le focus au bouton ; restauration de l'inertie vérifiée aussi après démontage en plein écran. |
| 3 — Worker classé comme PDF illisible | **Partiel** | La classification est corrigée et la tâche échouée est détruite. Le test de rejet de `getDocument()` suivi d'un nouvel essai passe. En revanche, une véritable panne de chargement du worker laisse son contexte en échec : le bouton ne rétablit pas le rendu après retour de la ressource, contrairement au remplacement du mock dans le test. Voir N1. |
| 4 — `no_cv` sans relecture de l'état | **Corrigé** | `STALE` couvre `no_cv` et `outdated`, dans l'aperçu et le téléchargement. Le test « état présent puis retrait avant le lien » passe et vérifie deux lectures d'état au total, sans bandeau disponible persistant. Le rappel ne fait pas partie des dépendances qui relancent le chargement ; sa mise à jour ne crée pas de boucle. |
| 5 — Suggestion de tests | **Prise en compte** | Expiration puis succès sur téléchargement, révocation programmée, tabulation modale, worker, retrait avant lien ; permission retirée sans changer le rôle, lecteur désactivé/supprimé, chemin modifié, signature fausse sur lien expiré ; doublon/NULL et retour arrière sur lignes remplies pour la migration. Les tests ciblés passent. La simulation du worker reste insuffisante pour N1. |

Les trois premiers scénarios de réponse tardive/worker/retrait ont été rejoués par les tests de régression correspondants, complétés par la lecture des chemins corrigés. Le plein écran et la panne réelle du worker ont en plus été vérifiés dans un navigateur avec le composant et la bibliothèque réels.

## Effets de bord examinés

**Portail et `inert`.** Les enfants de `body` déjà présents et non inertes sont désactivés ; ceux qui étaient déjà inertes ne sont pas modifiés. Reproduction Chrome : un bouton « notification fictive » extérieur est rendu inerte pendant le plein écran puis réactivé à la sortie ; un élément déjà inerte le reste. Les mêmes états sont restaurés après démontage de la visionneuse en plein écran, avec disparition du portail. Aucun blocage persistant de l'application observé.

La liste des éléments traités est une photographie à l'ouverture (`CvViewer.tsx:183`). Un autre portail ajouté ultérieurement dans `body` n'est pas automatiquement rendu inerte, et une modification concurrente de l'inertie n'est pas coordonnée. Aucun autre système de portail applicatif concurrent n'a été identifié dans le périmètre actuel ; c'est une limite à conserver à l'esprit lors d'un ajout futur de notifications ou de modales, pas un nouveau bug de parcours démontré ici.

**Annulation d'une demande de lien déjà partie.** `loadCvBytes()` ne transmet pas son signal à `requestCvLink()`, qui utilise le client JSON sans option d'annulation. Le POST déjà engagé peut donc finir. Si le composant est fermé entre-temps, le signal déjà annulé empêche normalement la requête de fichier d'aboutir et `alive` empêche toute sauvegarde. Le défaut initial du téléchargement est donc corrigé, mais l'annulation réseau ne couvre pas toute la chaîne. Ajouter ultérieurement le signal au client et vérifier son état avant/après la demande du lien améliorerait ce nettoyage. Une annulation côté navigateur ne garantit de toute façon pas l'arrêt d'un traitement déjà reçu par le serveur. Aucun PDF tardif ni contournement de droits observé dans ce cas ; pas de constat bloquant supplémentaire.

**Erreurs de pdf.js 6.5.438.** `PasswordException`, `InvalidPDFException`, `UnknownErrorException` et `FormatError` sont bien des noms présents dans le paquet installé. `MissingPDFException` n'apparaît plus dans ce build : cette entrée supplémentaire est sans effet et ne justifie pas à elle seule une correction de comportement. Le rejet d'initialisation du worker reste une erreur générique et suit maintenant la branche réessayable attendue. La persistance de son contexte en échec est le problème distinct reproduit en N1.

**Relecture unique.** L'aperçu appelle la relecture après un refus périmé, puis termine sa tentative. Une nouvelle fonction `onOutdated` fournie par le parent est mémorisée sans provoquer un nouveau chargement. Le téléchargement appelle également le rappel une fois par échec périmé ; un nouvel appui utilisateur constitue une nouvelle tentative, pas une boucle automatique. Le cas de relecture d'état échouée ou inchangée ne fait pas l'objet d'une nouvelle reproduction réseau ici.

**Disque des tests.** `tests/TestCase.php` conserve `Storage::fake('local')` après l'initialisation de chaque test, avant les scénarios qui appellent le nettoyage. Les tests CV utilisent ce disque fictif. La suite ciblée n'ouvre aucun CV applicatif réel. L'affirmation « fichiers locaux intacts pendant la suite complète » reste un résultat communiqué par Claude ; aucun inventaire du dossier des CV applicatifs n'a été réalisé par cette contre-revue.

**Couverture de la suggestion 5.** Le nouveau test de révocation appelle le callback enregistré pour 60 secondes : il prouve que le nettoyage est programmé et révoque la bonne URL, sans prouver le téléchargement réel sur iOS. Les nouvelles contraintes de migration sont exercées sur des lignes existantes, puis le retour arrière et la nouvelle montée sont vérifiés. Le test du lecteur supprimé injecte ce modèle via `actingAs` et prouve son refus par `CvAccess` ; il ne faut pas en déduire que toute session HTTP réelle d'un utilisateur supprimé répond nécessairement 404 plutôt que 401.

## Nouveau constat

### N1 — Mineur, confirmé : « Réessayer » ne récupère pas une panne réelle de chargement du worker

**Fichiers/lignes :** `frontend/src/cv/CvViewer.tsx:115` (traitement de l'échec) et `frontend/src/cv/pdf.ts:13` (bibliothèque conservée) ; bouton de nouvelle tentative dans `CvViewer`.

**Scénario reproduit dans Chrome headless 155.0.8059.85 :**

1. Charger le composant réel avec pdf.js **6.5.438** et un CV fictif.
2. Autoriser l'import du module et celui de `pdf.worker.min.mjs?url`, puis provoquer une panne réseau sur le script réel `pdf.worker.min.mjs` et son import de repli `?import`.
3. La visionneuse affiche correctement « La visionneuse n'a pas pu se charger » et Réessayer.
4. Rétablir tous les accès au worker, puis cliquer sur Réessayer : le même message revient, sans nouvelle demande réseau du script du worker dans la trace collectée.
5. Recharger complètement la page, ressource accessible : « Page 1 sur 1 » apparaît et le rendu fonctionne.

**Cause :** détruire la tâche PDF ne remet pas à zéro le contexte de module pdf.js. Dans le paquet installé, le repli `PDFWorker._setupFakeWorkerGlobal` mémorise sa promesse, y compris son rejet, et le mode de worker désactivé reste porté par la classe. `loadPdfjs()` conserve de son côté le module déjà chargé. Une nouvelle tâche réutilise cet état en échec.

**Impact :** la correction du message est réelle, mais le bouton promis pour une panne transitoire du worker ne rétablit pas la consultation. Le téléchargement reste disponible ; aucun défaut d'autorisation, de journal ou de contenu n'est impliqué.

**Correctif minimal :** pour ce type d'échec d'initialisation, proposer explicitement de recharger la page, ce qui réinitialise le contexte et a fonctionné dans la reproduction ; ou mettre en place une réinitialisation du contexte de worker dont la récupération est vérifiée avec la bibliothèque réelle. Ne pas considérer le seul passage de `library` à `null` comme une preuve de récupération : un nouvel `import` du même module peut conserver son état. Ne pas modifier le code du paquet tiers pour masquer le problème.

**Test à compléter :** le test « constat 3 » remplace la bibliothèque simulée en échec par une nouvelle bibliothèque simulée saine. Il passe sans exercer la persistance du module/worker réel. Ajouter une recette « script worker inaccessible → rétabli → action de récupération → rendu », avec le même contexte initial.

**Rare / suites / report :** rare, lié au chargement défaillant du worker et de son repli ; fréquence de production non mesurée. Aucun impact sur les données, la version ou l'autorisation ; pas de blocage métier de T-22/T-24, mais la même limite de récupération toucherait la visionneuse réutilisée en T-24. Report possible dans `docs/ERREURS_A_CORRIGER.md` si explicitement accepté ; la correction d'interface proposée est petite. Aucun report écrit pendant cet audit.

## Validation et limites

| Contrôle exécuté localement | Résultat |
|---|---|
| `docker compose run --rm php php artisan test tests/Feature/Cv` | **58 tests réussis, 366 assertions**, durée annoncée **238,80 s**. `docker ps` vérifié avant lancement ; aucune autre suite backend visible ; une seule suite backend lancée. |
| `npm.cmd run test -- src/cv` dans `frontend` | **22 tests réussis**, deux fichiers, code 0, **6,53 s**. |
| Chrome headless réel, API fictive et composant du dépôt | Huit tabulations dans le plein écran, Échap et retour du focus, inertie d'une notification déjà présente, préservation d'une inertie préalable, restauration après démontage : vérifiés. Panne puis retour du worker : N1 reproduit ; rechargement complet récupérant le rendu vérifié. |
| `git diff --check` | Aucune erreur d'espacement ; avertissements LF/CRLF sur certains fichiers. |

Le serveur et le navigateur temporaires ont été arrêtés. Le harnais se trouve hors du dépôt, dans `%TEMP%/jobdating-t21-code-review` ; le navigateur utilisait un profil temporaire distinct. Les erreurs réseau du worker sont injectées par interception de requêtes dans ce navigateur, sans changer de fichier applicatif ni de dépendance.

**Contrôles de Claude communiqués dans `PROGRESS.md`, non rejoués ici :** Pint et PHPStan sans erreur ; frontend lint, **144 tests** et build réussis ; suite backend complète lancée seule : **613 tests réussis, 3288 assertions**, code 0, environ 15 minutes ; recette du plein écran dans le navigateur intégré. La réserve de la précédente revue sur l'absence de suite complète verte est donc levée au vu de ce résultat communiqué.

La contre-revue navigateur utilise Vite en développement et une API fictive, sans session réelle. Elle ne valide pas le build de production, Safari/iOS/Android, l'enregistrement effectif du téléchargement ni le lecteur d'écran. Aucune nouvelle mesure mémoire HTTP, suite complète ou analyse statique exécutée pendant cette contre-revue. Aucun défaut majeur nouveau confirmé dans le périmètre limité.

## Verdict

**À fusionner après corrections mineures.**

Constats 1, 2 et 4 corrigés ; suggestion 5 prise en compte. Le constat 3 reste partiel uniquement sur la récupération réelle du worker, avec N1 confirmé. Les tests locaux ciblés passent et la suite complète verte est désormais communiquée. Corriger ou accepter explicitement le report de N1 avant fusion.

Seul livrable ajouté : ce rapport. Aucun fichier de code modifié, aucun commit ni push.
