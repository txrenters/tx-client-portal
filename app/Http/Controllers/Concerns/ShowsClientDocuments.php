<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\ClientRole;
use App\Http\Requests\Client\DocumentIndexRequest;
use App\Http\Requests\Client\UploadDocumentRequest;
use App\Services\Propertyware\Data\Document;
use App\Services\Propertyware\DocumentLibrary;
use App\Services\Propertyware\MaintenanceSummary;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lists and serves the files published to a client's portal.
 */
trait ShowsClientDocuments
{
    use ResolvesClientScope;

    /**
     * Render the client's documents page.
     */
    protected function renderDocuments(DocumentIndexRequest $request, ClientRole $role, string $component, PropertywareDirectory $directory, DocumentLibrary $documents): InertiaResponse
    {
        $account = $this->activeClientAccount($request, $role);
        abort_if($account === null, 403);

        $filters = $request->filters();

        return Inertia::render($component, [
            'filters' => $filters,
            'documents' => Inertia::defer(function () use ($request, $role, $directory, $documents, $filters): ?array {
                try {
                    return $documents->pageOrImport($this->clientScope($request, $role, $directory), $filters, $request->page(), path: $request->url());
                } catch (PropertywareException) {
                    return null;
                }
            }),
            'uploadTargets' => Inertia::defer(function () use ($request, $role, $directory, $documents): ?array {
                try {
                    return $documents->uploadTargets($this->clientScope($request, $role, $directory));
                } catch (PropertywareException) {
                    return null;
                }
            }, 'upload')->once(as: 'upload-targets-'.$role->value.'-'.$account->id, until: now()->addMinutes(5)),
            'maxUploadMegabytes' => UploadDocumentRequest::MAX_KILOBYTES / 1024,
        ]);
    }

    /**
     * Upload a client's file to Propertyware and send them back to their documents.
     */
    protected function storeDocument(UploadDocumentRequest $request, ClientRole $role, PropertywareDirectory $directory, DocumentLibrary $documents): RedirectResponse
    {
        try {
            $documents->upload(
                $this->clientScope($request, $role, $directory),
                $request->file('file'),
                $request->validated('description'),
                ...$request->target(),
            );
        } catch (PropertywareException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('We could not upload your file right now. Please try again.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Document uploaded.')]);

        return back();
    }

    /**
     * Stream a file attached to one of the client's work orders.
     *
     * The work order itself is authorized first: Propertyware will happily
     * return the attachments of any work order id, so the client's access to
     * that work order has to be proven before its files are read.
     */
    protected function streamWorkOrderDocument(Request $request, ClientRole $role, string $workOrderId, string $documentId, PropertywareDirectory $directory, DocumentLibrary $documents, MaintenanceSummary $maintenance, bool $inline = false): StreamedResponse
    {
        $scope = $this->clientScope($request, $role, $directory);

        abort_if($maintenance->find($scope, $workOrderId) === null, 404);

        $document = $documents->findForWorkOrder($scope, $workOrderId, $documentId);

        abort_if($document === null, 404);

        abort_if($inline && ! $document->isViewable(), 404);

        return $this->streamFile($documents, $document, $inline, $inline && $request->boolean('thumbnail'));
    }

    /**
     * Stream one of the client's documents through this application.
     *
     * The file never leaves Propertyware with a public link; the request is
     * authorized here and the bytes are passed on to the signed-in client.
     * Viewing renders the file in the browser, downloading saves it.
     */
    protected function streamDocument(Request $request, ClientRole $role, string $documentId, PropertywareDirectory $directory, DocumentLibrary $documents, bool $inline = false): StreamedResponse
    {
        $scope = $this->clientScope($request, $role, $directory);

        $document = $documents->find($scope, $documentId);

        abort_if($document === null, 404);

        abort_if($inline && ! $document->isViewable(), 404);

        return $this->streamFile($documents, $document, $inline, $inline && $request->boolean('thumbnail'));
    }

    /**
     * Pass an authorized file through to the signed-in client.
     */
    protected function streamFile(DocumentLibrary $documents, Document $document, bool $inline, bool $thumbnail = false): StreamedResponse
    {
        if ($thumbnail) {
            abort_unless(str_starts_with($document->fileType ?? '', 'image/'), 404);

            $thumbnail = $documents->thumbnail($document);

            return response()->streamDownload(
                function () use ($thumbnail): void {
                    echo $thumbnail['contents'];
                },
                $thumbnail['mimeType'] === 'image/webp' ? 'thumbnail.webp' : $document->fileName,
                [
                    'Content-Type' => $thumbnail['mimeType'],
                    'X-Content-Type-Options' => 'nosniff',
                    'Cache-Control' => 'private, no-store',
                ],
                disposition: 'inline',
            );
        }

        $response = $documents->download($document);

        return response()->streamDownload(
            function () use ($response): void {
                echo $response->body();
            },
            $document->fileName,
            [
                'Content-Type' => $document->fileType ?? 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
            ],
            disposition: $inline ? 'inline' : 'attachment',
        );
    }
}
