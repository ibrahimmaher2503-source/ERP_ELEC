<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class OpeningPayablesDemoSeeder extends Seeder
{
    public function run(): void
    {
        abort_unless(app()->environment(['local', 'testing']), 403);
        // Intentionally no financial facts: only approved activation may create opening AP.
    }
}
