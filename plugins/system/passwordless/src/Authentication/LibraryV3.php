<?php
/**
 * @package   AkeebaPasswordlessLogin
 * @copyright Copyright (c)2023 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

/**
 * @package     Akeeba\Plugin\System\Passwordless\Authentication
 * @subpackage
 *
 * @copyright   A copyright
 * @license     A "Slug" license name e.g. GPL2
 */

namespace Akeeba\Plugin\System\Passwordless\Authentication;

defined('_JEXEC') or die();

use Akeeba\Plugin\System\Passwordless\Authentication\LibraryV3\Server;
use Exception;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Laminas\Diactoros\ServerRequestFactory;
use RuntimeException;
use Webauthn\AuthenticationExtensions\AuthenticationExtensionsClientInputs;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialSource;

/**
 * Authentication helper for the PHP WebAuthn library version 3, included in Joomla 4
 *
 * @since       2.0.0
 */
class LibraryV3 extends AbstractAuthentication
{
	/**
	 * Generate the public key creation options.
	 *
	 * This is used for the first step of attestation (key registration).
	 *
	 * The PK creation options and the user ID are stored in the session.
	 *
	 * @param   User  $user      The Joomla user to create the public key for
	 * @param   bool  $resident  Should I request a resident authenticator?
	 *
	 * @return  PublicKeyCredentialCreationOptions
	 *
	 * @throws  Exception
	 * @since   2.0.0
	 */
	final public function getPubKeyCreationOptions(User $user, bool $resident = false): PublicKeyCredentialCreationOptions
	{
		$attestationMode = PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE;

		$publicKeyCredentialCreationOptions = $this->getWebauthnServer()->generatePublicKeyCredentialCreationOptions(
			$this->getUserEntity($user),
			$attestationMode,
			$this->getPubKeyDescriptorsForUser($user),
			new AuthenticatorSelectionCriteria(
				AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_NO_PREFERENCE,
				$resident,
				$resident ? AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED : AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED
			),
			new AuthenticationExtensionsClientInputs()
		);

		// Save data in the session
		$this->session->set('plg_system_passwordless.publicKeyCredentialCreationOptions', base64_encode(serialize($publicKeyCredentialCreationOptions)));
		$this->session->set('plg_system_passwordless.registration_user_id', $user->id);

		return $publicKeyCredentialCreationOptions;
	}

	/**
	 * Get the public key request options.
	 *
	 * This is used in the first step of the assertion (login) flow.
	 *
	 * @param   User  $user  The Joomla user to get the PK request options for
	 *
	 * @return  PublicKeyCredentialRequestOptions
	 *
	 * @throws  Exception
	 * @since   2.0.0
	 */
	final public function getPubkeyRequestOptions(?User $user): ?PublicKeyCredentialRequestOptions
	{
		Log::add('Creating PK request options', Log::DEBUG, 'plg_system_passwordless');
		$publicKeyCredentialDescriptors    = is_null($user)
			? []
			: $this->getPubKeyDescriptorsForUser($user);
		$publicKeyCredentialRequestOptions = $this->getWebauthnServer()->generatePublicKeyCredentialRequestOptions(
			PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED,
			$publicKeyCredentialDescriptors
		);

		// Save in session. This is used during the verification stage to prevent replay attacks.
		$this->session->set('plg_system_passwordless.publicKeyCredentialRequestOptions', base64_encode(serialize($publicKeyCredentialRequestOptions)));

		return $publicKeyCredentialRequestOptions;
	}

	/**
	 * Validate the authenticator assertion.
	 *
	 * This is used in the second step of the assertion (login) flow. The server verifies that the
	 * assertion generated by the authenticator has not been tampered with.
	 *
	 * @param   string  $data  The data
	 * @param   User    $user  The user we are trying to log in
	 *
	 * @return  PublicKeyCredentialSource
	 *
	 * @throws Exception
	 * @since   2.0.0
	 */
	final public function validateAssertionResponse(string $data, ?User $user): PublicKeyCredentialSource
	{
		// Make sure the public key credential request options in the session are valid
		$encodedPkOptions                  = $this->session->get('plg_system_passwordless.publicKeyCredentialRequestOptions', null);
		$serializedOptions                 = base64_decode($encodedPkOptions);
		$publicKeyCredentialRequestOptions = unserialize($serializedOptions);

		if (!is_object($publicKeyCredentialRequestOptions)
			|| empty($publicKeyCredentialRequestOptions)
			|| !($publicKeyCredentialRequestOptions instanceof PublicKeyCredentialRequestOptions))
		{
			Log::add('Cannot retrieve valid plg_system_passwordless.publicKeyCredentialRequestOptions from the session', Log::NOTICE, 'plg_system_passwordless');
			throw new RuntimeException(Text::_('PLG_SYSTEM_PASSWORDLESS_ERR_CREATE_INVALID_LOGIN_REQUEST'));
		}

		$data = base64_decode($data);

		if (empty($data))
		{
			Log::add('No or invalid assertion data received from the browser', Log::NOTICE, 'plg_system_passwordless');

			throw new RuntimeException(Text::_('PLG_SYSTEM_PASSWORDLESS_ERR_CREATE_INVALID_LOGIN_REQUEST'));
		}

		return $this->getWebauthnServer()->loadAndCheckAssertionResponse(
			$data,
			$this->getPKCredentialRequestOptions(),
			is_null($user) ? null : $this->getUserEntity($user),
			ServerRequestFactory::fromGlobals()
		);
	}

	/**
	 * Validate the authenticator attestation.
	 *
	 * This is used for the second step of attestation (key registration), when the user has
	 * interacted with the authenticator and we need to validate the legitimacy of its response.
	 *
	 * An exception will be returned on error. Also, under very rare conditions, you may receive
	 * NULL instead of a PublicKeyCredentialSource object which means that something was off in the
	 * returned data from the browser.
	 *
	 * @param   string  $data  The data
	 *
	 * @return  PublicKeyCredentialSource|null
	 *
	 * @throws  Exception
	 * @since   2.0.0
	 */
	final public function validateAttestationResponse(string $data): PublicKeyCredentialSource
	{
		// Retrieve the PublicKeyCredentialCreationOptions object created earlier and perform sanity checks
		$encodedOptions = $this->session->get('plg_system_passwordless.publicKeyCredentialCreationOptions', null);

		if (empty($encodedOptions))
		{
			Log::add('Cannot retrieve plg_system_passwordless.publicKeyCredentialCreationOptions from the session', Log::NOTICE, 'plg_system_passwordless');

			throw new RuntimeException(Text::_('PLG_SYSTEM_PASSWORDLESS_ERR_CREATE_NO_PK'));
		}

		/** @var PublicKeyCredentialCreationOptions|null $publicKeyCredentialCreationOptions */
		try
		{
			$publicKeyCredentialCreationOptions = unserialize(base64_decode($encodedOptions));
		}
		catch (Exception $e)
		{
			Log::add('The plg_system_passwordless.publicKeyCredentialCreationOptions in the session is invalid', Log::NOTICE, 'plg_system_passwordless');
			$publicKeyCredentialCreationOptions = null;
		}

		if (!is_object($publicKeyCredentialCreationOptions) || !($publicKeyCredentialCreationOptions instanceof PublicKeyCredentialCreationOptions))
		{
			throw new RuntimeException(Text::_('PLG_SYSTEM_PASSWORDLESS_ERR_CREATE_NO_PK'));
		}

		// Retrieve the stored user ID and make sure it's the same one in the request.
		$storedUserId = $this->session->get('plg_system_passwordless.registration_user_id', 0);
		$myUser       = $this->app->getIdentity() ?? new User();
		$myUserId     = $myUser->id;

		if (($myUser->guest) || ($myUserId != $storedUserId))
		{
			$message = sprintf('Invalid user! We asked the authenticator to attest user ID %d, the current user ID is %d', $storedUserId, $myUserId);
			Log::add($message, Log::NOTICE, 'plg_system_passwordless');

			throw new RuntimeException(Text::_('PLG_SYSTEM_PASSWORDLESS_ERR_CREATE_INVALID_USER'));
		}

		// We init the PSR-7 request object using Diactoros
		return $this->getWebauthnServer()->loadAndCheckAttestationResponse(
			base64_decode($data),
			$publicKeyCredentialCreationOptions,
			ServerRequestFactory::fromGlobals()
		);
	}

	/**
	 * Get the WebAuthn library's Server object which facilitates WebAuthn operations
	 *
	 * @return  Server
	 * @throws  Exception
	 * @since    2.0.0
	 */
	final private function getWebauthnServer(): \Webauthn\Server
	{
		$siteName = $this->app->get('sitename');

		// Credentials repository
		$repository = $this->credentialsRepository;

		// Relaying Party -- Our site
		$rpEntity = new PublicKeyCredentialRpEntity(
			$siteName,
			Uri::getInstance()->toString(['host']),
			$this->getSiteIcon()
		);

		$server = new Server($rpEntity, $repository);

		// Ed25519 is only available with libsodium
		if (!function_exists('sodium_crypto_sign_seed_keypair'))
		{
			$server->setSelectedAlgorithms(['RS256', 'RS512', 'PS256', 'PS512', 'ES256', 'ES512']);
		}

		return $server;
	}

	/**
	 * Retrieve the public key credential request options saved in the session.
	 *
	 * If they do not exist or are corrupt it is a hacking attempt and we politely tell the
	 * attacker to go away.
	 *
	 * @return  PublicKeyCredentialRequestOptions
	 *
	 * @throws  Exception
	 * @since   2.0.0
	 */
	final private function getPKCredentialRequestOptions(): PublicKeyCredentialRequestOptions
	{
		$encodedOptions = $this->session->get('plg_system_passwordless.publicKeyCredentialRequestOptions', null);

		if (empty($encodedOptions))
		{
			Log::add('Cannot retrieve plg_system_passwordless.publicKeyCredentialRequestOptions from the session', Log::NOTICE, 'plg_system_passwordless');

			throw new RuntimeException(Text::_('PLG_SYSTEM_PASSWORDLESS_ERR_CREATE_INVALID_LOGIN_REQUEST'));
		}

		try
		{
			$publicKeyCredentialRequestOptions = unserialize(base64_decode($encodedOptions));
		}
		catch (Exception $e)
		{
			Log::add('Invalid plg_system_passwordless.publicKeyCredentialRequestOptions in the session', Log::NOTICE, 'plg_system_passwordless');

			throw new RuntimeException(Text::_('PLG_SYSTEM_PASSWORDLESS_ERR_CREATE_INVALID_LOGIN_REQUEST'));
		}

		if (!is_object($publicKeyCredentialRequestOptions) || !($publicKeyCredentialRequestOptions instanceof PublicKeyCredentialRequestOptions))
		{
			throw new RuntimeException(Text::_('PLG_SYSTEM_PASSWORDLESS_ERR_CREATE_INVALID_LOGIN_REQUEST'));
		}

		return $publicKeyCredentialRequestOptions;
	}
}