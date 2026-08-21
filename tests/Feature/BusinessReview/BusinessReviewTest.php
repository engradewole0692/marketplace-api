<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessReview;

use App\Models\Role;
use App\Models\User;
use App\Modules\BusinessReview\Models\BusinessReview;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class BusinessReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'phone' => '+234 801 234 5678',
            'business_name' => 'Doe Enterprises',
            'business_industry' => 'Technology',
            'business_description' => 'A technology startup building fintech solutions for African markets.',
            'country' => 'Nigeria',
            'state_province' => 'Lagos',
            'years_in_operation' => 3,
            'business_stage' => 'Growing Business',
            'website_url' => 'https://doe.example',
            'social_links' => 'https://linkedin.com/company/doe',
            'advice_areas' => 'We need help with pricing strategy and market expansion.',
            'business_goals' => 'Scale into two new countries this year.',
            'additional_info' => 'Open to a virtual review session.',
            'employee_count' => 8,
            'referral_source' => 'Ministry gathering',
        ], $overrides);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
            SuperAdminSeeder::class,
        ]);

        $this->admin = User::query()->where('email', 'admin@marketplaceministers.org')->firstOrFail();
    }

    public function test_guest_can_submit_business_review(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/v1/public/forms/business-review', $this->validPayload());

        $response->assertStatus(201)
            ->assertJsonPath('data.email', 'john@example.com');

        $this->assertDatabaseHas('business_reviews', [
            'email' => 'john@example.com',
            'business_name' => 'Doe Enterprises',
            'status' => 'new',
            'user_id' => null,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'country' => 'Nigeria',
        ]);
    }

    public function test_authenticated_user_is_linked_and_not_duplicated(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'first_name' => 'Ada',
            'last_name' => 'Okeke',
            'email' => 'ada@example.com',
        ]);
        $before = User::query()->count();

        $this->actingAs($user)
            ->postJson('/api/v1/public/forms/business-review', $this->validPayload([
                'first_name' => 'Ada',
                'last_name' => 'Okeke',
                'email' => 'ada@example.com',
            ]))
            ->assertStatus(201);

        $this->assertSame($before, User::query()->count());
        $this->assertDatabaseHas('business_reviews', [
            'email' => 'ada@example.com',
            'user_id' => $user->id,
        ]);
    }

    public function test_submission_requires_core_fields_and_rejects_invalid_values(): void
    {
        $response = $this->postJson('/api/v1/public/forms/business-review', [
            'email' => 'not-a-valid-email',
            'website_url' => 'not-a-url',
            'phone' => 'abc',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'first_name',
                'last_name',
                'email',
                'phone',
                'business_name',
                'business_industry',
                'business_description',
                'country',
                'state_province',
                'years_in_operation',
                'business_stage',
                'website_url',
                'advice_areas',
            ]);
    }

    public function test_valid_submission_is_stored_with_snapshot_fields(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/public/forms/business-review', $this->validPayload())
            ->assertStatus(201);

        $review = BusinessReview::query()->where('email', 'john@example.com')->firstOrFail();
        $this->assertSame('Growing Business', $review->business_stage);
        $this->assertSame('https://doe.example', $review->website_url);
        $this->assertNotNull($review->uuid);
        $this->assertDatabaseHas('business_review_status_histories', [
            'business_review_id' => $review->id,
            'to_status' => 'new',
        ]);
    }

    public function test_admin_can_list_and_filter_business_reviews(): void
    {
        BusinessReview::factory()->create(['status' => 'new', 'country' => 'Nigeria', 'business_industry' => 'Technology']);
        BusinessReview::factory()->create(['status' => 'under_review', 'country' => 'Kenya', 'business_industry' => 'Agriculture']);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/business-review?status=under_review&country=Kenya')
            ->assertOk()
            ->assertJsonPath('data.data.0.status', 'under_review')
            ->assertJsonPath('data.meta.total', 1);
    }

    public function test_admin_can_search_business_reviews(): void
    {
        BusinessReview::factory()->create(['business_name' => 'Sunrise Foods', 'full_name' => 'Amina Bello']);
        BusinessReview::factory()->create(['business_name' => 'Other Co']);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/business-review?search=Sunrise')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.data.0.business_name', 'Sunrise Foods');
    }

    public function test_admin_can_view_business_review(): void
    {
        $review = BusinessReview::factory()->create();

        $this->actingAs($this->admin)
            ->getJson("/api/v1/business-review/{$review->uuid}")
            ->assertOk()
            ->assertJsonPath('data.review.id', $review->uuid);
    }

    public function test_admin_can_update_status_and_records_history(): void
    {
        Mail::fake();
        $review = BusinessReview::factory()->create(['status' => 'new']);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/business-review/{$review->uuid}/status", ['status' => 'under_review'])
            ->assertOk()
            ->assertJsonPath('data.review.status', 'under_review');

        $this->assertDatabaseHas('business_reviews', ['uuid' => $review->uuid, 'status' => 'under_review']);
        $this->assertDatabaseHas('business_review_status_histories', [
            'business_review_id' => $review->id,
            'from_status' => 'new',
            'to_status' => 'under_review',
        ]);
    }

    public function test_admin_can_assign_reviewer(): void
    {
        $review = BusinessReview::factory()->create();
        $reviewer = User::factory()->create();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/business-review/{$review->uuid}/assign", ['user_id' => $reviewer->id])
            ->assertOk()
            ->assertJsonPath('data.review.assigned_to.email', $reviewer->email);
    }

    public function test_admin_can_add_note(): void
    {
        $review = BusinessReview::factory()->create();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/business-review/{$review->uuid}/notes", ['content' => 'Reviewed the application.'])
            ->assertStatus(201);

        $this->assertDatabaseHas('business_review_notes', [
            'business_review_id' => $review->id,
            'content' => 'Reviewed the application.',
        ]);
    }

    public function test_admin_can_export_business_reviews(): void
    {
        BusinessReview::factory()->create([
            'full_name' => 'Export Person',
            'email' => 'export@example.com',
            'business_name' => 'Export Co',
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/api/v1/business-review/export');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('Export Person', $response->streamedContent());
        $this->assertStringContainsString('Export Co', $response->streamedContent());
    }

    public function test_unauthorized_admin_cannot_access_business_reviews(): void
    {
        $role = Role::query()->where('slug', 'member')->firstOrFail();
        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        $review = BusinessReview::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/business-review')
            ->assertForbidden();

        $this->actingAs($user)
            ->getJson("/api/v1/business-review/{$review->uuid}")
            ->assertForbidden();

        $this->actingAs($user)
            ->patchJson("/api/v1/business-review/{$review->uuid}/status", ['status' => 'under_review'])
            ->assertForbidden();
    }

    public function test_guest_cannot_access_admin_business_review_endpoints(): void
    {
        $this->getJson('/api/v1/business-review')->assertUnauthorized();
        $this->getJson('/api/v1/business-review/some-fake-uuid')->assertUnauthorized();
    }
}
