<?php

namespace App\Modules\Sales\Domain;

use Database\Factories\SalesOrderReviewFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(SalesOrderReviewFactory::class)]
class SalesOrderReview extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'target_version' => 'integer', 'requested_by' => 'integer', 'decided_by' => 'integer', 'decided_at' => 'immutable_datetime'];
    }
}
