# Revue indépendante du plan — T-11 (JD-16) : entreprises

- **Date :** 19/11/2026, Africa/Casablanca.
- **Branche :** `feature/T-11-entreprises`.
- **Base et HEAD :** `develop`, `bde6a2a70078930be9bb5038f807fd95ec54ce2d`.
- **Document examiné :** `docs/tasks/PLAN-T-11.md`, version 1 du 19/11/2026, non commitée.
- **Livrable :** ce rapport uniquement. Aucun code ni test écrit, aucun autre fichier modifié, aucun commit ni push.

## 1. Références et méthode

Lecture de `AGENTS.md`, `CLAUDE.md`, du diff avec `develop` et des fichiers non suivis concernés. Le diff suivi porte sur la décision du 19/11 dans `DECISIONS.md` ; le plan a été lu directement puisqu'il est non suivi.

Références examinées :

- CDC V2 : ADM-02, ADM-07, ENT-08, matrice 3.2, RG-ACC-01 à 06, modèle 8.2, PC-05 et TRA-01 ;
- décisions validées des 14/11, 16/11, 17/11 et 19/11 ; contrat T-10 et backlog des tâches consommatrices ;
- présentation `PRESENTATION_FONCTIONNELLE_JOBDATING_V2.pdf`, notamment pages 5 et 7 à 10 : recruteurs, profils recherchés, RG-ENTR-08 et droits ;
- les quatre maquettes demandées, ouvertes et examinées ;
- générateur de mots de passe, services de sessions et de jetons, service de comptes T-10, éligibilité des liens, rôles, matrice de permissions, référentiels, schéma `users` et mécanisme du journal existants.

Dans le PDF fourni, la **figure 4**, page 6, concerne la mise à jour d'un suivi ; la fiche entreprise avec profils et recruteurs est la **figure 5**, page 8. Les règles pertinentes ont été prises dans cette dernière et dans le texte associé.

Les décisions priment sur le CDC. Le remplacement de PC-14 est appliqué. Les reports validés et E-01 à E-03 ne sont pas remis en cause. Les exclusions demandées sont respectées.

Il s'agit d'une revue documentaire : **aucun contrôle applicatif ni test exécuté**. L'avis ne vaut pas approbation d'une implémentation future. `git diff --check develop` réussit.

## 2. Constats sur le plan

### 1 — À corriger avant développement : rattacher l'impression à la génération d'identifiants

**Références :** plan, lignes 54 à 61 et 97 ; RG-ENTR-07 ; filtre « Fiche non imprimée » de la maquette admin-03.

**Problème :** l'endpoint d'impression ne reçoit que l'identifiant de l'entreprise, alors qu'une réinitialisation remet `credentials_printed_at` à nul pour une **nouvelle** fiche. Il ne permet pas de distinguer une impression de l'ancienne fiche d'une impression de la nouvelle.

**Scénario prévisible :** A garde ouverte la fiche contenant M0. B réinitialise vers M1 : le statut devient « non imprimée ». A imprime ensuite sa vieille fiche et appelle `/credentials-printed`. Le contrat décrit permet de marquer la fiche comme imprimée alors que celle de M1 ne l'a jamais été.

**Impact :** statut et journal trompeurs ; risque de distribuer une fiche dont le mot de passe ne fonctionne plus. Il s'agit d'une lacune confirmée du contrat décrit, pas d'un bug exécuté.

**Correction dans le plan :** associer chaque émission d'identifiants à une version opaque, sans mot de passe ni hash. La réponse de création/réinitialisation et la confirmation d'impression portent cette version ; le serveur vérifie sous verrou qu'elle est encore courante, sinon refuse et demande une nouvelle fiche. Ajouter le test « ancienne fiche imprimée après réinitialisation » et le cas d'une requête d'impression retardée.

### 2 — À corriger avant développement : préciser l'atomicité du logo et de la création

**Références :** plan, lignes 54 et 67 ; compte et fiche associés d'ADM-02 ; validation du logo et traçabilité.

**Problème :** la création annonce une « transaction unique » incluant le logo, mais une transaction SQL ne restaure pas un fichier. Le plan précise la suppression de l'ancien logo après commit, sans définir le nettoyage du nouveau sur rollback ni le transport du logo initial : création avec fichier ou second appel à l'endpoint dédié.

**Scénarios prévisibles :** fichier enregistré puis collision de nom d'utilisateur ou échec du journal ; ou compte validé, suivi d'un second appel logo qui échoue alors que l'affichage unique des identifiants dépend encore du parcours de création.

**Impact :** fichiers publics orphelins ou création partielle annoncée en échec ; le résultat visible et la récupération des identifiants deviennent ambigus. Risque de conception, non reproduit puisqu'il n'y a pas de code T-11.

**Correction dans le plan :** choisir le contrat du logo initial et décrire les compensations : validation avant effet durable, suppression du nouveau fichier si l'opération échoue, conservation de l'ancien jusqu'au commit, gestion d'un échec de stockage. Appliquer les mêmes limites à la création et au remplacement. Ajouter des tests d'échec SQL/audit après stockage, d'échec de stockage et de remplacement concurrent avec relecture de la fiche verrouillée. Aucun mécanisme d'export ou de stockage supplémentaire n'est demandé.

### 3 — À corriger avant développement : garantir une proposition de nom d'utilisateur valide

**Références :** plan, lignes 47 à 49 et 136 ; RG-ENTR-04.

**Problème :** la simplification et le suffixe sont décrits, mais pas leur adaptation à la contrainte de **3 à 50 caractères**. Le repli `entreprise` ne couvre que les noms sans lettre ni chiffre.

**Exemples :** `HP` donne `hp`, trop court ; un nom de 150 lettres peut produire un identifiant trop long ; une base de 50 caractères devient trop longue avec `-2`. La possibilité de corriger manuellement le champ ne garantit pas une proposition automatique utilisable.

**Impact :** propositions refusées par la validation, y compris la nouvelle proposition renvoyée après collision.

**Correction dans le plan :** définir un repli pour les bases trop courtes, une troncature propre et la réservation de place pour le suffixe. Toute proposition doit respecter le format et la longueur, sans promettre une réservation concurrente. Compléter les cas prévus par noms de 1/2 caractères, nom long et suffixes à plusieurs chiffres.

### 4 — Suggestion : expliciter le contrat de mise à jour des références inactives

**Références :** plan, lignes 43, 72 et 137 ; décisions du 16/11 sur les référentiels et la conservation des associations.

**Problème :** le résultat attendu est correct, mais le comportement d'un `PUT` complet mérite une règle explicite : seules les nouvelles associations doivent exiger une valeur active ; une association existante inactive doit pouvoir rester dans la fiche sans faire échouer toute la validation.

**Scénario :** une compétence associée devient inactive ; la CME ajoute une autre compétence et renvoie la sélection complète. Une validation « tous les identifiants doivent être actifs » refuserait cette modification ; une synchronisation avec les seules options actives pourrait supprimer l'association ancienne.

**Impact :** rejet d'une modification légitime ou perte involontaire d'association, selon l'implémentation choisie.

**Correction dans le plan :** distinguer nouvelle association et association conservée, ainsi que retrait volontaire et disparition des options actives. Préciser aussi le maintien d'un secteur devenu inactif lors d'une modification sans changement de secteur. Ajouter ces cas au résumé des tests.

### 5 — Suggestion : préciser l'identité technique du compte `users`

**Références :** plan, lignes 36 à 42 et 54 ; migration existante `2026_11_14_090000_update_users_table_for_authentication.php:32–34` ; `CurrentUserResource` et `AuditLogger`.

**Problème :** le plan décrit les champs d'entreprise et les identifiants, mais pas le remplissage de `users.first_name` et `users.last_name`, obligatoires et limités à 100 caractères. Le nom d'entreprise peut en comporter 150. Ces champs servent actuellement à l'identité affichée et aux libellés d'audit.

**Impact :** une traduction littérale du plan ne suffit pas à insérer le compte, et une copie du nom complet sans adaptation peut dépasser la limite. Ce n'est pas un défaut du code existant.

**Correction dans le plan :** préciser le remplissage compatible avec le schéma, l'identité rendue à la connexion et le libellé du sujet entreprise dans l'audit, sans transformer le contact en compte personnel. Couvrir le cas d'un nom de 150 caractères. Une refonte générale du modèle `User` n'est pas nécessaire.

## 3. Avis sur H1 à H4

| Hypothèse | Avis argumenté |
|---|---|
| **H1 — Identifiant définitif après création** | **Favorable.** RG-ENTR-04 exige sa modification avant validation, sans imposer un changement ultérieur. Cela stabilise les fiches distribuées. Le renommage de l'entreprise doit rester indépendant de cet identifiant. |
| **H2 — Désactivation sans suppression** | **Déjà tranchée**, décision technique du 19/11, réponse 5. Cohérente avec la conservation des passages et la séparation entre compte et participation à un événement. La présenter comme décision acquise, pas comme question encore ouverte. |
| **H3 — Secteur facultatif** | **Favorable.** Le CDC exige explicitement le nom et impose le référentiel lorsqu'un secteur est renseigné ; il n'énonce pas explicitement l'obligation d'en choisir un à la création. `NULL` est cohérent. Prévoir l'affichage « Non renseigné » et transmettre ce cas aux futurs filtres/statistiques, sans créer de secteur fictif. |
| **H4 — Identifiant visible par la CME** | **Favorable.** La lecture de la liste et l'aide aux entreprises sont validées. Le nom d'utilisateur seul n'est pas le mot de passe. Cette lecture n'accorde ni réinitialisation, ni impression des identifiants secrets, ni modification du compte. Les ressources CME doivent exclure systématiquement `credentials`. |

## 4. Questions au responsable technique

1. Pour « Fiche imprimée », faut-il enregistrer le **lancement de l'impression navigateur** ou une **confirmation de l'administrateur après impression** ? Le navigateur ne permet pas de certifier une impression physique réussie. Préciser le sens du statut et du libellé d'audit ; dans les deux cas, conserver la vérification de version du constat 1.

Les autres corrections proposées relèvent de la précision technique du plan, sans remettre en cause les décisions validées.

## 5. Points validés sans remarque et dépendances

**Couverture fonctionnelle.** Création du compte et de la fiche, identifiants générés et affichés une fois, absence d'e-mail et de changement imposé, modification, logo, réinitialisation et désactivation sont couverts. Le profil limité à description/site web suit la réponse du 19/11 et la maquette entreprise-08 ; l'écart avec l'ENT-08 plus large du CDC est donc volontaire et accepté.

**T-14.** Le report de l'événement et des emplacements est accepté. Le modèle ne place ni événement ni emplacement dans `companies` : T-14 peut ajouter `event_companies`, son unicité `(event_id, company_id)` et étage/table facultatifs sans refonte. La connexion actuelle vérifie le compte et son activation, pas une participation : une entreprise créée dans T-11 peut se connecter sans événement. Cela ne lui accorde aucun accès aux apprenants d'un événement.

Documenter dans le passage de relais à T-14 que les entreprises déjà créées, dont celles de démonstration, doivent pouvoir être ajoutées à leur première participation, même sans édition précédente. Jusqu'à T-14, le critère « apparaît parmi les participants » reste différé, pas déclaré satisfait. Les données de démonstration ne doivent pas rendre la création métier dépendante d'un événement fictif.

**Profils et suggestions.** Les pivots globaux par entreprise conviennent à RG-ENTR-08 et à T-40. Les compétences s'ajoutent aux filières conformément au 16/11. Le bandeau est fidèle. T-40 devra combiner participation à l'événement et correspondance **filière OU compétence**, sans restriction des rencontres ; une compétence inactive ne compte plus. Aucune suggestion n'est à développer maintenant.

**Recruteurs.** Les champs sont compatibles avec l'évolution 1 : nom, e-mail et téléphone, fonction facultative, rattachement à l'entreprise. Lecture seule avant T-31 et confidentialité vis-à-vis des apprenants : correct. Expliciter les clés étrangères des pivots et de `company_recruiters` dans les migrations. La Direction et l'entreprise retrouveront leur droit de consultation par les ressources futures autorisées ; cela ne justifie pas d'ouvrir dès maintenant l'écran de gestion à ces rôles.

**Droits.** Les deux permissions sont cohérentes avec la décision du 19/11 et la matrice V2. Dans l'ordre du test existant `[Admin, CME, Entreprise, Apprenant, Direction]` :

| Permission ajoutée | Ligne attendue |
|---|---|
| `companies.manage` | `[oui, non, non, non, non]` |
| `companies.profiles.manage` | `[oui, oui, non, non, non]` |

Conserver les lignes attendues indépendantes de `Role::permissions()` et relancer le seeder versionné. `companies.view` garde ses droits existants et ses futurs périmètres. `PUT /me/company` doit utiliser la société du compte authentifié, sans faire confiance à un `company_id` reçu ; la liste blanche description/site web est bien prévue.

**Services de mots de passe.** `PasswordGenerator`, `SessionRevoker::closeAll()` et `revokeAll()` sont réutilisables. La réinitialisation administrative doit employer `closeAll()`, pas `afterPasswordChange()`, qui conserve les sessions Entreprise lors d'un changement personnel. Ne pas recopier l'éligibilité des liens ni la notification de T-10 : `PasswordAccess::canReset()` exclut volontairement les entreprises et exige un e-mail. Le service dédié prévu par T-11 permet cette séparation.

**Logo et journal.** Formats raster, SVG exclu, limite de taille et de dimensions, validation du contenu, nom aléatoire et stockage public sont adaptés à un logo. La catégorie « Entreprises » porterait bien le nombre de catégories de 7 à 8 ; l'écran existant lit les catégories depuis l'API. Ajouter aux vérifications prévues son filtre et le libellé du sujet entreprise. Les événements de compte restent dans leur catégorie existante, sans duplication inutile ni secret dans les valeurs.

**Tests futurs.** Le résumé couvre les parcours essentiels. Le compléter avec les scénarios des constats, un rollback sans compte/fiche/pivot/audit partiel, deux clients Entreprise lors d'un reset administratif, et une tentative de modification de l'entreprise B par A ou d'envoi de champs hors liste blanche. Vérifier également l'absence du secret dans les caches de query **et** de mutation, puis après fermeture de la fiche. Import, export et création des recruteurs restent bien différés.

## 6. Verdict

**Plan à revoir.**

Le périmètre et les décisions métier sont cohérents. Avant développement, préciser la version des fiches de connexion, le comportement transactionnel du logo et la normalisation des propositions d'identifiant. Les suggestions restantes peuvent être intégrées lors de cette mise à jour du plan, sans ajouter de fonctionnalité hors périmètre.
