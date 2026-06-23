<?php

namespace Tests\Unit\Models;

use App\Models\ResellerProduct;
use Tests\TestCase;

class ResellerProductDirectAdminPackageMetaTest extends TestCase
{
    public function test_direct_admin_package_meta_uses_exact_package_name_for_api(): void
    {
        $listing = new ResellerProduct([
            'direct_admin_package_name' => 'Gold Package',
        ]);

        $meta = $listing->directAdminPackageMeta();

        $this->assertSame('Gold Package', $meta['package_name']);
        $this->assertSame('Gold Package', $meta['package']);
    }
}
