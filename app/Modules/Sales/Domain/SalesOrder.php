<?php

namespace App\Modules\Sales\Domain;

use Database\Factories\SalesOrderFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(SalesOrderFactory::class)]
class SalesOrder extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['branch_id' => 'integer', 'customer_id' => 'integer', 'version' => 'integer', 'branch_snapshot' => 'array', 'customer_snapshot' => 'array', 'calculation' => 'array', 'order_date' => 'immutable_date', 'requested_delivery_date' => 'immutable_date', 'discount_value' => 'decimal:8'];
    }

    public function lines()
    {
        return $this->hasMany(SalesOrderLine::class)->orderBy('position');
    }

    public function reviews()
    {
        return $this->hasMany(SalesOrderReview::class)->orderByDesc('id');
    }

    public function label(): string
    {
        return $this->number ?? 'مسودة '.substr($this->draft_key, 0, 8);
    }

    public function editable(): bool
    {
        return $this->status === 'draft';
    }
}
