<?php

namespace App\Modules\Projects\Domain;

use Database\Factories\ProjectChangeFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(ProjectChangeFactory::class)]
class ProjectChange extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'project_changes';

    protected $guarded = ['id'];
}
