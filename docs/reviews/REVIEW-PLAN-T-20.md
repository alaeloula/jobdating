# Revue du plan T-20 — Téléversement, remplacement et retrait du CV

- **Date :** 26/11/2026, fuseau `Africa/Casablanca`.
- **Branche :** `feature/T-20-cv`.
- **Base et HEAD :** `develop`, `2f8a9d802704810240c6e937718dae258d353842`.
- **Document :** `docs/tasks/PLAN-T-20.md`, version 1.0, et `CURRENT_TASK.md` ; modifications documentaires non commitées, aucun code T-20 écrit.
- **Bilan :** trois constats majeurs et une suggestion. Les lacunes du plan sont constatées ; les scénarios de concurrence et de stockage décrits restent à tester après implémentation.

## Références et périmètre

Lecture du prompt, d'`AGENTS.md`, de `CLAUDE.md`, du plan, de la tâche courante, du diff documentaire avec `develop`, des décisions, des passages de relais de `PROGRESS.md` et des erreurs connues, sans rouvrir ces dernières. Références ciblées : CDC CV-01/CV-04, §§7.1, 7.2, 7.5, 7.6, 8.7 et 11.6, ADM-03, matrice 3.2 et TRA-01 ; ARCHITECTURE §§3, 7 et 8. Les décisions 1 à 4 et le retrait décidé le 26/11 sont respectés.

Code existant consulté : contrôleur, filtres, service, modèle et ressource des apprenants ; groupe de routes et permissions ; audit ; gestion des fichiers du service entreprises ; nettoyage des imports ; mécanismes de concurrence ; liste React des apprenants ; configuration du stockage, du chiffrement et image PHP. Les maquettes `cme-01-cv-apprenants.png` et `admin-06-apprenants.png` ont été examinées.

Aucun vrai CV, fichier de Téléchargements ou `backend/.env` ouvert. Exclusions respectées : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`. Aucun code ni autre document modifié ; aucun commit ni push. Seul ce rapport est ajouté. Aucun test applicatif ni opération sur une base de données effectué ; les contrôles techniques en mémoire sont détaillés plus bas.

## Avis sur les propositions 5 à 8

| Proposition | Avis |
|---|---|
| **5 — Même empreinte : inchangé, sans nouvel historique ni audit** | **Favorable sous validation explicite du responsable technique.** Aucun nouveau fichier courant n'est ajouté : conserver source, auteur et date actuels est cohérent. Le cas « inchangé » de §7.3 concerne cependant l'import et ne valide pas automatiquement cette règle pour le téléversement. Conserver la comparaison sous verrou et supprimer le fichier préparé inutilement. T-22 devra toujours pouvoir journaliser son propre résultat d'import « inchangé ». |
| **6 — Actions autorisées sur un apprenant désactivé** | **Favorable.** RG-APR-05 conserve le CV et n'interdit pas sa gestion par l'équipe habilitée. Cela ne réactive ni le compte ni une participation et ne doit pas ouvrir l'accès entreprise aux apprenants désactivés. |
| **7 — Refus pour un apprenant supprimé, CV conservé pour restauration** | **Favorable sur le principe, avec correction du verrouillage du compte : constat 1.** Préciser aussi la visibilité : le contrôleur actuel masque ces fiches aux non-administrateurs par 404. Le 409 proposé doit être articulé avec cette règle, sans rendre les fiches supprimées accessibles à la CME par la nouvelle route d'historique sans décision explicite. |
| **8 — Historique commun `cv_history`** | **Favorable sous validation du nom et du contrat partagé avec T-22.** Une table commune aux ajouts, remplacements, retraits et résultats d'import est adaptée. Les champs nullable et le motif prévus permettent les échecs sans document. Mettre en cohérence les références à `cv_import_logs` après validation ; aucune seconde table équivalente n'est nécessaire. Conserver le libellé d'auteur historique et définir la durée de conservation. |

Ces avis ne transforment pas les propositions en décisions déjà acquises.

## Constats

### 1. Majeur — Le verrou de fiche ne protège pas l'état « supprimé » du compte

**Références :** plan §5, lignes 52 et 57, proposition 7 ; tests §8, lignes 94 et 98. Code : `backend/app/Services/Learners/LearnerService.php:192`, `:195`, `:202`, `:206` ; `backend/app/Models/Learner.php`, relation `user()`.

**Problème confirmé dans le plan :** l'état supprimé est porté par `users.deleted_at`, et non par `learners`. Le plan ne verrouille que la fiche puis le document. La suppression et la restauration existantes verrouillent le **compte**, sans prendre de verrou de fiche pour la suppression. Le verrou proposé n'assure donc pas leur exclusion mutuelle.

**Scénario à reproduire :** une demande CV charge un apprenant non supprimé et prépare son fichier. Une suppression du compte se valide avant l'écriture du document. Verrouiller ensuite seulement `learners` et consulter une relation `user` précédemment chargée, ou lue sans verrou, peut encore conduire à accepter le CV. Le même problème concerne le retrait. Ajouter tardivement un verrou de compte après celui de fiche créerait en outre un ordre inverse à T-12/T-13.

**Impact :** la proposition 7 ne serait pas garantie en concurrence ; risque d'action validée sur un apprenant déjà supprimé ou d'interblocage si l'ordre est corrigé au mauvais endroit.

**Correction proposée dans le plan :** pour téléverser et retirer, verrouiller le compte avec suppression logique incluse, relire son état, puis verrouiller la fiche et le document : **compte → fiche → CV**. Aucun verrou de classe ou d'événement n'est nécessaire puisque ces éléments ne changent pas. Définir les résultats selon l'ordre de validation : opération CV validée avant suppression conservée ; suppression validée avant contrôle CV → refus. Ajouter les essais MySQL à deux connexions, suppression et restauration face au téléversement/retrait, avec preuve d'attente.

**Rare et sans impact sur les tâches suivantes ?** Chevauchement occasionnel entre gestionnaires, mais contrat réutilisé par T-22 et intégré aux comptes T-12/T-13. À résoudre avant développement.

### 2. Majeur — Le cycle de vie des fichiers ne distingue pas assez rollback et erreur après commit

**Références :** plan §5, lignes 51–59 ; conservation d'une seule version, CDC §§7.2 et 8.7. Référence utile : `CompanyService`, rappels de validation et d'annulation des transactions englobantes.

**Problème :** le plan prévoit de supprimer le nouveau fichier « en cas d'échec » dans un `try/finally`, mais ne définit pas le point à partir duquel ce fichier est devenu le document courant validé. Une erreur après commit n'est plus un rollback. La prise en charge d'une transaction englobante et des échecs du disque n'est pas précisée non plus.

**Scénarios à tester :**

- Le remplacement SQL et son audit se valident ; la suppression de l'ancien fichier dans le rappel `afterCommit` échoue. Si cet échec déclenche le nettoyage générique du nouveau fichier, la base peut pointer vers un CV absent.
- Le service est appelé dans une transaction englobante ensuite annulée : l'ancien CV doit rester intact, le nouveau ne doit pas être considéré comme définitivement adopté, et les rappels doivent être rattachés au bon niveau.
- Écriture sur disque refusée ou suppression retournant `false` : le disque `local` actuel a `throw = false`. Une absence d'exception ne prouve pas que l'opération a réussi.

**Nature : lacune confirmée du contrat ; pertes de fichier hypothétiques, aucun service T-20 exécuté.**

**Impact :** disparition possible du CV courant, conservation silencieuse d'un ancien CV ou succès annoncé alors que le fichier n'a pas été écrit. Le nettoyage différé des orphelins ne répare pas un document courant manquant.

**Correction proposée dans le plan :** définir explicitement les états « préparé », « adopté par validation finale » et « abandonné ». Supprimer le nouveau fichier après rollback ou inchangé, mais jamais parce que le nettoyage de l'ancien a échoué après validation. Vérifier les retours d'écriture et de suppression ; prévoir un signalement et une reprise du nettoyage des anciens fichiers non référencés. Définir le support des transactions englobantes, ou interdire explicitement ce mode d'appel et préciser le contrat T-22. Ajouter tests d'échec du journal, disque plein/écriture refusée, suppression de l'ancien en échec après commit, rollback englobant et reprises de transaction. Pour le nettoyeur, tester aussi le fichier récent non référencé, qui doit être conservé.

**Rare et sans impact sur les tâches suivantes ?** Les pannes de disque sont rares ; les transactions et la réutilisation par T-22 sont normales. L'intégrité du stockage courant est une dépendance de T-21/T-22 ; pas un défaut à reporter comme sans impact.

### 3. Majeur — MIME et signature ne suffisent pas à assurer la validité PDF attendue par les tests

**Références :** plan §4, ligne 44, et §8, ligne 90 ; RG-CV-05, CV-01 et CDC §11.6.

**Nature : limite technique confirmée par un banc PHP 8.4.28 en mémoire.** Le service T-20 n'existe pas encore ; aucun téléversement applicatif ni contenu actif n'a été exécuté.

**Scénario :** présenter comme `fictif.pdf` un texte de **67 octets** commençant par `%PDF-1.7`, sans objets, pages ni table de références. `finfo(FILEINFO_MIME_TYPE)` renvoie **`application/pdf`**, et la signature est présente. Un second tampon avec suffixe PHP est également reconnu PDF. Les deux satisfont les critères MIME/signature/taille annoncés, tout en ne constituant pas des PDF exploitables.

Le cas « texte avec signature mais type faux » n'est donc pas systématiquement rejetable par les seuls contrôles listés. Le nom `.bin`, le chiffrement et le disque privé réduisent l'exposition, mais ne valident pas le document. L'OWASP distingue également reconnaissance de type/signature et contrôle du contenu. [Référence OWASP sur les téléversements](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html#file-signature-validation).

**Impact :** possibilité de déclarer « CV présent » pour un document inutilisable, ensuite transmis au parcours de consultation T-21. L'innocuité d'un vrai PDF contenant du JavaScript ou d'un polyglotte n'est pas démontrée non plus ; cela ne signifie pas qu'une exécution est possible dans le stockage privé de T-20.

**Correction proposée dans le plan :** distinguer reconnaissance du format, validation structurelle et éventuel traitement des contenus actifs. Définir un contrôle structurel adapté, borné en ressources, avec choix d'outil ou de bibliothèque validé avant d'ajouter une dépendance ; inclure le faux PDF ci-dessus et un PDF corrompu dans les tests. Faire trancher la politique concernant les PDF actifs/polyglottes et son passage de relais à T-21. Ne pas annoncer de garantie antivirus ou de neutralisation sur la seule base de `finfo` et de `%PDF-`.

**Rare et sans impact sur les tâches suivantes ?** Un fichier corrompu ou maquillé est une entrée à refuser ; fréquence inconnue. Le contrôle détermine les documents que T-21 présentera comme disponibles, donc correction du plan nécessaire avant développement.

### 4. Suggestion — Cadrer la réponse de dépassement et la tester au niveau HTTP réel

**Références :** plan §4, ligne 45, et §8. Référence existante : middleware Laravel `ValidatePostSize`.

**Risque hypothétique :** une conversion globale et inconditionnelle de `PostTooLargeException` vers le message « Le CV doit être un fichier PDF… » modifierait aussi les erreurs des imports XLSX ou des logos. Une requête refusée par le serveur web peut aussi ne jamais atteindre ce middleware.

**Correction proposée :** préciser la portée du message CV dans le gestionnaire d'exception, y compris lorsque la route n'est pas encore résolue ; garder un message adapté aux autres routes. Prévoir un essai HTTP réel pour 5 Mio, plus de 5 Mio et dépassement de la limite PHP/serveur, en plus des fichiers simulés Laravel. Vérifier la taille physique reçue plutôt que la taille annoncée par le client. Le manuel PHP précise que le dépassement de `post_max_size` peut vider les données POST et les fichiers. [Documentation PHP des limites POST](https://www.php.net/manual/en/ini.core.php#ini.post-max-size).

**Rare et sans impact sur les tâches suivantes ?** Cas d'erreur local au téléversement, avec risque de message trompeur sur des routes existantes ; précision de plan et tests de non-régression, sans nouvelle fonctionnalité.

## Points vérifiés sans remarque

- **Couverture métier :** ajout depuis la fiche, remplacement quelle que soit la source, retrait confirmé, une seule version courante et métadonnées auteur/source/date sont identifiés. L'historique traite aussi le retrait décidé le 26/11. Les reports de consultation à T-21 et d'import à T-22 respectent les décisions ; l'absence d'aperçu dans T-20 n'est pas signalée comme manque.
- **Droits :** `cv.upload` et `cv.history` existent pour administrateur et CME ; Direction conserve l'état sans ces actions. Le groupe de rôles proposé évite qu'entreprise et apprenant, qui possèdent également `cv.view`, accèdent à ces routes de gestion. Prévoir les tests de chaque route, y compris absence de session et exposition minimale de la ressource.
- **Données :** unicité de `learner_id`, disque privé, noms aléatoires, contenu chiffré, absence de nom d'origine, chemin et empreinte hors API : direction cohérente. Les snapshots d'auteur permettent de garder un libellé lisible après disparition du compte. Le remplacement devra mettre à jour la date et l'auteur du CV courant ; un test doit vérifier que `added_at` ne reste pas la date de la première insertion.
- **Mémoire et chiffrement :** dans un banc isolé, **5 242 880 octets** deviennent **9 320 872 octets** avec l'Encrypter Laravel installé en AES-256-CBC, soit environ **8,89 Mio**, ratio 1,778. Pic de mémoire du processus : environ **33,2 Mio** à l'issue du chiffrement, **38,2 Mio** pour l'aller-retour avec égalité vérifiée. Ces chiffres excluent le démarrage de l'application et la concurrence HTTP ; prévoir une mesure de requête réelle avec le budget du serveur cible. Ce n'est pas un motif pour imposer maintenant un autre système de chiffrement.
- **Configuration :** le conteneur inspecté indique PHP **8.4.28**, `upload_max_filesize=2M`, `post_max_size=8M`, `memory_limit=1G`. Le relèvement prévu à 6M est nécessaire pour admettre 5 Mio. Herd et le serveur cible n'ont pas été contrôlés. `APP_PREVIOUS_KEYS` est déjà pris en charge par `config/app.php` : tester la lecture avec une ancienne clé et conserver les clés nécessaires aux fichiers existants.
- **Recherche et maquettes :** état du CV dans la liste/fiche, écran CME, textes de remplacement, filtres et compteur sont couverts. Calculer `without_cv` sur les mêmes filtres et droits que la liste, avant pagination et sans son seul filtre CV, est cohérent. Les éléments d'import de la maquette sont explicitement laissés à T-22.
- **Frontend :** tester le verrou d'action commun par apprenant entre ligne et panneau, upload face à retrait, changement de page/filtre puis retour avant fin d'envoi, erreur puis nouveau choix du même fichier et nouvel essai. Après succès, liste, compteur, fiche et historique déjà ouvert doivent refléter le nouvel état. Les réponses tardives ne doivent pas réinstaller le résultat dans une autre ligne ou un autre panneau.
- **Découpage :** les quatre lots sont raisonnables. Le périmètre reste plausible avant le 25/12 en réutilisant l'existant, mais capacité et estimations ne sont pas fournies : aucune date de livraison ne peut être garantie. Verrouillage et validation PDF doivent être tranchés dans le premier lot, avant de construire les écrans ; ne pas absorber T-21/T-22 dans T-20.

## Questions au responsable technique

1. **Propositions 5 à 8 :** confirmer ou ajuster ces choix. Pour le même fichier, confirme-t-on la conservation de la source, de l'auteur et de la date actuels, sans nouvelle trace de téléversement, tout en gardant la trace « inchangé » des futurs imports T-22 ?
2. **Contrôle PDF :** quel niveau de validation structurelle doit être garanti, et quelle politique pour les PDF contenant du contenu actif ou les polyglottes ? Un outil supplémentaire peut-il être retenu si nécessaire ? Le stockage seul ne tranche pas les conditions de présentation de T-21.
3. **Apprenants supprimés :** conserver le 404 actuel pour la CME/Direction et réserver le 409 à l'administrateur pouvant accéder à la fiche ? L'historique CV d'une fiche supprimée doit-il rester réservé à cet administrateur ? Le plan doit l'expliciter.
4. **Durée de `cv_history` :** s'agit-il d'un historique métier conservé indépendamment des journaux, ou d'un journal à purger selon la conservation d'un an du CDC ? « Ajout seul » doit rester compatible avec une éventuelle purge autorisée ; aucun ancien fichier PDF ne doit être conservé dans les deux cas.

## Validation et limites

Revue documentaire et lecture du code existant uniquement, complétées par les bancs techniques en mémoire décrits : détection MIME/signature de tampons fictifs et chiffrement/déchiffrement d'une charge fictive de 5 Mio. Aucun PDF actif exécuté, aucun rendu PDF, aucun service T-20, test MySQL, migration ou suite applicative lancé. Les références techniques publiques consultées sont liées près des constats correspondants.

La présence de tests dans le plan ne prouve pas leur réussite. Cette revue ne constitue aucune approbation de code avant résultats locaux ; les scénarios de concurrence, d'échec du journal et du disque devront être exécutés sur l'implémentation.

## Verdict

**Plan à revoir.** Clarifier le verrouillage du compte, le cycle de vie des fichiers face aux transactions et le niveau réel de validation PDF. Les décisions déjà validées et le découpage T-20/T-21/T-22 sont respectés ; les propositions 5 à 8 restent à confirmer.
