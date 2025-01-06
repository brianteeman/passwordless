# Akeeba Passwordless Login

[Download](https://github.com/akeeba/passwordless/releases/latest) • [Documentation](https://github.com/akeeba/passwordless/wiki) • [Source Code](https://github.com/akeeba/passwordless)

An improved WebAuthn and passkey login solution for Joomla!

## What does it do?

This plugin makes **true** passwordless login possible in Joomla!. No entering your username, or password making logging in easier, faster, and **much more secure**. 

Before using this plugin please take a minute to [learn more about passkeys](https://passkeys.dev) and [play with them](https://www.passkeys.io).

## Features

* **No username, no password authentication**. Unlike Joomla's built-in feature, you can create passkeys which let you log into your site without having to enter your username first.
* **Passkey detection**. You can optionally enable a feature which lets the browser auto-detect a passkey is available for logging into your site, showing an unobtrusive prompt to the user to log into your site using this passkey.
* **Backwards compatible**. You can use classic authenticators which require you to enter your username before using them, and even auto-import the ones your users have already set up using Joomla's feature.  
* **Disable password logins**. Each user can select to disable password logins when they have at least two passkeys set up on their user account.

## Isn't this feature already in Joomla?

Not quite. Joomla's WebAuthn/passkey login plugin is a much older version of this plugin which I had contributed back in 2020. Joomla's plugin does not support passkeys which do not require you to type a username, passkey discovery, and disabling password logins.

## Why is this not in newer versions of Joomla?

TL;DR: My code is GPLv3, not GPLv2, and I am no longer willing to relicense it. 

This plugin –just like all of my software– is licensed under the GNU GPLv3-or-later, but Joomla! is licensed under the GPLv2-or-later license. This means that [you can install and use it on your Joomla! site](https://www.gnu.org/licenses/gpl-faq.en.html#v2v3Compatibility), but Joomla! cannot include it in the core as it would [require upgrading its license to GPLv3 (or GPLv3-or-later)](https://www.gnu.org/licenses/gpl-faq.en.html#AllCompatibility) – which it can do anytime by [exercising the “or later” clause in its GPLv2-or-later license](https://www.gnu.org/licenses/gpl-faq.en.html#VersionThreeOrLater)

In the past, I had been relicensing my GPLv3-or-later code to GPLv2-or-later to contribute it back to Joomla. I am no longer willing to do that. That's a political stance to guarantee users' freedom, not some obstinate refusal or caprice. The changes introduced in GPLv3 [are very important](https://www.gnu.org/licenses/rms-why-gplv3). The most important, in my opinion, is that it better aligns with _international_ laws instead of being predominantly US–centric. As it happens, the majority of Joomla! users are, in fact, not in the USA.

## Copyright

Akeeba Passwordless Login – An improved WebAuthn and passkey login solution for Joomla!

Copyright (C) 2020–2025 Akeeba Ltd

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public
License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later
version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied
warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with this program. If not,
see [http://www.gnu.org/licenses/](http://www.gnu.org/licenses/).
