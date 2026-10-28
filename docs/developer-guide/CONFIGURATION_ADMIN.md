# Configuration de l’établissement

L’installation reste une université par serveur. Les valeurs d’origine sont celles de l’école actuelle. Les changer n’efface pas les étudiants, les notes, les paiements ni les archives.

Avant la première ouverture après cette mise à jour, sauvegarder la base :

```
docker exec myapp_db mysqldump -umyapp_user -pmyapp_password ecole_sante_gabes > backup.sql
```

La migration s’applique toute seule au chargement d’une page (`academic_policy.php`). Elle élargit les colonnes `niveau` et `semestre` pour accepter d’autres codes, retire le plafond SQL de 9 800 DT sur le solde, et crée les catalogues. Elle n’écrase pas un réglage déjà enregistré.

## Où régler

Menu **Configuration** du tableau de bord administrateur :

- **Structure académique** : programmes, diplôme, périodes (S1–S6 ou un autre code), niveaux.
- **Spécialités** : créer, activer, désactiver.
- **Frais de scolarité** : montant et devise. Confirmation `PUBLIER`. Les archives gardent l’ancien montant.
- **Seuil de promotion** : note d’admission. La validation demande toujours le mot de passe et `PROMOUVOIR`.
- **Règles de présence** : absences par matière, pourcentage, volume horaire, durée de séance.
- **Pièces d’identité** : longueur du CIN et du passeport.

Le nom, le logo et la ville sont dans le menu engrenage, **Nom et logo de l’université**. Le secrétaire, le directeur et les formules de moyenne restent à leur place actuelle.

## Ce qui ne change pas tout seul

Une archive, une décision de promotion déjà enregistrée et un paiement déjà saisi ne sont pas recalculés. Les alertes de présence, elles, lisent la politique publiée.

La grille des horaires, les rôles personnalisés au-delà de scolarité et stage, et les modèles Word téléversés depuis l’écran ne font pas partie de cette étape.

Les comptes **Scolarité** et **Stage** sont décrits dans `SCOLARITE_GUIDE.md` et `STAGE_GUIDE.md`. Le stage réutilise `stage_user`. La scolarité utilise la table `scolarite_user`. Les deux passent par la connexion existante.
