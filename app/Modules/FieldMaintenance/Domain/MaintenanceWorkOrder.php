<?php

namespace App\Modules\FieldMaintenance\Domain;

use Database\Factories\MaintenanceWorkOrderFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(MaintenanceWorkOrderFactory::class)]
class MaintenanceWorkOrder extends Model
{
    use HasFactory;

    protected $table = 'maintenance_work_orders';

    protected $guarded = ['id'];

    public $timestamps = true;
}
