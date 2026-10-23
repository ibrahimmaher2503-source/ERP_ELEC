<?php

namespace App\Modules\Inventory\Domain;

use Database\Factories\InventoryDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(InventoryDocumentFactory::class)]
class InventoryDocument extends Model
{
    use HasFactory;

    protected $table = 'inventory_documents';

    protected $guarded = ['id'];
}
