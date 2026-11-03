<?php

namespace App\Modules\WorkshopRepairs\Domain;

use Database\Factories\WorkshopJobFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(WorkshopJobFactory::class)]
class WorkshopJob extends Model
{
    use HasFactory;

    protected $table = 'workshop_jobs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['required_tests' => 'array', 'version' => 'integer', 'estimated_completion' => 'immutable_datetime'];
    }
}
