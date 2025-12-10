<?php

namespace App\Console\Commands;

use App\Jobs\GenerateAutonomousOrder;
use App\Models\AutonomousScenario;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RunAutonomousScenariosCommand extends Command
{
    protected $signature = 'scenarios:run-autonomous {--limit=50}';
    protected $description = 'Evaluate autonomous scenarios and queue order generation';

    public function handle(): int
    {
        $nowUtc = Carbon::now();
        $scenarios = AutonomousScenario::with(['shop', 'products'])
            ->where('is_active', true)
            ->limit((int) $this->option('limit'))
            ->get();

        $count = 0;
        foreach ($scenarios as $scenario) {
            $nextRun = $scenario->next_run_at;
            if ($nextRun && $nowUtc->lt($nextRun)) {
                continue;
            }

            GenerateAutonomousOrder::dispatch($scenario->id);
            $count++;
        }

        $this->info("Queued {$count} scenarios");

        return self::SUCCESS;
    }
}
