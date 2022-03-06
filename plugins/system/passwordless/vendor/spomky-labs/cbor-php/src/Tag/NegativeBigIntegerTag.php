<?php

declare(strict_types=1);

/*
 * The MIT License (MIT)
 *
 * Copyright (c) 2018-2020 Spomky-Labs
 *
 * This software may be modified and distributed under the terms
 * of the MIT license.  See the LICENSE file for details.
 */

namespace Akeeba\Passwordless\CBOR\Tag;

use Akeeba\Passwordless\Brick\Math\BigInteger;
use Akeeba\Passwordless\CBOR\ByteStringObject;
use Akeeba\Passwordless\CBOR\Akeeba\Passwordless\CBORObject;
use Akeeba\Passwordless\CBOR\IndefiniteLengthByteStringObject;
use Akeeba\Passwordless\CBOR\Normalizable;
use Akeeba\Passwordless\CBOR\Tag;
use InvalidArgumentException;

final class NegativeBigIntegerTag extends \Akeeba\Passwordless\CBOR\Tag implements \Akeeba\Passwordless\CBOR\Normalizable
{
    public function __construct(int $additionalInformation, ?string $data, \Akeeba\Passwordless\CBOR\Akeeba\Passwordless\CBORObject $object)
    {
        if (! $object instanceof \Akeeba\Passwordless\CBOR\ByteStringObject && ! $object instanceof \Akeeba\Passwordless\CBOR\IndefiniteLengthByteStringObject) {
            throw new InvalidArgumentException('This tag only accepts a Byte String object.');
        }

        parent::__construct($additionalInformation, $data, $object);
    }

    public static function getTagId(): int
    {
        return self::TAG_NEGATIVE_BIG_NUM;
    }

    public static function createFromLoadedData(int $additionalInformation, ?string $data, \Akeeba\Passwordless\CBOR\Akeeba\Passwordless\CBORObject $object): \Akeeba\Passwordless\CBOR\Tag
    {
        return new self($additionalInformation, $data, $object);
    }

    public static function create(\Akeeba\Passwordless\CBOR\Akeeba\Passwordless\CBORObject $object): \Akeeba\Passwordless\CBOR\Tag
    {
        [$ai, $data] = self::determineComponents(self::TAG_NEGATIVE_BIG_NUM);

        return new self($ai, $data, $object);
    }

    public function normalize(): string
    {
        /** @var ByteStringObject|IndefiniteLengthByteStringObject $object */
        $object = $this->object;
        $integer = \Akeeba\Passwordless\Brick\Math\BigInteger::fromBase(bin2hex($object->getValue()), 16);
        $minusOne = \Akeeba\Passwordless\Brick\Math\BigInteger::of(-1);

        return $minusOne->minus($integer)
            ->toBase(10)
        ;
    }

    /**
     * @deprecated The method will be removed on v3.0. Please rely on the CBOR\Normalizable interface
     */
    public function getNormalizedData(bool $ignoreTags = false)
    {
        if ($ignoreTags) {
            return $this->object->getNormalizedData($ignoreTags);
        }

        if (! $this->object instanceof \Akeeba\Passwordless\CBOR\ByteStringObject) {
            return $this->object->getNormalizedData($ignoreTags);
        }
        $integer = \Akeeba\Passwordless\Brick\Math\BigInteger::fromBase(bin2hex($this->object->getValue()), 16);
        $minusOne = \Akeeba\Passwordless\Brick\Math\BigInteger::of(-1);

        return $minusOne->minus($integer)
            ->toBase(10)
        ;
    }
}
