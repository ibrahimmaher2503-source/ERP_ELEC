<?php

namespace App\Modules\Organization\Presentation;

use App\Modules\Organization\Application\ChangeUserStatus;
use App\Modules\Organization\Application\PermissionAccess;
use App\Modules\Organization\Application\ResetManagedUserPassword;
use App\Modules\Organization\Application\SaveUser;
use App\Modules\Organization\Application\UserAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

abstract class UserManagementPage extends Component
{
    #[Locked]
    public ?int $userId = null;

    public bool $editing = false;

    #[Locked]
    public ?int $editingId = null;

    #[Locked]
    public ?int $version = null;

    public array $form = ['name' => '', 'email' => '', 'gender' => '', 'branch_id' => '', 'password' => '', 'password_confirmation' => ''];

    public bool $confirmingStatus = false;

    public string $statusName = '';

    #[Locked]
    public ?int $statusId = null;

    #[Locked]
    public ?int $statusVersion = null;

    #[Locked]
    public bool $nextActive = false;

    public bool $resetPasswordModal = false;

    public string $password = '';

    public string $password_confirmation = '';

    #[Locked]
    public ?int $passwordVersion = null;

    public function boot(): void
    {
        app(UserAccess::class)->authorizeDirectory(Auth::user());
    }

    protected function record(int $id): object
    {
        $record = app(UserAccess::class)->query(Auth::user())->where('u.id', $id)->first();
        abort_unless($record, 404);

        return $record;
    }

    public function create(): void
    {
        app(UserAccess::class)->authorize(Auth::user(), 'M01.user_create');
        $this->reset('form', 'editingId', 'version');
        $this->resetValidation();
        $this->editing = true;
    }

    public function edit(?int $id = null): void
    {
        $record = $this->record($id ?? $this->userId ?? 0);
        app(UserAccess::class)->authorize(Auth::user(), 'M01.user_update', $record);
        $this->editingId = $record->id;
        $this->version = $record->version;
        $this->form = ['name' => $record->name, 'email' => $record->email, 'gender' => $record->gender ?? '', 'branch_id' => $record->branch_id ?? '', 'password' => '', 'password_confirmation' => ''];
        $this->resetValidation();
        $this->editing = true;
    }

    public function cancel(): void
    {
        $this->editing = false;
        $this->reset('form');
        $this->resetValidation();
    }

    public function updatedEditing(bool $value): void
    {
        if (! $value) {
            $this->reset('form');
        }
    }

    public function save(): void
    {
        $input = $this->form;
        if ($this->editingId) {
            unset($input['password'], $input['password_confirmation']);
        }
        try {
            $saved = app(SaveUser::class)->execute(Auth::user(), $input, $this->editingId, $this->version);
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $key => $messages) {
                $errors['form.'.$key] = $messages;
            }
            throw ValidationException::withMessages($errors);
        } finally {
            $this->form['password'] = '';
            $this->form['password_confirmation'] = '';
        }
        $this->editing = false;
        session()->flash('status', 'تم حفظ حساب المستخدم. الحساب الجديد لا يحصل على صلاحيات أعمال تلقائيًا.');
        if (! $this->editingId
            && app(PermissionAccess::class)->allows(Auth::user(), 'M01.security_admin')
            && app(UserAccess::class)->can(Auth::user(), 'M01.user_view', $saved)) {
            $this->redirect(route('organization.users.show', $saved->id).'#user-access', navigate: true);
        }
    }

    public function confirmStatus(?int $id = null): void
    {
        $record = $this->record($id ?? $this->userId ?? 0);
        app(UserAccess::class)->authorize(Auth::user(), 'M01.user_status', $record);
        $this->statusId = $record->id;
        $this->statusVersion = $record->version;
        $this->statusName = $record->name;
        $this->nextActive = ! $record->active;
        $this->resetValidation();
        $this->confirmingStatus = true;
    }

    public function changeStatus(): void
    {
        abort_unless($this->statusId && $this->statusVersion, 422);
        app(ChangeUserStatus::class)->execute(Auth::user(), $this->statusId, $this->nextActive, $this->statusVersion);
        $this->cancelStatus();
        session()->flash('status', 'تم تحديث حالة الحساب وإبطال جلساته السابقة.');
    }

    public function cancelStatus(): void
    {
        $this->confirmingStatus = false;
        $this->statusId = null;
        $this->resetValidation();
    }

    public function openPasswordReset(): void
    {
        $record = $this->record($this->userId ?? 0);
        app(UserAccess::class)->authorize(Auth::user(), 'M01.user_password_reset', $record);
        $this->passwordVersion = $record->version;
        $this->reset('password', 'password_confirmation');
        $this->resetValidation();
        $this->resetPasswordModal = true;
    }

    public function resetPassword(): void
    {
        abort_unless($this->userId && $this->passwordVersion, 422);
        try {
            app(ResetManagedUserPassword::class)->execute(Auth::user(), $this->userId, ['password' => $this->password, 'password_confirmation' => $this->password_confirmation], $this->passwordVersion);
        } finally {
            $this->reset('password', 'password_confirmation');
        }
        $this->resetPasswordModal = false;
        session()->flash('status', 'تم تعيين كلمة المرور الجديدة وإبطال الجلسات السابقة.');
    }

    public function cancelPasswordReset(): void
    {
        $this->resetPasswordModal = false;
        $this->reset('password', 'password_confirmation');
        $this->resetValidation();
    }

    public function updatedResetPasswordModal(bool $value): void
    {
        if (! $value) {
            $this->reset('password', 'password_confirmation');
        }
    }
}
