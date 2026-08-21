<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Cms\Enums\CatalogItemType;
use App\Modules\Cms\Enums\PageStatus;
use App\Modules\Cms\Models\CmsCatalogItem;
use App\Modules\Cms\Models\CmsCountry;
use App\Modules\Cms\Models\CmsLeadershipProfile;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Cms\Models\CmsMenu;
use App\Modules\Cms\Models\CmsMenuItem;
use App\Modules\Cms\Models\CmsMinistry;
use App\Modules\Cms\Models\CmsPage;
use App\Modules\Cms\Models\CmsPageSection;
use App\Modules\Cms\Models\CmsSetting;
use App\Modules\Cms\Models\CmsTestimonial;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

final class CmsSeeder extends Seeder
{
  public function run(): void
  {
    $this->seedSettings();
    $this->seedMenus();
    $this->seedHomeSections();
    $this->seedCountries();
    $this->seedMinistries();
    $this->seedLeadership();
    $this->seedTestimonials();
    $this->seedPages();
    $this->seedCatalog();

    app(\App\Modules\Cms\Support\CmsCacheManager::class)->flushPublic();
  }

  private function seedSettings(): void
  {
    $settings = [
      ['key' => 'organization.name', 'value' => 'Marketplace Ministers', 'group' => 'organization', 'is_public' => true],
      [
        'key' => 'organization.tagline',
        'value' => 'A global convergence of Kingdom professionals, executives, entrepreneurs, and ministers raised to influence the marketplace with biblical values.',
        'group' => 'organization',
        'is_public' => true,
      ],
      ['key' => 'contact.email', 'value' => 'info@marketplaceministers.net', 'group' => 'contact', 'is_public' => true],
      ['key' => 'contact.phone', 'value' => '+234 800 000 0000', 'group' => 'contact', 'is_public' => true],
      ['key' => 'contact.headquarters', 'value' => 'Lagos, Nigeria · Accra, Ghana · Charlotte, USA', 'group' => 'contact', 'is_public' => true],
      ['key' => 'contact.office_hours', 'value' => 'Monday – Friday · 9:00 AM – 5:00 PM (WAT)', 'group' => 'contact', 'is_public' => true],
      [
        'key' => 'contact.phones',
        'value' => [
          ['label' => 'USA', 'display' => '+1 (143) 456-7897', 'href' => 'tel:+11434567897'],
          ['label' => 'Nigeria', 'display' => '+234 802 681 7693', 'href' => 'tel:+2348026817693'],
        ],
        'group' => 'contact',
        'is_public' => true,
      ],
      [
        'key' => 'contact.offices',
        'value' => [
          ['id' => 'nigeria', 'name' => 'Nigeria Office', 'city' => 'Lagos, Nigeria', 'address_lines' => ['49 Ikorodu Road', 'Fadeyi, Lagos, Nigeria']],
          ['id' => 'ghana', 'name' => 'Ghana Office', 'city' => 'Accra, Ghana', 'address_lines' => ['HSE No. F730/2, 18th Lane, Osu-Re', 'P.O. Box 5435, Cantonments', 'Accra, Ghana']],
          ['id' => 'usa', 'name' => 'USA Office', 'city' => 'Charlotte, NC, USA', 'address_lines' => ['660 Westinghouse Blvd, Suite 103', 'Charlotte, NC 28273, USA']],
        ],
        'group' => 'contact',
        'is_public' => true,
      ],
      [
        'key' => 'navigation.footer_columns',
        'value' => [
          ['title' => 'Explore', 'links' => [
            ['to' => '/about', 'label' => 'About'],
            ['to' => '/leadership', 'label' => 'Leadership'],
            ['to' => '/ministries', 'label' => 'Ministries'],
            ['to' => '/global-presence', 'label' => 'Global Presence'],
          ]],
          ['title' => 'Engage', 'links' => [
            ['to' => '/media', 'label' => 'Media Center'],
            ['to' => '/blog', 'label' => 'Blog'],
            ['to' => '/vlog', 'label' => 'Vlog'],
            ['to' => '/gallery', 'label' => 'Gallery'],
            ['to' => '/resources', 'label' => 'Resources'],
            ['to' => '/prayer-watch', 'label' => 'Prayer Watch'],
            ['to' => '/counseling', 'label' => 'Counseling'],
            ['to' => '/contact', 'label' => 'Contact'],
          ]],
          ['title' => 'Give', 'links' => [
            ['to' => '/join', 'label' => 'Join The Tribe'],
            ['to' => '/partner', 'label' => 'Partner With Us'],
            ['to' => '/donate', 'label' => 'Donate'],
            ['to' => '/privacy', 'label' => 'Privacy Policy'],
            ['to' => '/terms', 'label' => 'Terms of Use'],
          ]],
        ],
        'group' => 'navigation',
        'is_public' => true,
      ],
      [
        'key' => 'navigation.header_ctas',
        'value' => [
          ['to' => '/portal', 'label' => 'Access Portal'],
          ['to' => '/donate', 'label' => 'Donate'],
          ['to' => '/join', 'label' => 'Join The Tribe'],
        ],
        'group' => 'navigation',
        'is_public' => true,
      ],
      ['key' => 'social.facebook', 'value' => '', 'group' => 'social', 'is_public' => true],
      ['key' => 'social.instagram', 'value' => '', 'group' => 'social', 'is_public' => true],
      ['key' => 'social.youtube', 'value' => '', 'group' => 'social', 'is_public' => true],
      [
        'key' => 'footer.newsletter_title',
        'value' => 'Stay in the conversation',
        'group' => 'footer',
        'is_public' => true,
      ],
      [
        'key' => 'footer.newsletter_subtitle',
        'value' => 'Monthly notes on faith, leadership, and the marketplace.',
        'group' => 'footer',
        'is_public' => true,
      ],
      [
        'key' => 'footer.copyright',
        'value' => 'The Tribe of Marketplace Ministers. All rights reserved.',
        'group' => 'footer',
        'is_public' => true,
      ],
      [
        'key' => 'footer.tagline',
        'value' => 'Influencing nations · Transforming marketplaces · For His glory.',
        'group' => 'footer',
        'is_public' => true,
      ],
    ];

    foreach ($settings as $setting) {
      CmsSetting::query()->updateOrCreate(
        ['key' => $setting['key']],
        $setting,
      );
    }
  }

  private function seedMenus(): void
  {
    $menu = CmsMenu::query()->updateOrCreate(
      ['slug' => 'primary'],
      ['name' => 'Primary Navigation', 'location' => 'header', 'is_active' => true],
    );

    $items = [
      ['label' => 'Home', 'url' => '/', 'sort_order' => 0],
      [
        'label' => 'About',
        'url' => '/about',
        'sort_order' => 1,
        'children' => [
          ['label' => 'Leadership', 'url' => '/leadership', 'sort_order' => 0],
          ['label' => 'Global Presence', 'url' => '/global-presence', 'sort_order' => 1],
        ],
      ],
      ['label' => 'Ministries', 'url' => '/ministries', 'sort_order' => 2],
      [
        'label' => 'Connect',
        'url' => '/connect',
        'sort_order' => 3,
        'children' => [
          ['label' => 'Counseling', 'url' => '/counseling', 'sort_order' => 0],
          ['label' => 'Events', 'url' => '/events', 'sort_order' => 1],
          ['label' => 'Blog', 'url' => '/blog', 'sort_order' => 2],
          ['label' => 'Vlog', 'url' => '/vlog', 'sort_order' => 3],
          ['label' => 'Gallery', 'url' => '/gallery', 'sort_order' => 4],
          ['label' => 'Resources', 'url' => '/resources', 'sort_order' => 5],
          ['label' => 'Business Review', 'url' => '/business-review', 'sort_order' => 6],
        ],
      ],
      ['label' => 'Contact', 'url' => '/contact', 'sort_order' => 4],
    ];

    foreach ($items as $item) {
      $children = $item['children'] ?? [];
      unset($item['children']);
      $parent = CmsMenuItem::query()->updateOrCreate(
        ['menu_id' => $menu->id, 'label' => $item['label'], 'parent_id' => null],
        array_merge($item, ['menu_id' => $menu->id, 'is_active' => true, 'parent_id' => null]),
      );
      foreach ($children as $child) {
        CmsMenuItem::query()->updateOrCreate(
          ['menu_id' => $menu->id, 'label' => $child['label'], 'parent_id' => $parent->id],
          array_merge($child, [
            'menu_id' => $menu->id,
            'parent_id' => $parent->id,
            'is_active' => true,
          ]),
        );
      }
    }
  }

  private function seedHomeSections(): void
  {
    CmsPage::query()->updateOrCreate(
      ['slug' => 'home'],
      ['title' => 'Home', 'status' => PageStatus::Published, 'published_at' => now()],
    );

    $sections = [
      [
        'page_slug' => 'home',
        'section_key' => 'hero',
        'section_type' => 'hero',
        'title' => 'Hero',
        'content' => [
          'eyebrow' => 'Global Kingdom Movement',
          'headline' => "Raising Marketplace Ministers\nDiscipling Kingdom Leaders\nAdvancing God's Agenda",
          'rotating_headlines' => [
            'Where Faith Meets Influence.',
            'Where Purpose Meets Marketplace Impact.',
            'Equipping Kingdom Professionals for Global Transformation.',
          ],
          'lede' => 'Marketplace Ministers exists to raise spiritually grounded and professionally excellent leaders who carry God\'s Kingdom into every sphere of influence.',
          'background_image_asset' => 'hero-world-map',
          'ctas' => [
            ['label' => 'Join The Tribe', 'to' => '/join', 'variant' => 'primary'],
            ['label' => 'Discover Your Calling', 'to' => '/ministries', 'variant' => 'secondary'],
          ],
          'story_video_url' => '',
          'media_cta' => ['label' => 'Watch Our Story', 'href' => '#our-story'],
        ],
        'sort_order' => 1,
      ],
      [
        'page_slug' => 'home',
        'section_key' => 'welcome',
        'section_type' => 'welcome',
        'title' => 'Welcome',
        'content' => [
          'eyebrow' => 'What is a Marketplace Minister?',
          'title' => 'A leader commissioned for the spheres of society.',
          'description' => 'Not a job title. A calling. Marketplace Ministers carry the presence and principles of God into the boardroom, the laboratory, the studio, the parliament — wherever they have been sent.',
          'image_asset' => 'marketplace-professionals',
          'image_alt' => 'Marketplace professionals',
          'badge_eyebrow' => 'Since 2018',
          'badge_text' => 'A global tribe.',
          'cta' => ['label' => 'Take Your Calling Assessment', 'to' => '/join'],
          'items' => [
            ['tag' => 'Discover', 'icon' => 'compass', 'body' => 'Discover your God-given assignment and the sphere of influence you are called to steward.'],
            ['tag' => 'Develop', 'icon' => 'graduation-cap', 'body' => 'Receive biblical leadership formation, mentorship, and world-class professional training.'],
            ['tag' => 'Deploy', 'icon' => 'target', 'body' => 'Deploy back into your industry as a marketplace minister transforming workplaces for Christ.'],
          ],
        ],
        'sort_order' => 2,
      ],
      [
        'page_slug' => 'home',
        'section_key' => 'about_intro',
        'section_type' => 'about',
        'title' => 'About Intro',
        'content' => [
          'eyebrow' => 'What is Marketplace Ministers?',
          'title' => 'A global movement of Kingdom professionals.',
          'summary' => 'Not a job title — a calling. Marketplace Ministers carry the presence and principles of God into boardrooms, laboratories, studios, and parliaments wherever they have been sent.',
          'mission' => 'Marketplace Ministers exists to raise spiritually grounded and professionally excellent leaders who carry God\'s Kingdom into every sphere of influence.',
          'vision' => 'A global tribe of executives, entrepreneurs, and ministers — multi-generational, multi-national, anchored in scripture, formed in prayer, and sent into the world.',
          'image_asset' => 'group-picture',
          'image_alt' => 'The Tribe of Marketplace Ministers community gathering',
          'image_badge_eyebrow' => 'Since 2018',
          'image_badge_text' => 'One tribe. Many nations.',
          'cta' => ['label' => 'Read More', 'to' => '/about'],
          'items' => [
            ['tag' => 'Discover', 'icon' => 'compass', 'body' => 'Discover your God-given assignment and the sphere of influence you are called to steward.'],
            ['tag' => 'Develop', 'icon' => 'graduation-cap', 'body' => 'Receive biblical leadership formation, mentorship, and world-class professional training.'],
            ['tag' => 'Deploy', 'icon' => 'target', 'body' => 'Deploy back into your industry as a marketplace minister transforming workplaces for Christ.'],
          ],
        ],
        'sort_order' => 3,
      ],
      [
        'page_slug' => 'home',
        'section_key' => 'statistics',
        'section_type' => 'statistics',
        'title' => 'Global Statistics',
        'content' => [
          'eyebrow' => 'Global Impact',
          'title' => 'A movement measured in nations.',
          'subtitle' => 'From local prayer cells to global summits — the fruit of a tribe pursuing God in the marketplace.',
          'items' => [
            ['label' => 'Countries Represented', 'value' => '42+'],
            ['label' => 'Leaders Equipped', 'value' => '12000+'],
            ['label' => 'Kingdom Projects', 'value' => '240+'],
            ['label' => 'Active Members', 'value' => '8500+'],
            ['label' => 'Prayer Communities', 'value' => '60+'],
            ['label' => 'Volunteer Network', 'value' => '1500+'],
          ],
        ],
        'sort_order' => 4,
      ],
      [
        'page_slug' => 'home',
        'section_key' => 'ministries_preview',
        'section_type' => 'ministries',
        'title' => 'Ministries Preview',
        'content' => [
          'items' => [
            ['icon' => 'flame', 'title' => 'Prayer Ministry', 'slug' => 'prayer-ministry', 'image_asset' => 'event-prayer', 'desc' => 'A 24/7 global prayer altar covering nations, leaders, and industries.'],
            ['icon' => 'heart', 'title' => 'Care Ministry', 'slug' => 'care-ministry', 'image_asset' => 'marketplace-professionals', 'desc' => 'Walking with members through life\'s seasons — counsel, comfort, community.'],
            ['icon' => 'briefcase', 'title' => 'Faith & Works', 'slug' => 'faith-and-works', 'image_asset' => 'event-masterclass', 'desc' => 'Integrating biblical conviction with professional excellence in every sphere.'],
            ['icon' => 'award', 'title' => 'Kingdom Funders', 'slug' => 'kingdom-funders', 'image_asset' => 'event-summit', 'desc' => 'Mobilising Kingdom capital toward strategic, eternity-shaping initiatives.'],
            ['icon' => 'target', 'title' => 'Forerunners', 'slug' => 'forerunners', 'image_asset' => 'about-movement', 'desc' => 'Pioneering platforms for the next generation of marketplace leaders.'],
            ['icon' => 'heart-handshake', 'title' => 'Outreach', 'slug' => 'outreach', 'image_asset' => 'hero-summit', 'desc' => 'Carrying the gospel and tangible care into cities, communities, and campuses.'],
          ],
        ],
        'sort_order' => 5,
      ],
      [
        'page_slug' => 'home',
        'section_key' => 'leadership_spotlight',
        'section_type' => 'leadership',
        'title' => 'Leadership Spotlight',
        'content' => [
          'eyebrow' => 'Leadership',
          'title' => 'Convened by a visionary leader.',
          'subtitle' => 'The movement is shepherded by a trusted council — beginning with our Convener & Lead Visionary.',
          'philosophy' => 'Marketplace leadership is a sacred assignment — executives and entrepreneurs are called to carry God\'s presence into every decision, deal, and boardroom.',
          'vision_statement' => 'To raise a generation of marketplace ministers who transform industries, nations, and cultures through biblical conviction and professional excellence.',
          'cta' => ['label' => 'Meet the Leadership Team', 'to' => '/leadership'],
        ],
        'sort_order' => 6,
      ],
      [
        'page_slug' => 'home',
        'section_key' => 'prayer_watch',
        'section_type' => 'prayer_watch',
        'title' => 'Prayer Watch',
        'content' => [
          'eyebrow' => 'Prayer Watch',
          'title' => 'Take Prayer Everywhere.',
          'description' => 'The Prayer Watch app is your 24/7 portal into the global prayer altar of Marketplace Ministers. Track your hours, intercede with the tribe, receive daily scripture, and join live prayer rooms — wherever you are in the world.',
          'items' => [
            ['icon' => 'globe', 'title' => 'Global 24/7 prayer rooms', 'text' => 'Pray with live presence across time zones.'],
            ['icon' => 'flame', 'title' => 'Personal prayer tracker', 'text' => 'Track prayer hours, streaks, and intercession rhythms.'],
            ['icon' => 'book-open', 'title' => 'Daily scripture prompts', 'text' => 'Receive marketplace-shaped devotionals and prophetic prompts.'],
            ['icon' => 'users', 'title' => 'Country and industry cells', 'text' => 'Join focused watches for nations, sectors, and leaders.'],
          ],
          'schedule' => [
            'Daily watches · 6:00 AM, 12:00 PM, 6:00 PM',
            'Weekly Watchmen Prayer Convergence · Tuesdays 6:00 AM',
            'Monthly global altar · First Friday',
          ],
          'ctas' => [
            ['label' => 'Join Prayer Watch', 'to' => '/prayer-watch', 'variant' => 'primary'],
            ['label' => 'Submit Prayer Request', 'to' => '/prayer-watch#request', 'variant' => 'secondary'],
          ],
          'images' => [
            ['image_asset' => 'prayer-watch-phone-2', 'alt' => 'Prayer Watch app prayer list'],
            ['image_asset' => 'prayer-watch-phone-1', 'alt' => 'Prayer Watch app dashboard'],
          ],
        ],
        'sort_order' => 7,
      ],
      [
        'page_slug' => 'home',
        'section_key' => 'media_center',
        'section_type' => 'media',
        'title' => 'Media Center',
        'content' => [
          'eyebrow' => 'Media Center',
          'title' => 'Teaching, stories, and witness.',
          'subtitle' => 'Stay shaped by the message and the moments fueling Marketplace Ministers.',
          'items' => [
            ['icon' => 'pen-line', 'title' => 'Blog', 'desc' => 'Long-form essays, teaching, and reflections from the tribe.', 'cta' => 'Read articles', 'to' => '/blog'],
            ['icon' => 'video', 'title' => 'Vlog', 'desc' => 'Sermons, panels, and interviews from leaders across the movement.', 'cta' => 'Watch videos', 'to' => '/vlog'],
            ['icon' => 'images', 'title' => 'Gallery', 'desc' => 'Moments from summits, gatherings, and ministry on the ground.', 'cta' => 'View gallery', 'to' => '/gallery'],
          ],
        ],
        'sort_order' => 8,
      ],
      [
        'page_slug' => 'home',
        'section_key' => 'events',
        'section_type' => 'events',
        'title' => 'Featured Events',
        'content' => [
          'eyebrow' => 'Upcoming Events',
          'title' => 'Gather with the tribe.',
          'subtitle' => 'Summits, masterclasses, and prayer convergences forming Kingdom leaders for the marketplace.',
          'cta' => ['label' => 'View All Events', 'to' => '/events'],
          'items' => [
            ['slug' => 'global-summit', 'title' => 'Global Marketplace Summit', 'date' => '14 — 16 NOV 2026', 'location' => 'Lagos, Nigeria', 'image_asset' => 'event-summit', 'to' => '/events', 'desc' => 'Three days of teaching, deal-making, and prayer with kingdom executives from 40+ nations.'],
            ['slug' => 'executive-masterclass', 'title' => 'Executive Leadership Masterclass', 'date' => '08 FEB 2027', 'location' => 'London, UK', 'image_asset' => 'event-masterclass', 'to' => '/events', 'desc' => 'An intimate boardroom-style intensive for C-suite ministers in the marketplace.'],
            ['slug' => 'watchmen-prayer', 'title' => 'Watchmen Prayer Convergence', 'date' => 'WEEKLY · TUE 6AM', 'location' => 'Online · Global', 'image_asset' => 'event-prayer', 'to' => '/events', 'desc' => 'Marketplace ministers gathering weekly to intercede for nations and industries.'],
          ],
        ],
        'sort_order' => 9,
      ],
      [
        'page_slug' => 'home',
        'section_key' => 'courses',
        'section_type' => 'courses',
        'title' => 'Courses',
        'content' => [
          'eyebrow' => 'Learning',
          'title' => 'Featured Courses',
          'subtitle' => 'Grow in faith and marketplace leadership through our learning programmes.',
          'description' => 'CMS-managed course collections for the public homepage.',
          'banner_image_asset' => '',
          'grids' => [
            [
              'id' => 'featured',
              'title' => 'Featured Courses',
              'subtitle' => 'Hand-picked learning paths for the tribe.',
              'source' => 'featured',
              'limit' => 3,
              'visible' => true,
              'course_slugs' => [],
            ],
            [
              'id' => 'popular',
              'title' => 'Popular Courses',
              'subtitle' => 'Courses learners engage with most.',
              'source' => 'popular',
              'limit' => 3,
              'visible' => true,
              'course_slugs' => [],
            ],
            [
              'id' => 'latest',
              'title' => 'Latest Courses',
              'subtitle' => 'Recently published learning paths.',
              'source' => 'latest',
              'limit' => 3,
              'visible' => true,
              'course_slugs' => [],
            ],
          ],
          'cta_title' => 'Start learning today',
          'cta_subtitle' => 'Browse the catalogue or create a Learning Portal account to enrol as a public learner.',
          'cta_primary' => 'Browse courses',
          'cta_primary_to' => '/courses',
          'cta_secondary' => 'Create learner account',
          'cta_secondary_to' => '/learn/register',
          'items' => [],
        ],
        'sort_order' => 10,
      ],
      [
        'page_slug' => 'home',
        'section_key' => 'donate_cta',
        'section_type' => 'donate',
        'title' => 'Donate CTA',
        'content' => [
          'eyebrow' => 'Partner with Us',
          'title' => 'Fund the movement from your nation.',
          'description' => 'Your partnership sustains prayer, training, missions, and the formation of marketplace ministers across the world.',
          'buttons' => [
            ['label' => 'Donate', 'to' => '/donate', 'variant' => 'primary'],
            ['label' => 'Partner With Us', 'to' => '/partner', 'variant' => 'secondary'],
          ],
          'countries' => [
            ['slug' => 'nigeria', 'name' => 'Nigeria'],
            ['slug' => 'ghana', 'name' => 'Ghana'],
            ['slug' => 'kenya', 'name' => 'Kenya'],
            ['slug' => 'tanzania', 'name' => 'Tanzania'],
            ['slug' => 'south-africa', 'name' => 'South Africa'],
            ['slug' => 'usa', 'name' => 'United States'],
            ['slug' => 'others', 'name' => 'Others'],
          ],
          'payment_note' => 'Secure payment integration coming soon',
        ],
        'sort_order' => 11,
      ],
      [
        'page_slug' => 'home',
        'section_key' => 'global_presence',
        'section_type' => 'global_presence',
        'title' => 'Global Presence',
        'content' => [
          'eyebrow' => 'Global Presence',
          'title' => 'One tribe. Many nations.',
          'subtitle' => 'From Lagos to Nairobi, Johannesburg to New York — Marketplace Ministers is rising across the world.',
        ],
        'sort_order' => 11,
      ],
      [
        'page_slug' => 'home',
        'section_key' => 'global_map',
        'section_type' => 'map',
        'title' => 'Global Map',
        'content' => [
          'country_slugs' => ['nigeria', 'ghana', 'kenya', 'south-africa', 'usa', 'united-kingdom', 'tanzania'],
          'connections' => [
            ['nigeria', 'ghana'], ['nigeria', 'kenya'], ['nigeria', 'south-africa'],
            ['nigeria', 'usa'], ['nigeria', 'united-kingdom'], ['nigeria', 'tanzania'],
            ['ghana', 'kenya'], ['kenya', 'tanzania'], ['usa', 'united-kingdom'],
          ],
          'launched_years' => [
            'nigeria' => 2018, 'ghana' => 2020, 'kenya' => 2021, 'south-africa' => 2022,
            'usa' => 2023, 'united-kingdom' => 2023, 'tanzania' => 2024,
          ],
        ],
        'sort_order' => 12,
      ],
      [
        'page_slug' => 'home',
        'section_key' => 'testimonials',
        'section_type' => 'testimonials',
        'title' => 'Testimonials',
        'content' => [
          'eyebrow' => 'Testimonies',
          'title' => 'Lives reshaped by the tribe.',
          'photo_assets' => [
            'Adaeze O.' => 'gallery-community-selfie',
            'James W.' => 'gallery-fellowship-event',
            'Lerato M.' => 'gallery-tribe-gathering',
            'Daniel K.' => 'gallery-prayer-gathering',
          ],
        ],
        'sort_order' => 13,
      ],
      [
        'page_slug' => 'home',
        'section_key' => 'join_cta',
        'section_type' => 'cta',
        'title' => 'Join CTA',
        'content' => [
          'eyebrow' => 'Step Into the Movement',
          'title' => 'Your calling is bigger than your career.',
          'description' => 'Begin your Marketplace Ministry journey today. Join a global tribe walking together in prayer, formation, and Kingdom influence — and discover what God is positioning you to build.',
          'buttons' => [
            ['label' => 'Join The Tribe', 'to' => '/join', 'variant' => 'primary'],
            ['label' => 'Take Assessment', 'to' => '/join', 'variant' => 'secondary'],
          ],
        ],
        'sort_order' => 14,
      ],
      [
        'page_slug' => 'home',
        'section_key' => 'newsletter',
        'section_type' => 'newsletter',
        'title' => 'Newsletter',
        'content' => [
          'title' => 'Stay connected to the movement.',
          'description' => 'Monthly teaching, event invitations, and stories from marketplace ministers around the world.',
        ],
        'sort_order' => 15,
      ],
    ];

    foreach ($sections as $section) {
      CmsPageSection::query()->updateOrCreate(
        ['page_slug' => $section['page_slug'], 'section_key' => $section['section_key']],
        array_merge($section, ['is_active' => true]),
      );
    }
  }

  private function seedCountries(): void
  {
    $ministries = [
      'prayer-ministry' => ['name' => 'Prayer Ministry', 'slug' => 'prayer-ministry', 'icon' => 'prayer', 'tagline' => 'An altar that never goes cold.', 'summary' => 'A global house of prayer carrying the marketplace, leaders, and nations before the throne of God.', 'content' => ['image_asset' => 'event-prayer']],
      'care-ministry' => ['name' => 'Care Ministry', 'slug' => 'care-ministry', 'icon' => 'care', 'tagline' => 'No leader walks alone.', 'summary' => 'Pastoral care, counsel, and biblical companionship for marketplace leaders.', 'content' => ['image_asset' => 'marketplace-professionals']],
      'faith-and-works' => ['name' => 'Faith & Works Clinic', 'slug' => 'faith-and-works', 'icon' => 'clinic', 'tagline' => 'Where vocation meets devotion.', 'summary' => 'Equipping clinics that bridge biblical conviction and excellence in professional practice.', 'content' => ['image_asset' => 'event-masterclass']],
      'kingdom-funders' => ['name' => 'Kingdom Funders', 'slug' => 'kingdom-funders', 'icon' => 'funders', 'tagline' => 'Capital with conviction.', 'summary' => 'A community of givers, investors, and builders deploying resources for Kingdom impact.', 'content' => ['image_asset' => 'event-summit']],
      'forerunners' => ['name' => 'Forerunners', 'slug' => 'forerunners', 'icon' => 'forerunners', 'tagline' => 'Pioneers preparing the way.', 'summary' => 'A young leaders pipeline for emerging marketplace ministers.', 'content' => ['image_asset' => 'about-movement']],
      'outreach' => ['name' => 'Outreach', 'slug' => 'outreach', 'icon' => 'outreach', 'tagline' => 'The gospel goes where we go.', 'summary' => 'Mission, mercy, and movement-building that takes the Tribe beyond its own walls.', 'content' => ['image_asset' => 'hero-summit']],
    ];

    $leaders = [
      'damola-adelakun' => ['name' => 'Damola Adelakun', 'slug' => 'damola-adelakun', 'role' => 'Convener & Lead Visionary', 'location' => 'Charlotte, United States', 'bio' => 'Entrepreneur and minister raising kingdom professionals to influence the marketplace with biblical values.', 'image_asset' => 'leader-damola-adelakun'],
      'jonathan-oraka' => ['name' => 'Jonathan Oraka', 'slug' => 'jonathan-oraka', 'role' => 'Director · Ministries', 'location' => 'Abuja, Nigeria', 'bio' => 'Leading the deployment of the Tribe\'s ministry arms across regions and professional verticals.', 'image_asset' => 'leader-jonathan-oraka'],
      'stephen-nyaega' => ['name' => 'Stephen Nyaega', 'slug' => 'stephen-nyaega', 'role' => 'Regional Lead · East Africa', 'location' => 'Nairobi, Kenya', 'bio' => 'Convening executives and entrepreneurs across East Africa around shared Kingdom mandates.', 'image_asset' => 'leader-stephen-nyaega'],
      'lily-mahlo' => ['name' => 'Lily Mahlo', 'slug' => 'lily-mahlo', 'role' => 'Regional Lead · Southern Africa', 'location' => 'Johannesburg, South Africa', 'bio' => 'Mobilising young professionals across Southern Africa for Kingdom impact in business and policy.', 'image_asset' => 'leader-lily-mahlo'],
      'yemi-akins' => ['name' => 'Yemi Akins', 'slug' => 'yemi-akins', 'role' => 'Council Member · Executive Mentor', 'location' => 'London, United Kingdom', 'bio' => 'Seasoned executive coaching Kingdom leaders in C-suites and boardrooms around the world.', 'image_asset' => 'leader-yemi-akins'],
    ];

    $countries = [
      [
        'name' => 'Nigeria', 'slug' => 'nigeria', 'code' => 'NG', 'flag_emoji' => '🇳🇬', 'region' => 'West Africa',
        'summary' => 'The founding hub of the movement — a vibrant network of executives across Lagos, Abuja, and Port Harcourt.',
        'latitude' => 58, 'longitude' => 49.5, 'sort_order' => 1,
        'launched_year' => 2018,
        'content' => [
          'leader' => 'Damola Adelakun', 'status' => 'Active', 'members' => '1,200+', 'meeting' => 'Monthly Executive Forum · First Saturday',
          'contact_email' => 'info@marketplaceministers.net', 'whatsapp_url' => '', 'image_asset' => 'gallery-tribe-gathering',
          'history' => 'Nigeria is the founding hub of the Tribe — where the first executive forums convened and the global vision was birthed. From Lagos to Abuja and Port Harcourt, marketplace ministers are shaping industries with biblical conviction.',
          'chapter_count' => '4',
          'leadership_team' => [$leaders['damola-adelakun'], $leaders['jonathan-oraka']],
          'local_ministries' => [$ministries['prayer-ministry'], $ministries['faith-and-works'], $ministries['kingdom-funders'], $ministries['forerunners'], $ministries['outreach']],
          'gallery' => [
            ['id' => 'g1', 'title' => 'Nigeria prayer gathering', 'image_asset' => 'gallery-prayer-gathering'],
            ['id' => 'g2', 'title' => 'Executive forum', 'image_asset' => 'gallery-tribe-gathering'],
            ['id' => 'g3', 'title' => 'Marketplace fellowship', 'image_asset' => 'gallery-fellowship-event'],
          ],
          'events' => [
            ['slug' => 'global-summit', 'title' => 'Global Marketplace Summit', 'date' => '14 — 16 NOV 2026', 'location' => 'Lagos, Nigeria', 'summary' => 'Three days of teaching, deal-making, and prayer with kingdom executives from 40+ nations.', 'image_asset' => 'event-summit'],
          ],
          'prayer_requests' => ['Wisdom for national leaders and economic stability', 'Expansion of executive forums across major cities', 'Protection and provision for emerging marketplace ministers'],
        ],
      ],
      [
        'name' => 'Ghana', 'slug' => 'ghana', 'code' => 'GH', 'flag_emoji' => '🇬🇭', 'region' => 'West Africa',
        'summary' => 'Accra-based chapter mobilising marketplace ministers across banking, tech, and creative industries.',
        'latitude' => 58, 'longitude' => 47.5, 'sort_order' => 2,
        'launched_year' => 2020,
        'content' => [
          'leader' => '[PRODUCTION PENDING: country leader]', 'status' => 'Active', 'members' => '320+', 'meeting' => 'Bi-monthly · Second Friday',
          'contact_email' => 'info@marketplaceministers.net', 'whatsapp_url' => '', 'image_asset' => 'gallery-community-selfie',
          'history' => 'The Ghana chapter mobilises marketplace ministers across Accra\'s banking, technology, and creative sectors — a West African gateway for Kingdom influence.',
          'chapter_count' => '2',
          'leadership_team' => [],
          'local_ministries' => [$ministries['prayer-ministry'], $ministries['care-ministry'], $ministries['outreach']],
          'gallery' => [
            ['id' => 'g2', 'title' => 'Accra fellowship', 'image_asset' => 'gallery-community-selfie'],
            ['id' => 'g4', 'title' => 'Prayer room', 'image_asset' => 'gallery-prayer-gathering'],
            ['id' => 'g7', 'title' => 'Chapter gathering', 'image_asset' => 'gallery-tribe-gathering'],
          ],
          'events' => [],
          'prayer_requests' => ['Appointment of a confirmed country leader', 'Growth of the Accra executive community', 'Partnerships with local churches and businesses'],
        ],
      ],
      [
        'name' => 'Kenya', 'slug' => 'kenya', 'code' => 'KE', 'flag_emoji' => '🇰🇪', 'region' => 'East Africa',
        'summary' => 'Nairobi convergence of professionals shaping East African enterprise with biblical conviction.',
        'latitude' => 63, 'longitude' => 58, 'sort_order' => 3,
        'launched_year' => 2019,
        'content' => [
          'leader' => 'Stephen Nyaega', 'status' => 'Active', 'members' => '480+', 'meeting' => 'Monthly · Last Saturday',
          'contact_email' => 'info@marketplaceministers.net', 'whatsapp_url' => '', 'image_asset' => 'gallery-fellowship-event',
          'history' => 'Kenya\'s Nairobi chapter converges East African professionals shaping enterprise with biblical conviction — a regional anchor for the movement.',
          'chapter_count' => '3',
          'leadership_team' => [$leaders['stephen-nyaega']],
          'local_ministries' => [$ministries['prayer-ministry'], $ministries['outreach'], $ministries['forerunners']],
          'gallery' => [
            ['id' => 'g3', 'title' => 'Nairobi forum', 'image_asset' => 'gallery-fellowship-event'],
            ['id' => 'g5', 'title' => 'Formation gathering', 'image_asset' => 'gallery-tribe-gathering'],
            ['id' => 'g6', 'title' => 'Prayer moment', 'image_asset' => 'gallery-prayer-gathering'],
          ],
          'events' => [],
          'prayer_requests' => ['Strengthening of the Nairobi executive forum', 'Outreach into universities and tech hubs', 'Regional expansion across East Africa'],
        ],
      ],
      [
        'name' => 'South Africa', 'slug' => 'south-africa', 'code' => 'ZA', 'flag_emoji' => '🇿🇦', 'region' => 'Southern Africa',
        'summary' => 'A growing chapter spanning Johannesburg, Pretoria, and Cape Town — bridging faith, culture and enterprise.',
        'latitude' => 80, 'longitude' => 55, 'sort_order' => 4,
        'launched_year' => 2021,
        'content' => [
          'leader' => 'Lily Mahlo', 'status' => 'Active', 'members' => '260+', 'meeting' => 'Monthly · Second Saturday',
          'contact_email' => 'info@marketplaceministers.net', 'whatsapp_url' => '', 'image_asset' => 'gallery-tribe-gathering',
          'history' => 'Spanning Johannesburg, Pretoria, and Cape Town, the South Africa chapter bridges faith, culture, and enterprise across the southern continent.',
          'chapter_count' => '3',
          'leadership_team' => [$leaders['lily-mahlo']],
          'local_ministries' => [$ministries['care-ministry'], $ministries['outreach'], $ministries['prayer-ministry']],
          'gallery' => [
            ['id' => 'g4', 'title' => 'Southern Africa gathering', 'image_asset' => 'gallery-tribe-gathering'],
            ['id' => 'g6', 'title' => 'Community outreach', 'image_asset' => 'gallery-community-selfie'],
            ['id' => 'g7', 'title' => 'Prayer circle', 'image_asset' => 'gallery-prayer-gathering'],
          ],
          'events' => [],
          'prayer_requests' => ['Unity across metro chapters', 'Mercy initiatives in underserved communities', 'Formation of young marketplace leaders'],
        ],
      ],
      [
        'name' => 'Rwanda', 'slug' => 'rwanda', 'code' => 'RW', 'flag_emoji' => '🇷🇼', 'region' => 'East Africa',
        'summary' => 'Marketplace ministers gathering and deploying across Rwanda.',
        'latitude' => -1.94, 'longitude' => 30.06, 'sort_order' => 8,
        'launched_year' => 2024,
        'content' => [
          'leader' => 'Emma Kayonde', 'status' => 'Active', 'members' => '', 'meeting' => '',
          'contact_email' => 'info@marketplaceministers.net', 'whatsapp_url' => '', 'image_asset' => 'gallery-tribe-gathering',
          'history' => 'A growing Rwanda chapter of marketplace ministers carrying the Tribe mandate with local fluency.',
          'chapter_count' => '1',
          'leadership_team' => [],
          'local_ministries' => [],
          'gallery' => [],
          'events' => [],
          'prayer_requests' => [],
        ],
      ],
      [
        'name' => 'United States', 'slug' => 'usa', 'code' => 'US', 'flag_emoji' => '🇺🇸', 'region' => 'North America',
        'summary' => 'An emerging diaspora gathering of Kingdom professionals across major US metros.',
        'latitude' => 42, 'longitude' => 22, 'sort_order' => 5,
        'launched_year' => 2022,
        'content' => [
          'leader' => '[PRODUCTION PENDING: country leader]', 'status' => 'Active', 'members' => '180+', 'meeting' => 'Virtual Quarterly Forum',
          'contact_email' => 'info@marketplaceministers.net', 'whatsapp_url' => '', 'image_asset' => 'about-movement',
          'history' => 'An emerging diaspora gathering of Kingdom professionals across major US metros — connecting African and global marketplace ministers in North America.',
          'chapter_count' => '2',
          'leadership_team' => [],
          'local_ministries' => [$ministries['prayer-ministry'], $ministries['faith-and-works'], $ministries['kingdom-funders']],
          'gallery' => [
            ['id' => 'g1', 'title' => 'Diaspora forum', 'image_asset' => 'gallery-fellowship-event'],
            ['id' => 'g5', 'title' => 'Community gathering', 'image_asset' => 'gallery-community-selfie'],
            ['id' => 'g7', 'title' => 'Prayer watch', 'image_asset' => 'gallery-prayer-gathering'],
          ],
          'events' => [],
          'prayer_requests' => ['Appointment of a US country leader', 'Virtual forum growth across time zones', 'Bridge-building between diaspora and home nations'],
        ],
      ],
      [
        'name' => 'Tanzania', 'slug' => 'tanzania', 'code' => 'TZ', 'flag_emoji' => '🇹🇿', 'region' => 'East Africa',
        'summary' => 'Emerging chapter in Dar es Salaam exploring marketplace ministry across East Africa.',
        'latitude' => 67, 'longitude' => 58, 'sort_order' => 6,
        'launched_year' => 2023,
        'content' => [
          'leader' => '[PRODUCTION PENDING: country leader]', 'status' => 'Emerging', 'members' => 'Launching', 'meeting' => 'TBA',
          'contact_email' => 'info@marketplaceministers.net', 'whatsapp_url' => '', 'image_asset' => 'hero-summit',
          'history' => 'An emerging chapter in Dar es Salaam exploring marketplace ministry across East Africa — building foundations for regional impact.',
          'chapter_count' => '1',
          'leadership_team' => [],
          'local_ministries' => [$ministries['prayer-ministry'], $ministries['outreach']],
          'gallery' => [
            ['id' => 'g2', 'title' => 'East Africa fellowship', 'image_asset' => 'gallery-fellowship-event'],
            ['id' => 'g3', 'title' => 'Prayer gathering', 'image_asset' => 'gallery-prayer-gathering'],
            ['id' => 'g6', 'title' => 'Community moment', 'image_asset' => 'gallery-community-selfie'],
          ],
          'events' => [],
          'prayer_requests' => ['Confirmation of local leadership', 'First executive gathering in Dar es Salaam', 'Partnerships with regional chapters'],
        ],
      ],
      [
        'name' => 'United Kingdom', 'slug' => 'united-kingdom', 'code' => 'GB', 'flag_emoji' => '🇬🇧', 'region' => 'Europe',
        'summary' => 'London-anchored gathering of diaspora executives carrying the mandate into European boardrooms.',
        'latitude' => 35, 'longitude' => 47, 'sort_order' => 7,
        'launched_year' => 2020,
        'content' => [
          'leader' => 'Yemi Akins', 'status' => 'Emerging', 'members' => '120+', 'meeting' => 'Quarterly Executive Dinner',
          'contact_email' => 'info@marketplaceministers.net', 'whatsapp_url' => '', 'image_asset' => 'event-masterclass',
          'history' => 'The United Kingdom chapter convenes marketplace ministers across London and beyond — a bridge between Africa, Europe, and global enterprise.',
          'chapter_count' => '2',
          'leadership_team' => [$leaders['yemi-akins']],
          'local_ministries' => [$ministries['faith-and-works'], $ministries['prayer-ministry'], $ministries['kingdom-funders']],
          'gallery' => [
            ['id' => 'g3', 'title' => 'London masterclass', 'image_asset' => 'gallery-fellowship-event'],
            ['id' => 'g5', 'title' => 'Executive dinner', 'image_asset' => 'gallery-tribe-gathering'],
            ['id' => 'g8', 'title' => 'Prayer watch', 'image_asset' => 'gallery-prayer-gathering'],
          ],
          'events' => [
            ['slug' => 'executive-masterclass', 'title' => 'Executive Leadership Masterclass', 'date' => '08 FEB 2027', 'location' => 'London, UK', 'summary' => 'An intimate boardroom-style intensive for C-suite ministers in the marketplace.', 'image_asset' => 'event-masterclass'],
          ],
          'prayer_requests' => ['Executive masterclass outreach across the UK', 'Mentorship for diaspora leaders', 'Strengthening prayer watches across Europe'],
        ],
      ],
      [
        'name' => 'Future Nations', 'slug' => 'future-nations', 'code' => null, 'flag_emoji' => '🌍', 'region' => 'Global',
        'summary' => 'We are believing for a Tribe presence in every sphere of global marketplace influence.',
        'latitude' => 50, 'longitude' => 75, 'sort_order' => 8,
        'launched_year' => null,
        'content' => [
          'leader' => 'Praying & Preparing', 'status' => 'Future Nation', 'members' => 'TBA', 'meeting' => 'Be the first to start one in your nation.',
          'contact_email' => 'info@marketplaceministers.net', 'whatsapp_url' => '', 'image_asset' => 'hero-world-map',
          'history' => 'Future Nations represents territories where the Tribe is praying, pioneering, and preparing the ground for new chapters.',
          'chapter_count' => '0',
          'leadership_team' => [],
          'local_ministries' => [],
          'gallery' => [
            ['id' => 'g1', 'title' => 'Global prayer', 'image_asset' => 'gallery-prayer-gathering'],
            ['id' => 'g2', 'title' => 'Global tribe', 'image_asset' => 'gallery-tribe-gathering'],
            ['id' => 'g3', 'title' => 'Marketplace gathering', 'image_asset' => 'gallery-fellowship-event'],
          ],
          'events' => [],
          'prayer_requests' => ['Open doors for new national chapters', 'Pioneers raised in unreached nations', 'Strategic partnerships for chapter planting'],
        ],
      ],
    ];

    foreach ($countries as $country) {
      CmsCountry::query()->updateOrCreate(
        ['slug' => $country['slug']],
        array_merge($country, ['is_active' => true]),
      );
    }
  }

  private function seedMinistries(): void
  {
    $ministries = [
      [
        'name' => 'Prayer Ministry',
        'slug' => 'prayer-ministry',
        'icon' => 'prayer',
        'tagline' => 'An altar that never goes cold.',
        'summary' => 'A global house of prayer carrying the marketplace, leaders, and nations before the throne of God.',
        'about' => 'The Prayer Ministry is the engine room of the Tribe. We convene watches across time zones, intercede for businesses and governments, and equip marketplace ministers to lead from a place of communion with the Holy Spirit.',
        'purposes' => [
          ['title' => 'Sustain the Altar', 'description' => 'Maintain a 24/7 rhythm of intercession over the marketplace.'],
          ['title' => 'Stand for Leaders', 'description' => 'Cover executives, founders, and policymakers in focused prayer.'],
          ['title' => 'Shift Atmospheres', 'description' => 'Contend for cities, industries, and nations through prophetic prayer.'],
        ],
        'content' => [
          'image_asset' => 'event-prayer',
          'responsibilities' => ['Lead weekly prayer watches', 'Coordinate prayer requests across regions', 'Develop intercessors and prayer leaders', 'Host seasonal prayer summits'],
          'who_can_join' => 'Believers with a burden for the marketplace and a commitment to consistent intercession.',
          'faqs' => [
            ['question' => 'Do I need prior experience?', 'answer' => 'No. We provide training and pair you with a seasoned intercessor.'],
            ['question' => 'How much time is required?', 'answer' => 'A minimum of two hours per week, with optional watches throughout the day.'],
          ],
          'mission' => 'To sustain a global altar of intercession that covers marketplace leaders, industries, and nations without ceasing.',
          'vision' => 'A world where every marketplace sphere is saturated with prayer — shifting atmospheres and releasing Kingdom outcomes.',
          'purpose' => 'Prayer Ministry exists to keep the marketplace before God\'s throne — anchoring every executive decision, industry shift, and national moment in intercession.',
          'what_we_do' => 'We coordinate global prayer watches, receive and route intercession requests, train prayer leaders, and convene seasonal summits where marketplace ministers contend together for nations and industries.',
          'focus_areas' => [
            ['title' => '24/7 Prayer Altar', 'description' => 'Continuous watches across time zones covering leaders, businesses, and governments.'],
            ['title' => 'Industry Intercession', 'description' => 'Targeted prayer cells for finance, technology, media, healthcare, and government.'],
            ['title' => 'Leader Covering', 'description' => 'Dedicated intercession for executives, founders, and policymakers under pressure.'],
          ],
          'programs' => [
            'weekly' => ['Watchmen Prayer Convergence — Tuesdays 6AM GMT', 'Regional night watches — Fridays', 'Personal prayer hour tracking via Prayer Watch'],
            'monthly' => ['First Saturday Executive Prayer Forum', 'Industry-specific prayer cells', 'Intercessor training clinics'],
            'annual' => ['Global Prayer Summit', '40-day marketplace fast & prayer', 'Nations Week intercession'],
          ],
          'leaders' => [
            ['name' => 'Jonathan Oraka', 'role' => 'Director · Ministries', 'location' => 'Abuja, Nigeria', 'bio' => 'Leading the deployment of the Tribe\'s ministry arms across regions and professional verticals.', 'image_asset' => 'leader-jonathan-oraka'],
            ['name' => 'Damola Adelakun', 'role' => 'Convener & Lead Visionary', 'location' => 'Charlotte, United States', 'bio' => 'Entrepreneur and minister raising kingdom professionals to influence the marketplace with biblical values.', 'image_asset' => 'leader-damola-adelakun'],
          ],
          'gallery' => [
            ['id' => 'g1', 'title' => 'Prayer Gathering', 'image_asset' => 'gallery-prayer-gathering'],
            ['id' => 'g2', 'title' => 'Tribe Gathering', 'image_asset' => 'gallery-tribe-gathering'],
            ['id' => 'g3', 'title' => 'Fellowship Event', 'image_asset' => 'gallery-fellowship-event'],
          ],
          'events' => [
            ['slug' => 'watchmen-prayer', 'title' => 'Watchmen Prayer Convergence', 'date' => 'WEEKLY · TUE 6AM', 'location' => 'Online · Global', 'summary' => 'Marketplace ministers gathering weekly to intercede for nations and industries.', 'image_asset' => 'event-prayer'],
          ],
          'testimonials' => [
            ['name' => 'Daniel K.', 'role' => 'Healthcare Leader', 'country' => 'Kenya', 'quote' => 'Prayer Ministry taught me that my boardroom begins on my knees. Our hospital culture shifted when we started praying as a leadership team.'],
          ],
          'scripture' => ['reference' => '1 Thessalonians 5:17', 'text' => 'Pray without ceasing.'],
          'how_to_join' => ['Submit a membership application and select Prayer Ministry as your preferred assignment.', 'Complete intercessor orientation and covenant training.', 'Commit to a minimum weekly watch and join a regional prayer cell.', 'Receive your watch schedule and begin covering marketplace leaders.'],
          'contact_email' => 'info@marketplaceministers.net',
          'contact_message' => 'Reach the Prayer Ministry team for watch assignments, training, or regional coordination.',
          'related_ministry_slugs' => ['care-ministry', 'outreach', 'faith-and-works'],
        ],
        'sort_order' => 1,
      ],
      [
        'name' => 'Care Ministry',
        'slug' => 'care-ministry',
        'icon' => 'care',
        'tagline' => 'No leader walks alone.',
        'summary' => 'Pastoral care, counsel, and biblical companionship for the unique pressures of marketplace leadership.',
        'about' => 'Care Ministry surrounds members with the kind of pastoral attention executives rarely receive. From confidential counsel to crisis response, we ensure no minister in the marketplace is left without a hand to hold.',
        'purposes' => [
          ['title' => 'Pastoral Presence', 'description' => 'Be present in the seasons that matter most — celebration, loss, transition.'],
          ['title' => 'Confidential Counsel', 'description' => 'Provide biblically rooted guidance for personal and professional crossroads.'],
          ['title' => 'Restorative Community', 'description' => 'Create safe rooms where leaders can lay their burdens down.'],
        ],
        'content' => [
          'image_asset' => 'marketplace-professionals',
          'responsibilities' => ['Conduct member care calls', 'Mobilise rapid response in crisis moments', 'Coordinate counseling referrals', 'Host healing and restoration retreats'],
          'who_can_join' => 'Mature believers gifted in compassion, listening, and pastoral discernment.',
          'faqs' => [
            ['question' => 'Is this professional counseling?', 'answer' => 'It is pastoral care. Where clinical support is needed, we refer to certified counselors.'],
            ['question' => 'Is everything confidential?', 'answer' => 'Yes — confidentiality is non-negotiable and protected by our care covenant.'],
          ],
          'mission' => 'To surround every marketplace minister with pastoral care, counsel, and companionship through every season of life.',
          'vision' => 'No leader in the marketplace walks alone — every member is known, cared for, and restored.',
          'purpose' => 'Care Ministry ensures that the pressures of leadership never isolate a marketplace minister from pastoral presence, biblical counsel, and restorative community.',
          'what_we_do' => 'We provide confidential pastoral care calls, crisis response, counseling referrals, healing retreats, and companionship for members navigating professional and personal crossroads.',
          'focus_areas' => [
            ['title' => 'Pastoral Presence', 'description' => 'Consistent care calls and check-ins for members across seasons of life.'],
            ['title' => 'Crisis Response', 'description' => 'Rapid mobilisation when leaders face loss, transition, or professional crisis.'],
            ['title' => 'Restoration', 'description' => 'Retreats and safe spaces for healing, renewal, and spiritual refreshment.'],
          ],
          'programs' => [
            'weekly' => ['Member care check-in calls', 'Confidential counsel appointments', 'Prayer support for members in transition'],
            'monthly' => ['Care circle gatherings', 'Marriage & family support sessions', 'Grief and loss companion groups'],
            'annual' => ['Healing & Restoration Retreat', 'Leaders Wellness Summit', 'Care Ministry training intensive'],
          ],
          'leaders' => [
            ['name' => 'Emi Adelakun', 'role' => 'Co-Convener · Women in Marketplace', 'location' => 'Charlotte, United States', 'bio' => 'Champion of women rising in business, leadership, and Kingdom calling across the marketplace.', 'image_asset' => 'leader-emi-adelakun'],
          ],
          'gallery' => [
            ['id' => 'g2', 'title' => 'Community Care', 'image_asset' => 'gallery-community-selfie'],
            ['id' => 'g4', 'title' => 'Fellowship Event', 'image_asset' => 'gallery-fellowship-event'],
            ['id' => 'g7', 'title' => 'Tribe Gathering', 'image_asset' => 'gallery-tribe-gathering'],
          ],
          'events' => [],
          'testimonials' => [
            ['name' => 'Adaeze O.', 'role' => 'Banking Executive', 'country' => 'Nigeria', 'quote' => 'When I lost my father during a critical merger, Care Ministry did not send a message — they showed up. That presence saved my leadership.'],
          ],
          'scripture' => ['reference' => 'Galatians 6:2', 'text' => 'Bear one another\'s burdens, and so fulfill the law of Christ.'],
          'how_to_join' => ['Apply for membership and express interest in Care Ministry.', 'Complete pastoral care training and confidentiality covenant.', 'Shadow an experienced care minister for two sessions.', 'Begin serving in care calls, circles, or crisis response teams.'],
          'contact_email' => 'info@marketplaceministers.net',
          'contact_message' => 'Confidential pastoral care requests are handled with the utmost discretion.',
          'related_ministry_slugs' => ['prayer-ministry', 'faith-and-works', 'outreach'],
        ],
        'sort_order' => 2,
      ],
      [
        'name' => 'Faith & Works Clinic',
        'slug' => 'faith-and-works',
        'icon' => 'clinic',
        'tagline' => 'Where vocation meets devotion.',
        'summary' => 'Equipping clinics that bridge biblical conviction and excellence in professional practice.',
        'about' => 'The Faith & Works Clinic is a teaching environment for marketplace ministers ready to integrate their faith into how they design, lead, sell, build, and govern. Expect master classes, peer-learning circles, and case studies drawn from real boardrooms.',
        'purposes' => [
          ['title' => 'Integrate Faith & Excellence', 'description' => 'Translate biblical principles into operating practice.'],
          ['title' => 'Equip Practitioners', 'description' => 'Sharpen leaders through clinics, labs, and master classes.'],
          ['title' => 'Cultivate Wisdom', 'description' => 'Surface real-world Kingdom case studies.'],
        ],
        'content' => [
          'image_asset' => 'event-masterclass',
          'responsibilities' => ['Curate quarterly clinic curricula', 'Facilitate peer-learning cohorts', 'Develop case studies and playbooks', 'Mentor emerging marketplace leaders'],
          'who_can_join' => 'Marketplace professionals committed to lifelong learning and Kingdom application.',
          'faqs' => [
            ['question' => 'Is there a fee?', 'answer' => 'Clinics are subsidised for members. Non-members pay a modest contribution.'],
            ['question' => 'Are sessions recorded?', 'answer' => 'Yes, members access the library on demand.'],
          ],
          'mission' => 'To equip marketplace professionals to integrate biblical conviction with world-class excellence in practice.',
          'vision' => 'Boardrooms, studios, and clinics where faith and works are inseparable — excellence as worship.',
          'purpose' => 'Faith & Works Clinic bridges Sunday conviction and Monday execution — forming leaders who integrate scripture with professional mastery.',
          'what_we_do' => 'We deliver master classes, peer-learning cohorts, Kingdom case studies, and mentorship labs that translate biblical wisdom into boardroom, studio, and operating practice.',
          'focus_areas' => [
            ['title' => 'Executive Formation', 'description' => 'Boardroom intensives for C-suite and senior leaders integrating faith and strategy.'],
            ['title' => 'Peer Learning', 'description' => 'Cohort-based clinics where professionals sharpen one another with Kingdom case studies.'],
            ['title' => 'Applied Wisdom', 'description' => 'Playbooks and labs that turn biblical principles into operating practice.'],
          ],
          'programs' => [
            'weekly' => ['Faith & Works study circles', 'Peer accountability groups', 'Scripture & strategy devotionals'],
            'monthly' => ['Executive masterclass sessions', 'Industry clinic labs', 'Mentorship roundtables'],
            'annual' => ['London Executive Leadership Masterclass', 'Kingdom Case Study Symposium', 'Professional excellence certification track'],
          ],
          'leaders' => [
            ['name' => 'Yemi Akins', 'role' => 'Council Member · Executive Mentor', 'location' => 'London, United Kingdom', 'bio' => 'Seasoned executive coaching Kingdom leaders in C-suites and boardrooms around the world.', 'image_asset' => 'leader-yemi-akins'],
            ['name' => 'Jonathan Oraka', 'role' => 'Director · Ministries', 'location' => 'Abuja, Nigeria', 'bio' => 'Leading the deployment of the Tribe\'s ministry arms across regions and professional verticals.', 'image_asset' => 'leader-jonathan-oraka'],
          ],
          'gallery' => [
            ['id' => 'g3', 'title' => 'Executive Masterclass', 'image_asset' => 'gallery-fellowship-event'],
            ['id' => 'g5', 'title' => 'Professional Formation', 'image_asset' => 'gallery-tribe-gathering'],
            ['id' => 'g6', 'title' => 'Boardroom Prayer', 'image_asset' => 'gallery-prayer-gathering'],
          ],
          'events' => [
            ['slug' => 'executive-masterclass', 'title' => 'Executive Leadership Masterclass', 'date' => '08 FEB 2027', 'location' => 'London, UK', 'summary' => 'An intimate boardroom-style intensive for C-suite ministers in the marketplace.', 'image_asset' => 'event-masterclass'],
          ],
          'testimonials' => [
            ['name' => 'James W.', 'role' => 'Tech Founder', 'country' => 'United Kingdom', 'quote' => 'The masterclass reframed how I think about product ethics, team culture, and profit. Faith & Works is not theory — it is operating practice.'],
          ],
          'scripture' => ['reference' => 'James 2:17', 'text' => 'Faith by itself, if it does not have works, is dead.'],
          'how_to_join' => ['Join The Tribe and select Faith & Works Clinic as your ministry.', 'Attend orientation and choose a cohort track aligned with your industry.', 'Participate in monthly clinics and peer-learning assignments.', 'Optional: apply for mentorship or facilitator development.'],
          'contact_email' => 'info@marketplaceministers.net',
          'contact_message' => 'Inquire about clinics, cohorts, and executive formation programmes.',
          'related_ministry_slugs' => ['forerunners', 'kingdom-funders', 'prayer-ministry'],
        ],
        'sort_order' => 3,
      ],
      [
        'name' => 'Kingdom Funders',
        'slug' => 'kingdom-funders',
        'icon' => 'funders',
        'tagline' => 'Capital with conviction.',
        'summary' => 'A community of givers, investors, and builders deploying resources for Kingdom impact.',
        'about' => 'Kingdom Funders convenes high-capacity givers and investors who steward wealth as ministry. We co-fund missions, businesses, and movements that advance the gospel and renew the marketplace.',
        'purposes' => [
          ['title' => 'Steward with Strategy', 'description' => 'Deploy resources where they produce eternal and measurable yield.'],
          ['title' => 'Co-Fund Movements', 'description' => 'Pool capital to back Kingdom-aligned ventures and missions.'],
          ['title' => 'Mentor Generosity', 'description' => 'Form the next generation of generous, wise stewards.'],
        ],
        'content' => [
          'image_asset' => 'event-summit',
          'responsibilities' => ['Evaluate funding opportunities', 'Host quarterly stewardship roundtables', 'Mentor emerging philanthropists', 'Publish stewardship case studies'],
          'who_can_join' => 'Givers, investors, foundation leaders, and entrepreneurs called to fund the Kingdom.',
          'faqs' => [
            ['question' => 'Is participation confidential?', 'answer' => 'Yes. Membership and giving are held in the strictest confidence.'],
            ['question' => 'Do you vet opportunities?', 'answer' => 'All opportunities go through a multi-stage discernment and due diligence process.'],
          ],
          'mission' => 'To mobilise Kingdom capital and strategic generosity toward movements that advance the gospel and renew the marketplace.',
          'vision' => 'A generation of wise stewards deploying wealth with conviction, strategy, and eternal impact.',
          'purpose' => 'Kingdom Funders convenes givers, investors, and builders who treat capital as ministry — deploying resources with discernment, strategy, and eternal yield.',
          'what_we_do' => 'We evaluate Kingdom-aligned ventures, host stewardship roundtables, mentor emerging philanthropists, and co-fund missions, businesses, and movements that advance the gospel.',
          'focus_areas' => [
            ['title' => 'Strategic Stewardship', 'description' => 'Due diligence and discernment frameworks for Kingdom-aligned investments.'],
            ['title' => 'Co-Funding', 'description' => 'Pooled capital for missions, ventures, and movements with measurable Kingdom impact.'],
            ['title' => 'Generosity Formation', 'description' => 'Mentoring the next generation of wise, generous marketplace stewards.'],
          ],
          'programs' => [
            'weekly' => ['Stewardship prayer & discernment calls', 'Deal flow review sessions (members)', 'Mentorship office hours'],
            'monthly' => ['Stewardship roundtables', 'Kingdom venture showcases', 'Philanthropy master sessions'],
            'annual' => ['Global Marketplace Summit — Funders Track', 'Annual stewardship report & gathering', 'Emerging philanthropist cohort'],
          ],
          'leaders' => [
            ['name' => 'Terna Yahemba', 'role' => 'Director · Strategy & Partnerships', 'location' => 'Lagos, Nigeria', 'bio' => 'Architect of strategic partnerships connecting marketplace ministers to nation-building platforms.', 'image_asset' => 'leader-terna-yahemba'],
          ],
          'gallery' => [
            ['id' => 'g4', 'title' => 'Stewardship Roundtable', 'image_asset' => 'gallery-tribe-gathering'],
            ['id' => 'g6', 'title' => 'Summit Track', 'image_asset' => 'gallery-fellowship-event'],
            ['id' => 'g7', 'title' => 'Funding Conversations', 'image_asset' => 'gallery-community-selfie'],
          ],
          'events' => [
            ['slug' => 'global-summit', 'title' => 'Global Marketplace Summit', 'date' => '14 — 16 NOV 2026', 'location' => 'Lagos, Nigeria', 'summary' => 'Three days of teaching, deal-making, and prayer with kingdom executives from 40+ nations.', 'image_asset' => 'event-summit'],
          ],
          'testimonials' => [
            ['name' => 'Adaeze O.', 'role' => 'Banking Executive', 'country' => 'Nigeria', 'quote' => 'Kingdom Funders gave me a framework for giving that is both spiritual and strategic. My capital now carries conviction, not just charity.'],
          ],
          'scripture' => ['reference' => 'Luke 16:10', 'text' => 'He who is faithful in what is least is faithful also in much.'],
          'how_to_join' => ['Apply for membership and indicate Kingdom Funders interest.', 'Complete stewardship orientation and confidentiality agreement.', 'Attend an introductory roundtable and mentorship pairing.', 'Engage in deal flow review, giving, or mentor track.'],
          'contact_email' => 'info@marketplaceministers.net',
          'contact_message' => 'Connect with Kingdom Funders for roundtables, due diligence, and giving opportunities.',
          'related_ministry_slugs' => ['faith-and-works', 'outreach', 'forerunners'],
        ],
        'sort_order' => 4,
      ],
      [
        'name' => 'Forerunners',
        'slug' => 'forerunners',
        'icon' => 'forerunners',
        'tagline' => 'Pioneers preparing the way.',
        'summary' => 'A young leaders pipeline — emerging marketplace ministers being formed for global influence.',
        'about' => 'Forerunners is the discipleship pipeline for emerging professionals — students, early-career leaders, and rising entrepreneurs. We form them in character, calling, and competence so they can run when their time comes.',
        'purposes' => [
          ['title' => 'Form Character', 'description' => 'Anchor young leaders in biblical conviction before public influence.'],
          ['title' => 'Develop Calling', 'description' => 'Help them discover the marketplace assignment God has placed on their life.'],
          ['title' => 'Build Competence', 'description' => 'Stretch them through real assignments, mentorship, and exposure.'],
        ],
        'content' => [
          'image_asset' => 'about-movement',
          'responsibilities' => ['Run the annual Forerunners cohort', 'Pair members with senior mentors', 'Host exposure trips and shadowing', 'Convene quarterly formation labs'],
          'who_can_join' => 'Students and early-career professionals (18–32) with a clear sense of calling.',
          'faqs' => [
            ['question' => 'Is there an application?', 'answer' => 'Yes — a written application followed by an interview.'],
            ['question' => 'How long is the cohort?', 'answer' => 'Twelve months, including residencies and assignments.'],
          ],
          'mission' => 'To form emerging professionals in character, calling, and competence for global marketplace influence.',
          'vision' => 'Young leaders running ahead of their generation — prepared, rooted, and sent.',
          'purpose' => 'Forerunners is the discipleship pipeline for emerging marketplace ministers — forming character, calling, and competence before public influence arrives.',
          'what_we_do' => 'We run annual cohorts, mentor pairings, exposure trips, formation labs, and real assignments that stretch young leaders toward their marketplace assignment.',
          'focus_areas' => [
            ['title' => 'Character Formation', 'description' => 'Biblical conviction and spiritual discipline before platform and promotion.'],
            ['title' => 'Calling Discovery', 'description' => 'Guided discernment of marketplace assignment and sphere of influence.'],
            ['title' => 'Competence Building', 'description' => 'Mentorship, shadowing, and assignments that build real-world excellence.'],
          ],
          'programs' => [
            'weekly' => ['Cohort devotionals & teaching', 'Mentor check-ins', 'Assignment reviews'],
            'monthly' => ['Formation labs', 'Exposure sessions with senior leaders', 'Peer presentation circles'],
            'annual' => ['12-month Forerunners cohort', 'Exposure trips & shadowing residencies', 'Graduation & commissioning service'],
          ],
          'leaders' => [
            ['name' => 'Jesse Jangfa', 'role' => 'Director · Leadership Academy', 'location' => 'Jos, Nigeria', 'bio' => 'Curating the curriculum that forms the next generation of biblically-rooted marketplace leaders.', 'image_asset' => 'leader-jesse-jangfa'],
          ],
          'gallery' => [
            ['id' => 'g5', 'title' => 'Formation Lab', 'image_asset' => 'gallery-tribe-gathering'],
            ['id' => 'g7', 'title' => 'Mentor Gathering', 'image_asset' => 'gallery-community-selfie'],
            ['id' => 'g8', 'title' => 'Cohort Moment', 'image_asset' => 'gallery-fellowship-event'],
          ],
          'events' => [
            ['slug' => 'executive-masterclass', 'title' => 'Executive Leadership Masterclass', 'date' => '08 FEB 2027', 'location' => 'London, UK', 'summary' => 'An intimate boardroom-style intensive for C-suite ministers in the marketplace.', 'image_asset' => 'event-masterclass'],
          ],
          'testimonials' => [
            ['name' => 'Lerato M.', 'role' => 'Policy Advisor', 'country' => 'South Africa', 'quote' => 'Forerunners gave me mentors before I had a title. I was formed in private so I could lead in public with integrity.'],
          ],
          'scripture' => ['reference' => '1 Timothy 4:12', 'text' => 'Let no one despise your youth, but be an example to the believers.'],
          'how_to_join' => ['Apply for the annual Forerunners cohort (ages 18–32).', 'Complete written application and leadership interview.', 'Attend orientation and mentor matching weekend.', 'Commit to the 12-month formation track with assignments.'],
          'contact_email' => 'info@marketplaceministers.net',
          'contact_message' => 'Apply for the Forerunners cohort or mentor an emerging leader.',
          'related_ministry_slugs' => ['faith-and-works', 'outreach', 'prayer-ministry'],
        ],
        'sort_order' => 5,
      ],
      [
        'name' => 'Outreach',
        'slug' => 'outreach',
        'icon' => 'outreach',
        'tagline' => 'The gospel goes where we go.',
        'summary' => 'Mission, mercy, and movement-building that takes the Tribe beyond its own walls.',
        'about' => 'Outreach is the missional arm of the Tribe — taking the gospel and Kingdom care into workplaces, communities, prisons, schools, and unreached spheres. We mobilise short-term missions, mercy projects, and city engagements.',
        'purposes' => [
          ['title' => 'Take Ground', 'description' => 'Move the gospel into unreached spheres of marketplace influence.'],
          ['title' => 'Show Mercy', 'description' => 'Serve the vulnerable through mercy and justice initiatives.'],
          ['title' => 'Mobilise Members', 'description' => 'Send marketplace ministers into structured outreach assignments.'],
        ],
        'content' => [
          'image_asset' => 'hero-summit',
          'responsibilities' => ['Plan and execute outreach campaigns', 'Mobilise short-term mission teams', 'Partner with local communities', 'Report outcomes and learnings'],
          'who_can_join' => 'Members ready to serve actively, give time, and represent Christ in public spaces.',
          'faqs' => [
            ['question' => 'Do I need to travel?', 'answer' => 'Some outreaches are local; others are regional or international.'],
            ['question' => 'Are costs covered?', 'answer' => 'Each campaign has its own funding model — some are sponsored, some self-funded.'],
          ],
          'mission' => 'To take the gospel and tangible Kingdom care into workplaces, communities, and unreached spheres.',
          'vision' => 'Marketplace ministers as missionaries — every city touched, every sphere reached.',
          'purpose' => 'Outreach mobilises marketplace ministers beyond the walls of the Tribe — carrying the gospel and mercy into cities, campuses, prisons, and unreached spheres.',
          'what_we_do' => 'We plan and execute outreach campaigns, mobilise short-term mission teams, partner with local communities, and report outcomes that inform future Kingdom initiatives.',
          'focus_areas' => [
            ['title' => 'City Engagement', 'description' => 'Structured outreaches in workplaces, communities, and public spaces.'],
            ['title' => 'Mercy Initiatives', 'description' => 'Tangible care for the vulnerable through justice and compassion projects.'],
            ['title' => 'Mission Mobilisation', 'description' => 'Short-term teams sent regionally and internationally with clear mandate.'],
          ],
          'programs' => [
            'weekly' => ['Outreach prayer & planning cells', 'Local serve teams in member cities', 'Mission team preparation'],
            'monthly' => ['City engagement campaigns', 'Mercy project deployments', 'Testimony & debrief gatherings'],
            'annual' => ['Global mission week', 'Marketplace outreach summit', 'Cross-chapter mercy initiative'],
          ],
          'leaders' => [
            ['name' => 'Stephen Nyaega', 'role' => 'Regional Lead · East Africa', 'location' => 'Nairobi, Kenya', 'bio' => 'Convening executives and entrepreneurs across East Africa around shared Kingdom mandates.', 'image_asset' => 'leader-stephen-nyaega'],
            ['name' => 'Lily Mahlo', 'role' => 'Regional Lead · Southern Africa', 'location' => 'Johannesburg, South Africa', 'bio' => 'Mobilising young professionals across Southern Africa for Kingdom impact in business and policy.', 'image_asset' => 'leader-lily-mahlo'],
          ],
          'gallery' => [
            ['id' => 'g6', 'title' => 'Outreach Field Moment', 'image_asset' => 'gallery-community-selfie'],
            ['id' => 'g8', 'title' => 'Community Gathering', 'image_asset' => 'gallery-tribe-gathering'],
            ['id' => 'g9', 'title' => 'Mission Team', 'image_asset' => 'gallery-fellowship-event'],
          ],
          'events' => [
            ['slug' => 'global-summit', 'title' => 'Global Marketplace Summit', 'date' => '14 — 16 NOV 2026', 'location' => 'Lagos, Nigeria', 'summary' => 'Three days of teaching, deal-making, and prayer with kingdom executives from 40+ nations.', 'image_asset' => 'event-summit'],
          ],
          'testimonials' => [
            ['name' => 'Daniel K.', 'role' => 'Healthcare Leader', 'country' => 'Kenya', 'quote' => 'Outreach took our team from the hospital to the community. We saw patients as souls, not cases — and our city noticed.'],
          ],
          'scripture' => ['reference' => 'Matthew 28:19', 'text' => 'Go therefore and make disciples of all nations.'],
          'how_to_join' => ['Join The Tribe and select Outreach as your ministry assignment.', 'Attend outreach orientation and safety training.', 'Join a local serve team or apply for a mission deployment.', 'Participate in campaigns and submit field reports.'],
          'contact_email' => 'info@marketplaceministers.net',
          'contact_message' => 'Volunteer for outreach campaigns or propose a mercy initiative in your city.',
          'related_ministry_slugs' => ['prayer-ministry', 'care-ministry', 'kingdom-funders'],
        ],
        'sort_order' => 6,
      ],
    ];

    CmsMinistry::query()
      ->whereIn('slug', [
        'marketplace-leadership',
        'business-enterprise',
        'counseling-care',
        'prayer-intercession',
        'media-communications',
        'training-development',
      ])
      ->update(['is_active' => false]);

    foreach ($ministries as $ministry) {
      CmsMinistry::query()->updateOrCreate(
        ['slug' => $ministry['slug']],
        array_merge($ministry, ['is_active' => true]),
      );
    }
  }

  private function seedLeadership(): void
  {
    $profiles = [
      ['name' => 'Damola Adelakun', 'slug' => 'damola-adelakun', 'role' => 'Convener & Lead Visionary', 'location' => 'Charlotte, United States', 'category' => 'team', 'bio' => 'Entrepreneur and minister raising kingdom professionals to influence the marketplace with biblical values.', 'sort_order' => 1],
      ['name' => 'Emi Adelakun', 'slug' => 'emi-adelakun', 'role' => 'Co-Convener · Women in Marketplace', 'location' => 'Charlotte, United States', 'category' => 'team', 'bio' => 'Champion of women rising in business, leadership, and Kingdom calling across the marketplace.', 'sort_order' => 2],
      ['name' => 'Yemi Akins', 'slug' => 'yemi-akins', 'role' => 'Council Member · Executive Mentor', 'location' => 'London, United Kingdom', 'category' => 'team', 'bio' => 'Seasoned executive coaching Kingdom leaders in C-suites and boardrooms around the world.', 'sort_order' => 3],
      ['name' => 'Jonathan Oraka', 'slug' => 'jonathan-oraka', 'role' => 'Director · Ministries', 'location' => 'Abuja, Nigeria', 'category' => 'advisors', 'bio' => 'Leading the deployment of the Tribe\'s ministry arms across regions and professional verticals.', 'sort_order' => 4],
      ['name' => 'Terna Yahemba', 'slug' => 'terna-yahemba', 'role' => 'Director · Strategy & Partnerships', 'location' => 'Lagos, Nigeria', 'category' => 'advisors', 'bio' => 'Architect of strategic partnerships connecting marketplace ministers to nation-building platforms.', 'sort_order' => 5],
      ['name' => 'Jesse Jangfa', 'slug' => 'jesse-jangfa', 'role' => 'Director · Leadership Academy', 'location' => 'Jos, Nigeria', 'category' => 'team', 'bio' => 'Curating the curriculum that forms the next generation of biblically-rooted marketplace leaders.', 'sort_order' => 6],
      ['name' => 'Lily Mahlo', 'slug' => 'lily-mahlo', 'role' => 'Regional Lead · Southern Africa', 'location' => 'Johannesburg, South Africa', 'category' => 'country', 'bio' => 'Mobilising young professionals across Southern Africa for Kingdom impact in business and policy.', 'sort_order' => 7],
      ['name' => 'Nhlonipho Lethabo', 'slug' => 'nhlonipho-lethabo', 'role' => 'Regional Lead · Cultural Engagement', 'location' => 'Pretoria, South Africa', 'category' => 'country', 'bio' => 'Bridging faith, culture, and the marketplace through community-rooted leadership formation.', 'sort_order' => 8],
      ['name' => 'Stephen Nyaega', 'slug' => 'stephen-nyaega', 'role' => 'Regional Lead · East Africa', 'location' => 'Nairobi, Kenya', 'category' => 'country', 'bio' => 'Convening executives and entrepreneurs across East Africa around shared Kingdom mandates.', 'sort_order' => 9],
    ];

    foreach ($profiles as $profile) {
      CmsLeadershipProfile::query()->updateOrCreate(
        ['slug' => $profile['slug']],
        array_merge($profile, ['is_active' => true]),
      );
    }
  }

  private function seedTestimonials(): void
  {
    $testimonials = [
      [
        'author_name' => 'Adaeze O.',
        'author_title' => 'Banking Executive',
        'author_location' => 'Nigeria',
        'quote' => 'The Tribe gave language to what God had been forming in me for years. I now lead my division as a minister — not just a manager.',
        'sort_order' => 1,
      ],
      [
        'author_name' => 'James W.',
        'author_title' => 'Tech Founder',
        'author_location' => 'United Kingdom',
        'quote' => 'I came in looking for community. I left with conviction, mentors, and a redefined mandate for my company.',
        'sort_order' => 2,
      ],
      [
        'author_name' => 'Lerato M.',
        'author_title' => 'Policy Advisor',
        'author_location' => 'South Africa',
        'quote' => 'Marketplace Ministers helped me see governance as ministry. I serve my nation differently because of this tribe.',
        'sort_order' => 3,
      ],
      [
        'author_name' => 'Daniel K.',
        'author_title' => 'Healthcare Leader',
        'author_location' => 'Kenya',
        'quote' => 'There is no other space like this — where prayer, scripture, and serious professional formation meet at this level.',
        'sort_order' => 4,
      ],
    ];

    foreach ($testimonials as $testimonial) {
      CmsTestimonial::query()->updateOrCreate(
        ['author_name' => $testimonial['author_name']],
        array_merge($testimonial, ['is_active' => true, 'is_featured' => true]),
      );
    }

    CmsTestimonial::query()
      ->whereIn('author_name', ['Grace A.', 'David K.'])
      ->update(['is_active' => false]);
  }

  private function seedPages(): void
  {
    $pages = [
      [
        'title' => 'About', 'slug' => 'about', 'hero_title' => 'A global movement raising Kingdom professionals.',
        'hero_subtitle' => 'An international community equipping leaders to carry the gospel into every sphere of marketplace influence.',
        'blocks' => [
          ['type' => 'rich_text', 'eyebrow' => 'Who We Are', 'title' => 'A council of marketplace ministers — not a typical ministry.', 'content' => "Marketplace Ministers is a global convergence of executives, entrepreneurs, intercessors, and ministers who carry their callings into business, governance, media, technology, and culture.\n\nWe exist because the marketplace is not secular ground — it is mission territory. We serve professionals who refuse to leave their faith at the boardroom door, and who long for community, formation, and accountability among peers carrying the same fire.\n\nMembers emerge clearer in calling, sharper in competence, more anchored in Christ, and more present in the spheres God has assigned them."],
          ['type' => 'features', 'eyebrow' => 'Vision & Mission', 'title' => 'Discover, develop, and deploy Kingdom professionals globally.', 'items' => [
            ['icon' => 'sparkles', 'title' => 'Vision', 'text' => 'A million marketplace ministers shaping every sphere of society with biblical conviction and spiritual authority.'],
            ['icon' => 'crown', 'title' => 'Mission', 'text' => 'To mobilise, equip, and convene marketplace ministers across nations through teaching, mentorship, prayer, and accountable community.'],
          ]],
          ['type' => 'core_values', 'items' => [
            ['icon' => 'spirit', 'title' => 'The Holy Spirit', 'description' => 'We live, lead, and labour in continual partnership with the Spirit of God.', 'scripture' => 'Zechariah 4:6'],
            ['icon' => 'love', 'title' => 'Love', 'description' => 'The chief virtue that authenticates every word we speak and work we do.', 'scripture' => '1 Corinthians 13:1-3'],
            ['icon' => 'peace', 'title' => 'Peace', 'description' => 'We carry the peace of Christ into every meeting, market, and crisis.', 'scripture' => 'Colossians 3:15'],
            ['icon' => 'integrity', 'title' => 'Integrity', 'description' => 'Whole, undivided, and consistent — in private rooms and on public stages.', 'scripture' => 'Proverbs 11:3'],
            ['icon' => 'righteousness', 'title' => 'Practice of Righteousness', 'description' => 'Right standing with God expressed in right dealing with people.', 'scripture' => 'Micah 6:8'],
            ['icon' => 'prayer', 'title' => 'Prayer', 'description' => 'A non-negotiable rhythm — the marketplace is shifted on our knees.', 'scripture' => '1 Thessalonians 5:17'],
            ['icon' => 'diligence', 'title' => 'Diligence', 'description' => 'Excellence sustained over time. We outwork mediocrity in His name.', 'scripture' => 'Proverbs 22:29'],
            ['icon' => 'sacrifice', 'title' => 'Sacrificial Living', 'description' => 'We hold our lives, time, and resources loosely for Kingdom advance.', 'scripture' => 'Romans 12:1'],
            ['icon' => 'fasting', 'title' => 'Fasting', 'description' => 'A disciplined emptying that creates room for divine fullness.', 'scripture' => 'Isaiah 58:6'],
          ]],
          ['type' => 'journey', 'eyebrow' => 'Our Journey', 'title' => 'From a small altar to a global tribe.', 'subtitle' => 'A movement does not arrive overnight. Here is how we got here — and where we are headed.', 'items' => [
            ['era' => 'Past', 'year' => '2014', 'title' => 'The First Altar', 'description' => 'A small gathering of professionals in Lagos begins meeting weekly to seek God for the marketplace.'],
            ['era' => 'Past', 'year' => '2017', 'title' => 'The Tribe is Named', 'description' => 'The conviction crystallises: we are a tribe of marketplace ministers, not merely a network.'],
            ['era' => 'Past', 'year' => '2020', 'title' => 'Global Pivot', 'description' => 'The movement goes digital, reaching members across four continents during the pandemic.'],
            ['era' => 'Present', 'year' => '2024', 'title' => 'Council Formation', 'description' => 'A multi-national leadership council is consecrated to shepherd the Tribe globally.'],
            ['era' => 'Present', 'year' => 'Now', 'title' => 'Six Ministries Active', 'description' => 'Prayer, Care, Faith & Works, Kingdom Funders, Forerunners, and Outreach are operating in concert.'],
            ['era' => 'Future', 'year' => '2026+', 'title' => 'Nations Activation', 'description' => 'Plant Tribe expressions across 25 nations and launch the Marketplace Leadership Academy.'],
            ['era' => 'Future', 'year' => 'Vision', 'title' => 'One Million Ministers', 'description' => 'Mobilise a million marketplace ministers shaping every sphere of society with biblical influence.'],
          ]],
          ['type' => 'features', 'eyebrow' => 'Why Marketplace Ministers?', 'title' => 'A formation pathway for lasting influence.', 'items' => [
            ['icon' => 'sparkles', 'title' => 'Discover', 'text' => 'Help every member find the marketplace assignment God has placed on their life.'],
            ['icon' => 'award', 'title' => 'Develop', 'text' => 'Form character, calling, and competence through teaching and mentorship.'],
            ['icon' => 'globe', 'title' => 'Deploy', 'text' => 'Send members into spheres of influence with structure and accountability.'],
            ['icon' => 'crown', 'title' => 'Lead', 'text' => 'Raise leaders who shepherd well in boardrooms, ministries, and homes.'],
            ['icon' => 'sparkles', 'title' => 'Influence', 'text' => 'Shape culture, policy, and enterprise from a posture of biblical conviction.'],
            ['icon' => 'users', 'title' => 'Transform', 'text' => 'Watch cities and industries reshaped by the cumulative weight of Kingdom presence.'],
          ]],
          ['type' => 'cta', 'eyebrow' => 'Take The Next Step', 'title' => 'Ready to become a Marketplace Minister?', 'subtitle' => 'Step into the formation pathway and join a global community of Kingdom professionals.', 'primary' => ['to' => '/join', 'label' => 'Join The Tribe'], 'secondary' => ['to' => '/leadership', 'label' => 'Meet The Leadership']],
        ],
      ],
      [
        'title' => 'Counseling', 'slug' => 'counseling', 'hero_title' => 'Find hope, wisdom, and biblical guidance.',
        'hero_subtitle' => 'Confidential, pastoral, and Spirit-led counsel for the unique pressures of marketplace life.',
        'blocks' => [
          ['type' => 'features', 'eyebrow' => 'Areas We Serve', 'title' => 'Counsel for every crossroad.', 'items' => [
            ['icon' => 'heart-handshake', 'title' => 'Marriage', 'text' => 'Biblical guidance for covenant, communication, healing, and shared purpose.'],
            ['icon' => 'users', 'title' => 'Relationships', 'text' => 'Wisdom for friendships, conflict, boundaries, and reconciliation.'],
            ['icon' => 'briefcase', 'title' => 'Business', 'text' => 'Counsel for pressure, ethics, decisions, partnerships, and transitions.'],
            ['icon' => 'sparkles', 'title' => 'Purpose', 'text' => 'Discernment for calling, identity, and the next faithful step.'],
            ['icon' => 'crown', 'title' => 'Leadership', 'text' => 'Support for leaders carrying people, decisions, and public responsibility.'],
            ['icon' => 'flame', 'title' => 'Prayer', 'text' => 'Spiritual covering and prayerful counsel for complex seasons.'],
            ['icon' => 'heart', 'title' => 'Emotional Support', 'text' => 'Pastoral care for grief, burnout, anxiety, and discouragement.'],
            ['icon' => 'users', 'title' => 'Family', 'text' => 'Guidance for family pressures, parenting, and intergenerational care.'],
            ['icon' => 'graduation-cap', 'title' => 'Career', 'text' => 'Counsel for work transitions, growth, and marketplace assignment.'],
          ]],
          ['type' => 'rich_text', 'eyebrow' => 'Request Counseling', 'title' => 'Submit a confidential request.', 'subtitle' => 'A member of our care team will reach out to schedule your session within 48 hours.', 'content' => 'Every request is handled with discretion, prayer, and pastoral care. Where clinical support is needed, our team can help recommend professional support.'],
          ['type' => 'cta', 'eyebrow' => 'Care Ministry', 'title' => "You don't have to walk alone.", 'subtitle' => 'The Care Ministry is here for you in every season.', 'primary' => ['to' => '/contact', 'label' => 'Contact Care Team']],
        ],
      ],
      [
        'title' => 'Partner', 'slug' => 'partner', 'hero_title' => 'Partner with the vision.',
        'hero_subtitle' => 'The mandate is too big for any one person, business, or institution. We need partners who will stand with us.',
        'blocks' => [
          ['type' => 'partner_types', 'eyebrow' => 'Partnership Tiers', 'title' => 'Choose the seat that fits you.', 'items' => [
            ['icon' => 'user', 'title' => 'Individual', 'text' => 'Personal partnership with the work and ministers of the Tribe.'],
            ['icon' => 'briefcase', 'title' => 'Business', 'text' => 'Companies underwriting Tribe gatherings, ministries, and training cohorts.'],
            ['icon' => 'church', 'title' => 'Church', 'text' => 'Local churches commissioning members into the marketplace mandate.'],
            ['icon' => 'building', 'title' => 'Organization', 'text' => 'Foundations and institutions aligning with Kingdom enterprise.'],
            ['icon' => 'globe', 'title' => 'Mission Partner', 'text' => 'Strategic partners deploying the Tribe across new nations.'],
          ]],
          ['type' => 'impact_areas', 'eyebrow' => 'Your Impact', 'title' => 'What partnership unlocks.', 'items' => [
            ['icon' => 'award', 'title' => 'Training Cohorts', 'text' => 'Subsidise scholarships in the Forerunners cohort and Faith & Works Clinic.'],
            ['icon' => 'sprout', 'title' => 'Nation Planting', 'text' => 'Catalyse Tribe chapters in new nations and underserved regions.'],
            ['icon' => 'handshake', 'title' => 'Care & Counsel', 'text' => 'Sustain pastoral care, crisis support, and confidential counsel.'],
          ]],
          ['type' => 'rich_text', 'eyebrow' => 'Financial Stewardship', 'title' => 'Transparent stewardship for every gift.', 'content' => 'Every gift is stewarded with transparency. Quarterly impact reports are shared with all partners, and audited financials are available annually on request.'],
          ['type' => 'rich_text', 'eyebrow' => 'Partnership Application', 'title' => 'Start the conversation.', 'content' => 'Tell us how you want to stand with the Tribe. A partnerships director will review your inquiry and follow up with next steps.'],
          ['type' => 'cta', 'eyebrow' => 'Partner With Us', 'title' => 'Stand with us in prayer and giving.', 'subtitle' => 'Every chapter, gathering, and ministry is sustained by partners like you.', 'primary' => ['to' => '/donate', 'label' => 'Give Now']],
        ],
      ],
      [
        'title' => 'Contact', 'slug' => 'contact', 'hero_title' => 'Contact Us',
        'hero_subtitle' => 'Reach the Tribe — for partnership, media, prayer, or membership questions.',
        'blocks' => [],
      ],
      [
        'title' => 'Media', 'slug' => 'media', 'hero_title' => 'Grow through Kingdom resources.',
        'hero_subtitle' => 'Discover teachings, articles, videos, testimonies and resources designed to equip Marketplace Ministers worldwide.',
        'blocks' => [
          ['type' => 'media_hub', 'eyebrow' => 'Explore', 'title' => 'A growing library of formation content.', 'items' => [
            ['to' => '/blog', 'icon' => 'book-open', 'title' => 'Blog', 'text' => 'Long-form teachings, essays, and reflections from the council and contributors.', 'label' => 'Explore'],
            ['to' => '/vlog', 'icon' => 'play-circle', 'title' => 'Vlog', 'text' => 'Messages, masterclasses, and conversations on video — straight from the Tribe.', 'label' => 'Explore'],
            ['to' => '/gallery', 'icon' => 'image', 'title' => 'Gallery', 'text' => 'Curated images from gatherings, outreaches, prayer watches, and convergences.', 'label' => 'Explore'],
            ['to' => '/resources', 'icon' => 'library', 'title' => 'Resources', 'text' => 'Study guides, playbooks, books, audio, and video — downloadable formation tools.', 'label' => 'Explore'],
          ]],
          ['type' => 'cta', 'eyebrow' => 'Stay Connected', 'title' => 'Never miss a teaching.', 'subtitle' => 'Subscribe to the Tribe newsletter for new articles, videos, and resources.', 'primary' => ['to' => '/', 'label' => 'Subscribe to Newsletter', 'hash' => 'newsletter'], 'secondary' => ['to' => '/contact', 'label' => 'Contact Us']],
        ],
      ],
      [
        'title' => 'Prayer Watch', 'slug' => 'prayer-watch', 'hero_title' => 'Take prayer everywhere.',
        'hero_subtitle' => 'A 24/7 altar of intercession over the marketplace — designed for executives, entrepreneurs, and ministers on the move.',
        'blocks' => [
          ['type' => 'app_showcase', 'eyebrow' => 'The App', 'title' => 'A pocket altar built for marketplace life.', 'subtitle' => 'Prayer Watch keeps the rhythm of intercession alive across boardrooms, airports, and time zones.', 'content' => 'Download links can be updated from the CMS when the production app listings are ready.', 'items' => [
            ['label' => 'Download on the App Store', 'url' => '', 'image_asset' => 'prayer-watch-phone-1', 'alt' => 'Prayer Watch app dashboard'],
            ['label' => 'Get it on Google Play', 'url' => '', 'image_asset' => 'prayer-watch-phone-2', 'alt' => 'Prayer Watch app devotional'],
          ]],
          ['type' => 'features', 'eyebrow' => 'Features', 'title' => 'Designed for a life of prayer.', 'items' => [
            ['icon' => 'flame', 'title' => 'Daily Prayer', 'text' => 'A guided rhythm of prayer to ground every day in God\'s presence.'],
            ['icon' => 'bell', 'title' => 'Prayer Reminders', 'text' => 'Custom reminders that interrupt the noise of your day with prayer.'],
            ['icon' => 'book-open', 'title' => 'Kingdom Devotionals', 'text' => 'Short-form, marketplace-shaped meditations for executives on the go.'],
            ['icon' => 'users', 'title' => 'Community Engagement', 'text' => 'Pray with the Tribe — share requests, join watches, and stand together.'],
          ]],
          ['type' => 'faq', 'eyebrow' => 'FAQ', 'title' => 'Common questions.', 'items' => [
            ['question' => 'Is the app free?', 'answer' => 'Yes. Prayer Watch is free for the global Tribe community.'],
            ['question' => 'Do I need to be a member to use it?', 'answer' => 'No. Anyone can download, though members get additional community features.'],
            ['question' => 'What devices are supported?', 'answer' => 'iOS 14+ and Android 9+.'],
          ]],
          ['type' => 'cta', 'eyebrow' => 'Prayer Watch', 'title' => 'Download Prayer Watch today.', 'subtitle' => 'Make prayer the rhythm beneath everything you build.', 'primary' => ['to' => '/contact', 'label' => 'Request App Updates']],
        ],
      ],
      [
        'title' => 'Join', 'slug' => 'join', 'hero_title' => 'Begin your marketplace ministry journey.',
        'hero_subtitle' => 'Step into a global community of Kingdom professionals — formed in character, calling, and competence.',
        'blocks' => [
          ['type' => 'journey', 'eyebrow' => 'The Journey', 'title' => 'From application to active minister.', 'subtitle' => 'A clear pathway into the Tribe.', 'items' => [
            ['icon' => 'clipboard-check', 'title' => 'Membership Application'],
            ['icon' => 'file-check', 'title' => 'Application Review'],
            ['icon' => 'messages-square', 'title' => 'Leadership Interview'],
            ['icon' => 'briefcase', 'title' => 'Ministry Assignment'],
            ['icon' => 'globe', 'title' => 'Country Assignment'],
            ['icon' => 'graduation-cap', 'title' => 'Training'],
            ['icon' => 'sparkles', 'title' => 'Active Marketplace Minister'],
          ]],
        ],
      ],
      [
        'title' => 'Donate', 'slug' => 'donate', 'hero_title' => 'Advance God\'s Kingdom through generosity.',
        'hero_subtitle' => 'Give to a Tribe chapter in your country and fuel the global movement.',
        'blocks' => [
          ['type' => 'faq', 'eyebrow' => 'FAQ', 'title' => 'Common questions.', 'items' => [
            ['question' => 'Are donations tax-deductible?', 'answer' => 'Tax deductibility depends on your country. We provide receipts that comply with local rules.'],
            ['question' => 'How are funds used?', 'answer' => 'Funds support training, gatherings, leadership formation, prayer ministry, and chapter operations.'],
            ['question' => 'Can I designate my gift?', 'answer' => 'Yes. Select a donation purpose and optional ministry designation in the form.'],
          ]],
        ],
      ],
      [
        'title' => 'Blog', 'slug' => 'blog', 'hero_title' => 'Reflections from the marketplace.',
        'hero_subtitle' => 'Long-form teachings, essays, and conversations to form the inner life of marketplace ministers.',
        'blocks' => [],
      ],
      [
        'title' => 'Gallery', 'slug' => 'gallery', 'hero_title' => 'Moments from the journey.',
        'hero_subtitle' => 'A growing archive of the Tribe — gatherings, prayer watches, outreaches, and convergences across nations.',
        'blocks' => [],
      ],
      [
        'title' => 'Resources', 'slug' => 'resources', 'hero_title' => 'Formation tools for marketplace ministers.',
        'hero_subtitle' => 'Books, study guides, sermons, PDFs, audio, and video — curated to equip you for Kingdom influence.',
        'blocks' => [],
      ],
      [
        'title' => 'Vlog', 'slug' => 'vlog', 'hero_title' => 'Teachings on video.',
        'hero_subtitle' => 'Messages, masterclasses, and conversations from the Tribe.',
        'blocks' => [],
      ],
      [
        'title' => 'Leadership', 'slug' => 'leadership', 'hero_title' => 'The leaders shaping the movement.',
        'hero_subtitle' => 'A diverse council of executives, ministers, and marketplace builders carrying the mandate of the Tribe across nations.',
        'blocks' => [],
      ],
      [
        'title' => 'Ministries', 'slug' => 'ministries', 'hero_title' => 'One Tribe. Many spheres of influence.',
        'hero_subtitle' => 'Six ministry arms working in concert to discover, develop, and deploy marketplace ministers.',
        'blocks' => [],
      ],
      [
        'title' => 'Global Presence', 'slug' => 'global-presence', 'hero_title' => 'One Tribe across many nations.',
        'hero_subtitle' => 'Chapters of marketplace ministers gathering, training, and deploying across continents.',
        'blocks' => [
          [
            'type' => 'presence_stats',
            'eyebrow' => 'Impact',
            'title' => 'A tribe across nations.',
            'items' => [
              ['label' => 'Countries', 'value' => '14+'],
              ['label' => 'Cities', 'value' => '32+'],
              ['label' => 'Volunteers', 'value' => '20+'],
              ['label' => 'Mission Projects', 'value' => '48+'],
              ['label' => 'Lead Coordinators', 'value' => '10+'],
            ],
          ],
        ],
      ],
      [
        'title' => 'Testimonials', 'slug' => 'testimonials', 'hero_title' => 'Stories from the tribe.',
        'hero_subtitle' => 'Marketplace ministers sharing how conviction, community, and calling are reshaping their lives and work.',
        'blocks' => [
          ['type' => 'rich_text', 'eyebrow' => 'Testimonies', 'title' => 'Voices from across nations.', 'content' => "Approved testimonies are shared with care and pastoral review. Each story reflects a real journey of faith, formation, and marketplace influence.\n\nBrowse by category or submit your own testimony below. Every submission is reviewed before it appears publicly."],
          ['type' => 'rich_text', 'eyebrow' => 'Featured', 'title' => 'Highlighted stories.', 'content' => 'Featured testimonies are selected by the communications team to showcase the breadth of the Tribe — across industries, nations, and seasons of life. Check back regularly as new stories are approved.'],
        ],
      ],
      [
        'title' => 'Privacy Policy', 'slug' => 'privacy', 'hero_title' => 'Privacy Policy',
        'hero_subtitle' => 'How The Tribe of Marketplace Ministers collects, uses, and protects your information.',
        'blocks' => [
          ['type' => 'rich_text', 'eyebrow' => 'Legal', 'title' => 'Your privacy matters.', 'content' => "This Privacy Policy describes how The Tribe of Marketplace Ministers (\"we\", \"us\", or \"our\") collects, uses, and safeguards personal information when you visit our website, submit forms, donate, or engage with our ministries.\n\nInformation We Collect\nWe may collect information you provide directly — such as your name, email address, phone number, country, and testimony or application details — when you submit forms, register for events, or contact us.\n\nHow We Use Information\nWe use collected information to respond to inquiries, process donations, review testimonies and applications, send communications you have opted into, and improve our services.\n\nData Sharing\nWe do not sell personal information. We may share data with trusted service providers who assist with hosting, email delivery, payment processing, or pastoral care — subject to confidentiality obligations.\n\nYour Rights\nDepending on your jurisdiction, you may request access to, correction of, or deletion of your personal data. Contact us at info@marketplaceministers.net for privacy-related requests.\n\nUpdates\nWe may update this policy from time to time. Continued use of the site after changes constitutes acceptance of the revised policy.\n\nLast updated: July 2026."],
        ],
      ],
      [
        'title' => 'Terms of Use', 'slug' => 'terms', 'hero_title' => 'Terms of Use',
        'hero_subtitle' => 'The terms governing your use of The Tribe of Marketplace Ministers website and services.',
        'blocks' => [
          ['type' => 'rich_text', 'eyebrow' => 'Legal', 'title' => 'Terms and conditions.', 'content' => "By accessing or using The Tribe of Marketplace Ministers website (\"Site\"), you agree to these Terms of Use. If you do not agree, please do not use the Site.\n\nUse of the Site\nYou may use the Site for lawful purposes only. You agree not to misuse the Site, attempt unauthorized access, or interfere with its operation.\n\nContent\nMaterials on the Site — including teachings, articles, images, and testimonies — are provided for spiritual formation and informational purposes. Reproduction or redistribution without permission is prohibited unless otherwise noted.\n\nUser Submissions\nWhen you submit testimonies, applications, or other content, you grant us a non-exclusive license to review, edit for clarity, and publish approved content in accordance with our moderation policies.\n\nDonations\nDonations are voluntary and non-refundable except where required by applicable law. Designated gifts are applied in good faith toward stated purposes.\n\nDisclaimer\nThe Site is provided \"as is\" without warranties of any kind. We are not liable for indirect or consequential damages arising from use of the Site.\n\nGoverning Law\nThese terms are governed by applicable laws in the jurisdiction of our primary operations, without regard to conflict-of-law principles.\n\nContact\nQuestions about these terms may be directed to info@marketplaceministers.net.\n\nLast updated: July 2026."],
        ],
      ],
      [
        'title' => 'Newsletter', 'slug' => 'newsletter', 'hero_title' => 'Stay in the conversation',
        'hero_subtitle' => 'Monthly notes on faith, leadership, and the marketplace — delivered to your inbox.',
        'blocks' => [
          ['type' => 'rich_text', 'eyebrow' => 'Newsletter', 'title' => 'Formation for marketplace ministers.', 'content' => "Subscribe for curated teachings, event highlights, and resources from the Tribe. We send thoughtfully — typically once a month — with content shaped for executives, entrepreneurs, and ministers carrying Kingdom influence in the marketplace.\n\nYour email is handled with care. Unsubscribe anytime from any message."],
          ['type' => 'cta', 'eyebrow' => 'Subscribe', 'title' => 'Join the Tribe newsletter.', 'subtitle' => 'New articles, videos, and formation resources — straight to your inbox.', 'primary' => ['to' => '/', 'label' => 'Subscribe on Homepage', 'hash' => 'newsletter'], 'secondary' => ['to' => '/contact', 'label' => 'Contact Us']],
        ],
      ],
    ];

    foreach ($pages as $pageData) {
      $blocks = $pageData['blocks'];
      unset($pageData['blocks']);

      CmsPage::query()->updateOrCreate(
        ['slug' => $pageData['slug']],
        array_merge($pageData, ['status' => PageStatus::Published, 'published_at' => now()]),
      );

      CmsPageSection::query()->updateOrCreate(
        ['page_slug' => $pageData['slug'], 'section_key' => 'main'],
        [
          'section_type' => 'content',
          'title' => $pageData['title'],
          'content' => ['blocks' => $blocks],
          'is_active' => true,
          'sort_order' => 1,
        ],
      );
    }
  }

  private function seedCatalogImageMedia(string $assetKey, string $slug, string $title, CatalogItemType $type): CmsMedia
  {
    $assetSources = [
      'gallery-community-selfie' => base_path('../src/assets/gallery/gallery-community-selfie.webp'),
      'gallery-fellowship-event' => base_path('../src/assets/gallery/gallery-fellowship-event.webp'),
      'gallery-prayer-gathering' => base_path('../src/assets/gallery/gallery-prayer-gathering.webp'),
      'gallery-tribe-gathering' => base_path('../src/assets/gallery/gallery-tribe-gathering.webp'),
      'about-movement' => base_path('../src/assets/gallery/gallery-tribe-gathering.webp'),
      'event-masterclass' => base_path('../src/assets/gallery/gallery-fellowship-event.webp'),
      'event-prayer' => base_path('../src/assets/gallery/gallery-prayer-gathering.webp'),
      'event-summit' => base_path('../src/assets/gallery/gallery-tribe-gathering.webp'),
      'hero-summit' => base_path('../src/assets/gallery/gallery-tribe-gathering.webp'),
      'marketplace-professionals' => base_path('../src/assets/gallery/gallery-community-selfie.webp'),
    ];

    $source = $assetSources[$assetKey] ?? $assetSources['gallery-tribe-gathering'];
    $extension = is_file($source) ? pathinfo($source, PATHINFO_EXTENSION) : 'png';
    $path = "cms/catalog/seeded/{$type->value}/{$slug}.{$extension}";

    Storage::disk('public')->put(
      $path,
      is_file($source)
        ? (string) file_get_contents($source)
        : (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='),
    );

    return CmsMedia::query()->updateOrCreate(
      ['disk' => 'public', 'path' => $path],
      [
        'name' => $title,
        'file_name' => basename($path),
        'mime_type' => $extension === 'png' ? 'image/png' : 'image/webp',
        'size' => Storage::disk('public')->size($path),
        'alt_text' => $title,
        'title' => $title,
        'metadata' => ['entity' => 'catalog_item', 'type' => $type->value, 'seed_asset' => $assetKey],
      ],
    );
  }

  private function seedResourceFileMedia(string $slug, string $title, ?string $summary, ?string $body): CmsMedia
  {
    $path = "cms/catalog/seeded/resource-files/{$slug}.txt";
    Storage::disk('public')->put($path, implode("\n\n", array_filter([$title, $summary, $body])));

    return CmsMedia::query()->updateOrCreate(
      ['disk' => 'public', 'path' => $path],
      [
        'name' => $title,
        'file_name' => "{$slug}.txt",
        'mime_type' => 'text/plain',
        'size' => Storage::disk('public')->size($path),
        'alt_text' => $title,
        'title' => $title,
        'metadata' => ['entity' => 'catalog_item', 'type' => CatalogItemType::Resource->value, 'kind' => 'seed_file'],
      ],
    );
  }

  private function seedCatalog(): void
  {
    $blogs = [
      [
        'title' => 'Rebuilding the Altar in the Boardroom',
        'slug' => 'rebuilding-the-altar-in-the-boardroom',
        'category' => 'Marketplace Leadership',
        'summary' => 'Why the modern marketplace minister must re-learn the discipline of building altars before building empires.',
        'body' => 'The first instinct of every patriarch was to build an altar before building anything else. In our generation, marketplace leaders are being called to recover this rhythm.',
        'tags' => ['marketplace', 'leadership', 'prayer', 'faith'],
        'metadata' => ['author' => 'Damola Adelakun', 'reading_time' => '7 min read', 'reading_minutes' => 7, 'image_asset' => 'event-prayer', 'popular' => true, 'trending_score' => 98],
        'published_at' => '2026-06-12 10:00:00',
        'is_featured' => true,
      ],
      [
        'title' => 'Kingdom Economics 101',
        'slug' => 'kingdom-economics-101',
        'category' => 'Kingdom Entrepreneurship',
        'summary' => 'Money is a steward, not a sovereign. A primer on how the Kingdom rewires our relationship with capital.',
        'body' => 'In the Kingdom, capital is not the head. It is a servant of mission, stewardship, generosity, and eternal outcomes.',
        'tags' => ['economics', 'entrepreneurship', 'kingdom', 'mission'],
        'metadata' => ['author' => 'Terna Yahemba', 'reading_time' => '9 min read', 'reading_minutes' => 9, 'image_asset' => 'marketplace-professionals', 'editors_pick' => true, 'popular' => true, 'trending_score' => 92],
        'published_at' => '2026-05-28 10:00:00',
      ],
      [
        'title' => 'Praying for Your Industry',
        'slug' => 'praying-for-your-industry',
        'category' => 'Prayer',
        'summary' => 'A practical framework for interceding for the sector you have been called to influence.',
        'body' => 'Every sphere of the marketplace has spiritual architecture. Prayer teaches leaders to discern before they decide and to intercede before they intervene.',
        'tags' => ['prayer', 'industry', 'marketplace', 'leadership'],
        'metadata' => ['author' => 'Jonathan Oraka', 'reading_time' => '5 min read', 'reading_minutes' => 5, 'image_asset' => 'event-masterclass', 'trending_score' => 88],
        'published_at' => '2026-05-14 10:00:00',
      ],
      [
        'title' => 'The Quiet Discipline of Excellence',
        'slug' => 'the-quiet-discipline-of-excellence',
        'category' => 'Leadership',
        'summary' => 'Excellence is not a moment. It is a long obedience in the same direction.',
        'body' => 'We are formed in private long before we are deployed in public. Excellence is the daily offering of diligence, craft, and faithfulness.',
        'tags' => ['excellence', 'leadership', 'faith', 'marketplace'],
        'metadata' => ['author' => 'Yemi Akins', 'reading_time' => '6 min read', 'reading_minutes' => 6, 'image_asset' => 'event-summit', 'popular' => true, 'trending_score' => 85],
        'published_at' => '2026-04-30 10:00:00',
      ],
      [
        'title' => 'Women Rising in the Marketplace',
        'slug' => 'women-rising-in-the-marketplace',
        'category' => 'Faith & Work',
        'summary' => 'A celebration of the daughters of the Kingdom carrying influence with grace and grit.',
        'body' => 'There is a rising company of women being raised for such a time as this, carrying wisdom, conviction, excellence, and compassion into every sphere.',
        'tags' => ['women', 'faith', 'leadership', 'marketplace'],
        'metadata' => ['author' => 'Emi Adelakun', 'reading_time' => '8 min read', 'reading_minutes' => 8, 'image_asset' => 'about-movement', 'trending_score' => 80],
        'published_at' => '2026-04-08 10:00:00',
      ],
      [
        'title' => 'Outreach as Overflow',
        'slug' => 'outreach-as-overflow',
        'category' => 'Outreach',
        'summary' => 'Mission is what happens when a full cup is tipped over by love.',
        'body' => 'We do not reach out from obligation but from overflow. Mercy becomes sustainable when it is rooted in intimacy with Christ.',
        'tags' => ['outreach', 'mission', 'faith', 'kingdom'],
        'metadata' => ['author' => 'Jesse Jangfa', 'reading_time' => '5 min read', 'reading_minutes' => 5, 'image_asset' => 'hero-summit', 'trending_score' => 76],
        'published_at' => '2026-03-19 10:00:00',
      ],
    ];

    $gallery = [
      ['title' => 'Tribe Fellowship — Birthday Celebration', 'slug' => 'tribe-fellowship-birthday-celebration', 'category' => 'Events', 'summary' => 'Marketplace Ministers celebrating community and fellowship together at a chapter gathering.', 'metadata' => ['photographer' => 'Tribe Media Team', 'location' => 'Lagos, Nigeria', 'event' => 'Chapter Fellowship', 'uploaded_label' => 'June 28, 2026', 'image_asset' => 'gallery-community-selfie', 'aspect' => 'square'], 'published_at' => '2026-06-28 12:00:00'],
      ['title' => 'The Tribe of Marketplace Ministers', 'slug' => 'the-tribe-of-marketplace-ministers', 'category' => 'Leadership', 'summary' => 'Leaders and members united — a global community of marketplace ministers and Kingdom professionals.', 'metadata' => ['photographer' => 'Tribe Media Team', 'location' => 'Lagos, Nigeria', 'event' => 'Tribe Gathering', 'uploaded_label' => 'June 28, 2026', 'image_asset' => 'gallery-tribe-gathering', 'aspect' => 'square'], 'published_at' => '2026-06-28 12:00:00'],
      ['title' => 'Faith & Fellowship Gathering', 'slug' => 'faith-fellowship-gathering', 'category' => 'Events', 'summary' => 'Members connecting through scripture, fellowship, and shared Kingdom purpose at a ministry event.', 'metadata' => ['photographer' => 'Tribe Media Team', 'location' => 'Lagos, Nigeria', 'event' => 'Community Gathering', 'uploaded_label' => 'June 28, 2026', 'image_asset' => 'gallery-fellowship-event', 'aspect' => 'square'], 'published_at' => '2026-06-28 12:00:00'],
      ['title' => 'Corporate Prayer & Worship', 'slug' => 'corporate-prayer-worship', 'category' => 'Prayer', 'summary' => 'Intercessors and marketplace ministers in united prayer — contending together in worship.', 'metadata' => ['photographer' => 'Tribe Media Team', 'location' => 'Lagos, Nigeria', 'event' => 'Prayer Gathering', 'uploaded_label' => 'June 28, 2026', 'image_asset' => 'gallery-prayer-gathering', 'aspect' => 'square'], 'published_at' => '2026-06-28 12:00:00'],
      ['title' => 'Lagos Executive Forum', 'slug' => 'lagos-executive-forum', 'category' => 'Events', 'summary' => 'Marketplace ministers gathered for the monthly executive forum in Lagos — prayer, teaching, and fellowship.', 'metadata' => ['photographer' => 'Tribe Media Team', 'location' => 'Lagos, Nigeria', 'event' => 'Executive Forum', 'uploaded_label' => 'June 1, 2026', 'image_asset' => 'event-summit', 'aspect' => 'wide'], 'published_at' => '2026-06-01 10:00:00'],
      ['title' => 'Prayer Watch — Night Vigil', 'slug' => 'prayer-watch-night-vigil', 'category' => 'Prayer', 'summary' => 'Intercessors contending through the night across time zones during the global prayer watch.', 'metadata' => ['photographer' => 'Jonathan Oraka', 'location' => 'Lagos, Nigeria', 'event' => 'Prayer Watch', 'uploaded_label' => 'May 20, 2026', 'image_asset' => 'event-prayer', 'aspect' => 'tall'], 'published_at' => '2026-05-20 22:00:00'],
      ['title' => 'Faith & Works Masterclass', 'slug' => 'faith-works-masterclass', 'category' => 'Training', 'summary' => 'Professionals in session exploring the integration of biblical conviction with workplace excellence.', 'metadata' => ['photographer' => 'Yemi Akins', 'location' => 'London, United Kingdom', 'event' => 'Faith & Works Masterclass', 'uploaded_label' => 'May 10, 2026', 'image_asset' => 'event-masterclass', 'aspect' => 'square'], 'published_at' => '2026-05-10 14:00:00'],
      ['title' => 'Nairobi Convergence', 'slug' => 'nairobi-convergence', 'category' => 'Conferences', 'summary' => 'East African marketplace leaders converging for worship, strategy, and regional commissioning.', 'metadata' => ['photographer' => 'Stephen Nyaega', 'location' => 'Nairobi, Kenya', 'event' => 'Regional Convergence', 'uploaded_label' => 'April 28, 2026', 'image_asset' => 'hero-summit', 'aspect' => 'wide'], 'published_at' => '2026-04-28 09:00:00'],
      ['title' => 'Outreach in Soweto', 'slug' => 'outreach-in-soweto', 'category' => 'Outreach', 'summary' => 'Mercy initiative deployment — marketplace ministers serving communities with practical compassion.', 'metadata' => ['photographer' => 'Lily Mahlo', 'location' => 'Soweto, South Africa', 'event' => 'City Outreach', 'uploaded_label' => 'April 15, 2026', 'image_asset' => 'about-movement', 'aspect' => 'tall'], 'published_at' => '2026-04-15 11:00:00'],
      ['title' => 'Marketplace Professionals', 'slug' => 'marketplace-professionals', 'category' => 'Leadership', 'summary' => 'A portrait of executives and entrepreneurs united under one mandate across industries.', 'metadata' => ['photographer' => 'Tribe Media Team', 'location' => 'Accra, Ghana', 'event' => 'Chapter Gathering', 'uploaded_label' => 'April 2, 2026', 'image_asset' => 'marketplace-professionals', 'aspect' => 'square'], 'published_at' => '2026-04-02 16:00:00'],
      ['title' => 'Country Activity — Ghana', 'slug' => 'country-activity-ghana', 'category' => 'Country Activities', 'summary' => 'Accra chapter activity — mobilising marketplace ministers across banking and creative sectors.', 'metadata' => ['photographer' => 'Tribe Media Team', 'location' => 'Accra, Ghana', 'event' => 'Ghana Chapter Forum', 'uploaded_label' => 'March 22, 2026', 'image_asset' => 'event-summit', 'aspect' => 'square'], 'published_at' => '2026-03-22 10:00:00'],
      ['title' => 'Annual Summit Stage', 'slug' => 'annual-summit-stage', 'category' => 'Conferences', 'summary' => 'The main stage at the Global Summit — one Tribe, many nations, one mandate.', 'metadata' => ['photographer' => 'Damola Adelakun', 'location' => 'Lagos, Nigeria', 'event' => 'Global Summit 2026', 'uploaded_label' => 'March 8, 2026', 'image_asset' => 'hero-summit', 'aspect' => 'wide'], 'published_at' => '2026-03-08 18:00:00'],
      ['title' => 'Intercessors Lounge', 'slug' => 'intercessors-lounge', 'category' => 'Prayer', 'summary' => 'A dedicated space for prayer and intercession during executive gatherings.', 'metadata' => ['photographer' => 'Jonathan Oraka', 'location' => 'Lagos, Nigeria', 'event' => 'Executive Forum', 'uploaded_label' => 'Feb 18, 2026', 'image_asset' => 'event-prayer', 'aspect' => 'square'], 'published_at' => '2026-02-18 08:00:00'],
      ['title' => 'Kingdom Funders Roundtable', 'slug' => 'kingdom-funders-roundtable', 'category' => 'Leadership', 'summary' => 'Stewards of Kingdom capital in dialogue on deploying resources for eternal impact.', 'metadata' => ['photographer' => 'Terna Yahemba', 'location' => 'Lagos, Nigeria', 'event' => 'Kingdom Capital Forum', 'uploaded_label' => 'Feb 5, 2026', 'image_asset' => 'marketplace-professionals', 'aspect' => 'wide'], 'published_at' => '2026-02-05 13:00:00'],
      ['title' => 'Forerunners Cohort Send-Off', 'slug' => 'forerunners-cohort-send-off', 'category' => 'Training', 'summary' => 'Next-generation leaders commissioned at the close of an intensive formation cohort.', 'metadata' => ['photographer' => 'Jesse Jangfa', 'location' => 'Abuja, Nigeria', 'event' => 'Forerunners Intensive', 'uploaded_label' => 'Jan 20, 2026', 'image_asset' => 'event-masterclass', 'aspect' => 'tall'], 'published_at' => '2026-01-20 15:00:00'],
      ['title' => 'Dar es Salaam Pioneer Gathering', 'slug' => 'dar-es-salaam-pioneer-gathering', 'category' => 'Country Activities', 'summary' => 'Emerging Tanzania chapter exploring marketplace ministry foundations across East Africa.', 'metadata' => ['photographer' => 'Tribe Media Team', 'location' => 'Dar es Salaam, Tanzania', 'event' => 'Chapter Pioneer Meeting', 'uploaded_label' => 'Dec 12, 2025', 'image_asset' => 'about-movement', 'aspect' => 'wide'], 'published_at' => '2025-12-12 10:00:00'],
    ];

    $resources = [
      ['title' => 'Marketplace Minister\'s Handbook', 'slug' => 'marketplace-ministers-handbook', 'category' => 'Formation', 'summary' => 'A foundational guide to integrating faith and professional excellence — covering identity, prayer, excellence, and deployment in the marketplace.', 'metadata' => ['subtitle' => 'Foundations for faith-integrated leadership', 'type' => 'book', 'author' => 'The Tribe Council', 'image_asset' => 'event-prayer', 'file_size' => '4.2 MB', 'file_size_bytes' => 4400000, 'language' => 'English', 'download_count' => 2840, 'view_count' => 9120, 'published_label' => 'May 15, 2026', 'access_level' => 'free', 'download_url' => '/api/v1/public/catalog/resource/marketplace-ministers-handbook/download'], 'published_at' => '2026-05-15 10:00:00', 'is_featured' => true],
      ['title' => 'Kingdom Leadership Playbook', 'slug' => 'kingdom-leadership-playbook', 'category' => 'Leadership', 'summary' => 'Practical frameworks for leading teams, boards, and ventures from a biblical center — decision-making, culture, and conviction under pressure.', 'metadata' => ['subtitle' => 'Frameworks for boards, teams, and ventures', 'type' => 'pdf', 'author' => 'Faith & Works Clinic', 'image_asset' => 'event-masterclass', 'file_size' => '2.8 MB', 'file_size_bytes' => 2900000, 'language' => 'English', 'download_count' => 1920, 'view_count' => 5400, 'published_label' => 'April 28, 2026', 'access_level' => 'members-only', 'download_url' => '/api/v1/public/catalog/resource/kingdom-leadership-playbook/download'], 'published_at' => '2026-04-28 14:00:00'],
      ['title' => 'Prayer Watch Study Guide', 'slug' => 'prayer-watch-study-guide', 'category' => 'Prayer', 'summary' => 'A four-week devotional and study guide for marketplace intercessors — daily rhythms, scriptures, and prayer assignments.', 'metadata' => ['subtitle' => 'Four weeks for marketplace intercessors', 'type' => 'study-guide', 'author' => 'Prayer Ministry', 'image_asset' => 'event-prayer', 'file_size' => '1.6 MB', 'file_size_bytes' => 1650000, 'language' => 'English', 'download_count' => 1560, 'view_count' => 4200, 'published_label' => 'April 10, 2026', 'access_level' => 'free', 'download_url' => '/api/v1/public/catalog/resource/prayer-watch-study-guide/download'], 'published_at' => '2026-04-10 09:00:00'],
      ['title' => 'The Altar Before the Empire', 'slug' => 'the-altar-before-the-empire', 'category' => 'Prayer', 'summary' => 'Recorded sermon on rebuilding the altar in the boardroom — why marketplace leaders must recover prayer-first rhythms.', 'metadata' => ['subtitle' => 'Sermon — Executive Forum Lagos', 'type' => 'sermon', 'author' => 'Damola Adelakun', 'image_asset' => 'event-summit', 'file_size' => '48 MB', 'file_size_bytes' => 50300000, 'language' => 'English', 'download_count' => 890, 'view_count' => 6200, 'published_label' => 'March 22, 2026', 'access_level' => 'free', 'download_url' => '/api/v1/public/catalog/resource/the-altar-before-the-empire/download'], 'published_at' => '2026-03-22 11:00:00'],
      ['title' => 'Morning Declaration Series', 'slug' => 'morning-declaration-series', 'category' => 'Formation', 'summary' => 'Daily audio declarations for executives and entrepreneurs — start each day rooted in Kingdom identity.', 'metadata' => ['subtitle' => 'Daily audio for executives', 'type' => 'audio', 'author' => 'Media Team', 'image_asset' => 'marketplace-professionals', 'file_size' => '86 MB', 'file_size_bytes' => 90100000, 'language' => 'English', 'download_count' => 2100, 'view_count' => 7800, 'published_label' => 'March 8, 2026', 'access_level' => 'members-only'], 'published_at' => '2026-03-08 08:00:00'],
      ['title' => 'Executive Formation Masterclass', 'slug' => 'executive-formation-masterclass', 'category' => 'Leadership', 'summary' => 'Recorded sessions from the London leadership intensive — six sessions on formation, influence, and deployment.', 'metadata' => ['subtitle' => 'London leadership intensive recordings', 'type' => 'video', 'author' => 'Forerunners', 'image_asset' => 'hero-summit', 'file_size' => '1.2 GB', 'file_size_bytes' => 1280000000, 'language' => 'English', 'download_count' => 640, 'view_count' => 4500, 'published_label' => 'Feb 18, 2026', 'access_level' => 'paid'], 'published_at' => '2026-02-18 15:00:00'],
      ['title' => 'Stewardship & Generosity Guide', 'slug' => 'stewardship-generosity-guide', 'category' => 'Kingdom Economics', 'summary' => 'Biblical principles for Kingdom funders and givers — stewardship, strategy, and eternal yield.', 'metadata' => ['subtitle' => 'Kingdom economics for givers', 'type' => 'study-guide', 'author' => 'Kingdom Funders', 'image_asset' => 'marketplace-professionals', 'file_size' => '1.4 MB', 'file_size_bytes' => 1450000, 'language' => 'English', 'download_count' => 1180, 'view_count' => 3600, 'published_label' => 'Feb 5, 2026', 'access_level' => 'members-only'], 'published_at' => '2026-02-05 10:00:00'],
      ['title' => 'Excellence as Worship', 'slug' => 'excellence-as-worship', 'category' => 'Professional Excellence', 'summary' => 'Collected essays on pursuing professional excellence as an act of worship — craft, diligence, and Kingdom standard.', 'metadata' => ['subtitle' => 'Article series compilation', 'type' => 'article', 'author' => 'Yemi Akins', 'image_asset' => 'about-movement', 'file_size' => '820 KB', 'file_size_bytes' => 840000, 'language' => 'English', 'download_count' => 980, 'view_count' => 5100, 'published_label' => 'Jan 20, 2026', 'access_level' => 'free', 'download_url' => '/api/v1/public/catalog/resource/excellence-as-worship/download'], 'published_at' => '2026-01-20 12:00:00'],
      ['title' => 'Outreach Field Manual', 'slug' => 'outreach-field-manual', 'category' => 'Outreach', 'summary' => 'Teaching notes for outreach teams — planning, safety, mercy projects, and gospel integration.', 'metadata' => ['subtitle' => 'Mercy initiatives & city engagement', 'type' => 'teaching-notes', 'author' => 'Outreach Ministry', 'image_asset' => 'about-movement', 'file_size' => '960 KB', 'file_size_bytes' => 980000, 'language' => 'English', 'download_count' => 720, 'view_count' => 2900, 'published_label' => 'Jan 8, 2026', 'access_level' => 'restricted'], 'published_at' => '2026-01-08 09:00:00'],
      ['title' => 'Marriage & Marketplace', 'slug' => 'marriage-marketplace', 'category' => 'Family & Life', 'summary' => 'A PDF guide for couples navigating the pressures of marketplace leadership — communication, boundaries, and prayer.', 'metadata' => ['subtitle' => 'Balancing home and high-stakes leadership', 'type' => 'pdf', 'author' => 'Care Ministry', 'image_asset' => 'marketplace-professionals', 'file_size' => '2.1 MB', 'file_size_bytes' => 2200000, 'language' => 'English', 'download_count' => 1340, 'view_count' => 4100, 'published_label' => 'Dec 15, 2025', 'access_level' => 'members-only'], 'published_at' => '2025-12-15 10:00:00'],
      ['title' => 'Global Summit Session Notes', 'slug' => 'global-summit-session-notes', 'category' => 'Formation', 'summary' => 'Teaching notes from the Global Summit opening plenary — outlines, scriptures, and discussion prompts.', 'metadata' => ['subtitle' => '2026 plenary teaching notes', 'type' => 'teaching-notes', 'author' => 'The Tribe Council', 'image_asset' => 'hero-summit', 'file_size' => '1.1 MB', 'file_size_bytes' => 1120000, 'language' => 'English', 'download_count' => 560, 'view_count' => 2200, 'published_label' => 'Dec 1, 2025', 'access_level' => 'members-only'], 'published_at' => '2025-12-01 14:00:00'],
      ['title' => 'Why We Pray Before We Build', 'slug' => 'why-we-pray-before-we-build', 'category' => 'Prayer', 'summary' => 'Message on the altar-first rhythm that anchors every executive decision and industry shift.', 'metadata' => ['subtitle' => 'Sermon — Prayer Watch Convergence', 'type' => 'sermon', 'author' => 'Jonathan Oraka', 'image_asset' => 'event-prayer', 'file_size' => '52 MB', 'file_size_bytes' => 54500000, 'language' => 'English', 'download_count' => 1100, 'view_count' => 6800, 'published_label' => 'Nov 20, 2025', 'access_level' => 'free'], 'published_at' => '2025-11-20 10:00:00'],
    ];

    $items = [
      ...array_map(fn (array $item): array => array_merge($item, ['type' => CatalogItemType::Blog]), $blogs),
      ...array_map(fn (array $item): array => array_merge($item, ['type' => CatalogItemType::Gallery]), $gallery),
      ...array_map(fn (array $item): array => array_merge($item, ['type' => CatalogItemType::Resource]), $resources),
      ['type' => CatalogItemType::Vlog, 'title' => 'Convener Message', 'slug' => 'convener-message', 'category' => 'Messages', 'summary' => 'A word from the convener.'],
    ];

    CmsCatalogItem::query()
      ->whereIn('slug', [
        'kingdom-leadership-marketplace',
        'tribe-gathering',
        'marketplace-ministry-guide',
      ])
      ->update(['is_active' => false]);

    foreach ($items as $index => $item) {
      $metadata = $item['metadata'] ?? [];
      $type = $item['type'];

      if ($type === CatalogItemType::Gallery || $type === CatalogItemType::Resource) {
        $media = $this->seedCatalogImageMedia(
          (string) ($metadata['image_asset'] ?? 'gallery-tribe-gathering'),
          $item['slug'],
          $item['title'],
          $type,
        );

        $item['featured_media_id'] = $media->id;
        $metadata['image_url'] = $media->url();
        unset($metadata['image_asset']);
      }

      if ($type === CatalogItemType::Resource) {
        $fileMedia = $this->seedResourceFileMedia(
          $item['slug'],
          $item['title'],
          $item['summary'] ?? null,
          $item['body'] ?? null,
        );

        $metadata['file_url'] = $fileMedia->url();
        $metadata['download_url'] = $fileMedia->url();
        $metadata['file_media_id'] = $fileMedia->id;
      }

      $item['metadata'] = $metadata;

      CmsCatalogItem::query()->updateOrCreate(
        ['type' => $item['type'], 'slug' => $item['slug']],
        array_merge($item, [
          'is_active' => true,
          'is_featured' => $item['is_featured'] ?? false,
          'sort_order' => $index + 1,
          'published_at' => $item['published_at'] ?? now(),
        ]),
      );
    }
  }
}
