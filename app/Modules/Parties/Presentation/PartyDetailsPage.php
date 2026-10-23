<?php

namespace App\Modules\Parties\Presentation;

use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

abstract class PartyDetailsPage extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $recordId;

    #[Url]
    public string $tab = 'overview';

    #[Locked]
    public ?int $contactId = null;

    #[Locked]
    public ?int $addressId = null;

    public bool $contactOpen = false;

    public bool $addressOpen = false;

    public array $contact = ['name' => '', 'job_title' => '', 'phone' => '', 'mobile' => '', 'email' => '', 'active' => true, 'is_primary' => false];

    public array $address = ['label' => '', 'purpose' => 'other', 'line' => '', 'country' => '', 'city' => '', 'region' => '', 'postal_code' => '', 'is_primary' => false];

    public string $note = '';

    public $upload;

    protected const ENTITY = 'customers';

    protected function profile(): PartyPresentation
    {
        return new PartyPresentation(static::ENTITY);
    }

    public function boot(): void
    {
        $this->profile()->authorize(auth()->id(), 'view');
    }

    protected function initialize(int $customer): void
    {
        $this->recordId = $customer;
        $model = $this->profile()->model;
        $model::findOrFail($customer);
    }

    public function editContact(?int $id = null): void
    {
        $this->profile()->authorize(auth()->id(), 'contact_manage');
        $this->resetValidation();
        $this->reset('contact');
        $this->contactId = $id;
        if ($id) {
            $this->contact = ($this->profile()->contactModel)::where($this->profile()->foreignKey, $this->recordId)->findOrFail($id)->only(array_keys($this->contact));
        } $this->contactOpen = true;
    }

    public function saveContact(): void
    {
        $this->profile()->actions()->saveContact(auth()->id(), $this->recordId, $this->contact, $this->contactId);
        $this->contactOpen = false;
    }

    public function editAddress(?int $id = null): void
    {
        $this->profile()->authorize(auth()->id(), 'address_manage');
        $this->resetValidation();
        $this->reset('address');
        $this->addressId = $id;
        if ($id) {
            $this->address = ($this->profile()->addressModel)::where($this->profile()->foreignKey, $this->recordId)->findOrFail($id)->only(array_keys($this->address));
        } $this->addressOpen = true;
    }

    public function saveAddress(): void
    {
        $this->profile()->actions()->saveAddress(auth()->id(), $this->recordId, $this->address, $this->addressId);
        $this->addressOpen = false;
    }

    public function addNote(): void
    {
        $this->profile()->actions()->addNote(auth()->id(), $this->recordId, $this->note);
        $this->note = '';
    }

    public function addDocument(): void
    {
        $this->validate(['upload' => ['required', 'file', 'max:10240']]);
        $this->profile()->actions()->upload(auth()->id(), $this->recordId, $this->upload);
        $this->upload->delete();
        $this->upload = null;
    }

    public function changeStatus(string $status, int $version): void
    {
        $this->profile()->actions()->changeStatus(auth()->id(), $this->recordId, $version, $status);
    }

    public function render()
    {
        $ui = $this->profile();
        $can = fn ($key) => $ui->allows(auth()->id(), $key);
        $model = $ui->model;
        $r = $model::with(['contacts', 'addresses'])->findOrFail($this->recordId);
        if ($ui->supplier) {
            $r->load('category');
        }

        return view('parties.details', ['ui' => $ui, 'record' => $r, 'canUpdate' => $can('update'), 'canStatus' => $can('status'), 'canContacts' => $can('contact_manage'), 'canAddresses' => $can('address_manage'), 'canDocumentView' => $can('document_view'), 'canDocuments' => $can('document_manage'), 'canNotes' => $can('notes_view'), 'canAddNote' => $can('notes_view') && $can('notes_update'), 'documents' => $can('document_view') ? $r->documents()->latest()->get() : collect(), 'notes' => $can('notes_view') ? $r->notes()->latest()->limit(30)->get() : collect(), 'history' => $can('history_view') ? $ui->history($r->id) : collect()])->layout('layouts::app', ['title' => 'تفاصيل '.$ui->singular]);
    }
}
