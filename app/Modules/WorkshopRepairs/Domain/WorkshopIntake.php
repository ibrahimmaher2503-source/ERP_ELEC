<?php

namespace App\Modules\WorkshopRepairs\Domain;

use Database\Factories\WorkshopIntakeFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(WorkshopIntakeFactory::class)]
class WorkshopIntake extends Model
{
    use HasFactory;

    protected $table = 'workshop_intakes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['customer_owned' => 'boolean', 'version' => 'integer', 'received_at' => 'immutable_datetime'];
    }
}
