<?php

declare(strict_types=1);

namespace NeoPHP\Component\Controller\Contract;

use NeoPHP\Component\Api\Helper\Controller\ApiController;
use NeoPHP\Component\Cache\Helper\Controller\CacheController;
use NeoPHP\Component\Container\Helper\Controller\ContainerController;
use NeoPHP\Component\Cookie\Helper\Controller\CookieController;
use NeoPHP\Component\Csrf\Helper\Controller\CsrfController;
use NeoPHP\Component\Database\Helper\Controller\DatabaseController;
use NeoPHP\Component\Event\Helper\Controller\EventController;
use NeoPHP\Component\Flash\Helper\Controller\FlashController;
use NeoPHP\Component\Form\Helper\Controller\FormController;
use NeoPHP\Component\Http\Helper\Controller\HttpController;
use NeoPHP\Component\Mailer\Helper\Controller\MailerController;
use NeoPHP\Component\Routing\Helper\Controller\RoutingController;
use NeoPHP\Component\Serializer\Helper\Controller\SerializerController;
use NeoPHP\Component\Session\Helper\Controller\SessionController;
use NeoPHP\Component\Validator\Helper\Controller\ValidatorController;
use NeoPHP\Component\View\Helper\Controller\ViewController;
use NeoPHP\Component\HttpClient\Helper\Controller\HttpClientController;
use NeoPHP\Package\Orm\Helper\Controller\OrmController;
use NeoPHP\Package\Queue\Helper\Controller\QueueController;
use NeoPHP\Package\Security\Helper\Controller\SecurityController;
use NeoPHP\Package\Translation\Helper\Controller\TranslationController;

abstract class AbstractController implements ControllerInterface
{
    use ApiController;
    use CacheController;
    use ContainerController;
    use CookieController;
    use CsrfController;
    use DatabaseController;
    use EventController;
    use FlashController;
    use FormController;
    use HttpController;
    use MailerController;
    use HttpClientController;
    use OrmController;
    use QueueController;
    use RoutingController;
    use SecurityController;
    use SessionController;
    use TranslationController;
    use ValidatorController;
    use ViewController;
    use SerializerController;
}