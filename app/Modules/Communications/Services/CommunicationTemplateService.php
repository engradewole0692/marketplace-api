<?php

declare(strict_types=1);

namespace App\Modules\Communications\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Communications\Models\CommunicationRoute;
use App\Modules\Communications\Models\CommunicationTemplate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

final class CommunicationTemplateService implements ServiceContract
{
  public function __construct(
    private readonly CommunicationTemplateRenderer $renderer,
    private readonly CommunicationSettingsService $settings,
  ) {}

  /**
   * @param  array<string, mixed>  $filters
   */
  public function paginate(array $filters = []): LengthAwarePaginator
  {
    $query = CommunicationTemplate::query()->orderBy('section')->orderBy('name');

    if (! empty($filters['section'])) {
      $query->where('section', $filters['section']);
    }
    if (! empty($filters['search'])) {
      $search = (string) $filters['search'];
      $query->where(function ($q) use ($search): void {
        $q->where('name', 'like', "%{$search}%")
          ->orWhere('slug', 'like', "%{$search}%")
          ->orWhere('event_key', 'like', "%{$search}%");
      });
    }
    if (isset($filters['active'])) {
      $query->where('is_active', filter_var($filters['active'], FILTER_VALIDATE_BOOLEAN));
    }

    return $query->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 25))));
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function create(array $data, User $actor): CommunicationTemplate
  {
    $slug = $data['slug'] ?? Str::slug($data['name']);

    return CommunicationTemplate::query()->create([
      'slug' => $this->uniqueSlug($slug),
      'name' => $data['name'],
      'section' => $data['section'],
      'event_key' => $data['event_key'],
      'description' => $data['description'] ?? null,
      'subject' => $data['subject'],
      'html_body' => $data['html_body'],
      'text_body' => $data['text_body'] ?? null,
      'available_variables' => $data['available_variables'] ?? [],
      'sample_variables' => $data['sample_variables'] ?? [],
      'is_active' => (bool) ($data['is_active'] ?? true),
      'is_system' => false,
    ]);
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function update(CommunicationTemplate $template, array $data, User $actor): CommunicationTemplate
  {
    if (isset($data['slug'])) {
      $data['slug'] = $this->uniqueSlug($data['slug'], $template->id);
    }

    $template->fill(collect($data)->only([
      'slug', 'name', 'section', 'event_key', 'description', 'subject',
      'html_body', 'text_body', 'available_variables', 'sample_variables', 'is_active',
    ])->all())->save();

    return $template->fresh();
  }

  public function duplicate(CommunicationTemplate $template, User $actor): CommunicationTemplate
  {
    $copy = $template->replicate(['uuid']);
    $copy->slug = $this->uniqueSlug($template->slug.'-copy');
    $copy->name = $template->name.' (Copy)';
    $copy->is_system = false;
    $copy->save();

    return $copy->fresh();
  }

  public function resetSystemTemplate(CommunicationTemplate $template): CommunicationTemplate
  {
    if (! $template->is_system) {
      return $template;
    }

    $defaults = app(CommunicationSeederDefaults::class)->templateDefaults($template->event_key);
    if ($defaults === null) {
      return $template;
    }

    $template->fill($defaults)->save();

    return $template->fresh();
  }

  /**
   * @param  array<string, mixed>  $variables
   * @return array{subject: string, html: string}
   */
  public function preview(CommunicationTemplate $template, array $variables = []): array
  {
    $sample = array_merge($template->sample_variables ?? [], $variables);
    $branding = $this->settings->branding();
    $body = $this->renderer->render($template->html_body, $sample);

    return [
      'subject' => $this->renderer->render($template->subject, $sample),
      'html' => $this->renderer->wrapWithBranding($body, $sample, $branding),
    ];
  }

  private function uniqueSlug(string $slug, ?int $ignoreId = null): string
  {
    $base = Str::slug($slug) ?: 'template';
    $candidate = $base;
    $i = 1;
    while (
      CommunicationTemplate::query()
        ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
        ->where('slug', $candidate)
        ->exists()
    ) {
      $candidate = $base.'-'.$i++;
    }

    return $candidate;
  }
}
