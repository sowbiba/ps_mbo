<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
declare(strict_types=1);

namespace PrestaShop\Module\Mbo\Controller\Admin;

use Configuration;
use Exception;
use PhpEncryption;
use PrestaShop\Module\Mbo\Addons\Exception\LoginErrorException;
use PrestaShop\Module\Mbo\Addons\Provider\AddonsDataProvider;
use PrestaShop\Module\Mbo\Module\Exception\ModuleUpgradeNotNeededException;
use PrestaShop\Module\Mbo\Module\Repository;
use PrestaShop\PrestaShop\Core\Module\ModuleManager;
use PrestaShop\PrestaShop\Core\Module\ModuleRepository;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class AddonsController extends FrameworkBundleAdminController
{
    /**
     * @var RequestStack
     */
    protected $requestStack;

    /**
     * @var AddonsDataProvider
     */
    private $addonsDataProvider;

    /**
     * @var ModuleManager
     */
    private $moduleManager;

    /**
     * @var Repository
     */
    private $moduleRepository;

    /**
     * @var ModuleRepository
     */
    private $coreModuleRepository;

    public function __construct(
        RequestStack $requestStack,
        AddonsDataProvider $addonsDataProvider,
        ModuleManager $moduleManager,
        Repository $moduleRepository,
        ModuleRepository $coreModuleRepository
    ) {
        parent::__construct();
        $this->requestStack = $requestStack;
        $this->addonsDataProvider = $addonsDataProvider;
        $this->moduleManager = $moduleManager;
        $this->moduleRepository = $moduleRepository;
        $this->coreModuleRepository = $coreModuleRepository;
    }

    /**
     * Controller responsible for the authentication on PrestaShop Addons.
     *
     * @return JsonResponse
     */
    public function loginAction(): JsonResponse
    {
        $request = $this->requestStack->getCurrentRequest();
        $response = new JsonResponse();

        // Parameters needed in order to authenticate the merchant : login and password
        $params = [
            'format' => 'json',
            'username' => $request->get('username_addons', null),
            'password' => $request->get('password_addons', null),
        ];

        try {
            $json = $this->addonsDataProvider->request('check_customer', $params);
            if ($json === null) {
                throw new LoginErrorException();
            }

            if (!empty($json->errors)) {
                throw new LoginErrorException($json->errors->code . ': ' . $json->errors->label);
            }

            Configuration::updateValue('PS_LOGGED_ON_ADDONS', 1);

            if ($request->get('addons_remember_me', false)) {
                $response = $this->createCookieUser($response, $json, $params);
            } else {
                $response = $this->createSessionUser($response, $this->get('session'), $json, $params);
            }

            $response->setData(['success' => 1, 'message' => '']);

            // Clear previously filtered modules search
            $this->moduleRepository->clearCache();
        } catch (Exception $e) {
            $response->setData([
                'success' => 0,
                'message' => $this->trans(
                    'PrestaShop was unable to log in to Addons. Please check your credentials and your Internet connection.',
                    'Modules.Mbo.Errors'
                ),
            ]);
        }

        return $response;
    }

    /**
     * Controller responsible for the logout on PrestaShop Addons.
     *
     * @return JsonResponse|RedirectResponse
     */
    public function logoutAction()
    {
        // Clear previously filtered modules search
        $this->moduleRepository->clearCache();

        $request = $this->requestStack->getCurrentRequest();

        if ($request->isXmlHttpRequest()) {
            $response = new JsonResponse();
            $response->setData([
                'success' => 1,
                'message' => '',
            ]);
        } else {
            if ($request->server->get('HTTP_REFERER')) {
                $url = $request->server->get('HTTP_REFERER');
            } else {
                $url = $this->redirect($this->generateUrl('admin_module_catalog'));
            }
            $response = new RedirectResponse($url);
        }
        $response->headers->clearCookie('username_addons');
        $response->headers->clearCookie('password_addons');
        $response->headers->clearCookie('is_contributor');

        $session = $this->get('session');
        $session->remove('username_addons');
        $session->remove('password_addons');
        $session->remove('is_contributor');

        $request->setSession($session);

        return $response;
    }

    public function upgradeModuleAction(): JsonResponse
    {
        $request = $this->requestStack->getCurrentRequest();
        $moduleName = $request->request->get('moduleName');

        if (null === $moduleName) {
            return new JsonResponse(null, Response::HTTP_BAD_REQUEST);
        }

        try {
            $upgradeResponse = [
                'status' => $this->moduleManager->upgrade($moduleName),
                'msg' => '',
                'module_name' => $moduleName,
            ];

            if ($upgradeResponse['status'] === true) {
                $upgradeResponse['msg'] = $this->trans(
                    'Module %module% successfully upgraded.',
                    'Modules.Mbo.Modulescatalog',
                    ['%module%' => $moduleName]
                );
                $upgradeResponse['is_configurable'] = (bool) $this->coreModuleRepository
                    ->getModule($moduleName)
                    ->attributes
                    ->get('is_configurable');
            } else {
                $error = $this->moduleManager->getError($moduleName);
                $upgradeResponse['msg'] = $this->trans(
                    'Upgrade of module %module% failed. %error%',
                    'Modules.Mbo.Modulescatalog',
                    [
                        '%module%' => $moduleName,
                        '%error%' => $error,
                    ]
                );
            }
        } catch (Exception $e) {
            if ($e->getPrevious() instanceof ModuleUpgradeNotNeededException) {
                $upgradeResponse['status'] = true;
                $upgradeResponse['msg'] = $this->trans(
                    'Module %module% is already up to date',
                    'Modules.Mbo.Modulescatalog',
                    [
                        '%module%' => $moduleName,
                    ]
                );
            } else {
                try {
                    $this->moduleManager->disable($moduleName);
                } catch (Exception $subE) {
                }

                $upgradeResponse['msg'] = $this->trans(
                    'Upgrade of module %module% failed. %error%',
                    'Modules.Mbo.Modulescatalog',
                    [
                        '%module%' => $moduleName,
                        '%error%' => $e->getMessage(),
                    ]
                );
            }
        }

        return new JsonResponse($upgradeResponse);
    }

    private function createCookieUser(Response $response, \stdClass $json, array $params): Response
    {
        $expiresAt = strtotime('+30 days');

        $phpEncryption = new PhpEncryption(_NEW_COOKIE_KEY_);

        $response->headers->setCookie(
            new Cookie('username_addons', $phpEncryption->encrypt($params['username']), $expiresAt, null, null, null, false)
        );
        $response->headers->setCookie(
            new Cookie('password_addons', $phpEncryption->encrypt($params['password']), $expiresAt, null, null, null, false)
        );
        $response->headers->setCookie(
            new Cookie('is_contributor', (string) $json->is_contributor, $expiresAt, null, null, null, false)
        );

        return $response;
    }

    private function createSessionUser(Response $response, SessionInterface $session, \stdClass $json, array $params): Response
    {
        $phpEncryption = new PhpEncryption(_NEW_COOKIE_KEY_);

        $session->set('username_addons', $phpEncryption->encrypt($params['username']));
        $session->set('password_addons', $phpEncryption->encrypt($params['password']));
        $session->set('is_contributor', (string) $json->is_contributor);

        return $response;
    }
}
