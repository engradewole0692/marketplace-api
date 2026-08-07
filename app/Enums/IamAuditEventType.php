<?php

declare(strict_types=1);

namespace App\Enums;

enum IamAuditEventType: string
{
  case UserCreated = 'user_created';
  case UserUpdated = 'user_updated';
  case UserDeleted = 'user_deleted';
  case UserRestored = 'user_restored';
  case UserActivated = 'user_activated';
  case UserDeactivated = 'user_deactivated';
  case UserBulkAction = 'user_bulk_action';
  case RoleCreated = 'role_created';
  case RoleUpdated = 'role_updated';
  case RoleDeleted = 'role_deleted';
  case RoleCloned = 'role_cloned';
  case RoleAssigned = 'role_assigned';
  case RoleRemoved = 'role_removed';
  case PermissionAssigned = 'permission_assigned';
  case PermissionRevoked = 'permission_revoked';
  case StatusChanged = 'status_changed';
}
