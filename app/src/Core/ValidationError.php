<?php
declare(strict_types=1);

namespace Sofrexa\Core;

/** Form errors keyed by field name; the message is the first error. */
final class ValidationError extends \InvalidArgumentException
{
    public function __construct(public readonly array $errors)
    {
        parent::__construct((string) reset($errors));
    }
}
