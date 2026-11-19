<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who last saved an agent file: a person's name, or an agent's model and effort.
     */
    public function up(): void
    {
        Schema::table('agent_files', function (Blueprint $table) {
            $table->string('updated_by')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('agent_files', fn(Blueprint $table) => $table->dropColumn('updated_by'));
    }
};
