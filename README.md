# École de Santé Gabès — application XAMPP

## Installation

1. Copiez ce dossier dans `C:\xampp\htdocs\ecole_sante_gabes`.
2. Démarrez Apache et MySQL dans XAMPP.
3. Dans phpMyAdmin, importez `db_new.sql`.
4. Ouvrez `http://localhost/ecole_sante_gabes/login.php`.

Identifiants administrateur : mot de passe `admin`.
Identifiants du service finance : identifiant `mondher`, mot de passe `mondher123`.

Pour ajouter les 120 étudiants du tronc commun à une base déjà importée,
importez également `seed_tronc_commun.sql`. Ce fichier ajoute les noms en
alphabet français et arabe et les affecte aux groupes Cours/TD des semestres
S1 à S3.

### Réinitialisation après une erreur de tablespace

Si MariaDB affiche `#1813` ou `#1010` pour `ecole_sante_gabes`, arrêtez
MySQL dans le panneau XAMPP, sauvegardez si nécessaire
`C:\xampp\mysql\data\ecole_sante_gabes`, puis supprimez uniquement ce dossier
orphelin. Redémarrez MySQL, créez la base `ecole_sante_gabes` dans phpMyAdmin
et importez ensuite `db_new.sql`. Le fichier utilise `CREATE DATABASE IF NOT
EXISTS` et ne supprime pas automatiquement une base existante.

## Fonctionnalités

- Tableau de bord protégé par session administrateur.
- Inscription en deux étapes : saisie, vérification, puis insertion en base MySQL.
- Données de l’étudiant en français et en arabe ; l’attestation de présence est générée en arabe.
- Attestations d’inscription, de présence et de réussite, avec recherche CIN/passeport et impression A4.
- Schéma MySQL complet dans `db_new.sql` pour les étudiants, groupes, matières, notes, enseignants et finances.
- Pour activer la promotion de fin d'année, importez `promotion_archive_migration.sql`
  après `db_new.sql`. L'écran **Promotion / archivage** du tableau de bord
  prévisualise les décisions, exige le mot de passe administrateur et la saisie
  de `PROMOUVOIR`, archive les notes et l'état financier, puis remet le solde
  courant à zéro et avance les étudiants admis. Les relevés peuvent être
  consultés avec `?cin=...&year=2025-2026`.

Le logo doit rester à l’emplacement `images/logo.png`.

### Groupes TD

Pour activer les deux sous-groupes TD de chaque groupe Cours, exécutez une
seule fois `td_groups_migration.sql` sur la base utilisée par l’application,
après avoir sauvegardé cette base et avant d’utiliser les écrans Groupes,
Affecter Enseignant, Feuille de présence ou les nouvelles inscriptions. La
migration relie les groupes TD existants,
crée les sous-groupes manquants, complète les affectations Cours des étudiants
selon leur niveau et répartit aléatoirement chaque étudiant inscrit en Cours
dans l’un des deux TD du même groupe. Elle ne modifie pas les fichiers d’export
SQL.

Les nouvelles inscriptions sont ensuite affectées automatiquement aux groupes
Cours et TD correspondant à leur niveau. Dans Docker, la migration doit être
exécutée sur la base persistante du conteneur, et non simplement importée dans
une base locale XAMPP.

### Comptes du service des stages

Les nouvelles installations créent la table `stage_user` via `db_new.sql` (ou
le schéma de déploiement). Pour une base existante, sauvegardez-la puis exécutez
une fois `stage_accounts_migration.sql` sur la base réellement utilisée par
l’application. La migration ne crée aucun compte et ne touche pas aux données
enseignant/finance. Un administrateur peut ensuite créer les comptes depuis
**Ajouter compte stage** ; l’identifiant est un CIN de 8 chiffres et le mot de
passe est stocké sous forme de hash. Les modifications de comptes stage et
finance invalident leurs sessions actives. Dans Docker, appliquez la migration
à la base persistante du conteneur, pas à XAMPP.
