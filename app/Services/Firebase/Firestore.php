<?php

namespace App\Services\Firebase;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Server-side Firestore reads, as the application rather than as a customer.
 *
 * This is the reader spec §2 asks for: REST plus Guzzle, never the PHP SDK, because
 * `grpc` is unavailable on this host. It is how a server-rendered page gets the logo,
 * the site name and the footer for a visitor who has no account — the thing that makes
 * a WhatsApp link preview work, since those crawlers run no JavaScript.
 *
 * It NEVER throws. A page that cannot reach Firestore still has to render: the caller
 * gets null or an empty list and falls back to what it knows. Failing a whole page
 * because a tagline was unreadable is the wrong trade for a site whose job is to sell.
 */
class Firestore
{
    public function __construct(
        private readonly string $projectId,
        private readonly ServiceAccount $serviceAccount,
        private readonly int $timeout = 8,
    ) {}

    /**
     * One document as a plain array, or null when it is missing or unreadable.
     *
     * @param  string  $path  e.g. `settings/globalValue`
     */
    public function document(string $path): ?array
    {
        $response = $this->get($this->url($path));

        if ($response === null) {
            return null;
        }

        return FirestoreValue::decodeFields((array) ($response['fields'] ?? []));
    }

    /**
     * Every document in a collection, keyed by document id.
     *
     * Unpaged on purpose, with `pageSize` at Firestore's maximum: the collections this
     * site reads whole — `settings`, `languages`, `services` — are tens of documents,
     * and a paging loop would be machinery guarding against a case that does not exist.
     * `bookings` is never read this way.
     *
     * @return array<string, array<string, mixed>>
     */
    public function collection(string $name, int $limit = 300): array
    {
        $response = $this->get($this->url($name).'?pageSize='.$limit);

        if ($response === null) {
            return [];
        }

        $documents = [];

        foreach ($response['documents'] ?? [] as $document) {
            // The REST API returns the full resource path; the id is its last segment.
            $id = basename((string) ($document['name'] ?? ''));

            if ($id !== '') {
                $documents[$id] = FirestoreValue::decodeFields((array) ($document['fields'] ?? []));
            }
        }

        return $documents;
    }

    /**
     * The documents of a collection whose field equals a value.
     *
     * A structured query, so the customer's own bookings can be fetched without reading
     * everybody's and filtering in PHP — which is both wasteful and a habit that leaks
     * other people's data into this process.
     *
     * ONE equality filter and no ordering, deliberately: that combination is served by
     * Firestore's automatic single-field indexes, and anything more — a second filter, or
     * an `orderBy` on a different field — needs a composite index somebody has to create
     * in the console before the query works at all. Sorting is done in PHP on the handful
     * of documents that come back.
     *
     * @param  string|null  $idToken  the customer's own token, so the query keeps working
     *                                when the rules are tightened to "your own bookings"
     * @return array<string, array<string, mixed>> keyed by document id
     */
    public function query(string $collection, string $field, string $value, ?string $idToken = null, int $limit = 100): array
    {
        $request = Http::acceptJson()->timeout($this->timeout);
        $token = $idToken ?: $this->serviceAccount->token();

        if ($token) {
            $request = $request->withToken($token);
        }

        try {
            // `documents:runQuery`, not `documents/:runQuery` — the endpoint hangs off
            // the collection root itself, and `url()` ends in a slash.
            $response = $request->post(rtrim($this->url(''), '/').':runQuery', [
                'structuredQuery' => [
                    'from' => [['collectionId' => $collection]],
                    'where' => [
                        'fieldFilter' => [
                            'field' => ['fieldPath' => $field],
                            'op' => 'EQUAL',
                            'value' => ['stringValue' => $value],
                        ],
                    ],
                    'limit' => $limit,
                ],
            ]);
        } catch (ConnectionException $e) {
            Log::warning('Firestore could not be reached for a query.', [
                'collection' => $collection, 'message' => $e->getMessage(),
            ]);

            return [];
        }

        if ($response->failed()) {
            Log::warning('Firestore refused a query.', [
                'collection' => $collection,
                'status' => $response->status(),
                'error' => $response->json('error.message'),
            ]);

            return [];
        }

        $documents = [];

        foreach ((array) $response->json() as $row) {
            // A result set with no matches comes back as a single row carrying only a
            // `readTime`, not as an empty list.
            if (! isset($row['document'])) {
                continue;
            }

            $id = basename((string) ($row['document']['name'] ?? ''));

            if ($id !== '') {
                $documents[$id] = FirestoreValue::decodeFields((array) ($row['document']['fields'] ?? []));
            }
        }

        return $documents;
    }

    private function get(string $url): ?array
    {
        $request = Http::acceptJson()->timeout($this->timeout);

        // Unauthenticated when there is no key file. See ServiceAccount for why that
        // still works today, and why it stops working the day the rules are tightened.
        if ($token = $this->serviceAccount->token()) {
            $request = $request->withToken($token);
        }

        try {
            $response = $request->get($url);
        } catch (ConnectionException $e) {
            Log::warning('Firestore could not be reached.', ['url' => $url, 'message' => $e->getMessage()]);

            return null;
        }

        if ($response->status() === 404) {
            // A document that does not exist is an ordinary answer, not a fault: the
            // admin simply has not filled that section in.
            return null;
        }

        if ($response->failed()) {
            Log::warning('Firestore refused a read.', [
                'url' => $url,
                'status' => $response->status(),
                'error' => $response->json('error.message'),
            ]);

            return null;
        }

        return (array) $response->json();
    }

    private function url(string $path): string
    {
        return 'https://firestore.googleapis.com/v1/projects/'.$this->projectId
            .'/databases/(default)/documents/'.$path;
    }
}
