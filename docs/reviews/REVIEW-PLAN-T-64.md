# Revue du plan T-64 — Import des entreprises par fichier

- **Date :** 25/11/2026, fuseau `Africa/Casablanca`.
- **Documents :** `docs/tasks/PLAN-T-64.md`, version 1.1, et `docs/tasks/CURRENT_TASK.md`.
- **État :** branche `develop`, HEAD `c3f0365bba108d0a75a7d0f7dc783fc0d587d073` ; modifications documentaires non commitées. La branche `feature/T-64-import-entreprises` reste à créer avant le code.
- **Nature :** revue avant développement. Les lacunes documentaires sont constatées ; les scénarios T-64 ci-dessous sont des risques à tester après implémentation, pas des bugs d’un code déjà livré.

## Références et périmètre

Lecture du prompt, d’`AGENTS.md`, de `CLAUDE.md`, du plan, de la tâche courante et du diff documentaire avec `develop`. Comparaison avec ADM-04 « Import des entreprises », ses exceptions communes, ADM-02 et RG-ENTR-01 à 07, ADM-07 et TRA-01. Les décisions du 14/11, du 20/11, du 23/11 et les deux décisions du 25/11 sont prises en compte. Les décisions 1 à 8 du plan ne sont pas remises en cause.

Code de référence consulté : lecteur, planification/confirmation, traitement et modèle d’import apprenants ; tâche et nettoyage des imports ; contrôleur et téléchargement des mots de passe ; services, modèle et identifiants des entreprises ; changement de mot de passe depuis le profil ; affectations aux événements ; parcours frontend d’import et fiche de connexion. Le lecteur OpenSpout installé a également été examiné pour vérifier la faisabilité de la sélection des données.

Maquettes examinées : `admin-03-entreprises-participantes.png`, `admin-07-import-apprenants.png`, `admin-08-import-termine.png`. Les décisions remplacent les éléments devenus obsolètes de ces maquettes, notamment téléchargement unique et e-mails. Les passages de relais T-11/T-13/T-14 de `PROGRESS.md` et les erreurs connues E-01 à E-06 ont été consultés ; ces erreurs ne sont pas rouvertes.

**Aucun classeur réel ouvert**, notamment aucun fichier de Téléchargements. La structure du classeur est celle décrite dans le plan. Exclusions respectées : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`. Aucun code, plan ou autre document modifié ; seul ce rapport est ajouté. Aucun commit ni push.

## Constats

### 1. Majeur — Une transaction seule ne protège pas le nom simplifié contre les créations concurrentes

**Référence :** plan §5, lignes 64–68, et §13, ligne 121 ; décision 5 et règle de doublon d’ADM-04. Code de référence : `CompanyService::create/update`, `UsernameSuggester`, migration des entreprises.

**Nature :** mécanisme d’exclusion absent du plan ; risque de doublon concurrent hypothétique. La table actuelle possède un index ordinaire sur `companies.name`, sans contrainte sur une clé de nom simplifié.

**Scénario :** deux imports sur le même événement, ou sur deux événements, préparent respectivement « AcmeServices » et « Acme Services ». Leurs noms simplifiés sont identiques, mais leurs noms d’utilisateur proposés peuvent être `acmeservices` et `acme-services`. Chaque transaction peut constater le nom libre avant l’écriture de l’autre. Les verrous partagés sur l’événement sont compatibles et l’unicité des noms d’utilisateur ne provoque aucun conflit dans cet exemple. Les deux créations peuvent donc réussir, alors qu’en traitement successif le second import signalerait l’entreprise existante.

Une création ou un renommage manuel peut également s’intercaler. Le point signalé « la création à la main n’est pas modifiée » décrit une limite, mais ne fournit pas de protection pour la revalidation annoncée.

**Impact :** entreprises doublonnées et comptes distincts alors que le rapprochement par nom simplifié devait les identifier. Les reprises de transaction ne corrigent pas un cas où les deux transactions réussissent sans erreur.

**Correction proposée dans le plan :** préciser un mécanisme commun qui couvre la revalidation et l’écriture des noms pour les imports, ainsi que leur interaction avec création et renommage manuels. Un verrou de coordination partagé par les chemins concernés est une possibilité ; le verrou d’événement seul ne suffit pas. Définir son ordre avant les verrous existants et reprendre la lecture dans chaque nouvelle transaction. Cela n’impose pas d’interdire les homonymes en création manuelle : cette politique reste distincte du contrôle propre à l’import. Ajouter des essais MySQL à deux connexions avec variantes de ponctuation/espaces produisant des noms d’utilisateur différents, import contre import, création et renommage manuels.

**Fréquence et suites :** chevauchement occasionnel mais cas normal de plusieurs administrateurs ; incidence sur les entreprises et leurs participations futures. Pas un défaut rare démontré sans impact ultérieur.

### 2. Majeur — La version de fiche ne détecte pas le changement de mot de passe par l’entreprise

**Référence :** plan §6, lignes 73–74 ; RG-ENTR-06/07 et AUTH-03. Code : `backend/app/Http/Controllers/Auth/PasswordChangeController.php:56`, `:75` ; `backend/app/Services/Companies/CompanyService.php:256`.

**Nature :** limite confirmée du code réutilisé, conséquence non traitée dans le plan T-64 ; scénario de fiche périmée non exécuté.

**Scénario :** l’import crée Alpha avec un mot de passe généré et `credentials_version = 1`. Alpha se connecte puis change son mot de passe depuis son profil. Le contrôleur actuel modifie le hachage sur `users`, sans incrémenter `companies.credentials_version`. L’ancien secret conservé dans l’import ne fonctionne plus, mais le seul contrôle « version différente » continue de considérer sa fiche comme imprimable. La confirmation en lot pourrait encore marquer cette version comme imprimée.

**Impact :** remise d’une fiche inutilisable et état d’impression trompeur. La nouvelle conservation des fiches dans T-64 rend ce cas pertinent ; il ne s’agit pas de rouvrir une ancienne revue de T-05.

**Correction proposée dans le plan :** détecter les changements du hachage effectif, en plus de la version, par exemple avec un témoin initial conservé côté serveur, ou définir une révision commune invalidée par tous les changements de mot de passe. Stocker les identifiants stables des comptes/entreprises et la version nécessaires à ce contrôle, sans exposer de hachage au navigateur. Éviter de rendre chaque impression dépendante de centaines de vérifications bcrypt. Définir aussi l’atomicité de la vérification et du marquage en lot face à une réinitialisation concurrente. Respecter l’ordre existant entreprise → compte ; ajouter un verrou d’entreprise après celui du compte dans le contrôleur de profil introduirait un ordre inverse à examiner. Tester changement par l’entreprise, réinitialisation par l’administrateur et réinitialisation intercalée pendant « J’ai imprimé » : aucune version devenue caduque ne doit être marquée à tort.

**Fréquence et suites :** changement de mot de passe légitime, sans panne nécessaire. Impact sur les nouveaux parcours d’impression et les indicateurs existants ; à définir avant développement.

### 3. Majeur — La promesse de lecture sélective n’est pas assurée par la réutilisation ordinaire d’OpenSpout

**Référence :** plan §1, décisions 3 et 7 ; §3, lignes 35–38 ; §13, ligne 123. Code installé : `Reader/XLSX/Reader.php:147`, `Manager/SharedStringsManager.php:100` et `:218`, `RowIterator.php:341`, dans `backend/vendor/openspout/openspout/src/`.

**Nature :** limite de faisabilité confirmée par lecture de la bibliothèque ; risque futur de traitement/copie de valeurs exclues. Aucun contenu réel utilisé.

**Problème :** le lecteur XLSX installé extrait toutes les chaînes partagées à l’ouverture, avant la sélection de la feuille. Ces chaînes sont communes au classeur, y compris celles d’une feuille non parcourue. Son itérateur matérialise aussi les cellules d’une ligne avant le filtrage applicatif. Selon la stratégie choisie par la bibliothèque, les chaînes sont conservées en mémoire ou copiées dans un cache temporaire sur disque. Ne consulter que deux cellules après réception de la ligne garantit une projection métier, mais pas la promesse littérale « les autres valeurs ne sont jamais lues », notamment celles de mot de passe de l’autre feuille.

**Scénario fictif à prévoir :** placer des sentinelles de contact dans une colonne ignorée et une sentinelle de mot de passe dans les chaînes partagées utilisées par la seconde feuille. Un lecteur OpenSpout standard peut les décoder dès l’ouverture ; vérifier uniquement leur absence de la base et des réponses ne détecterait pas cette étape.

**Impact :** écart avec la minimisation expressément décidée, et possibilité de copies temporaires de données que T-64 s’engage à ne pas exploiter. Ce n’est pas une fuite publique démontrée : les dossiers temporaires actuels sont privés et nettoyés.

**Correction proposée dans le plan :** définir la frontière technique précise à respecter et choisir une lecture sélective avant matérialisation des valeurs exclues. Réutiliser les protections ZIP et les outils XML disponibles si nécessaire, sans modifier le lecteur apprenants hors besoin. Le plan doit expliquer le traitement des chaînes partagées : sélectionner les valeurs utiles plutôt que reconstruire d’abord le dictionnaire complet. Les métadonnées nécessaires à l’identification de la feuille et des colonnes restent distinctes des valeurs métier exclues. Définir les lignes vides à partir des colonnes utiles, sans lire les contacts pour décider si une ligne est vide. Prévoir des tests sentinelles sur chaînes partagées et chaînes en ligne, autres colonnes et seconde feuille, y compris sur les éventuels caches temporaires.

**Fréquence et suites :** comportement courant des XLSX à chaînes partagées ; ne nécessite ni concurrence ni incident. Conditionne le lecteur T-64, donc à trancher avant le code sans affaiblir les décisions 3 et 7.

### 4. Mineur — Le cas d’un nom simplifié vide et la première occurrence admissible restent à préciser

**Référence :** plan §1, décisions 5 et 8 ; §4, lignes 47–52.

**Nature :** cas limites non définis ; risque hypothétique de rapprochement erroné.

**Scénario :** « ... » et « !!! » ne sont ni vides ni trop longs à la lecture brute, mais peuvent produire la même clé vide après suppression de la ponctuation. Les traiter comme deux noms identiques créerait la première entrée puis signalerait la seconde comme doublon sans identité exploitable. Les noms composés de chiffres, eux, ne doivent pas être confondus avec ce cas.

La « première ligne » doit aussi désigner la première occurrence confirmée et admissible : une ligne non confirmée ou rejetée ne doit pas réserver le nom au détriment d’une ligne valide suivante.

**Impact :** compte sans nom exploitable ou ligne valide écartée à tort ; comportement variable selon les fonctions de normalisation retenues.

**Correction proposée dans le plan :** définir une fonction unique de comparaison, distincte du slug de connexion, préciser les caractères conservés et le traitement d’une clé vide. Refuser explicitement la clé vide est une solution simple ; conserver les chiffres. Définir à quel moment la clé entre dans l’ensemble des noms déjà vus. Tester variantes de ponctuation, nom numérique, clé vide et première occurrence non confirmée/rejetée suivie d’une occurrence admissible. Pour la proposition de connexion, préciser l’adaptation de `UsernameSuggester` afin de réserver les candidats déjà proposés dans le lot, y compris après troncature à 50 caractères.

**Fréquence et suites :** saisies atypiques ou correction d’un classeur ; faible fréquence probable, non mesurée. Les règles d’identité seront réutilisées par toutes les étapes de T-64 ; mieux vaut cette précision maintenant qu’un report comme défaut sans impact.

### 5. Mineur — Les fiches réimprimées n’incluent pas l’emplacement complété après l’import

**Référence :** plan §1, décision 3, et §6, ligne 72 ; décision du 23/11 sur la fiche de connexion et code `frontend/src/companies/CredentialsSheet.tsx:49`.

**Nature :** champ absent du contrat d’impression décrit ; conséquence fonctionnelle hypothétique.

**Scénario :** Alpha est importée sans emplacement, puis la CME lui affecte l’étage 2, table C03 dans l’événement de l’import. L’administrateur imprime à nouveau ses fiches. La liste des champs prévue au §6 comprend événement/date/lieu, mais pas étage/table, alors que la fiche existante de T-14 affiche cet emplacement.

**Impact :** la nouvelle impression en lot perd une information déjà disponible et attendue sur la fiche. L’absence initiale d’emplacement est valide ; c’est sa restitution après complément qui manque.

**Correction proposée dans le plan :** inclure l’emplacement actuel de la participation à l’événement de l’import, avec un message adapté lorsqu’il reste absent. Ne pas utiliser automatiquement l’événement courant si l’import concernait un autre événement futur. Réutiliser le contrat de placement de T-14 et tester la réimpression après saisie ou modification de l’emplacement.

**Fréquence et suites :** parcours normal « importer, compléter, imprimer », explicitement prévu par la décision 3 ; pas un cas rare. Correction documentaire courte, sans nouvelle fonctionnalité hors périmètre.

## Points vérifiés sans remarque

- **Choix métier :** classeur de suivi, feuille ciblée, filtrage « Oui », nom seul, signalement des entreprises existantes sans modification ni affectation, première occurrence conservée, absence de modèle téléchargeable et exclusion de la seconde feuille sont bien repris. L’abandon de T-16 est enregistré dans les décisions et le backlog ; T-63 reste explicitement à arbitrer. Les entreprises déjà créées dans la nouvelle application peuvent toujours être ajoutées par le parcours T-14 prévu.
- **Couverture générale d’ADM-04 :** aperçu, validation par ligne, confirmation, création de comptes avec participation, rapport et impression groupée sont identifiés. Les exceptions fichier vide, format ou en-têtes invalides sont à rendre explicites dans les retours du lecteur ; le découpage des responsabilités permet de les traiter.
- **Comptes entreprises :** `importCreate` distinct, mot de passe généré puis haché hors transaction, absence de logo et d’e-mail : approche adaptée. La méthode doit conserver l’activation immédiate, le rôle entreprise unique et l’insertion de la participation de T-11/T-14. Ne pas réutiliser `create()` en lui faisant régénérer un autre mot de passe.
- **Événement :** sélection non archivée/non terminée, contrôle sous verrou partagé pendant le traitement et affectation dans la même transaction sont cohérents avec T-14. Une suppression ou un archivage avant acquisition du verrou doit conduire à un échec propre ; aucune entreprise partiellement créée ne doit subsister.
- **Idempotence et file :** la prise en charge conditionnelle, les trois reprises, la conservation des mêmes secrets préparés pendant ces reprises et la finalisation transactionnelle suivent T-13. Reprendre aussi ses garanties de mise en file après commit, message contenant seulement l’identifiant, échec de remise à la file, callback de timeout et nettoyage conditionnel des états encore ouverts.
- **Identifiants :** l’outil existant vérifie les comptes supprimés et gère longueur minimale/maximale et suffixes. La réservation des propositions du lot est annoncée ; elle nécessite une adaptation, car `suggest()` consulte actuellement uniquement les comptes déjà enregistrés. L’index SQL sur le nom d’utilisateur doit rester le dernier contrôle en cas de collision.
- **Droits :** `companies.manage` correspond à l’administrateur et suffit pour toutes les routes prévues. Garder les contrôles serveur sur chaque route, notamment lecture/impression/suppression des secrets ; la restriction de la page React ne suffit pas. Pas de permission ou dépendance nouvelle nécessaire sur ce périmètre.
- **Audit :** catégorie « Imports et exports », événements du cycle de l’import, création et affectation individuelles identifiés. Les métadonnées autorisées et les comptes de lignes suffisent ; aucune valeur brute d’une colonne exclue ne doit entrer dans une exception ou un journal technique.
- **Frontend :** bouton dans l’écran attendu, événement plutôt que campus/promotion, étapes proches de T-13, aperçu invalidé après modification, même clé après réponse réseau perdue, refus 409 et réponses tardives sont annoncés. Les maquettes apprenants servent de modèle d’enchaînement, sans reprendre leurs e-mails, mises à jour ou ancien téléchargement unique.

## Compléments à inscrire dans les tests et le découpage

Les tests généraux du §10 sont pertinents. En plus des scénarios de chaque constat, préciser :

- échec au milieu du lot et échec du journal : aucun compte, fiche, participation ou secret partiel ; échec après les trois reprises ; redélivrance d’une tâche et nettoyage d’un import qui vient de démarrer ;
- confirmation simultanée avec la même clé, puis réutilisation de cette clé avec une autre empreinte ; collision de nom d’utilisateur au dernier moment, y compris compte supprimé ;
- deux colonnes obligatoires déplacées, doublon d’en-tête ou de nom de feuille après normalisation, feuille renommée, cellules fusionnées : pas de choix silencieux d’une colonne/feuille ambiguë ni de propagation implicite du nom d’une cellule fusionnée ; lignes vides intercalées et numéros réels ;
- lecture de la confirmation avant exploitation du nom ; règles explicites pour une confirmation non textuelle ou formule et pour les lignes non confirmées ;
- fiches périmées exclues côté serveur, marquage en lot idempotent, suppression des secrets définitive et audit atomique ;
- **secrets côté navigateur :** réponse `no-store` et chargement hors cache TanStack, stockage persistant et journaux ; état d’affichage temporaire effacé à la fermeture, au changement d’import ou d’identité ; réponse tardive ne réinstallant pas des fiches effacées. Le téléchargement binaire T-13 ne couvre pas à lui seul le nouveau rendu d’impression ;
- impression de plusieurs fiches avec sauts de page, exclusion des contrôles de navigation à l’impression, absence de secrets dans les vues d’historique/rapport ordinaires ; reprise après échec de chargement ou d’impression.

**Échéance du 25/12 :** la réutilisation ciblée de T-13/T-11/T-14 rend le périmètre plausible, mais ce n’est pas une simple copie du lecteur et de l’export. La lecture sélective et les nouveaux contrats d’impression sont les principaux travaux à résoudre avant une estimation fiable. Découper en quatre lots revoyables : lecteur/planification et fixtures fictives ; concurrence et traitement atomique ; cycle des fiches et API ; interface/impression.

Mesurer au plafond de **1 000 lignes**, avec le coût de hachage cible et le timeout du worker, plutôt que seulement « quelques centaines ». Le suivi T-13 rapporte environ 302 s pour 1 000 comptes au coût bcrypt 12 : c’est un repère communiqué dans `PROGRESS.md`, pas une mesure T-64. Sans capacité d’équipe ni estimation détaillée, cette revue ne peut pas garantir la date de livraison. Aucun framework générique d’import ni refactoring global n’est nécessaire pour répondre aux constats.

## Questions au responsable technique

1. **Événement ou participation supprimé après un import terminé :** faut-il encore permettre l’impression des identifiants, et avec quel contexte d’événement ? La table prévue référence l’événement mais le comportement de sa clé étrangère n’est pas défini. Préciser le devenir de l’historique et des fiches, sans bloquer implicitement les suppressions permises par T-14 ni effacer silencieusement l’historique d’import par cascade. Le cas « événement disparu avant traitement » est distinct et doit échouer proprement.
2. **Compte désactivé ou entreprise retirée de l’événement après l’import :** doit-on exclure sa fiche de l’impression groupée, ou la conserver avec un avertissement et un compte des exclusions/avertissements ? Les décisions tranchent les secrets réinitialisés, pas ces deux états. Cette réponse précisera le rapport de l’impression et sa confirmation en lot.

## Validation et limites

Contrôles effectués : lecture du plan et du diff documentaire, rapprochement des références, inspection statique des points de réutilisation et de la bibliothèque installée, examen des trois maquettes demandées. `git diff --check` ne signale pas d’erreur de contenu ; avertissement LF/CRLF sur la tâche courante.

**Aucun test applicatif, build, migration, banc de concurrence ou traitement de classeur exécuté.** Aucun code T-64 n’existe. Les scénarios du rapport sont des cas à prévoir ; les résultats d’anciennes tâches ne prouvent pas T-64. La revue du code devra disposer de résultats locaux communiqués conformément à `AGENTS.md`.

## Verdict

**Plan à revoir.**

Préciser avant développement la protection concurrente des noms, la validité des fiches après tout changement de mot de passe et la lecture réellement sélective. Compléter les deux points mineurs et trancher le devenir des fiches après changement d’état. Les décisions 1 à 8 et le périmètre métier retenu sont conservés.
