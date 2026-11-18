<?php

namespace Tests\Feature;

use App\Services\Invoice\InvoicePdfService;
use Tests\TestCase;

/** REM-10: the bundled official PrimeClassy logo is the byte-exact approved asset and is what the invoice embeds by default. */
class InvoiceOfficialLogoTest extends TestCase
{
    private const OFFICIAL_SHA256 = 'e2d724eef81645aee42328cc89b8f1a5ff3cd140cf92e70731a00fde26b552d3';

    public function test_bundled_logo_is_the_official_asset_and_matches_the_design_reference_when_available(): void
    {
        $path = resource_path('branding/prime-logo.png');
        $this->assertFileExists($path);
        $this->assertSame(self::OFFICIAL_SHA256, hash_file('sha256', $path));

        $reference = base_path('../design/assets/prime-logo-reference.png');
        if (is_file($reference)) { // the design folder is outside the backend mount in some runtimes
            $this->assertSame(hash_file('sha256', $path), hash_file('sha256', $reference));
        }
    }

    public function test_default_invoice_logo_embeds_exactly_the_official_bytes(): void
    {
        $m = new \ReflectionMethod(InvoicePdfService::class, 'defaultLogoDataUri');
        $uri = $m->invoke(app(InvoicePdfService::class));

        $this->assertSame('data:image/png;base64,'.base64_encode((string) file_get_contents(resource_path('branding/prime-logo.png'))), $uri);
    }
}
