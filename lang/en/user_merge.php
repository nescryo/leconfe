<?php

return [
    'action' => 'Merge User',
    'heading' => 'Merge :name into another account',
    'description' => 'All roles, submissions, reviews, comments and other data of :email move to the head account you select. This account is then deleted permanently. This cannot be undone.',
    'head_account' => 'Head account',
    'head_account_helper' => 'The email, name, profile and password of this account are kept.',
    'confirm' => 'I understand that the merged account will be deleted permanently.',
    'failed' => 'Merge failed. See the application log.',
    'success' => 'Account merged into :name.',
];
