<?php

// Standalone framework regression: no application bootstrap, database or network.
require_once __DIR__.'/../../vendor/symfony/lib/config/sfConfig.class.php';
sfConfig::set('sf_symfony_lib_dir', realpath(__DIR__.'/../../vendor/symfony/lib'));
require_once sfConfig::get('sf_symfony_lib_dir').'/autoload/sfCoreAutoload.class.php';
sfCoreAutoload::register();
require_once sfConfig::get('sf_symfony_lib_dir').'/helper/EscapingHelper.php';

class sfContext
{
    public static $instance;
    public $request;
    public $response;
    public $dispatcher;
    public $cache;
    public static function hasInstance() { return null !== self::$instance; }
    public static function getInstance() { return self::$instance; }
    public function getRequest() { return $this->request; }
    public function getResponse() { return $this->response; }
    public function getEventDispatcher() { return $this->dispatcher; }
    public function getUser() { return null; }
    public function getViewCacheManager() { return $this->cache; }
}

class ErrorRepresentationCache
{
    public $writes = 0;
    public function getCurrentCacheKey() { return 'fixture'; }
    public function getActionCache($uri) { return array(null, false); }
    public function setActionCache($uri, $content, $decorator) { ++$this->writes; return $content; }
}

class ErrorRepresentationView extends sfPHPView
{
    public static $fixtureDirectory;
    public function configure()
    {
        $this->directory = self::$fixtureDirectory;
        $this->template = 'indexSuccess'.$this->getExtension();
    }
    protected function loadCoreAndStandardHelpers() {}
}

class ErrorRepresentationException extends sfException
{
    public static function output($exception) { parent::outputStackTrace($exception); }
}

$fixture = sys_get_temp_dir().'/atom-error-representation-'.getmypid();
mkdir($fixture.'/error', 0700, true);
mkdir($fixture.'/views', 0700);
file_put_contents($fixture.'/error/errorB5.html.php', '<html>B5 <?php echo $code; ?></html>');
file_put_contents($fixture.'/views/indexSuccess.php', '<html>fixture page</html>');
ErrorRepresentationView::$fixtureDirectory = $fixture.'/views';
sfConfig::add(array(
    'sf_test' => true, 'sf_debug' => false, 'sf_logging_enabled' => false,
    'sf_cache' => false, 'sf_charset' => 'UTF-8', 'sf_escaping_strategy' => false,
    'sf_app_config_dir' => $fixture, 'sf_config_dir' => $fixture,
    'sf_root_dir' => dirname(__DIR__, 2), 'app_b5_theme' => true,
));
$assertions = 0;
$failures = array();
function check($condition, $label)
{
    global $assertions, $failures;
    ++$assertions;
    if (!$condition) { $failures[] = $label; }
}
function contextFor($format, $status = 200)
{
    $context = new sfContext();
    $context->dispatcher = new sfEventDispatcher();
    $context->request = new sfWebRequest($context->dispatcher);
    foreach (array('html' => 'text/html', 'xml' => 'text/xml', 'json' => 'application/json', 'txt' => 'text/plain') as $name => $mime) {
        $context->request->setFormat($name, $mime);
    }
    $context->request->setRequestFormat($format);
    $context->response = new sfWebResponse($context->dispatcher, array('send_http_headers' => false));
    $context->response->setStatusCode($status);
    $context->cache = new ErrorRepresentationCache();
    sfContext::$instance = $context;
    return $context;
}
function safely($label, $callback)
{
    $level = ob_get_level();
    try { $callback(); }
    catch (Throwable $error) { check(false, $label.': '.get_class($error).' '.$error->getMessage()); }
    finally { while (ob_get_level() > $level) { ob_end_clean(); } }
}


// The HTTP runner exercises the same framework classes with real PHP headers.
if ('cli-server' === PHP_SAPI) {
    register_shutdown_function(function () use ($fixture) {
        foreach (glob($fixture.'/error/*') as $file) { unlink($file); }
        foreach (glob($fixture.'/views/*') as $file) { unlink($file); }
        rmdir($fixture.'/error'); rmdir($fixture.'/views'); rmdir($fixture);
    });
    sfConfig::set('sf_test', false);
    sfConfig::set('sf_debug', isset($_GET['debug']));
    $format = isset($_GET['format']) ? $_GET['format'] : 'html';
    $case = isset($_GET['case']) ? $_GET['case'] : 'missing';
    $context = contextFor($format, 'missing' === $case ? 404 : ('forbidden' === $case ? 403 : 200));
    // Unit tests disable headers; HTTP tests must send them.
    $context->response = new sfWebResponse($context->dispatcher);
    $context->response->setStatusCode('missing' === $case ? 404 : ('forbidden' === $case ? 403 : 200));
    if ('exception' === $case) {
        (new sfException('PRIVATE_CANARY_195'))->printStackTrace();
    }
    if ('valid' === $case && 'xml' === $format) {
        file_put_contents($fixture.'/views/indexSuccess.xml.php', "<?php echo '<export>fixture data</export>';");
    }
    $view = new ErrorRepresentationView($context, 'fixture', 'index', 'Success');
    if ('missing' === $case && 'html' === $format) {
        $view->setTemplate($fixture.'/views/absent.php');
    }
    try {
        $body = $view->render();
        $context->response->sendHttpHeaders();
        echo $body;
    } catch (Throwable $error) {
        sfException::createFromException($error)->printStackTrace();
    }
    return;
}

try {
    foreach (array(false, true) as $b5) {
        sfConfig::set('app_b5_theme', $b5);
        foreach (array('xml', 'json', 'txt', 'atom', 'rdf', 'css', 'js') as $format) {
            $path = sfException::getTemplatePathForError($format, false);
            check(is_string($path) && is_file($path), 'safe '.$format.' template with B5='.(int) $b5);
        }
    }
    sfConfig::set('app_b5_theme', true);
    check(basename(sfException::getTemplatePathForError('html', false)) === 'errorB5.html.php', 'B5 HTML retained');
    foreach (array('335545696', '../xml', 'xml/../../secret', array('xml')) as $format) {
        check(false === sfException::getTemplatePathForError($format, false), 'invalid/unknown format has no file selection');
    }

    foreach (array('xml', 'json', '335545696') as $format) {
        foreach (array(404, 200) as $status) {
            safely('view '.$format.' '.$status, function () use ($format, $status) {
                $context = contextFor($format, $status);
                sfConfig::set('sf_cache', true);
                $view = new ErrorRepresentationView($context, 'fixture', 'index', 'Success');
                $body = $view->render();
                $expected = 404 === $status ? 404 : 406;
                check($context->response->getStatusCode() === $expected, $format.' status '.$expected);
                check(false !== strpos($body, (string) $expected), $format.' error body');
                check(false === strpos($body, '<debug>') && false === strpos($body, '<trace>'), $format.' has no debug');
                check(0 === $context->cache->writes, $format.' error not cached');
                if ('xml' === $format) {
                    $xml = simplexml_load_string($body);
                    check(false !== $xml && 'error' === $xml->getName(), 'well-formed XML error');
                } elseif ('json' === $format) {
                    check(isset(json_decode($body, true)['error']), 'well-formed JSON error');
                } else {
                    check(0 === strpos($context->response->getContentType(), 'text/plain'), 'unknown format uses plain text');
                }
            });
        }
    }

    foreach (array('xml', 'json', '335545696') as $format) {
        safely('exception '.$format, function () use ($format) {
            $context = contextFor($format);
            ob_start();
            ErrorRepresentationException::output(new RuntimeException('PRIVATE_CANARY_195'));
            $body = ob_get_clean();
            check(500 === $context->response->getStatusCode(), 'real exception keeps 500');
            check(strlen($body) > 0, 'production error not empty');
            check(false === strpos($body, 'PRIVATE_CANARY_195') && false === strpos($body, '<debug>'), 'no production disclosure '.$format);
        });
    }

    safely('404 HTML fallback', function () {
        $context = contextFor('html', 404);
        $view = new ErrorRepresentationView($context, 'fixture', 'missing', 'Success');
        $view->setTemplate(ErrorRepresentationView::$fixtureDirectory.'/absent.php');
        check('<html>B5 404</html>' === $view->render(), '404 keeps themed HTML');
        check(404 === $context->response->getStatusCode(), 'HTML 404 status retained');
    });
    safely('valid HTML', function () {
        $context = contextFor('html');
        $view = new ErrorRepresentationView($context, 'fixture', 'index', 'Success');
        check('<html>fixture page</html>' === $view->render(), 'existing HTML unchanged');
        check(1 === $context->cache->writes, 'successful view can still cache');
    });
    safely('missing HTML is server error', function () {
        $context = contextFor('html');
        $view = new ErrorRepresentationView($context, 'fixture', 'index', 'Success');
        $view->setTemplate(ErrorRepresentationView::$fixtureDirectory.'/absent.php');
        try { $view->render(); check(false, 'missing HTML must throw'); }
        catch (sfRenderException $expected) { check(true, 'missing HTML remains rendering error'); }
    });
    foreach (array('ead', 'dc', 'eac', 'oai') as $root) {
        safely('valid XML '.$root, function () use ($fixture, $root) {
            $body = '<?xml version="1.0"?><'.$root.'>fixture data</'.$root.'>';
            file_put_contents($fixture.'/views/indexSuccess.xml.php', '<?php echo '.var_export($body, true).';');
            $context = contextFor('xml');
            $view = new ErrorRepresentationView($context, 'fixture', 'index', 'Success');
            check($body === $view->render(), 'existing '.$root.' XML template unchanged');
            check(200 === $context->response->getStatusCode(), 'valid '.$root.' status');
            check(1 === $context->cache->writes, 'valid '.$root.' cache behavior');
        });
    }
    safely('debug remains available', function () {
        sfConfig::set('sf_debug', true);
        sfContext::$instance = null;
        ob_start();
        ErrorRepresentationException::output(new RuntimeException('DEBUG_CANARY_195'));
        $body = ob_get_clean();
        check(false !== strpos($body, 'DEBUG_CANARY_195'), 'debug exception diagnostics preserved');
    });
} finally {
    foreach (glob($fixture.'/error/*') as $file) { unlink($file); }
    foreach (glob($fixture.'/views/*') as $file) { unlink($file); }
    rmdir($fixture.'/error'); rmdir($fixture.'/views'); rmdir($fixture);
}
echo json_encode(array('assertions' => $assertions, 'failures' => $failures), JSON_PRETTY_PRINT)."\n";
exit(count($failures) ? 1 : 0);
