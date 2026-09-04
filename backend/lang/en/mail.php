<?php

return [
    'verification' => [
        'subject' => 'Verify your Sveevee email address',
        'heading' => 'Verify your email address',
        'body' => 'Confirm that this email address belongs to you to enable email-based features in your profile.',
        'action' => 'Verify email',
        'expires' => 'This link is valid for 24 hours. If you did not request it, you can ignore this email.',
    ],
    'chat' => [
        'subject' => 'New chat message from :sender',
        'heading' => 'You have a new chat message',
        'body' => ':sender sent you a message on Sveevee.',
        'action' => 'Open chat',
        'privacy' => 'For your privacy, the message content is not included in this email.',
    ],
    'account' => [
        'page_claim_approved' => [
            'subject' => 'Your claim for :page was approved',
            'heading' => 'The page is now yours',
            'body' => 'We approved your ownership request for :page. You can manage the page from your Sveevee account now.',
            'body_replaced' => 'We approved your ownership request for :page. Your previous page, :replaced_page, was replaced.',
            'action' => 'Manage page',
        ],
        'page_claim_rejected' => [
            'subject' => 'Update about your claim for :page',
            'heading' => 'Your ownership request was not approved',
            'body' => 'We reviewed your ownership request for :page and could not approve it.',
            'body_claimed' => 'Another verified ownership request for :page was approved, so your pending request was closed.',
            'action' => 'View page',
        ],
        'page_assigned' => [
            'subject' => ':page was assigned to your account',
            'heading' => 'A page was added to your account',
            'body' => ':page has been assigned to you. You can manage it from your Sveevee account.',
            'action' => 'Manage page',
        ],
        'page_detached' => [
            'subject' => ':page was removed from your account',
            'heading' => 'A page was removed from your account',
            'body' => 'You no longer manage :page on Sveevee.',
            'action' => 'Open Sveevee',
        ],
        'page_deleted' => [
            'subject' => ':page was deleted',
            'heading' => 'A page was deleted',
            'body' => ':page was permanently deleted from Sveevee.',
            'action' => 'Open Sveevee',
        ],
    ],
];
