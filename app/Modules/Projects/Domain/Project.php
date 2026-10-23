<?php

namespace App\Modules\Projects\Domain;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(ProjectFactory::class)]
class Project extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function phases()
    {
        return $this->hasMany(ProjectPhase::class);
    }
}
