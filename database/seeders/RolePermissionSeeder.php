<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AuthGuardName;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

final class RolePermissionSeeder extends Seeder
{
  public function run(): void
  {
    $allPermissionIds = Permission::query()->pluck('id')->all();

    $superAdmin = Role::query()->where('slug', 'super_administrator')->first();
    if ($superAdmin !== null) {
      $superAdmin->permissions()->sync($allPermissionIds);
    }

    $administrator = Role::query()->where('slug', 'administrator')->first();
    if ($administrator !== null) {
      $adminPermissionSlugs = [
        'admin.access',
        'users.view', 'users.create', 'users.update', 'users.delete', 'users.restore', 'users.assign_roles', 'users.bulk',
        'roles.view', 'roles.create', 'roles.update', 'roles.clone', 'roles.assign_permissions',
        'permissions.view', 'permissions.assign',
        'members.view', 'members.manage', 'members.create', 'members.update', 'members.delete', 'members.approve',
        'members.reject', 'members.activate', 'members.export', 'members.restore', 'members.archive',
        'interviews.manage', 'onboarding.manage',
        'countries.manage', 'regions.manage', 'ministries.view', 'ministries.manage', 'leadership.manage',
        'audit.view',
        'analytics.view', 'reports.view', 'reports.export',
        'settings.manage',
        'donations.view', 'donations.manage', 'donations.confirm', 'donations.receipts', 'donations.settings',
        'events.view', 'events.manage', 'events.publish', 'registrations.view', 'registrations.manage',
        'attendance.manage', 'certificates.issue', 'speakers.manage', 'venues.manage', 'exports.manage',
        'event_notifications.manage', 'volunteers.manage', 'event_payments.manage',
        'courses.manage', 'courses.publish', 'courses.review', 'courses.enroll', 'courses.teach',
        'assessments.manage', 'certificates.manage', 'course_payments.manage',
        'counselling.view', 'counselling.manage', 'counsellor.portal',
        'cms.manage',
        'cms.pages.view', 'cms.pages.manage', 'cms.pages.publish', 'cms.media.manage',
        'cms.partners.manage', 'cms.testimonials.manage', 'cms.menus.manage', 'cms.seo.manage',
        'blog.manage', 'gallery.manage', 'resources.manage', 'media.manage',
      ];

      $administrator->permissions()->sync(
        Permission::query()->whereIn('slug', $adminPermissionSlugs)->pluck('id'),
      );
    }

    $countryAdmin = Role::query()->where('slug', 'country_administrator')->first();
    if ($countryAdmin !== null) {
      $countryAdmin->permissions()->sync(
        Permission::query()->whereIn('slug', [
          'admin.access', 'users.view', 'members.view', 'members.create', 'members.update', 'members.approve',
          'countries.manage', 'regions.manage', 'leadership.manage', 'analytics.view',
        ])->pluck('id'),
      );
    }

    $regionalAdmin = Role::query()->where('slug', 'regional_administrator')->first();
    if ($regionalAdmin !== null) {
      $regionalAdmin->permissions()->sync(
        Permission::query()->whereIn('slug', [
          'admin.access', 'users.view', 'members.view', 'regions.manage', 'leadership.manage',
        ])->pluck('id'),
      );
    }

    $ministryAdmin = Role::query()->where('slug', 'ministry_administrator')->first();
    if ($ministryAdmin !== null) {
      $ministryAdmin->permissions()->sync(
        Permission::query()->whereIn('slug', [
          'admin.access', 'users.view', 'ministries.manage', 'events.view', 'events.manage', 'events.publish',
          'registrations.view', 'registrations.manage', 'attendance.manage', 'speakers.manage', 'venues.manage', 'cms.manage',
          'cms.pages.view', 'cms.pages.manage', 'cms.pages.publish', 'cms.media.manage',
          'cms.partners.manage', 'cms.testimonials.manage', 'cms.menus.manage', 'cms.seo.manage',
          'blog.manage', 'gallery.manage', 'resources.manage', 'media.manage',
        ])->pluck('id'),
      );
    }

    $leader = Role::query()->where('slug', 'leader')->first();
    if ($leader !== null) {
      $leader->permissions()->sync(
        Permission::query()->whereIn('slug', [
          'members.view', 'interviews.manage', 'events.view', 'events.manage', 'registrations.view',
          'registrations.manage', 'attendance.manage', 'prayer.manage',
        ])->pluck('id'),
      );
    }

    $memberRole = Role::query()->where('slug', 'member')->first();
    if ($memberRole !== null) {
      $memberRole->permissions()->sync(
        Permission::query()->whereIn('slug', ['member.portal'])->pluck('id'),
      );
    }

    $instructor = Role::query()->where('slug', 'instructor')->first();
    if ($instructor !== null) {
      $instructor->permissions()->sync(
        Permission::query()->whereIn('slug', [
          'admin.access',
          'courses.manage', 'courses.publish', 'courses.teach', 'courses.enroll', 'courses.review',
          'assessments.manage', 'certificates.manage', 'course_payments.manage',
        ])->pluck('id'),
      );
    }

    $counsellor = Role::query()->where('slug', 'counsellor')->first();
    if ($counsellor !== null) {
      $counsellor->permissions()->sync(
        Permission::query()->whereIn('slug', [
          'counsellor.portal', 'counselling.view',
        ])->pluck('id'),
      );
    }

    $learner = Role::query()->where('slug', 'learner')->first();
    if ($learner !== null) {
      $learner->permissions()->sync(
        Permission::query()->whereIn('slug', ['learner.portal'])->pluck('id'),
      );
    }
  }
}
