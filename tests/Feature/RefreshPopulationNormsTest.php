<?php

namespace Tests\Feature;

use App\Jobs\RefreshPopulationNorms;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A norms rebuild that comes up while deploy.sh is running waits until after it (2026-11-28): the
 * deploy's spell import and kit rebuild took the one vCPU and the rebuild timed out.
 */
class RefreshPopulationNormsTest extends TestCase
{
    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = storage_path('app/deploying');
        @unlink($this->marker);
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
        parent::tearDown();
    }

    public function test_during_a_deploy_it_queues_itself_for_later_and_does_not_rebuild(): void
    {
        touch($this->marker);
        Queue::fake();
        Artisan::shouldReceive('call')->never();

        (new RefreshPopulationNorms)->handle();

        Queue::assertPushed(RefreshPopulationNorms::class, fn ($job) => $job->delay !== null);
    }

    public function test_a_marker_left_by_a_deploy_that_died_is_ignored(): void
    {
        touch($this->marker, time() - 3600);
        Queue::fake();
        Artisan::shouldReceive('call')->once()->with('wow:population-refresh');

        (new RefreshPopulationNorms)->handle();

        Queue::assertNothingPushed();
    }

    public function test_with_no_deploy_it_rebuilds(): void
    {
        Queue::fake();
        Artisan::shouldReceive('call')->once()->with('wow:population-refresh');

        (new RefreshPopulationNorms)->handle();

        Queue::assertNothingPushed();
    }
}
