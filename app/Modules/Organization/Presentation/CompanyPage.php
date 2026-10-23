<?php

namespace App\Modules\Organization\Presentation;

use App\Modules\Organization\Application\OrganizationAccess;
use App\Modules\Organization\Application\SaveCompany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

class CompanyPage extends Component
{
    use WithFileUploads;

    public $logo;

    public bool $editing = false;

    public array $form = ['code' => '', 'name' => '', 'legal_name' => '', 'commercial_registration' => '', 'tax_registration' => '', 'address' => '', 'phone' => '', 'email' => ''];

    #[Locked]
    public ?int $version = null;

    public function boot(): void
    {
        app(OrganizationAccess::class)->authorize(Auth::user(), 'M01.company_view');
    }

    public function edit(): void
    {
        app(OrganizationAccess::class)->authorize(Auth::user(), 'M01.company_update');
        $company = DB::table('companies')->where('id', 1)->first();
        foreach (array_keys($this->form) as $field) {
            $this->form[$field] = $company?->{$field} ?? '';
        }
        $this->version = $company?->version;
        $this->reset('logo');
        $this->resetValidation();
        $this->editing = true;
    }

    public function cancel(): void
    {
        $this->editing = false;
        $this->reset('logo');
        $this->resetValidation();
    }

    public function save(): void
    {
        try {
            app(SaveCompany::class)->execute(Auth::user(), $this->form, $this->version, $this->logo);
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $key => $messages) {
                $errors[$key === 'logo' || str_starts_with($key, 'form.') ? $key : 'form.'.$key] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
        $this->editing = false;
        $this->reset('logo');
        session()->flash('status', 'تم حفظ بيانات الشركة بنجاح.');
    }

    public function render()
    {
        $company = DB::table('companies')->where('id', 1)->first();

        return view('organization.company', [
            'company' => $company,
            'logoUrl' => $company?->logo_path ? route('organization.company.logo', ['v' => $company->version]) : null,
            'canEdit' => app(OrganizationAccess::class)->allows(Auth::user(), 'M01.company_update'),
        ])->layout('layouts::app', ['title' => 'بيانات الشركة']);
    }
}
