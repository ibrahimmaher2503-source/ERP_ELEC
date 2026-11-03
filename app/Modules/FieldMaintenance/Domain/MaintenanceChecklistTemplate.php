<?php

namespace App\Modules\FieldMaintenance\Domain;

use Database\Factories\MaintenanceChecklistTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(MaintenanceChecklistTemplateFactory::class)]
class MaintenanceChecklistTemplate extends Model
{
    use HasFactory;

    protected $table = 'maintenance_checklist_templates';

    protected $guarded = ['id'];

    public $timestamps = true;
}
