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

namespace Akeeba\Passwordless\CBOR;

use Akeeba\Passwordless\Brick\Math\BigInteger;
use InvalidArgumentException;
use const STR_PAD_LEFT;

/**
 * @final
 */
class NegativeIntegerObject extends \Akeeba\Passwordless\CBOR\AbstractAkeeba\Passwordless\CBORObject implements \Akeeba\Passwordless\CBOR\Normalizable
{
    private const MAJOR_TYPE = self::MAJOR_TYPE_NEGATIVE_INTEGER;

    /**
     * @var string|null
     */
    private $data;

    public function __construct(int $additionalInformation, ?string $data)
    {
        parent::__construct(self::MAJOR_TYPE, $additionalInformation);
        $this->data = $data;
    }

    public function __toString(): string
    {
        $result = parent::__toString();
        if ($this->data !== null) {
            $result .= $this->data;
        }

        return $result;
    }

    public static function createObjectForValue(int $additionalInformation, ?string $data): self
    {
        return new self($additionalInformation, $data);
    }

    public static function create(int $value): self
    {
        return self::createFromString((string) $value);
    }

    public static function createFromString(string $value): self
    {
        $integer = \Akeeba\Passwordless\Brick\Math\BigInteger::of($value);

        return self::createBigInteger($integer);
    }

    public function getValue(): string
    {
        if ($this->data === null) {
            return (string) (-1 - $this->additionalInformation);
        }

        $result = \Akeeba\Passwordless\CBOR\Utils::binToBigInteger($this->data);
        $minusOne = \Akeeba\Passwordless\Brick\Math\BigInteger::of(-1);

        return $minusOne->minus($result)
            ->toBase(10)
        ;
    }

    public function normalize(): string
    {
        return $this->getValue();
    }

    /**
     * @deprecated The method will be removed on v3.0. Please rely on the CBOR\Normalizable interface
     */
    public function getNormalizedData(bool $ignoreTags = false): string
    {
        return $this->getValue();
    }

    private static function createBigInteger(\Akeeba\Passwordless\Brick\Math\BigInteger $integer): self
    {
        if ($integer->isGreaterThanOrEqualTo(\Akeeba\Passwordless\Brick\Math\BigInteger::zero())) {
            throw new InvalidArgumentException('The value must be a negative integer.');
        }

        $minusOne = \Akeeba\Passwordless\Brick\Math\BigInteger::of(-1);
        $computed_value = $minusOne->minus($integer);

        switch (true) {
            case $computed_value->isLessThan(\Akeeba\Passwordless\Brick\Math\BigInteger::of(24)):
                $ai = $computed_value->toInt();
                $data = null;
                break;
            case $computed_value->isLessThan(\Akeeba\Passwordless\Brick\Math\BigInteger::fromBase('FF', 16)):
                $ai = 24;
                $data = self::hex2bin(str_pad($computed_value->toBase(16), 2, '0', STR_PAD_LEFT));
                break;
            case $computed_value->isLessThan(\Akeeba\Passwordless\Brick\Math\BigInteger::fromBase('FFFF', 16)):
                $ai = 25;
                $data = self::hex2bin(str_pad($computed_value->toBase(16), 4, '0', STR_PAD_LEFT));
                break;
            case $computed_value->isLessThan(\Akeeba\Passwordless\Brick\Math\BigInteger::fromBase('FFFFFFFF', 16)):
                $ai = 26;
                $data = self::hex2bin(str_pad($computed_value->toBase(16), 8, '0', STR_PAD_LEFT));
                break;
            default:
                throw new InvalidArgumentException(
                    'Out of range. Please use NegativeBigIntegerTag tag with ByteStringObject object instead.'
                );
        }

        return new self($ai, $data);
    }

    private static function hex2bin(string $data): string
    {
        $result = hex2bin($data);
        if ($result === false) {
            throw new InvalidArgumentException('Unable to convert the data');
        }

        return $result;
    }
}
