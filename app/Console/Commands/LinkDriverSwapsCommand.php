<?php

namespace App\Console\Commands;

use App\Models\DriverSwap;
use App\Models\Trip;
use App\Services\TripKmSplitService;
use Illuminate\Console\Command;

class LinkDriverSwapsCommand extends Command
{
    protected $signature = 'trips:link-driver-swaps {--dry-run : Chỉ báo cáo, không ghi}';

    protected $description = 'Gắn checkpoint_id cho các bản ghi driver_swaps cũ theo mốc bàn giao cùng km';

    public function handle(): int
    {
        $linked = 0;
        $unmatched = [];

        $tripIds = DriverSwap::whereNull('checkpoint_id')->distinct()->pluck('trip_id');

        foreach (Trip::whereIn('id', $tripIds)->get() as $trip) {
            $matches = TripKmSplitService::matchUnlinkedSwaps($trip);

            foreach (DriverSwap::where('trip_id', $trip->id)->whereNull('checkpoint_id')->get() as $swap) {
                if (! isset($matches[$swap->id])) {
                    $unmatched[] = [$trip->trip_code, $swap->id, $swap->handover_km];

                    continue;
                }

                if (! $this->option('dry-run')) {
                    $swap->update(['checkpoint_id' => $matches[$swap->id]]);
                }

                $linked++;
            }
        }

        $this->info("Đã gắn: {$linked}");
        $this->info('Không khớp: '.count($unmatched));

        if ($unmatched !== []) {
            $this->table(['Chuyến', 'Swap ID', 'Km trên swap'], $unmatched);
        }

        return self::SUCCESS;
    }
}
