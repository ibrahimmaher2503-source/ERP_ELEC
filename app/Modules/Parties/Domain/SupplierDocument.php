<?php

namespace App\Modules\Parties\Domain;

use Database\Factories\SupplierDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(SupplierDocumentFactory::class)]
class SupplierDocument extends Model
{
    use HasFactory;

    protected $fillable = ['supplier_id', 'name', 'path', 'mime', 'size'];
}
