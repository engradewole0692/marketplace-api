<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
  public function up(): void
  {
    $sections = DB::table('cms_page_sections')->select(['id', 'content', 'draft_content', 'status', 'published_at'])->get();

    foreach ($sections as $section) {
      $updates = [];

      if ($section->draft_content === null && $section->content !== null) {
        $updates['draft_content'] = $section->content;
      }

      if ($section->published_at === null && ($section->status === null || $section->status === 'published')) {
        $updates['status'] = 'published';
        $updates['published_at'] = now();
      }

      if ($updates !== []) {
        DB::table('cms_page_sections')->where('id', $section->id)->update($updates);
      }
    }
  }

  public function down(): void
  {
    // Irreversible content backfill.
  }
};
