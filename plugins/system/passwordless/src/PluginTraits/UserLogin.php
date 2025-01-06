<?php
/**
 * @package   AkeebaPasswordlessLogin
 * @copyright Copyright (c)2018-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Plugin\System\Passwordless\PluginTraits;

defined('_JEXEC') || die;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserHelper;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;
use Joomla\Event\Event;
use Joomla\Utilities\ArrayHelper;

trait UserLogin
{
	/**
	 * Handle a successful login.
	 *
	 * If the logged-in user has one or more passwordless methods, and they just logged in with a username and password
	 * we will decline the login and print a custom message. This is the same set of messages printed when they use the
	 * wrong password, making it impossible for attackers to know if they have guessed the correct username.
	 *
	 * @param   Event  $event  The event we are handling
	 *
	 * @since   2.0.0
	 */
	public function onUserLogin(Event $event)
	{
		// Get the login event arguments
		[$userData, $options] = array_values($event->getArguments());
		$userData = $userData ?: [];

		// Only trigger when we are logging in with a username and password (auth type 'Joomla').
		if (($userData['type'] ?? '') !== 'Joomla')
		{
			return;
		}

		// Get the effective user
		$user = $this->getLoginUserObject($userData);

		// Has the user disabled password authentication on their user account?
		if (!$this->isPasswordlessOnlyUser($user))
		{
			return;
		}

		// Logout the user and close the session.
		$logoutOptions = [];

		$this->getApplication()->logout($user->id, $logoutOptions);
		$this->getApplication()->getSession()->close();

		// Get a valid return URL.
		$return = $this->getApplication()->input->getBase64('return', '');
		$return = !empty($return) ? @base64_decode($return) : '';
		$return = $return ?: Uri::base();

		// For security reasons we cannot allow a return URL that's outside the current site.
		if (!Uri::isInternal($return))
		{
			// If the URL wasn't an internal redirect to the site's root.
			$return = Uri::base();
		}

		// Redirect the user and display a message notifying them they have to log in with Passwordless.
		$message = $this->params->get('nopassword_use_custom_message', 1)
			? 'PLG_SYSTEM_PASSWORDLESS_ERR_NOPASSWORDLOGIN'
			: 'JGLOBAL_AUTH_INVALID_PASS';

		$this->getApplication()->enqueueMessage(
			Text::_($message),
			CMSApplication::MSG_WARNING
		);

		$this->getApplication()->redirect($return);
	}

	/**
	 * Handle a login failure.
	 *
	 * This is used to display our custom message for all users who have disabled password logins when they have at
	 * least one Passwordless method enabled. This means that both successful and failed logins for these users will
	 * display the same set of messages, making it virtually impossible for an attacker to discern if they guessed the
	 * right password or not. Yes, it also tells the attacker that the specific username exists, has passwordless
	 * authentication enabled, and the user chose not to log in with a username and password. This is NOT a security
	 * issue because passkeys –unlike passwords– cannot be brute-forced.
	 *
	 * @param   Event  $event  The event we are handling
	 *
	 * @return  void
	 *
	 * @since   2.0.0
	 */
	public function onUserLoginFailure(Event $event)
	{
		[$response] = array_values($event->getArguments());

		// Only trigger when we are logging in with a username and password (auth type 'Joomla').
		if (($response['type'] ?? '') !== 'Joomla')
		{
			return;
		}

		// Get the effective user
		$user = $this->getLoginUserObject($response);

		// Has the user disabled password authentication on their user account?
		if (!$this->isPasswordlessOnlyUser($user))
		{
			return;
		}

		// Enqueue a message
		$message = $this->params->get('nopassword_use_custom_message', 1)
			? 'PLG_SYSTEM_PASSWORDLESS_ERR_NOPASSWORDLOGIN'
			: 'JGLOBAL_AUTH_INVALID_PASS';

		$this->getApplication()->enqueueMessage(
			Text::_($message),
			CMSApplication::MSG_WARNING
		);
	}

	/**
	 * Is the user only allowed to use passwordless authentication?
	 *
	 * @param   User|null  $user  The user object. NULL for current user.
	 *
	 * @return  bool  TRUE if the user can only log into the site using passwordless authentication.
	 *
	 * @since   2.0.0
	 */
	private function isPasswordlessOnlyUser(?User $user): bool
	{
		if (empty($user) || $user->guest || empty($user->id) || $user->id <= 0)
		{
			return false;
		}

		// Get the user's passwordless authentication methods.
		$entity      = $this->authenticationHelper->getUserEntity($user);
		$credentials = $this->authenticationHelper->getCredentialsRepository()->findAllForUserEntity($entity);

		// There is no passwordless authentication method available. Allow password login.
		if (count($credentials) < 1)
		{
			return false;
		}

		// Get the password login preference applicable to this user.
		$preference = $this->getNoPasswordPreference($user);

		// Parse the preference
		switch ($preference)
		{
			// 0: Password authentication is always allowed
			default:
			case 0:
				return false;

			// 1: Password authentication is always forbidden
			case 1:
				return true;

			// 2: Password authentication is always forbidden if the user has two or more passwordless methods.
			case 2:
				return count($credentials) > 1;
		}
	}

	private function getNoPasswordPreference(User $user): int
	{
		// Forced preference by user group.
		$configuredGroups = $this->params->get('nopassword_groups', []) ?? [];
		$configuredGroups = is_array($configuredGroups)
			? $configuredGroups
			: array_filter(
				ArrayHelper::toInteger($configuredGroups)
			);

		if (!empty($configuredGroups) && !empty(array_intersect($user->getAuthorisedGroups(), $configuredGroups)))
		{
			return 1;
		}

		// Default: as per plugin options, fallback to password login always allowed.
		$preference = $this->params->get('nopassword_default', 0) ?? 0;

		// User preference, if allowed.
		if ($this->params->get('nopassword_controls', 1))
		{
			// Does this user prefer to only use passwordless login?
			/** @var DatabaseDriver $db */
			$db         = $this->getDatabase();
			$userId     = $user->id;
			$profileKey = 'passwordless.noPassword';
			$query      = $db->getQuery(true)
				->select($db->quoteName('profile_value'))
				->from($db->quoteName('#__user_profiles'))
				->where($db->quoteName('user_id') . ' = :user_id')
				->where($db->quoteName('profile_key') . ' = :profile_key')
				->bind(':user_id', $userId, ParameterType::INTEGER)
				->bind(':profile_key', $profileKey);

			try
			{
				$preference = $db->setQuery($query)->loadResult() ?: 0;
			}
			catch (\Exception $e)
			{
				$preference = 0;
			}
		}

		return $preference;
	}

	/**
	 * Get a Joomla user object based on the login success or login failure information
	 *
	 * @param   array  $loginInformation  The user data from a login success or failure event.
	 *
	 * @return  User  The Joomla User object
	 *
	 * @since   2.0.0
	 */
	private function getLoginUserObject(array $loginInformation): User
	{
		$instance = new User();

		if ($id = intval(UserHelper::getUserId($loginInformation['username'])))
		{
			$instance->load($id);

			return $instance;
		}

		$config           = ComponentHelper::getParams('com_users');
		$defaultUserGroup = $config->get('new_usertype', 2);

		$instance->set('id', 0);
		$instance->set('name', $loginInformation['fullname']);
		$instance->set('username', $loginInformation['username']);
		$instance->set('email', $loginInformation['email']);
		$instance->set('usertype', 'deprecated');
		$instance->set('groups', [$defaultUserGroup]);

		return $instance;
	}
}