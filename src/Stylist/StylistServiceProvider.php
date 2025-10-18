<?php

namespace Mehedi\Stylist;

use Mehedi\Stylist\Theme\Loader;
use Mehedi\Stylist\Theme\Stylist;
use Illuminate\Foundation\AliasLoader;
use Mehedi\Stylist\Html\ThemeHtmlBuilder;
use Illuminate\Support\AggregateServiceProvider;

class StylistServiceProvider extends AggregateServiceProvider
{
    /**
     * Stylist provides the HtmlServiceProvider for ease-of-use.
     *
     * @var array
     */
    protected $providers = [];

    /**
     * Registers the various bindings required by other packages.
     */
    public function register()
    {
        parent::register();

        $this->registerConfiguration();
        $this->registerStylist();
        $this->registerAliases();
        $this->registerThemeBuilder();
        $this->registerCommands();
    }

    /**
     * Boot the package, in this case also discovering any themes required by stylist.
     */
    public function boot()
    {
        $this->bootThemes();
    }

    /**
     * Once the provided has booted, we can now look at configuration and see if there's
     * any paths defined to automatically load and register the required themes.
     */
    protected function bootThemes()
    {
        $stylist = $this->app['stylist'];
        $paths = $this->app['config']->get('stylist.themes.paths', []);

        foreach ($paths as $path) {
            $themePaths = $stylist->discover($path);
            $stylist->registerPaths($themePaths);
        }

        $theme = $this->app['config']->get('stylist.themes.activate', null);

        if (!is_null($theme)) {
            $stylist->activate($theme, true);
        }
    }

    /**
     * Sets up the object that will be used for theme registration calls.
     */
    protected function registerStylist()
    {
        $this->app->singleton('stylist', function ($app) {
            return new Stylist(new Loader, $app);
        });
    }

    /**
     * Create the binding necessary for the theme html builder.
     */
    protected function registerThemeBuilder()
    {
        // Always bind a theme builder service. If Collective\Html is available use the
        // full ThemeHtmlBuilder, otherwise provide a lightweight fallback that does
        // not depend on the HtmlBuilder so the application can boot without it.
        $this->app->singleton('stylist.theme', function ($app) {
            if ($app->bound('html')) {
                return new ThemeHtmlBuilder($app['html'], $app['url']);
            }

            $urlGenerator = $app['url'];

            return new class($urlGenerator) {
                private $url;

                public function __construct($url)
                {
                    $this->url = $url;
                }

                private function attrString($attributes)
                {
                    $s = '';
                    foreach ($attributes as $k => $v) {
                        $s .= ' ' . htmlspecialchars($k, ENT_QUOTES) . '="' . htmlspecialchars((string) $v, ENT_QUOTES) . '"';
                    }

                    return $s;
                }

                protected function assetUrl($path)
                {
                    if ($this->url->isValidUrl($path)) {
                        return $path;
                    }

                    // Try to get current theme path, fall back to provided path
                    try {
                        $theme = \Mehedi\Stylist\Facades\StylistFacade::current();
                        if ($theme) {
                            $themePath = $theme->getAssetPath();
                            return "themes/{$themePath}/{$path}";
                        }
                    } catch (\Throwable $e) {
                        // ignore and return path
                    }

                    return $path;
                }

                public function script($url, $attributes = array(), $secure = null)
                {
                    $u = $this->assetUrl($url);
                    return '<script src="' . $this->url->to($u) . '"' . $this->attrString($attributes) . '></script>';
                }

                public function style($url, $attributes = array(), $secure = null)
                {
                    $u = $this->assetUrl($url);
                    return '<link rel="stylesheet" href="' . $this->url->to($u) . '"' . $this->attrString($attributes) . ' />';
                }

                public function image($url, $alt = null, $attributes = array(), $secure = null)
                {
                    $u = $this->assetUrl($url);
                    if ($alt !== null) {
                        $attributes = array_merge(['alt' => $alt], $attributes);
                    }

                    return '<img src="' . $this->url->to($u) . '"' . $this->attrString($attributes) . ' />';
                }

                public function url($file = '')
                {
                    return $this->url->to($this->assetUrl($file));
                }

                public function linkAsset($url, $title = null, $attributes = array(), $secure = null)
                {
                    $u = $this->assetUrl($url);
                    $text = $title ?? $u;
                    return '<a href="' . $this->url->to($u) . '"' . $this->attrString($attributes) . '>' . htmlspecialchars($text, ENT_QUOTES) . '</a>';
                }
            };
        });
    }

    /**
     * Stylist class should be accessible from global scope for ease of use.
     */
    private function registerAliases()
    {
        $aliasLoader = AliasLoader::getInstance();

        $aliasLoader->alias('Stylist', 'Mehedi\Stylist\Facades\StylistFacade');
        $aliasLoader->alias('Theme', 'Mehedi\Stylist\Facades\ThemeFacade');

        $this->app->alias('stylist', 'Mehedi\Stylist\Theme\Stylist');
    }

    /**
     * Register the commands available to the package.
     */
    private function registerCommands()
    {
        $this->commands(
            'Mehedi\Stylist\Console\PublishAssetsCommand'
        );
    }

    /**
     * Setup the configuration that can be used by stylist.
     */
    protected function registerConfiguration()
    {
        $this->publishes([
            __DIR__ . '/../config/config.php' => config_path('stylist.php')
        ]);
    }

    /**
     * An array of classes that Stylist provides.
     *
     * @return array
     */
    public function provides()
    {
        return array_merge(parent::provides(), [
            'Stylist',
            'Theme'
        ]);
    }
}
