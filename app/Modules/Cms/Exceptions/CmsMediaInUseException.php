<?php

declare(strict_types=1);

namespace App\Modules\Cms\Exceptions;

use RuntimeException;

final class CmsMediaInUseException extends RuntimeException
{
  /**
   * @param  list<array{type: string, id: string, label: string}>  $usages
   */
  public function __construct(public readonly array $usages)
  {
    parent::__construct('Media asset is currently in use and cannot be deleted.');
  }
}
