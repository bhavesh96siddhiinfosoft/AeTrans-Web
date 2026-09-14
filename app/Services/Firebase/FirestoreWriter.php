<?php

namespace App\Services\Firebase;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Firestore reads and writes inside a REAL transaction, over the REST API.
 *
 * This is what makes a booking safe to write. The panel's own booking screen says so
 * in its comments: a check made before a write is ADVISORY, because the website can
 * sell the same van in the gap between the check and the save. Firestore's web SDK
 * cannot run a query inside a transaction, so the panel has to live with that gap.
 * The REST API can, which is why this exists here and not there.
 *
 * The shape of a read-write transaction:
 *
 *   begin()                     → a transaction id
 *   query(...)  / get(...)      → reads, RECORDED against that id
 *   commit(writes)              → succeeds only if nothing read has changed since
 *
 * If another writer touched anything that was read, the commit fails with ABORTED and
 * the caller starts again. That is the whole guarantee, and it is why the reads have
 * to happen through here rather than through the ordinary reader.
 *
 * Authorised as the CUSTOMER, with their Firebase ID token. They are signed in by the
 * time a booking is written — that is the point of the sign-in gate — and a customer
 * writing their own booking is the right authority for it. No service account needed.
 */
class FirestoreWriter
{
    public function __construct(
        private readonly string $projectId,
        private readonly int $timeout = 15,
    ) {}

    /** @throws IdentityToolkitException */
    public function begin(string $idToken): string
    {
        $response = $this->post('documents:beginTransaction', $idToken, [
            // READ_WRITE, which is the default, and stated rather than assumed: a
            // read-only transaction would accept the reads and refuse the commit.
            'options' => ['readWrite' => (object) []],
        ]);

        $transaction = $response['transaction'] ?? null;

        if (! $transaction) {
            throw new IdentityToolkitException('FIRESTORE_NO_TRANSACTION');
        }

        return $transaction;
    }

    /**
     * Every document in a collection where one field equals a value, read INSIDE the
     * transaction so the commit can be checked against it.
     *
     * A single equality filter on purpose: anything richer needs a composite index in
     * Firestore, and an index that has not been created makes the query fail at
     * runtime rather than at deploy time. The remaining narrowing is done in PHP over
     * what comes back, which for one vehicle's locks is a handful of rows.
     *
     * @return array<string, array<string, mixed>> keyed by document id
     *
     * @throws IdentityToolkitException
     */
    public function query(string $collection, string $field, mixed $value, string $idToken, string $transaction): array
    {
        $response = $this->post('documents:runQuery', $idToken, [
            'structuredQuery' => [
                'from' => [['collectionId' => $collection]],
                'where' => [
                    'fieldFilter' => [
                        'field' => ['fieldPath' => $field],
                        'op' => 'EQUAL',
                        'value' => FirestoreValue::encode($value),
                    ],
                ],
            ],
            'transaction' => $transaction,
        ]);

        $documents = [];

        foreach ($response as $row) {
            // A query with no matches still answers, with a single row carrying only a
            // read time. Skipping anything without a `document` is what makes that an
            // empty list rather than a null entry the caller has to guard against.
            if (! isset($row['document']['name'])) {
                continue;
            }

            $documents[basename((string) $row['document']['name'])] = FirestoreValue::decodeFields(
                (array) ($row['document']['fields'] ?? [])
            );
        }

        return $documents;
    }

    /**
     * Applies every write at once, or none of them.
     *
     * @param  array<int, array<string, mixed>>  $writes  as Firestore's `Write` objects
     *
     * @throws IdentityToolkitException ABORTED when someone else got there first
     */
    public function commit(array $writes, string $idToken, string $transaction = ''): void
    {
        $payload = ['writes' => $writes];

        /*
         * A commit with no transaction is STILL all-or-nothing — Firestore applies every
         * write in one batch or none of them, and enforces each write's precondition
         * server-side. That is the whole guarantee this class needs, and it is why the
         * booking write no longer opens a transaction at all: see BookingReservation.
         */
        if ($transaction !== '') {
            $payload['transaction'] = $transaction;
        }

        $this->post('documents:commit', $idToken, $payload);
    }

    /** Abandons a transaction so Firestore does not hold its locks until they lapse. */
    public function rollback(string $idToken, string $transaction): void
    {
        try {
            $this->post('documents:rollback', $idToken, ['transaction' => $transaction]);
        } catch (IdentityToolkitException) {
            // Nothing useful to do: the transaction expires on its own, and the caller
            // is already handling whatever went wrong before this.
        }
    }

    /**
     * A `Write` that CREATES a document and fails if it already exists.
     *
     * The precondition is the lock. Two customers booking the same van for the same day
     * both try to create `{unit}_{day}`; the second commit fails, and the second
     * customer is told the van has gone rather than being sold it as well.
     *
     * @param  array<string, mixed>  $fields
     */
    public function createOnly(string $path, array $fields): array
    {
        return [
            'update' => [
                'name' => $this->documentName($path),
                'fields' => FirestoreValue::encodeFields($fields),
            ],
            'currentDocument' => ['exists' => false],
        ];
    }

    /**
     * A `Write` that creates or replaces a document outright.
     *
     * @param  array<string, mixed>  $fields
     */
    public function set(string $path, array $fields): array
    {
        return [
            'update' => [
                'name' => $this->documentName($path),
                'fields' => FirestoreValue::encodeFields($fields),
            ],
        ];
    }

    /**
     * A `Write` that adds to a number ATOMICALLY, on the server.
     *
     * For counters two customers can touch at the same moment — `coupon.usedCount` is
     * the only one so far. Read-modify-write would lose one of two simultaneous
     * redemptions and let a coupon with one use left be spent twice; Firestore's own
     * `increment` transform is applied inside its commit, so the arithmetic never
     * travels through this application at all.
     *
     * A transform is its own `Write` in a commit, not a field on an update — which is
     * why this returns a whole write rather than something to merge into `set()`.
     */
    public function increment(string $path, string $field, int $by = 1): array
    {
        return [
            'transform' => [
                'document' => $this->documentName($path),
                'fieldTransforms' => [[
                    'fieldPath' => $field,
                    // Firestore takes 64-bit integers as strings over REST, as it does
                    // everywhere else — see FirestoreValue.
                    'increment' => ['integerValue' => (string) $by],
                ]],
            ],
        ];
    }

    /** A Firestore auto-id: 20 characters from the same alphabet Google uses. */
    public function newId(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $id = '';

        for ($i = 0; $i < 20; $i++) {
            $id .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $id;
    }

    private function documentName(string $path): string
    {
        return 'projects/'.$this->projectId.'/databases/(default)/documents/'.$path;
    }

    /**
     * @throws IdentityToolkitException
     */
    private function post(string $endpoint, string $idToken, array $payload): array
    {
        $url = 'https://firestore.googleapis.com/v1/projects/'.$this->projectId
            .'/databases/(default)/'.$endpoint;

        try {
            $response = Http::withToken($idToken)
                ->acceptJson()
                ->timeout($this->timeout)
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            throw new IdentityToolkitException(IdentityToolkitException::TRANSPORT, $e->getMessage(), $e);
        }

        if ($response->failed()) {
            /*
             * ABORTED is the one the caller cares about — it means someone else
             * committed first — so it is passed through as its own code rather than
             * flattened into a status number.
             */
            $status = (string) $response->json('error.status', 'FIRESTORE_'.$response->status());

            throw new IdentityToolkitException($status, (string) $response->json('error.message', $response->body()));
        }

        return (array) $response->json();
    }
}
