<?php

namespace App\Modules\Parties\Domain;

use Database\Factories\CustomerNoteFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(CustomerNoteFactory::class)]
class CustomerNote extends Model
{
    use HasFactory;

    protected $fillable = ['customer_id', 'author_id', 'body'];
}
