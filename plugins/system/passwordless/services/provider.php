<?php
/**
 * @package   AkeebaPasswordlessLogin
 * @copyright Copyright (c)2018-2022 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

defined('_JEXEC') || die;

use Akeeba\Plugin\System\Passwordless\Authentication\AbstractAuthentication;
use Akeeba\Plugin\System\Passwordless\Authentication\AuthenticationInterface;
use Akeeba\Plugin\System\Passwordless\CredentialRepository;
use Akeeba\Plugin\System\Passwordless\Dependencies\Webauthn\PublicKeyCredentialSourceRepository;
use Akeeba\Plugin\System\Passwordless\Extension\Passwordless;
use Joomla\Application\ApplicationInterface;
use Joomla\Application\SessionAwareWebApplicationInterface;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Joomla\Session\SessionInterface;

return new class implements ServiceProviderInterface {
	/**
	 * Registers the service provider with a DI container.
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  void
	 *
	 * @since   7.0.0
	 */
	public function register(Container $container)
	{
		require_once __DIR__ . '/../src/Dependencies/Laminas/Diactoros/functions/create_uploaded_file.php';
		require_once __DIR__ . '/../src/Dependencies/Laminas/Diactoros/functions/marshal_headers_from_sapi.php';
		require_once __DIR__ . '/../src/Dependencies/Laminas/Diactoros/functions/marshal_method_from_sapi.php';
		require_once __DIR__ . '/../src/Dependencies/Laminas/Diactoros/functions/marshal_protocol_version_from_sapi.php';
		require_once __DIR__ . '/../src/Dependencies/Laminas/Diactoros/functions/normalize_server.php';
		require_once __DIR__ . '/../src/Dependencies/Laminas/Diactoros/functions/normalize_uploaded_files.php';
		require_once __DIR__ . '/../src/Dependencies/Laminas/Diactoros/functions/parse_cookie_header.php';

		$container->set(
			PluginInterface::class,
			function (Container $container) {
				/**
				 * Workaround weird Joomla! 4 autoloader issue.
				 *
				 * Even though psr/event-dispatcher is installed in the libraries/vendor/psr/event-dispatcher directory
				 * as a dependency of the symfony/event-dispatcher-contracts package, the Composer autoloader in the
				 * shipped Joomla! 4 releases does not register its namespace.
				 *
				 * This is weird, because checking out the 4.4-dev Git branch of joomla/joomla-cms and running `composer
				 * install` does result in an autoloader which correctly registers this namespace. Big WTF moment which
				 * wasted a lot more time than I would've liked, but ultimately trivially addressed with this two-liner.
				 */
				if (version_compare(JVERSION, '5.0.0', 'lt'))
				{
					JLoader::registerNamespace('Psr\\EventDispatcher', JPATH_LIBRARIES . '/vendor/psr/event-dispatcher/src');
				}

				$config  = (array) PluginHelper::getPlugin('system', 'passwordless');
				$subject = $container->get(DispatcherInterface::class);

				$app     = $container->has(ApplicationInterface::class) ? $container->has(ApplicationInterface::class) : $this->getApplication();
				$session = $container->has('session') ? $container->get('session') : $this->getSession($app);

				$db                    = $container->get(DatabaseInterface::class);
				$credentialsRepository = $container->has(PublicKeyCredentialSourceRepository::class)
					? $container->get(PublicKeyCredentialSourceRepository::class)
					: new CredentialRepository($db, $app);
				$authenticationHelper  = $container->has(AuthenticationInterface::class)
					? $container->get(AuthenticationInterface::class)
					: AbstractAuthentication::create($app, $session, $credentialsRepository);

				$plugin = new Passwordless($subject, $config);

				$plugin->setUpLogging();
				$plugin->setAuthenticationHelper($authenticationHelper);
				$plugin->setApplication($app);
				$plugin->setDatabase($db);

				return $plugin;
			}
		);
	}

	/**
	 * Get the current CMS application interface.
	 *
	 * @return CMSApplicationInterface|null
	 *
	 * @since  2.0.0
	 */
	private function getApplication(): ?CMSApplicationInterface
	{
		try
		{
			$app = Factory::getApplication();
		}
		catch (Exception $e)
		{
			return null;
		}

		return ($app instanceof CMSApplicationInterface) ? $app : null;
	}

	/**
	 * Get the current application session object
	 *
	 * @param   ApplicationInterface  $app  The application we are running in
	 *
	 * @return SessionInterface|null
	 *
	 * @since  2.0.0
	 */
	private function getSession(?ApplicationInterface $app = null): ?SessionInterface
	{
		$app = $app ?? $this->getApplication();

		return $app instanceof SessionAwareWebApplicationInterface ? $app->getSession() : null;
	}
};
