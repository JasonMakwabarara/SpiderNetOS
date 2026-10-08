<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Retired. 2026_09_28_150000_create_hr_register_tables creates employees
 * with the HR columns and hr_audit_entries. This migration ran first and
 * either altered a table that did not exist yet or collided with that one.
 */
return new class extends Migration
{
    public function up(): void
    {
    }

    public function down(): void
    {
    }
};
