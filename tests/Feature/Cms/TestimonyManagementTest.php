<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use App\Models\User;
use App\Modules\Cms\Enums\TestimonialStatus;
use App\Modules\Cms\Models\CmsTestimonial;
use Database\Seeders\CmsSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class TestimonyManagementTest extends TestCase
{
  use RefreshDatabase;

  private User $admin;

  protected function setUp(): void
  {
    parent::setUp();
    Storage::fake('public');

    $this->seed([
      RoleSeeder::class,
      PermissionSeeder::class,
      RolePermissionSeeder::class,
      CmsSeeder::class,
      SuperAdminSeeder::class,
    ]);

    $this->admin = User::query()->where('email', 'admin@marketplaceministers.org')->firstOrFail();
  }

  public function test_guest_can_submit_testimony_for_moderation(): void
  {
    $response = $this->post('/api/v1/public/forms/testimony', [
      'author_name' => 'Faith Okoro',
      'author_title' => 'Entrepreneur',
      'country' => 'Nigeria',
      'quote' => 'God transformed my marketplace calling through this tribe.',
      'category' => 'marketplace',
      'email' => 'faith@example.com',
      'is_anonymous' => false,
      'submitter_type' => 'guest',
      'photo' => UploadedFile::fake()->image('portrait.jpg', 400, 400),
    ])->assertCreated();

    $id = $response->json('data.id');
    $this->assertDatabaseHas('cms_testimonials', [
      'uuid' => $id,
      'status' => TestimonialStatus::Pending->value,
      'is_active' => 0,
    ]);
    $this->assertDatabaseHas('cms_form_submissions', ['type' => 'testimony']);
  }

  public function test_anonymous_testimony_hides_author_name_in_public_payload_after_publish(): void
  {
    $id = $this->post('/api/v1/public/forms/testimony', [
      'author_name' => 'Secret Saint',
      'quote' => 'Prayer changed everything for my household.',
      'category' => 'family',
      'is_anonymous' => true,
      'email' => 'anon@example.com',
    ])->assertCreated()->json('data.id');

    $testimonial = CmsTestimonial::query()->where('uuid', $id)->firstOrFail();

    $this->actingAs($this->admin)
      ->postJson("/api/v1/cms/testimonials/{$testimonial->uuid}/approve", [
        'show_on_homepage' => true,
        'show_on_page' => true,
        'is_featured' => true,
      ])
      ->assertOk()
      ->assertJsonPath('data.testimonial.status', 'approved')
      ->assertJsonPath('data.testimonial.author_name', 'Anonymous')
      ->assertJsonPath('data.testimonial.show_on_homepage', true);

    $this->getJson('/api/v1/public/testimonials?placement=homepage')
      ->assertOk()
      ->assertJsonFragment(['author_name' => 'Anonymous']);
  }

  public function test_admin_can_reject_and_filter_pending_testimonials(): void
  {
    $id = $this->postJson('/api/v1/public/forms/testimony', [
      'author_name' => 'Reject Me',
      'quote' => 'Not ready yet.',
      'category' => 'other',
      'email' => 'reject@example.com',
    ])->assertCreated()->json('data.id');

    $this->actingAs($this->admin)
      ->postJson("/api/v1/cms/testimonials/{$id}/reject", ['reason' => 'Needs more detail'])
      ->assertOk()
      ->assertJsonPath('data.testimonial.status', 'rejected');

    $this->actingAs($this->admin)
      ->getJson('/api/v1/cms/testimonials?status=rejected')
      ->assertOk()
      ->assertJsonPath('data.data.0.id', $id);
  }

  public function test_public_testimonials_page_only_returns_approved_page_listings(): void
  {
    CmsTestimonial::query()->create([
      'author_name' => 'Visible Leader',
      'quote' => 'Published on page.',
      'status' => TestimonialStatus::Approved,
      'category' => 'leadership',
      'is_active' => true,
      'show_on_page' => true,
      'show_on_homepage' => false,
      'sort_order' => 1,
    ]);

    CmsTestimonial::query()->create([
      'author_name' => 'Pending Voice',
      'quote' => 'Still waiting.',
      'status' => TestimonialStatus::Pending,
      'category' => 'leadership',
      'is_active' => false,
      'show_on_page' => false,
      'sort_order' => 2,
    ]);

    $this->getJson('/api/v1/public/testimonials?placement=page&category=leadership')
      ->assertOk()
      ->assertJsonCount(1, 'data')
      ->assertJsonPath('data.0.author_name', 'Visible Leader');
  }
}
