# Contre-revue du code T-20 — Téléversement, remplacement et retrait du CV

- **Date :** 27/11/2026.
- **Branche :** `feature/T-20-cv`, base `develop` — `2f8a9d8`, changements non commités.
- **Références :** `AGENTS.md`, `CLAUDE.md`, prompt de contre-revue, `REVIEW-T-20-cv.md`, plan T-20 v1.2 et décisions 1 à 11, décision du 26/11 sur le retrait, paragraphe des corrections dans `PROGRESS.md` ; CDC CV-01/CV-04 et règles de visibilité déjà examinées.

## Périmètre

Contre-revue limitée aux quatre constats, aux corrections de `PdfInspector`, `CvStorage`, `CvService`, `CvController`, du panneau CV et de leurs tests, ainsi qu'aux limites de validation signalées. Diff avec `develop` et fichiers T-20 non suivis examinés ; exclusions du prompt respectées. Aucun vrai CV ni fichier de Téléchargements ouvert.

Le contrôle PDF simple sans dépendance et la limite sur les contenus actifs cachés dans des flux compressés restent acceptés. Les nouveaux défauts ci-dessous concernent leur mise en œuvre, sans demander une garantie antivirus.

Seul ce rapport est ajouté au dépôt. Aucun code modifié, commit ou push ; bancs supplémentaires conservés hors du dépôt et exécutés sur des données fictives.

## Rejeu des quatre constats

| Constat initial | Statut | Résultat et preuve |
|---|---|---|
| 1. Image contenant `endstream (` qui masque une action JavaScript | **Partiellement corrigé** | Le scénario initial est corrigé : l'image piégée et l'image ordinaire suivies de JavaScript sont refusées ; les tests de motif et d'API passent. Le refus des contenus actifs reste néanmoins contournable par la sélection du mauvais `/Length` : **N1**. La résolution des longueurs introduit aussi les rejets **N2**. |
| 2. Nettoyage entre contrôle d'existence et adoption | **Partiellement corrigé** | Sur une connexion distincte, le nettoyeur ignore maintenant la préparation ancienne encore verrouillée. Le test à deux connexions passe ; mon entrelacement au point `exists()` obtient aussi zéro suppression et un fichier conservé après 201. Mais une reconnexion entre préparation et transaction fait perdre cette protection : **N3**. |
| 3. Panneau CV périmé après changement de liste | **Corrigé** | `LearnersPage.tsx:98` ferme le panneau CV depuis les changements de recherche/filtre et de page. Le nouveau test fourni passe. Un banc complémentaire vérifie aussi la fin de l'envoi pendant la fermeture, le retour à A, son état actualisé, « Retirer » et son message de succès ; aucun message transféré à B. |
| 4. Suppression concurrente : 409 pour la CME au lieu de 404 | **Corrigé** | `CvController.php:106` traduit le refus sous verrou selon le rôle. Le rejeu fourni supprime le compte pendant la préparation : CME → **404**, administrateur → **409 `learner_deleted`**, sans CV créé. La Direction reste refusée par les permissions des routes d'action ; la traduction commune couvre les non-administrateurs si elle est atteinte. |

Le banc initial du constat 2 appelait le nettoyage dans le processus de l'envoi. Avec `GET_LOCK`, il faut distinguer les connexions : MySQL autorise la réacquisition du même verrou par sa connexion propriétaire. Le rejeu concurrent pertinent utilise donc une connexion de nettoyage distincte. Un appel réentrant sur la connexion propriétaire supprime encore la préparation ancienne ; aucun appel applicatif de ce type n'a été identifié, ce n'est pas présenté comme une nouvelle course de production.

## Effets de bord vérifiés

- **Verrou nommé :** nom inférieur à 64 caractères et sans donnée personnelle ; conservé pendant la transaction ; nettoyage sans attente puis relecture de la référence sous le verrou. Les sorties normales et les échecs après retour de `store()` passent par `release()`. Réserves N3 et N4 pour reconnexion et exception interne à `store()`. Les verrous sont liés à la session, réentrants et indépendants du commit/rollback : [manuel MySQL 8.0](https://dev.mysql.com/doc/refman/8.0/en/locking-functions.html).
- **Autres SGBD :** utilisation directe de fonctions MySQL, cohérente avec la cible du projet. Aucune compatibilité SQLite/PostgreSQL annoncée ou testée ; aucun constat d'incompatibilité hors de cette cible.
- **Nettoyage :** banc de **250 fichiers anciens non référencés**, verrous de préparation libérés : 250 suppressions, environ **249 ms pour le balayage seul**. Les tests fournis de fichiers récents/référencés et de préparation active passent. Cela ne constitue pas une mesure à grande échelle : `files()` énumère tous les chemins, et les candidats anciens demandent des requêtes par fichier.
- **PDF et fins de ligne :** LF et CRLF sont reconnus après `stream`. CR seul n'est pas une fin de ligne conforme à cet endroit du format PDF ; son comportement ne constitue donc pas ici un rejet démontré de PDF valide. La distinction figure dans la [référence Adobe, §3.2.7](https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/pdfreference1.6.pdf#page=58).
- **Coût de l'inspection :** sous PHP Windows 8.5.12, environ 32 ms pour la fixture habituelle de 5 Mio, 662 ms pour de très nombreux noms ; un fichier synthétique de 5 229 224 octets avec **1 000 flux à longueur indirecte commune placée en fin de fichier prend environ 5,03 s**. La recherche globale répétée des longueurs est coûteuse. Une indexation lors du correctif N2 éviterait ces parcours répétés ; aucun seuil de performance n'est inventé pour en faire un constat bloquant supplémentaire.

## Nouveaux constats

### N1. Majeur — Le `/Length` d'un sous-dictionnaire permet encore de masquer du JavaScript en clair

**Fichier/ligne :** `backend/app/Services/Cv/PdfInspector.php:174`. **Référence :** plan §4, décision 9, refus des noms actifs.

**Nature : bug confirmé**, en mémoire puis par l'API Laravel sur MySQL.

**Scénario :** le catalogue pointe par `/OpenAction 7 0 R` vers une action placée après un flux vide. Le dictionnaire de ce flux est `<< /Audit << /Length 98 >> /Length 0 >>`. L'objet 7 contient `/S /JavaScript /JS (app.alert\(1\))` ; l'objet 8 est la chaîne `(endstream)`. Le générateur calcule les références et les décalages.

La regex prend **98**, longueur du sous-dictionnaire, au lieu de **0**, longueur du flux. Elle saute jusqu'au `endstream` contenu dans la chaîne de l'objet 8, en ignorant l'action en clair. Résultat : **`problem() === null`, HTTP 201 au lieu de 422**. Le PDF fictif mesure 640 octets ; aucun JavaScript n'a été exécuté.

Le même défaut provoque aussi un faux positif : flux vide avec `/Audit << /Length 99 >> /Length 0` → **422**, au lieu de 201.

**Impact :** contrôle actif contourné et certains fichiers bénins refusés ; inspecteur partagé avec T-22, document destiné à T-21. L'action n'est pas cachée dans un flux compressé.

**Correctif minimal :** extraire la clé `/Length` au niveau du dictionnaire du flux, avec prise en compte des dictionnaires imbriqués, chaînes et commentaires ; ne pas retenir sa première occurrence textuelle. Ajouter les deux scénarios ci-dessus, dont l'action après le flux.

**Rare et sans impact aval ?** Construction volontaire pour le contournement ; impact T-21/T-22, donc pas un défaut sans conséquence aval à reporter.

### N2. Majeur — Les longueurs indirectes compressées ou révisées font rejeter des PDF valides

**Fichier/ligne :** `backend/app/Services/Cv/PdfInspector.php:181`. **Référence :** acceptation des PDF valides, plan §4 et §8 ; compatibilité des objets compressés prévue en v1.2.

**Nature : régression confirmée** par deux bancs d'API, complétés en mémoire.

**Scénarios :**

1. Un PDF 1.5 contient un flux de page vide `/Length 7 0 R`. L'entier 7, égal à zéro, est stocké avec les objets dans un `/ObjStm` Flate ; le flux de références contient son entrée compressée. La regex ne trouve pas d'objet entier en clair → **422 `flux_non_delimite`**. La contrainte PDF qui interdit de compresser certaines longueurs concerne celle du flux d'objets lui-même, pas celle de tout flux de page : [référence Adobe, §3.4.6](https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/pdfreference1.6.pdf#page=98).
2. Une mise à jour incrémentale redéfinit le flux 4 et l'entier de longueur 5, avec une nouvelle section `xref` et un `/Prev`. La recherche globale conserve la **première** valeur de l'objet 5 et l'applique au nouveau flux : **422**. Un second banc en mémoire avec une page vide puis les seuls opérateurs graphiques `q`/`Q` confirme le même rejet.

**Impact :** rejet de représentations standards, indépendamment de tout contenu actif. Les fixtures actuelles « objets compressés » et « longueur indirecte » passent parce que leurs longueurs restent respectivement directes ou en clair sans révision.

**Correctif minimal :** résoudre les longueurs depuis les objets et références de la révision pertinente, y compris lorsque l'entier est compressé ; éviter une recherche globale de la première définition. Ajouter ces deux fixtures de régression. Conserver des bornes de taille/décompression : cela ne demande pas d'analyser les actions cachées dans les flux compressés ni d'ajouter une dépendance.

**Rare et sans impact aval ?** Fréquence réelle non mesurée faute d'exports bureautiques ; mécanismes standards pouvant toucher les téléversements ordinaires et T-22, donc impact aval.

### N3. Majeur — Une reconnexion perd le verrou de préparation sans empêcher l'adoption

**Fichiers/lignes :** `backend/app/Services/Cv/CvStorage.php:37`, `backend/app/Services/Cv/CvService.php:78`. **Référence :** cycle de vie du plan §5 et garantie du correctif 2.

**Nature : bug confirmé avec reconnexion et ancienneté simulées** ; pas une panne réseau réelle provoquée.

**Scénario :** préparer le fichier et prendre son verrou ; rouvrir la connexion **avant** la transaction d'adoption ; le verrou de l'ancienne session est libéré. Après que le contrôle d'existence a rendu vrai, un nettoyeur sur une autre connexion supprime la préparation rendue ancienne de deux heures. L'adoption poursuit son commit.

Le banc observe deux identifiants de connexion différents, **une suppression, un résultat `created` et une ligne courante dont le fichier n'existe plus**. L'assertion de présence échoue. Le code ne vérifie pas la propriété du verrou avant d'adopter. Laravel installé prévoit bien une reconnexion lors d'une perte de connexion à l'ouverture de transaction (`ManagesTransactions.php:185`).

**Impact :** retour du défaut de CV courant absent, consommé ensuite par T-21 ; risque sur l'ancien fichier en remplacement.

**Correctif minimal :** conserver l'identité de la session propriétaire et vérifier, dans la transaction et avant le contrôle du fichier, qu'elle détient toujours le verrou. En cas de perte, abandonner proprement, ou reprendre la protection avant une nouvelle vérification du fichier. Éviter une réacquisition systématique réentrante sans libération correspondante. Ajouter le test de reconnexion.

**Rare et sans impact aval ?** **Très rare** : reconnexion après préparation, préparation ancienne et nettoyage dans la fenêtre suivante. Impact sur T-21/T-22 ; ce n'est pas un cas sans conséquence aval.

### N4. Mineur — Une exception pendant la préparation laisse le verrou détenu

**Fichier/ligne :** `backend/app/Services/Cv/CvStorage.php:40`. **Référence :** libération après validation ou abandon annoncée par le correctif 2.

**Nature : fuite de verrou confirmée avec exception injectée.**

**Scénario :** fixer un UUID fictif, prendre le verrou réel, puis faire lever une `RuntimeException` à `Crypt::encryptString()`. Après interception, `IS_USED_LOCK()` renvoie toujours l'identifiant de la connexion. `store()` n'a pas rendu son chemin : le `finally` de `CvService` n'est pas encore installé. Une exception de disque à cet endroit n'est pas non plus couverte par une garde générale.

**Impact :** verrou conservé jusqu'à fermeture de la connexion ; rétention possible dans un processus persistant. Le scénario exécuté échoue avant écriture : aucun fichier courant perdu ni blocage d'un autre UUID démontré.

**Correctif minimal :** protéger tout le travail qui suit l'acquisition dans `store()` et libérer le verrou sur chaque exception avant de la relancer, avec nettoyage du fichier si nécessaire. Garder la détention normale sur succès jusqu'à la fin du service. Ajouter un échec après acquisition réelle ; le test actuel « disque plein » remplace tout `store()` et ne prend aucun verrou.

**Rare et sans impact aval ?** Rare panne de préparation ; aucun impact fonctionnel bloquant sur une tâche suivante démontré par ce banc. La durée de vie des connexions des futurs workers T-22 mérite toutefois d'être prise en compte.

## Suivi des limites de tests

Les nouveaux tests reproduisent correctement l'ancienne image piégée, la fermeture du panneau et la traduction du refus CME ; le test du nettoyeur utilise deux connexions réelles. Ils ne couvrent pas les nouveaux dictionnaires imbriqués, longueurs compressées/révisées, reconnexion et exception après acquisition réelle.

Le `/L` de `FakePdf::linearized()` est désormais exact : **562 octets annoncés et mesurés**. La limite n'est cependant que partiellement levée : `/H [0 0]`, `/E 0`, `/T 0` et la construction à une seule table ne prouvent toujours pas un véritable fichier linéarisé. Un export fictif réellement linéarisé reste préférable aux seuls marqueurs ; voir la [structure et les paramètres de linéarisation Adobe, annexe F](https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/pdfreference1.6.pdf#page=965).

Les exports fictifs Word, LibreOffice, Canva et navigateur restent absents : leur test est sauté. Les limites multipart PHP sont toujours simulées dans les tests Laravel ; aucun contrôle HTTP réel ajouté par cette contre-revue.

## Validation et limites

Tests backend lancés **successivement**, après vérification de `docker ps`, dans Docker : **PHP 8.4.28**, **MySQL 8.0.46**, base **`jobdating_test`**, jamais SQLite. Frontend sous Node 24.21.0 / Vitest 5.1.0. Bancs PDF en mémoire sous PHP Windows **8.5.12**, distincts des tests Docker.

| Contrôle exécuté par cette contre-revue | Résultat |
|---|---|
| `docker compose run --rm -T -e DB_DATABASE=jobdating_test -e APP_ENV=testing php php artisan test tests/Feature/Cv` | **45 réussis, 1 sauté, 235 assertions**, code 0 ; environ 191 s. |
| `npm.cmd exec -- vitest run src/cv/cv.test.tsx`, depuis `frontend/` | **7 réussis**, code 0. |
| Huit bancs backend externes, filtrés par `test_audit_` | **2 réussis, 6 échoués, 13 assertions**, code 1 ; environ 94 s. Échecs : contournement N1, faux positif bénin N1, deux rejets N2, fichier absent N3, verrou non libéré N4. Réussites : protection sur connexion distincte et balayage/réentrance mesurés. |
| Banc frontend supplémentaire injecté en mémoire par une configuration Vite externe | **8 réussis**, code 0 : les 7 existants et le retour à l'apprenant après envoi terminé pendant la fermeture. API simulée, composants réels. |
| Bancs PDF en mémoire et `git diff --check` | Résultats détaillés ci-dessus ; aucun défaut d'espacement, avertissements Git LF/CRLF. |

Les échecs des bancs d'audit sont les attentes de comportement correct qui exposent les défauts ; ils ne sont pas présentés comme des échecs de la suite fournie. Ancienneté, reconnexion et exceptions sont pilotées ; la course réseau réelle et une exploitation dans un lecteur PDF ne sont pas démontrées. Stockage des bancs isolé dans des conteneurs éphémères ; aucun fichier de test ajouté au dépôt.

Image PHP locale encore mesurée à **2M pour `upload_max_filesize`, 8M pour `post_max_size`**. Le Dockerfile prévoit 6M/8M et la reconstruction reste déjà signalée dans `PROGRESS.md`. Image non reconstruite ici ; aucun essai multipart sur serveur réel, navigateur, export bureautique ou mesure mémoire d'une requête complète effectué.

**Communiqués par Claude, non rejoués :** contrôles après correction dans `PROGRESS.md` du 27/11 : Pint et PHPStan sans erreur ; suite complète **600 tests réussis, 1 sauté, 3 157 assertions**, code 0 ; frontend lint, **129 tests** et build réussis. La suite complète, le lint, le build et les analyses statiques n'ont pas été relancés par cette contre-revue.

## Verdict

**À corriger avant fusion.** Les constats mineurs 3 et 4 sont corrigés. Les scénarios initiaux des deux majeurs sont traités dans le cas normal, mais le refus des PDF actifs et la protection de l'adoption restent incomplets ; le nouveau contrôle introduit aussi des rejets de PDF valides.
