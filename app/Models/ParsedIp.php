<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParsedIp extends Model
{
    protected $table = "parsed_ips";
    protected $guarded = [];
    public $timestamps = false;
    public function logs()
    {
        return $this->hasMany(ParsedLog::class);
    }
}
