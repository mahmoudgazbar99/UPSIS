USE ecole_sante_gabes;

ALTER TABLE groupe MODIFY COLUMN semestre ENUM('S1','S2','S3','S4','S5','S6') NOT NULL;

INSERT IGNORE INTO matiere
  (code_ue, description_ue, code_ecue, description_ecue, semestre, coeff, credit, regime,
   volume_cours, volume_td, volume_tp, volume_autres, coefficient_ecue, coefficient_ue)
VALUES
('UEF110','Sciences biomédicales 1','UEF111','Anatomie physiologie 1','S1',5,5,'Mixte',42,28,0,0,2.5,2.5),
('UEF120','Discipline paramédicale - Biologie - Ethique','UEF121','Diabétologie - Ethique','S1',2,0,'Mixte',24,0,0,0,1,2.5),
('UEF120','Discipline paramédicale - Biologie - Ethique','UEF122','Education à la discipline','S1',3,0,'Mixte',30,0,0,0,1.5,2.5),
('UEF130','Méthodes d’interventions infirmières 1','UEF131','Techniques de soins','S1',3,0,'Mixte',6,36,45,0,1.5,3),
('UEF130','Méthodes d’interventions infirmières 1','UEF132','Démarche de soins','S1',3,0,'Mixte',20,22,0,14,1.5,3),
('UEF140','Soins infirmiers en santé communautaire','UEF141','Soins dans la communauté 1','S1',2,0,'Mixte',21,9,0,12,1,2),
('UET120','Techniques de communication et Psychologie du développement social','UET121','Psychologie du développement et développement social','S1',2,0,'C.C',21,0,0,0,1,2),
('UET120','Techniques de communication et Psychologie du développement social','UET122','Techniques de communication 1','S1',2,0,'C.C',21,0,0,0,1,2),
('UET110','Formation transversale 1','UET111','Français Professionnel 1','S1',2,0,'C.C',21,0,0,0,1,3),
('UET110','Formation transversale 1','UET112','Digital (Certif 2CN)','S1',2,0,'C.C',21,0,0,0,1,3),
('UET110','Formation transversale 1','UET113','Study Skills (Quiz)','S1',2,0,'C.C',21,0,0,0,1,3);
