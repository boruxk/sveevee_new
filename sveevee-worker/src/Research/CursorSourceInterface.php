<?php

declare(strict_types=1);

namespace Sveevee\Worker\Research;

/** Advance only after a source row or its rejection is durable. */
interface CursorSourceInterface extends SourceAdapterInterface
{
    public function acknowledge(array $raw): void;
}
