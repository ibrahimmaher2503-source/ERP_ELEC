<?php

namespace App\Modules\Projects\Domain;

use Database\Factories\ProjectTaskFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(ProjectTaskFactory::class)]
class ProjectTask extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'project_tasks';

    protected $guarded = ['id'];
}
