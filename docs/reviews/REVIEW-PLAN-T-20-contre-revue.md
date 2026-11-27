# Contre-revue du plan T-20 — Téléversement, remplacement et retrait du CV

- **Date :** 26/11/2026, fuseau `Africa/Casablanca`.
- **Branche :** `feature/T-20-cv`.
- **Base et HEAD :** `develop`, `2f8a9d802704810240c6e937718dae258d353842` ; documents non commités.
- **Document :** `docs/tasks/PLAN-T-20.md`, version 1.1. Aucun code T-20 n'est écrit.
- **Nature :** contre-revue documentaire limitée. Les contradictions ou lacunes du plan sont constatées ; les conséquences sur l'implémentation restent des scénarios à vérifier.

## Périmètre

Lecture du prompt, d'`AGENTS.md`, de `CLAUDE.md`, du plan v1.1, du rapport initial, de la tâche courante et du diff documentaire avec `develop`, fichiers non suivis concernés compris. Vérification ciblée du verrouillage des apprenants et du fonctionnement des rappels de transaction Laravel installés. Les décisions **1 à 11** sont appliquées, notamment contrôle PDF simple sans dépendance, limite assumée des flux compressés, visibilité des fiches supprimées et conservation métier de `cv_history`.

Aucun vrai CV ni fichier de Téléchargements ouvert ; exclusions respectées : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`. Aucun code, plan ou autre document modifié, aucun commit ni push. Seul ce rapport est créé. Les contrôles complémentaires utilisent uniquement des données fictives en mémoire, sans base de données.

## Rejeu des constats initiaux

| Constat | Statut | Preuve dans le plan v1.1 |
|---|---|---|
| **1. État supprimé non protégé par le verrou de fiche** | **Corrigé** | §5, ligne 58 : compte avec suppression logique incluse → fiche → CV, état relu sous le verrou du compte et résultat selon l'ordre de validation. §8, lignes 109 et 114 : refus selon le rôle, conservation/restauration, essais MySQL à deux connexions face à suppression et restauration, avec preuve d'attente. |
| **2. Cycle de vie des fichiers insuffisamment défini** | **Partiel** | §5, lignes 60–69 : états préparé/adopté/abandonné, écriture vérifiée, ancien fichier supprimé après validation sans toucher au nouveau, erreurs signalées ; transactions englobantes refusées et contrat T-22 explicite. Les tests d'échec sont repris au §8. Mais le fichier préparé est encore déclaré abandonné lors d'un **interblocage rejoué**, et sa protection après une heure n'est pas définie : N1. |
| **3. Reconnaissance MIME/signature présentée comme validation suffisante** | **Corrigé pour le constat initial** | §4, lignes 47–51 : reconnaissance séparée d'un contrôle structurel et d'un refus de noms actifs ; limite des flux compressés annoncée, sans garantie antivirus. §8 : faux PDF de 67 octets, fichier tronqué et contenus interdits. Le niveau de contrôle est désormais tranché par la décision 9. La compatibilité des critères structurels pose toutefois un nouveau problème : N2. |
| **4. Suggestion sur la portée du message de dépassement** | **Corrigé sur la portée** | §4, ligne 53 : message réservé à la méthode POST et au chemin `/api/v1/learners/{id}/cv`, sans dépendre de la résolution de route ; autres routes inchangées. §8, ligne 105 : essais HTTP réels et non-régression XLSX. Il reste à distinguer correctement les deux limites PHP et à exercer les deux chemins d'erreur : N3. |

Ces statuts portent sur les contrats documentés, sans prétendre que les futurs tests T-20 sont déjà écrits ou réussis.

## Effets de bord examinés

- **Verrous :** le nouvel ordre correspond aux écritures de compte et fiche existantes. Le CV ne modifie ni classe ni événement : aucun verrou supplémentaire de ces ressources n'est nécessaire. La présence d'essais de suppression/restauration dans les deux sens répond à la demande initiale.
- **Transactions englobantes :** leur refus est un choix cohérent, et T-22 est explicitement prévu hors transaction, un apprenant à la fois. Tester cette garde avant toute préparation de fichier. Les rappels doivent être enregistrés pendant la transaction concernée : dans le gestionnaire Laravel installé, `afterCommit` sans transaction s'exécute immédiatement et `afterRollBack` sans transaction n'enregistre aucun rappel. Prévoir aussi le nettoyage si l'ouverture de transaction échoue avant cet enregistrement.
- **Nettoyage après une heure :** l'âge protège les préparations courtes, mais ne prouve pas qu'un fichier ancien a été abandonné. Un envoi encore vivant au-delà d'une heure est couvert par le scénario N1.b.
- **PDF courants :** le support explicite des flux de références est utile, mais il ne suffit pas si le contrôle de page exige encore du texte en clair. Un PDF linéarisé n'a pas les mêmes contraintes de placement d'objets qu'un PDF non linéarisé ; le corpus de tests doit comporter les deux. Aucun export Word, LibreOffice, Canva ou navigateur n'a été exécuté pendant cette revue ; ajouter des documents fictifs issus de ces outils, sans employer de vrais CV.
- **Contenus refusés :** le refus des structures `/EmbeddedFile` et `/XFA` est appliqué comme choix conservateur du plan, sans demander de les autoriser. Éviter cependant de confondre un nom PDF avec sa simple mention dans un texte, une chaîne ou un commentaire : prévoir par exemple un document fictif mentionnant `/JavaScript` ou une URL contenant `/JS`. C'est un risque de faux positif à tester, pas un défaut d'un inspecteur déjà exécuté.
- **Décisions 5 à 8, 10 et 11 :** source/auteur/date conservés si inchangé, actions permises sur les désactivés, conservation pour restauration, historique commun T-22 et durée métier sont explicités. Le report des mises à jour de noms de tables dans ARCHITECTURE est identifié. Aucun arbitrage métier de la revue précédente n'est rouvert.

## Constats restants ou nouveaux

### N1. Majeur — Le fichier préparé peut être supprimé alors que le traitement peut encore l'adopter

**Références :** `docs/tasks/PLAN-T-20.md:63`, `:67`, `:71`, §5 ; tests du cycle de vie et de concurrence au §8.

**Nature : incohérence confirmée du cycle décrit pour les reprises ; risque hypothétique de perte de document dans la future implémentation.** Le mécanisme de rappel au rollback a été vérifié isolément avec le gestionnaire Laravel, sans SQL ni fichier réel.

**Scénario a — Reprise après interblocage :** le fichier F est préparé hors transaction. La première tentative SQL est annulée et le rappel `afterRollBack` supprime F, conformément à la ligne 63 qui classe un « interblocage rejoué » comme abandon. La tentative suivante réutilise la préparation prévue en amont ; si elle réussit, `cv_documents` référence un fichier déjà supprimé. Le plan ne prévoit ni conservation pendant les reprises, ni nouvelle préparation avant chaque tentative.

**Scénario b — Préparation de plus d'une heure :** F est préparé puis l'opération reste en attente ou suspendue au-delà du seuil. F n'est pas encore référencé par une ligne validée de `cv_documents`. Le nettoyeur peut le supprimer selon la ligne 71 ; la reprise du traitement peut ensuite valider sa référence. La seule vérification d'existence à l'écriture initiale ne couvre pas ce cas.

**Impact :** document courant manquant après une réussite SQL, état « CV présent » trompeur et impossibilité de consultation dans T-21. La conservation de l'ancien fichier après échec de nettoyage, désormais correctement prévue, ne résout pas ce problème du nouveau fichier.

**Correction minimale dans le plan :**

- réserver l'état « abandonné » à la fin définitive du traitement ; conserver F pendant les reprises SQL, ou définir explicitement une nouvelle préparation hors transaction avant chaque tentative ;
- empêcher le nettoyeur de traiter une préparation encore active, par une coordination adaptée, ou définir une borne de durée réellement garantie avec une sortie propre empêchant toute adoption d'un fichier disparu ; l'âge seul ne doit pas établir l'abandon ;
- ajouter deux tests : première tentative annulée puis réussite avec CV déchiffrable ; préparation simulée ancienne alors que l'opération est encore en cours et passage du nettoyeur. Aucun besoin d'attendre réellement une heure pour ce test.

**Rare et sans impact sur les tâches suivantes ?** L'interblocage est un cas normal des mécanismes de reprise ; la préparation de plus d'une heure est rare. Les deux touchent le fichier courant consommé par T-21/T-22 : pas un défaut sans impact aval à reporter.

### N2. Majeur — La vérification des pages en clair exclut des PDF standards à objets compressés

**Références :** `docs/tasks/PLAN-T-20.md:49`, §4 ; `:104`, §8 ; CV-01 et acceptation d'un PDF valide au CDC §11.6.

**Nature : incompatibilité confirmée du critère annoncé avec la représentation PDF ; rejet par le futur `PdfInspector` déduit du plan, non exécuté.**

**Scénario :** un PDF 1.5 non linéarisé utilise un flux `/ObjStm` compressé pour le catalogue, l'arbre de pages et la page, avec un flux `/Type /XRef`. La page et son compteur existent, mais aucun `/Type /Page` ni `/Count` d'un objet `/Pages` n'est présent en clair dans le fichier. Le contrôle de la ligne 49 le refuse alors que ce stockage des objets est permis. La documentation du format utilisée par qpdf décrit ces objets compressés et distingue les contraintes supplémentaires des fichiers linéarisés. [Documentation primaire sur les objets et références PDF](https://qpdf.readthedocs.io/en/stable/object-streams.html).

Un document synthétique d'une page a été construit en mémoire : **459 octets**, en-tête PDF 1.5, fin `%%EOF`, `startxref`, flux de références et objets réguliers présents ; **aucun des deux marqueurs de page en clair**, mais marqueur de page présent après décompression du flux d'objets construit. Aucun rendu ni validation indépendante par un lecteur PDF n'a été lancé ; ce banc vérifie la représentation des objets et les motifs exigés par le plan.

**Impact :** refus de PDF ordinaires selon leurs options d'export ou de compression. Un test fondé uniquement sur un petit PDF à objets non compressés pourrait rester vert et masquer ce défaut.

**Correction minimale dans le plan :** garder le contrôle simple et sans dépendance décidé, mais définir une branche compatible avec les références et objets compressés : ne pas exiger une preuve de page en clair lorsqu'elle n'est pas disponible dans cette représentation. Ajouter une fixture fictive à objets compressés, une à références classiques et une linéarisée, puis un échantillon d'exports fictifs courants. Si un profil PDF doit être exclu, faire expliciter cette restriction au lieu de le déclarer silencieusement invalide. La limite acceptée concernant la détection de contenus actifs dans les flux compressés reste inchangée ; aucune installation de qpdf n'est proposée.

**Rare et sans impact sur les tâches suivantes ?** Ce mode de représentation fait partie du format PDF ; sa fréquence dans les futurs CV n'est pas mesurée. Le défaut touche le téléversement normal et l'inspecteur partagé avec T-22 ; à régler avant développement.

### N3. Mineur — Les deux limites PHP sont confondues, ce qui laisse le vrai chemin 413 insuffisamment testé

**Références :** `docs/tasks/PLAN-T-20.md:53`, §4 ; `:105`, §8.

**Nature : imprécision technique confirmée du plan et de sa matrice de tests ; aucun essai HTTP de T-20 exécuté.**

**Scénario :** avec `upload_max_filesize=6M` et `post_max_size=8M`, un fichier d'environ 6,5 Mio dans un corps inférieur à 8 Mio échoue avec `UPLOAD_ERR_INI_SIZE`. Cela ne signifie pas que toute la requête POST est vide, ni que Laravel lève `PostTooLargeException`. Le dépassement du corps POST au-delà de 8 Mio est un autre chemin. Un seul essai « au-delà de 6 Mo » n'assure donc pas la couverture du traitement 413 annoncé. Ces limites sont distinctes dans la [documentation PHP des erreurs de fichier](https://www.php.net/manual/en/filesystem.constants.php#constant.upload-err-ini-size) et celle de [la taille du corps POST](https://www.php.net/manual/en/ini.core.php#ini.post-max-size).

**Impact :** message incorrect ou non maîtrisé pour l'une des tailles rejetées, malgré une série de tests qui pourrait sembler couvrir toutes les limites. Dans Laravel installé, un fichier reçu avec erreur passe par la règle de validation `uploaded`, tandis que `ValidatePostSize` contrôle le corps POST.

**Correction minimale dans le plan :** séparer les cas : limite applicative de 5 Mio ; erreur native de fichier entre 6 et 8 Mio avec surcharge multipart maîtrisée ; corps POST au-delà de 8 Mio. Prévoir le même message CV sur ses deux chemins d'erreur, avec les autres routes inchangées. Corriger la phrase « PHP vide la requête » pour ne l'appliquer qu'au dépassement pertinent.

**Rare et sans impact sur les tâches suivantes ?** Cas d'erreur d'entrée, sans perte de CV ni dépendance aval identifiée ; correction documentaire mineure.

## Validation et limites

Contrôles réalisés pour cette contre-revue :

- lecture documentaire et comparaison aux constats initiaux ; vérification des sources Laravel de transactions, validation des fichiers et taille POST ;
- banc en mémoire avec `DatabaseTransactionsManager` sous **PHP 8.4.28** : après rollback, le rappel d'abandon a modifié le témoin de préparation avant une nouvelle transaction logique. C'est une vérification du rappel, pas une reproduction SQL d'interblocage ni un service CV exécuté ;
- génération du PDF synthétique ci-dessus et vérification de ses motifs en mémoire avec Python et sa bibliothèque standard. Aucun fichier n'a été conservé dans le dépôt, aucune bibliothèque ajoutée.

Aucun code T-20, suite applicative, test MySQL, migration, téléversement HTTP réel, export bureautique ou contrôle navigateur exécuté. Les futurs tests listés dans le plan ne sont pas des résultats acquis. Les sources techniques publiques sont liées près des constats qu'elles soutiennent. Cette revue n'approuve aucun code sans validation locale ultérieure.

## Verdict

**Plan à revoir.** Le verrouillage du compte et la portée du message CV sont corrigés ; les décisions 1 à 11 sont prises en compte. Il reste à préserver les fichiers préparés pendant les reprises et opérations longues, puis à rendre le contrôle structurel compatible avec les PDF à objets compressés. La distinction des deux limites PHP complète ces corrections.
