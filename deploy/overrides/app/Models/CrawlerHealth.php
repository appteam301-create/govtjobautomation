<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CrawlerHealth extends Model {
    protected $table = 'crawler_health';
    protected $guarded = [];
    protected function casts(): array {
        return ['last_success_at'=>'datetime','last_failure_at'=>'datetime','details'=>'array'];
    }
}
