<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Sales\InvoicePdf;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\DatabaseTenantContext;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 1 (100): the company's logo. An owner sets a PNG (a file that only claims to be one is refused, as is one too
 * big); everyone in the company sees it; it goes on the PDF where the host draws images, and on the invoice the customer
 * opens from a link. An accountant cannot change it. Removed, it is gone. Another company never sees it.
 */
final class CompanyLogoTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    public function test_the_logo_is_set_by_an_owner_and_shown_on_the_invoices(): void
    {
        config(['einvoicing.force_sandbox' => false, 'einvoicing.countries' => [], 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company'], 'Accept' => 'application/json'];
        $png = base64_decode(self::PNG);
        $upload = fn (string $bytes, string $name = 'logo.png') => $this->post('/api/v1/company/logo', ['file' => UploadedFile::fake()->createWithContent($name, $bytes)], $h);

        $this->get('/api/v1/company/logo', $h)->assertOk()->assertJsonPath('logo', null);
        $upload('<svg onload="alert(1)"></svg>', 'logo.png')->assertUnprocessable()->assertJsonValidationErrors('file');   // only its name says PNG
        $upload("\x89PNG\r\n\x1a\n" . str_repeat('x', 300 * 1024))->assertUnprocessable()->assertJsonValidationErrors('file');
        $upload($png)->assertOk()->assertJsonPath('content_type', 'image/png');
        $this->assertSame($png, $this->get('/api/v1/company/logo', $h)->assertOk()->assertHeader('Content-Type', 'image/png')->getContent());
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'action' => 'logo_changed'])->count());

        // On the PDF where the host draws images (GD); on the invoice the customer opens from a link.
        InvoicePdf::$drawImages = true;
        try {
            $html = DatabaseTenantContext::run(null, $c['company'], fn () => app(InvoicePdf::class)->html(Company::query()->findOrFail($c['company']), Document::query()->findOrFail($c['invoice'])));
            $this->assertStringContainsString('<img src="data:image/png;base64,' . self::PNG . '"', $html);
        } finally {
            InvoicePdf::$drawImages = null;
        }
        $url = $this->postJson("/api/v1/invoices/{$c['invoice']}/share", [], $h)->assertOk()->json('url');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/public/invoice?token=' . preg_replace('/^.*token=/', '', $url))->assertOk()->assertJsonPath('company.logo', 'data:image/png;base64,' . self::PNG);

        // An accountant cannot change it; the database refuses it too.
        $accountant = $this->createUser('Accountant', 'acc@example.test');
        $this->owner()->table('memberships')->insert(['company_id' => $c['company'], 'user_id' => $accountant->getKey(), 'role' => 'accountant', 'status' => 'active']);
        $this->actingAs($accountant, 'sanctum');
        $this->get('/api/v1/company/logo', $h)->assertOk();
        $upload($png)->assertForbidden();
        $this->postJson('/api/v1/company/logo/remove', [], $h)->assertForbidden();

        // Another company never sees it.
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $this->get('/api/v1/company/logo', ['X-Company-Id' => $b['company'], 'Accept' => 'application/json'])->assertOk()->assertJsonPath('logo', null);

        $this->actingAs(User::query()->findOrFail($c['user']), 'sanctum');
        $this->postJson('/api/v1/company/logo/remove', [], $h)->assertOk()->assertJsonPath('logo', false);
        $this->get('/api/v1/company/logo', $h)->assertOk()->assertJsonPath('logo', null);
    }
}
