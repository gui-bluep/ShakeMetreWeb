<?php

namespace App\Exceptions;

use App\Models\Lot;
use RuntimeException;

/**
 * A lot was about to be awarded to a company that is not the one holding the supplier slot
 * being compared.
 *
 * Its own exception type rather than an InvalidArgumentException: this is not a malformed
 * argument but two pieces of state disagreeing, and the caller may well want to catch exactly
 * this - a comparison screen that passes the slot and the company separately can get them out
 * of step, and the result would be a lot recorded as awarded to the wrong supplier with nothing
 * to reveal it afterwards.
 */
class TenderSupplierMismatchException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $lotId,
        public readonly int $supplierNumber,
        public readonly ?string $expectedCompanyId,
        public readonly string $givenCompanyId,
    ) {
        parent::__construct($message);
    }

    public static function slotHoldsAnotherCompany(
        Lot $lot,
        int $supplierNumber,
        string $expected,
        string $given,
    ): self {
        return new self(
            sprintf(
                'Lot %s cannot be awarded to company %s as supplier %d: that slot holds company %s.',
                $lot->getKey(),
                $given,
                $supplierNumber,
                $expected,
            ),
            (string) $lot->getKey(),
            $supplierNumber,
            $expected,
            $given,
        );
    }

    public static function slotHasNoCompany(Lot $lot, int $supplierNumber, string $given): self
    {
        return new self(
            sprintf(
                'Lot %s cannot be awarded to supplier %d: that slot has no company, so company %s '.
                'never quoted for this lot.',
                $lot->getKey(),
                $supplierNumber,
                $given,
            ),
            (string) $lot->getKey(),
            $supplierNumber,
            null,
            $given,
        );
    }
}
