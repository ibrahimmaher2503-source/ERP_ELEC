<?php

namespace App\Modules\Parties\Presentation;

use App\Modules\Parties\Domain\SupplierCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

abstract class PartyFormPage extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $recordId = null;

    #[Locked]
    public ?int $version = null;

    public array $form = ['code' => '', 'name' => '', 'name_en' => '', 'trading_name' => '', 'entity_type' => 'organization', 'segment' => '', 'status' => 'active', 'registration_number' => '', 'tax_number' => '', 'country' => '', 'city' => '', 'phone' => '', 'mobile' => '', 'email' => '', 'website' => ''];

    public array $contact = ['name' => '', 'job_title' => '', 'phone' => '', 'mobile' => '', 'email' => '', 'active' => true, 'is_primary' => true];

    public array $billing = ['label' => 'عنوان الفواتير', 'purpose' => 'billing', 'line' => '', 'country' => '', 'city' => '', 'region' => '', 'postal_code' => '', 'is_primary' => true];

    public array $shipping = ['label' => 'عنوان الشحن', 'purpose' => 'shipping', 'line' => '', 'country' => '', 'city' => '', 'region' => '', 'postal_code' => '', 'is_primary' => true];

    public bool $sameAddress = true;

    public string $note = '';

    public array $uploads = [];

    #[Locked]
    public ?int $contactId = null;

    #[Locked]
    public ?int $billingId = null;

    #[Locked]
    public ?int $shippingId = null;

    protected const ENTITY = 'customers';

    protected function profile(): PartyPresentation
    {
        return new PartyPresentation(static::ENTITY);
    }

    public function boot(): void
    {
        $this->profile()->authorize(auth()->id(), 'view');
    }

    protected function initialize(?int $customer): void
    {
        $ui = $this->profile();
        $model = $ui->model;
        if ($ui->supplier) {
            unset($this->form['segment']);
            $this->form['category_id'] = '';
            $this->form['supplier_type'] = '';
            $this->billing['label'] = 'العنوان المسجل';
            $this->billing['purpose'] = 'registered';
            $this->shipping['label'] = 'عنوان المكتب';
            $this->shipping['purpose'] = 'office';
            $this->sameAddress = false;
        }
        $this->recordId = $customer;
        $ui->authorize(auth()->id(), $customer === null ? 'create' : 'update');
        if ($customer !== null) {
            $r = $model::with(['contacts', 'addresses'])->findOrFail($customer);
            $this->version = $r->version;
            foreach (array_keys($this->form) as $key) {
                $this->form[$key] = $r->$key ?? '';
            }
            $c = $r->contacts->firstWhere('is_primary', true);
            if ($c) {
                $this->contact = $c->only(array_keys($this->contact));
                $this->contactId = $c->id;
            }
            foreach (array_combine(['billing', 'shipping'], $ui->formPurposes()) as $state => $purpose) {
                $a = $r->addresses->where('purpose', $purpose)->firstWhere('is_primary', true);
                if ($a) {
                    $this->$state = $a->only(array_keys($this->$state));
                    $key = $state.'Id';
                    $this->$key = $a->id;
                }
            }
            $this->sameAddress = ! $ui->supplier && $this->billing['line'] === $this->shipping['line'];
        }
    }

    public function save()
    {
        $ui = $this->profile();
        $a = $ui;
        $a->authorize(auth()->id(), $this->recordId === null ? 'create' : 'update');
        $stored = [];
        try {
            $this->validate(['uploads' => ['array', 'max:10'], 'uploads.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png']]);
            $r = DB::transaction(function () use ($a, $ui, &$stored) {
                $actions = $ui->actions();
                $r = $actions->save(auth()->id(), $this->form, $this->recordId, $this->version);
                if ($a->allows(auth()->id(), 'contact_manage') && trim($this->contact['name']) !== '') {
                    $actions->saveContact(auth()->id(), $r->id, $this->contact, $this->contactId);
                }
                if ($a->allows(auth()->id(), 'address_manage')) {
                    if (trim($this->billing['line']) !== '') {
                        $actions->saveAddress(auth()->id(), $r->id, array_replace($this->billing, ['purpose' => $ui->formPurposes()[0]]), $this->billingId);
                    }
                    $shipping = ! $ui->supplier && $this->sameAddress ? array_replace($this->billing, ['label' => 'عنوان الشحن', 'purpose' => 'shipping']) : $this->shipping;
                    if (trim($shipping['line']) !== '') {
                        $actions->saveAddress(auth()->id(), $r->id, array_replace($shipping, ['purpose' => $ui->formPurposes()[1]]), $this->shippingId);
                    }
                }
                if (trim($this->note) !== '') {
                    $actions->addNote(auth()->id(), $r->id, $this->note);
                }
                foreach ($this->uploads as $file) {
                    $stored[] = $actions->upload(auth()->id(), $r->id, $file)->path;
                }

                return $r;
            });
        } catch (\Throwable $e) {
            foreach ($stored as $path) {
                Storage::disk('local')->delete($path);
            } throw $e;
        }
        session()->flash('status', 'تم حفظ '.$ui->singular.' بنجاح.');

        return $this->redirectRoute($ui->route('show'), [$ui->supplier ? 'supplier' : 'customer' => $r->id], navigate: true);
    }

    public function render()
    {
        $ui = $this->profile();
        $a = $ui;
        $a->authorize(auth()->id(), $this->recordId === null ? 'create' : 'update');
        $can = fn ($key) => $ui->allows(auth()->id(), $key);
        $model = $ui->model;
        $duplicates = collect();
        if (mb_strlen(trim($this->form['name'])) > 2 || trim($this->form['tax_number']) !== '' || trim($this->form['email']) !== '') {
            $duplicates = $model::where(fn ($q) => $q->where('name', $this->form['name'])->when(trim($this->form['tax_number']) !== '', fn ($q) => $q->orWhere('tax_number', $this->form['tax_number']))->when(trim($this->form['email']) !== '', fn ($q) => $q->orWhere('email', $this->form['email'])))->when($this->recordId, fn ($q) => $q->where('id', '!=', $this->recordId))->limit(5)->get(['id', 'code', 'name']);
        }

        return view('parties.form', ['ui' => $ui, 'categories' => $ui->supplier ? SupplierCategory::orderBy('name')->get() : collect(), 'canStatus' => $can('status'), 'canContacts' => $can('contact_manage'), 'canAddresses' => $can('address_manage'), 'canNotes' => $can('notes_view') && $can('notes_update'), 'canDocuments' => $can('document_manage'), 'duplicates' => $duplicates])->layout('layouts::app', ['title' => $ui->formTitle($this->recordId !== null)]);
    }
}
