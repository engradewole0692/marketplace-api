<?php

declare(strict_types=1);

namespace App\Modules\Lms\Enums;

enum VideoSource: string
{
  case Youtube = 'youtube';
  case PrivateYoutube = 'private_youtube';
  case Media = 'media';
  case Embed = 'embed';
  case Upload = 'upload';
  case Vimeo = 'vimeo';
  case Zoom = 'zoom';
  case None = 'none';

  public function label(): string
  {
    return str_replace('_', ' ', ucfirst($this->value));
  }
}