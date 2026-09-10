<?php

use App\Enums\LeadStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manager_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->enum('status', array_column(LeadStatus::cases(), 'value'))
                ->default(LeadStatus::NEW->value);
            $table->timestamps();

            // Indexes
            $table->index(['manager_id', 'status']); // for workload calculation
            $table->index('status'); // for filtering by status
        });

        // Partial index for NEW leads (PostgreSQL specific)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX idx_leads_new ON leads (manager_id) WHERE status = \'NEW\'');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
