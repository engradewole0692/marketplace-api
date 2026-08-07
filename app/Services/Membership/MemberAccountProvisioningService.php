<?php

declare(strict_types=1);

namespace App\Services\Membership;

use App\Contracts\ServiceContract;
use App\Enums\MemberAuditEventType;
use App\Enums\MemberTimelineEventType;
use App\Enums\UserStatus;
use App\Models\Member;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Provisions a login account when membership is approved.
 *
 * @return array{user: User, temporary_password: string, activation_token: string, username: string}
 */
final class MemberAccountProvisioningService implements ServiceContract
{
  public function __construct(
    private readonly MemberAuditService $auditService,
    private readonly MemberNotificationQueueService $notificationQueueService,
    private readonly MemberCredentialPasswordService $credentialPasswordService,
  ) {}

  /**
   * @return array{user: User, temporary_password: string, activation_token: string, username: string}|null
   */
  public function provisionOnApproval(Member $member, User $actor): ?array
  {
    if ($member->email === null || $member->email === '') {
      return null;
    }

    $temporaryPassword = $this->credentialPasswordService->generate($member);
    $activationToken = Str::random(64);
    // Spec: username is the applicant email.
    $username = (string) $member->email;

    // Prefer the linked application user (visitor upgrade), then email match, else create.
    $user = $member->user_id !== null ? $member->user : null;
    if ($user === null) {
      $user = User::query()->where('email', $member->email)->first();
    }

    $alreadyHadPortalAccess = $user !== null && $user->hasPermission('member.portal');
    $linkedExistingAccount = false;

    if ($user === null) {
      $user = User::query()->create([
        'first_name' => $member->first_name,
        'last_name' => $member->last_name,
        'display_name' => $member->display_name,
        'email' => $member->email,
        // Cast `hashed` hashes once — do not Hash::make here.
        'password' => $temporaryPassword,
        'username' => $username,
        'phone' => $member->phone,
        'status' => UserStatus::Active,
        'must_change_password' => true,
        'activation_token' => $activationToken,
        'activated_at' => null,
      ]);
    } else {
      // Visitor/learner upgrade: keep the same login credentials — do not force a new password.
      $linkedExistingAccount = true;
      $temporaryPassword = '';
      $user->forceFill([
        'username' => $user->username ?: $username,
        'status' => UserStatus::Active,
        'first_name' => $user->first_name ?: $member->first_name,
        'last_name' => $user->last_name ?: $member->last_name,
        'display_name' => $user->display_name ?: $member->display_name,
        'phone' => $user->phone ?: $member->phone,
      ])->save();
      $username = (string) ($user->username ?: $username);
      $activationToken = (string) ($user->activation_token ?: $activationToken);
    }

    $memberRole = Role::query()->where('slug', 'member')->first();
    if ($memberRole !== null) {
      $user->roles()->syncWithoutDetaching([$memberRole->id]);
    }

    // Ensure portal + learner access for approved members.
    foreach (['member.portal', 'learner.portal'] as $slug) {
      $permission = Permission::query()->where('slug', $slug)->first();
      if ($permission !== null) {
        $user->permissions()->syncWithoutDetaching([$permission->id]);
        if ($memberRole !== null) {
          $memberRole->permissions()->syncWithoutDetaching([$permission->id]);
        }
      }
    }

    $member->user_id = $user->id;
    $member->updated_by = $actor->id;
    $member->save();

    $frontend = rtrim((string) config('app-frontend.url', config('app.url')), '/');
    $loginUrl = $frontend.'/portal/login?redirect=/portal/dashboard';
    $learnUrl = $frontend.'/learn/login';
    $activationUrl = $linkedExistingAccount
      ? $loginUrl
      : $frontend.'/portal/login?activation_token='.$activationToken;

    $credentialPayload = [
      'email' => $member->email,
      'username' => $username,
      'activation_token' => $activationToken,
      'activation_url' => $activationUrl,
      'login_url' => $loginUrl,
      'learn_login_url' => $learnUrl,
      'support_contact' => config('mail.from.address'),
      'must_change_password' => ! $linkedExistingAccount,
      'upgraded_existing_account' => $linkedExistingAccount,
    ];

    if (! $linkedExistingAccount) {
      $credentialPayload['temporary_password'] = $temporaryPassword;
    }

    if (! $alreadyHadPortalAccess) {
      $this->notificationQueueService->queueMany($member, [
        [
          'channel' => 'email',
          'template' => $linkedExistingAccount ? 'member_account_upgraded' : 'member_account_created',
          'payload' => $credentialPayload,
        ],
        [
          'channel' => 'in_app',
          'template' => $linkedExistingAccount ? 'member_account_upgraded' : 'member_account_created',
          'payload' => [
            'user_id' => $user->id,
            'username' => $username,
            'upgraded_existing_account' => $linkedExistingAccount,
          ],
        ],
      ]);

      $this->auditService->recordWithTimeline(
        MemberAuditEventType::CredentialsSent,
        MemberTimelineEventType::CredentialsSent,
        $member,
        $linkedExistingAccount
          ? 'Existing visitor account upgraded to member access (password preserved).'
          : 'Member login credentials generated and queued for delivery.',
        $actor,
        null,
        ['user_id' => $user->id, 'username' => $username, 'upgraded_existing_account' => $linkedExistingAccount],
      );
    }

    $this->auditService->recordWithTimeline(
      MemberAuditEventType::MemberActivated,
      MemberTimelineEventType::AccountCreated,
      $member,
      $alreadyHadPortalAccess
        ? 'Member login account already provisioned — permissions verified on approval.'
        : 'Member login account provisioned on approval.',
      $actor,
      null,
      ['user_id' => $user->id, 'username' => $username, 'upgraded_existing_account' => $linkedExistingAccount],
    );

    return [
      'user' => $user->fresh(),
      'temporary_password' => $temporaryPassword,
      'activation_token' => $activationToken,
      'username' => $username,
    ];
  }
}
