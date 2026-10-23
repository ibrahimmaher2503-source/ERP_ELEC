<?php

namespace App\Modules\Organization\Presentation;

use App\Modules\Organization\Application\PermissionAccess;
use App\Modules\Organization\Application\UserAccess;
use App\Modules\Organization\Application\UserSecurityHistory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class UserDetailsPage extends UserManagementPage
{
    protected $listeners = ['user-access-updated' => '$refresh'];

    public function mount(int $user): void
    {
        $this->userId = $user;
    }

    public function render()
    {
        $access = app(UserAccess::class);
        $record = $this->record($this->userId ?? 0);

        $canManageSecurity = app(PermissionAccess::class)->allows(Auth::user(), 'M01.security_admin') && Auth::user()->email_verified_at !== null;

        return view('organization.user-details', [
            'record' => $record, 'allowedBranches' => $access->allowedBranches(Auth::user(), $record),
            'branches' => $access->branches(Auth::user()),
            'roles' => DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->where('ur.user_id', $record->id)->get(['r.id', 'r.code', 'r.name']),
            'canManageSecurity' => $canManageSecurity,
            'activity' => $canManageSecurity ? app(UserSecurityHistory::class)->forUser(Auth::user(), $record->id) : collect(),
            'canEdit' => $access->can(Auth::user(), 'M01.user_update', $record),
            'canStatus' => $access->can(Auth::user(), 'M01.user_status', $record),
            'canReset' => $access->can(Auth::user(), 'M01.user_password_reset', $record),
        ])->layout('layouts::app', ['title' => 'تفاصيل المستخدم']);
    }
}
