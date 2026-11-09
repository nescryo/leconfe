<?php

return [
    'action' => 'Merge User',
    'heading' => 'Merge :name into another account',
    'description' => 'All roles, submissions, reviews, comments and other data of :email move to the head account you select. This account is then deleted permanently. This cannot be undone.',
    'head_account' => 'Head account',
    'head_account_helper' => 'The email, name, profile and password of this account are kept.',
    'confirm' => 'I understand that the merged account will be deleted permanently.',
    'failed' => 'Merge failed. See the application log.',
    'blocked_no_editor' => 'Submission #:id would be left without an editor. Assign another editor to it, then merge again.',
    'blocked_reviews' => 'Both accounts completed a review on submission #:id. Remove one of the reviews, then merge again.',
    'preview_participant' => 'Submission #:id: the :role assignment of :name will be released.',
    'preview_review' => 'Submission #:id: a duplicate review will be removed.',
    'preview_heading' => 'Effects of this merge',
    'success' => 'Account merged into :name.',
];
