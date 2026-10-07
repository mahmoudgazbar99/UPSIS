USE ecole_sante_gabes;

ALTER TABLE matiere
  ADD COLUMN semestre ENUM('S1','S2','S3','S4','S5','S6') NOT NULL DEFAULT 'S1' AFTER code_ecue,
  ADD COLUMN volume_cours DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER regime,
  ADD COLUMN volume_td DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER volume_cours,
  ADD COLUMN volume_tp DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER volume_td,
  ADD COLUMN volume_autres DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER volume_tp,
  ADD COLUMN coefficient_ecue DECIMAL(4,2) NOT NULL DEFAULT 1 AFTER volume_autres,
  ADD COLUMN coefficient_ue DECIMAL(4,2) NOT NULL DEFAULT 1 AFTER coefficient_ecue;

ALTER TABLE note
  MODIFY COLUMN note DECIMAL(5,2) NULL,
  ADD COLUMN id_groupe INT UNSIGNED NULL AFTER id_mat,
  ADD COLUMN note_cours DECIMAL(5,2) NULL AFTER note,
  ADD COLUMN note_td DECIMAL(5,2) NULL AFTER note_cours,
  ADD COLUMN note_tp DECIMAL(5,2) NULL AFTER note_td;

CREATE TABLE IF NOT EXISTS groupe (
  id_groupe INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  semestre ENUM('S2','S3') NOT NULL,
  type_groupe ENUM('Cours','TD') NOT NULL,
  numero SMALLINT UNSIGNED NOT NULL,
  capacite SMALLINT UNSIGNED NOT NULL,
  UNIQUE KEY unique_group (semestre, type_groupe, numero)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS groupe_etudiant (
  id_groupe INT UNSIGNED NOT NULL,
  id_etudiant INT UNSIGNED NOT NULL,
  PRIMARY KEY (id_groupe, id_etudiant),
  UNIQUE KEY one_group_per_semester (id_etudiant, id_groupe),
  CONSTRAINT fk_groupe_etudiant_groupe FOREIGN KEY (id_groupe) REFERENCES groupe(id_groupe) ON DELETE CASCADE,
  CONSTRAINT fk_groupe_etudiant_etudiant FOREIGN KEY (id_etudiant) REFERENCES etudiant(id_etudiant) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO matiere
  (code_ue, description_ue, code_ecue, description_ecue, semestre, coeff, credit, regime,
   volume_cours, volume_td, volume_tp, volume_autres, coefficient_ecue, coefficient_ue)
VALUES
('UEF210','Sciences biomédicales 2','UEF211','Biochimie clinique', 'S2',1,1,'Mixte',21,0,0,0,1,3),
('UEF210','Sciences biomédicales 2','UEF212','Anatomie Physiologie 2', 'S2',3,0,'Mixte',28,14,0,0,1,3),
('UEF210','Sciences biomédicales 2','UEF213','Pharmacologie 1', 'S2',2,0,'Mixte',21,0,0,0,1,3),
('UEF220','Soins infirmiers en gynécologie et périnatalité','UEF221','Soins infirmiers en gynécologie et périnatalité', 'S2',3,0,'Mixte',30,6,24,0,1.5,2),
('UEF220','Soins infirmiers en gynécologie et périnatalité','UEF222','Hygiène et environnement', 'S2',1,0,'Mixte',15,9,0,0,0.5,2),
('UEF230','Méthodes d’interventions infirmières 2','UEF231','Techniques infirmières 2', 'S2',2,0,'Mixte',0,30,0,0,1,2),
('UEF230','Méthodes d’interventions infirmières 2','UEF232','Relation d’aide', 'S2',2,0,'Mixte',20,10,0,0,1,2),
('UEF240','Pratiques de soins 1','UEF241','Initiation aux premiers secours', 'S2',2,0,'Mixte',6,0,20,0,1,3),
('UEF240','Pratiques de soins 1','UEF242','Pratique de soins en médecine-chirurgie 1', 'S2',4,0,'Mixte',0,0,70,0,2,3),
('UET220','Techniques de Communication, Sociologie et Philosophie des sciences','UET221','Sociologie de la santé', 'S2',2,0,'C.C',21,0,0,0,0.5,1),
('UET220','Techniques de Communication, Sociologie et Philosophie des sciences','UET222','Philosophie des Sciences', 'S2',1,0,'C.C',28,0,0,0,1,1),
('UET220','Techniques de Communication, Sociologie et Philosophie des sciences','UET223','Techniques de communication 2', 'S2',2,0,'C.C',14,0,0,0,0.5,1),
('UET210','Formation transversale 2','UET211','Français Pro2', 'S2',2,0,'C.C',21,0,0,0,1,1.5),
('UET210','Formation transversale 2','UET212','Anglais médical 1', 'S2',2,0,'C.C',21,0,0,0,1,1.5),
('UET210','Formation transversale 2','UET213','Apprendre à apprendre', 'S2',2,0,'C.C',21,0,0,0,1,1.5),
('UEF310','Soins infirmiers adultes 1','UEF311','Soins infirmiers en cardiologie', 'S3',2,0,'Mixte',16,0,8,0,1,2),
('UEF310','Soins infirmiers adultes 1','UEF312','Soins infirmiers en pneumologie', 'S3',2,0,'Mixte',14,0,8,0,1,2),
('UEF310','Soins infirmiers adultes 1','UEF313','Pharmacologie 2', 'S3',2,0,'Mixte',30,0,0,0,1,2),
('UEF320','Soins infirmiers adultes 2','UEF321','Soins infirmiers en infectiologie', 'S3',2,0,'Mixte',15,9,0,0,1,2.5),
('UEF320','Soins infirmiers adultes 2','UEF322','Soins infirmiers en neurologie', 'S3',3,0,'Mixte',24,12,0,0,1.5,2.5),
('UEF330','Soins infirmiers en pédiatrie','UEF331','Soins infirmiers en pédiatrie', 'S3',3,0,'Mixte',30,15,0,0,1.5,1.5),
('UEF340','Pratiques de soins 2','UEF341','Pratique de soins en médecine-chirurgie 2', 'S3',6,0,'Mixte',0,0,110,0,3,3),
('UET320','Soins infirmiers en santé au travail et en situation critique','UET321','Santé et sécurité au travail', 'S3',2,0,'C.C',20,0,15,0,1,1),
('UET320','Soins infirmiers en santé au travail et en situation critique','UET322','Soins infirmiers et santé de l’adolescent', 'S3',1,0,'C.C',20,0,15,0,0.5,1),
('UET320','Soins infirmiers en santé au travail et en situation critique','UET323','Soins infirmiers en rhumatologie et Handicap', 'S3',1,0,'C.C',20,0,15,0,0.5,1),
('UET310','Formation transversale 3','UET311','Français Pro3', 'S3',2,0,'C.C',21,0,0,0,1,1),
('UET310','Formation transversale 3','UET312','Anglais médical 2', 'S3',2,0,'C.C',21,0,0,0,1,1),
('UET310','Formation transversale 3','UET313','Soft Skills (Projet Pro)', 'S3',2,0,'C.C',21,0,0,0,1,1);
