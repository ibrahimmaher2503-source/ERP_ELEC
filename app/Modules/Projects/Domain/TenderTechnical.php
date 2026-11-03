<?php

namespace App\Modules\Projects\Domain;

use Database\Factories\TenderTechnicalFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(TenderTechnicalFactory::class)]
class TenderTechnical extends Model
{
    use HasFactory;

    protected $table = 'project_tender_technical';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }

    public function tender()
    {
        return $this->belongsTo(Tender::class);
    }
}
