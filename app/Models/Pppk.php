<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pppk extends Model
{
    protected $connection = 'kantor';
    protected $table = 'tbpppk';
    public $timestamps = false;
    protected $guarded = [];
}
