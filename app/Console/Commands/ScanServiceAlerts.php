<?php

namespace App\Console\Commands;

use App\Mail\ServiceAlertMail;
use App\Models\Alert;
use App\Models\Machine;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ScanServiceAlerts extends Command
{
    protected $signature = 'alerts:scan';

    protected $description = 'Scan active machines due for service (<=100h) and email administrators.';

    public function handle(): int
    {
        $created = 0;

        Machine::query()
            ->where('status', 'active')
            ->chunkById(100, function ($machines) use (&$created) {
                foreach ($machines as $machine) {
                    $remaining = $machine->computed_remaining_hours;

                    if ($remaining === null || $remaining > Machine::ALERT_THRESHOLD) {
                        continue;
                    }

                    $hasOpenAlert = Alert::query()
                        ->where('machine_id', $machine->id)
                        ->where('type', 'service')
                        ->where('status', 'open')
                        ->exists();

                    if ($hasOpenAlert) {
                        continue;
                    }

                    Alert::create([
                        'machine_id' => $machine->id,
                        'type' => 'service',
                        'title' => __('alerts.auto_title', ['machine' => $machine->id_code]),
                        'message' => __('alerts.auto_message', [
                            'machine' => $machine->id_code,
                            'hours' => $remaining,
                        ]),
                        'remaining_hours' => $remaining,
                        'status' => 'open',
                    ]);

                    $created++;
                }
            });

        // Digest diario: notifica a administradores las alertas de servicio aún no notificadas.
        $pending = Alert::query()
            ->where('type', 'service')
            ->where('status', 'open')
            ->whereNull('notified_at')
            ->with('machine.location')
            ->get();

        $notified = 0;

        if ($pending->isNotEmpty()) {
            $admins = User::role('administrador')->where('active', true)->get();

            if ($admins->isNotEmpty()) {
                Mail::to($admins->pluck('email')->all())->queue(new ServiceAlertMail($pending));
                $pending->each->update(['notified_at' => now()]);
                $notified = $pending->count();
            }
        }

        Log::info('alerts:scan finished', ['created' => $created, 'notified' => $notified]);
        $this->info("Service alerts created: {$created}. Notified: {$notified}.");

        return self::SUCCESS;
    }
}
