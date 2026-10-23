<?php

namespace App\Modules\Organization\Presentation;

use App\Modules\Organization\Application\PermissionCatalog;
use App\Modules\Organization\Application\RbacGuard;
use App\Modules\Organization\Application\SaveRolePermissions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class RoleDetailsPage extends Component
{
    #[Locked]
    public int $role;

    #[Locked]
    public int $version;

    #[Locked]
    public bool $embedded = false;

    public array $selectedPermissions = [];

    public function boot(): void
    {
        app(RbacGuard::class)->authorize(Auth::user());
    }

    public function mount(int $role, bool $embedded = false): void
    {
        $this->role = $role;
        $this->embedded = $embedded;
        $this->reloadRole();
    }

    protected function reloadRole(): void
    {
        $record = DB::table('roles')->where('id', $this->role)->first();
        abort_unless($record, 404);
        $this->version = $record->version;
        $this->selectedPermissions = $record->code === 'Administrator' ? array_keys(PermissionCatalog::DEFINITIONS) : DB::table('role_permissions')->where('role_id', $this->role)->orderBy('permission_code')->pluck('permission_code')->all();
    }

    public function togglePermission(string $code): void
    {
        abort_unless(isset(PermissionCatalog::DEFINITIONS[$code]), 404);
        $roleCode = DB::table('roles')->where('id', $this->role)->value('code');
        abort_if($roleCode === 'Administrator' || $code === 'M01.security_admin'
            || DB::table('user_roles')->where('user_id', Auth::id())->where('role_id', $this->role)->exists()
            || ($roleCode === 'ManagerReadOnly' && ! str_ends_with($code, '_view') && $code !== 'M04.quotation_history'), 403);
        $this->selectedPermissions = in_array($code, $this->selectedPermissions, true)
            ? array_values(array_diff($this->selectedPermissions, [$code]))
            : [...$this->selectedPermissions, $code];
        $this->resetErrorBag('permissions');
    }

    public function savePermissions(): void
    {
        try {
            app(SaveRolePermissions::class)->execute(Auth::user(), $this->role, $this->selectedPermissions, $this->version);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['permissions' => $e->validator->errors()->all()]);
        }
        $this->reloadRole();
        session()->flash('status', 'تم حفظ المصفوفة. تسري التغييرات على الطلب التالي مباشرة.');
        $this->dispatch('role-matrix-updated');
    }

    public function render()
    {
        $role = DB::table('roles')->where('id', $this->role)->first();
        abort_unless($role, 404);
        $memberIds = DB::table('user_roles')->where('role_id', $this->role)->pluck('user_id');

        return view('organization.role-details', [
            'record' => $role, 'catalog' => DB::table('permission_catalog')->orderBy('code')->get()->groupBy('module'),
            'members' => DB::table('users')->whereIn('id', $memberIds)->get(['id', 'name', 'email', 'active', 'branch_id']),
            'audits' => DB::table('access_audits')->whereRaw("after_snapshot->>'role_id' = ?", [(string) $this->role])->orderByDesc('id')->limit(30)->get(),
            'canEdit' => $role->code !== 'Administrator' && ! $memberIds->contains(Auth::id()),
        ])->layout('layouts::app', ['title' => $role->name]);
    }
}
