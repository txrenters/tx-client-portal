<?php

namespace App\Http\Controllers\Owner;

use App\Enums\ClientRole;
use App\Http\Controllers\Concerns\ShowsClientDocuments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\DocumentIndexRequest;
use App\Http\Requests\Client\UploadDocumentRequest;
use App\Services\Propertyware\DocumentLibrary;
use App\Services\Propertyware\PropertywareDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    use ShowsClientDocuments;

    /**
     * List the documents published to the owner.
     */
    public function index(DocumentIndexRequest $request, PropertywareDirectory $directory, DocumentLibrary $documents): Response
    {
        return $this->renderDocuments($request, ClientRole::Owner, 'owner/Documents', $directory, $documents);
    }

    /**
     * Upload a file from the owner to Propertyware.
     */
    public function store(UploadDocumentRequest $request, PropertywareDirectory $directory, DocumentLibrary $documents): RedirectResponse
    {
        return $this->storeDocument($request, ClientRole::Owner, $directory, $documents);
    }

    /**
     * Download one of the owner's documents.
     */
    public function download(Request $request, string $document, PropertywareDirectory $directory, DocumentLibrary $documents): StreamedResponse
    {
        return $this->streamDocument($request, ClientRole::Owner, $document, $directory, $documents);
    }

    /**
     * Show one of the owner's documents in the browser.
     */
    public function preview(Request $request, string $document, PropertywareDirectory $directory, DocumentLibrary $documents): StreamedResponse
    {
        return $this->streamDocument($request, ClientRole::Owner, $document, $directory, $documents, inline: true);
    }
}
