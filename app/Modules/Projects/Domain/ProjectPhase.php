<?php

namespace App\Modules\Projects\Domain;

use Database\Factories\ProjectPhaseFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(ProjectPhaseFactory::class)]
class ProjectPhase extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'project_phases';

    protected $guarded = ['id'];
}
