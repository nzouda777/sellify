<?php

namespace App\Console\Commands;

use App\Jobs\GenerateAutonomousVisit;
use App\Models\AutonomousVisitScenario;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RunAutonomousVisitScenariosCommand extends Command
{
    protected $signature = 'scenarios:run-autonomous-visits {--limit=50}';
    protected $description = 'Evaluate autonomous visit scenarios and queue visit generation';

    public function handle(): int
    {
        $nowUtc = Carbon::now();
        $scenarios = AutonomousVisitScenario::with('shop')
            ->where('is_active', true)
            ->limit((int) $this->option('limit'))
            ->get();

        $count = 0;
        foreach ($scenarios as $scenario) {
            $nextRun = $scenario->next_run_at;
            if ($nextRun && $nowUtc->lt($nextRun)) {
                continue;
            }

            GenerateAutonomousVisit::dispatch($scenario->id);
            $count++;
        }

        $this->info("Queued {$count} visit scenarios");

        return self::SUCCESS;
    }
}
