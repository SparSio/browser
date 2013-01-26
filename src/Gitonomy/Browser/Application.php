<?php

namespace Gitonomy\Browser;

use Symfony\Component\HttpFoundation\Request;

use Silex\Application as BaseApplication;
use Silex\Provider\FormServiceProvider;
use Silex\Provider\TranslationServiceProvider;
use Silex\Provider\TwigServiceProvider;
use Silex\Provider\UrlGeneratorServiceProvider;

use Gitonomy\Browser\EventListener\RepositoryListener;
use Gitonomy\Browser\Git\Repository;
use Gitonomy\Browser\Routing\GitUrlGenerator;
use Gitonomy\Browser\Twig\GitExtension;
use Gitonomy\Browser\Utils\RepositoriesFinder;

class Application extends BaseApplication
{
    /**
     * Constructor.
     *
     * @param string $configFile The config file to load.
     * @param array  $extraParam An array to overide params in configFile (usefull for test)
     */
    public function __construct($configFile, array $extraParam = array())
    {
        parent::__construct($extraParam);

        $gitonomy = $this;

        if (!file_exists($configFile)) {
            throw new \RuntimeException(sprintf('Can not find config file: "%s"', $configFile));
        }
        require $configFile;

        $this->loadRepositories();

        // urlgen
        $this->register(new UrlGeneratorServiceProvider());

        // translator
        $this->register(new TranslationServiceProvider(), array('locale_fallback' => 'en'));
        // form
        $this->register(new FormServiceProvider());

        // twig
        $this->register(new TwigServiceProvider(), array(
            'twig.path' => __DIR__.'/Resources/views',
            'debug'     => $this['debug'],
        ));

        $urlGenerator = new GitUrlGenerator($this['url_generator'], $this['repositories']);

        $this['twig']->addExtension(new GitExtension($urlGenerator, array('git/default_theme.html.twig')));

        $this['dispatcher']->addSubscriber(new RepositoryListener($this['request_context'], $this['twig'], $this['repositories']));

        $this->registerActions();
    }

    public function registerActions()
    {
        /**
         * Main page, showing all repositories.
         */
        $this->get('/', function (Application $app) {
            return $app['twig']->render('repository_list.html.twig', array('repositories' => $app['repositories']));
        })->bind('repositories');

        /**
         * Landing page of a repository.
         */
        $this->get('/{repository}', function (Application $app, $repository) {
            return $app['twig']->render('log.html.twig');
        })->bind('repository');

        /**
         * Ajax Log entries
         */
        $this->get('/{repository}/log-ajax', function (Request $request, Application $app, $repository) {
            if ($reference = $request->query->get('reference')) {
                $log = $repository->getReferences()->get($reference)->getLog();
            } else {
                $log = $repository->getLog();
            }

            if (null !== ($offset = $request->query->get('offset'))) {
                $log->setOffset($offset);
            }

            if (null !== ($limit = $request->query->get('limit'))) {
                $log->setLimit($limit);
            }

            $log = $repository->getLog()->setOffset($offset)->setLimit($limit);

            return $app['twig']->render('log_ajax.html.twig', array(
                'log'        => $log
            ));
        })->bind('log_ajax');

        /**
         * Commit page
         */
        $this->get('/{repository}/commit/{hash}', function (Application $app, $repository, $hash) {
            return $app['twig']->render('commit.html.twig', array(
                'commit'     => $repository->getCommit($hash),
            ));
        })->bind('commit');

        /**
         * Reference page
         */
        $this->get('/{repository}/{fullname}', function (Application $app, $repository, $fullname) {
            return $app['twig']->render('reference.html.twig', array(
                'reference'  => $repository->getReferences()->get($fullname),
            ));
        })->bind('reference')->assert('fullname', 'refs\\/.*');

        /**
         * Delete a reference
         */
        $this->post('/{repository}/admin/delete-ref/{fullname}', function (Application $app, $repository, $fullname) {
            $repository->getReferences()->get($fullname)->delete();

            return $app->redirect($app['url_generator']->generate('repository', array('repository' => $repository)));
        })->bind('reference_delete')->assert('fullname', 'refs\\/.*');
    }

    private function loadRepositories()
    {
        if (!isset($this['repositories'])) {
            throw new \RuntimeException(sprintf('You should declare some repositories in the config file: "%s"', $configFile));
        } elseif (is_string($this['repositories'])) {
            $repoFinder = new RepositoriesFinder();
            $this['repositories'] = $repoFinder->getRepositories($this['repositories']);
        } elseif ($this['repositories'] instanceof Repository) {
            $this['repositories'] = array($this['repositories']);
        } elseif (is_array($this['repositories'])) {
            foreach ($this['repositories'] as $key => $repository) {
                if (!$repository instanceof Repository) {
                    throw new \RuntimeException(sprintf('Value (%s) in $gitonomy[\'repositories\'] is not an instance of Repository in: "%s"', $key, $configFile));
                }
                if (is_string($key)) {
                    $repository->setName($key);
                }
            }
        } else {
            throw new \RuntimeException(sprintf('"$gitonomy" should be a array of Repository or a string in "%s"', $configFile));
        }

        $repositoryTmp = array();
        foreach ($this['repositories'] as $repository) {
            $repositoryTmp[$repository->getName()] = $repository;
        }

        $this['repositories'] = $repositoryTmp;
    }
}
