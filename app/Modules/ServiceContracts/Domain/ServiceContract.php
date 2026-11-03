<?php

namespace App\Modules\ServiceContracts\Domain;

use Database\Factories\ServiceContractFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(ServiceContractFactory::class)]
class ServiceContract extends Model
{
    use HasFactory;

    protected $guarded = ['id'];
}
