<?php

namespace App\Modules\Projects\Domain;

use Database\Factories\TenderFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(TenderFactory::class)]
class Tender extends Model
{
    use HasFactory;

    protected $table = 'project_tenders';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['deadlines' => 'array', 'version' => 'integer'];
    }

    public function technicalRevisions()
    {
        return $this->hasMany(TenderTechnical::class);
    }
}
