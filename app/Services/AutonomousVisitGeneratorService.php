<?php

namespace App\Services;

use App\Jobs\GenerateAutonomousVisit;
use App\Models\AutonomousVisitScenario;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AutonomousVisitGeneratorService
{
    public function shouldRunNow(AutonomousVisitScenario $scenario, Carbon $now = null): bool
    {
        $now = $now ?: Carbon::now($scenario->shop->timezone ?? 'UTC');
        $start = $scenario->window_start_time
            ? Carbon::parse($scenario->window_start_time, $now->timezone)
            : null;
        $end = $scenario->window_end_time
            ? Carbon::parse($scenario->window_end_time, $now->timezone)
            : null;

        if (!$start || !$end) {
            return true; // pas de fenêtre définie -> toujours OK
        }

        if ($end->lessThan($start)) {
            $end->addDay();
        }

        return $now->between($start, $end);
    }

    public function scheduleNextRun(AutonomousVisitScenario $scenario, Carbon $from = null): void
    {
        $from = $from ?: Carbon::now($scenario->shop->timezone ?? 'UTC');
        $interval = random_int($scenario->min_interval_seconds, $scenario->max_interval_seconds);
        $nextRun = $from->copy()->addSeconds($interval);

        Log::info('AutonomousVisitScenario: schedule next run', [
            'scenario_id' => $scenario->id,
            'from' => $from->toDateTimeString(),
            'interval_seconds' => $interval,
            'next_run_at' => $nextRun->toDateTimeString(),
        ]);

        $scenario->update(['next_run_at' => $nextRun]);

        GenerateAutonomousVisit::dispatch($scenario->id)->delay($interval);

        Log::info('AutonomousVisitScenario: end scheduleNextRun', [
            'scenario_id' => $scenario->id,
            'next_run_at' => $nextRun->toDateTimeString(),
            'dispatched_with_delay_seconds' => $interval,
        ]);
    }
public function randIP() {
        // Génère une IP aléatoire (format IPv4)
        return rand(1, 255) . "." . rand(0, 255) . "." . rand(0, 255) . "." . rand(1, 254);
    }
    public function generateVisit(AutonomousVisitScenario $scenario): void
    {
        Log::info('AutonomousVisitScenario: start generateVisit', [
            'scenario_id' => $scenario->id,
            'shop_id' => $scenario->shop_id,
            'generated_visits' => $scenario->generated_visits,
            'target_visits' => $scenario->target_visits,
        ]);

        try {
            $url = $scenario->target_url;

            $userAgents = [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
                'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0 Safari/537.36',
            ];

            $userAgent = $userAgents[array_rand($userAgents)];

            


            $response = Http::withHeaders([
                'User-Agent' => $userAgent,
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'ip' => $this->randIP(),
                'date' => date('Y-m-d H:i:s'),
                'page' => "https://firstmillionever.myshopify.com/password"
            ])->get($url);

            Log::info('AutonomousVisitScenario: visit requested', [
                'scenario_id' => $scenario->id,
                'url' => $url,
                'status' => $response->status(),
            ]);

            $scenario->increment('generated_visits');
            $scenario->refresh();

            Log::info('AutonomousVisitScenario: increment generated_visits', [
                'scenario_id' => $scenario->id,
                'generated_visits' => $scenario->generated_visits,
                'target_visits' => $scenario->target_visits,
            ]);

            if (!is_null($scenario->target_visits) && $scenario->generated_visits >= $scenario->target_visits) {
                Log::info('AutonomousVisitScenario: target reached, disabling scenario', [
                    'scenario_id' => $scenario->id,
                ]);

                $scenario->update([
                    'is_active' => false,
                    'next_run_at' => null,
                ]);
            } else {
                $this->scheduleNextRun($scenario);
            }

            Log::info('AutonomousVisitScenario: end generateVisit', [
                'scenario_id' => $scenario->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('AutonomousVisitScenario: error during generateVisit', [
                'scenario_id' => $scenario->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
