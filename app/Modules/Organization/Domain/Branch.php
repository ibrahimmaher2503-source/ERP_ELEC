<?php

namespace App\Modules\Organization\Domain;

use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(BranchFactory::class)]
class Branch extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'active' => 'boolean'];
    }
}
