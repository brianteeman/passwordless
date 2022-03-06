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

namespace Akeeba\Passwordless\CBOR\OtherObject;

use Akeeba\Passwordless\Brick\Math\BigInteger;
use Akeeba\Passwordless\CBOR\OtherObject as Base;
use Akeeba\Passwordless\CBOR\Utils;
use const INF;
use InvalidArgumentException;
use const NAN;

final class SinglePrecisionFloatObject extends \Akeeba\Passwordless\CBOR\OtherObject
{
    public static function supportedAdditionalInformation(): array
    {
        return [self::OBJECT_SINGLE_PRECISION_FLOAT];
    }

    public static function createFromLoadedData(int $additionalInformation, ?string $data): \Akeeba\Passwordless\CBOR\OtherObject
    {
        return new self($additionalInformation, $data);
    }

    public static function create(string $value): self
    {
        if (mb_strlen($value, '8bit') !== 4) {
            throw new InvalidArgumentException('The value is not a valid single precision floating point');
        }

        return new self(self::OBJECT_SINGLE_PRECISION_FLOAT, $value);
    }

    /**
     * @deprecated The method will be removed on v3.0. Please rely on the CBOR\Normalizable interface
     */
    public function getNormalizedData(bool $ignoreTags = false)
    {
        return $this->normalize();
    }

    /**
     * @return float|int
     */
    public function normalize()
    {
        $exponent = $this->getExponent();
        $mantissa = $this->getMantissa();
        $sign = $this->getSign();

        if ($exponent === 0) {
            $val = $mantissa * 2 ** (-(126 + 23));
        } elseif ($exponent !== 0b11111111) {
            $val = ($mantissa + (1 << 23)) * 2 ** ($exponent - (127 + 23));
        } else {
            $val = $mantissa === 0 ? INF : NAN;
        }

        return $sign * $val;
    }

    public function getExponent(): int
    {
        $data = $this->data;
        \Akeeba\Passwordless\CBOR\Utils::assertString($data, 'Invalid data');

        return \Akeeba\Passwordless\CBOR\Utils::binToBigInteger($data)->shiftedRight(23)->and(\Akeeba\Passwordless\CBOR\Utils::hexToBigInteger('ff'))->toInt();
    }

    public function getMantissa(): int
    {
        $data = $this->data;
        \Akeeba\Passwordless\CBOR\Utils::assertString($data, 'Invalid data');

        return \Akeeba\Passwordless\CBOR\Utils::binToBigInteger($data)->and(\Akeeba\Passwordless\CBOR\Utils::hexToBigInteger('7fffff'))->toInt();
    }

    public function getSign(): int
    {
        $data = $this->data;
        \Akeeba\Passwordless\CBOR\Utils::assertString($data, 'Invalid data');
        $sign = \Akeeba\Passwordless\CBOR\Utils::binToBigInteger($data)->shiftedRight(31);

        return $sign->isEqualTo(\Akeeba\Passwordless\Brick\Math\BigInteger::one()) ? -1 : 1;
    }
}
