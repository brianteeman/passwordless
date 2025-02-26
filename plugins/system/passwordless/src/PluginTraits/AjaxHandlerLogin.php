<?php
/**
 * @package   AkeebaPasswordlessLogin
 * @copyright Copyright (c)2018-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Plugin\System\Passwordless\PluginTraits;

defined('_JEXEC') or die();

use Akeeba\Plugin\System\Passwordless\CredentialRepository;
use Exception;
use Joomla\CMS\Authentication\Authentication;
use Joomla\CMS\Authentication\AuthenticationResponse;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Event\Event;
use RuntimeException;

/**
 * Ajax handler for akaction=login
 *
 * Verifies the response received from the browser and logs in the user
 */
trait AjaxHandlerLogin
{
	/**
	 * Returns the public key set for the user and a unique challenge in a Public Key Credential Request encoded as
	 * JSON.
	 *
	 * @throws  Exception
	 *
	 * @since   1.0.0
	 */
	public function onAjaxPasswordlessLogin(Event $event): void
	{
		$session   = $this->getApplication()->getSession();
		$returnUrl = $session->get('plg_system_passwordless.returnUrl', Uri::base());
		$userId    = $session->get('plg_system_passwordless.userId', 0);

		try
		{
			// Validate the authenticator response and get the user handle
			$credentialRepository = $this->authenticationHelper->getCredentialsRepository();

			// Login Flow 1: Login with a non-resident key
			if (!empty($userId))
			{
				Log::add('Regular WebAuthn credentials login flow', Log::DEBUG, 'plg_system_passwordless');

				// Make sure the user exists
				$user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($userId);

				if ($user->id != $userId)
				{
					$message = sprintf('User #%d does not exist', $userId);
					Log::add($message, Log::NOTICE, 'plg_system_passwordless');

					throw new RuntimeException(Text::_('PLG_SYSTEM_PASSWORDLESS_ERR_CREATE_INVALID_LOGIN_REQUEST'));
				}

				// Validate the authenticator response and get the user handle
				$userHandle = $this->getUserHandleFromResponse($user);

				if (is_null($userHandle))
				{
					Log::add(
						'Cannot retrieve the user handle from the request; the browser did not assert our request.',
						Log::NOTICE, 'plg_system_passwordless'
					);

					throw new RuntimeException(Text::_('PLG_SYSTEM_PASSWORDLESS_ERR_CREATE_INVALID_LOGIN_REQUEST'));
				}

				// Does the user handle match the user ID? This should never trigger by definition of the login check.
				$validUserHandle = $credentialRepository->getHandleFromUserId($userId);

				if ($userHandle != $validUserHandle)
				{
					$message = sprintf('Invalid user handle; expected %s, got %s', $validUserHandle, $userHandle);
					Log::add($message, Log::NOTICE, 'plg_system_passwordless');

					throw new RuntimeException(Text::_('PLG_SYSTEM_PASSWORDLESS_ERR_CREATE_INVALID_LOGIN_REQUEST'));
				}

				if ($user->id != $userId)
				{
					$message = sprintf('Invalid user ID; expected %d, got %d', $userId, $user->id);
					Log::add($message, Log::NOTICE, 'plg_system_passwordless');

					throw new RuntimeException(Text::_('PLG_SYSTEM_PASSWORDLESS_ERR_CREATE_INVALID_LOGIN_REQUEST'));
				}

				// Login the user
				Log::add('Logging in the user', Log::DEBUG, 'plg_system_passwordless');
				$this->loginUser((int) $userId);

				return;
			}

			// Login Flow 2: Login with a resident key
			Log::add('Resident WebAuthn credentials (Passkey) login flow', Log::DEBUG, 'plg_system_passwordless');

			$userHandle = $this->getUserHandleFromResponse(null);

			if (is_null($userHandle))
			{
				Log::add(
					'Cannot retrieve the user handle from the request; no resident key found.', Log::NOTICE,
					'plg_system_passwordless'
				);

				throw new RuntimeException(Text::_('PLG_SYSTEM_PASSWORDLESS_ERR_EMPTY_USERNAME'));
			}

			// Get the user ID from the user handle
			$repo = $this->authenticationHelper->getCredentialsRepository();

			if (!method_exists($repo, 'getUserIdFromHandle'))
			{
				Log::add(
					'The credentials repository provided in the plugin configuration does not allow retrieving user IDs from user handles. Falling back to default implementation.',
					Log::NOTICE, 'plg_system_passwordless'
				);

				$repo = new CredentialRepository($this->getDatabase(), $this->getApplication());
			}

			$userId = $repo->getUserIdFromHandle($userHandle);

			// If the user was not found show an error
			if ($userId <= 0)
			{
				Log::add(
					sprintf('User handle %s does not correspond to a known user.', $userHandle), Log::DEBUG,
					'plg_system_passwordless'
				);

				throw new RuntimeException(Text::_('PLG_SYSTEM_PASSWORDLESS_ERR_INVALID_USERNAME_RESIDENT'));
			}

			Log::add(
				sprintf('Passkey indicates user ID %d; proceeding with login', $userId), Log::DEBUG,
				'plg_system_passwordless'
			);

			// Login the user
			Log::add('Logging in the user', Log::DEBUG, 'plg_system_passwordless');
			$this->loginUser((int) $userId);
		}
		catch (\Throwable $e)
		{
			$session->set('plg_system_passwordless.publicKeyCredentialRequestOptions', null);

			$response                = $this->getAuthenticationResponseObject();
			$response->status        = Authentication::STATUS_UNKNOWN;
			$response->error_message = $e->getMessage();

			Log::add(
				sprintf('Received login failure. Message: %s', $e->getMessage()), Log::ERROR, 'plg_system_passwordless'
			);

			// This also enqueues the login failure message for display after redirection. Look for JLog in that method.
			$this->processLoginFailure($response);
		}
		finally
		{
			/**
			 * This code needs to run no matter if the login succeeded or failed. It prevents replay attacks and takes
			 * the user back to the page they started from.
			 */

			// Remove temporary information for security reasons
			$session->set('plg_system_passwordless.publicKeyCredentialRequestOptions', null);
			$session->set('plg_system_passwordless.userHandle', null);
			$session->set('plg_system_passwordless.returnUrl', null);
			$session->set('plg_system_passwordless.userId', null);

			// Redirect back to the page we were before.
			$this->getApplication()->redirect($returnUrl);
		}
	}

	/**
	 * Logs in a user to the site, bypassing the authentication plugins.
	 *
	 * @param   int  $userId  The user ID to log in
	 *
	 * @throws Exception
	 * @since   1.0.0
	 */
	private function loginUser(int $userId): void
	{
		// Trick the class auto-loader into loading the necessary classes
		class_exists('Joomla\\CMS\\Authentication\\Authentication', true);

		// Fake a successful login message
		$isAdmin = $this->getApplication()->isClient('administrator');
		$user    = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($userId);

		// Does the user account have a pending activation?
		if (!empty($user->activation))
		{
			throw new RuntimeException(Text::_('JGLOBAL_AUTH_ACCESS_DENIED'));
		}

		// Is the user account blocked?
		if ($user->block)
		{
			throw new RuntimeException(Text::_('JGLOBAL_AUTH_ACCESS_DENIED'));
		}

		$statusSuccess = Authentication::STATUS_SUCCESS;

		$response                = $this->getAuthenticationResponseObject();
		$response->status        = $statusSuccess;
		$response->username      = $user->username;
		$response->fullname      = $user->name;
		$response->error_message = '';
		$response->language      = $user->getParam('language');
		$response->type          = 'Passwordless';

		if ($isAdmin)
		{
			$response->language = $user->getParam('admin_language');
		}

		/**
		 * Set up the login options.
		 *
		 * The 'remember' element forces the use of the Remember Me feature when logging in with Webauthn, as the
		 * users would expect.
		 *
		 * The 'action' element is actually required by plg_user_joomla. It is the core ACL action the logged in user
		 * must be allowed for the login to succeed. Please note that front-end and back-end logins use a different
		 * action. This allows us to provide the social login button on both front- and back-end and be sure that if a
		 * used with no backend access tries to use it to log in Joomla! will just slap him with an error message about
		 * insufficient privileges - the same thing that'd happen if you tried to use your front-end only username and
		 * password in a back-end login form.
		 */
		$options = [
			'remember' => true,
			'action'   => 'core.login.site',
		];

		if ($isAdmin)
		{
			$options['action'] = 'core.login.admin';
		}

		// Run the user plugins. They CAN block login by returning boolean false and setting $response->error_message.
		PluginHelper::importPlugin('user');
		$results = $this->triggerPluginEvent(
			'onUserLogin', [(array) $response, $options], null, $this->getApplication()
		);

		// If there is no boolean FALSE result from any plugin the login is successful.
		if (in_array(false, $results, true) === false)
		{
			// Set the user in the session, letting Joomla! know that we are logged in.
			$this->getApplication()->getSession()->set('user', $user);

			// Trigger the onUserAfterLogin event
			$options['user']         = $user;
			$options['responseType'] = $response->type;

			// The user is successfully logged in. Run the after login events
			$this->triggerPluginEvent(
				'onUserAfterLogin', [$options], null, $this->getApplication()
			);

			return;
		}

		// If we are here the plugins marked a login failure. Trigger the onUserLoginFailure Event.
		$this->triggerPluginEvent(
			'onUserLoginFailure', [(array) $response], null, $this->getApplication()
		);

		// Log the failure
		Log::add($response->error_message, Log::WARNING, 'jerror');

		// Throw an exception to let the caller know that the login failed
		throw new RuntimeException($response->error_message);
	}

	/**
	 * Returns a (blank) Joomla! authentication response
	 *
	 * @return  AuthenticationResponse
	 *
	 * @since   1.0.0
	 */
	private function getAuthenticationResponseObject(): AuthenticationResponse
	{
		// Force the class auto-loader to load the JAuthentication class
		class_exists('Joomla\\CMS\\Authentication\\Authentication', true);

		return new AuthenticationResponse();
	}

	/**
	 * Have Joomla! process a login failure
	 *
	 * @param   AuthenticationResponse  $response  The Joomla! auth response object
	 *
	 * @return  bool
	 *
	 * @since   1.0.0
	 */
	private function processLoginFailure(AuthenticationResponse $response)
	{
		// Import the user plugin group.
		PluginHelper::importPlugin('user');

		// Trigger onUserLoginFailure Event.
		Log::add("Calling onUserLoginFailure plugin event", Log::INFO, 'plg_system_passwordless');

		$this->triggerPluginEvent(
			'onUserLoginFailure', [(array) $response], null, $this->getApplication()
		);

		// If status is success, any error will have been raised by the user plugin
		$expectedStatus = Authentication::STATUS_SUCCESS;

		if ($response->status !== $expectedStatus)
		{
			Log::add('The login failure has been logged in Joomla\'s error log', Log::INFO, 'plg_system_passwordless');

			// Everything logged in the 'jerror' category ends up being enqueued in the application message queue.
			Log::add($response->error_message, Log::WARNING, 'jerror');
		}
		else
		{
			$message = 'A login failure was caused by a third party user plugin but it did not return any' .
			           'further information.';
			Log::add($message, Log::WARNING, 'plg_system_passwordless');
		}

		return false;
	}

	/**
	 * Validate the authenticator response sent to us by the browser.
	 *
	 * @return  string|null  The user handle or null
	 *
	 * @throws  Exception
	 *
	 * @since   1.0.0
	 */
	private function getUserHandleFromResponse(?User $user): ?string
	{
		// Retrieve data from the request and session
		$pubKeyCredentialSource = $this->authenticationHelper->validateAssertionResponse(
			$this->getApplication()->input->getBase64('data', ''),
			$user
		);

		return $pubKeyCredentialSource ? $pubKeyCredentialSource->getUserHandle() : null;
	}
}