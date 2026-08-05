<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('personal_access_tokens')) {
            $this->createUuidTokenTable();

            return;
        }

        if ($this->tokenableIdIsUuid()) {
            return;
        }

        Schema::dropIfExists('personal_access_tokens');
        $this->createUuidTokenTable();
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    private function createUuidTokenTable(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuidMorphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    private function tokenableIdIsUuid(): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver !== 'pgsql') {
            return false;
        }

        $column = DB::selectOne("
            SELECT data_type
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND table_name = 'personal_access_tokens'
              AND column_name = 'tokenable_id'
            LIMIT 1
        ");

        return ($column->data_type ?? null) === 'uuid';
    }
};
