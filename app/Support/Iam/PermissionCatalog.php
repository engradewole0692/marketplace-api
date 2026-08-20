<?php

declare(strict_types=1);

namespace App\Support\Iam;

/**
 * Enterprise permission catalog for all platform modules.
 *
 * @return list<array{name: string, slug: string, module: string, group: string, description: string}>
 */
final class PermissionCatalog
{
  public static function all(): array
  {
    return array_merge(
      self::admin(),
      self::users(),
      self::roles(),
      self::permissions(),
      self::organization(),
      self::ministryStructure(),
      self::members(),
      self::learning(),
      self::content(),
      self::community(),
      self::finance(),
      self::insights(),
      self::system(),
    );
  }

  /**
   * @return list<array{name: string, slug: string, module: string, group: string, description: string}>
   */
  private static function admin(): array
  {
    return [
      self::entry('Admin Access', 'admin.access', 'admin', 'access', 'Access the administration portal.'),
    ];
  }

  /**
   * @return list<array{name: string, slug: string, module: string, group: string, description: string}>
   */
  private static function users(): array
  {
    return [
      self::entry('View Users', 'users.view', 'users', 'users', 'View user directory and profiles.'),
      self::entry('Create Users', 'users.create', 'users', 'users', 'Create new user accounts.'),
      self::entry('Update Users', 'users.update', 'users', 'users', 'Edit user accounts and profiles.'),
      self::entry('Delete Users', 'users.delete', 'users', 'users', 'Soft delete user accounts.'),
      self::entry('Restore Users', 'users.restore', 'users', 'users', 'Restore soft-deleted users.'),
      self::entry('Assign User Roles', 'users.assign_roles', 'users', 'users', 'Assign or remove roles from users.'),
      self::entry('Bulk User Actions', 'users.bulk', 'users', 'users', 'Perform bulk user operations.'),
    ];
  }

  /**
   * @return list<array{name: string, slug: string, module: string, group: string, description: string}>
   */
  private static function roles(): array
  {
    return [
      self::entry('View Roles', 'roles.view', 'roles', 'roles', 'View roles and assignments.'),
      self::entry('Create Roles', 'roles.create', 'roles', 'roles', 'Create new roles.'),
      self::entry('Update Roles', 'roles.update', 'roles', 'roles', 'Edit role definitions.'),
      self::entry('Delete Roles', 'roles.delete', 'roles', 'roles', 'Delete custom roles.'),
      self::entry('Clone Roles', 'roles.clone', 'roles', 'roles', 'Clone an existing role.'),
      self::entry('Assign Role Permissions', 'roles.assign_permissions', 'roles', 'roles', 'Manage role permission assignments.'),
    ];
  }

  /**
   * @return list<array{name: string, slug: string, module: string, group: string, description: string}>
   */
  private static function permissions(): array
  {
    return [
      self::entry('View Permissions', 'permissions.view', 'permissions', 'permissions', 'View permission catalog.'),
      self::entry('Assign Direct Permissions', 'permissions.assign', 'permissions', 'permissions', 'Assign direct permissions to users.'),
      self::entry('Manage Permissions', 'permissions.manage', 'permissions', 'permissions', 'Manage permission definitions.'),
    ];
  }

  /**
   * @return list<array{name: string, slug: string, module: string, group: string, description: string}>
   */
  private static function organization(): array
  {
    return [];
  }

  /**
   * @return list<array{name: string, slug: string, module: string, group: string, description: string}>
   */
  private static function ministryStructure(): array
  {
    return [
      self::entry('Manage Countries', 'countries.manage', 'ministry_structure', 'countries', 'Manage country records for Marketplace Ministers.'),
      self::entry('Manage Regions', 'regions.manage', 'ministry_structure', 'regions', 'Manage regional structure.'),
      self::entry('View Ministries', 'ministries.view', 'ministry_structure', 'ministries', 'View ministry units.'),
      self::entry('Manage Ministries', 'ministries.manage', 'ministry_structure', 'ministries', 'Manage ministry units.'),
      self::entry('Manage Leadership', 'leadership.manage', 'ministry_structure', 'leadership', 'Manage leadership records.'),
    ];
  }

  /**
   * @return list<array{name: string, slug: string, module: string, group: string, description: string}>
   */
  private static function members(): array
  {
    return [
      self::entry('View Members', 'members.view', 'members', 'members', 'View member registry and profiles.'),
      self::entry('Manage Members', 'members.manage', 'members', 'members', 'Full member lifecycle management.'),
      self::entry('Create Members', 'members.create', 'members', 'members', 'Create new member profiles.'),
      self::entry('Update Members', 'members.update', 'members', 'members', 'Edit member profiles.'),
      self::entry('Delete Members', 'members.delete', 'members', 'members', 'Soft delete member profiles.'),
      self::entry('Approve Members', 'members.approve', 'members', 'members', 'Approve membership applications.'),
      self::entry('Reject Members', 'members.reject', 'members', 'members', 'Reject membership applications.'),
      self::entry('Activate Members', 'members.activate', 'members', 'members', 'Activate member accounts.'),
      self::entry('Manage Interviews', 'interviews.manage', 'members', 'interviews', 'Schedule and manage membership interviews.'),
      self::entry('Manage Onboarding', 'onboarding.manage', 'members', 'onboarding', 'Manage member onboarding workflow.'),
      self::entry('Export Members', 'members.export', 'members', 'members', 'Export member data.'),
      self::entry('Restore Members', 'members.restore', 'members', 'members', 'Restore soft-deleted members.'),
      self::entry('Archive Members', 'members.archive', 'members', 'members', 'Archive member profiles.'),
      self::entry('Member Portal Access', 'member.portal', 'members', 'portal', 'Access the authenticated member portal.'),
    ];
  }

  /**
   * @return list<array{name: string, slug: string, module: string, group: string, description: string}>
   */
  private static function learning(): array
  {
    return [
      self::entry('Manage Courses', 'courses.manage', 'learning', 'courses', 'Full LMS course administration.'),
      self::entry('Publish Courses', 'courses.publish', 'learning', 'courses', 'Publish and manage courses.'),
      self::entry('Review Courses', 'courses.review', 'learning', 'courses', 'Moderate course reviews and content quality.'),
      self::entry('Enrol Courses', 'courses.enroll', 'learning', 'courses', 'Manage course enrolments.'),
      self::entry('Teach Courses', 'courses.teach', 'learning', 'courses', 'Instruct and manage assigned courses.'),
      self::entry('Manage Assessments', 'assessments.manage', 'learning', 'assessments', 'Manage assessments.'),
      self::entry('Manage Certificates', 'certificates.manage', 'learning', 'certificates', 'Issue and manage LMS certificates.'),
      self::entry('Manage Course Payments', 'course_payments.manage', 'learning', 'commerce', 'Manage course orders, invoices, refunds, and payment confirmation.'),
      self::entry('Learner Portal Access', 'learner.portal', 'learning', 'portal', 'Access the public learner portal.'),
    ];
  }

  /**
   * @return list<array{name: string, slug: string, module: string, group: string, description: string}>
   */
  private static function content(): array
  {
    return [
      self::entry('Manage CMS', 'cms.manage', 'content', 'cms', 'Manage all CMS content and pages.'),
      self::entry('View CMS Pages', 'cms.pages.view', 'content', 'cms', 'View CMS pages.'),
      self::entry('Manage CMS Pages', 'cms.pages.manage', 'content', 'cms', 'Create and edit CMS pages.'),
      self::entry('Publish CMS Pages', 'cms.pages.publish', 'content', 'cms', 'Publish and schedule CMS pages.'),
      self::entry('Manage CMS Media', 'cms.media.manage', 'content', 'cms', 'Manage CMS media library assets.'),
      self::entry('Manage CMS Partners', 'cms.partners.manage', 'content', 'cms', 'Manage CMS partner records.'),
      self::entry('Manage CMS Testimonials', 'cms.testimonials.manage', 'content', 'cms', 'Manage CMS testimonials.'),
      self::entry('Manage CMS Menus', 'cms.menus.manage', 'content', 'cms', 'Manage CMS navigation menus.'),
      self::entry('Manage CMS SEO', 'cms.seo.manage', 'content', 'cms', 'Manage CMS SEO metadata.'),
      self::entry('Manage Blog', 'blog.manage', 'content', 'blog', 'Manage blog posts and publishing.'),
      self::entry('Manage Resources', 'resources.manage', 'content', 'resources', 'Manage digital resources.'),
      self::entry('Manage Gallery', 'gallery.manage', 'content', 'gallery', 'Manage media galleries.'),
    ];
  }

  /**
   * @return list<array{name: string, slug: string, module: string, group: string, description: string}>
   */
  private static function community(): array
  {
    return [
      self::entry('View Events', 'events.view', 'community', 'events', 'View events, sessions, and event content.'),
      self::entry('Manage Events', 'events.manage', 'community', 'events', 'Create, edit, and delete events and registrations.'),
      self::entry('Publish Events', 'events.publish', 'community', 'events', 'Publish events for public visibility.'),
      self::entry('View Registrations', 'registrations.view', 'community', 'events', 'View event registrations.'),
      self::entry('Manage Registrations', 'registrations.manage', 'community', 'events', 'Manage event registrations and statuses.'),
      self::entry('Manage Attendance', 'attendance.manage', 'community', 'events', 'Record check-ins, check-outs, and attendance.'),
      self::entry('Issue Certificates', 'certificates.issue', 'community', 'events', 'Issue and revoke event certificates.'),
      self::entry('Manage Speakers', 'speakers.manage', 'community', 'events', 'Manage event speaker profiles.'),
      self::entry('Manage Venues', 'venues.manage', 'community', 'events', 'Manage event venue records.'),
      self::entry('Manage Event Exports', 'exports.manage', 'community', 'events', 'Queue and download event data exports.'),
      self::entry('Manage Event Notifications', 'event_notifications.manage', 'community', 'events', 'Manage event notification templates and announcements.'),
      self::entry('Manage Event Volunteers', 'volunteers.manage', 'community', 'events', 'Manage event volunteer roles and assignments.'),
      self::entry('Manage Event Payments', 'event_payments.manage', 'community', 'events', 'Manage event payments, coupons, and revenue.'),
      self::entry('Manage Prayer', 'prayer.manage', 'community', 'prayer', 'Manage prayer requests.'),
      self::entry('View Counselling', 'counselling.view', 'community', 'counselling', 'View counselling cases and catalogue.'),
      self::entry('Manage Counselling', 'counselling.manage', 'community', 'counselling', 'Manage counselling services, cases, payments, and reports.'),
      self::entry('Counsellor Portal', 'counsellor.portal', 'community', 'counselling', 'Access counsellor workspace for assigned cases.'),
      self::entry('Manage Newsletter', 'newsletter.manage', 'community', 'newsletter', 'Manage newsletter campaigns.'),
      self::entry('View Business Reviews', 'business-review.view', 'community', 'business-review', 'View Faith & Works business review applications.'),
      self::entry('Manage Business Reviews', 'business-review.manage', 'community', 'business-review', 'Manage business review applications, status, notes, and conversations.'),
    ];
  }

  /**
   * @return list<array{name: string, slug: string, module: string, group: string, description: string}>
   */
  private static function finance(): array
  {
    return [
      self::entry('View Donations', 'donations.view', 'finance', 'donations', 'View donation ledger and analytics.'),
      self::entry('Manage Donations', 'donations.manage', 'finance', 'donations', 'Manage donations, funds, and payment methods.'),
      self::entry('Confirm Donations', 'donations.confirm', 'finance', 'donations', 'Confirm offline/wire/bank donations.'),
      self::entry('Issue Donation Receipts', 'donations.receipts', 'finance', 'donations', 'Issue standard and tax receipts.'),
      self::entry('Manage Donation Settings', 'donations.settings', 'finance', 'donations', 'Configure country payment methods and providers.'),
    ];
  }

  /**
   * @return list<array{name: string, slug: string, module: string, group: string, description: string}>
   */
  private static function insights(): array
  {
    return [
      self::entry('View Analytics', 'analytics.view', 'insights', 'analytics', 'View analytics dashboards.'),
      self::entry('Export Reports', 'reports.export', 'insights', 'reports', 'Export operational reports.'),
      self::entry('View Reports', 'reports.view', 'insights', 'reports', 'View generated reports.'),
    ];
  }

  /**
   * @return list<array{name: string, slug: string, module: string, group: string, description: string}>
   */
  private static function system(): array
  {
    return [
      self::entry('Manage Media Library', 'media.manage', 'system', 'media', 'Manage media assets.'),
      self::entry('Manage Notifications', 'notifications.manage', 'system', 'notifications', 'Manage system notifications.'),
      self::entry('View Audit Logs', 'audit.view', 'system', 'audit', 'View IAM and security audit logs.'),
      self::entry('Manage Communications', 'communications.manage', 'system', 'communications', 'Manage email routing, templates, branding, and delivery logs.'),
      self::entry('Manage Settings', 'settings.manage', 'system', 'settings', 'Manage application settings.'),
    ];
  }

  /**
   * @return array{name: string, slug: string, module: string, group: string, description: string}
   */
  private static function entry(string $name, string $slug, string $module, string $group, string $description): array
  {
    return compact('name', 'slug', 'module', 'group', 'description');
  }
}
