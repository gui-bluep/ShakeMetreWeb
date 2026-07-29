<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Metre;
use App\Models\MetreLine;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The readonly role is enforced on the server, not merely hidden in the UI.
 */
class ReadOnlyRoleTest extends TestCase
{
    use RefreshDatabase;

    private Metre $metre;

    private MetreLine $line;

    protected function setUp(): void
    {
        parent::setUp();

        $this->metre = Metre::forceCreate(['name' => 'Métré', 'project_id' => 'PRJ-1']);
        $this->line = MetreLine::forceCreate([
            'metre_id' => $this->metre->id,
            'quantity' => 2,
            'price_sales' => 100,
            'unit' => 'm2',
        ]);
    }

    // --- the enum's mapping is closed and defaults down -------------------------------

    /** @return array<string, array{UserRole, bool}> */
    public static function rolePermissionProvider(): array
    {
        return [
            'admin writes' => [UserRole::Admin, true],
            'user writes' => [UserRole::User, true],
            'readonly does not' => [UserRole::ReadOnly, false],
        ];
    }

    #[DataProvider('rolePermissionProvider')]
    public function test_each_role_knows_whether_it_may_write(UserRole $role, bool $canWrite): void
    {
        $this->assertSame($canWrite, $role->canWrite());
    }

    public function test_a_user_with_no_role_at_all_may_not_write(): void
    {
        // Least privilege if the column is somehow unset, rather than assuming write access.
        $this->assertFalse((new User)->canWrite());
    }

    // --- the metre line endpoint -------------------------------------------------------

    public function test_a_readonly_user_cannot_update_a_metre_line(): void
    {
        $this->actingAs(User::factory()->readOnly()->create());

        $this->patchJson("/api/metre-lines/{$this->line->id}", ['quantity' => 99])
            ->assertForbidden();

        $this->assertEquals(2, (float) $this->line->refresh()->quantity);
    }

    public function test_a_readonly_user_may_still_open_the_grid(): void
    {
        $this->actingAs(User::factory()->readOnly()->create());

        $this->get("/metres/{$this->metre->id}/lines")->assertOk();
    }

    public function test_the_grid_tells_the_client_it_cannot_write(): void
    {
        $this->actingAs(User::factory()->readOnly()->create());

        $this->get("/metres/{$this->metre->id}/lines")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.canWrite', false));
    }

    #[DataProvider('writingRoleProvider')]
    public function test_a_writing_role_can_update_a_metre_line(UserRole $role): void
    {
        $this->actingAs(User::factory()->state(['role' => $role])->create());

        $this->patchJson("/api/metre-lines/{$this->line->id}", ['quantity' => 99])->assertOk();

        $this->assertEquals(99, (float) $this->line->refresh()->quantity);
    }

    /** @return array<string, array{UserRole}> */
    public static function writingRoleProvider(): array
    {
        return ['admin' => [UserRole::Admin], 'user' => [UserRole::User]];
    }

    // --- the web routes ----------------------------------------------------------------

    public function test_a_readonly_user_cannot_update_their_profile(): void
    {
        $user = User::factory()->readOnly()->create(['name' => 'Avant']);
        $this->actingAs($user);

        $this->patch('/profile', ['name' => 'Après', 'email' => $user->email])
            ->assertForbidden();

        $this->assertSame('Avant', $user->refresh()->name);
    }

    public function test_a_readonly_user_cannot_delete_their_account(): void
    {
        $user = User::factory()->readOnly()->create();
        $this->actingAs($user);

        $this->delete('/profile', ['password' => 'password'])->assertForbidden();

        $this->assertModelExists($user);
    }

    public function test_a_readonly_user_may_still_view_their_profile(): void
    {
        $this->actingAs(User::factory()->readOnly()->create());

        $this->get('/profile')->assertOk();
    }

    public function test_a_writing_user_can_update_their_profile(): void
    {
        $user = User::factory()->create(['name' => 'Avant']);
        $this->actingAs($user);

        $this->patch('/profile', ['name' => 'Après', 'email' => $user->email])->assertRedirect();

        $this->assertSame('Après', $user->refresh()->name);
    }

    // --- the column itself -------------------------------------------------------------

    public function test_the_role_column_defaults_to_readonly(): void
    {
        // The safe default for a row created outside the SSO flow: an unrecognised
        // ShakeDesign privilege set must not arrive with write access.
        $id = DB::table('users')->insertGetId([
            'name' => 'Sans rôle',
            'email' => 'sans.role@example.test',
            'password' => 'x',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(UserRole::ReadOnly, User::find($id)->role);
    }

    public function test_the_shakedesign_id_is_unique(): void
    {
        User::factory()->fromShakeDesign('ZUSR-1')->create();

        $this->expectException(QueryException::class);
        User::factory()->fromShakeDesign('ZUSR-1')->create();
    }

    public function test_the_role_is_not_mass_assignable(): void
    {
        // It comes from ShakeDesign, never from a request payload.
        $user = User::create([
            'name' => 'Test',
            'email' => 'test@example.test',
            'password' => 'secret',
            'role' => UserRole::Admin->value,
        ]);

        $this->assertSame(UserRole::ReadOnly, $user->refresh()->role);
    }
}
