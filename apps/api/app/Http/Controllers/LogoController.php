<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\CompanyLogo;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Module 1 (100): the company's logo on its invoices and quotes. Anyone in the company sees it; an owner or admin sets
 * or removes it (company.manage; the database checks the role too). Each change is audited, never the image itself.
 */
final class LogoController extends Controller
{
    public function __construct(private readonly CurrentCompany $current, private readonly CompanyLogo $logos, private readonly AuditLogger $audit)
    {
    }

    public function show(): Response|JsonResponse
    {
        $this->authorizeCompany('company.view');
        $logo = $this->logos->get($this->current->id());
        if ($logo === null) {
            return response()->json(['logo' => null]);   // none yet: an empty answer, as any list
        }

        return response($logo['image'], 200, ['Content-Type' => $logo['content_type'], 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=300']);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeCompany('company.manage');
        $request->validate(['file' => ['required', 'file', 'max:' . intdiv(CompanyLogo::MAX_BYTES, 1024)]],
            ['file.max' => 'A logo of 256 KB at most.', 'file.required' => 'Choose the logo file.']);
        $saved = $this->logos->store($this->current->id(), (string) file_get_contents((string) $request->file('file')->getRealPath()), $request->user()->getKey());
        $this->audit->record($this->current->id(), 'logo_changed', 'company', $this->current->id(), null, $saved, userId: $request->user()->getKey());

        return response()->json(['logo' => true, ...$saved]);
    }

    public function remove(Request $request): JsonResponse
    {
        $this->authorizeCompany('company.manage');
        if ($this->logos->remove($this->current->id())) {
            $this->audit->record($this->current->id(), 'logo_removed', 'company', $this->current->id(), null, null, userId: $request->user()->getKey());
        }

        return response()->json(['logo' => false]);
    }
}
