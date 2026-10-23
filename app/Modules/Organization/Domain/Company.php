<?php

namespace App\Modules\Organization\Domain;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(CompanyFactory::class)]
class Company extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'setup_complete' => 'boolean'];
    }
}
