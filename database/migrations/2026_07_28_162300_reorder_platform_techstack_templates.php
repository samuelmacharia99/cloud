<?php

use App\Models\ContainerTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $orderBySlug = [
            'wordpress' => 1,
            'nodejs' => 2,
            'python' => 3,
            'static-site' => 4,
            'laravel' => 5,
            'php' => 6,
            'ruby' => 7,
            'ghost' => 8,
            'strapi' => 9,
        ];

        foreach ($orderBySlug as $slug => $order) {
            ContainerTemplate::query()->where('slug', $slug)->update(['order' => $order]);
        }
    }

    public function down(): void
    {
        // Previous ordering is not restored — display order is product preference.
    }
};
