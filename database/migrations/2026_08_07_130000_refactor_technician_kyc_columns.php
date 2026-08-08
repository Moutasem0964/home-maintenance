<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KYC docs collected at technician registration: ID front + back + a personal photo.
 * Rename the single id_doc_url to id_front_url and add id_back_url (selfie_url already
 * holds the personal photo). All remain encrypted at rest and private-disk only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->renameColumn('id_doc_url', 'id_front_url');
        });

        Schema::table('technicians', function (Blueprint $table) {
            $table->text('id_back_url')->nullable()->after('id_front_url');
        });
    }

    public function down(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->dropColumn('id_back_url');
        });

        Schema::table('technicians', function (Blueprint $table) {
            $table->renameColumn('id_front_url', 'id_doc_url');
        });
    }
};
