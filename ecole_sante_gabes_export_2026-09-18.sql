-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: 127.0.0.1    Database: ecole_sante_gabes
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `admin_user`
--

DROP TABLE IF EXISTS `admin_user`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_user` (
  `id_admin` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(80) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  PRIMARY KEY (`id_admin`),
  UNIQUE KEY `uq_admin_username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_user`
--

LOCK TABLES `admin_user` WRITE;
/*!40000 ALTER TABLE `admin_user` DISABLE KEYS */;
INSERT INTO `admin_user` VALUES (1,'admin','$2y$10$9K2qVnYWB/dfdoKTGMn1FO4Qb0bh07OMLHi3l6XJM8VESe28whYNW');
/*!40000 ALTER TABLE `admin_user` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `enseignant`
--

DROP TABLE IF EXISTS `enseignant`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `enseignant` (
  `id_enseignant` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cin` varchar(8) NOT NULL,
  `nom` varchar(80) NOT NULL,
  `prenom` varchar(80) NOT NULL,
  `num_tel` varchar(30) NOT NULL,
  `email` varchar(150) NOT NULL,
  `adresse` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  PRIMARY KEY (`id_enseignant`),
  UNIQUE KEY `uq_enseignant_cin` (`cin`),
  UNIQUE KEY `uq_enseignant_email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `enseignant`
--

LOCK TABLES `enseignant` WRITE;
/*!40000 ALTER TABLE `enseignant` DISABLE KEYS */;
INSERT INTO `enseignant` VALUES (1,'13270560','Mahmoud','Gazbar','52555017','mahmoudgazbar@icloud.com','gabes','$2y$10$VztnH/9P45BPgjdCM1xOa.B4GW0D1D6tBZq36ZvGyw52psHYm3E6a'),(2,'12345678','aa','aa','55698752','aa@gmail.com','gabes','$2y$10$EuMkgEOA0vB80aAYv4nZWOcgDTwQ4de7aB03HZ7ZZuQNN5L7muvuK');
/*!40000 ALTER TABLE `enseignant` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `enseignant_affectation`
--

DROP TABLE IF EXISTS `enseignant_affectation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `enseignant_affectation` (
  `id_affectation` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `id_enseignant` int(10) unsigned NOT NULL,
  `id_mat` int(10) unsigned NOT NULL,
  `id_groupe` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id_affectation`),
  UNIQUE KEY `uq_affectation` (`id_enseignant`,`id_mat`,`id_groupe`),
  KEY `idx_affectation_matiere` (`id_mat`),
  KEY `idx_affectation_groupe` (`id_groupe`),
  CONSTRAINT `fk_affectation_enseignant` FOREIGN KEY (`id_enseignant`) REFERENCES `enseignant` (`id_enseignant`) ON DELETE CASCADE,
  CONSTRAINT `fk_affectation_groupe` FOREIGN KEY (`id_groupe`) REFERENCES `groupe` (`id_groupe`) ON DELETE CASCADE,
  CONSTRAINT `fk_affectation_matiere` FOREIGN KEY (`id_mat`) REFERENCES `matiere` (`id_mat`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `enseignant_affectation`
--

LOCK TABLES `enseignant_affectation` WRITE;
/*!40000 ALTER TABLE `enseignant_affectation` DISABLE KEYS */;
INSERT INTO `enseignant_affectation` VALUES (4,1,39,1),(5,1,39,13),(2,2,9,1),(3,2,9,13),(1,2,11,1);
/*!40000 ALTER TABLE `enseignant_affectation` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `etudiant`
--

DROP TABLE IF EXISTS `etudiant`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `etudiant` (
  `id_etudiant` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nom` varchar(80) NOT NULL,
  `prenom` varchar(80) NOT NULL,
  `nom_ar` varchar(100) NOT NULL,
  `prenom_ar` varchar(100) NOT NULL,
  `date_naissance` date NOT NULL,
  `lieu_naissance` varchar(120) NOT NULL,
  `lieu_naissance_ar` varchar(160) NOT NULL,
  `sexe` enum('Femme','Homme') NOT NULL,
  `nationalite` varchar(80) NOT NULL,
  `etat_civil` varchar(40) NOT NULL,
  `cin_passeport` varchar(40) NOT NULL,
  `num_tel` varchar(30) NOT NULL,
  `adresse` varchar(255) NOT NULL,
  `email` varchar(150) NOT NULL,
  `num_inscription` varchar(50) NOT NULL,
  `date_inscrit` date NOT NULL,
  `id_spec` int(10) unsigned NOT NULL,
  `niveau` enum('1','2','3') NOT NULL,
  `statut` enum('actif','diplome') NOT NULL DEFAULT 'actif',
  `annee_bac` smallint(5) unsigned NOT NULL,
  `specialite_bac` enum('Science','Math','Lettre','Sport') NOT NULL,
  PRIMARY KEY (`id_etudiant`),
  UNIQUE KEY `uq_etudiant_cin` (`cin_passeport`),
  UNIQUE KEY `uq_etudiant_email` (`email`),
  UNIQUE KEY `uq_etudiant_inscription` (`num_inscription`),
  KEY `idx_etudiant_specialite` (`id_spec`),
  CONSTRAINT `fk_etudiant_specialite` FOREIGN KEY (`id_spec`) REFERENCES `specialite` (`id_spec`)
) ENGINE=InnoDB AUTO_INCREMENT=122 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `etudiant`
--

LOCK TABLES `etudiant` WRITE;
/*!40000 ALTER TABLE `etudiant` DISABLE KEYS */;
INSERT INTO `etudiant` VALUES (1,'Ben Salem','Ahmed','بن سالم','أحمد','2000-01-01','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000001','20000001','Gabès','etudiant001@universite-sante.tn','TC000001','2026-09-01',4,'1','actif',2020,'Science'),(2,'Trabelsi','Ahmed','الطرابلسي','أحمد','2000-02-02','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000002','20000002','Gabès','etudiant002@universite-sante.tn','TC000002','2026-09-01',4,'1','actif',2020,'Science'),(3,'Jaziri','Ahmed','الجزيري','أحمد','2000-03-03','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000003','20000003','Gabès','etudiant003@universite-sante.tn','TC000003','2026-09-01',4,'1','actif',2020,'Science'),(4,'Masmoudi','Ahmed','المسعودي','أحمد','2000-04-04','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000004','20000004','Gabès','etudiant004@universite-sante.tn','TC000004','2026-09-01',4,'1','actif',2020,'Science'),(5,'Gharbi','Ahmed','الغربي','أحمد','2000-05-05','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000005','20000005','Gabès','etudiant005@universite-sante.tn','TC000005','2026-09-01',4,'1','actif',2020,'Science'),(6,'Brahmi','Ahmed','البراهمي','أحمد','2000-06-06','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000006','20000006','Gabès','etudiant006@universite-sante.tn','TC000006','2026-09-01',4,'1','actif',2020,'Science'),(7,'Khlifi','Ahmed','الخليفي','أحمد','2000-07-07','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000007','20000007','Gabès','etudiant007@universite-sante.tn','TC000007','2026-09-01',4,'1','actif',2020,'Science'),(8,'Ayari','Ahmed','العياري','أحمد','2000-08-08','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000008','20000008','Gabès','etudiant008@universite-sante.tn','TC000008','2026-09-01',4,'1','actif',2020,'Science'),(9,'Chebbi','Ahmed','الشابي','أحمد','2000-09-09','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000009','20000009','Gabès','etudiant009@universite-sante.tn','TC000009','2026-09-01',4,'1','actif',2020,'Science'),(10,'Dridi','Ahmed','الدريدي','أحمد','2000-10-10','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000010','20000010','Gabès','etudiant010@universite-sante.tn','TC000010','2026-09-01',4,'1','actif',2020,'Science'),(11,'Haddad','Ahmed','الحداد','أحمد','2000-11-11','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000011','20000011','Gabès','etudiant011@universite-sante.tn','TC000011','2026-09-01',4,'1','actif',2020,'Science'),(12,'Mabrouk','Ahmed','المبروك','أحمد','2000-12-12','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000012','20000012','Gabès','etudiant012@universite-sante.tn','TC000012','2026-09-01',4,'1','actif',2020,'Science'),(13,'Hamdi','Ahmed','الحمدي','أحمد','2000-01-13','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000013','20000013','Gabès','etudiant013@universite-sante.tn','TC000013','2026-09-01',4,'1','actif',2020,'Science'),(14,'Kammoun','Ahmed','الكمون','أحمد','2000-02-14','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000014','20000014','Gabès','etudiant014@universite-sante.tn','TC000014','2026-09-01',4,'1','actif',2020,'Science'),(15,'Mejri','Ahmed','المجري','أحمد','2000-03-15','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000015','20000015','Gabès','etudiant015@universite-sante.tn','TC000015','2026-09-01',4,'1','actif',2020,'Science'),(16,'Mansouri','Ahmed','المنصوري','أحمد','2000-04-16','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000016','20000016','Gabès','etudiant016@universite-sante.tn','TC000016','2026-09-01',4,'1','actif',2020,'Science'),(17,'Sassi','Ahmed','الساسي','أحمد','2000-05-17','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000017','20000017','Gabès','etudiant017@universite-sante.tn','TC000017','2026-09-01',4,'1','actif',2020,'Science'),(18,'Saidi','Ahmed','السعيدي','أحمد','2000-06-18','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000018','20000018','Gabès','etudiant018@universite-sante.tn','TC000018','2026-09-01',4,'1','actif',2020,'Science'),(19,'Jebali','Ahmed','الجبالي','أحمد','2000-07-19','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000019','20000019','Gabès','etudiant019@universite-sante.tn','TC000019','2026-09-01',4,'1','actif',2020,'Science'),(20,'Chaabane','Ahmed','الشعباني','أحمد','2000-08-20','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000020','20000020','Gabès','etudiant020@universite-sante.tn','TC000020','2026-09-01',4,'1','actif',2020,'Science'),(21,'Ben Salem','Mariem','بن سالم','مريم','2000-09-21','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000021','20000021','Gabès','etudiant021@universite-sante.tn','TC000021','2026-09-01',4,'1','actif',2020,'Science'),(22,'Trabelsi','Mariem','الطرابلسي','مريم','2000-10-22','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000022','20000022','Gabès','etudiant022@universite-sante.tn','TC000022','2026-09-01',4,'1','actif',2020,'Science'),(23,'Jaziri','Mariem','الجزيري','مريم','2000-11-23','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000023','20000023','Gabès','etudiant023@universite-sante.tn','TC000023','2026-09-01',4,'1','actif',2020,'Science'),(24,'Masmoudi','Mariem','المسعودي','مريم','2000-12-24','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000024','20000024','Gabès','etudiant024@universite-sante.tn','TC000024','2026-09-01',4,'1','actif',2020,'Science'),(25,'Gharbi','Mariem','الغربي','مريم','2000-01-25','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000025','20000025','Gabès','etudiant025@universite-sante.tn','TC000025','2026-09-01',4,'1','actif',2020,'Science'),(26,'Brahmi','Mariem','البراهمي','مريم','2000-02-26','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000026','20000026','Gabès','etudiant026@universite-sante.tn','TC000026','2026-09-01',4,'1','actif',2020,'Science'),(27,'Khlifi','Mariem','الخليفي','مريم','2000-03-27','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000027','20000027','Gabès','etudiant027@universite-sante.tn','TC000027','2026-09-01',4,'1','actif',2020,'Science'),(28,'Ayari','Mariem','العياري','مريم','2000-04-01','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000028','20000028','Gabès','etudiant028@universite-sante.tn','TC000028','2026-09-01',4,'1','actif',2020,'Science'),(29,'Chebbi','Mariem','الشابي','مريم','2000-05-02','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000029','20000029','Gabès','etudiant029@universite-sante.tn','TC000029','2026-09-01',4,'1','actif',2020,'Science'),(30,'Dridi','Mariem','الدريدي','مريم','2000-06-03','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000030','20000030','Gabès','etudiant030@universite-sante.tn','TC000030','2026-09-01',4,'1','actif',2020,'Science'),(31,'Haddad','Mariem','الحداد','مريم','2000-07-04','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000031','20000031','Gabès','etudiant031@universite-sante.tn','TC000031','2026-09-01',4,'1','actif',2020,'Science'),(32,'Mabrouk','Mariem','المبروك','مريم','2000-08-05','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000032','20000032','Gabès','etudiant032@universite-sante.tn','TC000032','2026-09-01',4,'1','actif',2020,'Science'),(33,'Hamdi','Mariem','الحمدي','مريم','2000-09-06','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000033','20000033','Gabès','etudiant033@universite-sante.tn','TC000033','2026-09-01',4,'1','actif',2020,'Science'),(34,'Kammoun','Mariem','الكمون','مريم','2000-10-07','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000034','20000034','Gabès','etudiant034@universite-sante.tn','TC000034','2026-09-01',4,'1','actif',2020,'Science'),(35,'Mejri','Mariem','المجري','مريم','2000-11-08','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000035','20000035','Gabès','etudiant035@universite-sante.tn','TC000035','2026-09-01',4,'1','actif',2020,'Science'),(36,'Mansouri','Mariem','المنصوري','مريم','2000-12-09','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000036','20000036','Gabès','etudiant036@universite-sante.tn','TC000036','2026-09-01',4,'1','actif',2020,'Science'),(37,'Sassi','Mariem','الساسي','مريم','2000-01-10','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000037','20000037','Gabès','etudiant037@universite-sante.tn','TC000037','2026-09-01',4,'1','actif',2020,'Science'),(38,'Saidi','Mariem','السعيدي','مريم','2000-02-11','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000038','20000038','Gabès','etudiant038@universite-sante.tn','TC000038','2026-09-01',4,'1','actif',2020,'Science'),(39,'Jebali','Mariem','الجبالي','مريم','2000-03-12','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000039','20000039','Gabès','etudiant039@universite-sante.tn','TC000039','2026-09-01',4,'1','actif',2020,'Science'),(40,'Chaabane','Mariem','الشعباني','مريم','2000-04-13','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000040','20000040','Gabès','etudiant040@universite-sante.tn','TC000040','2026-09-01',4,'1','actif',2020,'Science'),(41,'Ben Salem','Mohamed','بن سالم','محمد','2000-05-14','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000041','20000041','Gabès','etudiant041@universite-sante.tn','TC000041','2026-09-01',4,'1','actif',2020,'Science'),(42,'Trabelsi','Mohamed','الطرابلسي','محمد','2000-06-15','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000042','20000042','Gabès','etudiant042@universite-sante.tn','TC000042','2026-09-01',4,'1','actif',2020,'Science'),(43,'Jaziri','Mohamed','الجزيري','محمد','2000-07-16','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000043','20000043','Gabès','etudiant043@universite-sante.tn','TC000043','2026-09-01',4,'1','actif',2020,'Science'),(44,'Masmoudi','Mohamed','المسعودي','محمد','2000-08-17','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000044','20000044','Gabès','etudiant044@universite-sante.tn','TC000044','2026-09-01',4,'1','actif',2020,'Science'),(45,'Gharbi','Mohamed','الغربي','محمد','2000-09-18','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000045','20000045','Gabès','etudiant045@universite-sante.tn','TC000045','2026-09-01',4,'1','actif',2020,'Science'),(46,'Brahmi','Mohamed','البراهمي','محمد','2000-10-19','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000046','20000046','Gabès','etudiant046@universite-sante.tn','TC000046','2026-09-01',4,'1','actif',2020,'Science'),(47,'Khlifi','Mohamed','الخليفي','محمد','2000-11-20','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000047','20000047','Gabès','etudiant047@universite-sante.tn','TC000047','2026-09-01',4,'1','actif',2020,'Science'),(48,'Ayari','Mohamed','العياري','محمد','2000-12-21','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000048','20000048','Gabès','etudiant048@universite-sante.tn','TC000048','2026-09-01',4,'1','actif',2020,'Science'),(49,'Chebbi','Mohamed','الشابي','محمد','2000-01-22','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000049','20000049','Gabès','etudiant049@universite-sante.tn','TC000049','2026-09-01',4,'1','actif',2020,'Science'),(50,'Dridi','Mohamed','الدريدي','محمد','2000-02-23','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000050','20000050','Gabès','etudiant050@universite-sante.tn','TC000050','2026-09-01',4,'1','actif',2020,'Science'),(51,'Haddad','Mohamed','الحداد','محمد','2000-03-24','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000051','20000051','Gabès','etudiant051@universite-sante.tn','TC000051','2026-09-01',4,'1','actif',2020,'Science'),(52,'Mabrouk','Mohamed','المبروك','محمد','2000-04-25','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000052','20000052','Gabès','etudiant052@universite-sante.tn','TC000052','2026-09-01',4,'1','actif',2020,'Science'),(53,'Hamdi','Mohamed','الحمدي','محمد','2000-05-26','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000053','20000053','Gabès','etudiant053@universite-sante.tn','TC000053','2026-09-01',4,'1','actif',2020,'Science'),(54,'Kammoun','Mohamed','الكمون','محمد','2000-06-27','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000054','20000054','Gabès','etudiant054@universite-sante.tn','TC000054','2026-09-01',4,'1','actif',2020,'Science'),(55,'Mejri','Mohamed','المجري','محمد','2000-07-01','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000055','20000055','Gabès','etudiant055@universite-sante.tn','TC000055','2026-09-01',4,'1','actif',2020,'Science'),(56,'Mansouri','Mohamed','المنصوري','محمد','2000-08-02','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000056','20000056','Gabès','etudiant056@universite-sante.tn','TC000056','2026-09-01',4,'1','actif',2020,'Science'),(57,'Sassi','Mohamed','الساسي','محمد','2000-09-03','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000057','20000057','Gabès','etudiant057@universite-sante.tn','TC000057','2026-09-01',4,'1','actif',2020,'Science'),(58,'Saidi','Mohamed','السعيدي','محمد','2000-10-04','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000058','20000058','Gabès','etudiant058@universite-sante.tn','TC000058','2026-09-01',4,'1','actif',2020,'Science'),(59,'Jebali','Mohamed','الجبالي','محمد','2000-11-05','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000059','20000059','Gabès','etudiant059@universite-sante.tn','TC000059','2026-09-01',4,'1','actif',2020,'Science'),(60,'Chaabane','Mohamed','الشعباني','محمد','2000-12-06','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000060','20000060','Gabès','etudiant060@universite-sante.tn','TC000060','2026-09-01',4,'1','actif',2020,'Science'),(61,'Ben Salem','Amira','بن سالم','أميرة','2000-01-07','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000061','20000061','Gabès','etudiant061@universite-sante.tn','TC000061','2026-09-01',4,'1','actif',2020,'Science'),(62,'Trabelsi','Amira','الطرابلسي','أميرة','2000-02-08','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000062','20000062','Gabès','etudiant062@universite-sante.tn','TC000062','2026-09-01',4,'1','actif',2020,'Science'),(63,'Jaziri','Amira','الجزيري','أميرة','2000-03-09','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000063','20000063','Gabès','etudiant063@universite-sante.tn','TC000063','2026-09-01',4,'1','actif',2020,'Science'),(64,'Masmoudi','Amira','المسعودي','أميرة','2000-04-10','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000064','20000064','Gabès','etudiant064@universite-sante.tn','TC000064','2026-09-01',4,'1','actif',2020,'Science'),(65,'Gharbi','Amira','الغربي','أميرة','2000-05-11','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000065','20000065','Gabès','etudiant065@universite-sante.tn','TC000065','2026-09-01',4,'1','actif',2020,'Science'),(66,'Brahmi','Amira','البراهمي','أميرة','2000-06-12','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000066','20000066','Gabès','etudiant066@universite-sante.tn','TC000066','2026-09-01',4,'1','actif',2020,'Science'),(67,'Khlifi','Amira','الخليفي','أميرة','2000-07-13','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000067','20000067','Gabès','etudiant067@universite-sante.tn','TC000067','2026-09-01',4,'1','actif',2020,'Science'),(68,'Ayari','Amira','العياري','أميرة','2000-08-14','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000068','20000068','Gabès','etudiant068@universite-sante.tn','TC000068','2026-09-01',4,'1','actif',2020,'Science'),(69,'Chebbi','Amira','الشابي','أميرة','2000-09-15','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000069','20000069','Gabès','etudiant069@universite-sante.tn','TC000069','2026-09-01',4,'1','actif',2020,'Science'),(70,'Dridi','Amira','الدريدي','أميرة','2000-10-16','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000070','20000070','Gabès','etudiant070@universite-sante.tn','TC000070','2026-09-01',4,'1','actif',2020,'Science'),(71,'Haddad','Amira','الحداد','أميرة','2000-11-17','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000071','20000071','Gabès','etudiant071@universite-sante.tn','TC000071','2026-09-01',4,'1','actif',2020,'Science'),(72,'Mabrouk','Amira','المبروك','أميرة','2000-12-18','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000072','20000072','Gabès','etudiant072@universite-sante.tn','TC000072','2026-09-01',4,'1','actif',2020,'Science'),(73,'Hamdi','Amira','الحمدي','أميرة','2000-01-19','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000073','20000073','Gabès','etudiant073@universite-sante.tn','TC000073','2026-09-01',4,'1','actif',2020,'Science'),(74,'Kammoun','Amira','الكمون','أميرة','2000-02-20','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000074','20000074','Gabès','etudiant074@universite-sante.tn','TC000074','2026-09-01',4,'1','actif',2020,'Science'),(75,'Mejri','Amira','المجري','أميرة','2000-03-21','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000075','20000075','Gabès','etudiant075@universite-sante.tn','TC000075','2026-09-01',4,'1','actif',2020,'Science'),(76,'Mansouri','Amira','المنصوري','أميرة','2000-04-22','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000076','20000076','Gabès','etudiant076@universite-sante.tn','TC000076','2026-09-01',4,'1','actif',2020,'Science'),(77,'Sassi','Amira','الساسي','أميرة','2000-05-23','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000077','20000077','Gabès','etudiant077@universite-sante.tn','TC000077','2026-09-01',4,'1','actif',2020,'Science'),(78,'Saidi','Amira','السعيدي','أميرة','2000-06-24','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000078','20000078','Gabès','etudiant078@universite-sante.tn','TC000078','2026-09-01',4,'1','actif',2020,'Science'),(79,'Jebali','Amira','الجبالي','أميرة','2000-07-25','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000079','20000079','Gabès','etudiant079@universite-sante.tn','TC000079','2026-09-01',4,'1','actif',2020,'Science'),(80,'Chaabane','Amira','الشعباني','أميرة','2000-08-26','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000080','20000080','Gabès','etudiant080@universite-sante.tn','TC000080','2026-09-01',4,'1','actif',2020,'Science'),(81,'Ben Salem','Youssef','بن سالم','يوسف','2000-09-27','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000081','20000081','Gabès','etudiant081@universite-sante.tn','TC000081','2026-09-01',4,'1','actif',2020,'Science'),(82,'Trabelsi','Youssef','الطرابلسي','يوسف','2000-10-01','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000082','20000082','Gabès','etudiant082@universite-sante.tn','TC000082','2026-09-01',4,'1','actif',2020,'Science'),(83,'Jaziri','Youssef','الجزيري','يوسف','2000-11-02','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000083','20000083','Gabès','etudiant083@universite-sante.tn','TC000083','2026-09-01',4,'1','actif',2020,'Science'),(84,'Masmoudi','Youssef','المسعودي','يوسف','2000-12-03','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000084','20000084','Gabès','etudiant084@universite-sante.tn','TC000084','2026-09-01',4,'1','actif',2020,'Science'),(85,'Gharbi','Youssef','الغربي','يوسف','2000-01-04','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000085','20000085','Gabès','etudiant085@universite-sante.tn','TC000085','2026-09-01',4,'1','actif',2020,'Science'),(86,'Brahmi','Youssef','البراهمي','يوسف','2000-02-05','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000086','20000086','Gabès','etudiant086@universite-sante.tn','TC000086','2026-09-01',4,'1','actif',2020,'Science'),(87,'Khlifi','Youssef','الخليفي','يوسف','2000-03-06','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000087','20000087','Gabès','etudiant087@universite-sante.tn','TC000087','2026-09-01',4,'1','actif',2020,'Science'),(88,'Ayari','Youssef','العياري','يوسف','2000-04-07','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000088','20000088','Gabès','etudiant088@universite-sante.tn','TC000088','2026-09-01',4,'1','actif',2020,'Science'),(89,'Chebbi','Youssef','الشابي','يوسف','2000-05-08','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000089','20000089','Gabès','etudiant089@universite-sante.tn','TC000089','2026-09-01',4,'1','actif',2020,'Science'),(90,'Dridi','Youssef','الدريدي','يوسف','2000-06-09','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000090','20000090','Gabès','etudiant090@universite-sante.tn','TC000090','2026-09-01',4,'1','actif',2020,'Science'),(91,'Haddad','Youssef','الحداد','يوسف','2000-07-10','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000091','20000091','Gabès','etudiant091@universite-sante.tn','TC000091','2026-09-01',4,'1','actif',2020,'Science'),(92,'Mabrouk','Youssef','المبروك','يوسف','2000-08-11','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000092','20000092','Gabès','etudiant092@universite-sante.tn','TC000092','2026-09-01',4,'1','actif',2020,'Science'),(93,'Hamdi','Youssef','الحمدي','يوسف','2000-09-12','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000093','20000093','Gabès','etudiant093@universite-sante.tn','TC000093','2026-09-01',4,'1','actif',2020,'Science'),(94,'Kammoun','Youssef','الكمون','يوسف','2000-10-13','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000094','20000094','Gabès','etudiant094@universite-sante.tn','TC000094','2026-09-01',4,'1','actif',2020,'Science'),(95,'Mejri','Youssef','المجري','يوسف','2000-11-14','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000095','20000095','Gabès','etudiant095@universite-sante.tn','TC000095','2026-09-01',4,'1','actif',2020,'Science'),(96,'Mansouri','Youssef','المنصوري','يوسف','2000-12-15','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000096','20000096','Gabès','etudiant096@universite-sante.tn','TC000096','2026-09-01',4,'1','actif',2020,'Science'),(97,'Sassi','Youssef','الساسي','يوسف','2000-01-16','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000097','20000097','Gabès','etudiant097@universite-sante.tn','TC000097','2026-09-01',4,'1','actif',2020,'Science'),(98,'Saidi','Youssef','السعيدي','يوسف','2000-02-17','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000098','20000098','Gabès','etudiant098@universite-sante.tn','TC000098','2026-09-01',4,'1','actif',2020,'Science'),(99,'Jebali','Youssef','الجبالي','يوسف','2000-03-18','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000099','20000099','Gabès','etudiant099@universite-sante.tn','TC000099','2026-09-01',4,'1','actif',2020,'Science'),(100,'Chaabane','Youssef','الشعباني','يوسف','2000-04-19','Gabès','قابس','Homme','Tunisienne','Célibataire','TC000100','20000100','Gabès','etudiant100@universite-sante.tn','TC000100','2026-09-01',4,'1','actif',2020,'Science'),(101,'Ben Salem','Sarra','بن سالم','سارة','2000-05-20','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000101','20000101','Gabès','etudiant101@universite-sante.tn','TC000101','2026-09-01',4,'1','actif',2020,'Science'),(102,'Trabelsi','Sarra','الطرابلسي','سارة','2000-06-21','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000102','20000102','Gabès','etudiant102@universite-sante.tn','TC000102','2026-09-01',4,'1','actif',2020,'Science'),(103,'Jaziri','Sarra','الجزيري','سارة','2000-07-22','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000103','20000103','Gabès','etudiant103@universite-sante.tn','TC000103','2026-09-01',4,'1','actif',2020,'Science'),(104,'Masmoudi','Sarra','المسعودي','سارة','2000-08-23','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000104','20000104','Gabès','etudiant104@universite-sante.tn','TC000104','2026-09-01',4,'1','actif',2020,'Science'),(105,'Gharbi','Sarra','الغربي','سارة','2000-09-24','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000105','20000105','Gabès','etudiant105@universite-sante.tn','TC000105','2026-09-01',4,'1','actif',2020,'Science'),(106,'Brahmi','Sarra','البراهمي','سارة','2000-10-25','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000106','20000106','Gabès','etudiant106@universite-sante.tn','TC000106','2026-09-01',4,'1','actif',2020,'Science'),(107,'Khlifi','Sarra','الخليفي','سارة','2000-11-26','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000107','20000107','Gabès','etudiant107@universite-sante.tn','TC000107','2026-09-01',4,'1','actif',2020,'Science'),(108,'Ayari','Sarra','العياري','سارة','2000-12-27','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000108','20000108','Gabès','etudiant108@universite-sante.tn','TC000108','2026-09-01',4,'1','actif',2020,'Science'),(109,'Chebbi','Sarra','الشابي','سارة','2000-01-01','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000109','20000109','Gabès','etudiant109@universite-sante.tn','TC000109','2026-09-01',4,'1','actif',2020,'Science'),(110,'Dridi','Sarra','الدريدي','سارة','2000-02-02','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000110','20000110','Gabès','etudiant110@universite-sante.tn','TC000110','2026-09-01',4,'1','actif',2020,'Science'),(111,'Haddad','Sarra','الحداد','سارة','2000-03-03','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000111','20000111','Gabès','etudiant111@universite-sante.tn','TC000111','2026-09-01',4,'1','actif',2020,'Science'),(112,'Mabrouk','Sarra','المبروك','سارة','2000-04-04','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000112','20000112','Gabès','etudiant112@universite-sante.tn','TC000112','2026-09-01',4,'1','actif',2020,'Science'),(113,'Hamdi','Sarra','الحمدي','سارة','2000-05-05','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000113','20000113','Gabès','etudiant113@universite-sante.tn','TC000113','2026-09-01',4,'1','actif',2020,'Science'),(114,'Kammoun','Sarra','الكمون','سارة','2000-06-06','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000114','20000114','Gabès','etudiant114@universite-sante.tn','TC000114','2026-09-01',4,'1','actif',2020,'Science'),(115,'Mejri','Sarra','المجري','سارة','2000-07-07','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000115','20000115','Gabès','etudiant115@universite-sante.tn','TC000115','2026-09-01',4,'1','actif',2020,'Science'),(116,'Mansouri','Sarra','المنصوري','سارة','2000-08-08','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000116','20000116','Gabès','etudiant116@universite-sante.tn','TC000116','2026-09-01',4,'1','actif',2020,'Science'),(117,'Sassi','Sarra','الساسي','سارة','2000-09-09','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000117','20000117','Gabès','etudiant117@universite-sante.tn','TC000117','2026-09-01',4,'1','actif',2020,'Science'),(118,'Saidi','Sarra','السعيدي','سارة','2000-10-10','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000118','20000118','Gabès','etudiant118@universite-sante.tn','TC000118','2026-09-01',4,'1','actif',2020,'Science'),(119,'Jebali','Sarra','الجبالي','سارة','2000-11-11','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000119','20000119','Gabès','etudiant119@universite-sante.tn','TC000119','2026-09-01',4,'1','actif',2020,'Science'),(120,'Chaabane','Sarra','الشعباني','سارة','2000-12-12','Gabès','قابس','Femme','Tunisienne','Célibataire','TC000120','20000120','Gabès','etudiant120@universite-sante.tn','TC000120','2026-09-01',4,'1','actif',2020,'Science');
/*!40000 ALTER TABLE `etudiant` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `etudiant_archive`
--

DROP TABLE IF EXISTS `etudiant_archive`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `etudiant_archive` (
  `id_archive` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `id_etudiant` int(10) unsigned NOT NULL,
  `annee_scolaire` varchar(9) NOT NULL,
  `niveau` varchar(10) NOT NULL,
  `id_spec` int(10) unsigned DEFAULT NULL,
  `nom_spec` varchar(120) DEFAULT NULL,
  `num_inscription` varchar(50) NOT NULL,
  `cin_passeport` varchar(40) NOT NULL,
  `decision` enum('admis','redouble','archive') NOT NULL,
  `moyenne_generale` decimal(5,2) DEFAULT NULL,
  `student_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`student_payload`)),
  `transcript_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`transcript_payload`)),
  `finance_total_paye` decimal(10,2) NOT NULL DEFAULT 0.00,
  `archived_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `archived_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id_archive`),
  UNIQUE KEY `uq_archive_student_year` (`id_etudiant`,`annee_scolaire`),
  KEY `idx_archive_cin_year` (`cin_passeport`,`annee_scolaire`),
  CONSTRAINT `fk_archive_student` FOREIGN KEY (`id_etudiant`) REFERENCES `etudiant` (`id_etudiant`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `etudiant_archive`
--

LOCK TABLES `etudiant_archive` WRITE;
/*!40000 ALTER TABLE `etudiant_archive` DISABLE KEYS */;
/*!40000 ALTER TABLE `etudiant_archive` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `finance`
--

DROP TABLE IF EXISTS `finance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `finance` (
  `id_finance_paiement` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `id_etudiant` int(10) unsigned NOT NULL,
  `mode_paiement` enum('virement','espece') NOT NULL,
  `montant_paye` decimal(10,2) NOT NULL,
  `id_finance_user` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id_finance_paiement`),
  KEY `idx_finance_student` (`id_etudiant`),
  KEY `idx_finance_user` (`id_finance_user`),
  CONSTRAINT `fk_finance_student` FOREIGN KEY (`id_etudiant`) REFERENCES `etudiant` (`id_etudiant`) ON DELETE CASCADE,
  CONSTRAINT `fk_finance_user` FOREIGN KEY (`id_finance_user`) REFERENCES `finance_user` (`id_finance`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `finance`
--

LOCK TABLES `finance` WRITE;
/*!40000 ALTER TABLE `finance` DISABLE KEYS */;
INSERT INTO `finance` VALUES (1,8,'virement',4200.00,1,'2026-09-18 18:29:22','2026-09-18 19:01:34'),(2,8,'virement',800.00,1,'2026-09-18 18:30:07','2026-09-18 19:01:34'),(3,8,'espece',2000.00,1,'2026-09-18 19:01:57',NULL),(4,68,'espece',500.00,1,'2026-09-18 19:11:49','2026-09-18 19:14:41'),(5,68,'espece',4000.00,1,'2026-09-18 19:12:47',NULL),(6,68,'virement',1000.00,1,'2026-09-18 19:13:01',NULL),(7,68,'virement',1500.00,1,'2026-09-18 19:13:30','2026-09-18 19:14:41');
/*!40000 ALTER TABLE `finance` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `finance_rollover`
--

DROP TABLE IF EXISTS `finance_rollover`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `finance_rollover` (
  `id_rollover` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `id_etudiant` int(10) unsigned NOT NULL,
  `annee_scolaire` varchar(9) NOT NULL,
  `total_paye` decimal(10,2) NOT NULL DEFAULT 0.00,
  `remaining_balance` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id_rollover`),
  UNIQUE KEY `uq_rollover_student_year` (`id_etudiant`,`annee_scolaire`),
  CONSTRAINT `fk_rollover_student` FOREIGN KEY (`id_etudiant`) REFERENCES `etudiant` (`id_etudiant`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `finance_rollover`
--

LOCK TABLES `finance_rollover` WRITE;
/*!40000 ALTER TABLE `finance_rollover` DISABLE KEYS */;
/*!40000 ALTER TABLE `finance_rollover` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `finance_solde`
--

DROP TABLE IF EXISTS `finance_solde`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `finance_solde` (
  `id_etudiant` int(10) unsigned NOT NULL,
  `total_paye` decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id_etudiant`),
  CONSTRAINT `fk_finance_solde_student` FOREIGN KEY (`id_etudiant`) REFERENCES `etudiant` (`id_etudiant`) ON DELETE CASCADE,
  CONSTRAINT `chk_finance_solde_total` CHECK (`total_paye` >= 0 and `total_paye` <= 9800)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `finance_solde`
--

LOCK TABLES `finance_solde` WRITE;
/*!40000 ALTER TABLE `finance_solde` DISABLE KEYS */;
INSERT INTO `finance_solde` VALUES (8,7000.00),(68,7000.00);
/*!40000 ALTER TABLE `finance_solde` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `finance_user`
--

DROP TABLE IF EXISTS `finance_user`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `finance_user` (
  `id_finance` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(80) NOT NULL,
  `password` varchar(255) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id_finance`),
  UNIQUE KEY `uq_finance_username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `finance_user`
--

LOCK TABLES `finance_user` WRITE;
/*!40000 ALTER TABLE `finance_user` DISABLE KEYS */;
INSERT INTO `finance_user` VALUES (1,'mondher','$2y$10$1cm6nCynw64PVTbPk0wVAec9qFYYByOGVjFZkmtpa7IOH/ZRrPO8u',1);
/*!40000 ALTER TABLE `finance_user` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `groupe`
--

DROP TABLE IF EXISTS `groupe`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `groupe` (
  `id_groupe` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `semestre` enum('S1','S2','S3','S4','S5','S6') NOT NULL,
  `type_groupe` enum('Cours','TD') NOT NULL,
  `numero` smallint(5) unsigned NOT NULL,
  `capacite` smallint(5) unsigned NOT NULL,
  PRIMARY KEY (`id_groupe`),
  UNIQUE KEY `uq_groupe` (`semestre`,`type_groupe`,`numero`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `groupe`
--

LOCK TABLES `groupe` WRITE;
/*!40000 ALTER TABLE `groupe` DISABLE KEYS */;
INSERT INTO `groupe` VALUES (1,'S1','Cours',1,50),(2,'S1','TD',1,25),(3,'S2','Cours',1,50),(4,'S2','TD',1,25),(5,'S3','Cours',1,50),(6,'S3','TD',1,25),(7,'S4','Cours',1,50),(8,'S4','TD',1,25),(9,'S5','Cours',1,50),(10,'S5','TD',1,25),(11,'S6','Cours',1,50),(12,'S6','TD',1,25),(13,'S1','Cours',2,50),(14,'S1','Cours',3,50),(15,'S2','Cours',2,50),(16,'S2','Cours',3,50),(17,'S3','Cours',2,50),(18,'S3','Cours',3,50);
/*!40000 ALTER TABLE `groupe` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `groupe_etudiant`
--

DROP TABLE IF EXISTS `groupe_etudiant`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `groupe_etudiant` (
  `id_groupe` int(10) unsigned NOT NULL,
  `id_etudiant` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id_groupe`,`id_etudiant`),
  KEY `idx_groupe_etudiant_etudiant` (`id_etudiant`),
  CONSTRAINT `fk_groupe_etudiant_etudiant` FOREIGN KEY (`id_etudiant`) REFERENCES `etudiant` (`id_etudiant`) ON DELETE CASCADE,
  CONSTRAINT `fk_groupe_etudiant_groupe` FOREIGN KEY (`id_groupe`) REFERENCES `groupe` (`id_groupe`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `groupe_etudiant`
--

LOCK TABLES `groupe_etudiant` WRITE;
/*!40000 ALTER TABLE `groupe_etudiant` DISABLE KEYS */;
INSERT INTO `groupe_etudiant` VALUES (1,1),(1,4),(1,7),(1,10),(1,13),(1,16),(1,19),(1,22),(1,25),(1,28),(1,31),(1,34),(1,37),(1,40),(1,43),(1,46),(1,49),(1,52),(1,55),(1,58),(1,61),(1,64),(1,67),(1,70),(1,73),(1,76),(1,79),(1,82),(1,85),(1,88),(1,91),(1,94),(1,97),(1,100),(1,103),(1,106),(1,109),(1,112),(1,115),(1,118),(3,1),(3,4),(3,7),(3,10),(3,13),(3,16),(3,19),(3,22),(3,25),(3,28),(3,31),(3,34),(3,37),(3,40),(3,43),(3,46),(3,49),(3,52),(3,55),(3,58),(3,61),(3,64),(3,67),(3,70),(3,73),(3,76),(3,79),(3,82),(3,85),(3,88),(3,91),(3,94),(3,97),(3,100),(3,103),(3,106),(3,109),(3,112),(3,115),(3,118),(5,1),(5,4),(5,7),(5,10),(5,13),(5,16),(5,19),(5,22),(5,25),(5,28),(5,31),(5,34),(5,37),(5,40),(5,43),(5,46),(5,49),(5,52),(5,55),(5,58),(5,61),(5,64),(5,67),(5,70),(5,73),(5,76),(5,79),(5,82),(5,85),(5,88),(5,91),(5,94),(5,97),(5,100),(5,103),(5,106),(5,109),(5,112),(5,115),(5,118),(13,2),(13,5),(13,8),(13,11),(13,14),(13,17),(13,20),(13,23),(13,26),(13,29),(13,32),(13,35),(13,38),(13,41),(13,44),(13,47),(13,50),(13,53),(13,56),(13,59),(13,62),(13,65),(13,68),(13,71),(13,74),(13,77),(13,80),(13,83),(13,86),(13,89),(13,92),(13,95),(13,98),(13,101),(13,104),(13,107),(13,110),(13,113),(13,116),(13,119),(14,3),(14,6),(14,9),(14,12),(14,15),(14,18),(14,21),(14,24),(14,27),(14,30),(14,33),(14,36),(14,39),(14,42),(14,45),(14,48),(14,51),(14,54),(14,57),(14,60),(14,63),(14,66),(14,69),(14,72),(14,75),(14,78),(14,81),(14,84),(14,87),(14,90),(14,93),(14,96),(14,99),(14,102),(14,105),(14,108),(14,111),(14,114),(14,117),(14,120),(15,2),(15,5),(15,8),(15,11),(15,14),(15,17),(15,20),(15,23),(15,26),(15,29),(15,32),(15,35),(15,38),(15,41),(15,44),(15,47),(15,50),(15,53),(15,56),(15,59),(15,62),(15,65),(15,68),(15,71),(15,74),(15,77),(15,80),(15,83),(15,86),(15,89),(15,92),(15,95),(15,98),(15,101),(15,104),(15,107),(15,110),(15,113),(15,116),(15,119),(16,3),(16,6),(16,9),(16,12),(16,15),(16,18),(16,21),(16,24),(16,27),(16,30),(16,33),(16,36),(16,39),(16,42),(16,45),(16,48),(16,51),(16,54),(16,57),(16,60),(16,63),(16,66),(16,69),(16,72),(16,75),(16,78),(16,81),(16,84),(16,87),(16,90),(16,93),(16,96),(16,99),(16,102),(16,105),(16,108),(16,111),(16,114),(16,117),(16,120),(17,2),(17,5),(17,8),(17,11),(17,14),(17,17),(17,20),(17,23),(17,26),(17,29),(17,32),(17,35),(17,38),(17,41),(17,44),(17,47),(17,50),(17,53),(17,56),(17,59),(17,62),(17,65),(17,68),(17,71),(17,74),(17,77),(17,80),(17,83),(17,86),(17,89),(17,92),(17,95),(17,98),(17,101),(17,104),(17,107),(17,110),(17,113),(17,116),(17,119),(18,3),(18,6),(18,9),(18,12),(18,15),(18,18),(18,21),(18,24),(18,27),(18,30),(18,33),(18,36),(18,39),(18,42),(18,45),(18,48),(18,51),(18,54),(18,57),(18,60),(18,63),(18,66),(18,69),(18,72),(18,75),(18,78),(18,81),(18,84),(18,87),(18,90),(18,93),(18,96),(18,99),(18,102),(18,105),(18,108),(18,111),(18,114),(18,117),(18,120);
/*!40000 ALTER TABLE `groupe_etudiant` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `matiere`
--

DROP TABLE IF EXISTS `matiere`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `matiere` (
  `id_mat` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code_ue` varchar(30) NOT NULL,
  `description_ue` varchar(255) NOT NULL,
  `code_ecue` varchar(30) NOT NULL,
  `description_ecue` varchar(255) NOT NULL,
  `semestre` enum('S1','S2','S3','S4','S5','S6') NOT NULL DEFAULT 'S1',
  `coeff` decimal(4,2) NOT NULL DEFAULT 1.00,
  `credit` decimal(4,1) NOT NULL DEFAULT 0.0,
  `regime` varchar(80) NOT NULL,
  `volume_cours` decimal(5,2) NOT NULL DEFAULT 0.00,
  `volume_td` decimal(5,2) NOT NULL DEFAULT 0.00,
  `volume_tp` decimal(5,2) NOT NULL DEFAULT 0.00,
  `volume_autres` decimal(5,2) NOT NULL DEFAULT 0.00,
  `coefficient_ecue` decimal(4,2) NOT NULL DEFAULT 1.00,
  `coefficient_ue` decimal(4,2) NOT NULL DEFAULT 1.00,
  PRIMARY KEY (`id_mat`),
  UNIQUE KEY `uq_matiere_ecue` (`code_ecue`),
  KEY `idx_matiere_semestre` (`semestre`)
) ENGINE=InnoDB AUTO_INCREMENT=40 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `matiere`
--

LOCK TABLES `matiere` WRITE;
/*!40000 ALTER TABLE `matiere` DISABLE KEYS */;
INSERT INTO `matiere` VALUES (1,'UEF110','Sciences biomédicales 1','UEF111','Anatomie physiologie 1','S1',5.00,5.0,'Mixte',42.00,28.00,0.00,0.00,2.50,2.50),(2,'UEF120','Discipline paramédicale - Biologie - Ethique','UEF121','Diabétologie - Ethique','S1',2.00,0.0,'Mixte',24.00,0.00,0.00,0.00,1.00,2.50),(3,'UEF120','Discipline paramédicale - Biologie - Ethique','UEF122','Education à la discipline','S1',3.00,0.0,'Mixte',30.00,0.00,0.00,0.00,1.50,2.50),(4,'UEF130','Méthodes d’interventions infirmières 1','UEF131','Techniques de soins','S1',3.00,0.0,'Mixte',6.00,36.00,45.00,0.00,1.50,3.00),(5,'UEF130','Méthodes d’interventions infirmières 1','UEF132','Démarche de soins','S1',3.00,0.0,'Mixte',20.00,22.00,0.00,14.00,1.50,3.00),(6,'UEF140','Soins infirmiers en santé communautaire','UEF141','Soins dans la communauté 1','S1',2.00,0.0,'Mixte',21.00,9.00,0.00,12.00,1.00,2.00),(7,'UET120','Techniques de communication et Psychologie du développement social','UET121','Psychologie du développement et développement social','S1',2.00,0.0,'C.C',21.00,0.00,0.00,0.00,1.00,2.00),(8,'UET120','Techniques de communication et Psychologie du développement social','UET122','Techniques de communication 1','S1',2.00,0.0,'C.C',21.00,0.00,0.00,0.00,1.00,2.00),(9,'UET110','Formation transversale 1','UET111','Français Professionnel 1','S1',2.00,0.0,'C.C',21.00,0.00,0.00,0.00,1.00,3.00),(10,'UET110','Formation transversale 1','UET112','Digital (Certif 2CN)','S1',2.00,0.0,'C.C',21.00,0.00,0.00,0.00,1.00,3.00),(11,'UET110','Formation transversale 1','UET113','Study Skills (Quiz)','S1',2.00,0.0,'C.C',21.00,0.00,0.00,0.00,1.00,3.00),(12,'UEF210','Sciences biomédicales 2','UEF211','Biochimie clinique','S2',1.00,1.0,'Mixte',21.00,0.00,0.00,0.00,1.00,3.00),(13,'UEF210','Sciences biomédicales 2','UEF212','Anatomie Physiologie 2','S2',3.00,0.0,'Mixte',28.00,14.00,0.00,0.00,1.00,3.00),(14,'UEF210','Sciences biomédicales 2','UEF213','Pharmacologie 1','S2',2.00,0.0,'Mixte',21.00,0.00,0.00,0.00,1.00,3.00),(15,'UEF220','Soins infirmiers en gynécologie et périnatalité','UEF221','Soins infirmiers en gynécologie et périnatalité','S2',3.00,0.0,'Mixte',30.00,6.00,24.00,0.00,1.50,2.00),(16,'UEF220','Soins infirmiers en gynécologie et périnatalité','UEF222','Hygiène et environnement','S2',1.00,0.0,'Mixte',15.00,9.00,0.00,0.00,0.50,2.00),(17,'UEF230','Méthodes d’interventions infirmières 2','UEF231','Techniques infirmières 2','S2',2.00,0.0,'Mixte',0.00,30.00,0.00,0.00,1.00,2.00),(18,'UEF230','Méthodes d’interventions infirmières 2','UEF232','Relation d’aide','S2',2.00,0.0,'Mixte',20.00,10.00,0.00,0.00,1.00,2.00),(19,'UEF240','Pratiques de soins 1','UEF241','Initiation aux premiers secours','S2',2.00,0.0,'Mixte',6.00,0.00,20.00,0.00,1.00,3.00),(20,'UEF240','Pratiques de soins 1','UEF242','Pratique de soins en médecine-chirurgie 1','S2',4.00,0.0,'Mixte',0.00,0.00,70.00,0.00,2.00,3.00),(21,'UET220','Techniques de Communication, Sociologie et Philosophie des sciences','UET221','Sociologie de la santé','S2',2.00,0.0,'C.C',21.00,0.00,0.00,0.00,0.50,1.00),(22,'UET220','Techniques de Communication, Sociologie et Philosophie des sciences','UET222','Philosophie des Sciences','S2',1.00,0.0,'C.C',28.00,0.00,0.00,0.00,1.00,1.00),(23,'UET220','Techniques de Communication, Sociologie et Philosophie des sciences','UET223','Techniques de communication 2','S2',2.00,0.0,'C.C',14.00,0.00,0.00,0.00,0.50,1.00),(24,'UET210','Formation transversale 2','UET211','Français Pro2','S2',2.00,0.0,'C.C',21.00,0.00,0.00,0.00,1.00,1.50),(25,'UET210','Formation transversale 2','UET212','Anglais médical 1','S2',2.00,0.0,'C.C',21.00,0.00,0.00,0.00,1.00,1.50),(26,'UET210','Formation transversale 2','UET213','Apprendre à apprendre','S2',2.00,0.0,'C.C',21.00,0.00,0.00,0.00,1.00,1.50),(27,'UEF310','Soins infirmiers adultes 1','UEF311','Soins infirmiers en cardiologie','S3',2.00,0.0,'Mixte',16.00,0.00,8.00,0.00,1.00,2.00),(28,'UEF310','Soins infirmiers adultes 1','UEF312','Soins infirmiers en pneumologie','S3',2.00,0.0,'Mixte',14.00,0.00,8.00,0.00,1.00,2.00),(29,'UEF310','Soins infirmiers adultes 1','UEF313','Pharmacologie 2','S3',2.00,0.0,'Mixte',30.00,0.00,0.00,0.00,1.00,2.00),(30,'UEF320','Soins infirmiers adultes 2','UEF321','Soins infirmiers en infectiologie','S3',2.00,0.0,'Mixte',15.00,9.00,0.00,0.00,1.00,2.50),(31,'UEF320','Soins infirmiers adultes 2','UEF322','Soins infirmiers en neurologie','S3',3.00,0.0,'Mixte',24.00,12.00,0.00,0.00,1.50,2.50),(32,'UEF330','Soins infirmiers en pédiatrie','UEF331','Soins infirmiers en pédiatrie','S3',3.00,0.0,'Mixte',30.00,15.00,0.00,0.00,1.50,1.50),(33,'UEF340','Pratiques de soins 2','UEF341','Pratique de soins en médecine-chirurgie 2','S3',6.00,0.0,'Mixte',0.00,0.00,110.00,0.00,3.00,3.00),(34,'UET320','Soins infirmiers en santé au travail et en situation critique','UET321','Santé et sécurité au travail','S3',2.00,0.0,'C.C',20.00,0.00,15.00,0.00,1.00,1.00),(35,'UET320','Soins infirmiers en santé au travail et en situation critique','UET322','Soins infirmiers et santé de l’adolescent','S3',1.00,0.0,'C.C',20.00,0.00,15.00,0.00,0.50,1.00),(36,'UET320','Soins infirmiers en santé au travail et en situation critique','UET323','Soins infirmiers en rhumatologie et Handicap','S3',1.00,0.0,'C.C',20.00,0.00,15.00,0.00,0.50,1.00),(37,'UET310','Formation transversale 3','UET311','Français Pro3','S3',2.00,0.0,'C.C',21.00,0.00,0.00,0.00,1.00,1.00),(38,'UET310','Formation transversale 3','UET312','Anglais médical 2','S3',2.00,0.0,'C.C',21.00,0.00,0.00,0.00,1.00,1.00),(39,'UET310','Formation transversale 3','UET313','Soft Skills (Projet Pro)','S3',2.00,0.0,'C.C',21.00,0.00,0.00,0.00,1.00,1.00);
/*!40000 ALTER TABLE `matiere` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `note`
--

DROP TABLE IF EXISTS `note`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `note` (
  `id_note` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `id_etudiant` int(10) unsigned NOT NULL,
  `id_mat` int(10) unsigned NOT NULL,
  `id_groupe` int(10) unsigned DEFAULT NULL,
  `semestre` enum('S1','S2','S3','S4','S5','S6') NOT NULL,
  `note` decimal(5,2) DEFAULT NULL,
  `note_cours` decimal(5,2) DEFAULT NULL,
  `note_td` decimal(5,2) DEFAULT NULL,
  `note_tp` decimal(5,2) DEFAULT NULL,
  `note_rattrapage` decimal(5,2) DEFAULT NULL,
  PRIMARY KEY (`id_note`),
  UNIQUE KEY `uq_note_etudiant_matiere_semestre` (`id_etudiant`,`id_mat`,`semestre`),
  KEY `idx_note_matiere` (`id_mat`),
  KEY `idx_note_groupe` (`id_groupe`),
  CONSTRAINT `fk_note_etudiant` FOREIGN KEY (`id_etudiant`) REFERENCES `etudiant` (`id_etudiant`) ON DELETE CASCADE,
  CONSTRAINT `fk_note_groupe` FOREIGN KEY (`id_groupe`) REFERENCES `groupe` (`id_groupe`) ON DELETE SET NULL,
  CONSTRAINT `fk_note_matiere` FOREIGN KEY (`id_mat`) REFERENCES `matiere` (`id_mat`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `note`
--

LOCK TABLES `note` WRITE;
/*!40000 ALTER TABLE `note` DISABLE KEYS */;
INSERT INTO `note` VALUES (1,28,9,1,'S1',NULL,12.00,12.00,12.00,NULL),(2,28,39,1,'S1',NULL,12.00,14.00,15.00,NULL);
/*!40000 ALTER TABLE `note` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `specialite`
--

DROP TABLE IF EXISTS `specialite`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `specialite` (
  `id_spec` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nom_spec` varchar(120) NOT NULL,
  `nom_spec_ar` varchar(160) NOT NULL,
  PRIMARY KEY (`id_spec`),
  UNIQUE KEY `uq_specialite_nom` (`nom_spec`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `specialite`
--

LOCK TABLES `specialite` WRITE;
/*!40000 ALTER TABLE `specialite` DISABLE KEYS */;
INSERT INTO `specialite` VALUES (1,'Soins à domicile','العناية المنزلية'),(2,'Soins et accompagnement des personnes âgées','رعاية ومرافقة المسنين'),(3,'Anesthésie-Réanimation','التخدير والإنعاش'),(4,'Commun','الجذع المشترك');
/*!40000 ALTER TABLE `specialite` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_fees_archive`
--

DROP TABLE IF EXISTS `student_fees_archive`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `student_fees_archive` (
  `id_fee_archive` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `id_archive` int(10) unsigned NOT NULL,
  `id_etudiant` int(10) unsigned NOT NULL,
  `annee_scolaire` varchar(9) NOT NULL,
  `source_payment_id` int(10) unsigned DEFAULT NULL,
  `mode_paiement` varchar(30) NOT NULL,
  `montant_paye` decimal(10,2) NOT NULL,
  `payment_created_at` timestamp NULL DEFAULT NULL,
  `payment_updated_at` timestamp NULL DEFAULT NULL,
  `id_finance_user` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id_fee_archive`),
  KEY `idx_archive_fee_student_year` (`id_etudiant`,`annee_scolaire`),
  KEY `fk_fee_archive_header` (`id_archive`),
  CONSTRAINT `fk_fee_archive_header` FOREIGN KEY (`id_archive`) REFERENCES `etudiant_archive` (`id_archive`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_fees_archive`
--

LOCK TABLES `student_fees_archive` WRITE;
/*!40000 ALTER TABLE `student_fees_archive` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_fees_archive` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_grades_archive`
--

DROP TABLE IF EXISTS `student_grades_archive`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `student_grades_archive` (
  `id_archive_grade` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `id_archive` int(10) unsigned NOT NULL,
  `id_etudiant` int(10) unsigned NOT NULL,
  `annee_scolaire` varchar(9) NOT NULL,
  `id_mat` int(10) unsigned DEFAULT NULL,
  `semestre` varchar(5) NOT NULL,
  `note` decimal(5,2) DEFAULT NULL,
  `note_rattrapage` decimal(5,2) DEFAULT NULL,
  `coeff` decimal(4,2) NOT NULL DEFAULT 1.00,
  `credit` decimal(4,1) NOT NULL DEFAULT 0.0,
  `subject_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`subject_payload`)),
  PRIMARY KEY (`id_archive_grade`),
  KEY `idx_archive_grade_student_year` (`id_etudiant`,`annee_scolaire`),
  KEY `fk_archive_grade_header` (`id_archive`),
  CONSTRAINT `fk_archive_grade_header` FOREIGN KEY (`id_archive`) REFERENCES `etudiant_archive` (`id_archive`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_grades_archive`
--

LOCK TABLES `student_grades_archive` WRITE;
/*!40000 ALTER TABLE `student_grades_archive` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_grades_archive` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_transcripts_archive`
--

DROP TABLE IF EXISTS `student_transcripts_archive`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `student_transcripts_archive` (
  `id_transcript_archive` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `id_archive` int(10) unsigned NOT NULL,
  `id_etudiant` int(10) unsigned NOT NULL,
  `annee_scolaire` varchar(9) NOT NULL,
  `transcript_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`transcript_payload`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_transcript_archive`),
  UNIQUE KEY `uq_transcript_archive_year` (`id_etudiant`,`annee_scolaire`),
  KEY `fk_transcript_archive_header` (`id_archive`),
  CONSTRAINT `fk_transcript_archive_header` FOREIGN KEY (`id_archive`) REFERENCES `etudiant_archive` (`id_archive`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_transcripts_archive`
--

LOCK TABLES `student_transcripts_archive` WRITE;
/*!40000 ALTER TABLE `student_transcripts_archive` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_transcripts_archive` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'ecole_sante_gabes'
--

--
-- Dumping routines for database 'ecole_sante_gabes'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-18 22:14:37
