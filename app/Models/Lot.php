<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lot extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public function metreLines(): HasMany
    {
        return $this->hasMany(MetreLine::class);
    }

    /**
     * Cross-system reference: resolved via ShakeDesignClient::findProject() against
     * ShakeDesign's PRJ_Projects. Not a local Eloquent relation.
     */
    public function projectId(): ?string
    {
        return $this->project_id;
    }

    /**
     * Cross-system reference: resolved via ShakeDesignClient::findCompany() against
     * ShakeDesign's CPY_Companies. Not a local Eloquent relation.
     */
    public function companyId(): ?string
    {
        return $this->company_id;
    }

    /**
     * Cross-system reference: resolved via ShakeDesignClient::findContact() against
     * ShakeDesign's CTC_Contacts. Not a local Eloquent relation.
     */
    public function contactId(): ?string
    {
        return $this->contact_id;
    }

    /**
     * Cross-system references: resolved via ShakeDesignClient::findCompany() against
     * ShakeDesign's CPY_Companies (candidate tender suppliers 1..5). Not local
     * Eloquent relations.
     */
    public function tenderSupplier1Id(): ?string
    {
        return $this->tender_supplier_1_id;
    }

    public function tenderSupplier2Id(): ?string
    {
        return $this->tender_supplier_2_id;
    }

    public function tenderSupplier3Id(): ?string
    {
        return $this->tender_supplier_3_id;
    }

    public function tenderSupplier4Id(): ?string
    {
        return $this->tender_supplier_4_id;
    }

    public function tenderSupplier5Id(): ?string
    {
        return $this->tender_supplier_5_id;
    }
}
