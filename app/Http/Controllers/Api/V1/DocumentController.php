<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ShowsClientDocuments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\DocumentIndexRequest;
use App\Http\Requests\Client\UploadDocumentRequest;
use App\Models\ClientAccount;
use App\Services\Propertyware\DocumentLibrary;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    use ShowsClientDocuments;

    /**
     * List one page of the account's documents, newest first, optionally filtered.
     *
     * @throws PropertywareException
     */
    public function index(DocumentIndexRequest $request, ClientAccount $account, PropertywareDirectory $directory, DocumentLibrary $documents): JsonResponse
    {
        $result = $documents->pageOrImport($directory->scopeFor($account), $request->filters(), $request->page(), path: $request->url());

        if ($result['status'] === 'importing') {
            return response()->json([
                'status' => 'importing',
                'message' => __('Your documents are being imported from Propertyware. Try again in a few seconds.'),
            ], 202, ['Retry-After' => '3']);
        }

        $page = $result['results'];

        return response()->json([
            'data' => $page['data'],
            'meta' => [
                'currentPage' => $page['current_page'],
                'lastPage' => $page['last_page'],
                'perPage' => $page['per_page'],
                'total' => $page['total'],
                'unfilteredTotal' => $result['total'],
                'categories' => $result['categories'],
                'kindCounts' => $result['kindCounts'],
                'typeCounts' => $result['typeCounts'],
                'properties' => $result['properties'],
                'workOrders' => $result['workOrders'],
            ],
        ]);
    }

    /**
     * List what the account may attach an upload to: properties, portfolios,
     * work orders and leases, for the app's "Attach to" pickers.
     *
     * @throws PropertywareException
     */
    public function uploadTargets(ClientAccount $account, PropertywareDirectory $directory, DocumentLibrary $documents): JsonResponse
    {
        return response()->json(['data' => $documents->uploadTargets($directory->scopeFor($account))]);
    }

    /**
     * Upload a file from the app to Propertyware, published back to the account.
     *
     * @throws PropertywareException
     */
    public function store(UploadDocumentRequest $request, ClientAccount $account, PropertywareDirectory $directory, DocumentLibrary $documents): JsonResponse
    {
        $document = $documents->upload(
            $directory->scopeFor($account),
            $request->file('file'),
            $request->validated('description'),
            ...$request->target(),
        );

        return response()->json(['data' => $document->toArray()], 201);
    }

    /**
     * Download one of the account's documents.
     *
     * @throws PropertywareException
     */
    public function download(Request $request, ClientAccount $account, string $document, PropertywareDirectory $directory, DocumentLibrary $documents): StreamedResponse
    {
        $file = $documents->find($directory->scopeFor($account), $document);

        abort_if($file === null, 404);

        return $this->streamFile($documents, $file, inline: $request->boolean('inline'));
    }
}
