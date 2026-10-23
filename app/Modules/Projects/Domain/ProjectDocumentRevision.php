<?php

namespace App\Modules\Projects\Domain;

use Database\Factories\ProjectDocumentRevisionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(ProjectDocumentRevisionFactory::class)]
class ProjectDocumentRevision extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'project_document_revisions';

    protected $guarded = ['id'];
}
