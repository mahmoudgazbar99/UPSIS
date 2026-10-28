-- Seed 120 tronc-commun students into an existing database.
USE ecole_sante_gabes;

SET @commun_spec_id = (SELECT id_spec FROM specialite WHERE nom_spec = 'Commun' LIMIT 1);

-- 120 deterministic tronc-commun students.
-- The Commun specialty is inserted fourth above, so id_spec = 4.
INSERT IGNORE INTO etudiant
  (nom, prenom, nom_ar, prenom_ar, date_naissance, lieu_naissance,
   lieu_naissance_ar, sexe, nationalite, etat_civil, cin_passeport,
   num_tel, adresse, email, num_inscription, date_inscrit, id_spec,
   niveau, annee_bac, specialite_bac)
VALUES
  ('Ben Salem', 'Ahmed', 'بن سالم', 'أحمد', '2000-01-01', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000001', '20000001', 'Gabès', 'etudiant001@universite-sante.tn', 'TC000001', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Trabelsi', 'Ahmed', 'الطرابلسي', 'أحمد', '2000-02-02', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000002', '20000002', 'Gabès', 'etudiant002@universite-sante.tn', 'TC000002', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Jaziri', 'Ahmed', 'الجزيري', 'أحمد', '2000-03-03', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000003', '20000003', 'Gabès', 'etudiant003@universite-sante.tn', 'TC000003', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Masmoudi', 'Ahmed', 'المسعودي', 'أحمد', '2000-04-04', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000004', '20000004', 'Gabès', 'etudiant004@universite-sante.tn', 'TC000004', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Gharbi', 'Ahmed', 'الغربي', 'أحمد', '2000-05-05', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000005', '20000005', 'Gabès', 'etudiant005@universite-sante.tn', 'TC000005', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Brahmi', 'Ahmed', 'البراهمي', 'أحمد', '2000-06-06', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000006', '20000006', 'Gabès', 'etudiant006@universite-sante.tn', 'TC000006', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Khlifi', 'Ahmed', 'الخليفي', 'أحمد', '2000-07-07', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000007', '20000007', 'Gabès', 'etudiant007@universite-sante.tn', 'TC000007', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Ayari', 'Ahmed', 'العياري', 'أحمد', '2000-08-08', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000008', '20000008', 'Gabès', 'etudiant008@universite-sante.tn', 'TC000008', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Chebbi', 'Ahmed', 'الشابي', 'أحمد', '2000-09-09', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000009', '20000009', 'Gabès', 'etudiant009@universite-sante.tn', 'TC000009', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Dridi', 'Ahmed', 'الدريدي', 'أحمد', '2000-10-10', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000010', '20000010', 'Gabès', 'etudiant010@universite-sante.tn', 'TC000010', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Haddad', 'Ahmed', 'الحداد', 'أحمد', '2000-11-11', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000011', '20000011', 'Gabès', 'etudiant011@universite-sante.tn', 'TC000011', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Mabrouk', 'Ahmed', 'المبروك', 'أحمد', '2000-12-12', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000012', '20000012', 'Gabès', 'etudiant012@universite-sante.tn', 'TC000012', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Hamdi', 'Ahmed', 'الحمدي', 'أحمد', '2000-01-13', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000013', '20000013', 'Gabès', 'etudiant013@universite-sante.tn', 'TC000013', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Kammoun', 'Ahmed', 'الكمون', 'أحمد', '2000-02-14', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000014', '20000014', 'Gabès', 'etudiant014@universite-sante.tn', 'TC000014', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Mejri', 'Ahmed', 'المجري', 'أحمد', '2000-03-15', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000015', '20000015', 'Gabès', 'etudiant015@universite-sante.tn', 'TC000015', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Mansouri', 'Ahmed', 'المنصوري', 'أحمد', '2000-04-16', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000016', '20000016', 'Gabès', 'etudiant016@universite-sante.tn', 'TC000016', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Sassi', 'Ahmed', 'الساسي', 'أحمد', '2000-05-17', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000017', '20000017', 'Gabès', 'etudiant017@universite-sante.tn', 'TC000017', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Saidi', 'Ahmed', 'السعيدي', 'أحمد', '2000-06-18', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000018', '20000018', 'Gabès', 'etudiant018@universite-sante.tn', 'TC000018', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Jebali', 'Ahmed', 'الجبالي', 'أحمد', '2000-07-19', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000019', '20000019', 'Gabès', 'etudiant019@universite-sante.tn', 'TC000019', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Chaabane', 'Ahmed', 'الشعباني', 'أحمد', '2000-08-20', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000020', '20000020', 'Gabès', 'etudiant020@universite-sante.tn', 'TC000020', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Ben Salem', 'Mariem', 'بن سالم', 'مريم', '2000-09-21', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000021', '20000021', 'Gabès', 'etudiant021@universite-sante.tn', 'TC000021', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Trabelsi', 'Mariem', 'الطرابلسي', 'مريم', '2000-10-22', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000022', '20000022', 'Gabès', 'etudiant022@universite-sante.tn', 'TC000022', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Jaziri', 'Mariem', 'الجزيري', 'مريم', '2000-11-23', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000023', '20000023', 'Gabès', 'etudiant023@universite-sante.tn', 'TC000023', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Masmoudi', 'Mariem', 'المسعودي', 'مريم', '2000-12-24', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000024', '20000024', 'Gabès', 'etudiant024@universite-sante.tn', 'TC000024', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Gharbi', 'Mariem', 'الغربي', 'مريم', '2000-01-25', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000025', '20000025', 'Gabès', 'etudiant025@universite-sante.tn', 'TC000025', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Brahmi', 'Mariem', 'البراهمي', 'مريم', '2000-02-26', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000026', '20000026', 'Gabès', 'etudiant026@universite-sante.tn', 'TC000026', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Khlifi', 'Mariem', 'الخليفي', 'مريم', '2000-03-27', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000027', '20000027', 'Gabès', 'etudiant027@universite-sante.tn', 'TC000027', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Ayari', 'Mariem', 'العياري', 'مريم', '2000-04-01', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000028', '20000028', 'Gabès', 'etudiant028@universite-sante.tn', 'TC000028', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Chebbi', 'Mariem', 'الشابي', 'مريم', '2000-05-02', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000029', '20000029', 'Gabès', 'etudiant029@universite-sante.tn', 'TC000029', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Dridi', 'Mariem', 'الدريدي', 'مريم', '2000-06-03', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000030', '20000030', 'Gabès', 'etudiant030@universite-sante.tn', 'TC000030', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Haddad', 'Mariem', 'الحداد', 'مريم', '2000-07-04', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000031', '20000031', 'Gabès', 'etudiant031@universite-sante.tn', 'TC000031', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Mabrouk', 'Mariem', 'المبروك', 'مريم', '2000-08-05', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000032', '20000032', 'Gabès', 'etudiant032@universite-sante.tn', 'TC000032', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Hamdi', 'Mariem', 'الحمدي', 'مريم', '2000-09-06', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000033', '20000033', 'Gabès', 'etudiant033@universite-sante.tn', 'TC000033', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Kammoun', 'Mariem', 'الكمون', 'مريم', '2000-10-07', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000034', '20000034', 'Gabès', 'etudiant034@universite-sante.tn', 'TC000034', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Mejri', 'Mariem', 'المجري', 'مريم', '2000-11-08', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000035', '20000035', 'Gabès', 'etudiant035@universite-sante.tn', 'TC000035', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Mansouri', 'Mariem', 'المنصوري', 'مريم', '2000-12-09', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000036', '20000036', 'Gabès', 'etudiant036@universite-sante.tn', 'TC000036', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Sassi', 'Mariem', 'الساسي', 'مريم', '2000-01-10', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000037', '20000037', 'Gabès', 'etudiant037@universite-sante.tn', 'TC000037', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Saidi', 'Mariem', 'السعيدي', 'مريم', '2000-02-11', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000038', '20000038', 'Gabès', 'etudiant038@universite-sante.tn', 'TC000038', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Jebali', 'Mariem', 'الجبالي', 'مريم', '2000-03-12', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000039', '20000039', 'Gabès', 'etudiant039@universite-sante.tn', 'TC000039', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Chaabane', 'Mariem', 'الشعباني', 'مريم', '2000-04-13', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000040', '20000040', 'Gabès', 'etudiant040@universite-sante.tn', 'TC000040', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Ben Salem', 'Mohamed', 'بن سالم', 'محمد', '2000-05-14', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000041', '20000041', 'Gabès', 'etudiant041@universite-sante.tn', 'TC000041', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Trabelsi', 'Mohamed', 'الطرابلسي', 'محمد', '2000-06-15', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000042', '20000042', 'Gabès', 'etudiant042@universite-sante.tn', 'TC000042', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Jaziri', 'Mohamed', 'الجزيري', 'محمد', '2000-07-16', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000043', '20000043', 'Gabès', 'etudiant043@universite-sante.tn', 'TC000043', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Masmoudi', 'Mohamed', 'المسعودي', 'محمد', '2000-08-17', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000044', '20000044', 'Gabès', 'etudiant044@universite-sante.tn', 'TC000044', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Gharbi', 'Mohamed', 'الغربي', 'محمد', '2000-09-18', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000045', '20000045', 'Gabès', 'etudiant045@universite-sante.tn', 'TC000045', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Brahmi', 'Mohamed', 'البراهمي', 'محمد', '2000-10-19', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000046', '20000046', 'Gabès', 'etudiant046@universite-sante.tn', 'TC000046', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Khlifi', 'Mohamed', 'الخليفي', 'محمد', '2000-11-20', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000047', '20000047', 'Gabès', 'etudiant047@universite-sante.tn', 'TC000047', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Ayari', 'Mohamed', 'العياري', 'محمد', '2000-12-21', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000048', '20000048', 'Gabès', 'etudiant048@universite-sante.tn', 'TC000048', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Chebbi', 'Mohamed', 'الشابي', 'محمد', '2000-01-22', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000049', '20000049', 'Gabès', 'etudiant049@universite-sante.tn', 'TC000049', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Dridi', 'Mohamed', 'الدريدي', 'محمد', '2000-02-23', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000050', '20000050', 'Gabès', 'etudiant050@universite-sante.tn', 'TC000050', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Haddad', 'Mohamed', 'الحداد', 'محمد', '2000-03-24', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000051', '20000051', 'Gabès', 'etudiant051@universite-sante.tn', 'TC000051', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Mabrouk', 'Mohamed', 'المبروك', 'محمد', '2000-04-25', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000052', '20000052', 'Gabès', 'etudiant052@universite-sante.tn', 'TC000052', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Hamdi', 'Mohamed', 'الحمدي', 'محمد', '2000-05-26', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000053', '20000053', 'Gabès', 'etudiant053@universite-sante.tn', 'TC000053', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Kammoun', 'Mohamed', 'الكمون', 'محمد', '2000-06-27', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000054', '20000054', 'Gabès', 'etudiant054@universite-sante.tn', 'TC000054', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Mejri', 'Mohamed', 'المجري', 'محمد', '2000-07-01', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000055', '20000055', 'Gabès', 'etudiant055@universite-sante.tn', 'TC000055', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Mansouri', 'Mohamed', 'المنصوري', 'محمد', '2000-08-02', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000056', '20000056', 'Gabès', 'etudiant056@universite-sante.tn', 'TC000056', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Sassi', 'Mohamed', 'الساسي', 'محمد', '2000-09-03', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000057', '20000057', 'Gabès', 'etudiant057@universite-sante.tn', 'TC000057', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Saidi', 'Mohamed', 'السعيدي', 'محمد', '2000-10-04', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000058', '20000058', 'Gabès', 'etudiant058@universite-sante.tn', 'TC000058', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Jebali', 'Mohamed', 'الجبالي', 'محمد', '2000-11-05', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000059', '20000059', 'Gabès', 'etudiant059@universite-sante.tn', 'TC000059', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Chaabane', 'Mohamed', 'الشعباني', 'محمد', '2000-12-06', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000060', '20000060', 'Gabès', 'etudiant060@universite-sante.tn', 'TC000060', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Ben Salem', 'Amira', 'بن سالم', 'أميرة', '2000-01-07', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000061', '20000061', 'Gabès', 'etudiant061@universite-sante.tn', 'TC000061', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Trabelsi', 'Amira', 'الطرابلسي', 'أميرة', '2000-02-08', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000062', '20000062', 'Gabès', 'etudiant062@universite-sante.tn', 'TC000062', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Jaziri', 'Amira', 'الجزيري', 'أميرة', '2000-03-09', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000063', '20000063', 'Gabès', 'etudiant063@universite-sante.tn', 'TC000063', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Masmoudi', 'Amira', 'المسعودي', 'أميرة', '2000-04-10', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000064', '20000064', 'Gabès', 'etudiant064@universite-sante.tn', 'TC000064', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Gharbi', 'Amira', 'الغربي', 'أميرة', '2000-05-11', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000065', '20000065', 'Gabès', 'etudiant065@universite-sante.tn', 'TC000065', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Brahmi', 'Amira', 'البراهمي', 'أميرة', '2000-06-12', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000066', '20000066', 'Gabès', 'etudiant066@universite-sante.tn', 'TC000066', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Khlifi', 'Amira', 'الخليفي', 'أميرة', '2000-07-13', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000067', '20000067', 'Gabès', 'etudiant067@universite-sante.tn', 'TC000067', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Ayari', 'Amira', 'العياري', 'أميرة', '2000-08-14', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000068', '20000068', 'Gabès', 'etudiant068@universite-sante.tn', 'TC000068', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Chebbi', 'Amira', 'الشابي', 'أميرة', '2000-09-15', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000069', '20000069', 'Gabès', 'etudiant069@universite-sante.tn', 'TC000069', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Dridi', 'Amira', 'الدريدي', 'أميرة', '2000-10-16', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000070', '20000070', 'Gabès', 'etudiant070@universite-sante.tn', 'TC000070', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Haddad', 'Amira', 'الحداد', 'أميرة', '2000-11-17', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000071', '20000071', 'Gabès', 'etudiant071@universite-sante.tn', 'TC000071', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Mabrouk', 'Amira', 'المبروك', 'أميرة', '2000-12-18', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000072', '20000072', 'Gabès', 'etudiant072@universite-sante.tn', 'TC000072', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Hamdi', 'Amira', 'الحمدي', 'أميرة', '2000-01-19', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000073', '20000073', 'Gabès', 'etudiant073@universite-sante.tn', 'TC000073', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Kammoun', 'Amira', 'الكمون', 'أميرة', '2000-02-20', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000074', '20000074', 'Gabès', 'etudiant074@universite-sante.tn', 'TC000074', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Mejri', 'Amira', 'المجري', 'أميرة', '2000-03-21', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000075', '20000075', 'Gabès', 'etudiant075@universite-sante.tn', 'TC000075', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Mansouri', 'Amira', 'المنصوري', 'أميرة', '2000-04-22', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000076', '20000076', 'Gabès', 'etudiant076@universite-sante.tn', 'TC000076', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Sassi', 'Amira', 'الساسي', 'أميرة', '2000-05-23', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000077', '20000077', 'Gabès', 'etudiant077@universite-sante.tn', 'TC000077', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Saidi', 'Amira', 'السعيدي', 'أميرة', '2000-06-24', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000078', '20000078', 'Gabès', 'etudiant078@universite-sante.tn', 'TC000078', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Jebali', 'Amira', 'الجبالي', 'أميرة', '2000-07-25', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000079', '20000079', 'Gabès', 'etudiant079@universite-sante.tn', 'TC000079', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Chaabane', 'Amira', 'الشعباني', 'أميرة', '2000-08-26', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000080', '20000080', 'Gabès', 'etudiant080@universite-sante.tn', 'TC000080', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Ben Salem', 'Youssef', 'بن سالم', 'يوسف', '2000-09-27', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000081', '20000081', 'Gabès', 'etudiant081@universite-sante.tn', 'TC000081', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Trabelsi', 'Youssef', 'الطرابلسي', 'يوسف', '2000-10-01', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000082', '20000082', 'Gabès', 'etudiant082@universite-sante.tn', 'TC000082', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Jaziri', 'Youssef', 'الجزيري', 'يوسف', '2000-11-02', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000083', '20000083', 'Gabès', 'etudiant083@universite-sante.tn', 'TC000083', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Masmoudi', 'Youssef', 'المسعودي', 'يوسف', '2000-12-03', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000084', '20000084', 'Gabès', 'etudiant084@universite-sante.tn', 'TC000084', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Gharbi', 'Youssef', 'الغربي', 'يوسف', '2000-01-04', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000085', '20000085', 'Gabès', 'etudiant085@universite-sante.tn', 'TC000085', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Brahmi', 'Youssef', 'البراهمي', 'يوسف', '2000-02-05', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000086', '20000086', 'Gabès', 'etudiant086@universite-sante.tn', 'TC000086', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Khlifi', 'Youssef', 'الخليفي', 'يوسف', '2000-03-06', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000087', '20000087', 'Gabès', 'etudiant087@universite-sante.tn', 'TC000087', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Ayari', 'Youssef', 'العياري', 'يوسف', '2000-04-07', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000088', '20000088', 'Gabès', 'etudiant088@universite-sante.tn', 'TC000088', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Chebbi', 'Youssef', 'الشابي', 'يوسف', '2000-05-08', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000089', '20000089', 'Gabès', 'etudiant089@universite-sante.tn', 'TC000089', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Dridi', 'Youssef', 'الدريدي', 'يوسف', '2000-06-09', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000090', '20000090', 'Gabès', 'etudiant090@universite-sante.tn', 'TC000090', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Haddad', 'Youssef', 'الحداد', 'يوسف', '2000-07-10', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000091', '20000091', 'Gabès', 'etudiant091@universite-sante.tn', 'TC000091', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Mabrouk', 'Youssef', 'المبروك', 'يوسف', '2000-08-11', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000092', '20000092', 'Gabès', 'etudiant092@universite-sante.tn', 'TC000092', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Hamdi', 'Youssef', 'الحمدي', 'يوسف', '2000-09-12', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000093', '20000093', 'Gabès', 'etudiant093@universite-sante.tn', 'TC000093', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Kammoun', 'Youssef', 'الكمون', 'يوسف', '2000-10-13', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000094', '20000094', 'Gabès', 'etudiant094@universite-sante.tn', 'TC000094', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Mejri', 'Youssef', 'المجري', 'يوسف', '2000-11-14', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000095', '20000095', 'Gabès', 'etudiant095@universite-sante.tn', 'TC000095', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Mansouri', 'Youssef', 'المنصوري', 'يوسف', '2000-12-15', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000096', '20000096', 'Gabès', 'etudiant096@universite-sante.tn', 'TC000096', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Sassi', 'Youssef', 'الساسي', 'يوسف', '2000-01-16', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000097', '20000097', 'Gabès', 'etudiant097@universite-sante.tn', 'TC000097', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Saidi', 'Youssef', 'السعيدي', 'يوسف', '2000-02-17', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000098', '20000098', 'Gabès', 'etudiant098@universite-sante.tn', 'TC000098', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Jebali', 'Youssef', 'الجبالي', 'يوسف', '2000-03-18', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000099', '20000099', 'Gabès', 'etudiant099@universite-sante.tn', 'TC000099', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Chaabane', 'Youssef', 'الشعباني', 'يوسف', '2000-04-19', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000100', '20000100', 'Gabès', 'etudiant100@universite-sante.tn', 'TC000100', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Ben Salem', 'Sarra', 'بن سالم', 'سارة', '2000-05-20', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000101', '20000101', 'Gabès', 'etudiant101@universite-sante.tn', 'TC000101', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Trabelsi', 'Sarra', 'الطرابلسي', 'سارة', '2000-06-21', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000102', '20000102', 'Gabès', 'etudiant102@universite-sante.tn', 'TC000102', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Jaziri', 'Sarra', 'الجزيري', 'سارة', '2000-07-22', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000103', '20000103', 'Gabès', 'etudiant103@universite-sante.tn', 'TC000103', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Masmoudi', 'Sarra', 'المسعودي', 'سارة', '2000-08-23', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000104', '20000104', 'Gabès', 'etudiant104@universite-sante.tn', 'TC000104', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Gharbi', 'Sarra', 'الغربي', 'سارة', '2000-09-24', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000105', '20000105', 'Gabès', 'etudiant105@universite-sante.tn', 'TC000105', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Brahmi', 'Sarra', 'البراهمي', 'سارة', '2000-10-25', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000106', '20000106', 'Gabès', 'etudiant106@universite-sante.tn', 'TC000106', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Khlifi', 'Sarra', 'الخليفي', 'سارة', '2000-11-26', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000107', '20000107', 'Gabès', 'etudiant107@universite-sante.tn', 'TC000107', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Ayari', 'Sarra', 'العياري', 'سارة', '2000-12-27', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000108', '20000108', 'Gabès', 'etudiant108@universite-sante.tn', 'TC000108', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Chebbi', 'Sarra', 'الشابي', 'سارة', '2000-01-01', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000109', '20000109', 'Gabès', 'etudiant109@universite-sante.tn', 'TC000109', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Dridi', 'Sarra', 'الدريدي', 'سارة', '2000-02-02', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000110', '20000110', 'Gabès', 'etudiant110@universite-sante.tn', 'TC000110', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Haddad', 'Sarra', 'الحداد', 'سارة', '2000-03-03', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000111', '20000111', 'Gabès', 'etudiant111@universite-sante.tn', 'TC000111', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Mabrouk', 'Sarra', 'المبروك', 'سارة', '2000-04-04', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000112', '20000112', 'Gabès', 'etudiant112@universite-sante.tn', 'TC000112', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Hamdi', 'Sarra', 'الحمدي', 'سارة', '2000-05-05', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000113', '20000113', 'Gabès', 'etudiant113@universite-sante.tn', 'TC000113', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Kammoun', 'Sarra', 'الكمون', 'سارة', '2000-06-06', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000114', '20000114', 'Gabès', 'etudiant114@universite-sante.tn', 'TC000114', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Mejri', 'Sarra', 'المجري', 'سارة', '2000-07-07', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000115', '20000115', 'Gabès', 'etudiant115@universite-sante.tn', 'TC000115', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Mansouri', 'Sarra', 'المنصوري', 'سارة', '2000-08-08', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000116', '20000116', 'Gabès', 'etudiant116@universite-sante.tn', 'TC000116', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Sassi', 'Sarra', 'الساسي', 'سارة', '2000-09-09', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000117', '20000117', 'Gabès', 'etudiant117@universite-sante.tn', 'TC000117', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Saidi', 'Sarra', 'السعيدي', 'سارة', '2000-10-10', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000118', '20000118', 'Gabès', 'etudiant118@universite-sante.tn', 'TC000118', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Jebali', 'Sarra', 'الجبالي', 'سارة', '2000-11-11', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000119', '20000119', 'Gabès', 'etudiant119@universite-sante.tn', 'TC000119', '2026-09-01', @commun_spec_id, '1', 2020, 'Science'),
  ('Chaabane', 'Sarra', 'الشعباني', 'سارة', '2000-12-12', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000120', '20000120', 'Gabès', 'etudiant120@universite-sante.tn', 'TC000120', '2026-09-01', @commun_spec_id, '1', 2020, 'Science');

-- Ensure three Cours groups are available for each populated semester.
INSERT IGNORE INTO groupe (semestre, type_groupe, numero, capacite) VALUES
  ('S1', 'Cours', 1, 50), ('S1', 'Cours', 2, 50), ('S1', 'Cours', 3, 50),
  ('S2', 'Cours', 1, 50), ('S2', 'Cours', 2, 50), ('S2', 'Cours', 3, 50),
  ('S3', 'Cours', 1, 50), ('S3', 'Cours', 2, 50), ('S3', 'Cours', 3, 50);

-- Remove old Cours/TD memberships before applying the balanced Cours assignment.
DELETE ge
FROM groupe_etudiant ge
JOIN groupe g ON g.id_groupe = ge.id_groupe
JOIN etudiant e ON e.id_etudiant = ge.id_etudiant
WHERE e.id_spec = @commun_spec_id
  AND g.semestre IN ('S1', 'S2', 'S3');

INSERT IGNORE INTO groupe_etudiant (id_groupe, id_etudiant)
SELECT g.id_groupe, e.id_etudiant
FROM groupe g
CROSS JOIN etudiant e
WHERE e.id_spec = @commun_spec_id
  AND g.semestre IN ('S1', 'S2', 'S3')
  AND g.type_groupe = 'Cours'
  AND g.numero = MOD(CAST(SUBSTRING(e.cin_passeport, 3) AS UNSIGNED) - 1, 3) + 1;


