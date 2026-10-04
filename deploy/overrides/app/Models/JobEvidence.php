<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class JobEvidence extends Model {
    protected $table = 'job_evidence';
    protected $guarded = [];
    protected function casts(): array {
        return ['page'=>'integer','metadata'=>'array'];
    }
}
