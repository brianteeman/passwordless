# Akeeba Passwordless Login

A W3C Webauthn passwordless login solution for Joomla!

## WORK IN PROGRESS

This repository currently represents work in progress. It has not reached a point where it's usable. DO NOT attempt to use on your site. Ignore this warning at your peril but if something breaks don't cry; you get to keep both pieces.

## What does it do?

This plugin lets users link their Joomla user accounts to one or more Webauthn passwordless authentication methods. They can then use these methods for passwordless login into the site.

In practical terms, your users can log into your site using a FIDO U2F hardware key, a FIDO2 hardware key or credentials stored in their device's Trusted Platform Module (e.g. using Windows Hello on Windows 10).

You will most likely need to create template overrides on your site to integrate passwordless authentication. This is not a magic plugin that you activate and get that feature. Joomla is built on the assumption that users always need to provide a username and password when logging in. We have to apply some workarounds to get passwordless authentication working in Joomla. 

For more information and documentation for administrators, users and developers please [consult the documentation Wiki](https://github.com/akeeba/passwordless/wiki).

## Download

Pre-built packages of Akeeba PasswordLess Login are available through [our GitHub repository's Releases page](https://github.com/akeeba/passwordless/releases).

Akeeba Passwordless Login comes with English (Great Britain) language built-in. We do not offer official translations for any other language nor will we accept pull requests for language files. You are welcome to translate to your own language and make the translation available free of charge under the GPLv3 license which the original translation files are licensed under.

## Support policy

The software is provided free of charge, personalised support and development for it is not. We will be happy to discuss bug reports and feature requests free of charge and only through GitHub issues as long as the conditions outlined below are met.

First and foremost, be respectful and understand that discussions are taking place on GitHub in the spirit of collaboration and camaraderie. Do remember that the word “Free” in the Free and Open Source Software (FOSS) acronym refers to freedom _of choice_, not price, as explained [by the Free Software Foundation itself](https://www.gnu.org/philosophy/selling.en.html). Using our free of charge, FOSS software does not create any kind of business or other relationship between us, does not grant you any privileges and does not mean that we are obliged to offer a guaranteed service level free of charge. We only consider bug reports and feature requests in the context of what is best for the target audience of our software. Think of it as tending to the community garden everyone can use and enjoy, not fencing it off so that only specific people are allowed to use it.

Bug reports are only considered when the issue is reproducible given your instructions on a site and hosting _different than yours_. If it can only be reproduced on your site and / or hosting please try to isolate what is causing the problem and give more accurate instructions which helps us reproduce the issue. Only then can we investigate whether it's a legitimate bug with our software or a problem with your site or hosting environment. If your issue does not meet this reproducibility criteria, if it's unrelated to our software or is something already documented we will probably close your GitHub issue without further commentary.

Feature requests will be considered as long as they align with the needs and interests of the target audience of our software. You should understand and accept that we have the final word on what these needs and interests are. We also kindly like you to remember that just because something would be a great fit for _your_ business needs doesn't mean it justifies us spending time to implement and maintain it for everyone and for an extended period of time.

If you find these conditions off-putting you are always welcome to use the free access we provide to [our documentation](https://github.com/akeeba/loginguard/wiki) and source code to find a solution for your issue or implement your new feature / feature change as long as you respect the license of the documentation and our source code.

If you would like to receive personalised support for issues not meeting the “bug report” criteria or you would like us to implement or change a feature we rejected please state so in your GitHub issue either when filing it or when replying to it. In this case we can offer private support or development for a very reasonable fee. Do note that we may choose to decline offering that service if we have legitimate reasons to believe that your request cannot be reasonably fulfilled, is unlawful or we simply do not have availability. 

If you are a developer you are free to submit a pull request with your code fix to an issue / feature change / new feature implementation as long as there is a clear description of what you changed and why. We would greatly appreciate it if you could file a GitHub issue beforehand describing the issue you faced and including the words "PR will be provided shortly" in it, then referencing the GitHub issue number in your PR. This will greatly facilitate our decision making process in reviewing your PR.
 
## Prerequisites

In order to build the installation packages of this component you will need to have the following tools:

* A command line environment. Using Bash under Linux / Mac OS X works best.
* A PHP CLI binary in your path
* Phing installed account-wide on your machine
* Command line Git executables

You will also need the following path structure inside a folder on your system

* **webauthn** This repository
* **buildfiles** [Akeeba Build Tools](https://github.com/akeeba/buildfiles)

You will need to use the exact folder names specified here.

### Useful Phing tasks

All commands are to be run from the `build` directory of this repository.

Create a dev release installation package

		phing git
		
The installable ZIP file is written in the `release` directory inside the repository's root.