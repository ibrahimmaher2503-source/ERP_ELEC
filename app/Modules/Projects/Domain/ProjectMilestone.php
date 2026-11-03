<?php

namespace App\Modules\Projects\Domain;

use Database\Factories\ProjectMilestoneFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(ProjectMilestoneFactory::class)]
class ProjectMilestone extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $guarded = ['id'];
}
