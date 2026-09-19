<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerMetric;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Billed transfer is read from a very large number of rows.
 *
 * Samples are taken every five minutes, so one container produces around nine
 * thousand rows a month and a reseller's dashboard asks about all of theirs at
 * once. Reading those as Eloquent models costs roughly 2 KB each, which put a
 * month of a handful of containers past the 128 MB request limit and answered
 * the dashboard with a fatal. The reader streams instead.
 */
class ContainerMetricTransferReadingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<int>
     */
    private function seedSamples(int $deployments, int $samplesEach): array
    {
        $ids = [];
        $rows = [];
        $now = now();

        for ($d = 0; $d < $deployments; $d++) {
            $service = Service::factory()->create(['user_id' => User::factory()->customer()->create()->id]);
            $deployment = ContainerDeployment::factory()->create([
                'service_id' => $service->id,
                'container_name' => 'c'.uniqid(),
            ]);
            $ids[] = (int) $deployment->id;

            for ($i = 0; $i < $samplesEach; $i++) {
                $rows[] = [
                    'container_deployment_id' => $deployment->id,
                    'sample_type' => ContainerMetric::SAMPLE_USAGE,
                    'net_io_rx_bytes' => $i * 1000,
                    'net_io_tx_bytes' => $i * 500,
                    'recorded_at' => $now->copy()->subMinutes(5 * ($samplesEach - $i)),
                ];
            }
        }

        foreach (array_chunk($rows, 2000) as $chunk) {
            DB::table('container_metrics')->insert($chunk);
        }

        return $ids;
    }

    #[Test]
    public function reading_a_month_of_samples_does_not_scale_memory_with_the_row_count(): void
    {
        $ids = $this->seedSamples(deployments: 12, samplesEach: 1500);

        gc_collect_cycles();
        $before = memory_get_usage();

        $totals = ContainerMetric::transferBytesForDeployments($ids, now()->subMonth(), now());

        $usedMb = (memory_get_usage() - $before) / 1048576;

        $this->assertCount(12, $totals);

        // 18,000 rows hydrated as models cost about 40 MB; streamed they cost
        // almost nothing. The ceiling is deliberately loose — it is here to
        // catch a return to loading them all, not to measure the allocator.
        $this->assertLessThan(
            10,
            $usedMb,
            sprintf('Reading 18,000 samples retained %.1fMB; it should stream rather than hold them.', $usedMb)
        );
    }

    #[Test]
    public function the_batched_total_equals_the_per_deployment_total(): void
    {
        $ids = $this->seedSamples(deployments: 3, samplesEach: 40);
        $from = now()->subMonth();
        $to = now();

        $batched = ContainerMetric::transferBytesForDeployments($ids, $from, $to);

        foreach ($ids as $id) {
            $deployment = ContainerDeployment::findOrFail($id);

            // The figure is billed, so the two readers must not drift apart.
            $this->assertSame(
                ContainerMetric::transferBytesForPeriod($deployment, $from, $to),
                $batched[$id],
                'batched and single-deployment transfer disagree for deployment '.$id
            );
        }
    }
}
