<?php

namespace App\Services\LeadDistribution;

use App\Services\LeadDistribution\Contracts\LeadDistributionStrategy;
use App\Models\Lead;
use App\Models\Manager;
use App\Models\LeadAssignmentHistory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Enums\LeadStatus;

class LeadDistributionService
{
    /**
     * The strategy to use for choosing managers.
     */
    protected LeadDistributionStrategy $strategy;

    /**
     * Constructor.
     *
     * @param LeadDistributionStrategy $strategy
     */
    public function __construct(LeadDistributionStrategy $strategy)
    {
        $this->strategy = $strategy;
    }

    /**
     * Distribute NEW leads to active managers.
     *
     * @return int Number of leads distributed
     */
    public function distribute(): int
    {
        // Start a transaction to ensure atomicity of the entire distribution process
        return DB::transaction(function () {
            // Step 1: Get all active managers and compute their initial workload (NEW + IN_PROGRESS)
            $activeManagers = Manager::where('is_active', true)->get();

            // Compute workload for each active manager: NEW + IN_PROGRESS leads
            $workloads = [];
            foreach ($activeManagers as $manager) {
                $workload = Lead::where('manager_id', $manager->id)
                    ->whereIn('status', [LeadStatus::NEW, LeadStatus::IN_PROGRESS])
                    ->count();
                $workloads[$manager->id] = $workload;
            }

            // Step 2: Get all NEW leads that are not yet assigned (manager_id is null)
            // We lock these rows to prevent concurrent processes from selecting the same leads
            $newLeads = Lead::where('status', LeadStatus::NEW)
                ->whereNull('manager_id')
                ->lockForUpdate() // This uses FOR UPDATE in PostgreSQL
                ->get();

            // If there are no new leads, return early
            if ($newLeads->isEmpty()) {
                return 0;
            }

            // If there are no active managers, we cannot assign leads
            if ($activeManagers->isEmpty()) {
                // We could throw an exception, but the requirement says to handle gracefully.
                // We'll just not assign any leads and return 0.
                return 0;
            }

            $distributedCount = 0;

            // Step 3: Process each lead
            foreach ($newLeads as $lead) {
                // Choose a manager based on current workloads
                $manager = $this->strategy->chooseManager($activeManagers, $workloads);

                // Assign the lead to the manager
                $lead->manager_id = $manager->id;
                $lead->status = LeadStatus::IN_PROGRESS;
                $lead->save();

                // Create assignment history
                LeadAssignmentHistory::create([
                    'lead_id' => $lead->id,
                    'manager_id' => $manager->id,
                    // assigned_at will be set automatically by the database (default CURRENT_TIMESTAMP)
                ]);

                // Update the workload for the chosen manager (increment by 1)
                $workloads[$manager->id] = ($workloads[$manager->id] ?? 0) + 1;

                $distributedCount++;
            }

            return $distributedCount;
        });
    }
}
