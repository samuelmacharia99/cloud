<?php

namespace App\Services\Customer;

use App\Models\ContainerTemplate;
use Illuminate\Support\Collection;

/**
 * Which stacks a chosen plan can run.
 *
 * Today's rule in reverse: a product is either generic (runs any stack) or
 * pinned to one container template through products.container_template_id,
 * and a stack asks for a minimum of RAM and CPU. With the plan chosen first,
 * the picker shows every offered stack and greys out the ones this plan
 * cannot run, with the reason, instead of discovering "no plans available"
 * at the end.
 */
class StackEligibilityService
{
    /**
     * @param  array{cpu?: float|int|null, memory_mb?: int|null}|null  $limits  the plan's included resources, null when unknown
     * @return Collection<int, StackChoice>
     */
    public function forPlan(?int $pinnedTemplateId, ?array $limits, ?Collection $templates = null): Collection
    {
        $templates ??= ContainerTemplate::offeredForNewDeploy()->catalogOrder()->get();
        $pinned = $pinnedTemplateId ? $templates->firstWhere('id', $pinnedTemplateId) ?? ContainerTemplate::find($pinnedTemplateId) : null;

        return $templates->map(function (ContainerTemplate $template) use ($pinned, $limits): StackChoice {
            if ($pinned && (int) $template->id !== (int) $pinned->id) {
                return new StackChoice($template, false, "This plan is for {$pinned->name} only.");
            }

            $memoryMb = isset($limits['memory_mb']) && $limits['memory_mb'] !== null ? (int) $limits['memory_mb'] : null;
            $required = (int) ($template->required_ram_mb ?? 0);
            if ($memoryMb !== null && $memoryMb > 0 && $required > $memoryMb) {
                return new StackChoice($template, false, sprintf(
                    'Needs %s RAM; this plan includes %s.',
                    self::gb($required),
                    self::gb($memoryMb)
                ));
            }

            $cpu = isset($limits['cpu']) && $limits['cpu'] !== null ? (float) $limits['cpu'] : null;
            $requiredCpu = (float) ($template->required_cpu_cores ?? 0);
            if ($cpu !== null && $cpu > 0 && $requiredCpu > $cpu + 1e-9) {
                return new StackChoice($template, false, sprintf(
                    'Needs %s CPU; this plan includes %s.',
                    self::cores($requiredCpu),
                    self::cores($cpu)
                ));
            }

            return new StackChoice($template, true);
        })->values();
    }

    /**
     * Keyed for the picker: template id => {eligible, reason}.
     *
     * @param  Collection<int, StackChoice>  $choices
     * @return array<int, array{eligible: bool, reason: ?string}>
     */
    public function keyed(Collection $choices): array
    {
        return $choices->mapWithKeys(fn (StackChoice $choice) => [(int) $choice->template->id => $choice->toArray()])->all();
    }

    /**
     * @param  array<string, mixed>|null  $resourceLimits  a product or listing resource_limits column
     * @return array{cpu: ?float, memory_mb: ?int}
     */
    public static function limitsFromResourceLimits(?array $resourceLimits): array
    {
        return [
            'cpu' => isset($resourceLimits['cpu']) && $resourceLimits['cpu'] !== '' ? (float) $resourceLimits['cpu'] : null,
            'memory_mb' => isset($resourceLimits['memory']) && $resourceLimits['memory'] !== '' ? (int) $resourceLimits['memory'] : null,
        ];
    }

    private static function gb(int $mb): string
    {
        return $mb >= 1024
            ? rtrim(rtrim(number_format($mb / 1024, 1), '0'), '.').' GB'
            : $mb.' MB';
    }

    private static function cores(float $cores): string
    {
        return rtrim(rtrim(number_format($cores, 1), '0'), '.').' '.($cores === 1.0 ? 'core' : 'cores');
    }
}
