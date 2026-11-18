<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Files\Attachments;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Files attached to invoices, bills and expenses (SECURITY.md S7, schema 063). Seeing them takes the permission that
 * sees the document, adding or removing them the one that writes it. A file is sent only by this API after that check,
 * as a download that never runs in the browser; there is no public link to it. Rows are read through row-level
 * security and every query names the company.
 */
final class AttachmentController extends Controller
{
    /** The document's kind => [see, write]. */
    private const PERMISSIONS = [
        'invoice' => ['invoices.view', 'invoices.create'], 'credit_note' => ['invoices.view', 'invoices.create'],
        'bill' => ['bills.view', 'bills.create'], 'vendor_credit' => ['bills.view', 'bills.create'],
        'expense' => ['expenses.view', 'expenses.create'],
    ];

    public function __construct(private readonly CurrentCompany $current, private readonly Attachments $files, private readonly AuditLogger $audit)
    {
    }

    public function index(Request $request, Document $document): JsonResponse
    {
        $this->authorizeCompany(self::PERMISSIONS[(string) $request->route('kind')][0]);

        return response()->json([
            'data' => DB::table('attachments as a')->join('users as u', 'u.id', '=', 'a.created_by')
                ->where(['a.company_id' => $document->company_id, 'a.document_id' => $document->getKey()])->whereNull('a.removed_at')
                ->orderBy('a.created_at')->get(['a.id', 'a.file_name', 'a.content_type', 'a.size_bytes', 'a.created_at', 'u.name as added_by']),
            'can' => ['add' => (bool) $this->current->membership()?->can(self::PERMISSIONS[(string) $request->route('kind')][1])],
        ]);
    }

    public function store(Request $request, Document $document): JsonResponse
    {
        $this->authorizeCompany(self::PERMISSIONS[(string) $request->route('kind')][1]);
        $request->validate(['file' => ['required', 'file', 'max:' . intdiv((int) config('attachments.max_bytes'), 1024)]],
            ['file.max' => 'A file of 10 MB at most.', 'file.required' => 'Choose the file to attach.']);
        $key = 'attachments:' . $request->user()->getKey();
        if (RateLimiter::tooManyAttempts($key, (int) config('attachments.per_hour'))) {
            throw ValidationException::withMessages(['file' => ['Many files were attached in the last hour: try again later.']]);
        }
        RateLimiter::hit($key, 3600);

        return response()->json($this->files->store($document, $request->file('file'), $request->user()->getKey()), 201);
    }

    /** The file, as a download: its own type, never sniffed, never cached, never run in the browser. */
    public function download(string $attachment): BinaryFileResponse
    {
        $this->authorizeCompany($this->permissionFor($attachment, 0));
        $a = $this->live($attachment);
        $path = $this->files->path($a->company_id, $a->storage_key);
        if (! is_file($path)) {
            abort(404);
        }

        return response()->download($path, $a->file_name, [
            'Content-Type' => $a->content_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            // The API's security headers add Content-Security-Policy: default-src 'none' to every answer: nothing in it runs.
        ]);
    }

    /** Taken off the document: kept, marked removed with who and when. */
    public function remove(Request $request, string $attachment): JsonResponse
    {
        $this->authorizeCompany($this->permissionFor($attachment, 1));
        $a = $this->live($attachment);
        DB::table('attachments')->where(['company_id' => $a->company_id, 'id' => $a->id])->whereNull('removed_at')
            ->update(['removed_at' => now(), 'removed_by' => $request->user()->getKey()]);
        $this->audit->record($a->company_id, 'attachment_removed', (string) $a->type, $a->document_id, ['attachment' => $a->id, 'file_name' => $a->file_name], null,
            userId: $request->user()->getKey());

        return response()->json(['removed' => $a->id]);
    }

    /** The permission an attachment needs (0 to see it, 1 to change it), by its document's kind; not found: invoices (then 404). */
    private function permissionFor(string $id, int $which): string
    {
        $type = Str::isUuid($id) ? DB::table('attachments as a')->join('documents as d', 'd.id', '=', 'a.document_id')
            ->where(['a.company_id' => $this->current->id(), 'a.id' => $id])->value('d.type') : null;

        return (self::PERMISSIONS[$type] ?? self::PERMISSIONS['invoice'])[$which];
    }

    private function live(string $id): object
    {
        $a = Str::isUuid($id) ? DB::table('attachments as a')->join('documents as d', 'd.id', '=', 'a.document_id')
            ->where(['a.company_id' => $this->current->id(), 'a.id' => $id])->whereNull('a.removed_at')
            ->first(['a.id', 'a.company_id', 'a.document_id', 'a.file_name', 'a.content_type', 'a.storage_key', 'd.type']) : null;
        if ($a === null) {
            abort(404);
        }

        return $a;
    }
}
