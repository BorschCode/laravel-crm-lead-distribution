<?php

namespace App\Services\LeadDistribution\Contracts;

use App\Models\Manager;
use Illuminate\Support\Collection;

interface LeadDistributionStrategy
{
    /**
     * Choose a manager for distribution based on current workloads.
     *
     * @param  Collection<int, Manager>  $activeManagers
     * @param  array<int, int>  $workloads  [managerId => workload]
     */
    public function chooseManager(Collection $activeManagers, array $workloads): Manager;
}
