<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The home screen: a project search backed by ShakeDesign. No test here touches a real
 * FileMaker server - every ShakeDesign call is Http::fake()d.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.shakedesign', [
            'host' => 'fms.example.test',
            'database' => 'ShakeDesign',
            'username' => 'api_user',
            'password' => 'api_secret',
            'version' => 'vLatest',
            'timeout' => 15,
            'connect_timeout' => 5,
            'verify' => true,
            'token_cache_key' => 'shakedesign:data-api:token',
            'token_ttl' => 840,
        ]);

        Cache::flush();
    }

    private function actAsWriter(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    private function actAsReadOnly(): User
    {
        $user = User::factory()->readOnly()->create();
        $this->actingAs($user);

        return $user;
    }

    private function ok(array $response): array
    {
        return ['response' => $response, 'messages' => [['code' => '0', 'message' => 'OK']]];
    }

    private function sessionBody(): array
    {
        return $this->ok(['token' => 'tok-1']);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function foundBody(array $rows): array
    {
        return $this->ok([
            'data' => array_map(fn (array $fieldData) => ['fieldData' => $fieldData, 'recordId' => '1'], $rows),
        ]);
    }

    public function test_the_dashboard_renders_for_a_logged_in_user(): void
    {
        $this->actAsWriter();

        $this->get('/dashboard')
            ->assertInertia(fn ($page) => $page->component('Dashboard'));
    }

    public function test_a_guest_is_redirected_away_from_the_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_a_blank_query_returns_no_projects_and_never_calls_shakedesign(): void
    {
        $this->actAsWriter();

        Http::fake();

        $response = $this->getJson('/api/projects/search');

        $response->assertOk()->assertJson(['data' => []]);
        Http::assertNothingSent();
    }

    public function test_a_search_returns_the_matching_projects(): void
    {
        $this->actAsWriter();

        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            // Exactly the field set the live API_PRJ layout exposes.
            '*/layouts/API_PRJ/_find' => Http::response($this->foundBody([
                [
                    'zkp' => 'PRJ-1', 'Name' => 'Chantier Nord', 'Number' => '2026-001',
                    'Status' => 'En cours', 'zkf_CPY' => '', 'zkf_CTC' => '',
                ],
            ])),
        ]);

        $response = $this->getJson('/api/projects/search?q=nord');

        $response->assertOk()->assertJson([
            'data' => [
                [
                    'id' => 'PRJ-1',
                    'name' => 'Chantier Nord',
                    'number' => '2026-001',
                    'status' => 'En cours',
                ],
            ],
        ]);
    }

    /**
     * The active/inactive concept was dropped: PRJ_Projects.isActive_b is not exposed on
     * API_PRJ, and inferring it from an absent field made every project claim to be inactive.
     * Nothing in the payload should reintroduce it.
     */
    public function test_the_payload_carries_no_active_flag(): void
    {
        $this->actAsWriter();

        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            '*/layouts/API_PRJ/_find' => Http::response($this->foundBody([
                ['zkp' => 'PRJ-1', 'Name' => 'Chantier Nord', 'Status' => 'Closed'],
            ])),
        ]);

        $this->getJson('/api/projects/search?q=nord')
            ->assertOk()
            ->assertJsonMissingPath('data.0.is_active');
    }

    public function test_a_search_matching_nothing_returns_an_empty_list(): void
    {
        $this->actAsWriter();

        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            '*/layouts/API_PRJ/_find' => Http::response(
                $this->ok([]) + ['messages' => [['code' => '401', 'message' => 'No records match the request']]],
                404,
            ),
        ]);

        $this->getJson('/api/projects/search?q=nothing')
            ->assertOk()
            ->assertJson(['data' => []]);
    }

    public function test_a_readonly_account_may_search(): void
    {
        $this->actAsReadOnly();

        Http::fake([
            '*/sessions' => Http::response($this->sessionBody()),
            '*/layouts/API_PRJ/_find' => Http::response($this->foundBody([])),
        ]);

        $this->getJson('/api/projects/search?q=nord')->assertOk();
    }

    public function test_a_guest_cannot_search(): void
    {
        Http::fake();

        $this->getJson('/api/projects/search?q=nord')->assertUnauthorized();
        Http::assertNothingSent();
    }
}
