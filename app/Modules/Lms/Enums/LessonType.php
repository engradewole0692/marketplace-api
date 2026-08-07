<?php

declare(strict_types=1);

namespace App\Modules\Lms\Enums;

enum LessonType: string
{
  case Video = 'video';
  case Text = 'text';
  case Quiz = 'quiz';
  case Assignment = 'assignment';
  case Resource = 'resource';
  case Audio = 'audio';
  case Document = 'document';
  case Slide = 'slide';
  case ExternalUrl = 'external_url';
  case Zoom = 'zoom';
  case Youtube = 'youtube';
  case PrivateYoutube = 'private_youtube';
  case Vimeo = 'vimeo';
  case Mixed = 'mixed';
  case Practical = 'practical';

  public function label(): string
  {
    return str_replace('_', ' ', ucfirst($this->value));
  }
}