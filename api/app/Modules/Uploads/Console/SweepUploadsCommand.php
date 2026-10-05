<?php

namespace App\Modules\Uploads\Console;

use App\Modules\Uploads\Services\UploadSweeper;
use Illuminate\Console\Command;

/** Scheduled every 15 minutes (routes/console.php). Safe to run by hand or concurrently. */
final class SweepUploadsCommand extends Command
{
    protected $signature = 'uploads:sweep {--limit=500 : Sessions of each kind to handle per run}';

    protected $description = 'Expire abandoned upload sessions and finish ones stuck while completing';

    public function handle(UploadSweeper $sweeper): int
    {
        $counts = $sweeper->sweep((int) $this->option('limit'));
        $this->info(collect($counts)->map(fn (int $n, string $k) => "{$k}: {$n}")->implode(', '));

        return $counts['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
