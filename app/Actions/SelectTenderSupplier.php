<?php

namespace App\Actions;

use App\Exceptions\TenderSupplierMismatchException;
use App\Models\Lot;
use App\Models\MetreLine;
use InvalidArgumentException;

/**
 * Awards a lot to one of its five candidate suppliers.
 *
 * The decision is a single column: lots.company_id, the ShakeDesign CPY_Companies zkp of the
 * winner. Everything else about the tender - the quotes, the criteria notes, the scores - stays
 * as the record of how the decision was reached, and is not rewritten by making it.
 *
 * The supplier number is not stored. It only identifies which of the five quote columns was
 * being compared, and once a company is recorded the slot adds nothing that
 * tender_supplier_{n}_id does not already say.
 */
class SelectTenderSupplier
{
    /**
     * @throws InvalidArgumentException on a slot outside 1-5 or an empty company
     * @throws TenderSupplierMismatchException when the company is not the one holding that slot
     */
    public function handle(Lot $lot, int $supplierNumber, string $companyZkp): Lot
    {
        if (! in_array($supplierNumber, MetreLine::SUPPLIER_SLOTS, true)) {
            throw new InvalidArgumentException(
                "Supplier slot must be one of 1-5, got {$supplierNumber}."
            );
        }

        $company = trim($companyZkp);

        if ($company === '') {
            throw new InvalidArgumentException('A company zkp is required to award a lot.');
        }

        // The stored slot value is written, not the argument - see below.
        $lot->forceFill([
            'company_id' => $this->canonicalCompanyForSlot($lot, $supplierNumber, $company),
        ])->save();

        return $lot;
    }

    /**
     * Checks that the slot and the company agree, and returns the company as the slot spells it.
     *
     * They arrive as separate arguments, so nothing but this check stops a caller - a comparison
     * screen reading the slot from one place and the company from another - recording a lot as
     * awarded to a supplier that never quoted for it. Once written there is no trace of the
     * mismatch: company_id looks like any other award.
     *
     * The comparison is case-insensitive after trimming: FileMaker's zkp values are hex, so two
     * different ones cannot differ only in case, which makes this exactly as strict as a byte
     * comparison while not refusing a legitimate award over formatting.
     *
     * What gets written, though, is the value already stored on the slot rather than the one
     * passed in. company_id is a ShakeDesign key looked up verbatim elsewhere - findCompany()
     * matches it with `==` on the Data API - so admitting a differently-cased spelling for the
     * comparison must not be a way of persisting one.
     */
    private function canonicalCompanyForSlot(Lot $lot, int $supplierNumber, string $company): string
    {
        $slotCompany = trim((string) $lot->{"tender_supplier_{$supplierNumber}_id"});

        if ($slotCompany === '') {
            throw TenderSupplierMismatchException::slotHasNoCompany($lot, $supplierNumber, $company);
        }

        if (strcasecmp($slotCompany, $company) !== 0) {
            throw TenderSupplierMismatchException::slotHoldsAnotherCompany(
                $lot,
                $supplierNumber,
                $slotCompany,
                $company,
            );
        }

        return $slotCompany;
    }
}
