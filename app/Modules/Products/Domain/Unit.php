<?php

namespace App\Modules\Products\Domain;

use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(UnitFactory::class)]
class Unit extends Model
{
    use HasFactory;

    protected $table = 'units_of_measure';

    protected $guarded = ['id'];
}
