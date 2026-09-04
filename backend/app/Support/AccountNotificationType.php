<?php

namespace App\Support;

final class AccountNotificationType
{
    public const PAGE_CLAIM_APPROVED = 'page_claim_approved';

    public const PAGE_CLAIM_REJECTED = 'page_claim_rejected';

    public const PAGE_ASSIGNED = 'page_assigned';

    public const PAGE_DETACHED = 'page_detached';

    public const PAGE_DELETED = 'page_deleted';

    public const PAGE_RATING_RECEIVED = 'page_rating_received';

    public const PAGE_CLAIM_SUBMITTED = 'page_claim_submitted';

    public const LEAD_PAGE_CREATED = 'lead_page_created';

    public const EMAIL_TYPES = [
        self::PAGE_CLAIM_APPROVED,
        self::PAGE_CLAIM_REJECTED,
        self::PAGE_ASSIGNED,
        self::PAGE_DETACHED,
        self::PAGE_DELETED,
    ];

    public const OWNERSHIP_TYPES = [
        self::PAGE_CLAIM_APPROVED,
        self::PAGE_ASSIGNED,
        self::PAGE_DETACHED,
        self::PAGE_DELETED,
    ];

    public const ALL = [
        ...self::EMAIL_TYPES,
        self::PAGE_RATING_RECEIVED,
        self::PAGE_CLAIM_SUBMITTED,
        self::LEAD_PAGE_CREATED,
    ];
}
