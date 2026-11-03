<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class OpeningTreasuryDemoSeeder extends Seeder
{
    public function run(): void
    {
        abort_unless(app()->environment(['local', 'testing']), 403);
        // Intentionally no financial facts: only approved activation may create cash/bank openings.
    }
}
