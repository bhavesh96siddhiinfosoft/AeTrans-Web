<?php

namespace App\Services\Firebase;

/**
 * Firestore's typed JSON ↔ plain PHP.
 *
 * The REST API does not send `{"name": "Aetranse"}`; it sends
 * `{"name": {"stringValue": "Aetranse"}}`, with integers as STRINGS and timestamps as
 * RFC-3339. Every read and write in this application goes through here, so that shape
 * is understood in one place instead of being unpacked at each call site.
 */
class FirestoreValue
{
    /** @param array<string, array> $fields */
    public static function decodeFields(array $fields): array
    {
        return collect($fields)->map(fn ($value) => self::decode($value))->all();
    }

    public static function decode(array $value): mixed
    {
        return match (array_key_first($value)) {
            'stringValue' => $value['stringValue'],
            'booleanValue' => (bool) $value['booleanValue'],
            // Sent as a string because JSON numbers cannot hold a 64-bit integer.
            'integerValue' => (int) $value['integerValue'],
            'doubleValue' => (float) $value['doubleValue'],
            'timestampValue' => $value['timestampValue'],
            'nullValue' => null,
            'mapValue' => self::decodeFields($value['mapValue']['fields'] ?? []),
            'arrayValue' => array_map(
                fn ($item) => self::decode($item),
                $value['arrayValue']['values'] ?? []
            ),
            /*
             * A location, as the panel draws a booking's route from. Both halves are
             * required and both may legitimately be absent from the payload — Firestore
             * omits a zero — so they default to 0 only after the key itself is known to
             * be a geoPoint.
             */
            'geoPointValue' => new GeoPoint(
                (float) ($value['geoPointValue']['latitude'] ?? 0),
                (float) ($value['geoPointValue']['longitude'] ?? 0),
            ),
            // `referenceValue` and `bytesValue`: nothing here reads them, and inventing a
            // shape for them would be a guess a caller might trust.
            default => null,
        };
    }

    /** @param array<string, mixed> $fields */
    public static function encodeFields(array $fields): array
    {
        return collect($fields)->map(fn ($value) => self::encode($value))->all();
    }

    public static function encode(mixed $value): array
    {
        return match (true) {
            is_null($value) => ['nullValue' => null],
            is_bool($value) => ['booleanValue' => $value],
            is_int($value) => ['integerValue' => (string) $value],
            is_float($value) => ['doubleValue' => $value],
            $value instanceof GeoPoint => [
                'geoPointValue' => [
                    'latitude' => $value->latitude,
                    'longitude' => $value->longitude,
                ],
            ],
            $value instanceof \DateTimeInterface => [
                // Converted to UTC first: the `Z` says UTC, and stamping it on a local
                // time would shift every timestamp by the server's offset.
                'timestampValue' => \DateTimeImmutable::createFromInterface($value)
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format('Y-m-d\TH:i:s\Z'),
            ],
            is_array($value) && array_is_list($value) => [
                'arrayValue' => ['values' => array_map(fn ($item) => self::encode($item), $value)],
            ],
            is_array($value) => ['mapValue' => ['fields' => self::encodeFields($value)]],
            default => ['stringValue' => (string) $value],
        };
    }
}
