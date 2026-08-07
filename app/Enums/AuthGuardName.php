<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Guard identifiers for multi-guard authentication architecture.
 * Only web and admin are active in Phase 1B; others are reserved for RBAC phases.
 */
enum AuthGuardName: string
{
  case Web = 'web';
  case Admin = 'admin';
  case SuperAdministrator = 'super_admin';
  case Administrator = 'administrator';
  case Leader = 'leader';
  case Instructor = 'instructor';
  case Member = 'member';

  /**
   * @return list<string>
   */
  public static function configured(): array
  {
    return [
      self::Web->value,
      self::Admin->value,
    ];
  }

  /**
   * @return list<string>
   */
  public static function reserved(): array
  {
    return [
      self::SuperAdministrator->value,
      self::Administrator->value,
      self::Leader->value,
      self::Instructor->value,
      self::Member->value,
    ];
  }
}
