<?php

namespace App\Modules\Parties\Domain;

use Database\Factories\CustomerDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(CustomerDocumentFactory::class)]
class CustomerDocument extends Model
{
    use HasFactory;

    protected $fillable = ['customer_id', 'name', 'path', 'mime', 'size'];
}
