<?php

namespace App\Services\Listings;

use App\Services\Propertyware\Data\Property;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Finds a property's photos from our own MLS listings, through tx-cma.
 *
 * Propertyware's API has no building photos, while tx-cma keeps every photo
 * from TexasRenters' HAR listings. Only an exact address match is used, so
 * a unit elsewhere in the same building never stands in for the home.
 */
class ListingPhotos
{
    /**
     * Get the property's listing photos in MLS display order, or none.
     *
     * @return array<int, array{url: string, description: ?string}>
     */
    public function for(Property $property): array
    {
        $url = config('services.tx_cma.url');
        $token = config('services.tx_cma.token');

        if (blank($url) || blank($token) || blank($property->address)) {
            return [];
        }

        $key = 'tx-cma:photos:'.hash('sha256', mb_strtolower($property->fullAddress().'|'.$property->unitName));
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $photos = $this->fetch($url, $token, $property);

        // Only a real answer is kept; a failed lookup is tried again next time.
        if ($photos !== null) {
            Cache::put($key, $photos, now()->addHours((int) config('services.tx_cma.photo_cache_hours', 24)));
        }

        return $photos ?? [];
    }

    /**
     * Ask tx-cma for the listing photos, or null when it couldn't answer.
     *
     * @return array<int, array{url: string, description: ?string}>|null
     */
    private function fetch(string $url, string $token, Property $property): ?array
    {
        try {
            $response = Http::baseUrl(rtrim($url, '/'))
                ->withToken($token)
                ->acceptJson()
                ->timeout(10)
                ->withOptions(['verify' => $this->verify()])
                ->get('api/v1/listings/property', array_filter([
                    'address' => $property->fullAddress(),
                    'unit_number' => $property->unitName,
                ]));
        } catch (ConnectionException $exception) {
            Log::warning('Could not reach tx-cma for listing photos.', ['message' => $exception->getMessage()]);

            return null;
        }

        if ($response->notFound()) {
            return [];
        }

        if ($response->failed()) {
            Log::warning('tx-cma refused a listing photo lookup.', ['status' => $response->status()]);

            return null;
        }

        if ($response->json('match_type') !== 'exact') {
            return [];
        }

        $photos = $response->json('listing.photos');

        return collect(is_array($photos) ? $photos : [])
            ->filter(fn (mixed $photo): bool => is_array($photo) && filled($photo['url'] ?? null))
            ->sortBy(fn (array $photo): int => (int) ($photo['order'] ?? PHP_INT_MAX))
            ->map(fn (array $photo): array => ['url' => (string) $photo['url'], 'description' => $photo['description'] ?? null])
            ->values()
            ->all();
    }

    /**
     * Get the TLS setting: a CA bundle path, or whether to verify at all.
     */
    private function verify(): bool|string
    {
        $verify = config('services.tx_cma.verify', true);

        return is_string($verify) && ! in_array(strtolower($verify), ['true', 'false', '1', '0'], true)
            ? $verify
            : filter_var($verify, FILTER_VALIDATE_BOOLEAN);
    }
}
