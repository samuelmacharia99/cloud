<?php

namespace Database\Seeders;

use App\Models\SmsTemplate;
use Illuminate\Database\Seeder;

class SmsTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach (SmsTemplate::defaultTemplates() as $template) {
            SmsTemplate::updateOrCreate(
                ['event_key' => $template['event_key']],
                $template
            );
        }
    }
}
