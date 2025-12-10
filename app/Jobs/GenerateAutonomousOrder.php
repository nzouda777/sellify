<?php

namespace App\Jobs;

use App\Models\AutonomousScenario;
use App\Models\Order;
use App\Services\AutonomousOrderGeneratorService;
use App\Jobs\SyncOrderToShopify;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateAutonomousOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $scenarioId)
    {
        
    }

    public function handle(AutonomousOrderGeneratorService $generator): void
    {
        Log::info('GenerateAutonomousOrder: job started', [
            'scenario_id' => $this->scenarioId,
        ]);

        $scenario = AutonomousScenario::with(['products', 'shop'])->find($this->scenarioId);
        if (!$scenario || !$scenario->is_active) {
            Log::info('GenerateAutonomousOrder: scenario not found or inactive', [
                'scenario_id' => $this->scenarioId,
            ]);
            return;
        }

        // Si un quota est défini et déjà atteint, on désactive définitivement le scénario (one-shot)
        if (!is_null($scenario->target_orders) && $scenario->generated_orders >= $scenario->target_orders) {
            Log::info('GenerateAutonomousOrder: target already reached, disabling scenario', [
                'scenario_id' => $scenario->id,
                'generated_orders' => $scenario->generated_orders,
                'target_orders' => $scenario->target_orders,
            ]);

            $scenario->update([
                'is_active' => false,
                'next_run_at' => null,
            ]);
            return;
        }

        // Autonome mais produits imposés : si aucun produit n'est rattaché, on saute et on replanifie.
        if ($scenario->products->isEmpty()) {
            Log::warning('GenerateAutonomousOrder: scenario has no products, scheduling next run', [
                'scenario_id' => $scenario->id,
            ]);
            $generator->scheduleNextRun($scenario);
            return;
        }

        if (!$generator->shouldRunNow($scenario)) {
            Log::info('GenerateAutonomousOrder: not within time window, scheduling next run', [
                'scenario_id' => $scenario->id,
            ]);
            $generator->scheduleNextRun($scenario);
            return;
        }

        $order = $generator->generateOrder($scenario);

        Log::info('GenerateAutonomousOrder: order generated, dispatching sync job', [
            'scenario_id' => $scenario->id,
            'order_id' => $order->id,
        ]);

        SyncOrderToShopify::dispatch($order->id);
    }
}
