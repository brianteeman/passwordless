<?php
/**
 * @package   AkeebaPasswordlessLogin
 * @copyright Copyright (c)2023 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Plugin\System\Passwordless\Authentication;

defined('_JEXEC') or die();

use Exception;
use Joomla\Application\ApplicationInterface;
use Joomla\CMS\User\User;
use Joomla\Session\SessionInterface;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialSourceRepository;
use Webauthn\PublicKeyCredentialUserEntity;

interface AuthenticationInterface
{
	/**
	 * Public constructor.
	 *
	 * @param   ApplicationInterface|null                 $app       The app we are running in
	 * @param   SessionInterface|null                     $session   The app session object
	 * @param   PublicKeyCredentialSourceRepository|null  $credRepo  Credentials repo
	 *
	 * @since   2.0.0
	 */
	public function __construct(
		?ApplicationInterface                $app = null,
		?SessionInterface                    $session = null,
		?PublicKeyCredentialSourceRepository $credRepo = null
	);

	/**
	 * Returns the Public Key credential source repository object
	 *
	 * @return  PublicKeyCredentialSourceRepository|null
	 *
	 * @since   2.0.0
	 */
	public function getCredentialsRepository(): ?PublicKeyCredentialSourceRepository;

	/**
	 * Returns a User Entity object given a Joomla user
	 *
	 * @param   User  $user  The Joomla user to get the user entity for
	 *
	 * @return  PublicKeyCredentialUserEntity
	 *
	 * @since   2.0.0
	 */
	public function getUserEntity(User $user): PublicKeyCredentialUserEntity;

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
	public function getPubKeyCreationOptions(User $user, bool $resident = false): PublicKeyCredentialCreationOptions;

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
	public function validateAttestationResponse(string $data): PublicKeyCredentialSource;

	/**
	 * Get the public key request options.
	 *
	 * This is used in the first step of the assertion (login) flow.
	 *
	 * @param   User|null  $user  The Joomla user to get the PK request options for
	 *
	 * @return  PublicKeyCredentialRequestOptions
	 *
	 * @throws  Exception
	 * @since   2.0.0
	 */
	public function getPubkeyRequestOptions(?User $user): ?PublicKeyCredentialRequestOptions;

	/**
	 * Validate the authenticator assertion.
	 *
	 * This is used in the second step of the assertion (login) flow. The server verifies that the
	 * assertion generated by the authenticator has not been tampered with.
	 *
	 * @param   string     $data  The data
	 * @param   User|null  $user  The user we are trying to log in
	 *
	 * @return  PublicKeyCredentialSource
	 *
	 * @throws  Exception
	 * @since   2.0.0
	 */
	public function validateAssertionResponse(string $data, ?User $user): PublicKeyCredentialSource;
}