<?php

namespace Tests\Unit;

use App\Services\Firebase\FirestoreValue;
use PHPUnit\Framework\TestCase;

/**
 * How a PHP value reaches Firestore over the REST API.
 *
 * Mostly one thing: **a list must arrive as a list.** The mobile app and the panel read
 * `passengerNames` by position, and Firestore has two shapes that both come from a PHP
 * array — `arrayValue` and `mapValue` — with nothing in the writing code to tell them
 * apart. Getting it wrong is silent here and visible only in the client's console, as a
 * field that expands to `0: …, 1: …` under a map icon instead of an array.
 */
class FirestoreValueTest extends TestCase
{
    /**
     * The shape the client asked for, on 2026-09-02:
     * `passengers: 2` and `passengerNames` as an array.
     */
    public function test_a_passenger_list_is_encoded_as_an_array(): void
    {
        $fields = FirestoreValue::encodeFields([
            'passengers' => 2,
            'passengerNames' => ['Mayank Modi', 'Raj Modi'],
        ]);

        $this->assertSame(['integerValue' => '2'], $fields['passengers']);

        $this->assertSame([
            'arrayValue' => [
                'values' => [
                    ['stringValue' => 'Mayank Modi'],
                    ['stringValue' => 'Raj Modi'],
                ],
            ],
        ], $fields['passengerNames']);
    }

    /**
     * THE TRAP, pinned so nobody has to rediscover it.
     *
     * A SPARSE array — one with a gap in its keys — is not a list to PHP's eyes either,
     * and it encodes as a MAP. That is why both `BookingReservation` and the two
     * controllers run `array_values()` over the names before they go anywhere near a
     * write: unsetting one box, or filtering an empty one out of the middle, is enough
     * to change the type of the field in the client's database.
     */
    public function test_a_sparse_array_becomes_a_map_which_is_why_it_is_reindexed(): void
    {
        $sparse = [0 => 'A', 2 => 'C'];

        $this->assertArrayHasKey(
            'mapValue',
            FirestoreValue::encodeFields(['passengerNames' => $sparse])['passengerNames'],
            'A sparse array encodes as a map — this is the shape that must never be written.',
        );

        // Which `array_values()` fixes, and is the guard the writing code relies on.
        $this->assertArrayHasKey(
            'arrayValue',
            FirestoreValue::encodeFields(['passengerNames' => array_values($sparse)])['passengerNames'],
        );
    }

    /** An empty list is still a list, not a map and not null. */
    public function test_an_empty_list_stays_a_list(): void
    {
        $this->assertSame(
            ['arrayValue' => ['values' => []]],
            FirestoreValue::encodeFields(['passengerNames' => []])['passengerNames'],
        );
    }
}
