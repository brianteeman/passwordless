<?php
/**
 * @package   AkeebaPasswordlessLogin
 * @copyright Copyright (c)2023 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Plugin\System\Passwordless\Authentication;

defined('_JEXEC') or die();

use Akeeba\Plugin\System\Passwordless\Dependencies\Cose\Algorithm\Manager;
use Akeeba\Plugin\System\Passwordless\Dependencies\Cose\Algorithm\Signature\ECDSA;
use Akeeba\Plugin\System\Passwordless\Dependencies\Cose\Algorithm\Signature\EdDSA;
use Akeeba\Plugin\System\Passwordless\Dependencies\Cose\Algorithm\Signature\RSA;
use Akeeba\Plugin\System\Passwordless\Dependencies\Cose\Algorithms;
use Akeeba\Plugin\System\Passwordless\Dependencies\Laminas\Diactoros\ServerRequestFactory;
use Akeeba\Plugin\System\Passwordless\Dependencies\ParagonIE\ConstantTime\Base64;
use Akeeba\Plugin\System\Passwordless\Dependencies\ParagonIE\ConstantTime\Base64UrlSafe;
use Akeeba\Plugin\System\Passwordless\Dependencies\Webauthn\AttestationStatement\AndroidKeyAttestationStatementSupport;
use Akeeba\Plugin\System\Passwordless\Dependencies\Webauthn\AttestationStatement\AppleAttestationStatementSupport;
use Akeeba\Plugin\System\Passwordless\Dependencies\Webauthn\AttestationStatement\AttestationObjectLoader;
use Akeeba\Plugin\System\Passwordless\Dependencies\Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Akeeba\Plugin\System\Passwordless\Dependencies\Webauthn\AttestationStatement\FidoU2FAttestationStatementSupport;
use Akeeba\Plugin\System\Passwordless\Dependencies\Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Akeeba\Plugin\System\Passwordless\Dependencies\Webauthn\AttestationStatement\PackedAttestationStatementSupport;
use Akeeba\Plugin\System\Passwordless\Dependencies\Webauthn\AttestationStatement\TPMAttestationStatementSupport;
use Akeeba\Plugin\System\Passwordless\Dependencies\Webauthn\AuthenticationExtensions\AuthenticationExtensionsClientInputs;
use Akeeba\Plugin\System\Passwordless\Dependencies\Webauthn\AuthenticationExtensions\ExtensionOutputCheckerHandler;
use Akeeba\Plugin\System\Passwordless\Dependencies\Webauthn\AuthenticatorAssertionResponse;
use Akeeba\Plugin\System\Passwordless\Dependencies\Webauthn\AuthenticatorAssertionResponseValidator;
use Akeeba\Plugin\System\Passwordless\Dependencies\Webauthn\AuthenticatorAttestationResponse;
use Akeeba\Plugin\System\Passwordless\Dependencies\Webauthn\AuthenticatorAttestationResponseValidator;
use Akeeba\Plugin\System\Passwordless\Dependencies\Webauthn\AuthenticatorSelectionCriteria;
use Akeeba\Plugin\System\Passwordless\Dependencies\Webauthn\PublicKeyCredentialCreationOptions;
use Akeeba\Plugin\System\Passwordless\Dependencies\Webauthn\PublicKeyCredentialDescriptor;
use Akeeba\Plugin\System\Passwordless\Dependencies\Webauthn\PublicKeyCredentialLoader;
use Akeeba\Plugin\System\Passwordless\Dependencies\Webauthn\PublicKeyCredentialParameters;
use Akeeba\Plugin\System\Passwordless\Dependencies\Webauthn\PublicKeyCredentialRequestOptions;
use Akeeba\Plugin\System\Passwordless\Dependencies\Webauthn\PublicKeyCredentialRpEntity;
use Akeeba\Plugin\System\Passwordless\Dependencies\Webauthn\PublicKeyCredentialSource;
use Akeeba\Plugin\System\Passwordless\Dependencies\Webauthn\TokenBinding\TokenBindingNotSupportedHandler;
use Exception;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use RuntimeException;

/**
 * Authentication helper for the PHP WebAuthn library version 4, included in Joomla 5
 *
 * @since       2.0.0
 */
class LibraryV4 extends AbstractAuthentication
{

	/**
	 * @inheritDoc
	 */
	final public function getPubKeyCreationOptions(User $user, bool $resident = false
	): PublicKeyCredentialCreationOptions
	{
		$siteName = $this->app->get('sitename', 'Joomla! Site');

		// Relaying Party -- Our site
		$rpEntity = new PublicKeyCredentialRpEntity(
			$siteName,
			Uri::getInstance()->toString(['host']),
			$this->getSiteIcon()
		);

		// User Entity
		$userEntity = $this->getUserEntity($user);

		// Challenge
		$challenge = random_bytes(32);

		// Public Key Credential Parameters
		$publicKeyCredentialParametersList = [
			// Prefer ECDSA (keys based on Elliptic Curve Cryptography with NIST P-521, P-384 or P-256)
			new PublicKeyCredentialParameters('public-key', Algorithms::COSE_ALGORITHM_ES512),
			new PublicKeyCredentialParameters('public-key', Algorithms::COSE_ALGORITHM_ES384),
			new PublicKeyCredentialParameters('public-key', Algorithms::COSE_ALGORITHM_ES256),
			// Fall back to RSASSA-PSS when ECC is not available. Minimal storage for resident keys available for these.
			new PublicKeyCredentialParameters('public-key', Algorithms::COSE_ALGORITHM_PS512),
			new PublicKeyCredentialParameters('public-key', Algorithms::COSE_ALGORITHM_PS384),
			new PublicKeyCredentialParameters('public-key', Algorithms::COSE_ALGORITHM_PS256),
			// Shared secret w/ HKDF and SHA-512
			new PublicKeyCredentialParameters('public-key', Algorithms::COSE_ALGORITHM_DIRECT_HKDF_SHA_512),
			new PublicKeyCredentialParameters('public-key', Algorithms::COSE_ALGORITHM_DIRECT_HKDF_SHA_256),
			// Shared secret w/ AES-MAC 256-bit key
			new PublicKeyCredentialParameters('public-key', Algorithms::COSE_ALGORITHM_DIRECT_HKDF_AES_256),
		];

		// If libsodium is enabled prefer Edwards-curve Digital Signature Algorithm (EdDSA)
		if (function_exists('sodium_crypto_sign_seed_keypair'))
		{
			array_unshift(
				$publicKeyCredentialParametersList,
				new PublicKeyCredentialParameters('public-key', Algorithms::COSE_ALGORITHM_EDDSA)
			);
		}

		// Timeout: 60 seconds (given in milliseconds)
		$timeout = 60000;

		// Devices to exclude (already set up authenticators)
		$excludedPublicKeyDescriptors = [];
		$records                      = $this->getCredentialsRepository()->findAllForUserEntity($userEntity);

		foreach ($records as $record)
		{
			$excludedPublicKeyDescriptors[] = new PublicKeyCredentialDescriptor(
				$record->getType(), $record->getCredentialPublicKey()
			);
		}

		$authenticatorAttachment = AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_NO_PREFERENCE;

		// Authenticator Selection Criteria (we used default values)
		$authenticatorSelectionCriteria = (new AuthenticatorSelectionCriteria())
			->setAuthenticatorAttachment($authenticatorAttachment)
			->setResidentKey(
				$resident
					? AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED
					: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_DISCOURAGED
			)
			->setUserVerification(
				AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED
			);

		// Extensions (not yet supported by the library)
		$extensions = new AuthenticationExtensionsClientInputs;

		// Attestation preference
		$attestationPreference = PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE;

		// Public key credential creation options
		$publicKeyCredentialCreationOptions = new PublicKeyCredentialCreationOptions(
			$rpEntity,
			$userEntity,
			$challenge,
			$publicKeyCredentialParametersList
		);
		$publicKeyCredentialCreationOptions->setTimeout($timeout);
		$publicKeyCredentialCreationOptions->excludeCredentials(...$excludedPublicKeyDescriptors);
		$publicKeyCredentialCreationOptions->setAuthenticatorSelection($authenticatorSelectionCriteria);
		$publicKeyCredentialCreationOptions->setAttestation($attestationPreference);
		$publicKeyCredentialCreationOptions->setExtensions($extensions);

		// Save data in the session
		$this->session->set(
			'plg_system_passwordless.publicKeyCredentialCreationOptions',
			base64_encode(serialize($publicKeyCredentialCreationOptions))
		);
		$this->session->set('plg_system_passwordless.registration_user_id', $user->id);

		return $publicKeyCredentialCreationOptions;
	}

	/**
	 * @inheritDoc
	 */
	final public function validateAttestationResponse(string $data): PublicKeyCredentialSource
	{
		// Retrieve the PublicKeyCredentialCreationOptions object created earlier and perform sanity checks
		$encodedOptions = $this->session->get('plg_system_passwordless.publicKeyCredentialCreationOptions', null);

		if (empty($encodedOptions))
		{
			Log::add(
				'Cannot retrieve plg_system_passwordless.publicKeyCredentialCreationOptions from the session',
				Log::NOTICE, 'plg_system_passwordless'
			);

			throw new RuntimeException(Text::_('PLG_SYSTEM_PASSWORDLESS_ERR_CREATE_NO_PK'));
		}

		/** @var PublicKeyCredentialCreationOptions|null $publicKeyCredentialCreationOptions */
		try
		{
			$publicKeyCredentialCreationOptions = unserialize(base64_decode($encodedOptions));
		}
		catch (Exception $e)
		{
			Log::add(
				'The plg_system_passwordless.publicKeyCredentialCreationOptions in the session is invalid', Log::NOTICE,
				'plg_system_passwordless'
			);
			$publicKeyCredentialCreationOptions = null;
		}

		if (!is_object($publicKeyCredentialCreationOptions)
		    || !($publicKeyCredentialCreationOptions instanceof PublicKeyCredentialCreationOptions))
		{
			throw new RuntimeException(Text::_('PLG_SYSTEM_PASSWORDLESS_ERR_CREATE_NO_PK'));
		}

		// Retrieve the stored user ID and make sure it's the same one in the request.
		$storedUserId = $this->session->get('plg_system_passwordless.registration_user_id', 0);
		$myUser       = $this->app->getIdentity() ?? new User();
		$myUserId     = $myUser->id;

		if (($myUser->guest) || ($myUserId != $storedUserId))
		{
			$message = sprintf(
				'Invalid user! We asked the authenticator to attest user ID %d, the current user ID is %d',
				$storedUserId, $myUserId
			);
			Log::add($message, Log::NOTICE, 'plg_system_passwordless');

			throw new RuntimeException(Text::_('PLG_SYSTEM_PASSWORDLESS_ERR_CREATE_INVALID_USER'));
		}

		// Cose Algorithm Manager
		$coseAlgorithmManager = (new Manager())
			->add(new ECDSA\ES256())
			->add(new ECDSA\ES512())
			->add(new EdDSA\EdDSA())
			->add(new RSA\RS1())
			->add(new RSA\RS256())
			->add(new RSA\RS512());

		// The token binding handler
		$tokenBindingHandler = new TokenBindingNotSupportedHandler();

		// Attestation Statement Support Manager
		$attestationStatementSupportManager = new AttestationStatementSupportManager();
		$attestationStatementSupportManager->add(new NoneAttestationStatementSupport());
		$attestationStatementSupportManager->add(new AndroidKeyAttestationStatementSupport());
		$attestationStatementSupportManager->add(new AppleAttestationStatementSupport());
		$attestationStatementSupportManager->add(new FidoU2FAttestationStatementSupport());
		$attestationStatementSupportManager->add(new TPMAttestationStatementSupport());
		$attestationStatementSupportManager->add(new PackedAttestationStatementSupport($coseAlgorithmManager));

		// Attestation Object Loader
		$attestationObjectLoader = new AttestationObjectLoader($attestationStatementSupportManager);

		if (isset($this->logger))
		{
			$attestationObjectLoader->setLogger($this->logger);
		}

		// Public Key Credential Loader
		$publicKeyCredentialLoader = new PublicKeyCredentialLoader($attestationObjectLoader);

		if (isset($this->logger))
		{
			$publicKeyCredentialLoader->setLogger($this->logger);
		}

		// Extension output checker handler
		$extensionOutputCheckerHandler = new ExtensionOutputCheckerHandler();

		// Authenticator Attestation Response Validator
		$authenticatorAttestationResponseValidator = new AuthenticatorAttestationResponseValidator(
			$attestationStatementSupportManager,
			$this->getCredentialsRepository(),
			$tokenBindingHandler,
			$extensionOutputCheckerHandler
		);

		if (isset($this->logger))
		{
			$authenticatorAttestationResponseValidator->setLogger($this->logger);
		}

		// Note: Any Throwable from this point will bubble up to the GUI

		// Initialise a PSR-7 request object using Laminas Diactoros
		$request = ServerRequestFactory::fromGlobals();

		// Load the data
		$publicKeyCredential = $publicKeyCredentialLoader->load(
			base64_decode(
				$this->reshapeRegistrationData($data)
			)
		);
		$response            = $publicKeyCredential->getResponse();

		// Check if the response is an Authenticator Attestation Response
		if (!$response instanceof AuthenticatorAttestationResponse)
		{
			throw new RuntimeException('Not an authenticator attestation response');
		}

		// Check the response against the request
		$authenticatorAttestationResponseValidator->check($response, $publicKeyCredentialCreationOptions, $request);

		/**
		 * Everything is OK here. You can get the Public Key Credential Source. This object should be persisted using
		 * the Public Key Credential Source repository.
		 */
		return $authenticatorAttestationResponseValidator->check(
			$response, $publicKeyCredentialCreationOptions, $request
		);
	}

	/**
	 * @inheritDoc
	 */
	final public function getPubkeyRequestOptions(?User $user): ?PublicKeyCredentialRequestOptions
	{
		Log::add('Creating PK request options', Log::DEBUG, 'plg_system_passwordless');
		$registeredPublicKeyCredentialDescriptors = is_null($user)
			? []
			: $this->getPubKeyDescriptorsForUser($user);

		$challenge = random_bytes(32);

		// Extensions
		$extensions = new AuthenticationExtensionsClientInputs();

		// Public Key Credential Request Options
		$publicKeyCredentialRequestOptions = (new PublicKeyCredentialRequestOptions($challenge))
			->setTimeout(60000)
			->allowCredentials(... $registeredPublicKeyCredentialDescriptors)
			->setUserVerification(PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED)
			->setExtensions($extensions);

		// Save in session. This is used during the verification stage to prevent replay attacks.
		$this->session->set(
			'plg_system_passwordless.publicKeyCredentialRequestOptions',
			base64_encode(serialize($publicKeyCredentialRequestOptions))
		);

		return $publicKeyCredentialRequestOptions;
	}

	/**
	 * @inheritDoc
	 */
	final public function validateAssertionResponse(string $data, ?User $user): PublicKeyCredentialSource
	{
		// Make sure the public key credential request options in the session are valid
		$encodedPkOptions                  = $this->session->get(
			'plg_system_passwordless.publicKeyCredentialRequestOptions', null
		);
		$serializedOptions                 = base64_decode($encodedPkOptions);
		$publicKeyCredentialRequestOptions = unserialize($serializedOptions);

		if (!is_object($publicKeyCredentialRequestOptions)
		    || empty($publicKeyCredentialRequestOptions)
		    || !($publicKeyCredentialRequestOptions instanceof PublicKeyCredentialRequestOptions))
		{
			Log::add(
				'Cannot retrieve valid plg_system_passwordless.publicKeyCredentialRequestOptions from the session',
				Log::NOTICE, 'plg_system_passwordless'
			);
			throw new RuntimeException(Text::_('PLG_SYSTEM_PASSWORDLESS_ERR_CREATE_INVALID_LOGIN_REQUEST'));
		}

		$data = base64_decode($data);

		if (empty($data))
		{
			Log::add('No or invalid assertion data received from the browser', Log::NOTICE, 'plg_system_passwordless');

			throw new RuntimeException(Text::_('PLG_SYSTEM_PASSWORDLESS_ERR_CREATE_INVALID_LOGIN_REQUEST'));
		}

		// Cose Algorithm Manager
		$coseAlgorithmManager = (new Manager)
			->add(new ECDSA\ES256)
			->add(new ECDSA\ES512)
			->add(new EdDSA\EdDSA)
			->add(new RSA\RS1)
			->add(new RSA\RS256)
			->add(new RSA\RS512);

		// Attestation Statement Support Manager
		$attestationStatementSupportManager = new AttestationStatementSupportManager();
		$attestationStatementSupportManager->add(new NoneAttestationStatementSupport());
		$attestationStatementSupportManager->add(new AndroidKeyAttestationStatementSupport());
		$attestationStatementSupportManager->add(new AppleAttestationStatementSupport());
		$attestationStatementSupportManager->add(new FidoU2FAttestationStatementSupport());
		$attestationStatementSupportManager->add(new TPMAttestationStatementSupport);
		$attestationStatementSupportManager->add(new PackedAttestationStatementSupport($coseAlgorithmManager));

		// Attestation Object Loader
		$attestationObjectLoader = new AttestationObjectLoader($attestationStatementSupportManager);

		if (isset($this->logger))
		{
			$attestationObjectLoader->setLogger($this->logger);
		}

		// Public Key Credential Loader
		$publicKeyCredentialLoader = new PublicKeyCredentialLoader($attestationObjectLoader);

		if (isset($this->logger))
		{
			$publicKeyCredentialLoader->setLogger($this->logger);
		}

		// The token binding handler
		$tokenBindingHandler = new TokenBindingNotSupportedHandler();

		// Extension Output Checker Handler
		$extensionOutputCheckerHandler = new ExtensionOutputCheckerHandler;

		// Authenticator Assertion Response Validator
		$authenticatorAssertionResponseValidator = new AuthenticatorAssertionResponseValidator(
			$this->getCredentialsRepository(),
			$tokenBindingHandler,
			$extensionOutputCheckerHandler,
			$coseAlgorithmManager
		);

		if (isset($this->logger))
		{
			$authenticatorAssertionResponseValidator->setLogger($this->logger);
		}

		// Initialise a PSR-7 request object using Laminas Diactoros
		$request = ServerRequestFactory::fromGlobals();

		// Load the data
		$publicKeyCredential = $publicKeyCredentialLoader->load(
			$this->reshapeValidationData($data)
		);
		$response            = $publicKeyCredential->getResponse();

		// Check if the response is an Authenticator Assertion Response
		if (!$response instanceof AuthenticatorAssertionResponse)
		{
			throw new RuntimeException('Not an authenticator assertion response');
		}

		/** @var AuthenticatorAssertionResponse $authenticatorAssertionResponse */
		$authenticatorAssertionResponse = $publicKeyCredential->getResponse();

		$userEntity = ($user === null || $user->guest) ? null : $this->getUserEntity($user);
		$userHandle = ($userEntity === null) ? null : $userEntity->getId();


		return $authenticatorAssertionResponseValidator->check(
			$publicKeyCredential->getRawId(),
			$authenticatorAssertionResponse,
			$publicKeyCredentialRequestOptions,
			$request,
			$userHandle
		);
	}

	/**
	 * Reshape the PassKey registration data.
	 *
	 * Some of the data returned from the browser are encoded using regular Base64 (instead of URL-safe Base64) and/or
	 * have padding. The WebAuthn library requires all data to be encoded using the URL-safe Base64 algorithm *without*
	 * padding.
	 *
	 * This method will safely convert between the actual and the desired format.
	 *
	 * @param   string  $data
	 *
	 * @return  string
	 * @since   1.0.0
	 */
	private function reshapeRegistrationData(string $data): string
	{
		$json = @Base64UrlSafe::decode($data);

		if ($json === false)
		{
			return $data;
		}

		$decodedData = @json_decode($json);

		if (empty($decodedData) || !is_object($decodedData))
		{
			return $data;
		}

		if (!isset($decodedData->response) || !is_object($decodedData->response))
		{
			return $data;
		}

		$clientDataJSON = $decodedData->response->clientDataJSON ?? null;

		if ($clientDataJSON)
		{
			$json = Base64UrlSafe::decode($clientDataJSON);

			if ($json !== false)
			{
				$clientDataJSON = @json_decode($json);

				if (!empty($clientDataJSON) && is_object($clientDataJSON) && isset($clientDataJSON->challenge))
				{
					$clientDataJSON->challenge = Base64UrlSafe::encodeUnpadded(
						Base64UrlSafe::decode($clientDataJSON->challenge)
					);

					$decodedData->response->clientDataJSON = Base64UrlSafe::encodeUnpadded(
						json_encode($clientDataJSON)
					);
				}

			}
		}

		$attestationObject = $decodedData->response->attestationObject ?? null;

		if ($attestationObject)
		{
			$decoded = Base64::decode($attestationObject);

			if ($decoded !== false)
			{
				$decodedData->response->attestationObject = Base64UrlSafe::encodeUnpadded($decoded);
			}
		}

		return Base64UrlSafe::encodeUnpadded(json_encode($decodedData));
	}

	/**
	 * Reshape the PassKey validation data.
	 *
	 * Some of the data returned from the browser are encoded using regular Base64 (instead of URL-safe Base64) and/or
	 * have padding. The WebAuthn library requires all data to be encoded using the URL-safe Base64 algorithm *without*
	 * padding.
	 *
	 * This method will safely convert between the actual and the desired format.
	 *
	 * @param   string  $data
	 *
	 * @return  string
	 * @since   1.0.0
	 */
	private function reshapeValidationData(string $data): string
	{
		$decodedData = @json_decode($data);

		if (empty($decodedData) || !is_object($decodedData))
		{
			return $data;
		}

		if ($decodedData->id ?? null)
		{
			$decodedData->id = Base64UrlSafe::encodeUnpadded(Base64UrlSafe::decode($decodedData->id));
		}

		if ($decodedData->rawId ?? null)
		{
			$decodedData->rawId = Base64::encodeUnpadded(Base64UrlSafe::decode($decodedData->id));
		}

		if (!is_object($decodedData->response ?? null))
		{
			return json_encode($decodedData);
		}

		foreach ($decodedData->response as $key => $value)
		{

			$decodedData->response->{$key} = Base64UrlSafe::encodeUnpadded(
				Base64::decode($decodedData->response->{$key})
			);
		}

		return json_encode($decodedData);
	}

}