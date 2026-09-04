<?php

return [
    'verification' => [
        'subject' => 'Confirmez votre adresse e-mail Sveevee',
        'heading' => 'Confirmez votre adresse e-mail',
        'body' => 'Confirmez que cette adresse e-mail vous appartient afin d’activer les fonctions liées aux e-mails dans votre profil.',
        'action' => 'Confirmer l’adresse',
        'expires' => 'Ce lien est valable pendant 24 heures. Si vous ne l’avez pas demandé, vous pouvez ignorer cet e-mail.',
    ],
    'chat' => [
        'subject' => 'Nouveau message de :sender',
        'heading' => 'Vous avez un nouveau message',
        'body' => ':sender vous a envoyé un message sur Sveevee.',
        'action' => 'Ouvrir le chat',
        'privacy' => 'Pour protéger votre vie privée, le contenu du message n’est pas inclus dans cet e-mail.',
    ],
    'account' => [
        'page_claim_approved' => [
            'subject' => 'Votre demande pour :page a été approuvée',
            'heading' => 'Cette page est maintenant à vous',
            'body' => 'Nous avons approuvé votre demande de propriété pour :page. Vous pouvez maintenant gérer la page depuis votre compte Sveevee.',
            'body_replaced' => 'Nous avons approuvé votre demande de propriété pour :page. Votre ancienne page, :replaced_page, a été remplacée.',
            'action' => 'Gérer la page',
        ],
        'page_claim_rejected' => [
            'subject' => 'Mise à jour de votre demande pour :page',
            'heading' => "Votre demande de propriété n'a pas été approuvée",
            'body' => "Nous avons examiné votre demande de propriété pour :page et n'avons pas pu l'approuver.",
            'body_claimed' => 'Une autre demande de propriété vérifiée pour :page a été approuvée. Votre demande en attente a donc été clôturée.',
            'action' => 'Voir la page',
        ],
        'page_assigned' => [
            'subject' => ':page a été attribuée à votre compte',
            'heading' => 'Une page a été ajoutée à votre compte',
            'body' => ':page vous a été attribuée. Vous pouvez la gérer depuis votre compte Sveevee.',
            'action' => 'Gérer la page',
        ],
        'page_detached' => [
            'subject' => ':page a été retirée de votre compte',
            'heading' => 'Une page a été retirée de votre compte',
            'body' => 'Vous ne gérez plus :page sur Sveevee.',
            'action' => 'Ouvrir Sveevee',
        ],
        'page_deleted' => [
            'subject' => ':page a été supprimée',
            'heading' => 'Une page a été supprimée',
            'body' => ':page a été définitivement supprimée de Sveevee.',
            'action' => 'Ouvrir Sveevee',
        ],
    ],
];
