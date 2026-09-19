<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * conversation_messages.status was varchar(16), but MessageDispatchService
 * writes 'awaiting_operator' (17 chars) for the manual-DM channel. On Postgres
 * every DM draft insert therefore failed with
 *
 *   SQLSTATE[22001]: value too long for type character varying(16)
 *
 * so the partner DM queue has never worked outside sqlite (which does not
 * enforce varchar lengths). Widen the column to 32.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE conversation_messages ALTER COLUMN status TYPE varchar(32)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("UPDATE conversation_messages SET status = 'queued' WHERE length(status) > 16");
            DB::statement('ALTER TABLE conversation_messages ALTER COLUMN status TYPE varchar(16)');
        }
    }
};
