<?php

declare(strict_types=1);

/*
 * The MIT License (MIT)
 *
 * Copyright (c) 2014-2021 Spomky-Labs
 *
 * This software may be modified and distributed under the terms
 * of the MIT license.  See the LICENSE file for details.
 */

namespace Akeeba\Passwordless\Webauthn;

use function array_key_exists;
use ArrayIterator;
use Akeeba\Passwordless\Assert\Akeeba\Passwordless\Assertion;
use function count;
use Countable;
use Iterator;
use IteratorAggregate;
use JsonSerializable;
use function Akeeba\Passwordless\Safe\json_decode;

class PublicKeyCredentialDescriptorCollection implements JsonSerializable, Countable, IteratorAggregate
{
    /**
     * @var PublicKeyCredentialDescriptor[]
     */
    private $publicKeyCredentialDescriptors = [];

    public function add(\Akeeba\Passwordless\Webauthn\PublicKeyCredentialDescriptor $publicKeyCredentialDescriptor): void
    {
        $this->publicKeyCredentialDescriptors[$publicKeyCredentialDescriptor->getId()] = $publicKeyCredentialDescriptor;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->publicKeyCredentialDescriptors);
    }

    public function remove(string $id): void
    {
        if (!$this->has($id)) {
            return;
        }

        unset($this->publicKeyCredentialDescriptors[$id]);
    }

    /**
     * @return Iterator<string, PublicKeyCredentialDescriptor>
     */
    public function getIterator(): Iterator
    {
        return new ArrayIterator($this->publicKeyCredentialDescriptors);
    }

    public function count(int $mode = COUNT_NORMAL): int
    {
        return count($this->publicKeyCredentialDescriptors, $mode);
    }

    /**
     * @return array[]
     */
    public function jsonSerialize(): array
    {
        return array_map(static function (\Akeeba\Passwordless\Webauthn\PublicKeyCredentialDescriptor $object): array {
            return $object->jsonSerialize();
        }, $this->publicKeyCredentialDescriptors);
    }

    public static function createFromString(string $data): self
    {
        $data = \Akeeba\Passwordless\Safe\json_decode($data, true);
        \Akeeba\Passwordless\Assert\Akeeba\Passwordless\Assertion::isArray($data, 'Invalid data');

        return self::createFromArray($data);
    }

    /**
     * @param mixed[] $json
     */
    public static function createFromArray(array $json): self
    {
        $collection = new self();
        foreach ($json as $item) {
            $collection->add(\Akeeba\Passwordless\Webauthn\PublicKeyCredentialDescriptor::createFromArray($item));
        }

        return $collection;
    }
}
