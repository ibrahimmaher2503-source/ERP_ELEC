<?php

namespace App\Modules\FieldMaintenance\Domain;

use Database\Factories\MaintenanceRequestFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(MaintenanceRequestFactory::class)]
class MaintenanceRequest extends Model
{
    use HasFactory;

    protected $table = 'maintenance_requests';

    protected $guarded = ['id'];

    public $timestamps = true;
}
