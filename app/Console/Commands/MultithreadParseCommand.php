<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ParsedIp;
use App\Models\ParsedLog;
use App\Models\ParsedReferer;
use App\Models\ParsedUrl;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use parallel\Runtime;
use parallel\Channel;
use parallel\Events;

class MultithreadParseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:multithread-parse-command';

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
        $baseBath = base_path();
        $inputFilePath = storage_path('app/private/access.log');
        $fileSize = filesize($inputFilePath);
        $this->info('Start parsing...');

        $progress = $this->output->createProgressBar($fileSize);
        $progress->setFormat('very_verbose');
        $progress->start();

        $handle = fopen($inputFilePath, 'r');
        Artisan::call('migrate:fresh');
        // RequestLog::truncate();
        // Ipclient::truncate();

        $regexp = '/^((?:\d+\.?){4}) - - \[(.*)\] \"(GET|PUT|PATCH|HEAD|DELETE|POST|PROPFIND|OPTIONS)\s(.*?)\s(.*?)\" ([1-5][0-9][0-9]) (\d+) \"(.*?)\" \"(.*?)\"$/';
        if ($handle) {
            $countOfIps = 0;
            $countOfUrls = 0;
            $countOfReferers = 0;
            $logs = [];
            $handledIps = [];
            $handledUrls = [];
            $handledReferers = [];

            // // Количество ядер процессора
            $num_cores = 2;
            // Создаем Runtime для каждого ядра
            $runtimes = [];
            $workers = [];
            $channels = [];
            $events = [];
            for ($i = 0; $i < $num_cores; $i++) {
                $runtimes[] = new Runtime();
            }
            for ($i = 0; $i < $num_cores; $i++) {
                $channels[] = new Channel();
            }
            for ($i = 0; $i < $num_cores; $i++) {
                $events[] = new Events();
                $events[$i]->addChannel($channels[$i]);
                $events[$i]->setBlocking(false);
            }
            $worker = function ($id, Channel $channel) {
                ini_set('memory_limit', '2048M');
                $pdo = null;
                while (true) {
                    // Ждем нового задания
                    // echo "$id запустился \n";
                    // echo "уже жду задачу\n";
                    $task = $channel->recv();
                    if ($task == 'break') {
                        break;
                    }
                    // print_r($task);
                    if ($task['worker_id'] == $id) {
                        // echo "$id запустился \n";
                        // sleep(rand(0, 0.1));
                        if (isset($task['payload'])) {
                            if ($task['payload'] == 'finish') {
                                echo "worker $id closed";
                                break;
                            }
                            if ($pdo == null) {
                                $pdo = new \PDO('sqlite:' . $task['payload']['base_path'] . '/database/database.sqlite');
                            }
                            $stmt = $pdo->prepare($task['payload']['sql']);
                            $values = [];
                            foreach ($task['payload']['item'] as $object) {
                                $values[] = $object['parsed_ip_id'];
                                $values[] = $object['parsed_url_id'];
                                $values[] = $object['parsed_referer_id'];
                                $values[] = $object['ip_address'];
                                $values[] = $object['time'];
                                $values[] = $object['action'];
                                $values[] = $object['url'];
                                $values[] = $object['protocol'];
                                $values[] = $object['status_code'];
                                $values[] = $object['size'];
                                $values[] = $object['http_referer'];
                                $values[] = $object['user_agent'];
                            }
                            $stmt->execute($values);
                            $channel->send(
                                [
                                    'worker_id' => $id,
                                    'status' => 'done'
                                ]
                            );
                        }
                    }
                }
            };
            // Запускаем worker в каждом Runtime
            foreach ($runtimes as $id => $runtime) {
                $runtime->run($worker, [$id, $channels[$id]]);
                $workers[] = ['worker_id' => $id, 'status' => 'done'];
            }
            while (($line = fgets($handle)) !== false) {
                // преобразуем каждую строку в массив с данными
                preg_match($regexp, $line, $parsedLine);

                try {
                    $item = [
                        'parsed_ip_id' => null,
                        'parsed_url_id' => null,
                        'parsed_referer_id' => null,
                        'ip_address' => $parsedLine[1] ?? null,
                        'time' => Carbon::parse($parsedLine[2])->toDateTimeString(),
                        'action' => $parsedLine[3],
                        'url' => $parsedLine[4],
                        'protocol' => $parsedLine[5],
                        'status_code' => $parsedLine[6],
                        'size' => $parsedLine[7],
                        'http_referer' => $parsedLine[8],
                        'user_agent' => $parsedLine[9],
                    ];
                    if (!isset($handledIps[$item['ip_address']])) {
                        $countOfIps++;
                        $handledIps[$item['ip_address']] = $countOfIps;
                    }
                    $item['parsed_ip_id'] = $countOfIps;

                    if (!isset($handledUrls[$item['url']])) {
                        $countOfUrls++;
                        $handledUrls[$item['url']] = [
                            'id' => $countOfUrls,
                            'hits_count' => 0,
                            'size' => $item['size'],
                            'url' => $item['url']
                        ];
                    }
                    $handledUrls[$item['url']]['hits_count']++;
                    $item['parsed_ip_id'] = $countOfUrls;

                    if (!isset($handledReferers[$item['http_referer']])) {
                        $countOfReferers++;
                        $handledReferers[$item['http_referer']] = [
                            'id' => $countOfReferers,
                            'hits_count' => 0,
                            'referer' => $item['http_referer']
                        ];
                    }
                    $handledReferers[$item['http_referer']]['hits_count']++;

                    $item['parsed_referer_id'] = $countOfReferers;
                    $item['parsed_ip_id'] = $countOfIps;
                    $item['parsed_url_id'] = $countOfUrls;
                    $logs[] = $item;
                } catch (\Throwable $th) {
                    $this->info($line);
                }

                // логика связанная с запросами
                if (count($logs) == 2500) {
                    $builder = DB::table((new ParsedLog)->getTable());
                    $sql = $builder->getGrammar()->compileInsert($builder, $logs);
                    while (true) {
                        // echo "дрочусь \n";
                        $isSent = false;
                        foreach ($workers as $id => $worker) {
                            // echo "прохожусь по воркерам и смотрю свободный \n";
                            if ($worker['status'] == 'done') {
                                // echo "нашёл \n";
                                $workers[$id]['status'] = 'running';
                                // echo "отпрая";
                                $channels[$id]->send(
                                    [
                                        'worker_id' => $id,
                                        'payload' => [
                                            'item' => $logs,
                                            'base_path' => $baseBath,
                                            'sql' => $sql,
                                        ]
                                    ]
                                );
                                // echo ('отправил');
                                $isSent = true;
                                break 2;
                            }
                        }
                        if ($isSent == false) {
                            $isQueueHasFreeWorker = false;
                            while (true) {
                                // echo "дрочусь по poll\n";
                                // $value = $events[0]->poll()?->value;
                                foreach ($events as $id => $event) {
                                    $value = $event->poll()?->value;
                                    if ($value != null) {
                                        $events[$id]->addChannel($channels[$id]);
                                        $workers[$value['worker_id']]['status'] = 'done';
                                        // dd(32);
                                        $isQueueHasFreeWorker = true;
                                    }
                                }
                                if ($isQueueHasFreeWorker) {
                                    // echo "прервался \n";
                                    break;
                                }
                            }
                        }
                    }
                    $logs = [];
                }

                $lineSize = strlen($line);
                // echo $lineSize;
                $progress->advance($lineSize);
                // if (count($handledIps) == 4000) {
                //     break;
                // }
            }
            if (count($logs) > 0) {
                $builder = DB::table((new ParsedLog)->getTable());
                $sql = $builder->getGrammar()->compileInsert($builder, $logs);
                while (true) {
                    // if (count($handledIps) == 4000) {
                    //     break;
                    // }
                    // echo "дрочусь \n";
                    $isSent = false;
                    foreach ($workers as $id => $worker) {
                        // echo "прохожусь по воркерам и смотрю свободный \n";
                        if ($worker['status'] == 'done') {
                            // echo "нашёл \n";
                            $workers[$id]['status'] = 'running';
                            // echo "отпрая";
                            $channels[$id]->send(
                                [
                                    'worker_id' => $id,
                                    'payload' => [
                                        'item' => $logs,
                                        'base_path' => $baseBath,
                                        'sql' => $sql,
                                    ]
                                ]
                            );
                            // echo ('отправил');
                            $isSent = true;
                            break 2;
                        }
                    }
                    if ($isSent == false) {
                        $isQueueHasFreeWorker = false;
                        while (true) {
                            foreach ($events as $id => $event) {
                                $value = $event->poll()?->value;
                                if ($value != null) {
                                    $events[$id]->addChannel($channels[$id]);
                                    $workers[$value['worker_id']]['status'] = 'done';
                                    $isQueueHasFreeWorker = true;
                                }
                            }
                            if ($isQueueHasFreeWorker) {
                                break;
                            }
                        }
                    }
                }
                $logs = [];
            }
            $progress->finish();
            foreach ($channels as $channel) {
                $channel->recv();
                $channel->send('break');
                $channel->close();
                try {
                } catch (\Throwable $th) {
                    //throw $th;
                }
            }
            // dd(array_unique(array_values(array_flip($handledIps))));
            $ipChunks = array_chunk(array_values(array_flip($handledIps)), 3000);
            $progress2 = $this->output->createProgressBar(count($ipChunks));
            $progress2->setFormat('very_verbose');
            $progress2->start();
            foreach ($ipChunks as $chunk) {
                $newIps = [];
                foreach ($chunk as $ip) {
                    $newIps[] = ['ip_address' => $ip];
                }
                try {
                    //code...
                    ParsedIp::insert($newIps);
                } catch (\Throwable $th) {
                    dd($th);
                }
                $progress2->advance($lineSize);
            }
            $progress2->finish();

            $urlChunks = array_chunk($handledUrls, 3000);
            $progress3 = $this->output->createProgressBar(count($urlChunks));
            $progress3->setFormat('very_verbose');
            $progress3->start();
            foreach ($urlChunks as $chunk) {
                $newUrls = [];
                foreach ($chunk as $url) {
                    // dd($url);
                    $newUrls[] = [
                        'url' => $url['url'],
                        'hits_count' => $url['hits_count'],
                        'size' => $url['size'],
                    ];
                }
                try {
                    //code...
                    ParsedUrl::insert($newUrls);
                } catch (\Throwable $th) {
                    dd($th);
                }
                $progress3->advance($lineSize);
            }
            $progress3->finish();

            $refererChunks = array_chunk($handledReferers, 3000);
            $progress4 = $this->output->createProgressBar(count($refererChunks));
            $progress4->setFormat('very_verbose');
            $progress4->start();
            foreach ($refererChunks as $chunk) {
                $newReferers = [];
                foreach ($chunk as $referer) {
                    // dd($referer);
                    $newReferers[] = [
                        'referer' => $referer['referer'],
                        'hits_count' => $referer['hits_count'],
                    ];
                }
                try {
                    ParsedReferer::insert($newReferers);
                } catch (\Throwable $th) {
                    dd($th);
                }
                $progress4->advance($lineSize);
            }
            $progress4->finish();
        }
        $this->info("parsed!");
        // dd($workers);
        // foreach ($channels as $channel) {
        //     $channel->recv();
        //     $channel->send('break');
        //     $channel->close();
        //     try {
        //     } catch (\Throwable $th) {
        //         //throw $th;
        //     }
        // }
    }
}
