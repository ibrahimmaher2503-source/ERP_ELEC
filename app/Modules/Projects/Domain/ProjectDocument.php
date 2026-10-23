<?php

namespace App\Modules\Projects\Domain;

use Database\Factories\ProjectDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(ProjectDocumentFactory::class)]
class ProjectDocument extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'project_documents';

    protected $guarded = ['id'];
}
