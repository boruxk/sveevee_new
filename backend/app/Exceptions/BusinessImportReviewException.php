<?php

namespace App\Exceptions;

class BusinessImportReviewException extends BusinessImportException
{
    public function __construct(
        public readonly array $source,
        public readonly array $input,
        public readonly array $candidates,
        public readonly string $reviewReason,
    ) {
        parent::__construct('The source record requires review before it can be associated with a business page.', 409, 'review_required');
    }
}
