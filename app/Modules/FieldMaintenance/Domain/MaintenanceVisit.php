<?php

namespace App\Modules\FieldMaintenance\Domain;

use Database\Factories\MaintenanceVisitFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(MaintenanceVisitFactory::class)]
class MaintenanceVisit extends Model
{
    use HasFactory;

    protected $table = 'maintenance_visits';

    protected $guarded = ['id'];

    public $timestamps = false;
}
