# Revue du plan — T-12 (JD-17), apprenants

- **Date :** 19/11/2026.
- **Branche :** `feature/T-12-apprenants`.
- **Base :** `develop`, `d6cb56f66417bd882ef521f55c897347411768a7`.
- **Document examiné :** `docs/tasks/PLAN-T-12.md`, version 1, et `docs/tasks/CURRENT_TASK.md`.
- **Verdict : Plan à revoir.** Les parcours principaux sont couverts, mais les points de conception majeurs ci-dessous doivent être précisés avant le développement.

## Références et périmètre

Lecture de `AGENTS.md`, `CLAUDE.md`, du diff documentaire par rapport à `develop` et des documents non suivis concernés. Aucun code de T-12 n’est présent dans le changement examiné.

Références croisées :

- CDC V2 : ADM-03, ADM-04, matrice 3.2, RG-ACC, RG-AUTH-12, modèle 8.2, TRA-01, EXI-DATA-03, PC-15 et PC-22 ;
- `docs/DECISIONS.md` : décisions applicables du 14/11, du 16/11 et les huit réponses du 19/11 ; COMP-01 est bien tranché pour la saisie manuelle et en masse ;
- maquettes `admin-06-apprenants.png` et `admin-09-ajout-apprenant.png`, consultées ;
- code existant : permissions et rôles, actions et catégories d’audit, `AuditLogger`, `StaffAccountService`, `CompanyService`, `SessionRevoker`, `GeneratedPasswordNotification`, migrations des référentiels, routes et navigation React ;
- compléments nécessaires à la faisabilité : `ClassUsage`, son implémentation provisoire et son branchement, `ReferentialService`, contrat du plan T-02, `PasswordTokenService`, `PasswordAccess`, observateur des comptes et seeder de démonstration ;
- backlog pour les relais T-13, T-14, T-20 à T-24 et T-40. E-01 à E-04 ont été lus et ne sont pas rouverts.

Les décisions validées priment sur les passages contradictoires du CDC et des maquettes. Cette revue ne remet notamment pas en cause le mot de passe envoyé par e-mail, l’absence de changement obligatoire à la première connexion, le masquage de la CNIE dans la liste ou les reports validés.

## Constats

### 1. Majeur — Le raccord obligatoire au comptage des classes manque

- **Section du plan :** §1 et §3 du périmètre, « Passages de relais » et « Tests » ; notamment `PLAN-T-12.md:53`, `:55`, `:56` et `:119`.
- **Nature :** omission confirmée du plan ; conséquence future si ce raccord est oublié, pas un bug T-12 déjà exécuté.
- **Référence :** décision Référentiels du 16/11 ; contrat T-02 « Filière d’une classe » et « Coordination des contrôles d’utilisation ». `backend/app/Contracts/ClassUsage.php:7` impose explicitement le remplacement de l’implémentation par T-12 et le verrou commun lors d’une affectation. `AppServiceProvider.php:29` branche encore `NoClassUsage`, qui renvoie toujours zéro ; `ReferentialService.php:72` utilise ce résultat pour décider du refus 409.
- **Scénario et impact :** créer un apprenant dans une classe, puis changer la filière de cette classe sans confirmation. Sans remplacement du branchement, T-02 continue à considérer la classe comme inutilisée : la filière de l’apprenant change sans l’avertissement validé. Un simple verrou sur la fiche ou le compte ne remplace pas le protocole commun sur la classe.
- **Correction proposée dans le plan :** ajouter l’implémentation réelle de `ClassUsage`, son branchement et le périmètre des fiches comptées. Prévoir le verrou de la classe lors des affectations dans `create` et lors d’un changement de classe dans `update`, avec un ordre de verrouillage documenté et réutilisé par T-13. Ajouter un test d’intégration avec de vraies fiches : 409 sans confirmation, acceptation avec confirmation et nombre correct ; prévoir aussi le test de concurrence MySQL annoncé par T-02.

### 2. Majeur — La recherche par CNIE peut contourner le masquage du journal via les filtres

- **Section du plan :** §3, §4, §5 et H5 ; `PLAN-T-12.md:59`, `:62`, `:68`, `:88` et `:115`.
- **Nature :** risque de conception étayé par le code existant ; aucune fuite T-12 n’a été reproduite puisqu’il n’existe pas encore de code.
- **Référence :** RG-APR-06, EXI-DATA-03 et décision de masquage du 16/11. `AuditLogger.php:121` masque les chaînes sous une clé `cnie` ; il ne reconnaît pas une CNIE placée dans une recherche générique.
- **Scénario et impact :** l’administrateur recherche une CNIE fictive exacte, puis lance une action en masse par filtre. Si la synthèse copie les filtres, une valeur comme `filters.search = AB123456` reste en clair : la clé `search` n’est pas masquée par `AuditLogger`. Le plan prévoit à la fois la conservation des filtres et la protection de la CNIE, sans résoudre ce croisement.
- **Correction proposée dans le plan :** définir la représentation autorisée des filtres dans les synthèses. Omettre la recherche libre, ou séparer explicitement la recherche CNIE et la transmettre sous une clé traitée par le masquage ; ne pas recopier les filtres bruts dans le journal. Prévoir le même contrôle pour les deux actions en masse. Compléter les tests d’absence de CNIE en clair dans l’audit, les erreurs de validation et les collisions SQL, y compris en modification. Préciser également comment une recherche CNIE transmise en paramètres GET sera exclue des éventuels journaux techniques d’URL : le masque du journal d’audit ne protège pas ceux-ci.

### 3. Majeur — Le changement d’e-mail ne prévoit pas l’annulation des anciens liens

- **Section du plan :** §3, `update`, et relais T-13 ; `PLAN-T-12.md:56` et `:119`.
- **Nature :** omission confirmée ; risque de sécurité conditionnel lors de l’implémentation.
- **Référence :** ADM-03 permet la correction de l’e-mail professionnel, également identifiant et destination des liens. Dans le service existant, `PasswordTokenService.php:43`, `:57` et `:78`, le lien est associé au `user_id`. Sa validité ne dépend pas de l’adresse à laquelle il a été envoyé. La révocation n’est prévue dans le plan qu’en réinitialisation ou désactivation.
- **Scénario et impact :** un lien « Mot de passe oublié » valable a été envoyé à une adresse erronée ; l’administrateur corrige ensuite l’e-mail. Sans révocation, le destinataire de l’ancienne adresse peut encore consommer son lien et changer le mot de passe du compte corrigé, pendant la durée de validité restante. Le même risque concerne une correction par import T-13.
- **Correction proposée dans le plan :** quand l’e-mail change effectivement, annuler les liens de réinitialisation en attente dans la même transaction, sous le verrou du compte. Faire hériter T-13 de cette règle. Ajouter le scénario « ancien lien → correction d’e-mail → ancien lien refusé », et vérifier qu’une modification sans changement d’adresse n’annule pas inutilement les liens.

### 4. Majeur — Le comptage et le renouvellement de confirmation en masse restent ambigus

- **Section du plan :** §3, §4 et §6 ; `PLAN-T-12.md:59`, `:62`, `:69` et `:93`.
- **Nature :** contrat incomplet confirmé ; scénarios d’échec potentiels.
- **Problème :** `/learners/count` compte les apprenants correspondant aux filtres, tandis que `bulkDeactivate` limite l’ensemble aux actifs non supprimés. La liste permet aussi de sélectionner des désactivés et, pour l’administrateur, des supprimés. `bulkAddCompetence` reprend la même sélection et le même recomptage sans distinguer nombre sélectionné, nombre éligible et nombre réellement modifié. Le frontend ne décrit pas sa reprise après 409 ni le devenir de la sélection lorsque les filtres changent.
- **Scénarios et impact :** sélectionner les dix résultats du filtre « Désactivés » donne potentiellement un compte attendu de dix, puis un ensemble éligible de zéro. Confirmer à nouveau sans modifier le contrat ne résout pas ce désaccord. Autre cas : sélectionner tout un filtre, changer de promotion et conserver le même nombre de résultats ; le recomptage seul ne détecte pas que l’utilisateur agit désormais sur une autre sélection. Risques : confirmation trompeuse, boucle de 409 ou action sur un groupe différent de celui annoncé.
- **Correction proposée dans le plan :** définir une sélection et un comptage communs à la liste, au bouton « tout le filtre » et à chaque action. Rendre explicite le traitement des désactivés/supprimés et des compétences déjà associées ; distinguer, si nécessaire, éligibles et réellement modifiés. Au changement de recherche ou de filtre, vider la sélection ou conserver clairement un filtre figé. Après 409, afficher le nouveau nombre et exiger une nouvelle action explicite de confirmation, sans relance automatique. Le contrôle final du nombre et les écritures doivent porter sur le même ensemble verrouillé ; aucun changement ni journal en cas de refus. Tester les modes `ids` et `filters`, les sélections mixtes, le changement de filtre et le parcours 409 → nouvelle confirmation → succès.

### 5. Mineur — Les référentiels inactifs sont couverts côté règle, pas complètement côté écran

- **Section du plan :** §3, §4 et §6 ; `PLAN-T-12.md:56`, `:83` et `:92`.
- **Nature :** précision manquante du contrat de lecture ; pas une perte de valeur déjà constatée.
- **Référence et scénario :** T-02 distingue options actives de saisie, valeur liée même inactive et options historiques `?inactives=1`. Le plan T-12 autorise bien la conservation d’une valeur inactive, mais ne reprend pas ces trois usages. Un formulaire alimenté uniquement par les options actives peut perdre l’option courante ; un filtre « Promotion » peut ne plus proposer une promotion désactivée alors qu’il faut encore retrouver ses apprenants.
- **Impact :** affichage ou filtrage historique incomplet et risque de remplacement involontaire d’une ancienne valeur.
- **Correction proposée dans le plan :** préciser que campus, promotion, classe et filière liés sont renvoyés avec identifiant, libellé et état actif, même inactifs ; réinjecter l’option courante inactive dans le formulaire ; utiliser les options historiques pour les filtres. Tester la modification d’un autre champ sans perte de l’association, puis le filtrage sur une ancienne promotion/classe désactivée. La règle « nouvelle valeur active exigée » reste inchangée.

### 6. Mineur — La notification réutilisée annonce une réinitialisation lors d’une création

- **Section du plan :** références réutilisées et §3, `create` ; `PLAN-T-12.md:16` et `:55`.
- **Nature :** incompatibilité de contenu confirmée dans la classe existante ; pas un e-mail T-12 déjà envoyé.
- **Scénario et référence :** `GeneratedPasswordNotification.php:40` annonce « L’administration a réinitialisé votre mot de passe » ; la ligne 43 annonce la fermeture des sessions. Réutilisée sans adaptation pour la création, elle n’explique pas l’ouverture du nouveau compte.
- **Impact :** message trompeur pour un apprenant qui reçoit son premier accès.
- **Correction proposée dans le plan :** prévoir un contenu de création distinct du contenu de réinitialisation, en conservant le chiffrement de la tâche et l’envoi après validation finale de la transaction. Tester le texte des deux cas ; aucun changement du mode d’envoi validé n’est demandé.

## Avis sur les hypothèses H1 à H5

| Hypothèse | Avis |
|---|---|
| **H1 — Normalisation, 5 à 30 caractères alphanumériques** | Normalisation unique pertinente pour la saisie, la recherche et l’import. Centraliser le contrat et conserver l’identifiant comme chaîne, notamment les zéros initiaux. Le CDC accepte aussi passeport/carte de séjour mais ne fixe pas leur format : faire confirmer les bornes et caractères admis sur des exemples fictifs représentatifs avant de figer le rejet. Ne pas retirer silencieusement d’autres caractères que les espaces prévus. |
| **H2 — 2 000 comptes par action** | Limite applicative raisonnable à proposer, mais sa faisabilité en une requête n’est pas démontrée. L’appliquer côté serveur aux deux actions et préciser la réponse si elle est dépassée. Prévoir, après implémentation, une mesure sur MySQL avec révocation des sessions et audit par compte, ainsi qu’un test de dépassement et d’annulation complète. Verrouiller les comptes dans un ordre stable ; éviter les appels réseau dans la transaction. Ne pas introduire une file ou un découpage transactionnel sans décision justifiée par la mesure. |
| **H3 — Catégorie « Apprenants »** | Favorable. Séparer fiches/compétences de la catégorie CNIE existante et garder les actions de compte dans leur catégorie. Mettre à jour le filtre et ses tests ; préciser aussi l’action de synthèse de l’ajout de compétence en masse, absente de la liste explicite du §5. |
| **H4 — Direction en lecture seule, sans CNIE/téléphone ni sélection** | Conforme à la matrice et déjà validée par la décision 7 du 19/11 : ce n’est plus un arbitrage ouvert. Aucune sélection ni action d’écriture ; la CME conserve uniquement l’exception de saisie des compétences décidée. |
| **H5 — Recherche CNIE exacte, administrateur seul** | Favorable. Utiliser la même normalisation que H1, appliquer la restriction côté serveur et aux requêtes de comptage/masse, sans accorder cette recherche via le seul droit `learners.view`. Corriger le traitement des filtres journalisés décrit au constat 2. |

## Points vérifiés sans remarque

### Couverture d’ADM-03 et reports

| Exigence | Traitement prévu |
|---|---|
| Création manuelle, identité et mot de passe | Prévue ; affichage unique et e-mail conformes aux décisions du 14/11 et du 19/11. Pas de changement obligatoire à la première connexion. |
| Modification de toutes les informations | Champs demandés présents ; unicités conservées, y compris pour les comptes supprimés. Complément de sécurité demandé au constat 3. |
| Réinitialisation administrateur | Ancien mot de passe remplacé, liens annulés, toutes les sessions fermées, e-mail après validation, réponse `no-store`, refus sur compte désactivé/supprimé et audit prévus. |
| Liste, recherche, filtres, pagination | Couverts pour les rôles autorisés ; précisions requises aux constats 2, 4 et 5. |
| Désactivation en masse | Présente avec confirmation et recomptage, sans réactivation en masse ; contrat à compléter au constat 4. |
| Suppression et restauration | Suppression logique du compte, conservation de la fiche et des associations : conforme à la décision 2. |
| CV et état du CV | Téléversement/remplacement et état de fiche reportés à T-20, consultation à T-21 et import plateforme à T-22. Le report du critère d’affichage de l’état du CV est explicitement validé. |

- **RG-APR-01 :** clés vers les référentiels ; filière déduite de la classe, sans duplication. Campus/classe inexistants refusables par validation et contraintes. Le téléphone dans `users`, déjà présent, évite une seconde source de vérité.
- **RG-APR-02 :** identifiant CME facultatif et unique, plusieurs valeurs nulles permises. Préciser son type/sa longueur lors de la définition de migration ; le format de la future plateforme reste lié à PC-26.
- **RG-APR-03 et 04 :** aucun téléversement par l’apprenant ni réservation/pointage ajouté. Les CV sont reportés aux tâches prévues.
- **RG-APR-05 :** désactivation, révocation des sessions/liens et conservation de l’historique prévues. T-23 devra exclure les désactivés de la recherche entreprise ; T-14 portera les règles liées à l’événement courant.
- **RG-APR-06 :** CNIE obligatoire et unique, liste administrateur masquée, fiche complète avec consultation journalisée, absence pour CME et Direction ; restriction entreprise et apprenant par rôle en plus de la permission. La limitation T-12 est suffisante malgré `learners.view` détenu par l’entreprise : T-23 ajoutera sa route avec son propre périmètre, sans ouvrir `/learners`.
- **RG-APR-07 :** modification de fiche et réinitialisation réservées à l’administrateur ; nouvelle permission de compétences limitée à administrateur/CME, conformément à l’extension validée de la matrice. La mise à jour de `PermissionMatrixTest` est prévue.
- **Modèle :** unicités du compte et de la CNIE, clés étrangères, pivot à clé composée sans niveau et conservation après suppression logique cohérents. Les index annoncés correspondent aux principaux filtres. La restriction de suppression physique du compte est compatible avec la suppression logique choisie.
- **TRA-01 :** audit dans le cadre des transactions, champs modifiés avant/après, auteur et catégorie CNIE prévus. Les actions de compte existantes peuvent être réutilisées ; coordonner l’observateur et le service pour éviter de journaliser deux fois la même modification de compte.

### Passages de relais

- **T-13 :** la réutilisation des règles de normalisation, d’unicité et de service évite un second modèle d’apprenant. Ajouter explicitement les compléments des constats 1 et 3. L’import devra appliquer le statut actif/désactivé sans réinitialiser les comptes existants et ne déclencher les notifications qu’au commit de la transaction englobante ; une annulation de l’import ne doit envoyer aucun e-mail.
- **T-14 :** report validé de la participation ; les clés du modèle permettent l’ajout d’`event_learners` sans refonte. L’inscription initiale des actifs existants et celle des futurs ajouts sont annoncées.
- **T-20 à T-22 :** état du CV, téléversement et import restent identifiés. Conserver dans T-20 le détail du critère ADM-03 : présence, source, date et auteur.
- **T-23/T-24 :** routes entreprise distinctes, données minimales de PC-15 et contrôle par événement/rencontre à ajouter dans ces tâches ; aucune CNIE ni téléphone à réutiliser depuis la ressource d’administration.
- **T-40 :** filière via la classe et seules compétences actives pour les suggestions ; associations désactivées conservées. COMP-01 n’est plus ouvert pour le mode de saisie retenu ; la source plateforme reste une autre question liée à PC-26.

### Tests à conserver et à compléter

La matrice des cinq rôles, l’unicité, les référentiels inactifs, les collisions de création et la révocation après réinitialisation sont de bons axes. Outre les scénarios des constats, expliciter dans le plan :

- l’échec du journal au milieu d’une action en masse : aucun compte, lien, session ou pivot modifié après rollback ; mêmes garanties pour un refus 409 et un dépassement H2 ;
- le refus d’un mélange `ids`/`filters`, des identifiants invalides et des listes mal formées ; contrôle de la nouvelle permission sur chaque opération de compétences ;
- la concurrence entre changement de mot de passe, réinitialisation et désactivation, en relisant l’état sous le verrou du **compte**, pas uniquement de `learners` ;
- la suppression : fermeture des sessions et annulation des liens comme T-10 ; restauration sans réutilisation d’un ancien lien révoqué et avec conservation du statut antérieur de désactivation ;
- les enchaînements frontend : 422 → correction → nouvel essai, 409 → nouvelle confirmation, changement de filtre pendant une sélection, fermeture/changement de fiche et disparition du mot de passe affiché ; aucun secret dans le cache des requêtes **ou des mutations** ;
- le seeder : relance sans doublon et comportement explicite lorsque les listes officielles sont encore vides. Ne pas créer implicitement de valeurs officielles pour satisfaire les références de démonstration ; signaler les prérequis manquants ou ignorer proprement la fiche.

## Questions au responsable technique

1. **H1 :** les bornes de 5 à 30 caractères et l’alphabet alphanumérique conviennent-ils aussi aux passeports/cartes de séjour attendus ? Le CDC n’en fournit pas d’échantillon permettant de les valider.
2. **Actions de compétences :** la reprise de la sélection « actifs non supprimés » est-elle volontaire aussi pour l’ajout en masse de compétence, alors que l’ajout individuel n’annonce pas cette restriction ? Écrire le choix dans le contrat de comptage du constat 4.
3. **Comptage des classes :** le nombre affiché lors d’un changement de filière doit-il inclure les fiches désactivées et les comptes supprimés logiquement, dont la fiche reste conservée ? Fixer ce périmètre pour le raccord du constat 1.

Ces questions ne remettent pas en cause les huit réponses du 19/11. Aucun nouveau choix métier n’est nécessaire pour protéger les filtres journalisés ou annuler les anciens liens après correction d’e-mail.

## Validation et limites

Revue documentaire et lecture du code existant uniquement. **Aucun test applicatif, commande de migration, seeder ou contrôle de performance exécuté** : il n’y a pas d’implémentation T-12 à valider. Les commandes de `CURRENT_TASK.md` sont un programme de contrôles futurs, pas des résultats communiqués. Les scénarios ci-dessus sont des cas à prévoir, pas des reproductions d’un code T-12 inexistant.

Aucun fichier de code ou de test modifié, aucun commit ni push. Le présent rapport est le seul fichier créé. Cette revue du plan ne vaut pas approbation du futur code, qui devra être accompagnée des résultats locaux requis par `AGENTS.md`.

## Verdict

**Plan à revoir.** Corriger avant développement les quatre constats majeurs : raccord `ClassUsage`, confidentialité des filtres, invalidation des anciens liens lors d’un changement d’e-mail et contrat complet des actions en masse. Les deux précisions mineures et les hypothèses identifiées peuvent être traitées dans la même révision du plan.
