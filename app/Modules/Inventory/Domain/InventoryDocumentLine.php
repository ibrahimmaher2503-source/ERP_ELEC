<?php

namespace App\Modules\Inventory\Domain;

use Database\Factories\InventoryDocumentLineFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(InventoryDocumentLineFactory::class)]
class InventoryDocumentLine extends Model
{
    use HasFactory;

    protected $table = 'inventory_document_lines';

    protected $guarded = ['id'];

    public $timestamps = false;
}
