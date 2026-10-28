# École de Santé Gabès — application XAMPP / Docker

Structure actuelle : le document root HTTP est `public/` (accueil + assets). Tout le métier ERP est dans `application/` (Finance, Stage, Admin, Scolarité, …). Les SQL sont dans `database/`. Voir `PROJECT_STRUCTURE.md`.

## Installation Docker (recommandée)

1. Depuis ce dossier : `docker compose up -d --build`
2. Ouvrez `https://192.168.10.11:8443/accueil` (landing) ou `https://192.168.10.11:8443/login`.
   Les ports restent `8080` (HTTP) et `8443` (HTTPS). Le port `8443` redirige
   également les requêtes HTTP accidentelles vers HTTPS.
3. PostgreSQL est la base applicative (`db` / port hôte `5433`). MySQL reste disponible pour rollback.
4. Docker Desktop démarre à l’ouverture de session Windows et les conteneurs utilisent
   `restart: unless-stopped`, donc l’application et ses bases reviennent après un redémarrage du PC.
5. Pour supprimer l’avertissement de certificat, chaque appareil doit approuver une seule fois
   l’autorité locale : ouvrez `http://192.168.10.11:8080/certificates/upsis-lan-root-ca.mobileconfig`
   sur iPhone, installez le profil téléchargé dans **Réglages > Général > VPN et gestion de
   l’appareil**, puis activez sa confiance complète dans **Réglages > Général > Informations >
   Réglages des certificats**. Sur Windows, téléchargez
   `http://192.168.10.11:8080/certificates/upsis-lan-root-ca.crt`, ouvrez-le, puis installez-le
   dans **Autorités de certification racines de confiance** pour l’utilisateur courant. Cette
   installation ne peut pas être effectuée silencieusement par un site web. Le certificat racine
   est public ; sa clé privée reste dans un volume Docker séparé, inaccessible à l’application web.

## Installation XAMPP

1. Copiez ce dossier dans `C:\xampp\htdocs\ecole_sante_gabes`.
2. Pointez le VirtualHost / alias Apache vers le sous-dossier `public/`.
3. Démarrez Apache et importez `database/seeds/db_new.sql` (ou utilisez Postgres via Docker).
4. Ouvrez `http://localhost/ecole_sante_gabes/login` (selon votre alias).

Identifiants administrateur : mot de passe `admin`.
Identifiants du service finance : identifiant `mondher`, mot de passe `mondher123`.

Pour ajouter les 120 étudiants du tronc commun à une base déjà importée,
importez également `database/seeds/seed_tronc_commun.sql`. Ce fichier ajoute les noms en
alphabet français et arabe et les affecte aux groupes Cours/TD des semestres
S1 à S3.

### Réinitialisation après une erreur de tablespace

Si MariaDB affiche `#1813` ou `#1010` pour `ecole_sante_gabes`, arrêtez
MySQL dans le panneau XAMPP, sauvegardez si nécessaire
`C:\xampp\mysql\data\ecole_sante_gabes`, puis supprimez uniquement ce dossier
orphelin. Redémarrez MySQL, créez la base `ecole_sante_gabes` dans phpMyAdmin
et importez ensuite `database/seeds/db_new.sql`. Le fichier utilise `CREATE DATABASE IF NOT
EXISTS` et ne supprime pas automatiquement une base existante.

## Fonctionnalités

- Tableau de bord protégé par session administrateur.
- Inscription en deux étapes : saisie, vérification, puis insertion en base MySQL.
- Données de l’étudiant en français et en arabe ; l’attestation de présence est générée en arabe.
- Attestations d’inscription, de présence et de réussite, avec recherche CIN/passeport et impression A4.
- Schéma MySQL de référence dans `database/seeds/db_new.sql` pour les étudiants, groupes, matières, notes, enseignants et finances.
- Pour activer la promotion de fin d'année, importez `database/migrations/promotion_archive_migration.sql`
  après `database/seeds/db_new.sql`. L'écran **Promotion / archivage** du tableau de bord
  prévisualise les décisions, exige le mot de passe administrateur et la saisie
  de `PROMOUVOIR`, archive les notes et l'état financier, puis remet le solde
  courant à zéro et avance les étudiants admis. Les relevés peuvent être
  consultés avec `?cin=...&year=2025-2026`.

Le logo public est servi depuis `public/images/logo.png` (URL navigateur : `images/logo.png`).

### Groupes TD

Pour activer les deux sous-groupes TD de chaque groupe Cours, exécutez une
seule fois `database/migrations/td_groups_migration.sql` sur la base utilisée par l’application,
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

Les nouvelles installations créent la table `stage_user` via `database/seeds/db_new.sql` (ou
le schéma de déploiement). Pour une base existante, sauvegardez-la puis exécutez
une fois `database/migrations/stage_accounts_migration.sql` sur la base réellement utilisée par
l’application. La migration ne crée aucun compte et ne touche pas aux données
enseignant/finance. Un administrateur peut ensuite créer les comptes depuis
**Ajouter compte stage** ; l’identifiant est un CIN de 8 chiffres et le mot de
passe est stocké sous forme de hash. La désactivation d’un compte étudiant,
enseignant, finance, stage ou scolarité invalide ses sessions : dès sa prochaine
requête, le compte est déconnecté et le message « Votre compte est suspendu »
est affiché sur la page de connexion. Les changements de mot de passe ou
d’identifiant invalident également les sessions des comptes concernés. Dans
Docker, appliquez les migrations à la base persistante du conteneur, pas à XAMPP.
