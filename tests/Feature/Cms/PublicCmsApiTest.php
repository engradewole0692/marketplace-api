<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PublicCmsApiTest extends TestCase
{
  use RefreshDatabase;

  protected function setUp(): void
  {
    parent::setUp();
    $this->seed(\Database\Seeders\CmsSeeder::class);
  }

  public function test_public_home_endpoint_returns_content(): void
  {
    $response = $this->getJson('/api/v1/public/home');

    $response
      ->assertOk()
      ->assertJsonPath('success', true)
      ->assertJsonStructure([
        'data' => ['sections', 'countries', 'ministries', 'leadership', 'testimonials', 'partners'],
      ]);
  }

  public function test_public_ministry_endpoints_return_cms_content(): void
  {
    $this->getJson('/api/v1/public/ministries')
      ->assertOk()
      ->assertJsonPath('data.0.slug', 'prayer-ministry')
      ->assertJsonPath('data.0.content.image_asset', 'event-prayer');

    $this->getJson('/api/v1/public/ministries/prayer-ministry')
      ->assertOk()
      ->assertJsonPath('data.slug', 'prayer-ministry')
      ->assertJsonPath('data.content.contact_email', 'info@marketplaceministers.net')
      ->assertJsonPath('data.content.related_ministry_slugs.0', 'care-ministry');
  }

  public function test_public_country_endpoints_return_cms_content(): void
  {
    $this->getJson('/api/v1/public/countries')
      ->assertOk()
      ->assertJsonPath('data.0.slug', 'nigeria')
      ->assertJsonPath('data.0.longitude', 49.5)
      ->assertJsonPath('data.0.content.local_ministries.0.slug', 'prayer-ministry');

    $this->getJson('/api/v1/public/countries/nigeria')
      ->assertOk()
      ->assertJsonPath('data.slug', 'nigeria')
      ->assertJsonPath('data.content.contact_email', 'info@marketplaceministers.net')
      ->assertJsonPath('data.content.leadership_team.0.slug', 'damola-adelakun');
  }

  public function test_public_remaining_page_endpoints_return_renderable_cms_blocks(): void
  {
    $this->getJson('/api/v1/public/pages/about')
      ->assertOk()
      ->assertJsonPath('data.page.slug', 'about')
      ->assertJsonPath('data.sections.0.content.blocks.0.type', 'rich_text')
      ->assertJsonPath('data.sections.0.content.blocks.2.type', 'core_values')
      ->assertJsonPath('data.sections.0.content.blocks.3.type', 'journey');

    $this->getJson('/api/v1/public/pages/counseling')
      ->assertOk()
      ->assertJsonPath('data.page.slug', 'counseling')
      ->assertJsonPath('data.sections.0.content.blocks.0.type', 'features')
      ->assertJsonPath('data.sections.0.content.blocks.1.type', 'rich_text');

    $this->getJson('/api/v1/public/pages/partner')
      ->assertOk()
      ->assertJsonPath('data.page.slug', 'partner')
      ->assertJsonPath('data.sections.0.content.blocks.0.type', 'partner_types')
      ->assertJsonPath('data.sections.0.content.blocks.1.type', 'impact_areas');

    $this->getJson('/api/v1/public/pages/media')
      ->assertOk()
      ->assertJsonPath('data.page.slug', 'media')
      ->assertJsonPath('data.sections.0.content.blocks.0.type', 'media_hub')
      ->assertJsonPath('data.sections.0.content.blocks.0.items.0.to', '/blog');

    $this->getJson('/api/v1/public/pages/prayer-watch')
      ->assertOk()
      ->assertJsonPath('data.page.slug', 'prayer-watch')
      ->assertJsonPath('data.sections.0.content.blocks.0.type', 'app_showcase')
      ->assertJsonPath('data.sections.0.content.blocks.1.type', 'features')
      ->assertJsonPath('data.sections.0.content.blocks.2.type', 'faq');

    foreach (['join', 'donate', 'blog', 'gallery', 'resources', 'vlog', 'leadership', 'ministries', 'global-presence', 'testimonials', 'privacy', 'terms', 'contact'] as $slug) {
      $this->getJson("/api/v1/public/pages/{$slug}")
        ->assertOk()
        ->assertJsonPath('data.page.slug', $slug);
    }
  }

  public function test_public_gallery_endpoint_returns_cms_media_urls(): void
  {
    $response = $this->getJson('/api/v1/public/catalog/gallery')
      ->assertOk()
      ->assertJsonPath('data.0.slug', 'tribe-fellowship-birthday-celebration')
      ->assertJsonPath('data.0.metadata.photographer', 'Tribe Media Team');

    $items = $response->json('data');

    $this->assertCount(16, $items);
    $this->assertStringContainsString('/storage/', $items[0]['featured_image_url']);
    $this->assertArrayNotHasKey('image_asset', $items[0]['metadata']);
  }

  public function test_public_resources_endpoint_returns_cms_file_urls(): void
  {
    $response = $this->getJson('/api/v1/public/catalog/resource')
      ->assertOk()
      ->assertJsonPath('data.0.slug', 'marketplace-ministers-handbook')
      ->assertJsonPath('data.0.metadata.author', 'The Tribe Council');

    $items = $response->json('data');

    $this->assertCount(12, $items);
    $this->assertStringContainsString('/storage/', $items[0]['featured_image_url']);
    $this->assertStringContainsString('/storage/', $items[0]['metadata']['file_url']);
    $this->assertSame($items[0]['metadata']['file_url'], $items[0]['metadata']['download_url']);
    $this->assertArrayNotHasKey('image_asset', $items[0]['metadata']);
  }

  public function test_contact_form_submission_is_stored(): void
  {
    $response = $this->postJson('/api/v1/public/forms/contact', [
      'name' => 'Jane Doe',
      'email' => 'jane@example.com',
      'country' => 'Nigeria',
      'subject' => 'General inquiry',
      'message' => 'Hello from the public website.',
    ]);

    $response
      ->assertCreated()
      ->assertJsonPath('success', true)
      ->assertJsonStructure(['data' => ['id', 'received_at']]);

    $this->assertDatabaseHas('cms_form_submissions', [
      'type' => 'contact',
      'submitter_email' => 'jane@example.com',
    ]);
  }

  public function test_membership_application_creates_member(): void
  {
    $this->seed([
      \Database\Seeders\RoleSeeder::class,
      \Database\Seeders\PermissionSeeder::class,
      \Database\Seeders\RolePermissionSeeder::class,
    ]);

    $response = $this->postJson('/api/v1/public/forms/membership', [
      'firstName' => 'John',
      'lastName' => 'Applicant',
      'email' => 'john.applicant@example.com',
      'phone' => '+2348012345678',
      'country' => 'Nigeria',
      'preferredMinistry' => 'marketplace-leadership',
      'dob' => '1990-05-15',
      'gender' => 'male',
      'maritalStatus' => 'single',
      'testimony' => 'I came to faith through marketplace discipleship and fellowship.',
      'churchName' => 'Grace Assembly',
      'yearsInFaith' => '4–7',
      'occupation' => 'Engineer',
      'industry' => 'Technology',
      'whyJoin' => 'I want to serve the body of Christ in the marketplace with excellence.',
      'availability' => 'monthly',
      'nextOfKin' => 'Jane Applicant',
      'kinRelationship' => 'Spouse',
      'kinPhone' => '+2348099999999',
      'declaration' => true,
    ]);

    $response
      ->assertCreated()
      ->assertJsonPath('success', true)
      ->assertJsonStructure(['data' => ['id', 'membership_number', 'application_number', 'tracking_token', 'received_at']]);

    $this->assertDatabaseHas('members', [
      'email' => 'john.applicant@example.com',
      'status' => 'application_submitted',
    ]);

    $this->assertSame(
      '1990-05-15',
      \App\Models\Member::query()->where('email', 'john.applicant@example.com')->value('date_of_birth')?->toDateString(),
    );
  }
}
