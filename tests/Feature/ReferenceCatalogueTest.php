<?php

namespace Tests\Feature;

use App\Models\Metre;
use App\Models\MetreLine;
use App\Models\Reference;
use App\Models\SubReference;
use App\Models\SubReferenceLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The reference catalogue screen: the three levels, their nine write endpoints, and the one rule
 * that matters most - editing or deleting a catalogue entry must never touch a métré.
 */
class ReferenceCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private function actAsWriter(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    /** @return array{0: Reference, 1: SubReference, 2: SubReferenceLine} */
    private function catalogue(): array
    {
        $reference = Reference::forceCreate(['code' => 20, 'title_fr' => 'SOLS', 'title_en' => 'FLOOR']);
        $subReference = SubReference::forceCreate([
            'reference_id' => $reference->id, 'code' => 8, 'title_fr' => 'Carrelage',
        ]);
        $item = SubReferenceLine::forceCreate([
            'sub_reference_id' => $subReference->id, 'reference_id' => $reference->id,
            'code' => 1, 'title_fr' => 'Carrelage 30x30', 'unit' => 'm2', 'price' => 48.5,
        ]);

        return [$reference, $subReference, $item];
    }

    public function test_the_page_lists_the_catalogue_as_a_tree(): void
    {
        $this->actAsWriter();
        $this->catalogue();

        $this->get('/references')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('References/Index')
                ->where('references.0.code', 20)
                ->where('references.0.title', 'SOLS')
                ->where('references.0.sub_references.0.title', 'Carrelage')
                ->where('references.0.sub_references.0.lines.0.title', 'Carrelage 30x30')
                ->where('references.0.sub_references.0.lines.0.price', 48.5));
    }

    public function test_a_guest_is_redirected_away(): void
    {
        $this->get('/references')->assertRedirect('/login');
    }

    public function test_a_readonly_account_may_look_but_not_write(): void
    {
        [$reference, $subReference, $item] = $this->catalogue();
        $this->actingAs(User::factory()->readOnly()->create());

        $this->get('/references')->assertOk();

        $this->postJson('/api/references')->assertStatus(403);
        $this->patchJson("/api/references/{$reference->id}", ['code' => 99])->assertStatus(403);
        $this->deleteJson("/api/references/{$reference->id}")->assertStatus(403);
        $this->postJson("/api/references/{$reference->id}/sub-references")->assertStatus(403);
        $this->patchJson("/api/sub-references/{$subReference->id}", ['code' => 9])->assertStatus(403);
        $this->deleteJson("/api/sub-references/{$subReference->id}")->assertStatus(403);
        $this->postJson("/api/sub-references/{$subReference->id}/lines")->assertStatus(403);
        $this->patchJson("/api/sub-reference-lines/{$item->id}", ['price' => 1])->assertStatus(403);
        $this->deleteJson("/api/sub-reference-lines/{$item->id}")->assertStatus(403);

        $this->assertSame(20, (int) $reference->fresh()->code);
    }

    /**
     * Creation is an empty row at the right level, filled in afterwards - REF_New, which creates
     * the record and puts the cursor in its Code field.
     */
    public function test_creating_a_section_numbers_it_after_the_last_one_in_tens(): void
    {
        $this->actAsWriter();
        Reference::forceCreate(['code' => 20, 'title_fr' => 'SOLS']);

        $this->postJson('/api/references')
            ->assertCreated()
            // Sections run 00, 10, 20 in the live catalogue: the gaps are what let a new chapter
            // be filed between two existing ones later.
            ->assertJsonPath('data.code', 30)
            ->assertJsonPath('data.title', null);

        $this->assertSame(2, Reference::count());
    }

    public function test_creating_a_sub_section_and_an_item_numbers_them_one_by_one(): void
    {
        $this->actAsWriter();
        [$reference, $subReference] = $this->catalogue();

        $this->postJson("/api/references/{$reference->id}/sub-references")
            ->assertCreated()
            ->assertJsonPath('data.code', 9)
            ->assertJsonPath('data.reference_id', $reference->id);

        $this->postJson("/api/sub-references/{$subReference->id}/lines")
            ->assertCreated()
            ->assertJsonPath('data.code', 2)
            ->assertJsonPath('data.sub_reference_id', $subReference->id);

        // REFSL carries the grandparent key too, as the source does.
        $this->assertSame($reference->id, SubReferenceLine::latest('id')->get()
            ->firstWhere('code', 2)?->reference_id);
    }

    public function test_it_writes_the_editable_fields_of_the_three_levels(): void
    {
        $this->actAsWriter();
        [$reference, $subReference, $item] = $this->catalogue();

        $this->patchJson("/api/references/{$reference->id}", ['code' => 25, 'title_fr' => 'REVÊTEMENTS'])
            ->assertOk()
            ->assertJsonPath('data.title', 'REVÊTEMENTS');

        $this->patchJson("/api/sub-references/{$subReference->id}", ['title_en' => 'Tiles'])->assertOk();

        $this->patchJson("/api/sub-reference-lines/{$item->id}", [
            'unit' => 'Ff', 'price' => 51.75, 'description_fr' => 'pose comprise',
        ])->assertOk()->assertJsonPath('data.price', 51.75);

        $this->assertSame(25, (int) $reference->fresh()->code);
        $this->assertSame('Tiles', $subReference->fresh()->title_en);
        $this->assertSame('Ff', $item->fresh()->unit);
        $this->assertSame('pose comprise', $item->fresh()->description_fr);
    }

    /**
     * The parent is in the URL, never in the payload: moving a sub-section under another section
     * would change the code of every métré line that quoted it, which is a data migration rather
     * than an edit.
     */
    public function test_the_parent_link_and_unknown_fields_are_refused(): void
    {
        $this->actAsWriter();
        [$reference, $subReference, $item] = $this->catalogue();

        $this->patchJson("/api/sub-references/{$subReference->id}", ['reference_id' => $reference->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reference_id');

        $this->patchJson("/api/sub-reference-lines/{$item->id}", ['sub_reference_id' => $subReference->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sub_reference_id');

        $this->patchJson("/api/references/{$reference->id}", ['code' => -1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    /**
     * A unit outside UpdateMetreLineRequest::UNITS is accepted here on purpose: the live catalogue
     * holds units that list does not ("Ff"), and refusing them would make existing entries
     * unsavable.
     */
    public function test_a_catalogue_unit_is_not_constrained_to_the_metre_lines_list(): void
    {
        $this->actAsWriter();
        [, , $item] = $this->catalogue();

        $this->patchJson("/api/sub-reference-lines/{$item->id}", ['unit' => 'Ff'])->assertOk();
        $this->assertSame('Ff', $item->fresh()->unit);
    }

    public function test_deleting_a_section_takes_its_sub_sections_and_items_with_it(): void
    {
        $this->actAsWriter();
        [$reference] = $this->catalogue();

        $this->deleteJson("/api/references/{$reference->id}")->assertNoContent();

        $this->assertSame(0, Reference::count());
        $this->assertSame(0, SubReference::count());
        $this->assertSame(0, SubReferenceLine::count());
    }

    public function test_deleting_a_sub_section_takes_its_items_but_leaves_its_section(): void
    {
        $this->actAsWriter();
        [$reference, $subReference] = $this->catalogue();

        $this->deleteJson("/api/sub-references/{$subReference->id}")->assertNoContent();

        $this->assertSame(1, Reference::count());
        $this->assertSame(0, SubReference::count());
        $this->assertSame(0, SubReferenceLine::count());
        $this->assertNotNull($reference->fresh());
    }

    /**
     * The rule the whole design rests on: a métré line keeps the section it was filed under. A
     * catalogue entry can be renamed, renumbered or retired without rewriting a document that may
     * already have been sent to a client - only the provenance keys are cleared, because after
     * the delete there is nothing left for them to point at.
     */
    public function test_deleting_a_catalogue_entry_leaves_metre_lines_intact(): void
    {
        $this->actAsWriter();
        [$reference, $subReference, $item] = $this->catalogue();
        $metre = Metre::forceCreate(['project_id' => 'PRJ-1', 'name' => 'Métré']);

        $this->postJson("/api/metres/{$metre->id}/lines/from-catalogue", [
            'sub_reference_line_ids' => [$item->id],
        ])->assertCreated();

        $this->deleteJson("/api/references/{$reference->id}")->assertNoContent();

        $line = MetreLine::sole();
        $this->assertSame(20, (int) $line->ref_code, 'le code de section reste');
        $this->assertSame('SOLS', $line->ref_title, 'le libellé reste celui de la création');
        $this->assertSame(8, (int) $line->refs_code);
        $this->assertSame('Carrelage', $line->refs_title);
        $this->assertSame('Carrelage 30x30', $line->refsl_title);
        $this->assertSame('20.8.1', $line->refLineCode());

        // Seule la provenance disparaît : il n'y a plus rien à pointer.
        $this->assertNull($line->reference_id);
        $this->assertNull($line->sub_reference_id);
        $this->assertNull($line->sub_reference_line_id);
    }

    public function test_renaming_a_catalogue_entry_leaves_metre_lines_intact(): void
    {
        $this->actAsWriter();
        [$reference, , $item] = $this->catalogue();
        $metre = Metre::forceCreate(['project_id' => 'PRJ-1', 'name' => 'Métré']);

        $this->postJson("/api/metres/{$metre->id}/lines/from-catalogue", [
            'sub_reference_line_ids' => [$item->id],
        ])->assertCreated();

        $this->patchJson("/api/references/{$reference->id}", ['code' => 21, 'title_fr' => 'REVÊTEMENTS'])->assertOk();
        $this->patchJson("/api/sub-reference-lines/{$item->id}", ['title_fr' => 'Carrelage 60x60'])->assertOk();

        $line = MetreLine::sole();
        $this->assertSame(20, (int) $line->ref_code);
        $this->assertSame('SOLS', $line->ref_title);
        $this->assertSame('Carrelage 30x30', $line->refsl_title);
    }

    public function test_the_catalogue_json_is_the_same_tree_as_the_page(): void
    {
        $this->actingAs(User::factory()->readOnly()->create());
        $this->catalogue();

        $this->getJson('/api/references/catalogue')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'SOLS')
            ->assertJsonPath('data.0.sub_references.0.lines.0.unit', 'm2');
    }
}
