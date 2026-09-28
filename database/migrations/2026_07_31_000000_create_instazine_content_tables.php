<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the content tables for fresh installs.
     *
     * Existing installs created these tables by hand, so each table is only
     * created when it is missing.
     */
    public function up(): void
    {
        if (! Schema::hasTable('Random_text')) {
            Schema::create('Random_text', function (Blueprint $table) {
                $table->id('R_id');
                $table->string('Type', 16);
                $table->string('Random_text', 255);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('Article')) {
            Schema::create('Article', function (Blueprint $table) {
                $table->id('A_id');
                $table->dateTime('Date');
                $table->unsignedBigInteger('Author');
                $table->string('Headline', 64)->nullable();
                $table->text('Pic')->nullable();
                $table->boolean('Approved')->default(false);
                $table->text('Text')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('Tracking')) {
            Schema::create('Tracking', function (Blueprint $table) {
                $table->id('T_id');
                $table->unsignedBigInteger('A_id')->nullable()->index();
                $table->unsignedBigInteger('R_id')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('Health')) {
            Schema::create('Health', function (Blueprint $table) {
                $table->id('H_id');
                $table->dateTime('Date')->nullable();
                $table->text('Message')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Leave the content tables in place so a rollback cannot drop articles
     * that existed before this migration.
     */
    public function down(): void
    {
    }
};
