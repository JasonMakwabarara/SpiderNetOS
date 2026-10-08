<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Retired. Canonical migrations already own these tables:
 * document_sequences and vendors (August), the HR register (150000),
 * procurement (160000), procure-to-pay finance (180000), and credit notes
 * (2026_10_06). Creating them here made migrate fail with duplicate relations.
 */
return new class extends Migration
{
    public function up(): void {}

    public function down(): void {}
};
