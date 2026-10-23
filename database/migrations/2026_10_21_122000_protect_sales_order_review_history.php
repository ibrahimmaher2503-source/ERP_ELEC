<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_sales_order_review_history() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Sales Order review history is immutable';
    END IF;
    IF OLD.status <> 'pending' OR NEW.status NOT IN ('withdrawn', 'rejected') THEN
        RAISE EXCEPTION 'Sales Order review decision is immutable';
    END IF;
    IF NEW.sales_order_id IS DISTINCT FROM OLD.sales_order_id
        OR NEW.target_version IS DISTINCT FROM OLD.target_version
        OR NEW.content_hash IS DISTINCT FROM OLD.content_hash
        OR NEW.snapshot IS DISTINCT FROM OLD.snapshot
        OR NEW.requested_by IS DISTINCT FROM OLD.requested_by
        OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
        RAISE EXCEPTION 'Sales Order review snapshot is immutable';
    END IF;
    IF NEW.decided_by IS NULL OR NEW.decided_at IS NULL THEN
        RAISE EXCEPTION 'Sales Order review decision requires attribution';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sales_order_review_history BEFORE UPDATE OR DELETE ON sales_order_reviews
FOR EACH ROW EXECUTE FUNCTION guard_sales_order_review_history();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Sales Order review history protection is forward-only.');
    }
};
