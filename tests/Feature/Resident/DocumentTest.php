<?php

use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake([
        '*/workorders*' => Http::response([]),
        '*/contacts/*' => Http::response([
            'id' => '900',
            'type' => 'TENANT',
            'attachedLeases' => [['id' => 55]],
            'attachedPortfolios' => [],
        ]),
        '*/buildings*' => Http::response([['id' => 7, 'leaseID' => 55, 'portfolioID' => 2]]),
        '*/units*' => Http::response([]),
        '*/docs/9/download' => Http::response('%PDF-1.4 fake', 200, ['Content-Type' => 'application/pdf']),
        '*/docs?*' => Http::response([
            ['id' => 9, 'fileName' => 'Lease.pdf', 'fileType' => 'application/pdf', 'entityType' => 'BUILDING', 'entityId' => 7, 'publishToTenantPortal' => true, 'createdDateTime' => '2026-09-01T10:00:00.000Z'],
            ['id' => 10, 'fileName' => 'Internal.pdf', 'fileType' => 'application/pdf', 'entityType' => 'BUILDING', 'entityId' => 7, 'publishToTenantPortal' => false],
            ['id' => 11, 'fileName' => 'Notes.docx', 'fileType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'entityType' => 'BUILDING', 'entityId' => 7, 'publishToTenantPortal' => true],
            ['id' => 12, 'fileName' => 'Photo.jpg', 'fileType' => 'image/jpeg', 'entityType' => 'BUILDING', 'entityId' => 7, 'publishToTenantPortal' => true],
        ]),
    ]);
});

function documentResident(): User
{
    $user = User::factory()->create();
    ClientAccount::factory()->resident()->for($user)->create(['propertyware_id' => '900']);

    return $user;
}

test('lists only the documents published to the resident portal', function () {
    $this->actingAs(documentResident());

    partialGet(route('resident.documents'), 'resident/Documents', 'documents')
        ->assertOk()
        ->assertJsonPath('props.documents.results.data.0.fileName', 'Lease.pdf')
        ->assertJsonPath('props.documents.results.data.0.viewable', true)
        ->assertJsonPath('props.documents.results.data.0.createdAt', '2026-09-01T10:00:00+00:00')
        ->assertDontSee('Internal.pdf');
});

test('marks a word document as not viewable in the browser', function () {
    $this->actingAs(documentResident());

    partialGet(route('resident.documents'), 'resident/Documents', 'documents')
        ->assertJsonPath('props.documents.results.data.1.fileName', 'Notes.docx')
        ->assertJsonPath('props.documents.results.data.1.viewable', false);
});

test('streams a document the resident may see as a download', function () {
    $response = $this->actingAs(documentResident())
        ->get(route('resident.documents.download', 9));

    $response->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect($response->headers->get('content-disposition'))->toContain('attachment');
});

test('shows a pdf in the browser when previewing', function () {
    $response = $this->actingAs(documentResident())
        ->get(route('resident.documents.preview', 9));

    $response->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('inline');
});

test('returns 404 when previewing a file the browser cannot display', function () {
    $this->actingAs(documentResident())
        ->get(route('resident.documents.preview', 11))
        ->assertNotFound();
});

test('returns 404 for a document not published to the resident', function () {
    $this->actingAs(documentResident())
        ->get(route('resident.documents.download', 10))
        ->assertNotFound();
});

test('returns 404 for a document belonging to another client', function () {
    $this->actingAs(documentResident())
        ->get(route('resident.documents.download', 9999))
        ->assertNotFound();
});

test('serves a privately cached webp thumbnail and preserves the original download', function () {
    Http::preventStrayRequests();
    $photo = UploadedFile::fake()->image('photo.jpg', 1200, 800);
    $original = file_get_contents($photo->getPathname());
    Http::fake(['*/docs/12/download' => Http::response($original, 200, ['Content-Type' => 'image/jpeg'])]);
    $this->actingAs(documentResident());

    $url = route('resident.documents.preview', ['document' => 12, 'thumbnail' => true]);
    $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/webp');
    $thumbnail = $response->streamedContent();
    $dimensions = getimagesizefromstring($thumbnail);

    expect($dimensions[0])->toBe(288)
        ->and($dimensions[1])->toBe(192)
        ->and($dimensions['mime'])->toBe('image/webp')
        ->and($response->headers->get('Cache-Control'))->toContain('private', 'no-store');

    expect($this->get($url)->assertOk()->streamedContent())->toBe($thumbnail);
    expect(Http::recorded(fn ($request) => str_contains($request->url(), '/docs/12/download')))->toHaveCount(1);

    expect($this->get(route('resident.documents.download', 12))->assertOk()->streamedContent())->toBe($original);
});

test('rejects thumbnails for unpublished documents and non image files', function () {
    $this->actingAs(documentResident());

    foreach ([9, 10, 9999] as $document) {
        $this->get(route('resident.documents.preview', ['document' => $document, 'thumbnail' => true]))->assertNotFound();
    }

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/download'));
});

test('requires login before serving a thumbnail', function () {
    $this->get(route('resident.documents.preview', ['document' => 12, 'thumbnail' => true]))->assertRedirect(route('login'));
});

test('sends the original image when this server cannot make a thumbnail', function () {
    Http::preventStrayRequests();
    Http::fake(['*/docs/12/download' => Http::response('not-really-a-jpeg', 200, ['Content-Type' => 'image/jpeg'])]);
    $this->actingAs(documentResident());

    $response = $this->get(route('resident.documents.preview', ['document' => 12, 'thumbnail' => true]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');

    expect($response->streamedContent())->toBe('not-really-a-jpeg');
});
