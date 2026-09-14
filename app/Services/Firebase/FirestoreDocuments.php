<?php

namespace App\Services\Firebase;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The smallest possible Firestore REST client: read one document, patch one document.
 *
 * This is NOT the Firestore reader the website needs (queries, collections, locale
 * maps — spec §2). It exists because sign-in has two Firestore errands and cannot
 * wait for that reader: check `users/{uid}.blocked`, and keep the customer's profile
 * document in step. When the full reader lands, this folds into it.
 *
 * Calls are made AS THE CUSTOMER, with their Firebase ID token as the bearer, not
 * with a service account. That is the right authority for a document the customer
 * owns, it needs no credentials file on disk, and it is the only shape that will
 * still work once the Firestore rules are tightened — which they must be before this
 * site is reachable (spec §13).
 */
class FirestoreDocuments
{
    public function __construct(
        private readonly string $projectId,
        private readonly int $timeout = 10,
    ) {}

    /**
     * Reads one document. Returns null when it does not exist.
     *
     * @param  string  $path  e.g. `users/g7I1L1sOb6dsCn0O1HmAcBgBfg83`
     *
     * @throws IdentityToolkitException when Firestore could not be reached or refused
     */
    public function get(string $path, string $idToken): ?array
    {
        try {
            $response = Http::withToken($idToken)
                ->acceptJson()
                ->timeout($this->timeout)
                ->get($this->url($path));
        } catch (ConnectionException $e) {
            throw new IdentityToolkitException(IdentityToolkitException::TRANSPORT, $e->getMessage(), $e);
        }

        if ($response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            throw new IdentityToolkitException(
                'FIRESTORE_'.$response->status(),
                (string) $response->json('error.message', $response->body()),
            );
        }

        return FirestoreValue::decodeFields((array) $response->json('fields', []));
    }

    /**
     * Writes ONLY the named fields, leaving every other field on the document alone.
     *
     * The update mask is the whole point. This document is also written by the admin
     * panel and by the mobile app, and it carries fields this site knows nothing
     * about — `blocked` among them. A maskless write would blank them, and blanking
     * `blocked` would quietly un-suspend a customer an operator had suspended.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws IdentityToolkitException
     */
    public function patch(string $path, array $fields, string $idToken): void
    {
        $query = collect(array_keys($fields))
            ->map(fn (string $field) => 'updateMask.fieldPaths='.urlencode($field))
            ->implode('&');

        try {
            $response = Http::withToken($idToken)
                ->acceptJson()
                ->timeout($this->timeout)
                ->patch($this->url($path).'?'.$query, ['fields' => FirestoreValue::encodeFields($fields)]);
        } catch (ConnectionException $e) {
            throw new IdentityToolkitException(IdentityToolkitException::TRANSPORT, $e->getMessage(), $e);
        }

        if ($response->failed()) {
            throw new IdentityToolkitException(
                'FIRESTORE_'.$response->status(),
                (string) $response->json('error.message', $response->body()),
            );
        }
    }

    private function url(string $path): string
    {
        return 'https://firestore.googleapis.com/v1/projects/'.$this->projectId
            .'/databases/(default)/documents/'.$path;
    }

}
