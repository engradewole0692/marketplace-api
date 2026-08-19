<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessReview;

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

    public function test_public_can_submit_business_review(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/v1/public/forms/business-review', [
            'full_name' => 'John Doe',
            'email' => 'john@example.com',
            'business_name' => 'Doe Enterprises',
            'business_description' => 'A technology startup building fintech solutions.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.email', 'john@example.com');

        $this->assertDatabaseHas('business_reviews', [
            'email' => 'john@example.com',
            'business_name' => 'Doe Enterprises',
            'status' => 'new',
        ]);
    }

    public function test_submission_requires_full_name_email_and_business_name(): void
    {
        $response = $this->postJson('/api/v1/public/forms/business-review', [
            'email' => 'not-a-valid-email',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['full_name', 'email', 'business_name']);
    }

    public function test_admin_can_list_business_reviews(): void
    {
        BusinessReview::factory()->create(['status' => 'new']);
        BusinessReview::factory()->create(['status' => 'under_review']);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/business-review')
            ->assertOk()
            ->assertJsonStructure(['data' => ['data' => [['id', 'full_name', 'email', 'status']]]]);
    }

    public function test_admin_can_view_business_review(): void
    {
        $review = BusinessReview::factory()->create();

        $this->actingAs($this->admin)
            ->getJson("/api/v1/business-review/{$review->uuid}")
            ->assertOk()
            ->assertJsonPath('data.review.id', $review->uuid);
    }

    public function test_admin_can_update_status(): void
    {
        $review = BusinessReview::factory()->create(['status' => 'new']);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/business-review/{$review->uuid}/status", ['status' => 'under_review'])
            ->assertOk()
            ->assertJsonPath('data.review.status', 'under_review');

        $this->assertDatabaseHas('business_reviews', ['uuid' => $review->uuid, 'status' => 'under_review']);
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

    public function test_guest_cannot_access_admin_business_review_endpoints(): void
    {
        $this->getJson('/api/v1/business-review')->assertUnauthorized();
        $this->getJson('/api/v1/business-review/some-fake-uuid')->assertUnauthorized();
    }
}
