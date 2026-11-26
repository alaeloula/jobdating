# Plan – T-64 (JD-44) : import des entreprises par fichier

Branche à créer avant le code : `feature/T-64-import-entreprises` (base `develop`, `c3f0365`).

**Version 1.3** (25/11/2026) : corrections de la revue Codex (`docs/reviews/REVIEW-PLAN-T-64.md`) et de la contre-revue (`docs/reviews/REVIEW-PLAN-T-64-contre-revue.md`, « Plan à revoir » : longueur de la clé, ordre des verrous, variantes XML) ; développement autorisé par le responsable technique (25/11), la revue du code vérifiera ces corrections. **Aucun code n'est écrit.**

## Références

1. CDC V2 : **ADM-04, « Import des entreprises »** et exceptions communes ; ADM-02 (RG-ENTR-01 à 07) ; ADM-07 (RG-PAR-01, 02, 07) ; AUTH-03 ; TRA-01.
2. `docs/DECISIONS.md` : 14/11 (aucun e-mail aux entreprises, y compris à l'import) ; 20/11 « Import des apprenants (T-13) » ; 23/11 (T-14) ; 25/11 « Reprise des entreprises abandonnée (T-16) » ; 25/11 « Import des entreprises (T-64) » (décisions 1 à 8). 25/11 « Import des entreprises : fiches après l'import (T-64) » (décisions 9 et 10).
3. Réutilisation de T-13 : file `database-imports`, aperçu → confirmation (empreinte, clé idempotente) → traitement, mots de passe chiffrés, `imports:cleanup`, protections ZIP. Réutilisation de T-11 et T-14 : `UsernameSuggester`, `PasswordGenerator`, `CompanyService`, `EventParticipationService::lockEligibleEvent`, contrat de placement de la fiche.
4. Maquette `admin-03` : bouton « Importer (fichier) » ; `admin-07` et `admin-08` pour l'enchaînement (sans leurs e-mails ni leur téléchargement unique).

## 1. Décisions du responsable technique (25/11)

| # | Sujet | Décision |
|---|---|---|
| 1 | Fichier | Le **classeur de suivi de l'équipe CME, tel quel** (pas de modèle à télécharger). |
| 2 | Lignes importées | Feuille **« Entreprises Invitées »**, lignes dont **« Confirmation par mail » vaut « Oui »** (sans casse ni espaces). Les autres lignes sont ignorées. |
| 3 | Données reprises | **Seul le nom** (colonne « Entreprises »). Secteur, contact, description, logo et emplacement se complètent ensuite dans JobDating. Toutes les autres colonnes sont ignorées et jamais enregistrées. |
| 4 | Entreprise déjà existante | **Signalée seulement** : ni modifiée ni ajoutée à l'événement ; l'administrateur l'ajoute avec « Ajouter des entreprises existantes ». |
| 5 | Comparaison des noms | **Nom simplifié** : sans casse, accents, espaces ni ponctuation, y compris entre deux lignes du fichier. |
| 6 | Fiches de connexion | **Comme l'import des apprenants** : mots de passe chiffrés sur le serveur, fiches imprimables en une fois et plusieurs fois, jusqu'à leur suppression par l'administrateur. |
| 7 | Feuille « Informations entreprises Appli » | **Non utilisée** ; sa colonne « Mot de passe application jobdating » n'est **jamais** lue. |
| 8 | Doublon dans le fichier | La **première ligne confirmée et valide est créée**, les suivantes sont signalées. |
| 9 | Événement supprimé après l'import | L'historique est **conservé** ; les fiches restent imprimables **sans événement ni emplacement**, avec la mention « Événement supprimé ». La suppression d'un événement n'est jamais bloquée par un import. |
| 10 | Entreprise désactivée ou retirée depuis l'import | **Désactivée : fiche exclue** de l'impression (comptée). **Retirée de l'événement : fiche conservée**, sans emplacement, avec l'avertissement « Ne participe plus à l'événement ». |

## 2. Périmètre

**Inclus** : lecture sélective du classeur, aperçu, confirmation, traitement en arrière-plan, rapport, fiches imprimables en une fois, historique, journal ; clé de nom simplifié et verrou commun des noms d'entreprise.

**Exclus** : modèle de fichier ; autres colonnes ; seconde feuille ; mise à jour ou ajout automatique d'une entreprise existante ; e-mail ; interdiction des homonymes en création à la main.

## 3. Lecture sélective du classeur (`CompanyWorkbookReader`) — revue Codex, constat 3

OpenSpout décode **toutes** les chaînes partagées du classeur à l'ouverture, y compris celles des colonnes et feuilles exclues. Le lecteur T-64 ne l'utilise donc pas : c'est un **lecteur dédié en flux XML** (`ZipArchive` + `XMLReader`), sans dépendance nouvelle, et sans modifier le lecteur des apprenants.

**Frontière** : seuls sont décodés (a) les noms des feuilles, (b) les cellules de la **ligne 1** de la feuille ciblée (en-têtes), (c) les cellules des **deux colonnes utiles** des lignes suivantes. Aucune autre valeur n'est décodée, copiée ni écrite, en mémoire, sur disque ou dans un journal.

1. **Contrôles du fichier** (repris de T-13) : XLSX uniquement, 5 Mo, archive valide, 50 Mo décompressés, nombre d'entrées, chemins internes sûrs. Fichier vide ou illisible → 422 explicite.
2. **Feuille** : `xl/workbook.xml` et ses relations ; nom simplifié (§ 4) égal à « entreprisesinvitees ». Absente → « Feuille « Entreprises Invitées » introuvable » ; **deux feuilles** de même nom simplifié → fichier refusé (jamais de choix silencieux).
3. **En-têtes** (ligne 1) : colonnes « Entreprises » et « Confirmation par mail » repérées par nom simplifié. Absente → « Colonne … introuvable » ; **en double** → fichier refusé. Les autres en-têtes ne sont pas conservés.
4. **Premier passage sur la feuille** (flux) : pour chaque ligne, seules les cellules des deux colonnes utiles sont examinées ; pour une chaîne partagée, on ne garde que son **numéro** ; une chaîne en ligne est lue directement. Cellule avec formule ou de type non texte → « Valeur non admise » (sauf vide). Les cellules fusionnées ne propagent rien : une cellule vide reste vide.
5. **Passage sur `xl/sharedStrings.xml`** (flux) : seules les chaînes dont le numéro a été relevé sont décodées ; les autres sont sautées sans être lues ; arrêt au-delà de 50 000 entrées. Les **en-têtes** sont résolus de la même façon, **avant** les données (passage dédié : numéros des cellules de la ligne 1, puis décodage de ces seuls numéros). Texte riche : concaténation des fragments `<r><t>`, sans les annotations phonétiques ; chaînes en ligne (`inlineStr`) lues directement.
6. **Parties du classeur** : chemins résolus par les relations, normalisés, obligatoirement sous `xl/` ; cible externe (`TargetMode="External"`) ou chemin sortant refusés.
7. **Lignes** : une ligne est vide si ses **deux colonnes utiles** sont vides (aucune autre colonne n'est lue pour le décider). Au plus **1 000 lignes non vides** ; numéros de ligne réels.
8. Le fichier envoyé reste dans le dossier privé temporaire de T-13, supprimé aussitôt.

## 4. Noms (`CompanyNameKey`) — constats 1 et 4

- **Clé de nom simplifié** : translittération ASCII, minuscules, puis **seuls les lettres et chiffres** sont conservés (« Société Générale », « societe-generale », « SOCIETE GENERALE » → `societegenerale` ; « 3M » → `3m`). Fonction unique, distincte du nom d'utilisateur.
- **Clé vide** (nom sans lettre ni chiffre, par exemple « ... ») → ligne **rejetée** : « Nom sans lettre ni chiffre ».
- Nouvelle colonne **`companies.name_key`** : **empreinte SHA-256 hexadécimale (64 caractères) du nom simplifié complet** (contre-revue, constat 1), jamais une troncature. Le nom simplifié vide est refusé **avant** le calcul. **Indexée, non unique** (homonymes permis à la main). Une seule fonction calcule la clé pour la migration des entreprises existantes, la création, le renommage et l'aperçu.
- **Verrou commun des noms** : une ligne unique dans `company_name_locks` (même principe que `event_locks`), prise **en premier**, en mode **exclusif**, par l'import (traitement) **et** par la création et le renommage à la main (T-11). La création à la main ne refuse pas un homonyme ; le verrou garantit seulement que la revalidation de l'import voit toute création ou tout renommage validé avant lui.
- **Ordre des verrous** (contre-revue, constat 2) :
  - **création** (à la main ou par l'import, lignes nouvelles) : noms → événement → compte → entreprise ;
  - **renommage** d'une entreprise existante : noms → **entreprise → compte** (ordre existant de `CompanyService::update`, jamais inversé) ;
  - **réinitialisation** et **marquage des fiches** : entreprise → compte, sans le verrou des noms ;
  - le verrou des noms n'est jamais pris après celui d'un événement, d'une entreprise ou d'un compte.

## 5. Aperçu (`CompanyImportPlanner`)

Pour chaque ligne non vide, dans l'ordre du fichier :

| Cas | Résultat |
|---|---|
| « Confirmation par mail » différent de « Oui » | **Non confirmée** : ignorée, comptée, ne réserve aucun nom |
| Nom vide ou plus de 150 caractères | **Rejetée** : « Nom manquant ou trop long » |
| Clé vide | **Rejetée** : « Nom sans lettre ni chiffre » |
| Même clé qu'une entreprise existante (désactivées comprises) | **Déjà existante** : « Ajoutez-la depuis l'événement » |
| Même clé qu'une ligne **déjà retenue** plus haut | **Doublon dans le fichier** |
| Sinon | **À créer** ; sa clé est alors retenue |

- Une ligne non confirmée ou rejetée **ne réserve pas** le nom : la ligne valide suivante est créée (décision 8).
- **Noms d'utilisateur** : `UsernameSuggester` reçoit la liste des noms déjà proposés dans le lot ; il les évite comme des comptes existants (supprimés compris), **après** troncature à 50 caractères (`acme`, `acme-2`…). Non modifiables dans l'aperçu.
- Événement choisi à l'écran (non archivé, non terminé) ; le courant est présélectionné.
- Empreinte : hachage du fichier, événement, et pour chaque ligne : numéro, résultat, motif, clé, nom d'utilisateur proposé.

## 6. Confirmation et traitement

- **Confirmation** : fichier relu, aperçu recalculé ; empreinte différente → 409 ; **clé idempotente** (même clé et même empreinte → même import ; même clé et autre empreinte → 409) ; rien à créer → 422. Mise en file **après** validation, message = identifiant seul ; échec de mise en file → import en échec, rien d'autre.
- **Table `company_imports`** : auteur, `event_id` (**`nullOnDelete`**, décision 9), nom de l'événement au moment de l'import, nom du fichier, clé, empreinte, statut, comptes, lignes signalées (numéro et motif seulement), `payload` (lignes à créer : numéro, nom, clé, nom d'utilisateur), identifiants chiffrés (§ 7), date de suppression, erreur, dates.
- **Traitement** (`CompanyImportRunner`, `ProcessCompanyImport` sur `database-imports`, file `imports`, une tentative, délai comme T-13) :
  1. prise en charge conditionnelle ; redélivrance d'un import déjà pris en charge sans effet ;
  2. mots de passe générés et **hachés hors transaction**, réutilisés par les reprises ;
  3. **une transaction** : verrou exclusif des noms → événement sous verrou partagé (non archivé, non terminé, sinon échec propre) → revalidation de chaque ligne sous les verrous (clé toujours libre, nom d'utilisateur toujours libre, comptes supprimés compris) ; sinon échec « Une entreprise ou un nom d'utilisateur a été pris entre-temps : relancez l'aperçu » ;
  4. pour chaque ligne : `CompanyService::importCreate` (compte entreprise activé, rôle unique, fiche avec `name_key`, participation, journal), sans logo ni nouveau mot de passe ;
  5. finalisation dans la même transaction : comptes, identifiants chiffrés, statut « terminé », `payload` effacé, journal ;
  6. interblocage → reprise complète (3 fois), sans double journal ni nouveau mot de passe ; erreurs expurgées ; aucun compte, fiche, participation ou secret partiel en cas d'échec.
- `imports:cleanup` traite aussi les imports d'entreprises abandonnés, avec la même condition atomique que T-13.

## 7. Fiches de connexion — constats 2 et 5, décisions 9 et 10

- **Contenu conservé (chiffré)** pour chaque entreprise créée : identifiant de l'entreprise et du compte, nom d'utilisateur, mot de passe, version des identifiants (1) et **hachage d'origine** du mot de passe.
- **Fiche valide** si : compte non désactivé (sinon **exclue**, décision 10), même version **et** hachage actuel du compte **identique** au hachage d'origine (sinon « Mot de passe changé ou réinitialisé depuis l'import », exclue). Une simple comparaison de chaînes, sans calcul de hachage.
- **Contenu imprimé** : nom, adresse de connexion, nom d'utilisateur, mot de passe, et pour l'événement de l'import : nom, date, lieu et **emplacement actuel** (contrat de placement de T-14) ; « Emplacement à confirmer sur place » s'il manque ; entreprise retirée → sans emplacement, avec « Ne participe plus à l'événement » ; événement supprimé → sans événement, avec « Événement supprimé » (décision 9). Jamais l'événement courant à la place de celui de l'import.
- **« Imprimer toutes les fiches »** : une fiche par page, sans les boutons de navigation ; résumé « N imprimables, M exclues (motifs) » ; journalisé.
- **« J'ai imprimé les fiches »** : dans une transaction, par entreprise dans l'ordre des identifiants, en respectant l'ordre existant entreprise → compte : re-contrôle de validité **sous verrous**, puis marquage de la version 1 ; une fiche devenue caduque entre-temps n'est pas marquée. Idempotent.
- **« Supprimer les mots de passe »** : définitif, atomique avec son journal.
- **Côté navigateur** : réponses `no-store` ; identifiants chargés hors cache TanStack et hors stockage persistant ; affichage effacé à la fermeture, au changement d'import ou de compte ; une réponse tardive ne réaffiche jamais des fiches effacées.

## 8. API (`/api/v1`, permission `companies.manage`, contrôlée sur chaque route)

| Méthode et route | Rôle |
|---|---|
| `POST /company-imports/preview` | aperçu (fichier, `event_id`) |
| `POST /company-imports` | confirmation (fichier, `event_id`, empreinte, clé) |
| `GET /company-imports`, `GET /company-imports/{id}` | historique paginé, rapport (sans secret) |
| `GET /company-imports/{id}/credentials` | fiches valides et exclusions (`no-store`, journalisé) |
| `POST /company-imports/{id}/credentials-printed` | « J'ai imprimé les fiches » |
| `DELETE /company-imports/{id}/credentials` | suppression des mots de passe |

Liste blanche stricte : nom, nom d'utilisateur, numéro de ligne, motif. Aucune autre valeur du classeur dans la base, le `payload`, le journal, les exceptions ou les réponses.

## 9. Journal d'audit

`company_import.confirmed`, `completed`, `failed`, `credentials_viewed`, `credentials_printed`, `credentials_deleted` (catégorie « Imports et exports ») ; `company.created` et `event_company.added` pour chaque entreprise créée.

## 10. Frontend

- Bouton **« Importer (fichier) »** dans « Entreprises participantes » vers `/entreprises/import` (administrateur).
- Écran : événement, dépôt du classeur, aperçu (comptes, tableau des noms à créer avec nom d'utilisateur, lignes signalées), confirmation, suivi, rapport, impression des fiches, suppression des mots de passe, historique paginé. Les noms de feuille et de colonnes attendus sont affichés.
- Aperçu effacé à tout changement (fichier, événement) ; réponses tardives ignorées ; double clic bloqué ; même clé renvoyée après une erreur réseau ; 409 → nouvel aperçu ; séquences « erreur → correction → nouvel essai » ; reprise après échec de chargement ou d'impression.

## 11. Tests

**Backend (MySQL), classeurs fictifs générés dans les tests**

- Lecture : feuille absente, renommée ou en double après simplification ; en-têtes absents, en double ou déplacés ; « Oui » sans casse ni espaces ; non confirmées ; formule ou valeur non texte ; cellules fusionnées ; lignes vides intercalées et numéros réels ; limites ; fichier vide ou non XLSX.
- **Sentinelles** dans une colonne exclue (chaîne partagée et chaîne en ligne), dans la colonne « Mot de passe » de la seconde feuille et dans une chaîne partagée non utilisée : jamais décodées (contrôle du lecteur lui-même), ni en base, journal, réponses ou fichiers temporaires.
- Noms : variantes de casse, accents, espaces, ponctuation ; nom numérique ; clé vide ; nom de plus de 64 caractères, 150 caractères, préfixe commun de 64 caractères ; remplissage de `name_key` des entreprises existantes ; première occurrence non confirmée ou rejetée suivie d'une occurrence valide ; noms d'utilisateur `acme` / `acme-2` et troncature à 50 caractères ; compte supprimé.
- Confirmation : empreinte et 409 ; même clé deux fois en même temps ; même clé et autre empreinte ; événement archivé ou supprimé avant le traitement.
- Traitement : échec au milieu du lot et échec du journal sans rien de partiel ; échec après 3 reprises ; redélivrance ; nettoyage d'un import qui vient de démarrer ; collision de nom d'utilisateur au dernier moment.
- **Concurrence à deux connexions MySQL** (banc de T-14, preuve d'attente de verrou) : import contre import avec des noms qui ne diffèrent que par la ponctuation ; import contre création à la main ; import contre renommage ; renommage contre réinitialisation ; renommage contre marquage des fiches.
- Lecteur : en-têtes en chaînes partagées, texte riche en plusieurs fragments, chaîne en ligne équivalente, relation externe ou chemin sortant refusés.
- Fiches : changement de mot de passe par l'entreprise, réinitialisation par l'administrateur, réinitialisation pendant « J'ai imprimé » ; compte désactivé (exclu) ; entreprise retirée (avertissement) ; événement supprimé (historique conservé, mention) ; emplacement ajouté après l'import ; marquage idempotent ; suppression définitive.
- Droits des 5 rôles sur chaque route ; journal.
- **Mesure** au plafond de 1 000 lignes avec le coût de hachage réel et le délai du worker, **durée de détention du verrou des noms** et attente d'une création à la main pendant ce temps.

**Frontend** : parcours complet ; erreurs et nouvel essai ; réponses tardives ; double clic ; changement d'événement pendant l'aperçu ; fiches jamais en cache, effacées à la fermeture ; impression de plusieurs fiches.

## 12. `DECISIONS.md`

Décisions 9 et 10 inscrites le 25/11/2026, texte ci-dessous.

> **25/11/2026 — Import des entreprises : fiches après l'import (T-64)** : si l'événement d'un import est supprimé, l'historique de l'import est conservé et ses fiches restent imprimables sans événement ni emplacement, avec la mention « Événement supprimé » ; la suppression d'un événement n'est jamais bloquée par un import. À l'impression groupée, la fiche d'une entreprise **désactivée** est exclue ; celle d'une entreprise **retirée de l'événement** est conservée, sans emplacement, avec l'avertissement « Ne participe plus à l'événement ». Une fiche dont le mot de passe a été changé ou réinitialisé depuis l'import est exclue.

## 13. Découpage (4 lots revus ensemble)

1. Lecteur sélectif, clé de nom, planification, classeurs fictifs et sentinelles.
2. Verrou des noms, `name_key`, traitement atomique, concurrence.
3. Cycle des fiches et API.
4. Interface et impression.

## 14. Points signalés

- **Écart au CDC** (ADM-04) : pas de modèle téléchargeable (décision 1).
- **Doublon dans le fichier** : règle différente de T-13 (décision 8).
- **Création à la main** : elle prend désormais le verrou des noms et renseigne `name_key`, mais n'interdit pas les homonymes ; seul l'import les signale.
- **Dépendance au classeur** : feuille ou colonnes renommées → refus explicite.
- **Données personnelles** : le classeur réel contient des contacts ; ils ne sont jamais décodés. Tests uniquement avec des classeurs fictifs.

## 15. Risques

- Lecteur XML dédié : plus de code que la réutilisation d'OpenSpout, mais nécessaire à la minimisation décidée.
- Format du classeur susceptible de changer d'une année à l'autre.
- Échéance du 25/12 : 4 lots ; la mesure à 1 000 lignes fixera le délai du worker.
