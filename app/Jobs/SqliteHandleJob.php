<?php

namespace App\Jobs;

use App\Models\Ipclient;
use App\Models\RequestLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SqliteHandleJob implements ShouldQueue
{
    use Queueable;
    // use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public $ipsToInsert;
    public $ips;
    public $logs;
    /**
     * Create a new job instance.
     */
    public function __construct($ipsToInsert, $ips, $logs)
    {
        $this->ipsToInsert = $ipsToInsert;
        $this->ips = $ips;
        $this->logs = $logs;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Ipclient::insert($this->ipsToInsert);
        $insertedIps = Ipclient::whereIn('ip_address', $this->ips)->get()->toArray();
        foreach ($this->logs as $key => $log) {
            foreach ($insertedIps as $insertedIp) {
                if ($insertedIp['ip_address'] == $log['ip_address']) {
                    $this->logs[$key]['ipclient_id'] = $insertedIp['id'];
                }
            }
        }
        RequestLog::insert($this->logs);
    }
}
