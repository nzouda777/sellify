<?php

namespace App\Jobs;

use App\Models\AutonomousVisitScenario;
use App\Services\AutonomousVisitGeneratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateAutonomousVisit implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $scenarioId)
    {
    }

    public function handle(AutonomousVisitGeneratorService $generator): void
    {
        Log::info('GenerateAutonomousVisit: job started', [
            'scenario_id' => $this->scenarioId,
        ]);

        $scenario = AutonomousVisitScenario::with('shop')->find($this->scenarioId);
        if (!$scenario || !$scenario->is_active) {
            Log::info('GenerateAutonomousVisit: scenario not found or inactive', [
                'scenario_id' => $this->scenarioId,
            ]);
            return;
        }

        if (!is_null($scenario->target_visits) && $scenario->generated_visits >= $scenario->target_visits) {
            Log::info('GenerateAutonomousVisit: target already reached, disabling scenario', [
                'scenario_id' => $scenario->id,
                'generated_visits' => $scenario->generated_visits,
                'target_visits' => $scenario->target_visits,
            ]);

            $scenario->update([
                'is_active' => false,
                'next_run_at' => null,
            ]);
            return;
        }

        if (!$generator->shouldRunNow($scenario)) {
            Log::info('GenerateAutonomousVisit: not within time window, scheduling next run', [
                'scenario_id' => $scenario->id,
            ]);
            $generator->scheduleNextRun($scenario);
            return;
        }

        $generator->generateVisit($scenario);
    }
}
