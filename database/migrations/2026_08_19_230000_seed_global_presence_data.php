<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds supplied country leaders and office addresses into cms_countries and cms_leadership_profiles.
 * Uses upsert-style logic — safe to re-run, will not duplicate records.
 */
return new class extends Migration
{
    private const LEADERS = [
        [
            'country_slug' => 'nigeria',
            'name' => 'Mercy Ochigbo-Obe',
            'slug' => 'mercy-ochigbo-obe',
            'role' => 'Country Leader',
            'phone' => '+234 809 062 2586',
            'category' => 'country',
        ],
        [
            'country_slug' => 'ghana',
            'name' => 'Naa Djama',
            'slug' => 'naa-djama',
            'role' => 'Country Leader',
            'phone' => '+233 24 685 3605',
            'category' => 'country',
        ],
        [
            'country_slug' => 'kenya',
            'name' => 'Stephen Nyayega',
            'slug' => 'stephen-nyayega',
            'role' => 'Country Leader',
            'phone' => '+254 718 124834',
            'category' => 'country',
        ],
        [
            'country_slug' => 'south-africa',
            'name' => 'Lily Mahlo',
            'slug' => 'lily-mahlo',
            'role' => 'Country Leader',
            'phone' => '+27 79 371 2576',
            'category' => 'country',
        ],
        [
            'country_slug' => 'rwanda',
            'name' => 'Emma Kayonde',
            'slug' => 'emma-kayonde',
            'role' => 'Country Leader',
            'phone' => '+250 791 944 681',
            'category' => 'country',
        ],
    ];

    private const OFFICES = [
        'nigeria' => [
            'office_address' => '49 Ikorodu Road, Fadeyi Bus Stop, Jibowu, Yaba, Lagos',
        ],
        'ghana' => [
            'office_address' => 'Holdbrook Plaza, 18th Lane, Osu, Accra Ghana',
        ],
        'usa' => [
            'office_address' => 'Ekballo Ministries: 660 Westinghouse Blvd., Suite 108, Charlotte North Carolina 28273',
        ],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::LEADERS as $leaderData) {
            $countrySlug = $leaderData['country_slug'];
            $country = DB::table('cms_countries')->where('slug', $countrySlug)->first();

            if (! $country) {
                continue;
            }

            // Create leadership profile only when missing — never overwrite admin edits.
            $existing = DB::table('cms_leadership_profiles')->where('slug', $leaderData['slug'])->first();

            if ($existing) {
                $leaderId = $existing->id;
                $leaderPatch = ['updated_at' => $now];
                if (empty($existing->phone)) {
                    $leaderPatch['phone'] = $leaderData['phone'];
                }
                if (empty($existing->country_id)) {
                    $leaderPatch['country_id'] = $country->id;
                }
                if (empty($existing->category)) {
                    $leaderPatch['category'] = $leaderData['category'];
                }
                if (count($leaderPatch) > 1) {
                    DB::table('cms_leadership_profiles')->where('id', $existing->id)->update($leaderPatch);
                }
            } else {
                $leaderId = DB::table('cms_leadership_profiles')->insertGetId([
                    'uuid' => (string) Str::uuid(),
                    'name' => $leaderData['name'],
                    'slug' => $leaderData['slug'],
                    'role' => $leaderData['role'],
                    'category' => $leaderData['category'],
                    'phone' => $leaderData['phone'],
                    'country_id' => $country->id,
                    'is_active' => true,
                    'sort_order' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Fill missing country contact fields only — preserve administrator-entered values.
            $countryPatch = ['updated_at' => $now];
            if (empty($country->primary_leader_id)) {
                $countryPatch['primary_leader_id'] = $leaderId;
            }
            if (empty($country->phone)) {
                $countryPatch['phone'] = $leaderData['phone'];
            }
            if (empty($country->whatsapp_number)) {
                $countryPatch['whatsapp_number'] = $leaderData['phone'];
            }
            if (count($countryPatch) > 1) {
                DB::table('cms_countries')->where('id', $country->id)->update($countryPatch);
            }
        }

        // Fill missing office addresses only.
        foreach (self::OFFICES as $slug => $officeData) {
            $country = DB::table('cms_countries')->where('slug', $slug)->first();
            if (! $country || ! empty($country->office_address)) {
                continue;
            }
            DB::table('cms_countries')
                ->where('id', $country->id)
                ->update([
                    'office_address' => $officeData['office_address'],
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        // Reset seeded data — does not remove manually added data.
        $slugs = array_column(self::LEADERS, 'slug');
        DB::table('cms_leadership_profiles')->whereIn('slug', $slugs)->delete();

        foreach (array_keys(self::OFFICES) as $countrySlug) {
            DB::table('cms_countries')
                ->where('slug', $countrySlug)
                ->update(['office_address' => null, 'primary_leader_id' => null, 'phone' => null, 'whatsapp_number' => null]);
        }
    }
};
