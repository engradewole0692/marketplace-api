<?php

declare(strict_types=1);

namespace App\Modules\Lms\Enums;

enum ResourceType: string
{
  case Pdf = 'pdf';
  case Word = 'word';
  case Powerpoint = 'powerpoint';
  case Excel = 'excel';
  case Zip = 'zip';
  case Audio = 'audio';
  case Book = 'book';
  case Link = 'link';
  case Download = 'download';

  public function label(): string
  {
    return str_replace('_', ' ', ucfirst($this->value));
  }
}