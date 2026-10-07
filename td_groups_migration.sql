USE ecole_sante_gabes;

ALTER TABLE groupe
  ADD COLUMN id_groupe_parent INT(10) UNSIGNED DEFAULT NULL AFTER type_groupe,
  ADD KEY idx_groupe_parent (id_groupe_parent),
  ADD CONSTRAINT fk_groupe_parent
    FOREIGN KEY (id_groupe_parent) REFERENCES groupe (id_groupe)
    ON DELETE CASCADE;

UPDATE groupe td
JOIN groupe cours
  ON cours.semestre = td.semestre
 AND cours.type_groupe = 'Cours'
 AND cours.numero = FLOOR((td.numero + 1) / 2)
SET td.id_groupe_parent = cours.id_groupe,
    td.numero = ((cours.numero - 1) * 2) + MOD(td.numero - 1, 2) + 1
WHERE td.type_groupe = 'TD'
  AND td.id_groupe_parent IS NULL;

INSERT INTO groupe (semestre, type_groupe, numero, capacite, id_groupe_parent)
SELECT cours.semestre,
       'TD',
       ((cours.numero - 1) * 2) + ordinale.numero_td,
       GREATEST(1, CEILING(cours.capacite / 2)),
       cours.id_groupe
FROM groupe cours
CROSS JOIN (
    SELECT 1 AS numero_td
    UNION ALL
    SELECT 2 AS numero_td
) ordinale
LEFT JOIN groupe td
  ON td.id_groupe_parent = cours.id_groupe
 AND td.type_groupe = 'TD'
 AND td.numero = ((cours.numero - 1) * 2) + ordinale.numero_td
WHERE cours.type_groupe = 'Cours'
  AND td.id_groupe IS NULL;

START TRANSACTION;

INSERT IGNORE INTO groupe_etudiant (id_groupe, id_etudiant)
SELECT (
           SELECT cours.id_groupe
           FROM groupe cours
           WHERE cours.semestre = cohort.semestre
             AND cours.type_groupe = 'Cours'
           ORDER BY RAND()
           LIMIT 1
       ),
       cohort.id_etudiant
FROM (
    SELECT e.id_etudiant, 'S1' AS semestre FROM etudiant e WHERE e.niveau = '1'
    UNION ALL
    SELECT e.id_etudiant, 'S2' AS semestre FROM etudiant e WHERE e.niveau = '1'
    UNION ALL
    SELECT e.id_etudiant, 'S3' AS semestre FROM etudiant e WHERE e.niveau = '2'
    UNION ALL
    SELECT e.id_etudiant, 'S4' AS semestre FROM etudiant e WHERE e.niveau = '2'
    UNION ALL
    SELECT e.id_etudiant, 'S5' AS semestre FROM etudiant e WHERE e.niveau = '3'
    UNION ALL
    SELECT e.id_etudiant, 'S6' AS semestre FROM etudiant e WHERE e.niveau = '3'
) cohort
WHERE EXISTS (
    SELECT 1
    FROM groupe cours
    WHERE cours.semestre = cohort.semestre
      AND cours.type_groupe = 'Cours'
)
AND NOT EXISTS (
    SELECT 1
    FROM groupe_etudiant existing
    JOIN groupe current_group ON current_group.id_groupe = existing.id_groupe
    WHERE existing.id_etudiant = cohort.id_etudiant
      AND current_group.semestre = cohort.semestre
      AND current_group.type_groupe = 'Cours'
);

DELETE membership
FROM groupe_etudiant membership
JOIN groupe td ON td.id_groupe = membership.id_groupe
WHERE td.type_groupe = 'TD';

INSERT INTO groupe_etudiant (id_groupe, id_etudiant)
SELECT (
           SELECT child.id_groupe
           FROM groupe child
           WHERE child.id_groupe_parent = cours.id_groupe
             AND child.type_groupe = 'TD'
           ORDER BY RAND()
           LIMIT 1
       ),
       membership.id_etudiant
FROM groupe_etudiant membership
JOIN groupe cours
  ON cours.id_groupe = membership.id_groupe
 AND cours.type_groupe = 'Cours';

COMMIT;

SELECT cours.semestre, cours.numero, COUNT(td.id_groupe) AS nombre_td
FROM groupe cours
LEFT JOIN groupe td
  ON td.id_groupe_parent = cours.id_groupe
 AND td.type_groupe = 'TD'
WHERE cours.type_groupe = 'Cours'
GROUP BY cours.id_groupe, cours.semestre, cours.numero
HAVING COUNT(td.id_groupe) <> 2;

SELECT cours.semestre, cours.numero, membership.id_etudiant
FROM groupe_etudiant membership
JOIN groupe cours
  ON cours.id_groupe = membership.id_groupe
 AND cours.type_groupe = 'Cours'
LEFT JOIN groupe_etudiant td_membership
  ON td_membership.id_etudiant = membership.id_etudiant
LEFT JOIN groupe td
  ON td.id_groupe = td_membership.id_groupe
 AND td.id_groupe_parent = cours.id_groupe
 AND td.type_groupe = 'TD'
WHERE td.id_groupe IS NULL;
