<?php

namespace Tests\Unit\LeadDistribution;

use App\Models\Manager;
use App\Services\LeadDistribution\Contracts\LeadDistributionStrategy;
use App\Services\LeadDistribution\Strategies\LeastLoadedStrategy;
use Illuminate\Support\Collection;
use Tests\TestCase;

class LeastLoadedStrategyTest extends TestCase
{
    /** @test */
    public function it_chooses_the_manager_with_the_least_workload()
    {
        $strategy = new LeastLoadedStrategy();

        // Create three managers with different workloads
        $managerA = Manager::factory()->make(['id' => 1]);
        $managerB = Manager::factory()->make(['id' => 2]);
        $managerC = Manager::factory()->make(['id' => 3]);

        $activeManagers = new Collection([$managerA, $managerB, $managerC]);

        // Workloads: A=5, B=2, C=3
        $workloads = [
            1 => 5,
            2 => 2,
            3 => 3,
        ];

        $chosen = $strategy->chooseManager($activeManagers, $workloads);

        $this->assertEquals($managerB->id, $chosen->id);
    }

    /** @test */
    public function it_breaks_ties_by_lowest_manager_id()
    {
        $strategy = new LeastLoadedStrategy();

        $managerA = Manager::factory()->make(['id' => 1]);
        $managerB = Manager::factory()->make(['id' => 2]);
        $managerC = Manager::factory()->make(['id' => 3]);

        $activeManagers = new Collection([$managerA, $managerB, $managerC]);

        // Workloads: A=2, B=2, C=3
        $workloads = [
            1 => 2,
            2 => 2,
            3 => 3,
        ];

        $chosen = $strategy->chooseManager($activeManagers, $workloads);

        // Should choose manager A because it has the lowest ID among those with workload 2
        $this->assertEquals($managerA->id, $chosen->id);
    }

    /** @test */
    public function it_throws_an_exception_if_no_active_managers()
    {
        $this->expectException(\Exception::class);

        $strategy = new LeastLoadedStrategy();

        $activeManagers = new Collection();
        $workloads = [];

        $strategy->chooseManager($activeManagers, $workloads);
    }
}
