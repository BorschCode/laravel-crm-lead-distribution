<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lead_assignment_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')
                ->constrained()
                ->onDelete('cascade');
            $table->foreignId('manager_id')
                ->constrained()
                ->onDelete('cascade');
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamps(); // Laravel's created_at/updated_at

            // Indexes
            $table->index(['lead_id', 'assigned_at']); // for audit trail by lead
            $table->index(['manager_id', 'assigned_at']); // for workload history
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_assignment_history');
    }
};
