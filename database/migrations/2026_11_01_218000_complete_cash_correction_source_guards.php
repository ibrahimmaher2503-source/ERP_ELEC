<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $definition = DB::selectOne("SELECT pg_get_functiondef('treasury_statement_insert_guard()'::regprocedure) AS definition")->definition;
        $needle = "('customer_collection','transfer_in','supplier_credit_refund')";
        if (substr_count($definition, $needle) !== 1) {
            throw new RuntimeException('Unexpected bank statement guard revision.');
        }
        DB::unprepared(str_replace($needle, "('customer_collection','transfer_in','supplier_credit_refund','cash_correction_in')", $definition));

        $definition = DB::selectOne("SELECT pg_get_functiondef('treasury_cash_correction_guard()'::regprocedure) AS definition")->definition;
        $needle = ' IF EXISTS ((SELECT account_id,sum(debit) dr,sum(credit) cr';
        if (substr_count($definition, $needle) !== 1) {
            throw new RuntimeException('Unexpected cash correction guard revision.');
        }
        $guard = <<<'SQL'
 IF o.kind IN ('customer_collection','supplier_payment') AND EXISTS (
  SELECT 1 FROM rp_settlement_allocations source JOIN rp_open_items i ON i.id=source.open_item_id WHERE source.movement_id=o.id AND
   i.settled_amount<>(coalesce((SELECT sum(a.amount) FROM rp_settlement_allocations a WHERE a.open_item_id=i.id AND NOT EXISTS
    (SELECT 1 FROM rp_cash_reversals r WHERE r.original_movement_id=a.movement_id)),0)
    +coalesce((SELECT sum(l.amount) FROM rp_customer_advance_application_lines l WHERE l.open_item_id=i.id),0))
 ) THEN RAISE EXCEPTION 'Cash correction must restore exact settlement balances'; END IF;
 IF o.kind='customer_collection' AND EXISTS (
  SELECT 1 FROM rp_customer_advances a WHERE a.movement_id=o.id AND
   (EXISTS (SELECT 1 FROM rp_customer_advance_applications p WHERE p.advance_id=a.id)
   OR EXISTS (SELECT 1 FROM rp_customer_advance_refunds f WHERE f.advance_id=a.id AND NOT EXISTS (SELECT 1 FROM rp_cash_reversals r WHERE r.original_movement_id=f.movement_id)))
 ) THEN RAISE EXCEPTION 'Consumed advance prevents original cash correction'; END IF;
 IF o.party_credit_id IS NOT NULL AND EXISTS (
  SELECT 1 FROM rp_party_credits c WHERE c.id=o.party_credit_id AND c.allocated_amount<>
   coalesce((SELECT sum(f.amount) FROM rp_party_credit_refunds f WHERE f.credit_id=c.id AND NOT EXISTS (SELECT 1 FROM rp_cash_reversals r WHERE r.original_movement_id=f.movement_id)),0)
 ) THEN RAISE EXCEPTION 'Cash correction must restore exact party credit'; END IF;
SQL;
        DB::unprepared(str_replace($needle, $guard."\n".$needle, $definition));
    }

    public function down(): void
    {
        throw new RuntimeException('Retain exact source balance and matching protections.');
    }
};
