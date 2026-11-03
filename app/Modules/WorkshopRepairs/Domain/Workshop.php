<?php

namespace App\Modules\WorkshopRepairs\Domain;

use Database\Factories\WorkshopFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(WorkshopFactory::class)]
class Workshop extends Model
{
    use HasFactory;

    protected $table = 'workshops';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['schedule' => 'array', 'active' => 'boolean', 'version' => 'integer', 'capacity' => 'integer'];
    }
}
