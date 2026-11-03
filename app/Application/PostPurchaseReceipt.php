<?php

namespace App\Application;

use App\Modules\Accounting\Application\Contracts\AccountingApi;
use App\Modules\Inventory\Application\Contracts\InventoryApi;
use App\Modules\Purchasing\Application\Contracts\PurchasingApi;
use Illuminate\Support\Facades\DB;

final class PostPurchaseReceipt
{
    public function execute(int $actor, int $receipt, int $version): void
    {
        DB::transaction(function () use ($actor, $receipt, $version) {
            $source = app(PurchasingApi::class)->prepareReceiptPosting($actor, $receipt);
            if ($source['already_posted'] ?? false) {
                return;
            }
            abort_unless($source['receipt']['version'] === $version, 409, 'Receipt version changed.');
            $stock = app(InventoryApi::class)->receivePurchase($actor, $receipt);
            $journal = app(AccountingApi::class)->postReceipt($actor, $receipt);
            app(PurchasingApi::class)->completeReceiptPosting($actor, $receipt, $version, $stock, $journal);
        });
    }
}
