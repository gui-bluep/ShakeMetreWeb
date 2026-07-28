<?php

namespace Tests\Feature;

use App\Models\Lot;
use App\Models\Metre;
use App\Models\MetreLine;
use App\Models\MetreLineComponent;
use App\Models\Reference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ShakeDesign stores hard references to ShakeMetre keys (OFF_Offers.zkf_MET and
 * SOR_SupplierOrders.zkf_MET point at MET_Metre.zkp), so a migrated record must keep its
 * FileMaker zkp byte-for-byte or those stored foreign keys break silently.
 *
 * HasUuids only generates a key when none was supplied, which is what makes importing the
 * original zkp possible - these tests lock that behaviour in.
 */
class ZkpPreservationTest extends TestCase
{
    use RefreshDatabase;

    /** FileMaker Get(UUID) produces upper-case values; the case must survive too. */
    private const ZKP_METRE = '1A2B3C4D-5E6F-7890-ABCD-EF1234567890';

    private const ZKP_METRE_LINE = '0F9E8D7C-6B5A-4321-FEDC-BA0987654321';

    private const ZKP_LOT = 'AABBCCDD-1122-3344-5566-778899AABBCC';

    private const ZKP_REFERENCE = 'DEADBEEF-0000-1111-2222-333344445555';

    private const ZKP_COMPONENT = 'CAFEBABE-9999-8888-7777-666655554444';

    public function test_a_metre_keeps_its_source_zkp(): void
    {
        Metre::forceCreate(['id' => self::ZKP_METRE, 'name' => 'Metre']);

        $this->assertSame(self::ZKP_METRE, Metre::sole()->id);
        $this->assertDatabaseHas('metres', ['id' => self::ZKP_METRE]);
    }

    public function test_a_metre_line_keeps_its_source_zkp_and_its_parent_reference(): void
    {
        Metre::forceCreate(['id' => self::ZKP_METRE, 'name' => 'Metre']);
        MetreLine::forceCreate(['id' => self::ZKP_METRE_LINE, 'metre_id' => self::ZKP_METRE]);

        $line = MetreLine::sole();

        $this->assertSame(self::ZKP_METRE_LINE, $line->id);
        $this->assertSame(self::ZKP_METRE, $line->metre_id);
        $this->assertTrue($line->metre->is(Metre::sole()));
    }

    public function test_a_lot_keeps_its_source_zkp(): void
    {
        Lot::forceCreate(['id' => self::ZKP_LOT, 'code' => 1]);

        $this->assertSame(self::ZKP_LOT, Lot::sole()->id);
    }

    public function test_a_reference_keeps_its_source_zkp(): void
    {
        Reference::forceCreate(['id' => self::ZKP_REFERENCE, 'code' => 1]);

        $this->assertSame(self::ZKP_REFERENCE, Reference::sole()->id);
    }

    public function test_a_metre_line_component_keeps_its_source_zkp(): void
    {
        Metre::forceCreate(['id' => self::ZKP_METRE, 'name' => 'Metre']);
        MetreLine::forceCreate(['id' => self::ZKP_METRE_LINE, 'metre_id' => self::ZKP_METRE]);
        MetreLineComponent::forceCreate([
            'id' => self::ZKP_COMPONENT,
            'metre_line_id' => self::ZKP_METRE_LINE,
        ]);

        $this->assertSame(self::ZKP_COMPONENT, MetreLineComponent::sole()->id);
    }

    public function test_a_generated_uuid_is_still_used_when_no_zkp_is_supplied(): void
    {
        $metre = Metre::forceCreate(['name' => 'Metre']);

        $this->assertNotEmpty($metre->id);
        $this->assertNotSame(self::ZKP_METRE, $metre->id);
    }
}
