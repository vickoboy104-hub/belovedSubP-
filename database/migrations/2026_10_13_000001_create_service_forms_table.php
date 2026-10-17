<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Services a customer orders through a form, stored as data instead of code.
 *
 * Prices, markup and turnaround deliberately do NOT live here: they stay in the
 * settings table under the same keys the customer page already reads, so one
 * number has one home and the existing settings page keeps working. This table
 * holds the shape of the request itself - which dropdowns exist, what each
 * choice reveals, and which price key the answered choice is charged at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_forms', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('group', 20)->default('other');
            $table->text('summary')->nullable();
            $table->string('icon', 16)->nullable();
            $table->string('cta')->nullable();
            $table->json('definition');
            // hub: the service has its own tile on the identity page.
            // hidden: it is reached from another service's page only.
            // wired: a live API answers it, so the generic form must not offer a
            // second way into the same job.
            $table->string('visibility', 12)->default('hub');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_forms');
    }
};
