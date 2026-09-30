<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HolidayTele extends Model
{
    protected $table = 'holidays_tele';

    public $timestamps = false;

    protected $fillable = [
        'holiday_date',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'holiday_date' => 'date',
        ];
    }
}
