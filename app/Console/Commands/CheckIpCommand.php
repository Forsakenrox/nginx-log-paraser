<?php

namespace App\Console\Commands;

use App\Models\ParsedIp;
use Illuminate\Console\Command;
use GeoIp2\Database\Reader;

class CheckIpCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:check-ip-command';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        ini_set('memory_limit', '2048M');
        $country = new Reader(database_path('GeoLite2-Country.mmdb'));
        // $city = new Reader(database_path('GeoLite2-City.mmdb'));

        $progress = $this->output->createProgressBar(ParsedIp::count());
        $progress->setFormat('very_verbose');
        $progress->start();

        $parsedIp = ParsedIp::chunk(5000, function ($ips) use ($country, $progress) {
            foreach ($ips as $ip) {
                try {
                    $base = $country->country($ip->ip_address);
                    $ip->country = $base->country->names['en'];
                    $ip->save();
                } catch (\Throwable $th) {
                    $this->info($th->getMessage());
                }
                $progress->advance();
            }
        });
        $progress->finish();
    }
}
