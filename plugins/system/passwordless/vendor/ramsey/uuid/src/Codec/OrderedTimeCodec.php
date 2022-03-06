<?php

/**
 * This file is part of the ramsey/uuid library
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @copyright Copyright (c) Ben Ramsey <ben@benramsey.com>
 * @license http://opensource.org/licenses/MIT MIT
 */

declare(strict_types=1);

namespace Akeeba\Passwordless\Ramsey\Uuid\Codec;

use Akeeba\Passwordless\Ramsey\Uuid\Exception\InvalidArgumentException;
use Akeeba\Passwordless\Ramsey\Uuid\Exception\UnsupportedOperationException;
use Akeeba\Passwordless\Ramsey\Uuid\Rfc4122\FieldsInterface as Rfc4122FieldsInterface;
use Akeeba\Passwordless\Ramsey\Uuid\Uuid;
use Akeeba\Passwordless\Ramsey\Uuid\UuidInterface;

use function strlen;
use function substr;

/**
 * OrderedTimeCodec encodes and decodes a UUID, optimizing the byte order for
 * more efficient storage
 *
 * For binary representations of version 1 UUID, this codec may be used to
 * reorganize the time fields, making the UUID closer to sequential when storing
 * the bytes. According to Percona, this optimization can improve database
 * INSERTs and SELECTs using the UUID column as a key.
 *
 * The string representation of the UUID will remain unchanged. Only the binary
 * representation is reordered.
 *
 * **PLEASE NOTE:** Binary representations of UUIDs encoded with this codec must
 * be decoded with this codec. Decoding using another codec can result in
 * malformed UUIDs.
 *
 * @link https://www.percona.com/blog/2014/12/19/store-uuid-optimized-way/ Storing UUID Values in MySQL
 *
 * @psalm-immutable
 */
class OrderedTimeCodec extends \Akeeba\Passwordless\Ramsey\Uuid\Codec\StringCodec
{
    /**
     * Returns a binary string representation of a UUID, with the timestamp
     * fields rearranged for optimized storage
     *
     * @inheritDoc
     * @psalm-return non-empty-string
     * @psalm-suppress MoreSpecificReturnType we know that the retrieved `string` is never empty
     * @psalm-suppress LessSpecificReturnStatement we know that the retrieved `string` is never empty
     */
    public function encodeBinary(\Akeeba\Passwordless\Ramsey\Uuid\UuidInterface $uuid): string
    {
        if (
            !($uuid->getFields() instanceof \Akeeba\Passwordless\Ramsey\Uuid\Rfc4122\FieldsInterface)
            || $uuid->getFields()->getVersion() !== \Akeeba\Passwordless\Ramsey\Uuid\Uuid::UUID_TYPE_TIME
        ) {
            throw new \Akeeba\Passwordless\Ramsey\Uuid\Exception\InvalidArgumentException(
                'Expected RFC 4122 version 1 (time-based) UUID'
            );
        }

        $bytes = $uuid->getFields()->getBytes();

        /** @phpstan-ignore-next-line PHPStan complains that this is not a non-empty-string. */
        return $bytes[6] . $bytes[7]
            . $bytes[4] . $bytes[5]
            . $bytes[0] . $bytes[1] . $bytes[2] . $bytes[3]
            . substr($bytes, 8);
    }

    /**
     * Returns a UuidInterface derived from an ordered-time binary string
     * representation
     *
     * @throws InvalidArgumentException if $bytes is an invalid length
     *
     * @inheritDoc
     */
    public function decodeBytes(string $bytes): \Akeeba\Passwordless\Ramsey\Uuid\UuidInterface
    {
        if (strlen($bytes) !== 16) {
            throw new \Akeeba\Passwordless\Ramsey\Uuid\Exception\InvalidArgumentException(
                '$bytes string should contain 16 characters.'
            );
        }

        // Rearrange the bytes to their original order.
        $rearrangedBytes = $bytes[4] . $bytes[5] . $bytes[6] . $bytes[7]
            . $bytes[2] . $bytes[3]
            . $bytes[0] . $bytes[1]
            . substr($bytes, 8);

        $uuid = parent::decodeBytes($rearrangedBytes);

        if (
            !($uuid->getFields() instanceof \Akeeba\Passwordless\Ramsey\Uuid\Rfc4122\FieldsInterface)
            || $uuid->getFields()->getVersion() !== \Akeeba\Passwordless\Ramsey\Uuid\Uuid::UUID_TYPE_TIME
        ) {
            throw new \Akeeba\Passwordless\Ramsey\Uuid\Exception\UnsupportedOperationException(
                'Attempting to decode a non-time-based UUID using '
                . 'OrderedTimeCodec'
            );
        }

        return $uuid;
    }
}
