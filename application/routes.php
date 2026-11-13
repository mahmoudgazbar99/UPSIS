<?php
declare(strict_types=1);

/**
 * Maps legacy public script names to files under application/.
 * Browser URLs stay /finance.php, /stage_portal.php, etc.
 */
return [
    'login.php' => 'Auth/login.php',
    'logout.php' => 'Auth/logout.php',
    'connexion_etudiant.php' => 'Auth/connexion_etudiant.php',
    'changer_mot_de_passe.php' => 'Auth/changer_mot_de_passe.php',
    'creer_espace_etudiant.php' => 'Auth/creer_espace_etudiant.php',
    'account_status.php' => 'Auth/account_status.php',

    'creer_comptes.php' => 'Comptes/creer_comptes.php',
    'modifier_comptes.php' => 'Comptes/modifier_comptes.php',
    'activer_comptes.php' => 'Comptes/activer_comptes.php',
    'ajouter_compte_stage.php' => 'Comptes/ajouter_compte_stage.php',
    'finance_users.php' => 'Comptes/finance_users.php',
    'securite_session.php' => 'Comptes/securite_session.php',

    'index.php' => 'Dashboard/index.php',
    'dashboard.php' => 'Dashboard/dashboard.php',

    'inscription.php' => 'Scolarite/inscription.php',
    'inscription_old.php' => 'Scolarite/inscription_old.php',
    'inscription_regles.php' => 'Scolarite/inscription_regles.php',
    'identite.php' => 'Scolarite/identite.php',
    'etablissement.php' => 'Scolarite/etablissement.php',
    'attestations_config.php' => 'Scolarite/attestations_config.php',
    'etudiant_inbox.php' => 'Scolarite/etudiant_inbox.php',
    'student_photo.php' => 'Scolarite/student_photo.php',

    'enseignants.php' => 'Enseignant/enseignants.php',
    'affecter_enseignant.php' => 'Enseignant/affecter_enseignant.php',
    'responsables.php' => 'Enseignant/responsables.php',
    'mes_cours.php' => 'Enseignant/mes_cours.php',

    'tronc_commun.php' => 'Pedagogie/tronc_commun.php',
    'structure_academique.php' => 'Pedagogie/structure_academique.php',
    'specialites.php' => 'Pedagogie/specialites.php',
    'ajouter_matiere.php' => 'Pedagogie/ajouter_matiere.php',
    'ajouter_unite.php' => 'Pedagogie/ajouter_unite.php',
    'groupes.php' => 'Pedagogie/groupes.php',
    'capacite_groupes.php' => 'Pedagogie/capacite_groupes.php',
    'annee_periode.php' => 'Pedagogie/annee_periode.php',
    'formule_calcul.php' => 'Pedagogie/formule_calcul.php',
    'politique_promotion.php' => 'Pedagogie/politique_promotion.php',
    'politique_presence.php' => 'Pedagogie/politique_presence.php',
    'promotion.php' => 'Pedagogie/promotion.php',
    'emploi_temps.php' => 'Pedagogie/emploi_temps.php',
    'emploi_pdf.php' => 'Pedagogie/emploi_pdf.php',
    'cours_fichier.php' => 'Pedagogie/cours_fichier.php',

    'gestion_notes.php' => 'Notes/gestion_notes.php',
    'grille_notes.php' => 'Notes/grille_notes.php',
    'releve_notes.php' => 'Notes/releve_notes.php',
    'resultats_rachat.php' => 'Notes/resultats_rachat.php',
    'envoyer_resultats.php' => 'Notes/envoyer_resultats.php',
    'releve_etudiant.php' => 'Notes/releve_etudiant.php',

    'presence_etudiants.php' => 'Presence/presence_etudiants.php',
    'feuille_presence.php' => 'Presence/feuille_presence.php',
    'generate_feuille_presence.php' => 'Presence/generate_feuille_presence.php',
    'generate_presence.php' => 'Presence/generate_presence.php',
    'alertes_elimination.php' => 'Presence/alertes_elimination.php',
    'demandes_presence.php' => 'Presence/demandes_presence.php',

    'stage_allocation.php' => 'Stage/stage_allocation.php',
    'stage_archives.php' => 'Stage/stage_archives.php',
    'stage_campagne.php' => 'Stage/stage_campagne.php',
    'stage_docs.php' => 'Stage/stage_docs.php',
    'stage_document.php' => 'Stage/stage_document.php',
    'stage_doc_types.php' => 'Stage/stage_doc_types.php',
    'stage_dossier.php' => 'Stage/stage_dossier.php',
    'stage_organismes.php' => 'Stage/stage_organismes.php',
    'stage_placements.php' => 'Stage/stage_placements.php',
    'stage_portal.php' => 'Stage/stage_portal.php',
    'stage_bureau.php' => 'Stage/stage_bureau.php',
    'stage_demande.php' => 'Stage/stage_demande.php',
    'stage_suivi.php' => 'Stage/stage_suivi.php',

    'finance.php' => 'Finance/finance.php',
    'frais_scolarite.php' => 'Finance/frais_scolarite.php',
    'recu_paiement.php' => 'Finance/recu_paiement.php',

    'attestation_inscription.php' => 'Documents/attestation_inscription.php',
    'attestation_live.php' => 'Documents/attestation_live.php',
    'attestation_presence.php' => 'Documents/attestation_presence.php',
    'attestation_reussite.php' => 'Documents/attestation_reussite.php',
    'generate_word.php' => 'Documents/generate_word.php',
    'generate_reussite.php' => 'Documents/generate_reussite.php',
    'download_all_inscriptions.php' => 'Documents/download_all_inscriptions.php',
    'archive_export.php' => 'Documents/archive_export.php',
    'etudiants_export.php' => 'Documents/etudiants_export.php',
    'etudiants_import.php' => 'Scolarite/etudiants_import.php',

    'journal_audit.php' => 'Admin/journal_audit.php',
    'demandes_etudiants.php' => 'Admin/demandes_etudiants.php',
    'demandes_etudiants_live.php' => 'Admin/demandes_etudiants_live.php',
    'fix_utf8.php' => 'Admin/fix_utf8.php',

    'brand_logo.php' => 'support/brand_logo.php',

    'chatbot_api.php' => 'Chatbot/api.php',
];
