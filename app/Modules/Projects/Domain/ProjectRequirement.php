<?php

namespace App\Modules\Projects\Domain;

use Database\Factories\ProjectRequirementFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(ProjectRequirementFactory::class)]
class ProjectRequirement extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'project_requirements';

    protected $guarded = ['id'];
}
