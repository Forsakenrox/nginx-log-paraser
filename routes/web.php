<?php

use App\Models\Ipclient;
use App\Models\RequestLog;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    // $ipclient = Ipclient::where('id', 454579)->with('logs')->first();
    // dd($ipclient->logs);
    $requests = RequestLog::where('http_referer', "like", "%rls%")->paginate(10);
    dd($requests);

    // return view('welcome');
});
