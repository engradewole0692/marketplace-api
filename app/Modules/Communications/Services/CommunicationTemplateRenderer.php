<?php

declare(strict_types=1);

namespace App\Modules\Communications\Services;

use App\Contracts\ServiceContract;
use Illuminate\Support\Str;

final class CommunicationTemplateRenderer implements ServiceContract
{
  /**
   * @param  array<string, mixed>  $variables
   */
  public function render(string $template, array $variables): string
  {
    return (string) preg_replace_callback(
      '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
      function (array $matches) use ($variables): string {
        $key = $matches[1];
        if (! array_key_exists($key, $variables)) {
          return '';
        }

        return e($this->stringify($variables[$key]));
      },
      $template,
    );
  }

  /**
   * @param  array<string, mixed>  $variables
   */
  public function wrapWithBranding(string $htmlBody, array $variables, array $branding): string
  {
    $vars = array_merge($variables, [
      'site_name' => $branding['site_name'] ?? 'Marketplace Ministers',
      'website_url' => $branding['website_url'] ?? '',
      'contact_email' => $branding['contact_email'] ?? '',
      'footer_text' => $branding['footer_text'] ?? '',
      'header_text' => $branding['header_text'] ?? ($branding['site_name'] ?? 'Marketplace Ministers'),
      'logo_url' => $branding['logo_url'] ?? '',
    ]);

    $header = $this->render(
      '<div style="font-family:Georgia,serif;max-width:640px;margin:0 auto;padding:24px;color:#1c1917;line-height:1.6">'
      .'<p style="letter-spacing:.18em;text-transform:uppercase;font-size:11px;color:#a16207">{{header_text}}</p>'
      .($vars['logo_url'] ? '<p><img src="'.e((string) $vars['logo_url']).'" alt="Logo" style="max-width:180px;height:auto" /></p>' : ''),
      $vars,
    );

    $footer = $this->render(
      '<p style="margin-top:32px;color:#78716c;font-size:13px">{{footer_text}}'
      .($vars['contact_email'] ? ' · {{contact_email}}' : '')
      .($vars['website_url'] ? ' · <a href="{{website_url}}">{{website_url}}</a>' : '')
      .'</p></div>',
      $vars,
    );

    return $header.$htmlBody.$footer;
  }

  private function stringify(mixed $value): string
  {
    if ($value === null) {
      return '';
    }
    if (is_bool($value)) {
      return $value ? 'Yes' : 'No';
    }
    if (is_scalar($value)) {
      return (string) $value;
    }
    if (is_array($value)) {
      return implode(', ', array_map(fn ($v) => $this->stringify($v), $value));
    }

    return Str::limit(json_encode($value) ?: '', 500);
  }
}
