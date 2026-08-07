<?php

declare(strict_types=1);

namespace App\Enums;

enum LeadershipHierarchyLevel: string
{
  case President = 'president';
  case VicePresident = 'vice_president';
  case GlobalCoordinator = 'global_coordinator';
  case ContinentalCoordinator = 'continental_coordinator';
  case NationalCoordinator = 'national_coordinator';
  case RegionalCoordinator = 'regional_coordinator';
  case StateCoordinator = 'state_coordinator';
  case MinistryHead = 'ministry_head';
  case DepartmentHead = 'department_head';
  case TeamLead = 'team_lead';
  case VolunteerLead = 'volunteer_lead';

  public function label(): string
  {
    return match ($this) {
      self::President => 'President',
      self::VicePresident => 'Vice President',
      self::GlobalCoordinator => 'Global Coordinator',
      self::ContinentalCoordinator => 'Continental Coordinator',
      self::NationalCoordinator => 'National Coordinator',
      self::RegionalCoordinator => 'Regional Coordinator',
      self::StateCoordinator => 'State Coordinator',
      self::MinistryHead => 'Ministry Head',
      self::DepartmentHead => 'Department Head',
      self::TeamLead => 'Team Lead',
      self::VolunteerLead => 'Volunteer Lead',
    };
  }

  /**
   * @return list<self>
   */
  public static function ordered(): array
  {
    return [
      self::President,
      self::VicePresident,
      self::GlobalCoordinator,
      self::ContinentalCoordinator,
      self::NationalCoordinator,
      self::RegionalCoordinator,
      self::StateCoordinator,
      self::MinistryHead,
      self::DepartmentHead,
      self::TeamLead,
      self::VolunteerLead,
    ];
  }
}
