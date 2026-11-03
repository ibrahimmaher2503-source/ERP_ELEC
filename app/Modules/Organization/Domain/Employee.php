<?php

namespace App\Modules\Organization\Domain;

use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(EmployeeFactory::class)]
class Employee extends Model
{
    use HasFactory;

    protected $table = 'organization_employees';

    protected $fillable = ['code', 'name', 'branch_id', 'user_id', 'starts_on', 'ends_on', 'active', 'job_title'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'starts_on' => 'immutable_date', 'ends_on' => 'immutable_date'];
    }
}
