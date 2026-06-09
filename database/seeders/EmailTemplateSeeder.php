<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use Illuminate\Database\Seeder;

class EmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach (EmailTemplate::defaultTemplates() as $template) {
            if (app()->environment('production')) {
                EmailTemplate::firstOrCreate(
                    ['event_key' => $template['event_key']],
                    $template,
                );

                continue;
            }

            EmailTemplate::updateOrCreate(
                ['event_key' => $template['event_key']],
                $template
            );
        }
    }
}
