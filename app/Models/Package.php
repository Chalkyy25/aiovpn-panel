<?php 

// app/Models/Package.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    protected $fillable = ['name','price_credits','max_connections'];
}