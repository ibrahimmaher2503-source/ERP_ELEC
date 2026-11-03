<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE rp_open_items ALTER COLUMN source_number TYPE varchar(160)');
        // Derive missing open items from genuine immutable opening sources; never post another opening journal.
        DB::statement(<<<'SQL'
INSERT INTO rp_open_items(branch_id,party_type,party_id,source_type,source_id,source_number,document_date,due_date,original_amount,created_at)
SELECT p.branch_id,'supplier',p.supplier_id,'opening_payable',p.id,p.reference,p.opening_date,p.opening_date,p.amount,p.created_at
FROM payable_opening_items p
WHERE p.amount>0 AND NOT EXISTS(SELECT 1 FROM rp_open_items o WHERE o.source_type='opening_payable' AND o.source_id=p.id)
ON CONFLICT(source_type,source_id) DO NOTHING
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Opening source links may have settlements and require forward corrections.');
    }
};
