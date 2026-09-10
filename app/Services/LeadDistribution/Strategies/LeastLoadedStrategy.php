<?php

namespace App\Services\LeadDistribution\Strategies;

use App\Services\LeadDistribution\Contracts\LeadDistributionStrategy;
use Illuminate\Support\Collection;
use App\Models\Manager;

class LeastLoadedStrategy implements LeadDistributionStrategy
{
    /**
     * Choose a manager with the least workload.
     * In case of tie, choose the one with the lowest ID.
     *
     * @param Collection<int, Manager> $activeManagers
     * @param array<int, int> $workloads [managerId => workload]
     * @return Manager
     */
    public function chooseManager(Collection $activeManagers, array $workloads): Manager
    {
        // Filter out managers that are not in the activeManagers collection (should not happen, but safe)
        $activeManagers = $activeManagers->keyBy('id');

        // Find the manager with the minimum workload
        $minWorkload = PHP_INT_MAX;
        $selectedManagerId = null;

        foreach ($activeManagers as $manager) {
            $workload = $workloads[$manager->id] ?? 0;

            if ($workload < $minWorkload) {
                $minWorkload = $workload;
                $selectedManagerId = $manager->id;
            } elseif ($workload === $minWorkload && $manager->id < $selectedManagerId) {
                // Tie-break by lower ID
                $selectedManagerId = $manager->id;
            }
        }

        return $activeManagers->get($selectedManagerId);
    }
}
