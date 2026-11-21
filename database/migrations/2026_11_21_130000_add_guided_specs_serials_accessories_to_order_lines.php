<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['sales_order_lines', 'purchasing_order_lines'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('spec_model', 200)->nullable();
                $t->string('spec_type', 200)->nullable();
                $t->string('spec_ampere', 120)->nullable();
                $t->string('spec_kilo', 120)->nullable();
                $t->text('serial_reference')->nullable();
                $t->text('accessories_note')->nullable();
            });
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_purchasing_order() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE expected_action text;
BEGIN
 IF TG_OP='DELETE' OR OLD.status='approved' THEN RAISE EXCEPTION 'Approved PO immutable; amendment required'; END IF;
 IF (OLD.status<>'draft' OR NEW.status<>'draft') AND (NEW.branch_id,NEW.supplier_id,NEW.created_by,NEW.creation_key,NEW.award_id) IS DISTINCT FROM (OLD.branch_id,OLD.supplier_id,OLD.created_by,OLD.creation_key,OLD.award_id) THEN RAISE EXCEPTION 'PO identity immutable'; END IF;
 IF OLD.status='draft' AND NEW.status='draft' AND (NEW.created_by,NEW.creation_key) IS DISTINCT FROM (OLD.created_by,OLD.creation_key) THEN RAISE EXCEPTION 'PO identity immutable'; END IF;
 IF OLD.status<>'draft' AND (NEW.order_date,NEW.expected_date,NEW.currency,NEW.payment_terms,NEW.notes,NEW.net_amount,NEW.reported_tax) IS DISTINCT FROM (OLD.order_date,OLD.expected_date,OLD.currency,OLD.payment_terms,OLD.notes,OLD.net_amount,OLD.reported_tax) THEN RAISE EXCEPTION 'PO review snapshot immutable'; END IF;
 IF NEW.status<>OLD.status THEN
   expected_action:=CASE WHEN OLD.status='draft' AND NEW.status='submitted' THEN 'submit' WHEN OLD.status='submitted' AND NEW.status='approved' THEN 'approve' WHEN OLD.status='submitted' AND NEW.status='rejected' THEN 'reject' WHEN OLD.status='rejected' AND NEW.status='draft' THEN 'reopen' ELSE NULL END;
   IF expected_action IS NULL OR NEW.version<>OLD.version+1 OR NOT EXISTS(SELECT 1 FROM purchasing_order_reviews WHERE order_id=OLD.id AND document_version=OLD.version AND action=expected_action) THEN RAISE EXCEPTION 'PO transition requires exact version decision'; END IF;
 END IF;
 RETURN NEW;
END $$;
SQL);
    }

    public function down(): void
    {
        foreach (['purchasing_order_lines', 'sales_order_lines'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn([
                    'spec_model',
                    'spec_type',
                    'spec_ampere',
                    'spec_kilo',
                    'serial_reference',
                    'accessories_note',
                ]);
            });
        }
    }
};
