<?php

namespace App\Modules\Treasury\Domain;

use Database\Factories\TreasuryAccountFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(TreasuryAccountFactory::class)]
final class TreasuryAccount extends Model
{
    use HasFactory;

    protected $table = 'treasury_accounts';

    protected $guarded = ['id'];
}
