<?php

return [
    'action' => 'Fusionner l’utilisateur',
    'heading' => 'Fusionner :name avec un autre compte',
    'description' => 'Tous les rôles, soumissions, évaluations, commentaires et autres données de :email sont transférés vers le compte principal choisi. Ce compte est ensuite supprimé définitivement. Cette action est irréversible.',
    'head_account' => 'Compte principal',
    'head_account_helper' => 'L’e-mail, le nom, le profil et le mot de passe de ce compte sont conservés.',
    'confirm' => 'Je comprends que le compte fusionné sera supprimé définitivement.',
    'failed' => "La fusion a échoué. Consultez le journal de l'application.",
    'blocked_no_editor' => "La soumission #:id n'aurait plus d'éditeur. Assignez-en un autre, puis relancez la fusion.",
    'blocked_reviews' => 'Les deux comptes ont terminé une évaluation sur la soumission #:id. Supprimez-en une, puis relancez la fusion.',
    'preview_participant' => "Soumission #:id : l'affectation :role de :name sera retirée.",
    'preview_review' => 'Soumission #:id : une évaluation en double sera supprimée.',
    'preview_heading' => 'Effets de cette fusion',
    'success' => 'Compte fusionné avec :name.',
];
