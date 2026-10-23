<?php

namespace App\Modules\Parties\Domain;

use Database\Factories\SupplierNoteFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(SupplierNoteFactory::class)]
class SupplierNote extends Model
{
    use HasFactory;

    protected $fillable = ['supplier_id', 'author_id', 'body'];
}
