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

namespace Akeeba\Passwordless\Webauthn\AuthenticationExtensions;

class ExtensionOutputCheckerHandler
{
    /**
     * @var ExtensionOutputChecker[]
     */
    private $checkers = [];

    public function add(\Akeeba\Passwordless\Webauthn\AuthenticationExtensions\ExtensionOutputChecker $checker): void
    {
        $this->checkers[] = $checker;
    }

    /**
     * @throws ExtensionOutputError
     */
    public function check(\Akeeba\Passwordless\Webauthn\AuthenticationExtensions\AuthenticationExtensionsClientInputs $inputs, \Akeeba\Passwordless\Webauthn\AuthenticationExtensions\AuthenticationExtensionsClientOutputs $outputs): void
    {
        foreach ($this->checkers as $checker) {
            $checker->check($inputs, $outputs);
        }
    }
}
