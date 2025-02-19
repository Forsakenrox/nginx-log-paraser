<?php

namespace App\Console\Commands;

use App\Models\Ipclient;
use App\Models\RequestLog;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class ParseLogCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:parse-log-command';

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

        $inputFilePath = storage_path('app/private/rlsnet.ru.access.log-20250131');
        $fileSize = filesize($inputFilePath);
        $this->info('Start parsing...');
        $progress = $this->output->createProgressBar($fileSize);
        $progress->setFormat('very_verbose');
        $progress->start();
        $handle = fopen($inputFilePath, 'r');
        RequestLog::truncate();
        Ipclient::truncate();
        $regexp = '/^((?:\d+\.?){4}) - - \[(.*)\] \"(GET|PUT|PATCH|HEAD|DELETE|POST|PROPFIND|OPTIONS)\s(.*?)\s(.*?)\" ([1-5][0-9][0-9]) (\d+) \"(.*?)\" \"(.*?)\"$/';
        if ($handle) {
            $logs = [];
            $handledIps = [];
            while (($line = fgets($handle)) !== false) {
                preg_match($regexp, $line, $parsedLine);

                try {
                    $item = [
                        'ipclient_id' => null,
                        'ip_address' => $parsedLine[1] ?? null,
                        'time' => Carbon::parse($parsedLine[2])->toDateTime(),
                        'action' => $parsedLine[3],
                        'url' => $parsedLine[4],
                        'protocol' => $parsedLine[5],
                        'status_code' => $parsedLine[6],
                        'http_referer' => $parsedLine[8],
                        'user_agent' => $parsedLine[9],
                    ];
                    $logs[] = $item;
                    // array_push($logs, $item);
                } catch (\Throwable $th) {
                    $this->info($line);
                }

                if (count($logs) == 1200) {
                    $ips = array_unique(array_column($logs, 'ip_address'));
                    $newIps = array_diff($ips, $handledIps);
                    $ipsToInsert = [];
                    foreach ($newIps as $newIp) {
                        // $newIps[] = $newIp;
                        // array_push($handledIps, $newIp);
                        $handledIps[] = $newIp;
                        $ipsToInsert[] = ['ip_address' => $newIp];
                        // array_push($ipsToInsert, ['ip_address' => $newIp]);
                    }
                    Ipclient::insert($ipsToInsert);
                    $insertedIps = Ipclient::whereIn('ip_address', $ips)->get()->toArray();
                    foreach ($logs as $key => $log) {
                        foreach ($insertedIps as $insertedIp) {
                            if ($insertedIp['ip_address'] == $log['ip_address']) {
                                $logs[$key]['ipclient_id'] = $insertedIp['id'];
                            }
                        }
                    }
                    RequestLog::insert($logs);
                    $logs = [];
                }
                $lineSize = strlen($line);
                $progress->advance($lineSize);
            }
            if (count($logs) > 0) {
                $ips = array_unique(array_column($logs, 'ip_address'));
                $newIps = array_diff($ips, $handledIps);
                $ipsToInsert = [];
                foreach ($newIps as $newIp) {
                    array_push($handledIps, $newIp);
                    array_push($ipsToInsert, ['ip_address' => $newIp]);
                }
                $insertedIps = Ipclient::whereIn('ip_address', $ips)->get()->toArray();
                foreach ($logs as $key => $log) {
                    foreach ($insertedIps as $insertedIp) {
                        if ($insertedIp['ip_address'] == $log['ip_address']) {
                            $logs[$key]['ipclient_id'] = $insertedIp['id'];
                        }
                    }
                }
                RequestLog::insert($logs);
            }
        }
        $progress->finish();
    }
}
